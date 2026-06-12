<?php
/**
 * Step 33 - read-only site health verification and result history.
 */

namespace WPCommandCenter\Health;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class HealthVerificationEngine {

	public function verify( array $actor = [] ): array|\WP_Error {
		global $wpdb;
		$id      = wp_generate_uuid4();
		$started = time();
		$audit   = new AuditLog();

		$audit->record( 'health.verification.started', [
			'verification_id' => $id,
			'actor'           => AuditLog::resolve_actor( $actor ),
		] );

		try {
			$checks = [
				$this->http_check( 'frontend_health', 'Frontend health', home_url( '/' ) ),
				$this->http_check( 'admin_health', 'wp-admin health', admin_url() ),
				$this->http_check( 'rest_api_health', 'REST API health', rest_url() ),
				$this->http_check( 'wpcc_api_health', 'WPCC API health', rest_url( 'wp-command-center/v1/health' ), true ),
				$this->woocommerce_check(),
				$this->plugin_integrity_check(),
				$this->theme_integrity_check(),
			];

			$failed  = count( array_filter( $checks, static fn ( array $check ): bool => 'failed' === $check['status'] ) );
			$warning = count( array_filter( $checks, static fn ( array $check ): bool => 'warning' === $check['status'] ) );
			$status  = $failed > 0 ? 'failed' : ( $warning > 0 ? 'warning' : 'passed' );
			$summary = [ 'total' => count( $checks ), 'passed' => count( $checks ) - $failed - $warning, 'warnings' => $warning, 'failed' => $failed ];
			$completed = time();

			$inserted = $wpdb->insert( $this->table(), [
				'verification_id' => $id,
				'status'          => $status,
				'checks_json'     => wp_json_encode( $checks ),
				'summary_json'    => wp_json_encode( $summary ),
				'started_at'      => $started,
				'completed_at'    => $completed,
				'created_at'      => $completed,
			] );
			if ( false === $inserted ) {
				throw new \RuntimeException( 'Could not persist health verification.' );
			}

			$event = 'failed' === $status ? 'health.verification.failed' : 'health.verification.completed';
			$audit->record( $event, [
				'verification_id' => $id,
				'status'          => $status,
				'summary'         => $summary,
				'actor'           => AuditLog::resolve_actor( $actor ),
			] );

			return $this->get( $id );
		} catch ( \Throwable $exception ) {
			$audit->record( 'health.verification.failed', [
				'verification_id' => $id,
				'status'          => 'failed',
				'error'           => $exception->getMessage(),
				'actor'           => AuditLog::resolve_actor( $actor ),
			] );
			return new \WP_Error( 'wpcc_health_verification_failed', __( 'Health verification failed.', 'wp-command-center' ) );
		}
	}

	public function list( array $filters = [] ): array {
		global $wpdb;
		$sql = "SELECT * FROM {$this->table()}";
		$params = [];
		if ( ! empty( $filters['status'] ) ) {
			$sql .= ' WHERE status = %s';
			$params[] = $filters['status'];
		}
		$sql .= ' ORDER BY id DESC' . $wpdb->prepare( ' LIMIT %d OFFSET %d', max( 1, min( 100, (int) ( $filters['limit'] ?? 20 ) ) ), max( 0, (int) ( $filters['offset'] ?? 0 ) ) );
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}
		return array_map( [ $this, 'normalize' ], $wpdb->get_results( $sql, ARRAY_A ) ?: [] );
	}

	public function get( string $verification_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE verification_id = %s", $verification_id ), ARRAY_A );
		return $row ? $this->normalize( $row ) : null;
	}

	private function http_check( string $id, string $label, string $url, bool $accept_auth_error = false ): array {
		$start    = microtime( true );
		$response = wp_safe_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3, 'user-agent' => 'WP-Command-Center-Health/' . WPCC_VERSION ] );
		$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return $this->check( $id, $label, 'failed', $response->get_error_message(), [ 'url' => $url, 'duration_ms' => $elapsed ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$passed = $code >= 200 && $code < 400;
		if ( $accept_auth_error && in_array( $code, [ 401, 403 ], true ) ) {
			$passed = true;
		}
		return $this->check( $id, $label, $passed ? 'passed' : 'failed', sprintf( 'HTTP %d returned in %d ms.', $code, $elapsed ), [ 'url' => $url, 'http_status' => $code, 'duration_ms' => $elapsed ] );
	}

	private function woocommerce_check(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->check( 'woocommerce_health', 'WooCommerce health', 'warning', 'WooCommerce is not active; store-specific health was skipped.' );
		}
		$version = defined( 'WC_VERSION' ) ? WC_VERSION : '';
		$db      = (string) get_option( 'woocommerce_db_version' );
		$valid   = '' !== $version && '' !== $db && version_compare( $db, $version, '>=' );
		return $this->check( 'woocommerce_health', 'WooCommerce health', $valid ? 'passed' : 'warning', $valid ? 'WooCommerce is active and its database schema is current.' : 'WooCommerce is active but its database schema may require an update.', [ 'version' => $version, 'db_version' => $db ] );
	}

	private function plugin_integrity_check(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active  = (array) get_option( 'active_plugins', [] );
		$missing = [];
		foreach ( $active as $plugin_file ) {
			$path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				$missing[] = $plugin_file;
			}
		}
		return $this->check( 'plugin_integrity', 'Plugin integrity', $missing ? 'failed' : 'passed', $missing ? 'One or more active plugin entry files are missing or unreadable.' : sprintf( '%d active plugin entry file(s) are readable.', count( $active ) ), [ 'checked' => count( $active ), 'invalid' => $missing ] );
	}

	private function theme_integrity_check(): array {
		$theme = wp_get_theme();
		$paths = [ trailingslashit( $theme->get_stylesheet_directory() ) . 'style.css' ];
		if ( $theme->parent() ) {
			$paths[] = trailingslashit( $theme->get_template_directory() ) . 'style.css';
		}
		$invalid = array_values( array_filter( $paths, static fn ( string $path ): bool => ! is_file( $path ) || ! is_readable( $path ) ) );
		return $this->check( 'theme_integrity', 'Theme integrity', $invalid ? 'failed' : 'passed', $invalid ? 'The active theme stylesheet is missing or unreadable.' : 'Active theme stylesheet files are readable.', [ 'theme' => $theme->get_stylesheet(), 'invalid' => array_map( 'basename', $invalid ) ] );
	}

	private function check( string $id, string $label, string $status, string $message, array $context = [] ): array {
		return compact( 'id', 'label', 'status', 'message', 'context' );
	}

	private function normalize( array $row ): array {
		return [
			'verification_id' => $row['verification_id'],
			'status'          => $row['status'],
			'checks'          => json_decode( (string) $row['checks_json'], true ) ?: [],
			'summary'         => json_decode( (string) $row['summary_json'], true ) ?: [],
			'started_at'      => (int) $row['started_at'],
			'completed_at'    => $row['completed_at'] ? (int) $row['completed_at'] : null,
			'created_at'      => (int) $row['created_at'],
		];
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcc_health_verifications';
	}
}
