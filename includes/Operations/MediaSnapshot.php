<?php
/**
 * STEP 100.1 — File-level media snapshot service.
 *
 * Captures an attachment's BYTES — the original file plus every generated size
 * file plus its `_wp_attachment_metadata` — and restores them byte-for-byte.
 * This is the safety primitive that makes file-mutating media operations
 * (replace, thumbnail regenerate, optimize, cleanup) truly reversible.
 *
 * Media files live under `uploads/`, which the code-oriented Snapshot/PathGuard
 * system intentionally excludes; so this service keeps its own byte store under
 * `uploads/wpcc-media-snapshots/` (directory-listing denied). Exposed via a thin
 * set of `media_manage` actions (media_snapshot_create/restore/verify/list) so it
 * is exercisable over REST and MCP. Capture never mutates live files; restore
 * rewrites the captured bytes.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class MediaSnapshot {

	private const STORE   = 'wpcc_media_file_snapshots';
	private const MAX     = 200;
	private const SUBDIR  = 'wpcc-media-snapshots';

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	/**
	 * Snapshot an attachment's original + all size files + metadata.
	 *
	 * @return array{id:string,attachment_id:int,files:int}|\WP_Error
	 */
	public function capture( int $attachment_id, string $label = '' ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}

		$files = $this->attachment_files( $attachment_id );
		if ( empty( $files ) ) {
			return new \WP_Error( 'wpcc_media_no_files', __( 'Attachment has no files on disk to snapshot.', 'wp-command-center' ) );
		}

		$id  = wp_generate_uuid4();
		$dir = $this->snapshot_dir( $id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$captured = [];
		$n        = 0;
		foreach ( $files as $abs ) {
			$store_name = $n . '.snap';
			$dest       = trailingslashit( $dir ) . $store_name;
			if ( ! @copy( $abs, $dest ) ) {
				$this->rmdir_recursive( $dir );
				return new \WP_Error( 'wpcc_media_snapshot_copy_failed', __( 'Failed to copy a media file into the snapshot store.', 'wp-command-center' ) );
			}
			$captured[] = [
				'rel_path'   => $this->to_relative( $abs ),
				'store_name' => $store_name,
				'hash'       => md5_file( $abs ),
				'size'       => filesize( $abs ) ?: 0,
			];
			$n++;
		}

		$store   = get_option( self::STORE, [] );
		$store[] = [
			'id'            => $id,
			'attachment_id' => $attachment_id,
			'label'         => sanitize_text_field( $label ),
			'files'         => $captured,
			'metadata'      => wp_get_attachment_metadata( $attachment_id ),
			'attached_file' => get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'created_at'    => time(),
		];
		while ( count( $store ) > self::MAX ) {
			$oldest = array_shift( $store );
			$this->purge_store_dir( (string) ( $oldest['id'] ?? '' ) );
		}
		update_option( self::STORE, $store );

		$this->audit->record( 'media.snapshot.captured', [ 'snapshot_id' => $id, 'attachment_id' => $attachment_id, 'files' => count( $captured ) ] );

		return [ 'id' => $id, 'attachment_id' => $attachment_id, 'files' => count( $captured ) ];
	}

	/**
	 * Restore a media snapshot — rewrite every captured file to its captured
	 * bytes and restore the metadata. Byte-for-byte.
	 *
	 * @return array{restored:bool,files_restored:int,verified:bool}|\WP_Error
	 */
	public function restore( string $snapshot_id ) {
		$record = $this->find( $snapshot_id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$base     = $this->store_basedir();
		$dir      = trailingslashit( $base ) . $snapshot_id;
		$restored = 0;
		$verified = true;

		foreach ( $record['files'] as $f ) {
			$source = trailingslashit( $dir ) . $f['store_name'];
			$abs    = $this->to_absolute( $f['rel_path'] );
			if ( ! is_file( $source ) ) {
				$verified = false;
				continue;
			}
			if ( ! wp_mkdir_p( dirname( $abs ) ) || ! @copy( $source, $abs ) ) {
				$verified = false;
				continue;
			}
			if ( md5_file( $abs ) !== $f['hash'] ) {
				$verified = false;
			}
			$restored++;
		}

		if ( is_array( $record['metadata'] ) ) {
			wp_update_attachment_metadata( (int) $record['attachment_id'], $record['metadata'] );
		}
		if ( ! empty( $record['attached_file'] ) ) {
			update_post_meta( (int) $record['attachment_id'], '_wp_attached_file', $record['attached_file'] );
		}

		$this->audit->record( 'media.snapshot.restored', [ 'snapshot_id' => $snapshot_id, 'attachment_id' => $record['attachment_id'], 'files_restored' => $restored, 'verified' => $verified ] );

		return [ 'restored' => true, 'files_restored' => $restored, 'verified' => $verified ];
	}

	/**
	 * Verify a snapshot's integrity: each stored copy still matches its recorded
	 * hash, and whether the current live file still matches the captured bytes.
	 *
	 * @return array{valid:bool,files:array}|\WP_Error
	 */
	public function verify( string $snapshot_id ) {
		$record = $this->find( $snapshot_id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$dir   = trailingslashit( $this->store_basedir() ) . $snapshot_id;
		$valid = true;
		$files = [];
		foreach ( $record['files'] as $f ) {
			$source        = trailingslashit( $dir ) . $f['store_name'];
			$snapshot_ok   = is_file( $source ) && md5_file( $source ) === $f['hash'];
			$abs           = $this->to_absolute( $f['rel_path'] );
			$matches_now   = is_file( $abs ) && md5_file( $abs ) === $f['hash'];
			if ( ! $snapshot_ok ) {
				$valid = false;
			}
			$files[] = [
				'path'            => $f['rel_path'],
				'snapshot_intact' => $snapshot_ok,
				'matches_current' => $matches_now,
			];
		}

		return [ 'valid' => $valid, 'files' => $files ];
	}

	/** @return array<int,array{id:string,attachment_id:int,files:int,created_at:int}> */
	public function list( int $attachment_id = 0 ): array {
		$store = get_option( self::STORE, [] );
		$out   = [];
		foreach ( $store as $r ) {
			if ( $attachment_id > 0 && (int) $r['attachment_id'] !== $attachment_id ) {
				continue;
			}
			$out[] = [
				'id'            => $r['id'],
				'attachment_id' => (int) $r['attachment_id'],
				'label'         => $r['label'] ?? '',
				'files'         => count( (array) ( $r['files'] ?? [] ) ),
				'created_at'    => (int) $r['created_at'],
			];
		}
		return array_reverse( $out );
	}

	/** Delete a media snapshot and its stored bytes. */
	public function delete( string $snapshot_id ): bool {
		$store = get_option( self::STORE, [] );
		$kept  = [];
		$found = false;
		foreach ( $store as $r ) {
			if ( $r['id'] === $snapshot_id ) {
				$found = true;
				continue;
			}
			$kept[] = $r;
		}
		if ( $found ) {
			$this->purge_store_dir( $snapshot_id );
			update_option( self::STORE, $kept );
		}
		return $found;
	}

	// ── Helpers ──────────────────────────────────────────────────

	/** @return array<int,string> Absolute paths: original + each existing size file (deduped). */
	private function attachment_files( int $attachment_id ): array {
		$original = get_attached_file( $attachment_id );
		$files    = [];
		if ( $original && is_file( $original ) ) {
			$files[ wp_normalize_path( $original ) ] = wp_normalize_path( $original );
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && $original ) {
			$base = trailingslashit( dirname( $original ) );
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$abs = wp_normalize_path( $base . $size['file'] );
				if ( is_file( $abs ) ) {
					$files[ $abs ] = $abs;
				}
			}
		}
		return array_values( $files );
	}

	private function store_basedir(): string {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . self::SUBDIR;
	}

	/** Create (and harden) the per-snapshot storage directory. @return string|\WP_Error */
	private function snapshot_dir( string $id ) {
		$base = $this->store_basedir();
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return new \WP_Error( 'wpcc_media_snapshot_mkdir_failed', __( 'Failed to create the media snapshot store.', 'wp-command-center' ) );
		}
		// Deny directory listing / direct access.
		if ( ! is_file( trailingslashit( $base ) . 'index.php' ) ) {
			@file_put_contents( trailingslashit( $base ) . 'index.php', "<?php // Silence is golden.\n" );
		}
		$dir = trailingslashit( $base ) . $id;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'wpcc_media_snapshot_mkdir_failed', __( 'Failed to create the media snapshot directory.', 'wp-command-center' ) );
		}
		return $dir;
	}

	private function purge_store_dir( string $id ): void {
		if ( '' === $id ) {
			return;
		}
		$this->rmdir_recursive( trailingslashit( $this->store_basedir() ) . $id );
	}

	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( $dir );
	}

	private function to_relative( string $absolute ): string {
		$base = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		return ltrim( str_replace( $base, '', wp_normalize_path( $absolute ) ), '/' );
	}

	private function to_absolute( string $relative ): string {
		return trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . ltrim( $relative, '/' );
	}

	/** @return array|\WP_Error */
	private function find( string $snapshot_id ) {
		foreach ( get_option( self::STORE, [] ) as $r ) {
			if ( $r['id'] === $snapshot_id ) {
				return $r;
			}
		}
		return new \WP_Error( 'wpcc_media_snapshot_not_found', __( 'Media snapshot not found.', 'wp-command-center' ) );
	}
}
