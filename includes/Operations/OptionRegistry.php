<?php
/**
 * Step 38 — Option Registry.
 *
 * Defines every supported WordPress option with risk levels, types,
 * and validation rules. All option access must go through this registry.
 * No arbitrary option names.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class OptionRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';
	const RISK_CRITICAL = 'critical';

	const RISK_LEVELS = [ 'low', 'medium', 'high', 'critical' ];

	const TYPE_STRING  = 'string';
	const TYPE_INTEGER = 'integer';
	const TYPE_BOOL    = 'bool';
	const TYPE_EMAIL   = 'email';
	const TYPE_URL     = 'url';

	/**
	 * Structured option definitions.
	 *
	 * @return array<string, array>
	 */
	public function get_options(): array {
		return [
			'site_title' => [
				'option_id'       => 'site_title',
				'option_name'     => 'blogname',
				'title'           => __( 'Site Title', 'wp-command-center' ),
				'description'     => __( 'The site title displayed in the header and browser title bar.', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_LOW,
				'requires_approval' => false,
				'group'           => 'site_settings',
				'validation'      => [
					'min_length' => 1,
					'max_length' => 200,
				],
			],
			'tagline' => [
				'option_id'       => 'tagline',
				'option_name'     => 'blogdescription',
				'title'           => __( 'Tagline', 'wp-command-center' ),
				'description'     => __( 'A short description or tagline for the site.', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_LOW,
				'requires_approval' => false,
				'group'           => 'site_settings',
				'validation'      => [
					'max_length' => 500,
				],
			],
			'timezone' => [
				'option_id'       => 'timezone',
				'option_name'     => 'timezone_string',
				'title'           => __( 'Timezone', 'wp-command-center' ),
				'description'     => __( 'The site timezone string (e.g. America/Chicago).', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_LOW,
				'requires_approval' => false,
				'group'           => 'site_settings',
				'validation'      => [
					'valid_timezone' => true,
				],
			],
			'date_format' => [
				'option_id'       => 'date_format',
				'option_name'     => 'date_format',
				'title'           => __( 'Date Format', 'wp-command-center' ),
				'description'     => __( 'The default date format string.', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_LOW,
				'requires_approval' => false,
				'group'           => 'site_settings',
				'validation'      => [
					'max_length' => 100,
				],
			],
			'time_format' => [
				'option_id'       => 'time_format',
				'option_name'     => 'time_format',
				'title'           => __( 'Time Format', 'wp-command-center' ),
				'description'     => __( 'The default time format string.', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_LOW,
				'requires_approval' => false,
				'group'           => 'site_settings',
				'validation'      => [
					'max_length' => 100,
				],
			],
			'start_of_week' => [
				'option_id'       => 'start_of_week',
				'option_name'     => 'start_of_week',
				'title'           => __( 'Start of Week', 'wp-command-center' ),
				'description'     => __( 'The day the week starts on (0=Sunday, 1=Monday, ...).', 'wp-command-center' ),
				'type'            => self::TYPE_INTEGER,
				'risk_level'      => self::RISK_LOW,
				'requires_approval' => false,
				'group'           => 'site_settings',
				'validation'      => [
					'min' => 0,
					'max' => 6,
				],
			],
			'posts_per_page' => [
				'option_id'       => 'posts_per_page',
				'option_name'     => 'posts_per_page',
				'title'           => __( 'Posts Per Page', 'wp-command-center' ),
				'description'     => __( 'Number of blog posts shown per page.', 'wp-command-center' ),
				'type'            => self::TYPE_INTEGER,
				'risk_level'      => self::RISK_MEDIUM,
				'requires_approval' => true,
				'group'           => 'reading_settings',
				'validation'      => [
					'min' => 1,
					'max' => 500,
				],
			],
			'show_on_front' => [
				'option_id'       => 'show_on_front',
				'option_name'     => 'show_on_front',
				'title'           => __( 'Front Page Displays', 'wp-command-center' ),
				'description'     => __( 'What to show on the front page: posts or a static page.', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_MEDIUM,
				'requires_approval' => true,
				'group'           => 'reading_settings',
				'validation'      => [
					'enum' => [ 'posts', 'page' ],
				],
			],
			'page_on_front' => [
				'option_id'       => 'page_on_front',
				'option_name'     => 'page_on_front',
				'title'           => __( 'Front Page', 'wp-command-center' ),
				'description'     => __( 'The ID of the page shown on the front page (when Front Page Displays is "page").', 'wp-command-center' ),
				'type'            => self::TYPE_INTEGER,
				'risk_level'      => self::RISK_MEDIUM,
				'requires_approval' => true,
				'group'           => 'reading_settings',
				'validation'      => [
					'min'           => 0,
					'valid_page_id' => true,
				],
			],
			'page_for_posts' => [
				'option_id'       => 'page_for_posts',
				'option_name'     => 'page_for_posts',
				'title'           => __( 'Posts Page', 'wp-command-center' ),
				'description'     => __( 'The ID of the page used for blog posts (when Front Page Displays is "page").', 'wp-command-center' ),
				'type'            => self::TYPE_INTEGER,
				'risk_level'      => self::RISK_MEDIUM,
				'requires_approval' => true,
				'group'           => 'reading_settings',
				'validation'      => [
					'min'           => 0,
					'valid_page_id' => true,
				],
			],
			'default_comment_status' => [
				'option_id'       => 'default_comment_status',
				'option_name'     => 'default_comment_status',
				'title'           => __( 'Default Comment Status', 'wp-command-center' ),
				'description'     => __( 'Default comment status for new posts.', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_MEDIUM,
				'requires_approval' => true,
				'group'           => 'discussion_settings',
				'validation'      => [
					'enum' => [ 'open', 'closed' ],
				],
			],
			'default_ping_status' => [
				'option_id'       => 'default_ping_status',
				'option_name'     => 'default_ping_status',
				'title'           => __( 'Default Ping Status', 'wp-command-center' ),
				'description'     => __( 'Default ping/trackback status for new posts.', 'wp-command-center' ),
				'type'            => self::TYPE_STRING,
				'risk_level'      => self::RISK_MEDIUM,
				'requires_approval' => true,
				'group'           => 'discussion_settings',
				'validation'      => [
					'enum' => [ 'open', 'closed' ],
				],
			],
			'admin_email' => [
				'option_id'       => 'admin_email',
				'option_name'     => 'admin_email',
				'title'           => __( 'Administration Email', 'wp-command-center' ),
				'description'     => __( 'The email address used for site administration notifications.', 'wp-command-center' ),
				'type'            => self::TYPE_EMAIL,
				'risk_level'      => self::RISK_HIGH,
				'requires_approval' => true,
				'group'           => 'admin',
				'validation'      => [
					'valid_email' => true,
				],
			],
		];
	}

	/**
	 * Get a single option definition by ID.
	 */
	public function get_option( string $option_id ): ?array {
		$options = $this->get_options();
		return $options[ $option_id ] ?? null;
	}

	/**
	 * Validate a value for a given option.
	 *
	 * @return null|\WP_Error Null on success, WP_Error on failure.
	 */
	public function validate_value( string $option_id, mixed $value ): ?\WP_Error {
		$option = $this->get_option( $option_id );
		if ( null === $option ) {
			return new \WP_Error( 'wpcc_invalid_option_id', __( 'Unknown option ID.', 'wp-command-center' ) );
		}

		$type       = $option['type'];
		$validation = $option['validation'] ?? [];

		// Type check.
		switch ( $type ) {
			case self::TYPE_STRING:
			case self::TYPE_EMAIL:
			case self::TYPE_URL:
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'wpcc_invalid_option_type', sprintf( __( 'Expected string, got %s.', 'wp-command-center' ), gettype( $value ) ) );
				}
				break;
			case self::TYPE_INTEGER:
				if ( ! is_int( $value ) && ! ctype_digit( (string) $value ) ) {
					return new \WP_Error( 'wpcc_invalid_option_type', sprintf( __( 'Expected integer, got %s.', 'wp-command-center' ), gettype( $value ) ) );
				}
				$value = (int) $value;
				break;
			case self::TYPE_BOOL:
				if ( ! is_bool( $value ) && ! in_array( $value, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					return new \WP_Error( 'wpcc_invalid_option_type', __( 'Expected boolean value.', 'wp-command-center' ) );
				}
				break;
		}

		// Min/max length for strings.
		if ( is_string( $value ) ) {
			$len = mb_strlen( $value );
			if ( isset( $validation['min_length'] ) && $len < $validation['min_length'] ) {
				return new \WP_Error( 'wpcc_option_value_too_short', sprintf( __( 'Value must be at least %d characters.', 'wp-command-center' ), $validation['min_length'] ) );
			}
			if ( isset( $validation['max_length'] ) && $len > $validation['max_length'] ) {
				return new \WP_Error( 'wpcc_option_value_too_long', sprintf( __( 'Value must be at most %d characters.', 'wp-command-center' ), $validation['max_length'] ) );
			}
		}

		// Min/max for integers.
		if ( is_int( $value ) ) {
			if ( isset( $validation['min'] ) && $value < $validation['min'] ) {
				return new \WP_Error( 'wpcc_option_value_too_small', sprintf( __( 'Value must be at least %d.', 'wp-command-center' ), $validation['min'] ) );
			}
			if ( isset( $validation['max'] ) && $value > $validation['max'] ) {
				return new \WP_Error( 'wpcc_option_value_too_large', sprintf( __( 'Value must be at most %d.', 'wp-command-center' ), $validation['max'] ) );
			}
		}

		// Enum validation.
		if ( isset( $validation['enum'] ) && ! in_array( $value, $validation['enum'], true ) ) {
			return new \WP_Error( 'wpcc_invalid_option_value', sprintf( __( 'Invalid value. Allowed: %s.', 'wp-command-center' ), implode( ', ', $validation['enum'] ) ) );
		}

		// Valid timezone check.
		if ( ! empty( $validation['valid_timezone'] ) && is_string( $value ) ) {
			if ( ! in_array( $value, timezone_identifiers_list(), true ) ) {
				return new \WP_Error( 'wpcc_invalid_timezone', __( 'Invalid timezone identifier.', 'wp-command-center' ) );
			}
		}

		// Valid email check.
		if ( ! empty( $validation['valid_email'] ) && is_string( $value ) ) {
			if ( ! is_email( $value ) ) {
				return new \WP_Error( 'wpcc_invalid_email', __( 'Invalid email address.', 'wp-command-center' ) );
			}
		}

		// Valid page ID check (must reference a published page or 0).
		if ( ! empty( $validation['valid_page_id'] ) ) {
			$page_id = (int) $value;
			if ( 0 !== $page_id ) {
				$post = get_post( $page_id );
				if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
					return new \WP_Error( 'wpcc_invalid_page_id', __( 'The specified page does not exist or is not published.', 'wp-command-center' ) );
				}
			}
		}

		return null;
	}

	/**
	 * Get all options grouped by their group category.
	 *
	 * @return array<string, array>
	 */
	public function get_by_group(): array {
		$groups = [];
		foreach ( $this->get_options() as $option ) {
			$group = $option['group'];
			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = [];
			}
			$groups[ $group ][] = $option;
		}
		return $groups;
	}

	/**
	 * Get option summaries for manifest/context exposure.
	 *
	 * @return array<int, array>
	 */
	public function get_summary(): array {
		$summary = [];
		foreach ( $this->get_options() as $option ) {
			$summary[] = [
				'option_id'          => $option['option_id'],
				'title'              => $option['title'],
				'type'               => $option['type'],
				'risk_level'         => $option['risk_level'],
				'requires_approval'  => $option['requires_approval'],
				'group'              => $option['group'],
			];
		}
		return $summary;
	}

	/**
	 * Count options by risk level.
	 */
	public function count_by_risk(): array {
		$counts = [ 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0 ];
		foreach ( $this->get_options() as $option ) {
			if ( isset( $counts[ $option['risk_level'] ] ) ) {
				$counts[ $option['risk_level'] ]++;
			}
		}
		return $counts;
	}
}
