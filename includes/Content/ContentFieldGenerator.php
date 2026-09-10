<?php
/**
 * Content-field AI generation — provider → Proposal Store bridge.
 *
 * Turns an AI content-field suggestion into a governed DRAFT. For one explicitly-
 * selected post + one field kind ('title'|'excerpt') it asks the active
 * ContentFieldProvider for a suggestion and, on success, creates a draft via
 * ProposalStore::create(). That is the ONLY write it performs.
 *
 * Propose != Apply: this class NEVER applies, NEVER calls OperationExecutor /
 * ProposalApplyService / ContentManager, NEVER writes posts / postmeta / options /
 * change_log, and NEVER mutates the site. The draft it creates is reviewed and applied
 * later through the existing governed apply path (the draft's payload is a
 * content_manage/content_update request the deployed runtime already runs).
 *
 * Allowed collaborators: ContentFieldProviderResolver (suggestion), ProposalStore
 * (read-only dedup + the single create() write), and read-only WordPress post lookups.
 */

namespace WPCommandCenter\Content;

use WPCommandCenter\Ai\CapabilityGate;
use WPCommandCenter\Ai\ProviderProvenance;
use WPCommandCenter\Ai\SourceContentSignal;
use WPCommandCenter\Proposals\ProposalStore;

defined( 'ABSPATH' ) || exit;

final class ContentFieldGenerator {

	/**
	 * Editable content statuses suggestions may be generated for. Core content fields
	 * are worth preparing before publishing, so drafts/pending/scheduled/private are
	 * included. Everything else (trash / auto-draft / inherit [revisions, attachments]
	 * / any other) is intentionally excluded and skipped with reason
	 * `unsupported_status`.
	 */
	public const SUPPORTED_STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private' ];

	/** The core content fields this generator can prepare drafts for. */
	public const KINDS = [ 'title', 'excerpt' ];

	/** Whether suggestions may be generated for a given post status. */
	public static function is_supported_status( string $status ): bool {
		return in_array( $status, self::SUPPORTED_STATUSES, true );
	}

	/** Content excerpt cap fed to the provider (~1.5k tokens). */
	private const EXCERPT_CHARS = 6000;

	private ProposalStore $store;
	private ContentFieldProviderResolver $resolver;

	public function __construct( ?ProposalStore $store = null, ?ContentFieldProviderResolver $resolver = null ) {
		$this->store    = $store ?? new ProposalStore();
		$this->resolver = $resolver ?? new ContentFieldProviderResolver();
	}

	/**
	 * Generate one draft content-field proposal for one post + one field kind.
	 *
	 * @param int    $post_id The post to prepare a draft for.
	 * @param string $kind    'title' or 'excerpt'.
	 * @param array  $context { actor?: array, replacing?: string } — the admin who
	 *                        triggered it, and the draft this run is replacing, if any.
	 * @return array { action, kind, batch_id, provider, model, created[], skipped[], failed[], replaced, source }
	 */
	public function generate( int $post_id, string $kind, array $context = [] ): array {
		$batch_id = wp_generate_uuid4();
		$actor    = ( isset( $context['actor'] ) && is_array( $context['actor'] ) ) ? $context['actor'] : [];

		/*
		 * Regeneration — "I don't like this suggestion, give me another".
		 *
		 * The customer's current draft has to survive a failed retry: asking for a
		 * second opinion must never cost them the first one. So the old draft is left
		 * completely alone until the replacement has actually been created, and only
		 * then dismissed. If the provider errors, times out, or returns something
		 * unparseable, nothing has changed and the original is still sitting there.
		 *
		 * It also has to be exempt from the open-draft dedup below, which exists to stop
		 * a customer stacking up duplicate suggestions for one field — the whole point
		 * here is that a draft for this field already exists.
		 */
		$replacing = isset( $context['replacing'] ) ? (string) $context['replacing'] : '';

		$created = [];
		$skipped = [];
		$failed  = [];

		// Validate the requested field kind.
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$failed[] = [ 'post_id' => $post_id, 'code' => 'invalid_kind', 'message' => __( 'Unknown content field kind.', 'action-steward' ) ];
			return $this->envelope( $kind, $batch_id, '', '', $created, $skipped, $failed );
		}

		// Precondition: an AI text provider must be configured. (No SEO-plugin
		// precondition — these are core WordPress content fields.)
		$provider = $this->resolver->active();
		if ( null === $provider ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => 'no_provider' ];
			return $this->envelope( $kind, $batch_id, '', '', $created, $skipped, $failed );
		}

		// Provenance: only a provider the product actually ships may produce a draft a
		// customer will review. The resolver seam is injectable so the suites can drive
		// this generator deterministically, and those runs used to leave real "STUB
		// TITLE" drafts behind that were indistinguishable from genuine suggestions.
		if ( ! ProviderProvenance::accepts( $provider->id() ) ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => ProviderProvenance::REASON ];
			return $this->envelope( $kind, $batch_id, $provider->id(), '', $created, $skipped, $failed );
		}

		// Capability gate: inert for Anthropic; never selects/routes.
		if ( ! CapabilityGate::check( 'ai_content', $provider->id() )['ok'] ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => 'capability_unsupported' ];
			return $this->envelope( $kind, $batch_id, $provider->id(), '', $created, $skipped, $failed );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => 'not_found' ];
			return $this->envelope( $kind, $batch_id, $provider->id(), '', $created, $skipped, $failed );
		}
		if ( ! self::is_supported_status( (string) $post->post_status ) ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => 'unsupported_status' ];
			return $this->envelope( $kind, $batch_id, $provider->id(), '', $created, $skipped, $failed );
		}

		// Per-kind dedup: target_type separates title vs excerpt vs SEO so an open
		// draft/pending for one field never blocks another.
		$target_type = ( 'title' === $kind ) ? 'content_title' : 'content_excerpt';
		if ( '' !== $replacing && ! $this->is_replaceable( $replacing, $post_id, $target_type ) ) {
			// A stale id, someone else's proposal, or one already applied/submitted. Do
			// not quietly generate a duplicate instead — say what happened.
			$failed[] = [ 'post_id' => $post_id, 'code' => 'not_replaceable', 'message' => __( 'That suggestion can no longer be replaced.', 'action-steward' ) ];
			return $this->envelope( $kind, $batch_id, $provider->id(), '', $created, $skipped, $failed );
		}
		if ( $this->has_open_proposal( $post_id, $target_type, $replacing ) ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => 'has_open_proposal' ];
			return $this->envelope( $kind, $batch_id, $provider->id(), '', $created, $skipped, $failed );
		}

		$current = ( 'title' === $kind ) ? get_the_title( $post ) : (string) $post->post_excerpt;
		$source  = $this->excerpt( (string) $post->post_content );

		// How much the provider had to work with. Reported, never enforced: a thin page
		// still generates, and the envelope carries the reason its suggestion may stay
		// close to what is already there.
		$signal = SourceContentSignal::describe( $source );

		$result = $provider->suggest( $kind, [
			'title'   => get_the_title( $post ),
			'content' => $source,
			'current' => $current,
		] );

		if ( ! $result->is_ok() ) {
			$err      = $result->get_error();
			$failed[] = [ 'post_id' => $post_id, 'code' => (string) $err['code'], 'message' => (string) $err['message'] ];
			return $this->envelope( $kind, $batch_id, $provider->id(), $result->model(), $created, $skipped, $failed, $signal );
		}

		$field = ( 'title' === $kind ) ? 'title' : 'excerpt';

		$proposal = $this->store->create( [
			'operation_id' => 'content_manage',
			'action'       => 'content_update',
			'target_type'  => $target_type,
			'target_id'    => (string) $post_id,
			'payload'      => [
				'action'     => 'content_update',
				'content_id' => $post_id,
				$field       => $result->text(),
			],
			'prior'        => [ $field => $current ],
			'provider'     => $result->provider(),
			'model'        => $result->model(),
			'confidence'   => null,
			'batch_id'     => $batch_id,
			'proposed_by'  => $actor,
		] );

		if ( is_wp_error( $proposal ) ) {
			$failed[] = [ 'post_id' => $post_id, 'code' => $proposal->get_error_code(), 'message' => $proposal->get_error_message() ];
			return $this->envelope( $kind, $batch_id, $provider->id(), $result->model(), $created, $skipped, $failed, $signal );
		}

		$created[] = (string) $proposal['proposal_id'];

		// The replacement exists — only now is it safe to retire the old draft.
		$replaced = false;
		if ( '' !== $replacing ) {
			$replaced = ! is_wp_error( $this->store->dismiss( $replacing ) );
		}

		return $this->envelope( $kind, $batch_id, $provider->id(), $result->model(), $created, $skipped, $failed, $signal, $replaced );
	}

	/**
	 * Open-proposal dedup via the ProposalStore READ API (no writes). Scoped by
	 * target_type so each field kind dedups independently.
	 */
	private function has_open_proposal( int $post_id, string $target_type, string $ignore_id = '' ): bool {
		$tid = (string) $post_id;
		foreach ( [ ProposalStore::STATUS_DRAFT, ProposalStore::STATUS_PENDING_APPROVAL ] as $status ) {
			$filters = [ 'target_id' => $tid, 'operation_id' => 'content_manage', 'target_type' => $target_type, 'status' => $status ];
			if ( '' === $ignore_id ) {
				if ( $this->store->count( $filters ) > 0 ) {
					return true;
				}
				continue;
			}
			// Regeneration: the draft being replaced does not block its own replacement.
			foreach ( $this->store->list( $filters + [ 'limit' => 50 ] ) as $row ) {
				if ( (string) ( $row['proposal_id'] ?? '' ) !== $ignore_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether $proposal_id is a draft this run may replace: it must exist, still be a
	 * DRAFT (never one already submitted for approval or applied — replacing those would
	 * step around the approval the customer already asked for), and belong to exactly
	 * this post and field.
	 */
	private function is_replaceable( string $proposal_id, int $post_id, string $target_type ): bool {
		$row = $this->store->get( $proposal_id );
		return is_array( $row )
			&& ProposalStore::STATUS_DRAFT === (string) ( $row['status'] ?? '' )
			&& 'content_manage' === (string) ( $row['operation_id'] ?? '' )
			&& $target_type === (string) ( $row['target_type'] ?? '' )
			&& (string) $post_id === (string) ( $row['target_id'] ?? '' );
	}

	/** Plain-text, bounded content excerpt for the prompt. */
	private function excerpt( string $content ): string {
		$text = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		if ( mb_strlen( $text ) > self::EXCERPT_CHARS ) {
			$text = mb_substr( $text, 0, self::EXCERPT_CHARS );
		}
		return $text;
	}

	/**
	 * @param array{level:string,words:int,thin:bool}|null $source How much source content
	 *        the provider had. Null when the run stopped before a post was ever read.
	 */
	private function envelope( string $kind, string $batch_id, string $provider, string $model, array $created, array $skipped, array $failed, ?array $source = null, bool $replaced = false ): array {
		return [
			'action'   => 'content_field_generate',
			'kind'     => $kind,
			'batch_id' => $batch_id,
			'provider' => $provider,
			'model'    => $model,
			'created'  => $created,
			'skipped'  => $skipped,
			'failed'   => $failed,
			// Advisory only. A thin page still generates; this says why its suggestion
			// may stay close to what is already there.
			'source'   => $source,
			// True when this run replaced an existing draft (regeneration).
			'replaced' => $replaced,
		];
	}
}
