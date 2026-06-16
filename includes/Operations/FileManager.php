<?php
/**
 * STEP 87 — file_manage operation handler.
 *
 * Bridges the existing FileAccessApi service to the Operations framework so the
 * SAME read/list/metadata service is reachable through both REST
 * (/operations/file_manage/run) and MCP (file_manage tool). No file-access
 * logic lives here — it delegates to FileAccessApi and adds audit + redaction.
 *
 * Read-only: actions file_read, file_tree, file_metadata. PathGuard deny-list
 * and secret redaction are preserved.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\AiAgent\FileAccessApi;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class FileManager {

	const ACTIONS = [ 'file_read', 'file_tree', 'file_metadata' ];

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_file_action', sprintf( __( 'Invalid action: %s. Use file_read, file_tree, or file_metadata.', 'wp-command-center' ), esc_html( $action ) ) );
		}

		$path = isset( $params['path'] ) ? (string) $params['path'] : '';
		$api  = new FileAccessApi();

		return match ( $action ) {
			'file_read'     => $this->file_read( $api, $path, $context, $params ),
			'file_tree'     => $this->file_tree( $api, $path, $context ),
			'file_metadata' => $this->file_metadata( $api, $path, $context ),
		};
	}

	private function file_read( FileAccessApi $api, string $path, array $context, array $params = [] ): array|\WP_Error {
		if ( '' === $path ) {
			return new \WP_Error( 'wpcc_missing_path', __( 'A file path is required.', 'wp-command-center' ) );
		}

		// STEP 103.0A — paginated reads so large live files can be inspected in
		// chunks. Only forward the keys the caller actually supplied.
		$opts = [];
		foreach ( [ 'line_start', 'line_count', 'byte_offset', 'byte_limit', 'context_before', 'context_after' ] as $k ) {
			if ( isset( $params[ $k ] ) && '' !== $params[ $k ] ) {
				$opts[ $k ] = (int) $params[ $k ];
			}
		}

		$result = $api->read( $path, $opts );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Preserve the REST /files/content guarantee: redact secrets in contents.
		$redacted = ( new Redactor() )->redact( $result['contents'] );
		$result['contents'] = $redacted['text'];
		if ( $redacted['count'] > 0 ) {
			$result['redacted']        = true;
			$result['redaction_count'] = $redacted['count'];
		}

		$result['action'] = 'file_read';
		$this->audit( 'file.read', [ 'path' => $result['path'], 'redacted' => $redacted['count'] > 0 ], $context );

		return $result;
	}

	private function file_tree( FileAccessApi $api, string $path, array $context ): array|\WP_Error {
		$result = $api->list_directory( $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['action'] = 'file_tree';
		$this->audit( 'file.tree', [ 'path' => $result['path'], 'entries' => count( $result['entries'] ) ], $context );

		return $result;
	}

	private function file_metadata( FileAccessApi $api, string $path, array $context ): array|\WP_Error {
		if ( '' === $path ) {
			return new \WP_Error( 'wpcc_missing_path', __( 'A file path is required.', 'wp-command-center' ) );
		}

		$result = $api->meta( $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['action'] = 'file_metadata';
		$this->audit( 'file.metadata', [ 'path' => $result['path'] ], $context );

		return $result;
	}

	private function audit( string $event, array $data, array $context ): void {
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		( new AuditLog() )->record( $event, array_merge( [ 'actor' => $actor ], $data ) );
	}
}
