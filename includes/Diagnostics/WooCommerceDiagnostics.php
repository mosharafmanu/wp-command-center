<?php
/**
 * Layer 2 — WooCommerce diagnostics: scheduled actions, payment
 * gateway status, and template overrides.
 */

namespace WPCommandCenter\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class WooCommerceDiagnostics extends AbstractDiagnostics {

	public function analyze(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				$this->check(
					'woocommerce_inactive',
					__( 'WooCommerce', 'wp-command-center' ),
					self::STATUS_INFO,
					__( 'WooCommerce is not active on this site.', 'wp-command-center' )
				),
			];
		}

		return [
			$this->check_db_version(),
			$this->check_payment_gateways(),
			$this->check_scheduled_actions(),
			$this->check_template_overrides(),
		];
	}

	private function check_db_version(): array {
		$db_version = get_option( 'woocommerce_db_version' );
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '';
		$up_to_date = $db_version && $wc_version && version_compare( $db_version, $wc_version, '>=' );

		return $this->check(
			'woocommerce_db_version',
			__( 'WooCommerce Database Version', 'wp-command-center' ),
			$up_to_date ? self::STATUS_GOOD : self::STATUS_RECOMMENDED,
			$up_to_date
				? sprintf(
					/* translators: %s: database version */
					__( 'WooCommerce database schema is up to date (%s).', 'wp-command-center' ),
					$db_version
				)
				: sprintf(
					/* translators: 1: database version, 2: plugin version */
					__( 'WooCommerce database version (%1$s) does not match the plugin version (%2$s). A database update may be pending.', 'wp-command-center' ),
					$db_version ?: __( 'unknown', 'wp-command-center' ),
					$wc_version ?: __( 'unknown', 'wp-command-center' )
				)
		);
	}

	private function check_payment_gateways(): array {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$count    = count( $gateways );

		if ( 0 === $count ) {
			return $this->check(
				'woocommerce_payment_gateways',
				__( 'Payment Gateways', 'wp-command-center' ),
				self::STATUS_CRITICAL,
				__( 'No payment gateways are enabled. Customers will not be able to complete checkout.', 'wp-command-center' )
			);
		}

		$names = wp_list_pluck( $gateways, 'title' );

		return $this->check(
			'woocommerce_payment_gateways',
			__( 'Payment Gateways', 'wp-command-center' ),
			self::STATUS_GOOD,
			sprintf(
				/* translators: %s: comma-separated list of enabled payment gateways */
				__( 'Enabled payment gateways: %s.', 'wp-command-center' ),
				implode( ', ', array_map( 'wp_strip_all_tags', $names ) )
			)
		);
	}

	private function check_scheduled_actions(): array {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return $this->check(
				'woocommerce_scheduled_actions',
				__( 'Scheduled Actions', 'wp-command-center' ),
				self::STATUS_INFO,
				__( 'Action Scheduler is not available.', 'wp-command-center' )
			);
		}

		$store = \ActionScheduler_Store::instance();

		$failed  = (int) $store->query_actions( [ 'status' => \ActionScheduler_Store::STATUS_FAILED ], 'count' );
		$pending = (int) $store->query_actions( [ 'status' => \ActionScheduler_Store::STATUS_PENDING ], 'count' );

		if ( $failed > 0 ) {
			return $this->check(
				'woocommerce_scheduled_actions',
				__( 'Scheduled Actions', 'wp-command-center' ),
				self::STATUS_RECOMMENDED,
				sprintf(
					/* translators: 1: number of failed actions, 2: number of pending actions */
					__( '%1$d scheduled action(s) have failed, %2$d are pending. Failed actions may indicate a recurring error.', 'wp-command-center' ),
					$failed,
					$pending
				)
			);
		}

		return $this->check(
			'woocommerce_scheduled_actions',
			__( 'Scheduled Actions', 'wp-command-center' ),
			self::STATUS_GOOD,
			sprintf(
				/* translators: %d: number of pending actions */
				__( 'No failed scheduled actions. %d pending.', 'wp-command-center' ),
				$pending
			)
		);
	}

	private function check_template_overrides(): array {
		$overrides = $this->find_template_overrides();

		if ( empty( $overrides ) ) {
			return $this->check(
				'woocommerce_template_overrides',
				__( 'Template Overrides', 'wp-command-center' ),
				self::STATUS_GOOD,
				__( 'No theme template overrides found.', 'wp-command-center' )
			);
		}

		$outdated = array_filter( $overrides, static fn( array $override ): bool => $override['outdated'] );

		if ( ! empty( $outdated ) ) {
			return $this->check(
				'woocommerce_template_overrides',
				__( 'Template Overrides', 'wp-command-center' ),
				self::STATUS_RECOMMENDED,
				sprintf(
					/* translators: 1: number of outdated overrides, 2: total number of overrides */
					__( '%1$d of %2$d overridden WooCommerce template(s) are outdated compared to the installed plugin version.', 'wp-command-center' ),
					count( $outdated ),
					count( $overrides )
				)
			);
		}

		return $this->check(
			'woocommerce_template_overrides',
			__( 'Template Overrides', 'wp-command-center' ),
			self::STATUS_INFO,
			sprintf(
				/* translators: %d: number of overridden templates */
				_n( '%d theme template override found, all up to date.', '%d theme template overrides found, all up to date.', count( $overrides ), 'wp-command-center' ),
				count( $overrides )
			)
		);
	}

	/**
	 * @return array<int, array{file: string, theme_version: string|null, core_version: string|null, outdated: bool}>
	 */
	private function find_template_overrides(): array {
		if ( ! defined( 'WC_ABSPATH' ) ) {
			return [];
		}

		$core_template_dir = WC_ABSPATH . 'templates/';
		$theme_dirs         = array_unique(
			array_filter(
				[
					trailingslashit( get_stylesheet_directory() ) . 'woocommerce/',
					trailingslashit( get_template_directory() ) . 'woocommerce/',
				]
			)
		);

		$overrides = [];

		foreach ( $theme_dirs as $theme_dir ) {
			if ( ! is_dir( $theme_dir ) ) {
				continue;
			}

			$files = $this->scan_directory( $theme_dir );

			foreach ( $files as $relative_path ) {
				$core_template = $core_template_dir . $relative_path;

				if ( ! file_exists( $core_template ) ) {
					continue;
				}

				$theme_version = $this->get_template_version( $theme_dir . $relative_path );
				$core_version  = $this->get_template_version( $core_template );

				$outdated = $theme_version && $core_version && version_compare( $theme_version, $core_version, '<' );

				$overrides[ $relative_path ] = [
					'file'          => $relative_path,
					'theme_version' => $theme_version,
					'core_version'  => $core_version,
					'outdated'      => (bool) $outdated,
				];
			}
		}

		return array_values( $overrides );
	}

	/**
	 * @return array<int, string> Paths relative to $dir.
	 */
	private function scan_directory( string $dir ): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = ltrim( str_replace( $dir, '', $file->getPathname() ), '/' );
			}
		}

		return $files;
	}

	private function get_template_version( string $file ): ?string {
		$contents = file_get_contents( $file, false, null, 0, 8192 );

		if ( false === $contents ) {
			return null;
		}

		if ( preg_match( '/@version\s+([0-9][0-9a-zA-Z.\-]*)/', $contents, $matches ) ) {
			return $matches[1];
		}

		return null;
	}
}
