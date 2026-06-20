<?php
/**
 * STEP 111 — GA#2 Slice 2b: SEO meta provider → Proposal Store bridge.
 *
 * Turns AI SEO suggestions into governed DRAFTS. For each explicitly-selected post
 * it asks the active SeoMetaProvider for {meta_title, meta_description} and, on
 * success, creates a draft via ProposalStore::create(). That is the ONLY write it
 * performs.
 *
 * Propose != Apply: this class NEVER applies, NEVER calls OperationExecutor /
 * ProposalApplyService / SeoProvider::write, NEVER writes post meta / posts /
 * options / change_log, and NEVER mutates the site. The drafts it creates are
 * reviewed and applied later through the existing governed apply path (the draft's
 * payload is a seo_manage/seo_update request the deployed runtime already runs).
 *
 * Allowed collaborators: SeoMetaProviderResolver (suggestion), SeoProvider (read-
 * only current meta + plugin detect), ProposalStore::create (the single write), and
 * read-only WordPress post lookups.
 */

namespace WPCommandCenter\Seo;

use WPCommandCenter\Proposals\ProposalStore;
use WPCommandCenter\Operations\SeoProvider;

defined( 'ABSPATH' ) || exit;

final class SeoMetaGenerator {

	/** Hard cap on a single explicit selection (bounded; no bulk-at-scale here). */
	public const MAX_BATCH = 25;

	/**
	 * Editable content statuses SEO suggestions may be generated for — the single
	 * source of truth shared with the contextual entry points (SeoRowActions) so the
	 * UI never offers an action the generator would then skip. SEO meta is worth
	 * preparing before publishing, so drafts/pending/scheduled/private are included.
	 * Everything else (trash / auto-draft / inherit [revisions, attachments] / any
	 * other) is intentionally excluded and skipped with reason `unsupported_status`.
	 */
	public const SUPPORTED_STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private' ];

	/** Whether SEO suggestions may be generated for a given post status. */
	public static function is_supported_status( string $status ): bool {
		return in_array( $status, self::SUPPORTED_STATUSES, true );
	}

	/** Content excerpt cap fed to the provider (~1.5k tokens). */
	private const EXCERPT_CHARS = 6000;

	private ProposalStore $store;
	private SeoMetaProviderResolver $resolver;

	public function __construct( ?ProposalStore $store = null, ?SeoMetaProviderResolver $resolver = null ) {
		$this->store    = $store ?? new ProposalStore();
		$this->resolver = $resolver ?? new SeoMetaProviderResolver();
	}

	/**
	 * Generate draft SEO meta proposals for an explicit set of post ids.
	 *
	 * @param int[] $post_ids Explicit selection (deduped, capped).
	 * @param array $context  { actor?: array } — the admin who triggered it.
	 * @return array { action, batch_id, provider, model, created[], skipped[], failed[] }
	 */
	public function generate( array $post_ids, array $context = [] ): array {
		$batch_id = wp_generate_uuid4();
		$actor    = ( isset( $context['actor'] ) && is_array( $context['actor'] ) ) ? $context['actor'] : [];

		$created = [];
		$skipped = [];
		$failed  = [];

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( $i ) => $i > 0 ) ) );
		if ( count( $ids ) > self::MAX_BATCH ) {
			$ids = array_slice( $ids, 0, self::MAX_BATCH );
		}

		// Precondition 1: an SEO plugin must be active (to read current meta + to
		// apply later). NONE -> skip all, no calls made.
		$seo_provider = SeoProvider::detect();
		if ( SeoProvider::NONE === $seo_provider ) {
			foreach ( $ids as $id ) {
				$skipped[] = [ 'post_id' => $id, 'reason' => 'no_seo_plugin' ];
			}
			return $this->envelope( $batch_id, '', '', $created, $skipped, $failed );
		}

		// Precondition 2: an AI text provider must be configured.
		$provider = $this->resolver->active();
		if ( null === $provider ) {
			foreach ( $ids as $id ) {
				$skipped[] = [ 'post_id' => $id, 'reason' => 'no_provider' ];
			}
			return $this->envelope( $batch_id, '', '', $created, $skipped, $failed );
		}

		$used_model = '';

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' === $post->post_type ) {
				$skipped[] = [ 'post_id' => $id, 'reason' => 'not_found' ];
				continue;
			}
			// Allow editable statuses (publish/draft/pending/future/private); skip the
			// rest (trash/auto-draft/inherit/…). Same allow-list the row action uses.
			if ( ! self::is_supported_status( (string) $post->post_status ) ) {
				$skipped[] = [ 'post_id' => $id, 'reason' => 'unsupported_status' ];
				continue;
			}
			if ( $this->has_open_proposal( $id ) ) {
				$skipped[] = [ 'post_id' => $id, 'reason' => 'has_open_proposal' ];
				continue;
			}

			$current = SeoProvider::read( $id, $seo_provider );

			$result = $provider->suggest_meta( [
				'post_id'             => $id,
				'title'               => get_the_title( $post ),
				'content'             => $this->excerpt( (string) $post->post_content ),
				'current_title'       => (string) $current['title'],
				'current_description' => (string) $current['description'],
			] );

			if ( ! $result->is_ok() ) {
				$err      = $result->get_error();
				$failed[] = [ 'post_id' => $id, 'code' => (string) $err['code'], 'message' => (string) $err['message'] ];
				continue; // per-item failure never aborts the run
			}

			$used_model = $result->model();

			$proposal = $this->store->create( [
				'operation_id' => 'seo_manage',
				'action'       => 'seo_update',
				'target_type'  => 'post',
				'target_id'    => (string) $id,
				'payload'      => [
					'action'     => 'seo_update',
					'content_id' => $id,
					'seo'        => [ 'title' => $result->meta_title(), 'description' => $result->meta_description() ],
				],
				'prior'        => [ 'title' => (string) $current['title'], 'description' => (string) $current['description'] ],
				'provider'     => $result->provider(),
				'model'        => $result->model(),
				'confidence'   => null,
				'batch_id'     => $batch_id,
				'proposed_by'  => $actor,
			] );

			if ( is_wp_error( $proposal ) ) {
				$failed[] = [ 'post_id' => $id, 'code' => $proposal->get_error_code(), 'message' => $proposal->get_error_message() ];
				continue;
			}
			$created[] = (string) $proposal['proposal_id'];
		}

		return $this->envelope( $batch_id, $provider->id(), $used_model, $created, $skipped, $failed );
	}

	/** Open-proposal dedup via the ProposalStore READ API (no writes). */
	private function has_open_proposal( int $id ): bool {
		$tid = (string) $id;
		if ( $this->store->count( [ 'target_id' => $tid, 'operation_id' => 'seo_manage', 'status' => ProposalStore::STATUS_DRAFT ] ) > 0 ) {
			return true;
		}
		return $this->store->count( [ 'target_id' => $tid, 'operation_id' => 'seo_manage', 'status' => ProposalStore::STATUS_PENDING_APPROVAL ] ) > 0;
	}

	/** Plain-text, bounded content excerpt for the prompt. */
	private function excerpt( string $content ): string {
		$text = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		if ( mb_strlen( $text ) > self::EXCERPT_CHARS ) {
			$text = mb_substr( $text, 0, self::EXCERPT_CHARS );
		}
		return $text;
	}

	private function envelope( string $batch_id, string $provider, string $model, array $created, array $skipped, array $failed ): array {
		return [
			'action'   => 'seo_meta_generate',
			'batch_id' => $batch_id,
			'provider' => $provider,
			'model'    => $model,
			'created'  => $created,
			'skipped'  => $skipped,
			'failed'   => $failed,
		];
	}
}
