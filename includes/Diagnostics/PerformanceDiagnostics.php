<?php
/**
 * Layer 2 — performance diagnostics: cache analysis, memory usage,
 * and plugin impact analysis, built on top of the Site Intelligence
 * snapshot (Layer 1).
 */

namespace WPCommandCenter\Diagnostics;

use WPCommandCenter\SiteIntelligence\SiteScanner;

defined( 'ABSPATH' ) || exit;

final class PerformanceDiagnostics extends AbstractDiagnostics {

	public function analyze( ?array $site_data = null ): array {
		$site_data ??= ( new SiteScanner() )->scan();

		return [
			$this->check_memory_limit( $site_data['php'] ),
			$this->check_memory_usage(),
			$this->check_object_cache( $site_data['cache'] ),
			$this->check_opcache( $site_data['cache'] ),
			$this->check_page_cache( $site_data['cache'] ),
			$this->check_autoloaded_options(),
			$this->check_active_plugins( $site_data['plugins'] ),
			$this->check_cron(),
		];
	}

	private function check_memory_limit( array $php ): array {
		$raw   = $php['memory_limit'];
		$bytes = wp_convert_hr_to_bytes( $raw );

		if ( $bytes <= 0 ) {
			$status      = self::STATUS_GOOD;
			$description = __( 'No PHP memory limit is set (unlimited).', 'wp-command-center' );
		} elseif ( $bytes < 64 * MB_IN_BYTES ) {
			$status      = self::STATUS_CRITICAL;
			$description = __( 'PHP memory limit is below 64 MB and may cause "allowed memory size exhausted" fatal errors.', 'wp-command-center' );
		} elseif ( $bytes < 128 * MB_IN_BYTES ) {
			$status      = self::STATUS_RECOMMENDED;
			$description = __( 'PHP memory limit is below the recommended 128 MB minimum.', 'wp-command-center' );
		} else {
			$status      = self::STATUS_GOOD;
			$description = __( 'PHP memory limit is sufficient.', 'wp-command-center' );
		}

		return $this->check( 'memory_limit', __( 'PHP Memory Limit', 'wp-command-center' ), $status, sprintf( '%s (%s)', $description, $raw ) );
	}

	private function check_memory_usage(): array {
		return $this->check(
			'memory_usage',
			__( 'Current Request Memory Usage', 'wp-command-center' ),
			self::STATUS_INFO,
			sprintf(
				/* translators: 1: current memory usage, 2: peak memory usage */
				__( 'Current: %1$s, Peak: %2$s', 'wp-command-center' ),
				size_format( memory_get_usage() ),
				size_format( memory_get_peak_usage() )
			)
		);
	}

	private function check_object_cache( array $cache ): array {
		$enabled = $cache['object_cache_enabled'];

		return $this->check(
			'object_cache',
			__( 'External Object Cache', 'wp-command-center' ),
			$enabled ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$enabled
				? __( 'A persistent object cache (e.g. Redis/Memcached) is active.', 'wp-command-center' )
				: __( 'No persistent object cache detected. Database-heavy sites benefit from one.', 'wp-command-center' )
		);
	}

	private function check_opcache( array $cache ): array {
		$enabled = $cache['opcache_enabled'];

		return $this->check(
			'opcache',
			__( 'OPcache', 'wp-command-center' ),
			$enabled ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$enabled
				? __( 'PHP OPcache is enabled.', 'wp-command-center' )
				: __( 'PHP OPcache is not enabled. Enabling it significantly speeds up PHP execution.', 'wp-command-center' )
		);
	}

	private function check_page_cache( array $cache ): array {
		if ( $cache['page_cache_dropin'] || ! empty( $cache['caching_plugins'] ) ) {
			$source = ! empty( $cache['caching_plugins'] )
				? implode( ', ', $cache['caching_plugins'] )
				: __( 'advanced-cache.php drop-in', 'wp-command-center' );

			return $this->check(
				'page_cache',
				__( 'Page Caching', 'wp-command-center' ),
				self::STATUS_GOOD,
				sprintf(
					/* translators: %s: detected caching plugin(s) or drop-in */
					__( 'Page caching detected: %s.', 'wp-command-center' ),
					$source
				)
			);
		}

		return $this->check(
			'page_cache',
			__( 'Page Caching', 'wp-command-center' ),
			self::STATUS_RECOMMENDED,
			__( 'No page caching plugin or drop-in detected.', 'wp-command-center' )
		);
	}

	private function check_autoloaded_options(): array {
		global $wpdb;

		$bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" );

		if ( $bytes > 2 * MB_IN_BYTES ) {
			$status = self::STATUS_CRITICAL;
		} elseif ( $bytes > 800 * KB_IN_BYTES ) {
			$status = self::STATUS_RECOMMENDED;
		} else {
			$status = self::STATUS_GOOD;
		}

		return $this->check(
			'autoloaded_options',
			__( 'Autoloaded Options Size', 'wp-command-center' ),
			$status,
			sprintf(
				/* translators: %s: formatted size of autoloaded options */
				__( 'Total size of autoloaded options: %s. Large autoloaded data is loaded on every page request.', 'wp-command-center' ),
				size_format( $bytes )
			)
		);
	}

	private function check_active_plugins( array $plugins ): array {
		$count = count( $plugins );

		return $this->check(
			'active_plugins_count',
			__( 'Active Plugins', 'wp-command-center' ),
			$count > 50 ? self::STATUS_RECOMMENDED : self::STATUS_INFO,
			sprintf(
				/* translators: %d: number of active plugins */
				_n( '%d plugin is active.', '%d plugins are active.', $count, 'wp-command-center' ),
				$count
			)
		);
	}

	private function check_cron(): array {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return $this->check(
			'wp_cron',
			__( 'WP-Cron', 'wp-command-center' ),
			self::STATUS_INFO,
			$disabled
				? __( 'WP-Cron is disabled via DISABLE_WP_CRON. Ensure a real system cron job triggers wp-cron.php.', 'wp-command-center' )
				: __( 'WP-Cron runs on page loads (default behavior).', 'wp-command-center' )
		);
	}
}
