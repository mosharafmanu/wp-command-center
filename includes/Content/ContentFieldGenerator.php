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
	 * @param array  $context { actor?: array } — the admin who triggered it.
	 * @return array { action, kind, batch_id, provider, model, created[], skipped[], failed[] }
	 */
	public function generate( int $post_id, string $kind, array $context = [] ): array {
		$batch_id = wp_generate_uuid4();
		$actor    = ( isset( $context['actor'] ) && is_array( $context['actor'] ) ) ? $context['actor'] : [];

		$created = [];
		$skipped = [];
		$failed  = [];

		// Validate the requested field kind.
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$failed[] = [ 'post_id' => $post_id, 'code' => 'invalid_kind', 'message' => __( 'Unknown content field kind.', 'wp-command-center' ) ];
			return $this->envelope( $kind, $batch_id, '', '', $created, $skipped, $failed );
		}

		// Precondition: an AI text provider must be configured. (No SEO-plugin
		// precondition — these are core WordPress content fields.)
		$provider = $this->resolver->active();
		if ( null === $provider ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => 'no_provider' ];
			return $this->envelope( $kind, $batch_id, '', '', $created, $skipped, $failed );
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
		if ( $this->has_open_proposal( $post_id, $target_type ) ) {
			$skipped[] = [ 'post_id' => $post_id, 'reason' => 'has_open_proposal' ];
			return $this->envelope( $kind, $batch_id, $provider->id(), '', $created, $skipped, $failed );
		}

		$current = ( 'title' === $kind ) ? get_the_title( $post ) : (string) $post->post_excerpt;

		$result = $provider->suggest( $kind, [
			'title'   => get_the_title( $post ),
			'content' => $this->excerpt( (string) $post->post_content ),
			'current' => $current,
		] );

		if ( ! $result->is_ok() ) {
			$err      = $result->get_error();
			$failed[] = [ 'post_id' => $post_id, 'code' => (string) $err['code'], 'message' => (string) $err['message'] ];
			return $this->envelope( $kind, $batch_id, $provider->id(), $result->model(), $created, $skipped, $failed );
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
			return $this->envelope( $kind, $batch_id, $provider->id(), $result->model(), $created, $skipped, $failed );
		}

		$created[] = (string) $proposal['proposal_id'];

		return $this->envelope( $kind, $batch_id, $provider->id(), $result->model(), $created, $skipped, $failed );
	}

	/**
	 * Open-proposal dedup via the ProposalStore READ API (no writes). Scoped by
	 * target_type so each field kind dedups independently.
	 */
	private function has_open_proposal( int $post_id, string $target_type ): bool {
		$tid = (string) $post_id;
		if ( $this->store->count( [ 'target_id' => $tid, 'operation_id' => 'content_manage', 'target_type' => $target_type, 'status' => ProposalStore::STATUS_DRAFT ] ) > 0 ) {
			return true;
		}
		return $this->store->count( [ 'target_id' => $tid, 'operation_id' => 'content_manage', 'target_type' => $target_type, 'status' => ProposalStore::STATUS_PENDING_APPROVAL ] ) > 0;
	}

	/** Plain-text, bounded content excerpt for the prompt. */
	private function excerpt( string $content ): string {
		$text = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		if ( mb_strlen( $text ) > self::EXCERPT_CHARS ) {
			$text = mb_substr( $text, 0, self::EXCERPT_CHARS );
		}
		return $text;
	}

	private function envelope( string $kind, string $batch_id, string $provider, string $model, array $created, array $skipped, array $failed ): array {
		return [
			'action'   => 'content_field_generate',
			'kind'     => $kind,
			'batch_id' => $batch_id,
			'provider' => $provider,
			'model'    => $model,
			'created'  => $created,
			'skipped'  => $skipped,
			'failed'   => $failed,
		];
	}
}
