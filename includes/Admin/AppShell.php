<?php
/**
 * Experience Layer — App Shell + 5-C information architecture.
 *
 * The single source of truth for WP Command Center's navigation. It collapses the
 * former ~12 flat submenus into five operator-mental-model sections — Overview ·
 * Operate · Audit · Access · Connect — each rendered as a branded shell (header +
 * sub-tab bar) that hosts the EXISTING view files in a content canvas. It adds no
 * REST routes, operations, capabilities, MCP tools, or schema: it only re-frames
 * where the existing read/write surfaces live and how they are reached.
 *
 * Section-tab selection uses the namespaced `?wpcc_tab=` query arg so it never
 * collides with a hosted view's own `?tab=` / `?view=` sub-navigation. Legacy slugs
 * keep working via AdminMenu's redirects, which map the old slug to the new
 * section + wpcc_tab and pass through every original query arg.
 *
 * Builder vs Engineer disclosure/density is a client-side concern (wpcc-cds.js +
 * data-wpcc-mode); the shell renders the toggle, the ⌘K trigger, and the live
 * security-posture pill.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class AppShell {

	/** The Overview/home section slug = the plugin's top-level menu slug. */
	public const HOME_SLUG = 'wp-command-center';

	/**
	 * The 5-C section slugs in menu order. Overview is the home (HOME_SLUG).
	 *
	 * @var array<string,string> slug => i18n label (resolved at runtime).
	 */
	public const SECTION_SLUGS = [
		self::HOME_SLUG => 'Overview',
		'wpcc-operate'  => 'Operate',
		'wpcc-audit'    => 'Audit',
		'wpcc-access'   => 'Access',
		'wpcc-connect'  => 'Connect',
	];

	/**
	 * Legacy slug → [ section slug, wpcc_tab ] migration map. Used by AdminMenu to
	 * redirect old bookmarks/deep-links into the new IA (passing through any other
	 * query args so a hosted view still receives its own tab/view/session_id/etc.).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function legacy_map(): array {
		return [
			// Phase A surfaces folded into sections.
			'wpcc-dashboard-overview' => [ self::HOME_SLUG, '' ],
			'wpcc-approval-center'    => [ 'wpcc-operate', 'approvals' ],
			'wpcc-approvals'          => [ 'wpcc-operate', 'approvals' ], // pre-106 slug
			'wpcc-operations'         => [ 'wpcc-operate', 'operations' ],
			'wpcc-change-history'     => [ 'wpcc-audit', 'changes' ],
			'wpcc-rollback'           => [ 'wpcc-audit', 'changes' ],     // pre-105.3 slug
			'wpcc-patches'            => [ 'wpcc-audit', 'patches' ],
			'wpcc-diagnostics'        => [ 'wpcc-audit', 'diagnostics' ],
			'wpcc-site-intelligence'  => [ 'wpcc-audit', 'intelligence' ],
			'wpcc-tokens'             => [ 'wpcc-access', 'tokens' ],
			'wpcc-settings'           => [ 'wpcc-access', 'security' ],
			'wpcc-ai-integrations'    => [ 'wpcc-connect', 'integrations' ],
			'wpcc-ai-setup'           => [ 'wpcc-connect', 'setup' ],
			'wpcc-file-access'        => [ 'wpcc-connect', 'files' ],
			// Build-flagged AI surfaces (only reachable when their flag is on).
			'wpcc-proposals'          => [ 'wpcc-operate', 'drafts' ],
			'wpcc-alt-text'           => [ 'wpcc-operate', 'alt_text' ],
			'wpcc-seo'                => [ 'wpcc-operate', 'seo' ],
			'wpcc-ai-content'         => [ 'wpcc-operate', 'ai_content' ],
		];
	}

	/**
	 * Build the full 5-C section/tab tree, already filtered by FeatureGate and the
	 * dev/build flags. Each tab: [ label, view (file stem), feature (key|null) ].
	 *
	 * @return array<string,array{label:string,tabs:array<string,array{label:string,view:string,feature:?string}>}>
	 */
	public static function sections(): array {
		$operate_tabs = [
			'approvals'  => [ 'label' => __( 'Approvals', 'wp-command-center' ),  'view' => 'approval-center',     'feature' => 'approval_center' ],
			'operations' => [ 'label' => __( 'Operations', 'wp-command-center' ), 'view' => 'operations-explorer', 'feature' => 'operations_explorer' ],
			'runtime'    => [ 'label' => __( 'Runtime', 'wp-command-center' ),    'view' => 'dashboard',           'feature' => null ],
		];

		// Build-flagged Governed Action surfaces fold under Operate only when enabled.
		if ( self::flag( 'WPCC_PROPOSALS_DEV_UI', 'wpcc_proposals_dev_ui' ) && FeatureGate::allows( 'proposal_store' ) ) {
			$operate_tabs['drafts'] = [ 'label' => __( 'Governed Drafts (Dev)', 'wp-command-center' ), 'view' => 'proposals', 'feature' => null ];
		}
		if ( self::flag( 'WPCC_ALT_TEXT_UI', 'wpcc_alt_text_ui' ) && FeatureGate::allows( 'ai_alt_text' ) ) {
			$operate_tabs['alt_text'] = [ 'label' => __( 'AI Alt Text', 'wp-command-center' ), 'view' => 'ai-alt-text', 'feature' => null ];
		}
		if ( self::flag( 'WPCC_SEO_META_UI', 'wpcc_seo_meta_ui' ) && FeatureGate::allows( 'seo_meta_generator' ) ) {
			$operate_tabs['seo'] = [ 'label' => __( 'SEO Meta', 'wp-command-center' ), 'view' => 'seo-meta', 'feature' => null ];
		}
		if ( self::flag( 'WPCC_AI_CONTENT_UI', 'wpcc_ai_content_ui' ) && ( FeatureGate::allows( 'title_generator' ) || FeatureGate::allows( 'excerpt_generator' ) ) ) {
			$operate_tabs['ai_content'] = [ 'label' => __( 'AI Content', 'wp-command-center' ), 'view' => 'ai-content', 'feature' => null ];
		}

		$tree = [
			self::HOME_SLUG => [
				'label' => __( 'Overview', 'wp-command-center' ),
				'tabs'  => [
					'home' => [ 'label' => __( 'Home', 'wp-command-center' ), 'view' => 'command-home', 'feature' => null ],
				],
			],
			'wpcc-operate' => [
				'label' => __( 'Operate', 'wp-command-center' ),
				'tabs'  => $operate_tabs,
			],
			'wpcc-audit' => [
				'label' => __( 'Audit', 'wp-command-center' ),
				'tabs'  => [
					'changes'      => [ 'label' => __( 'Changes', 'wp-command-center' ),          'view' => 'change-history',    'feature' => 'change_history' ],
					'patches'      => [ 'label' => __( 'Patches', 'wp-command-center' ),          'view' => 'patches',          'feature' => null ],
					'diagnostics'  => [ 'label' => __( 'Diagnostics', 'wp-command-center' ),      'view' => 'diagnostics',      'feature' => null ],
					'intelligence' => [ 'label' => __( 'Site Intelligence', 'wp-command-center' ), 'view' => 'site-intelligence', 'feature' => null ],
				],
			],
			'wpcc-access' => [
				'label' => __( 'Access', 'wp-command-center' ),
				'tabs'  => [
					'tokens'   => [ 'label' => __( 'Tokens & Capabilities', 'wp-command-center' ), 'view' => 'token-capability-manager', 'feature' => 'token_capability_manager' ],
					'security' => [ 'label' => __( 'Security Mode', 'wp-command-center' ),         'view' => 'settings',                'feature' => null ],
				],
			],
			'wpcc-connect' => [
				'label' => __( 'Connect', 'wp-command-center' ),
				'tabs'  => [
					'setup'        => [ 'label' => __( 'AI Setup', 'wp-command-center' ),        'view' => 'ai-setup',        'feature' => null ],
					'integrations' => [ 'label' => __( 'AI Integrations', 'wp-command-center' ), 'view' => 'ai-integrations', 'feature' => null ],
					'files'        => [ 'label' => __( 'File Access', 'wp-command-center' ),     'view' => 'file-access',     'feature' => null ],
				],
			],
		];

		// Drop any tab whose FeatureGate is closed (licensing seam; ungated today).
		foreach ( $tree as $slug => &$section ) {
			foreach ( $section['tabs'] as $key => $tab ) {
				if ( null !== $tab['feature'] && ! FeatureGate::allows( $tab['feature'] ) ) {
					unset( $section['tabs'][ $key ] );
				}
			}
		}
		unset( $section );

		return $tree;
	}

	/** A build/dev flag: true if the constant is truthy OR the filter returns true. */
	private static function flag( string $const, string $filter ): bool {
		if ( defined( $const ) && constant( $const ) ) {
			return true;
		}
		return (bool) apply_filters( $filter, false );
	}

	/**
	 * The navigation map for client consumption (the ⌘K palette): each visible
	 * section + tab as a label + admin URL.
	 *
	 * @return array<int,array{label:string,url:string,tabs:array<int,array{label:string,url:string}>}>
	 */
	public static function nav_map(): array {
		$out = [];
		foreach ( self::sections() as $slug => $section ) {
			if ( self::HOME_SLUG === $slug ) {
				$out[] = [ 'label' => $section['label'], 'url' => admin_url( 'admin.php?page=' . $slug ), 'tabs' => [] ];
				continue;
			}
			$tabs = [];
			foreach ( $section['tabs'] as $key => $tab ) {
				$tabs[] = [
					'label' => $tab['label'],
					'url'   => admin_url( 'admin.php?page=' . $slug . '&wpcc_tab=' . $key ),
				];
			}
			$out[] = [ 'label' => $section['label'], 'url' => admin_url( 'admin.php?page=' . $slug ), 'tabs' => $tabs ];
		}
		return $out;
	}

	/**
	 * Render a section page: the branded shell header, the sub-tab bar, and the
	 * active hosted view inside the content canvas. The active tab comes from
	 * `?wpcc_tab=` and defaults to the section's first visible tab.
	 */
	public function render( string $section_slug ): void {
		$sections = self::sections();
		if ( ! isset( $sections[ $section_slug ] ) ) {
			return;
		}
		$section = $sections[ $section_slug ];
		$tabs    = $section['tabs'];
		if ( empty( $tabs ) ) {
			return; // every tab gated off — nothing to show.
		}

		// Resolve the active tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection, no state change.
		$requested = isset( $_GET['wpcc_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpcc_tab'] ) ) : '';
		$active    = isset( $tabs[ $requested ] ) ? $requested : array_key_first( $tabs );
		$is_home   = ( self::HOME_SLUG === $section_slug );

		$mode  = SecurityModeManager::current();
		$label = SecurityModeManager::label();
		?>
		<div class="wrap wpcc-app" data-wpcc-mode="builder" data-wpcc-density="comfortable">
			<div class="wpcc-shell__bar">
				<h1 class="wpcc-shell__brand">
					<span class="wpcc-shell__brand-mark" aria-hidden="true">&#9783;</span>
					<?php esc_html_e( 'Command Center', 'wp-command-center' ); ?>
					<?php if ( ! $is_home ) : ?>
						<span class="wpcc-shell__brand-section"><?php echo esc_html( $section['label'] ); ?></span>
					<?php endif; ?>
				</h1>
				<div class="wpcc-shell__tools">
					<span class="wpcc-shell__posture" data-mode="<?php echo esc_attr( $mode ); ?>" title="<?php esc_attr_e( 'Current security mode', 'wp-command-center' ); ?>">
						<?php echo esc_html( $label ); ?>
					</span>
					<div class="wpcc-shell__modes" role="group" aria-label="<?php esc_attr_e( 'Display mode', 'wp-command-center' ); ?>">
						<button type="button" class="wpcc-shell__mode" data-mode="builder" aria-pressed="true"><?php esc_html_e( 'Builder', 'wp-command-center' ); ?></button>
						<button type="button" class="wpcc-shell__mode" data-mode="engineer" aria-pressed="false"><?php esc_html_e( 'Engineer', 'wp-command-center' ); ?></button>
					</div>
					<button type="button" class="wpcc-shell__cmdk" aria-haspopup="dialog">
						<?php esc_html_e( 'Search', 'wp-command-center' ); ?> <kbd>&#8984;K</kbd>
					</button>
				</div>
			</div>

			<?php if ( ! $is_home && count( $tabs ) > 0 ) : ?>
				<nav class="wpcc-shell__tabs" aria-label="<?php echo esc_attr( $section['label'] ); ?>">
					<?php foreach ( $tabs as $key => $tab ) : ?>
						<a class="wpcc-shell__tab <?php echo $key === $active ? 'is-active' : ''; ?>"
							href="<?php echo esc_url( admin_url( 'admin.php?page=' . $section_slug . '&wpcc_tab=' . $key ) ); ?>"
							<?php echo $key === $active ? 'aria-current="page"' : ''; ?>>
							<?php echo esc_html( $tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<div class="wpcc-shell__canvas">
				<?php $this->require_view( $tabs[ $active ]['view'] ); ?>
			</div>
		</div>
		<?php
	}

	private function require_view( string $view ): void {
		$path = WPCC_PLUGIN_DIR . "includes/Admin/views/{$view}.php";
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
