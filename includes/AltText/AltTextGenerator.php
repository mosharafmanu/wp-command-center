<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7C: provider → Proposal Store bridge.
 *
 * Turns AI suggestions into governed DRAFTS. For each explicitly-selected image
 * it asks the active provider for alt text and, on success, creates a draft via
 * ProposalStore::create(). That is the ONLY write it performs.
 *
 * Propose ≠ Apply: this class NEVER applies, NEVER calls OperationExecutor /
 * ProposalApplyService / OperationManager, NEVER writes attachment meta / posts /
 * options / change_log, and NEVER mutates the site. The drafts it creates are
 * reviewed and applied later through the existing governed apply path.
 *
 * Allowed collaborators: ProviderResolver (suggestion), ProposalStore::create
 * (the single write), and read-only WordPress attachment lookups.
 */

namespace WPCommandCenter\AltText;

use WPCommandCenter\Proposals\ProposalStore;

defined( 'ABSPATH' ) || exit;

final class AltTextGenerator {

	/** Hard cap on a single explicit selection (bounded; no bulk-at-scale here). */
	public const MAX_BATCH = 25;

	private ProposalStore $store;
	private ProviderResolver $resolver;

	public function __construct( ?ProposalStore $store = null, ?ProviderResolver $resolver = null ) {
		$this->store    = $store ?? new ProposalStore();
		$this->resolver = $resolver ?? new ProviderResolver();
	}

	/**
	 * Generate draft alt-text proposals for an explicit set of attachment ids.
	 *
	 * @param int[]  $attachment_ids Explicit selection (deduped, capped).
	 * @param array  $context        { actor?: array } — the admin who triggered it.
	 * @return array { action, batch_id, provider, model, created[], skipped[], failed[] }
	 */
	public function generate( array $attachment_ids, array $context = [] ): array {
		$batch_id = wp_generate_uuid4();
		$actor    = ( isset( $context['actor'] ) && is_array( $context['actor'] ) ) ? $context['actor'] : [];

		$created = [];
		$skipped = [];
		$failed  = [];

		// Explicit ids only: sanitize, drop non-positive, dedupe, cap.
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ), static fn( $i ) => $i > 0 ) ) );
		if ( count( $ids ) > self::MAX_BATCH ) {
			$ids = array_slice( $ids, 0, self::MAX_BATCH );
		}

		$provider = $this->resolver->active();
		if ( null === $provider ) {
			// Safe degradation: nothing configured — every id is skipped, no calls made.
			foreach ( $ids as $id ) {
				$skipped[] = [ 'attachment_id' => $id, 'reason' => 'no_provider' ];
			}
			return $this->envelope( $batch_id, '', '', $created, $skipped, $failed );
		}

		$used_model = '';

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				$skipped[] = [ 'attachment_id' => $id, 'reason' => 'not_found' ];
				continue;
			}
			$mime = (string) get_post_mime_type( $id );
			if ( 0 !== strpos( $mime, 'image/' ) ) {
				$skipped[] = [ 'attachment_id' => $id, 'reason' => 'not_image' ];
				continue;
			}
			if ( $this->has_open_proposal( $id ) ) {
				$skipped[] = [ 'attachment_id' => $id, 'reason' => 'has_open_proposal' ];
				continue;
			}

			$path = (string) get_attached_file( $id );
			if ( '' === $path || ! is_file( $path ) ) {
				$skipped[] = [ 'attachment_id' => $id, 'reason' => 'file_missing' ];
				continue;
			}

			$current_alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

			$result = $provider->suggest_alt(
				[ 'attachment_id' => $id, 'path' => $path, 'mime' => $mime ],
				[ 'title' => get_the_title( $id ), 'filename' => basename( $path ) ]
			);

			if ( ! $result->is_ok() ) {
				$err      = $result->get_error();
				$failed[] = [ 'attachment_id' => $id, 'code' => (string) $err['code'], 'message' => (string) $err['message'] ];
				continue; // per-image failure never aborts the run
			}

			$used_model = $result->model();

			$proposal = $this->store->create( [
				'operation_id' => 'media_manage',
				'action'       => 'media_update',
				'target_type'  => 'attachment',
				'target_id'    => (string) $id,
				'payload'      => [ 'action' => 'media_update', 'media_id' => $id, 'alt' => $result->text() ],
				'prior'        => [ 'alt' => $current_alt ],
				'provider'     => $result->provider(),
				'model'        => $result->model(),
				'confidence'   => $result->confidence(), // may be null
				'batch_id'     => $batch_id,
				'proposed_by'  => $actor,
			] );

			if ( is_wp_error( $proposal ) ) {
				$failed[] = [ 'attachment_id' => $id, 'code' => $proposal->get_error_code(), 'message' => $proposal->get_error_message() ];
				continue;
			}
			$created[] = (string) $proposal['proposal_id'];
		}

		return $this->envelope( $batch_id, $provider->id(), $used_model, $created, $skipped, $failed );
	}

	/** Open-proposal dedup via the ProposalStore READ API (no writes). */
	private function has_open_proposal( int $id ): bool {
		$tid = (string) $id;
		if ( $this->store->count( [ 'target_id' => $tid, 'status' => ProposalStore::STATUS_DRAFT ] ) > 0 ) {
			return true;
		}
		return $this->store->count( [ 'target_id' => $tid, 'status' => ProposalStore::STATUS_PENDING_APPROVAL ] ) > 0;
	}

	private function envelope( string $batch_id, string $provider, string $model, array $created, array $skipped, array $failed ): array {
		return [
			'action'   => 'alt_text_generate',
			'batch_id' => $batch_id,
			'provider' => $provider,
			'model'    => $model,
			'created'  => $created,
			'skipped'  => $skipped,
			'failed'   => $failed,
		];
	}
}
