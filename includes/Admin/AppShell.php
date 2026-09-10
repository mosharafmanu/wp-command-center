<?php
/**
 * Experience Layer — App Shell + the "Three Doors, One Engine" information architecture.
 *
 * The single source of truth for Action Steward's navigation. It presents FIVE
 * product-language sections — Home · Connect · Activity · History · Settings —
 * each rendered as a branded shell (header + sub-tab bar) hosting the EXISTING view
 * files in a content canvas. It adds no REST routes, operations, capabilities, MCP
 * tools, or schema: it only re-frames where the existing read/write surfaces live,
 * how they are reached, and what they are called — so the user thinks in product
 * terms, never in architecture terms.
 *
 * Built-in AI was retired as a sixth section (it is optional and off by default);
 * it now lives at Settings › Built-in AI, and its slug redirects. Tab LABELS are
 * product language and change freely; the tab KEYS are the URL contract and do not.
 *
 * Section-tab selection uses the namespaced `?wpcc_tab=` query arg so it never
 * collides with a hosted view's own `?tab=` / `?view=` sub-navigation. Every legacy
 * URL keeps working: AdminMenu calls resolve_legacy() to map an old slug (and, for
 * the retired 5-C section slugs, the old `wpcc_tab` value) onto the new section + tab,
 * passing through every other original query arg so deep links survive.
 *
 * Simple vs Detailed disclosure/density is a client-side concern (wpcc-cds.js +
 * data-wpcc-mode, whose stored values remain builder/engineer); the shell renders
 * the toggle, the ⌘K trigger, the live security-posture pill, and the
 * `.wp-header-end` marker that keeps admin notices out of the product header.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class AppShell {

	/** The Home (Mission Control) section slug = the plugin's top-level menu slug. */
	public const HOME_SLUG = 'wp-command-center';

	/**
	 * Built-in AI (Door 1) — RETIRED as a primary section in V1.
	 *
	 * Built-in AI is optional: its generation tools are build-flagged OFF by default,
	 * so on a stock install this section was a lone "Providers" screen asking for an
	 * API key to power tools the site had not switched on — while the actual V1
	 * journey (connect an MCP assistant) needs no provider key at all. It now lives
	 * as Settings › Built-in AI. The slug is kept ONLY so old bookmarks resolve.
	 */
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
	 * The five section slugs in menu order. Home is the top-level (HOME_SLUG).
	 * This is also the "is a live section" test used by resolve_legacy(), so a slug
	 * listed here must never also be a redirect source.
	 *
	 * @var array<string,string> slug => i18n label (resolved at runtime).
	 */
	public const SECTION_SLUGS = [
		self::HOME_SLUG     => 'Home',
		self::ACTIVITY_SLUG => 'Approvals',
		self::HISTORY_SLUG  => 'Changes',
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
		// Every retired standalone slug → its home in the redesigned IA.
		// Panes: connections = assistants|api|tokens · advanced = ai|diagnostics|system|capabilities|files|tools|drafts
		return [
			'wpcc-dashboard-overview' => [ self::HOME_SLUG, '' ],
			'wpcc-approval-center'    => [ self::ACTIVITY_SLUG, 'approvals' ],
			'wpcc-approvals'          => [ self::ACTIVITY_SLUG, 'approvals' ], // pre-106 slug
			'wpcc-change-history'     => [ self::HISTORY_SLUG, 'changes' ],
			'wpcc-rollback'           => [ self::HISTORY_SLUG, 'changes' ],     // pre-105.3 slug
			// Connection surfaces.
			self::CONNECT_SLUG        => [ self::SETTINGS_SLUG, 'connections', [ 'cpane' => 'assistants' ] ],
			'wpcc-ai-integrations'    => [ self::SETTINGS_SLUG, 'connections', [ 'cpane' => 'assistants' ] ],
			'wpcc-tokens'             => [ self::SETTINGS_SLUG, 'connections', [ 'cpane' => 'tokens' ] ],
			// Everything engine-facing lives under Advanced.
			'wpcc-operations'         => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'capabilities' ] ],
			'wpcc-operations-center'  => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'system' ] ],
			'wpcc-diagnostics'        => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'diagnostics' ] ],
			'wpcc-patches'            => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'diagnostics' ] ],
			'wpcc-site-intelligence'  => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'diagnostics' ] ],
			'wpcc-file-access'        => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'files' ] ],
			/*
			 * Built-in AI (optional, off by default) and its flag-gated tools.
			 *
			 * Each of these MUST carry its `aipane`, not just `apane => ai`.
			 * The three tools' bulk actions (SeoRowActions, MediaRowActions,
			 * ContentRowActions) redirect to these slugs after generating, and
			 * every one of them resolved to the Built-in AI hub's FIRST pane —
			 * Providers. So the customer selected a page, ran a real generation
			 * that really succeeded, and landed on a provider-key settings screen
			 * with no suggestion and no confirmation, because the result notice is
			 * rendered by the tool's own view. The work was done and the product
			 * showed them somewhere else.
			 */
			self::BUILTIN_SLUG        => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'ai', 'aipane' => 'providers' ] ],
			'wpcc-ai-setup'           => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'ai', 'aipane' => 'providers' ] ],
			'wpcc-alt-text'           => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'ai', 'aipane' => 'alt_text' ] ],
			'wpcc-seo'                => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'ai', 'aipane' => 'seo' ] ],
			'wpcc-ai-content'         => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'ai', 'aipane' => 'content' ] ],
			'wpcc-proposals'          => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'drafts' ] ],
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
		$adv = static fn ( string $pane ): array => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => $pane ] ];
		$con = static fn ( string $pane ): array => [ self::SETTINGS_SLUG, 'connections', [ 'cpane' => $pane ] ];
		// Built-in AI is a hub inside a hub: apane selects it, aipane selects the tool.
		$bai = static fn ( string $pane ): array => [ self::SETTINGS_SLUG, 'advanced', [ 'apane' => 'ai', 'aipane' => $pane ] ];

		return [
			// Pre-5-C section slugs.
			'wpcc-operate' => [
				'*'          => [ self::ACTIVITY_SLUG, 'approvals' ],
				'center'     => $adv( 'system' ),
				'approvals'  => [ self::ACTIVITY_SLUG, 'approvals' ],
				'operations' => $adv( 'capabilities' ),
				'runtime'    => $adv( 'diagnostics' ),
				'drafts'     => $adv( 'drafts' ),
				'alt_text'   => $bai( 'alt_text' ),
				'seo'        => $bai( 'seo' ),
				'ai_content' => $bai( 'content' ),
			],
			'wpcc-audit' => [
				'*'            => [ self::HISTORY_SLUG, 'changes' ],
				'changes'      => [ self::HISTORY_SLUG, 'changes' ],
				'patches'      => $adv( 'diagnostics' ),
				'diagnostics'  => $adv( 'diagnostics' ),
				'intelligence' => $adv( 'diagnostics' ),
			],
			'wpcc-access' => [
				'*'        => $con( 'tokens' ),
				'tokens'   => $con( 'tokens' ),
				'security' => [ self::SETTINGS_SLUG, 'security' ],
			],
			// Retired Connect section: its tabs land on the matching Connections pane.
			self::CONNECT_SLUG => [
				'*'            => $con( 'assistants' ),
				'clients'      => $con( 'assistants' ),
				'integrations' => $con( 'assistants' ),
				'api'          => $con( 'api' ),
				'setup'        => $adv( 'ai' ),
				'files'        => $adv( 'files' ),
			],
			// Retired Built-in AI section. Each old tab keeps landing on its OWN
			// pane inside the hub — see the note in legacy_map(): dropping the
			// `aipane` sends a finished generation to the Providers screen.
			self::BUILTIN_SLUG => [
				'*'         => $bai( 'providers' ),
				'providers' => $bai( 'providers' ),
				'seo'       => $bai( 'seo' ),
				'alt_text'  => $bai( 'alt_text' ),
				'content'   => $bai( 'content' ),
			],
			// Approvals: its former engine-facing siblings moved to Advanced.
			self::ACTIVITY_SLUG => [
				'live'   => $adv( 'system' ),
				'drafts' => $adv( 'drafts' ),
			],
			// Settings: five tabs collapsed to three.
			self::SETTINGS_SLUG => [
				'access'          => $con( 'tokens' ),
				'ai'              => $adv( 'ai' ),
				'diagnostics'     => $adv( 'diagnostics' ),
				'runtime'         => $adv( 'diagnostics' ),
				'tools'           => $adv( 'tools' ),
				'patches'         => $adv( 'diagnostics' ),
				'intelligence'    => $adv( 'diagnostics' ),
				'recommendations' => $adv( 'diagnostics' ),
				'files'           => $adv( 'files' ),
				'capabilities'    => $adv( 'capabilities' ),
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
	 * The Built-in AI panes, gated exactly as before: "Providers" is always present,
	 * and each generation tool (SEO / Alt Text / Content) appears only when BOTH its
	 * build flag and its FeatureGate allow it — so the UI never promises a tool this
	 * site cannot actually run.
	 *
	 * Extracted from sections() when Built-in AI stopped being a primary section, so
	 * this gating stays in ONE place (and stays directly testable) now that the panes
	 * are hosted by the Settings › Built-in AI hub.
	 *
	 * @return array<string,array{label:string,view:string,feature:?string}>
	 */
	public static function builtin_tabs(): array {
		$tabs = [
			'providers' => [ 'label' => __( 'Providers', 'action-steward' ), 'view' => 'ai-setup', 'feature' => null ],
		];
		if ( self::flag( 'WPCC_SEO_META_UI', 'wpcc_seo_meta_ui' ) && FeatureGate::allows( 'seo_meta_generator' ) ) {
			$tabs['seo'] = [ 'label' => __( 'SEO', 'action-steward' ), 'view' => 'seo-meta', 'feature' => null ];
		}
		if ( self::flag( 'WPCC_ALT_TEXT_UI', 'wpcc_alt_text_ui' ) && FeatureGate::allows( 'ai_alt_text' ) ) {
			$tabs['alt_text'] = [ 'label' => __( 'Alt Text', 'action-steward' ), 'view' => 'ai-alt-text', 'feature' => null ];
		}
		if ( self::flag( 'WPCC_AI_CONTENT_UI', 'wpcc_ai_content_ui' ) && ( FeatureGate::allows( 'title_generator' ) || FeatureGate::allows( 'excerpt_generator' ) ) ) {
			$tabs['content'] = [ 'label' => __( 'Content', 'action-steward' ), 'view' => 'ai-content', 'feature' => null ];
		}
		return $tabs;
	}

	/**
	 * Whether the dev-only proposal ("Drafts") surface is switched on for this site.
	 * Build-flagged and off on a stock install; extracted so the Advanced hub and
	 * anything else asking the question share one answer.
	 */
	public static function proposals_ui_enabled(): bool {
		return self::flag( 'WPCC_PROPOSALS_DEV_UI', 'wpcc_proposals_dev_ui' )
			&& FeatureGate::allows( 'proposal_store' );
	}

	/**
	 * Build the full four-section/tab tree, already filtered by FeatureGate and the
	 * dev/build flags. Each tab: [ label, view (file stem), feature (key|null) ].
	 *
	 * @return array<string,array{label:string,desc:string,tabs:array<string,array{label:string,view:string,feature:?string}>}>
	 */
	public static function sections(): array {
		/*
		 * FOUR destinations, named after what the customer wants, not after what the
		 * system contains.
		 *
		 * The previous five were named from the engine's point of view. "Activity"
		 * and "History" were the same object — changes — split by tense, so a user
		 * with a question ("did anything happen to my site?") had to know which of
		 * two screens held the answer. "Connect" is a task you perform once and never
		 * again, yet it held permanent top-level space forever. "Settings" was five
		 * tabs, two of which were themselves hubs.
		 *
		 * A non-technical site owner has three thoughts, in this order:
		 *   nothing is connected yet   → Home owns the whole setup
		 *   something wants to change my site → Approvals
		 *   something changed my site  → Changes
		 * Everything else is Settings.
		 *
		 * Section SLUGS are deliberately unchanged so every existing URL, bookmark,
		 * admin-bar link and redirect keeps resolving; only the labels, the grouping
		 * and what lives inside them are redesigned.
		 */
		/*
		 * `detail` — does this section actually HAVE a Detailed view?
		 *
		 * The Simple/Detailed control was rendered on every section, but the
		 * disclosure it drives (`.wpcc-engineer-only` / `.wpcc-builder-only`)
		 * exists only on Home, Approvals and Changes. On all eight Settings
		 * destinations the toggle changed row spacing and nothing else — a control
		 * labelled "Level of detail" that did not change the level of detail. A
		 * customer who presses it, sees nothing happen and presses it again has
		 * just learned that this product's controls are decorative, on the screens
		 * where they most need to trust them.
		 *
		 * So it is shown where it works. Same rule the shell already applies to the
		 * whole tools group before first connection ($has_started, below): a
		 * control appears when it can do something.
		 */
		$tree = [
			self::HOME_SLUG => [
				'label'  => __( 'Home', 'action-steward' ),
				/*
				 * Home's subtitle is the product's promise, not a description of the
				 * page — so it has to follow the mode. It ended with "You approve
				 * anything that matters" on every site, including a Development one
				 * whose own banner two lines below reads "Approvals are turned off".
				 * The invitation is constant; the guarantee comes from the one place
				 * that knows whether it is true.
				 */
				'desc'   => sprintf(
					/* translators: %s: the mode-aware guarantee sentence. */
					__( 'Ask your AI assistant to change this site, in your own words and your own language. %s', 'action-steward' ),
					SecurityModeManager::promise()
				),
				'detail' => true,
				'tabs'  => [
					'home' => [ 'label' => __( 'Home', 'action-steward' ), 'view' => 'command-home', 'feature' => null ],
				],
			],
			// Was "Activity" — a word that describes nothing a customer wants. This
			// section asks one thing of them: decide. The engine feed and the dev
			// draft surface that used to share it are engine internals; they moved to
			// Settings › Advanced where the rest of the machinery lives.
			self::ACTIVITY_SLUG => [
				'label'  => __( 'Approvals', 'action-steward' ),
				// Mode-aware: on a Development site nothing new is held here, but a
				// queue built up before the switch still is. See approvals_desc().
				'desc'   => SecurityModeManager::approvals_desc(),
				'detail' => true,
				'tabs'  => [
					'approvals' => [ 'label' => __( 'Approvals', 'action-steward' ), 'view' => 'approval-center', 'feature' => 'approval_center' ],
				],
			],
			// Was "History" — the system's word for it. The customer calls these
			// changes, and comes here to see or undo one.
			self::HISTORY_SLUG => [
				'label'  => __( 'Changes', 'action-steward' ),
				'desc'   => __( 'Everything that has changed on this site. Supported changes can be undone from here.', 'action-steward' ),
				'detail' => true,
				'tabs'  => [
					'changes' => [ 'label' => __( 'Changes', 'action-steward' ), 'view' => 'change-history', 'feature' => 'change_history' ],
				],
			],
			self::SETTINGS_SLUG => [
				'label'  => __( 'Settings', 'action-steward' ),
				'desc'   => __( 'How this site is protected, who can reach it, and everything advanced.', 'action-steward' ),
				// No Detailed view: Settings screens are already the detailed ones.
				// Advanced is, by its own subtitle, "everything a normal customer
				// never needs to open" — there is nothing here to progressively
				// disclose, so the control that discloses it is not shown.
				'detail' => false,
				// Five tabs → three, grouped by the question each answers:
				//   Protection  — how much can AI change without asking?
				//   Connections — who is allowed to reach this site?
				//   Advanced    — everything a normal customer never needs.
				'tabs'  => [
					'security'    => [ 'label' => __( 'Protection', 'action-steward' ),  'view' => 'settings',             'feature' => null ],
					'connections' => [ 'label' => __( 'Connections', 'action-steward' ), 'view' => 'settings-connections', 'feature' => null ],
					'advanced'    => [ 'label' => __( 'Advanced', 'action-steward' ),    'view' => 'settings-advanced',    'feature' => null ],
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
		return BuiltinAiSettings::flag( $const, $filter );
	}

	/**
	 * Settings › Connections panes — the three answers to "who may reach this site".
	 *
	 * Lived inside settings-connections.php, where the ⌘K palette could not see it,
	 * so "Access tokens" was a real destination that search could never find. The
	 * view still owns the rendering; this owns the list, exactly as sections() owns
	 * the tab list. Gating (FeatureGate) is applied here so the palette can never
	 * offer a pane this site does not have.
	 *
	 * @return array<string,array{label:string,view:string,feature:?string,keywords:string}>
	 */
	public static function connection_panes(): array {
		$panes = [
			'assistants' => [
				'label'    => __( 'Assistants', 'action-steward' ),
				'view'     => 'ai-integrations',
				'feature'  => null,
				'keywords' => 'assistant ai claude chatgpt cursor codex gemini copilot continue opencode antigravity muse connect client mcp setup',
			],
			'api'        => [
				'label'    => __( 'Your own software', 'action-steward' ),
				'view'     => 'api-integrations',
				'feature'  => null,
				'keywords' => 'api rest developer integration endpoint openapi code',
			],
			'tokens'     => [
				'label'    => __( 'Access tokens', 'action-steward' ),
				'view'     => 'token-capability-manager',
				'feature'  => 'token_capability_manager',
				'keywords' => 'token tokens access key secret revoke expire scope capabilities permission',
			],
		];
		return self::drop_gated( $panes );
	}

	/**
	 * Settings › Advanced panes — everything a normal customer never opens.
	 *
	 * Extracted from settings-advanced.php for the same reason as
	 * connection_panes(): Diagnostics, System and Capabilities are destinations,
	 * and search could not reach any of them. Build/developer gating stays here so
	 * the palette and the sub-nav can never disagree about what exists.
	 *
	 * @return array<string,array{label:string,view:string,feature:?string,keywords:string}>
	 */
	public static function advanced_panes(): array {
		$panes = [
			'ai'           => [
				'label'    => __( 'Built-in AI', 'action-steward' ),
				'view'     => 'settings-ai',
				'feature'  => null,
				'keywords' => 'ai provider anthropic openai api key model seo alt text content generate',
			],
			'diagnostics'  => [
				'label'    => __( 'Diagnostics', 'action-steward' ),
				'view'     => 'settings-diagnostics',
				'feature'  => null,
				'keywords' => 'diagnostics health troubleshoot problem report recommendations patches status check',
			],
			'system'       => [
				'label'    => __( 'System', 'action-steward' ),
				'view'     => 'operations-center',
				'feature'  => null,
				'keywords' => 'system engine runtime live feed operations activity queue',
			],
			'capabilities' => [
				'label'    => __( 'Capabilities', 'action-steward' ),
				'view'     => 'operations-explorer',
				'feature'  => 'operations_explorer',
				'keywords' => 'capabilities capability operations permissions allowed map what can it do',
			],
		];

		// Dev-only proposal surface: build-flagged, off on a stock install.
		if ( self::proposals_ui_enabled() ) {
			$panes['drafts'] = [
				'label'    => __( 'Drafts (Dev)', 'action-steward' ),
				'view'     => 'proposals',
				'feature'  => null,
				'keywords' => 'drafts proposals pending suggestions',
			];
		}

		// File browsing and database search/replace stay fully functional over
		// REST/MCP; these screens appear only when developer tools are switched on.
		if ( DeveloperTools::enabled() ) {
			$panes['files'] = [
				'label'    => __( 'File access', 'action-steward' ),
				'view'     => 'file-access',
				'feature'  => null,
				'keywords' => 'files file access browse read theme plugin code',
			];
			$panes['tools'] = [
				'label'    => __( 'Search & replace', 'action-steward' ),
				'view'     => 'tools-search-replace',
				'feature'  => null,
				'keywords' => 'search replace database find text bulk',
			];
		}

		return self::drop_gated( $panes );
	}

	/**
	 * Drop any entry whose FeatureGate is closed (the licensing seam; ungated
	 * today). Shared by both pane lists so the rule is written once.
	 *
	 * @param  array<string,array{feature:?string}> $entries
	 * @return array<string,array>
	 */
	private static function drop_gated( array $entries ): array {
		foreach ( $entries as $key => $entry ) {
			if ( null !== ( $entry['feature'] ?? null ) && ! FeatureGate::allows( $entry['feature'] ) ) {
				unset( $entries[ $key ] );
			}
		}
		return $entries;
	}

	/**
	 * The navigation map for client consumption (the ⌘K palette).
	 *
	 * A FLAT list of real destinations, not a section/tab tree. The tree shape was
	 * the bug: the palette rendered a section AND its only tab as two rows that
	 * went to the same screen ("Approvals" and "Approvals › Approvals"), while the
	 * screens a customer actually searches for — Access tokens, Diagnostics,
	 * Capabilities, Assistants — were sub-panes the map never described at all, so
	 * searching for any of them returned nothing.
	 *
	 * Each destination carries `keywords`: the words a customer types for a screen
	 * whose label is something else. "Undo" is how people ask for Changes;
	 * "Security" is how they ask for Protection; "History" is the word the product
	 * deliberately stopped using but customers did not. Keywords are matched but
	 * never displayed, so the list stays readable.
	 *
	 * URLs are unique by construction (one row per destination), which is what
	 * makes duplicate destinations impossible rather than merely unlikely.
	 *
	 * `keywords` is everything that should reach this destination, including the
	 * words its parent section contributes. `aliases` is the subset that is about
	 * THIS screen and nothing else.
	 *
	 * The distinction matters to WordPress's own command palette, which scores
	 * fuzzily: the longer and more varied a searchable string, the more likely some
	 * unrelated query's letters appear across it in order. Feeding it the inherited
	 * words was enough to make "protection" rank Changes above Protection — Changes
	 * does not contain the word at all, but "Action Steward: Changes … rollback
	 * revert restore …" contains its letters in sequence. The plugin's own palette
	 * matches contiguously and is unaffected, so it keeps using `keywords`; see
	 * CommandPaletteIntegration for the other consumer.
	 *
	 * @return array<int,array{label:string,hint:string,url:string,keywords:string,aliases:string}>
	 */
	public static function nav_map(): array {
		$out = [];

		$add = static function ( string $label, string $hint, string $url, string $keywords, string $aliases = '' ) use ( &$out ): void {
			$out[] = [
				'label'    => $label,
				'hint'     => $hint,
				'url'      => $url,
				'keywords' => $keywords,
				// A top-level section owns all of its words; only the panes inherit.
				'aliases'  => '' !== $aliases ? $aliases : $keywords,
			];
		};

		$sections = self::sections();

		// Section-level keywords, keyed by slug. A section whose visible name is
		// not the word customers reach for needs the other words too.
		$section_keywords = [
			self::HOME_SLUG     => 'home dashboard start setup overview get started connect first',
			self::ACTIVITY_SLUG => 'approvals approve review pending waiting requests decide queue permission',
			self::HISTORY_SLUG  => 'changes history undo rollback revert restore activity audit log what changed',
			self::SETTINGS_SLUG => 'settings options configure preferences',
		];

		foreach ( $sections as $slug => $section ) {
			$keywords = $section_keywords[ $slug ] ?? '';

			// Home and any single-tab section ARE one destination. Emitting the
			// section and its lone tab is what produced the duplicate rows.
			if ( self::HOME_SLUG === $slug || count( $section['tabs'] ) <= 1 ) {
				$add(
					$section['label'],
					__( 'Section', 'action-steward' ),
					admin_url( 'admin.php?page=' . $slug ),
					$keywords
				);
				continue;
			}

			// Multi-tab sections list their tabs only — the bare section URL just
			// redisplays the first tab, so it is the same destination again.
			foreach ( $section['tabs'] as $key => $tab ) {
				$tab_url = admin_url( 'admin.php?page=' . $slug . '&wpcc_tab=' . $key );

				// Settings' two hub tabs are containers: their panes are the real
				// destinations, so the hub itself is not listed separately.
				if ( self::SETTINGS_SLUG === $slug && 'connections' === $key ) {
					foreach ( self::connection_panes() as $pane_key => $pane ) {
						$add(
							$section['label'] . ' › ' . $tab['label'] . ' › ' . $pane['label'],
							$tab['label'],
							$tab_url . '&cpane=' . $pane_key,
							$keywords . ' connections ' . $pane['keywords'],
							'connections ' . $pane['keywords']
						);
					}
					continue;
				}
				if ( self::SETTINGS_SLUG === $slug && 'advanced' === $key ) {
					foreach ( self::advanced_panes() as $pane_key => $pane ) {
						$add(
							$section['label'] . ' › ' . $tab['label'] . ' › ' . $pane['label'],
							$tab['label'],
							$tab_url . '&apane=' . $pane_key,
							$keywords . ' advanced ' . $pane['keywords'],
							'advanced ' . $pane['keywords']
						);
					}
					continue;
				}

				$extra = ( self::SETTINGS_SLUG === $slug && 'security' === $key )
					? ' protection security mode safe approval rules strict permission risk'
					: '';

				$add(
					$section['label'] . ' › ' . $tab['label'],
					$section['label'],
					$tab_url,
					$keywords . $extra,
					// A tab's own words, not its section's. Settings › Protection is
					// about protection; "settings options configure preferences" is
					// true of every tab under Settings and identifies none of them.
					'' !== $extra ? trim( $extra ) : $tab['label']
				);
			}
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

		/*
		 * Render the hosted view FIRST, into a buffer, and echo it into the canvas
		 * further down. The output lands in exactly the same place; only the order
		 * of execution changes.
		 *
		 * Why: hosted views process their own form POSTs. The protection chip in the
		 * bar below reads the security mode, but the bar used to render before the
		 * view ran — so saving a new mode showed "Saved. This site is now set to
		 * Strict approval." in the page while the chip above it still said "Standard
		 * protection". The chip was always exactly one save behind, on the one screen
		 * where being wrong about the security posture matters most.
		 *
		 * Buffering means any state a view changes is already committed when the
		 * chrome reads it, so the shell can never contradict the page inside it.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection; the hosted view does its own nonce check.
		$requested   = isset( $_GET['wpcc_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpcc_tab'] ) ) : '';
		$active      = isset( $tabs[ $requested ] ) ? $requested : ( empty( $tabs ) ? '' : array_key_first( $tabs ) );
		$canvas_html = '';
		if ( empty( $tabs ) ) {
			ob_start();
			// Every tab gated off (licensing seam): instruct, never show a blank page.
			$this->render_empty_section( $section['label'] );
			$canvas_html = (string) ob_get_clean();
		} else {
			ob_start();
			$this->require_view( $tabs[ $active ]['view'] );
			$canvas_html = (string) ob_get_clean();
		}

		// Read AFTER the view has run, so the chip reflects this request's save.
		$mode  = SecurityModeManager::current();
		$label = SecurityModeManager::label();

		/*
		 * Before an assistant has ever connected, the density toggle and the ⌘K
		 * palette are controls for a product the customer has not started using:
		 * there is no detail to expand and nowhere earned to jump to. Showing them
		 * during onboarding is the interface talking about itself. They appear the
		 * moment they can do something.
		 */
		$has_started = ConnectionStatus::ever_connected();
		?>
		<div class="wrap wpcc-app" data-wpcc-mode="builder" data-wpcc-density="comfortable">
			<?php
			/*
			 * Notice boundary — ABOVE the application, not inside it.
			 *
			 * WordPress relocates every `admin_notices` output with:
			 *   if ( ! $headerEnd.length ) { $headerEnd = $( '.wrap h1, .wrap h2' ).first(); }
			 *   $( 'div.updated, div.error, div.notice' )…insertAfter( $headerEnd );
			 * (wp-admin/js/common.js). With no marker, "the first h1 in .wrap" is our
			 * brand heading, so a third-party banner lands inside the product header.
			 *
			 * Placing the marker FIRST puts notices above the whole application. This
			 * matters more than it sounds: a tab bar and the panel it controls are one
			 * control, and the earlier placement (after the tabs) inserted ~230px of
			 * unrelated content between them, breaking proximity — the tabs visually
			 * belonged to someone else's upsell rather than to their own content.
			 * Above the shell, notices read as the platform speaking, the product keeps
			 * one uninterrupted canvas, and header → tabs → content never separates.
			 *
			 * `.wp-header-end` is core's own supported opt-out for exactly this (core
			 * uses it on the list-table screens). Nothing is hidden or suppressed:
			 * core and every other plugin render normally, just in one predictable band.
			 */
			?>
			<div class="wpcc-shell__notices" role="region" aria-label="<?php esc_attr_e( 'WordPress notices', 'action-steward' ); ?>">
				<hr class="wp-header-end" />
			</div>

			<div class="wpcc-shell__bar">
				<?php
				// Identity block: brand → section → one-line purpose. The purpose line
				// used to float between the tab bar and the page content as a loose
				// paragraph, which read as a stray caption and broke the vertical
				// rhythm. As a subtitle it becomes real typographic hierarchy. Home
				// omits it: that page opens with its own promise line, and saying the
				// same thing twice on the first screen is worse than saying it once.
				?>
				<div class="wpcc-shell__identity">
					<h1 class="wpcc-shell__brand">
						<?php
						/*
						 * The mark is the real artwork, not a typographic stand-in. This
						 * was a Unicode trigram glyph (&#9783;) inside a filled blue
						 * square, which the identity standard rules out twice over: the
						 * mark may never be reconstructed from font glyphs, and it may
						 * never sit inside another badge or container. The 26 px box is
						 * kept exactly, because .wpcc-shell__desc aligns itself under the
						 * heading text with a hard 34px offset (26 + the 8px flex gap).
						 *
						 * It carries a real accessible name rather than being decorative: the
						 * heading beside it names the area, so the mark is now the only thing
						 * in the header that says which product this is.
						 *
						 * ── LIGHT-SURFACE ARTWORK, UNCONDITIONALLY. Do not use Brand::picture() here.
						 *
						 * This mark sits on the shell header card, which is white on the WordPress
						 * admin's #f0f0f1 content background. That is true in EVERY core admin
						 * colour scheme — those schemes restyle the sidebar and accents, never the
						 * content area — so this surface is permanently light.
						 *
						 * Brand::picture() switches variants on `(prefers-color-scheme: dark)`,
						 * which reports the viewer's OPERATING SYSTEM / browser theme. It says
						 * nothing about the surface the artwork is painted on. Using it here meant
						 * that any admin whose OS was in dark mode was served the dark-surface
						 * mark — near-white #F8FAFC — onto this light card: a contrast ratio of
						 * 1.09:1, i.e. invisible. Reported from Firefox in dark mode while Chrome
						 * in light mode looked perfect, which is exactly why it reads as a caching
						 * problem and is not one.
						 *
						 * picture() and the *-dark assets remain correct and available — for a
						 * surface that is genuinely dark, like the admin sidebar. This one is not.
						 */
						printf(
							'<img src="%1$s" alt="%2$s" class="wpcc-shell__brand-mark" width="26" height="26" decoding="async" />',
							esc_url( Brand::mark() ),
							esc_attr__( 'Action Steward', 'action-steward' )
						);
						?>
						<?php
						/*
						 * NAMING HIERARCHY — each surface names one thing, once.
						 *
						 *   admin menu       -> "Action Steward"  (which product, globally)
						 *   shell header     -> the current area     (where you are, now)
						 *   first-run lockup -> "Action Steward"  (the introduction)
						 *
						 * This heading used to read "Command Center / Approvals": a third spelling
						 * of the product name, repeated on every screen at 18px, with the one word
						 * that actually changes between screens set small and grey beside it. That
						 * inverts the hierarchy — the constant shouted, the variable whispered —
						 * while the sidebar had already said which product this is. The area name
						 * is the heading now; the mark beside it keeps the brand present without
						 * spelling it a third way.
						 */
						echo esc_html( $section['label'] );
						?>
					</h1>
					<?php
					/*
					 * Home states its promise in the setup hero at full size. Repeating
					 * it in the subtitle immediately above meant a first-time customer
					 * read the same sentence twice before reaching the first step. The
					 * subtitle returns once setup is done and the hero is gone.
					 */
					$show_desc = ! empty( $section['desc'] ) && ( ! $is_home || $has_started );
					?>
					<?php if ( $show_desc ) : ?>
						<p class="wpcc-shell__desc"><?php echo esc_html( $section['desc'] ); ?></p>
					<?php endif; ?>
				</div>
				<div class="wpcc-shell__tools">
					<span class="wpcc-shell__posture" data-mode="<?php echo esc_attr( $mode ); ?>" title="<?php esc_attr_e( 'Current security mode', 'action-steward' ); ?>">
						<?php echo esc_html( $label ); ?>
					</span>
					<?php
					// Density disclosure. The stored values stay builder/engineer (the CSS
					// and the persisted preference key depend on them) — only the words
					// change: "Builder / Engineer" asked the customer to pick a job title
					// to decide how much detail they wanted.
					?>
					<?php if ( $has_started && ! empty( $section['detail'] ) ) : ?>
						<div class="wpcc-shell__modes" role="group" aria-label="<?php esc_attr_e( 'Level of detail', 'action-steward' ); ?>">
							<button type="button" class="wpcc-shell__mode" data-mode="builder" aria-pressed="true"><?php esc_html_e( 'Simple', 'action-steward' ); ?></button>
							<button type="button" class="wpcc-shell__mode" data-mode="engineer" aria-pressed="false"><?php esc_html_e( 'Detailed', 'action-steward' ); ?></button>
						</div>
					<?php endif; ?>
					<?php
					// Search is not a disclosure control: it reaches every screen from
					// every screen, so it stays wherever the customer is.
					?>
					<?php if ( $has_started ) : ?>
						<button type="button" class="wpcc-shell__cmdk" aria-haspopup="dialog">
							<?php esc_html_e( 'Search', 'action-steward' ); ?> <kbd>&#8984;K</kbd>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php
			// A tab bar with one tab is chrome pretending to be navigation: it offers
			// no choice, costs a row of vertical space, and makes the section look
			// unfinished. History has exactly one view, so it simply renders it.
			?>
			<?php if ( ! $is_home && count( $tabs ) > 1 ) : ?>
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

			<div class="wpcc-shell__canvas">
				<?php
				// Already rendered into $canvas_html at the top of this method, before
				// the chrome above read any state. Escaping is the hosted view's own
				// responsibility, exactly as it was when it echoed directly here.
				echo $canvas_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered view markup.
				?>
			</div>
		</div>
		<?php
	}

	/** A graceful, instructive empty state when a whole section is gated off. */
	private function render_empty_section( string $label ): void {
		?>
		<div class="wpcc-cds-empty" role="status">
			<p><strong><?php echo esc_html( sprintf( /* translators: %s: section name */ __( '%s is not available in this edition.', 'action-steward' ), $label ) ); ?></strong></p>
			<p class="description"><?php esc_html_e( 'This area is gated by your current plan. Everything else in Action Steward stays available.', 'action-steward' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::HOME_SLUG ) ); ?>"><?php esc_html_e( 'Back to Home', 'action-steward' ); ?></a></p>
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
