<?php
/**
 * Experience Layer — App Shell + the "Three Doors, One Engine" information architecture.
 *
 * The single source of truth for WP Command Center's navigation. Per the canonical
 * UX Master Blueprint (§2) it presents SIX product-language sections —
 * Home · Built-in AI · Connect · Activity · History · Settings — that map onto the
 * platform's "Three Doors, One Engine" model: Built-in AI (Door 1), Connect
 * (Doors 2 & 3: AI Clients over MCP + API & Integrations over REST), and the engine's
 * activity / history / policy surfaces. Each section is rendered as a branded shell
 * (header + sub-tab bar) hosting the EXISTING view files in a content canvas. It adds
 * no REST routes, operations, capabilities, MCP tools, or schema: it only re-frames
 * where the existing read/write surfaces live, how they are reached, and what they
 * are called — so the user thinks in product terms, never in architecture terms.
 *
 * Section-tab selection uses the namespaced `?wpcc_tab=` query arg so it never
 * collides with a hosted view's own `?tab=` / `?view=` sub-navigation. Every legacy
 * URL keeps working: AdminMenu calls resolve_legacy() to map an old slug (and, for
 * the retired 5-C section slugs, the old `wpcc_tab` value) onto the new section + tab,
 * passing through every other original query arg so deep links survive.
 *
 * Builder vs Engineer disclosure/density is a client-side concern (wpcc-cds.js +
 * data-wpcc-mode); the shell renders the toggle, the ⌘K trigger, and the live
 * security-posture pill.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class AppShell {

	/** The Home (Mission Control) section slug = the plugin's top-level menu slug. */
	public const HOME_SLUG = 'wp-command-center';

	/** Built-in AI (Door 1). */
	public const BUILTIN_SLUG = 'wpcc-built-in-ai';

	/** Connect (Doors 2 & 3 — AI Clients + API & Integrations). */
	public const CONNECT_SLUG = 'wpcc-connect';

	/** Activity (the live engine feed + approvals). */
	public const ACTIVITY_SLUG = 'wpcc-activity';

	/** History (every change + undo). */
	public const HISTORY_SLUG = 'wpcc-history';

	/** Settings (rules + advanced controls). */
	public const SETTINGS_SLUG = 'wpcc-settings';

	/**
	 * The six section slugs in menu order. Home is the top-level (HOME_SLUG).
	 *
	 * @var array<string,string> slug => i18n label (resolved at runtime).
	 */
	public const SECTION_SLUGS = [
		self::HOME_SLUG     => 'Home',
		self::BUILTIN_SLUG  => 'Built-in AI',
		self::CONNECT_SLUG  => 'Connect',
		self::ACTIVITY_SLUG => 'Activity',
		self::HISTORY_SLUG  => 'History',
		self::SETTINGS_SLUG => 'Settings',
	];

	/**
	 * Retired standalone (pre-5-C) slugs → [ section slug, wpcc_tab ]. These slugs
	 * never carried a `wpcc_tab` of their own, so the mapping is unconditional. Used
	 * by resolve_legacy() to redirect old bookmarks/deep-links into the new IA.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function legacy_map(): array {
		return [
			// Phase A standalone surfaces.
			'wpcc-dashboard-overview' => [ self::HOME_SLUG, '' ],
			'wpcc-approval-center'    => [ self::ACTIVITY_SLUG, 'approvals' ],
			'wpcc-approvals'          => [ self::ACTIVITY_SLUG, 'approvals' ], // pre-106 slug
			'wpcc-operations'         => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'capabilities' ] ],
			'wpcc-operations-center'  => [ self::ACTIVITY_SLUG, 'live' ],
			'wpcc-change-history'     => [ self::HISTORY_SLUG, 'changes' ],
			'wpcc-rollback'           => [ self::HISTORY_SLUG, 'changes' ],     // pre-105.3 slug
			'wpcc-patches'            => [ self::SETTINGS_SLUG, 'diagnostics', [ 'dpane' => 'patches' ] ],
			'wpcc-diagnostics'        => [ self::SETTINGS_SLUG, 'diagnostics' ],
			'wpcc-site-intelligence'  => [ self::SETTINGS_SLUG, 'diagnostics', [ 'dpane' => 'sitereport' ] ],
			'wpcc-tokens'             => [ self::SETTINGS_SLUG, 'access' ],
			// NOTE: the retired Security-Mode page shared today's live Settings slug
			// (`wpcc-settings`). It is intentionally NOT mapped here — a live section
			// slug must never resolve as a legacy slug (that would self-redirect /
			// loop). resolve_legacy() short-circuits live section slugs; the old
			// `?page=wpcc-settings&section=tokens` deep-link is handled separately in
			// AdminMenu before resolution.
			'wpcc-ai-integrations'    => [ self::CONNECT_SLUG, 'clients' ],
			'wpcc-ai-setup'           => [ self::BUILTIN_SLUG, 'providers' ],
			'wpcc-file-access'        => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'files' ] ],
			// Build-flagged AI surfaces (only reachable when their flag is on).
			'wpcc-proposals'          => [ self::ACTIVITY_SLUG, 'drafts' ],
			'wpcc-alt-text'           => [ self::BUILTIN_SLUG, 'alt_text' ],
			'wpcc-seo'                => [ self::BUILTIN_SLUG, 'seo' ],
			'wpcc-ai-content'         => [ self::BUILTIN_SLUG, 'content' ],
		];
	}

	/**
	 * Retired 5-C SECTION slugs → [ old wpcc_tab => [ new section slug, new wpcc_tab ] ].
	 * The 5-C IA (Overview · Operate · Audit · Access · Connect) hosted multiple tabs
	 * per section; the new IA re-homes them. resolve_legacy() consults this with the
	 * incoming `wpcc_tab` so a deep link like `…?page=wpcc-operate&wpcc_tab=approvals`
	 * lands on its exact new home. A `*` key is the section's no-tab default.
	 *
	 * Note `wpcc-connect` is reused as a live slug but its tab keys changed, so its old
	 * tabs are remapped here too.
	 *
	 * @return array<string,array<string,array{0:string,1:string}>>
	 */
	public static function legacy_tab_map(): array {
		return [
			'wpcc-operate' => [
				'*'          => [ self::ACTIVITY_SLUG, 'live' ],
				'center'     => [ self::ACTIVITY_SLUG, 'live' ],
				'approvals'  => [ self::ACTIVITY_SLUG, 'approvals' ],
				'operations' => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'capabilities' ] ],
				'runtime'    => [ self::SETTINGS_SLUG, 'diagnostics' ],
				'drafts'     => [ self::ACTIVITY_SLUG, 'drafts' ],
				'alt_text'   => [ self::BUILTIN_SLUG, 'alt_text' ],
				'seo'        => [ self::BUILTIN_SLUG, 'seo' ],
				'ai_content' => [ self::BUILTIN_SLUG, 'content' ],
			],
			'wpcc-audit' => [
				'*'            => [ self::HISTORY_SLUG, 'changes' ],
				'changes'      => [ self::HISTORY_SLUG, 'changes' ],
				'patches'      => [ self::SETTINGS_SLUG, 'diagnostics', [ 'dpane' => 'patches' ] ],
				'diagnostics'  => [ self::SETTINGS_SLUG, 'diagnostics' ],
				'intelligence' => [ self::SETTINGS_SLUG, 'diagnostics', [ 'dpane' => 'sitereport' ] ],
			],
			'wpcc-access' => [
				'*'        => [ self::SETTINGS_SLUG, 'access' ],
				'tokens'   => [ self::SETTINGS_SLUG, 'access' ],
				'security' => [ self::SETTINGS_SLUG, 'security' ],
			],
			// Old Connect tab keys (setup/integrations/files) → new homes.
			'wpcc-connect' => [
				'setup'        => [ self::BUILTIN_SLUG, 'providers' ],
				'integrations' => [ self::CONNECT_SLUG, 'clients' ],
				'files'        => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'files' ] ],
			],
			// Phase 2B: Settings sub-tabs retired into the Diagnostics/Advanced hubs.
			// Only the RETIRED keys are listed here; current tabs (security/access/tools/
			// diagnostics/advanced) are absent, so they render directly via the live-section
			// short-circuit (no self-redirect, no loop).
			'wpcc-settings' => [
				'runtime'         => [ self::SETTINGS_SLUG, 'diagnostics' ],
				'patches'         => [ self::SETTINGS_SLUG, 'diagnostics', [ 'dpane' => 'patches' ] ],
				'intelligence'    => [ self::SETTINGS_SLUG, 'diagnostics', [ 'dpane' => 'sitereport' ] ],
				'recommendations' => [ self::SETTINGS_SLUG, 'diagnostics', [ 'dpane' => 'recommendations' ] ],
				'files'           => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'files' ] ],
				'capabilities'    => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'capabilities' ] ],
			],
		];
	}

	/**
	 * Resolve a (possibly legacy) page slug + incoming wpcc_tab to its canonical
	 * [ section_slug, wpcc_tab ] in the current IA, or null when no migration applies
	 * (the slug is already current, or unknown). AdminMenu uses this for redirects.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function resolve_legacy( string $page, string $tab = '' ): ?array {
		// A slug that is itself one of today's live sections must NEVER be treated as
		// a legacy slug — doing so self-redirects and loops (this was the Settings
		// redirect-loop bug). Live sections only need tab-aware remapping for a slug
		// that is *reused* but whose tab keys changed (Connect: setup/integrations/
		// files → new homes). Any current/empty tab on a live section renders as-is.
		$is_live_section = isset( self::SECTION_SLUGS[ $page ] );

		// Retired section slugs (and reused live slugs carrying an OLD tab) resolve by tab.
		$tab_map = self::legacy_tab_map();
		if ( isset( $tab_map[ $page ] ) ) {
			$section_tabs = $tab_map[ $page ];
			if ( '' !== $tab && isset( $section_tabs[ $tab ] ) ) {
				$result = $section_tabs[ $tab ];
				// Guard: never emit a no-op redirect to the exact same page+tab.
				return ( $result[0] === $page && $result[1] === $tab ) ? null : $result;
			}
			// A live/reused section slug with a new or empty tab is already current.
			if ( $is_live_section ) {
				return null;
			}
			if ( isset( $section_tabs['*'] ) ) {
				return $section_tabs['*'];
			}
		}

		// Live section slugs render as-is — they are not legacy standalone slugs.
		if ( $is_live_section ) {
			return null;
		}

		// Standalone retired slugs (no tab of their own).
		$map = self::legacy_map();
		if ( isset( $map[ $page ] ) ) {
			return $map[ $page ];
		}

		return null;
	}

	/**
	 * Build the full six-section/tab tree, already filtered by FeatureGate and the
	 * dev/build flags. Each tab: [ label, view (file stem), feature (key|null) ].
	 *
	 * @return array<string,array{label:string,desc:string,tabs:array<string,array{label:string,view:string,feature:?string}>}>
	 */
	public static function sections(): array {
		// Door 1 — Built-in AI. Providers is always present; the generation tools
		// (SEO / Alt Text / Content) appear only when their build flag + FeatureGate
		// allow, so the section never promises a tool the site can't actually run.
		$builtin_tabs = [
			'providers' => [ 'label' => __( 'Providers', 'wp-command-center' ), 'view' => 'ai-setup', 'feature' => null ],
		];
		if ( self::flag( 'WPCC_SEO_META_UI', 'wpcc_seo_meta_ui' ) && FeatureGate::allows( 'seo_meta_generator' ) ) {
			$builtin_tabs['seo'] = [ 'label' => __( 'SEO', 'wp-command-center' ), 'view' => 'seo-meta', 'feature' => null ];
		}
		if ( self::flag( 'WPCC_ALT_TEXT_UI', 'wpcc_alt_text_ui' ) && FeatureGate::allows( 'ai_alt_text' ) ) {
			$builtin_tabs['alt_text'] = [ 'label' => __( 'Alt Text', 'wp-command-center' ), 'view' => 'ai-alt-text', 'feature' => null ];
		}
		if ( self::flag( 'WPCC_AI_CONTENT_UI', 'wpcc_ai_content_ui' ) && ( FeatureGate::allows( 'title_generator' ) || FeatureGate::allows( 'excerpt_generator' ) ) ) {
			$builtin_tabs['content'] = [ 'label' => __( 'Content', 'wp-command-center' ), 'view' => 'ai-content', 'feature' => null ];
		}

		// Activity — the live feed + approvals. The dev-only proposal store folds in
		// here (review queue) when its flag is on.
		$activity_tabs = [
			'live'      => [ 'label' => __( 'Live', 'wp-command-center' ),      'view' => 'operations-center', 'feature' => null ],
			'approvals' => [ 'label' => __( 'Approvals', 'wp-command-center' ), 'view' => 'approval-center',   'feature' => 'approval_center' ],
		];
		if ( self::flag( 'WPCC_PROPOSALS_DEV_UI', 'wpcc_proposals_dev_ui' ) && FeatureGate::allows( 'proposal_store' ) ) {
			$activity_tabs['drafts'] = [ 'label' => __( 'Drafts (Dev)', 'wp-command-center' ), 'view' => 'proposals', 'feature' => null ];
		}

		$tree = [
			self::HOME_SLUG => [
				'label' => __( 'Home', 'wp-command-center' ),
				'desc'  => __( 'Mission control: what needs you, what changed, and where to start.', 'wp-command-center' ),
				'tabs'  => [
					'home' => [ 'label' => __( 'Home', 'wp-command-center' ), 'view' => 'command-home', 'feature' => null ],
				],
			],
			self::BUILTIN_SLUG => [
				'label' => __( 'Built-in AI', 'wp-command-center' ),
				'desc'  => __( 'Use AI to do work on your site: connect a provider, then generate — every change is reviewed and reversible.', 'wp-command-center' ),
				'tabs'  => $builtin_tabs,
			],
			self::CONNECT_SLUG => [
				'label' => __( 'Connect', 'wp-command-center' ),
				'desc'  => __( 'Let an external AI assistant or your own app act on this site — safely, under approval and audit.', 'wp-command-center' ),
				'tabs'  => [
					'clients' => [ 'label' => __( 'AI Clients', 'wp-command-center' ),        'view' => 'ai-integrations', 'feature' => null ],
					'api'     => [ 'label' => __( 'API & Integrations', 'wp-command-center' ), 'view' => 'api-integrations', 'feature' => null ],
				],
			],
			self::ACTIVITY_SLUG => [
				'label' => __( 'Activity', 'wp-command-center' ),
				'desc'  => __( 'What is happening right now, and what is waiting for your sign-off.', 'wp-command-center' ),
				'tabs'  => $activity_tabs,
			],
			self::HISTORY_SLUG => [
				'label' => __( 'History', 'wp-command-center' ),
				'desc'  => __( 'Every change to your site — review it, and undo what is reversible.', 'wp-command-center' ),
				'tabs'  => [
					'changes' => [ 'label' => __( 'Changes', 'wp-command-center' ), 'view' => 'change-history', 'feature' => 'change_history' ],
				],
			],
			self::SETTINGS_SLUG => [
				'label' => __( 'Settings', 'wp-command-center' ),
				'desc'  => __( 'Rules and advanced controls: security mode, access, diagnostics, and developer tools.', 'wp-command-center' ),
				// Phase 2B: five intent-grouped tabs. Diagnostics and Advanced are hubs that
				// host the formerly-separate surfaces via a second-level ?dpane=/?apane= sub-nav.
				// Runtime is RETIRED — its tools live in Tools + Diagnostics › Recommendations;
				// its engine internals are deferred to a future Developer-mode Engine Inspector.
				'tabs'  => [
					'security'    => [ 'label' => __( 'Security & Approvals', 'wp-command-center' ), 'view' => 'settings',              'feature' => null ],
					'access'      => [ 'label' => __( 'Access', 'wp-command-center' ),               'view' => 'token-capability-manager', 'feature' => 'token_capability_manager' ],
					'tools'       => [ 'label' => __( 'Tools', 'wp-command-center' ),                'view' => 'tools-search-replace',  'feature' => null ],
					'diagnostics' => [ 'label' => __( 'Diagnostics', 'wp-command-center' ),          'view' => 'settings-diagnostics',  'feature' => null ],
					'advanced'    => [ 'label' => __( 'Advanced', 'wp-command-center' ),             'view' => 'settings-advanced',     'feature' => null ],
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

	/**
	 * Build/dev flag with Phase-4 in-admin enablement, in strict precedence:
	 *   1. A DEFINED constant is site configuration and wins (on OR off).
	 *   2. A truthy `wpcc_*_ui` filter is a programmatic opt-in.
	 *   3. Otherwise the in-admin per-tool option governs (default off; only the three
	 *      built-in AI tools are option-backed — other flags resolve to false here).
	 */
	private static function flag( string $const, string $filter ): bool {
		if ( defined( $const ) ) {
			return (bool) constant( $const );
		}
		if ( (bool) apply_filters( $filter, false ) ) {
			return true;
		}
		return BuiltinAiSettings::enabled_by_option( $const );
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
		$is_home = ( self::HOME_SLUG === $section_slug );

		$mode  = SecurityModeManager::current();
		$label = SecurityModeManager::label();
		?>
		<div class="wrap wpcc-app" data-wpcc-mode="builder" data-wpcc-density="comfortable">
			<div class="wpcc-shell__bar">
				<h1 class="wpcc-shell__brand">
					<span class="wpcc-shell__brand-mark" aria-hidden="true">&#9783;</span>
					<?php esc_html_e( 'Command Center', 'wp-command-center' ); ?>
					<?php if ( $is_home ) : ?>
						<span class="wpcc-shell__brand-section"><?php esc_html_e( 'Mission Control', 'wp-command-center' ); ?></span>
					<?php else : ?>
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
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection, no state change.
					$requested = isset( $_GET['wpcc_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpcc_tab'] ) ) : '';
					$active    = isset( $tabs[ $requested ] ) ? $requested : array_key_first( $tabs );
					foreach ( $tabs as $key => $tab ) :
						?>
						<a class="wpcc-shell__tab <?php echo $key === $active ? 'is-active' : ''; ?>"
							href="<?php echo esc_url( admin_url( 'admin.php?page=' . $section_slug . '&wpcc_tab=' . $key ) ); ?>"
							<?php echo $key === $active ? 'aria-current="page"' : ''; ?>>
							<?php echo esc_html( $tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php if ( ! empty( $section['desc'] ) ) : ?>
				<p class="wpcc-shell__desc" style="margin:10px 0 4px;color:#50575e;max-width:760px;font-size:13px;"><?php echo esc_html( $section['desc'] ); ?></p>
			<?php endif; ?>

			<?php
			// Honest helper for the Built-in AI section when its generation tools are
			// not enabled on this site (build-flag gated — contradiction C1). It does
			// NOT fake a toggle; it tells the user where the tools live and that they
			// are turned on per-site, so a first-timer who sees only "Providers" is
			// never left wondering where SEO / Alt Text / Content are.
			if ( self::BUILTIN_SLUG === $section_slug
				&& ! isset( $tabs['seo'] ) && ! isset( $tabs['alt_text'] ) && ! isset( $tabs['content'] ) ) :
				?>
				<p class="wpcc-builtin-note" role="note" style="margin:6px 0 4px;padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:0 4px 4px 0;max-width:760px;font-size:13px;color:#1d2327;">
					<?php esc_html_e( 'Connect a provider below to power WP Command Center’s AI. The SEO, Alt Text, and Content tools appear here as tabs once enabled for this site — they are turned on per site and do not switch on just by adding a key.', 'wp-command-center' ); ?>
				</p>
			<?php endif; ?>

			<div class="wpcc-shell__canvas">
				<?php
				if ( empty( $tabs ) ) {
					// Every tab gated off (licensing seam): instruct, never show a blank page.
					$this->render_empty_section( $section['label'] );
				} else {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection.
					$requested = isset( $_GET['wpcc_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpcc_tab'] ) ) : '';
					$active    = isset( $tabs[ $requested ] ) ? $requested : array_key_first( $tabs );
					$this->require_view( $tabs[ $active ]['view'] );
				}
				?>
			</div>
		</div>
		<?php
	}

	/** A graceful, instructive empty state when a whole section is gated off. */
	private function render_empty_section( string $label ): void {
		?>
		<div class="wpcc-cds-empty" role="status">
			<p><strong><?php echo esc_html( sprintf( /* translators: %s: section name */ __( '%s is not available in this edition.', 'wp-command-center' ), $label ) ); ?></strong></p>
			<p class="description"><?php esc_html_e( 'This area is gated by your current plan. Everything else in WP Command Center stays available.', 'wp-command-center' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::HOME_SLUG ) ); ?>"><?php esc_html_e( 'Back to Home', 'wp-command-center' ); ?></a></p>
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
