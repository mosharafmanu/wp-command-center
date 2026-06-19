<?php
/**
 * Admin-only REST endpoints for the approval workflow.
 *
 * These routes use WP cookie authentication (X-WP-Nonce header) and are
 * intended exclusively for the WP Admin approval UI. API token requests
 * cannot reach these endpoints because they do not set a WP session cookie,
 * so current_user_can() returns false for guest/token-only callers.
 *
 * Namespace: wp-command-center/v1/admin/...
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\OperationManager;
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\SecurityModeManager;
use WPCommandCenter\Operations\DestructiveGuard;
use WPCommandCenter\Operations\ChangeHistoryRuntimeManager;
use WPCommandCenter\Operations\OperationExecutor;
use WPCommandCenter\PatchSystem\PatchManager;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Proposals\ProposalStore;
use WPCommandCenter\Proposals\ProposalApplyService;
use WPCommandCenter\Proposals\ProposalSync;
use WPCommandCenter\AltText\AltTextScanQuery;
use WPCommandCenter\AltText\AltTextGenerator;
use WPCommandCenter\Seo\SeoAuditQuery;
use WPCommandCenter\Seo\SeoMetaGenerator;

defined( 'ABSPATH' ) || exit;

final class AdminRestApi {

	private const NS = 'wp-command-center/v1';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NS, '/admin/approvals', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_pending' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( self::NS, '/admin/approvals/count', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'count_pending' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		foreach ( [ 'approve', 'reject' ] as $action ) {
			register_rest_route( self::NS, '/admin/approvals/(?P<id>[a-f0-9-]{36})/' . $action, [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) use ( $action ) {
					return $this->handle_action( $r, $action );
				},
				'permission_callback' => [ $this, 'check_permission' ],
			] );
		}

		// STEP 106.1 — Approval Center read surface (cookie + nonce only). All
		// READABLE, presentation-layer aggregation over the existing approval
		// tables (ApprovalAdminQuery). No write/approval routes are added here —
		// approve/reject stay on the existing routes above; queue retry lands in
		// STEP 106.3. Literal segments are registered before the bare /{id}
		// detail route so they match first.
		register_rest_route( self::NS, '/admin/approvals/history', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'approvals_history' ],
			'permission_callback' => [ $this, 'check_approval_permission' ],
		] );

		register_rest_route( self::NS, '/admin/approvals/summary', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'approvals_summary' ],
			'permission_callback' => [ $this, 'check_approval_permission' ],
		] );

		register_rest_route( self::NS, '/admin/approvals/queue', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'approvals_queue' ],
			'permission_callback' => [ $this, 'check_approval_permission' ],
		] );

		register_rest_route( self::NS, '/admin/approvals/results/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'approval_result' ],
			'permission_callback' => [ $this, 'check_approval_permission' ],
		] );

		// STEP 106.3 — re-queue a FAILED queue item. The only new write route in
		// the Approval Center. It does NOT retry inline: it routes queue_retry
		// through ApprovalRuntimeManager, inheriting the STEP 80 human-approver
		// guard + AuditLog — no bypass.
		register_rest_route( self::NS, '/admin/approvals/queue/(?P<id>[a-f0-9-]{36})/retry', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'approval_retry' ],
			'permission_callback' => [ $this, 'check_approval_permission' ],
		] );

		register_rest_route( self::NS, '/admin/approvals/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'approval_detail' ],
			'permission_callback' => [ $this, 'check_approval_permission' ],
		] );

		// STEP 105.1 — Change History admin read surface (cookie + nonce only).
		// All read-only. List/timeline/get delegate to the STEP 104 runtime
		// manager (identical envelope as token REST/MCP); sessions is a thin
		// presentation-layer aggregation. No write/rollback routes here — the
		// Restore action lands in STEP 105.3.
		register_rest_route( self::NS, '/admin/history', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_list' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		register_rest_route( self::NS, '/admin/history/timeline', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_timeline' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		register_rest_route( self::NS, '/admin/history/sessions', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_sessions' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		// STEP 105.2 — server-rendered diff for one change (read-only). Registered
		// before the bare /{change_id} route so the /diff suffix matches first.
		register_rest_route( self::NS, '/admin/history/(?P<change_id>[A-Za-z0-9\-]{1,64})/diff', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_diff' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		// STEP 105.3 — reverse one change from wp-admin. The ONLY write route in
		// the Change History admin surface. It does NOT roll back itself: it
		// routes change_history/rollback_target through OperationExecutor::run,
		// inheriting capability, DestructiveGuard (ROLLBACK_CHANGE handshake),
		// security-mode approval, AuditLog, and ChangeRecorder — no bypass.
		register_rest_route( self::NS, '/admin/history/(?P<change_id>[A-Za-z0-9\-]{1,64})/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'history_rollback' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		register_rest_route( self::NS, '/admin/history/(?P<change_id>[A-Za-z0-9\-]{1,64})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'history_get' ],
			'permission_callback' => [ $this, 'check_history_permission' ],
		] );

		// STEP 107.1 — Token & Capability Manager read surface (cookie + nonce
		// only). All READABLE, presentation-layer aggregation over the existing
		// token manifest + capability assignments (TokenCapabilityAdminQuery). No
		// write routes here — token lifecycle (create/revoke/delete) and capability
		// assign/remove land in STEP 107.3 / 107.4. Literal segments are registered
		// before the bare /tokens/{id} detail route so they match first.
		// STEP 107.4 — /admin/tokens carries the list read AND the create write.
		// Create reuses AuthTokens::create() verbatim (no reimplementation); the
		// route's manage_options + nonce is the gate. The raw token is returned
		// ONCE and the token_hash is never surfaced.
		register_rest_route( self::NS, '/admin/tokens', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'tokens_list' ],
				'permission_callback' => [ $this, 'check_tokens_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'token_create' ],
				'permission_callback' => [ $this, 'check_tokens_permission' ],
			],
		] );

		register_rest_route( self::NS, '/admin/capabilities', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'capabilities_catalogue' ],
			'permission_callback' => [ $this, 'check_tokens_permission' ],
		] );

		register_rest_route( self::NS, '/admin/operations-map', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'operations_map' ],
			'permission_callback' => [ $this, 'check_tokens_permission' ],
		] );

		// STEP 107.3 — capability write actions (assign / remove). These are the
		// ONLY write routes in the Token & Capability Manager. They do NOT mutate
		// capabilities themselves: each routes 'capability_manage' THROUGH
		// OperationExecutor::run, inheriting the capability.admin gate, AuditLog,
		// security-mode approval, and the system.admin refusal guard — no bypass.
		// Registered before the bare /tokens/{id} read route so the more specific
		// /capabilities segments match first.
		register_rest_route( self::NS, '/admin/tokens/(?P<id>[a-f0-9-]{36})/capabilities', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'token_assign_capability' ],
			'permission_callback' => [ $this, 'check_tokens_permission' ],
		] );

		register_rest_route( self::NS, '/admin/tokens/(?P<id>[a-f0-9-]{36})/capabilities/(?P<cap>[a-z0-9_.]{1,64})', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'token_remove_capability' ],
			'permission_callback' => [ $this, 'check_tokens_permission' ],
		] );

		// STEP 107.4 — revoke (reuses AuthTokens::revoke()). Registered before the
		// bare /tokens/{id} route so the /revoke suffix matches first.
		register_rest_route( self::NS, '/admin/tokens/(?P<id>[a-f0-9-]{36})/revoke', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'token_revoke' ],
			'permission_callback' => [ $this, 'check_tokens_permission' ],
		] );

		// STEP 107.4 — /admin/tokens/{id} carries the detail read AND the delete
		// write. Delete reuses AuthTokens::delete() verbatim.
		register_rest_route( self::NS, '/admin/tokens/(?P<id>[a-f0-9-]{36})', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'token_detail' ],
				'permission_callback' => [ $this, 'check_tokens_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'token_delete' ],
				'permission_callback' => [ $this, 'check_tokens_permission' ],
			],
		] );

		// STEP 108.1 — Operations Explorer read surface (cookie + nonce only).
		// All READABLE, presentation-layer aggregation over the operation catalogue
		// + capability map (OperationExplorerAdminQuery). NO write routes — this
		// surface NEVER executes an operation (execution is out of scope by design).
		// The /summary literal segment is registered before the bare /operations/{id}
		// detail route (which arrives in STEP 108.2) so it matches first.
		register_rest_route( self::NS, '/admin/operations', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'operations_list' ],
			'permission_callback' => [ $this, 'check_operations_permission' ],
		] );

		register_rest_route( self::NS, '/admin/operations/summary', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'operations_summary' ],
			'permission_callback' => [ $this, 'check_operations_permission' ],
		] );

		// STEP 108.2 — single-operation detail (read-only). Registered AFTER the
		// /summary literal so that segment matches first; operation ids are
		// [a-z0-9_]. Still READABLE only — the detail surface describes an operation,
		// it never runs it.
		register_rest_route( self::NS, '/admin/operations/(?P<id>[a-z0-9_]{1,64})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'operation_detail' ],
			'permission_callback' => [ $this, 'check_operations_permission' ],
		] );

		// STEP 109.1 — Dashboard Overview: a single READABLE roll-up of the existing
		// admin surfaces (approvals / change history / tokens & capabilities /
		// operations) plus the live security posture and the platform invariants.
		// Read-only — no write/execute route, no engine dispatch. The aggregation is
		// a thin fan-out over the per-surface AdminQuery summaries (DashboardAdminQuery).
		register_rest_route( self::NS, '/admin/dashboard', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'dashboard_overview' ],
			'permission_callback' => [ $this, 'check_dashboard_permission' ],
		] );

		// STEP 110 (Task 5) — Proposal Store admin surface. Reads are read-through
		// (ProposalSync materializes pending_approval rows on GET, since the
		// reconciler cron is not scheduled). Writes route ONLY through
		// ProposalApplyService (apply) and ProposalStore (edit/dismiss) — never a
		// direct table write and never a direct OperationExecutor call here.
		// Literal /apply and /dismiss suffixes are registered before the bare
		// /{id} route so they match first.
		register_rest_route( self::NS, '/admin/proposals', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'proposals_list' ],
				'permission_callback' => [ $this, 'check_proposals_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'proposals_create' ],
				'permission_callback' => [ $this, 'check_proposals_permission' ],
			],
		] );

		register_rest_route( self::NS, '/admin/proposals/(?P<id>[a-f0-9-]{36})/apply', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'proposals_apply' ],
			'permission_callback' => [ $this, 'check_proposals_permission' ],
		] );

		register_rest_route( self::NS, '/admin/proposals/(?P<id>[a-f0-9-]{36})/dismiss', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'proposals_dismiss' ],
			'permission_callback' => [ $this, 'check_proposals_permission' ],
		] );

		register_rest_route( self::NS, '/admin/proposals/(?P<id>[a-f0-9-]{36})', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'proposals_get' ],
				'permission_callback' => [ $this, 'check_proposals_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'proposals_update' ],
				'permission_callback' => [ $this, 'check_proposals_permission' ],
			],
		] );

		// STEP 110 (Task 7A) — AI Alt Text read-only scan. READABLE only: audits the
		// Media Library for missing/weak/ok alt text. No writes, no outbound HTTP, no
		// proposal creation, no engine interaction. Delegates to AltTextScanQuery.
		register_rest_route( self::NS, '/admin/alt-text/scan', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'alt_text_scan' ],
			'permission_callback' => [ $this, 'check_alt_text_permission' ],
		] );

		// STEP 110 (Task 7C) — AI Alt Text generation. CREATABLE: produces governed
		// DRAFTS only (provider suggestion → ProposalStore::create). It never applies
		// and never mutates the site. Delegates to AltTextGenerator.
		register_rest_route( self::NS, '/admin/alt-text/generate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'alt_text_generate' ],
			'permission_callback' => [ $this, 'check_alt_text_permission' ],
		] );

		// STEP 111 (S2.2.1) — selection resolve. READABLE only: resolves a stateless
		// "select all matching" criteria into a BOUNDED, capability-scoped id set via
		// the read-only SelectionResolver. It NEVER writes, never applies, never
		// persists a selection — the ids it returns are fed to the EXISTING per-item
		// apply/dismiss routes by the client. No new operation/capability/MCP tool.
		register_rest_route( self::NS, '/admin/alt-text/selection', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'alt_text_selection' ],
			'permission_callback' => [ $this, 'check_alt_text_permission' ],
		] );

		// STEP 111 — Governed Action #2 (SEO Meta Generator) Slice 1. READABLE only:
		// a read-only SEO meta audit (missing/weak/ok) over public content via the
		// existing SeoProvider. No writes, no provider/model call, no proposal, no
		// seo_update. Delegates to SeoAuditQuery.
		register_rest_route( self::NS, '/admin/seo/audit', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'seo_audit' ],
			'permission_callback' => [ $this, 'check_seo_permission' ],
		] );

		// STEP 111 — GA#2 Slice 2b: SEO meta generation. CREATABLE: produces governed
		// DRAFTS only (AI suggestion → ProposalStore::create with a seo_manage/seo_update
		// payload). It never applies and never mutates the site. Delegates to SeoMetaGenerator.
		register_rest_route( self::NS, '/admin/seo/generate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'seo_generate' ],
			'permission_callback' => [ $this, 'check_seo_permission' ],
		] );
	}

	/**
	 * C1 (Phase B) — the FeatureGate key each admin surface's REST routes gate on,
	 * keyed by a short surface handle. Local to AdminRestApi by design: the Dashboard
	 * Overview aggregator (DashboardAdminQuery) keeps its OWN independent map, and a
	 * shared cross-file gate map is a deliberate future concern, out of C1 scope. A
	 * surface that is absent here — or the null the legacy gate passes — means
	 * capability-only (no feature seam), which preserves the legacy /admin/approvals*
	 * routes exactly.
	 */
	private const FEATURE_KEYS = [
		'approvals'      => 'approval_center',
		'operations'     => 'operations_explorer',
		'tokens'         => 'token_capability_manager',
		'change_history' => 'change_history',
		'dashboard'      => 'dashboard_overview',
		'proposals'      => 'proposal_store',
		'alt_text'       => 'ai_alt_text',
		'seo'            => 'seo_meta_generator',
	];

	/**
	 * C1 (Phase B) — the single admin permission resolver. Consolidates six
	 * near-identical callbacks into one rule: every admin route requires
	 * `manage_options` AND, when its surface has a FeatureGate key, that key (the
	 * Free/Pro switch point a future edition flips via FeatureGate). $surface === null
	 * (or an unmapped surface) is capability-only — no feature seam. The per-surface
	 * methods below are thin, named bindings the route registrations point at; the
	 * gating logic lives here only.
	 */
	private function gate( ?string $surface ): bool {
		$feature = ( null !== $surface ) ? ( self::FEATURE_KEYS[ $surface ] ?? null ) : null;
		return current_user_can( 'manage_options' )
			&& ( null === $feature || FeatureGate::allows( $feature ) );
	}

	/** Legacy approval routes (/admin/approvals*): capability-only, no feature seam. */
	public function check_permission(): bool {
		return $this->gate( null );
	}

	/** Change History surface gate (manage_options + FeatureGate 'change_history'). */
	public function check_history_permission(): bool {
		return $this->gate( 'change_history' );
	}

	/** Approval Center surface gate (manage_options + FeatureGate 'approval_center'). */
	public function check_approval_permission(): bool {
		return $this->gate( 'approvals' );
	}

	/** Token & Capability Manager surface gate (manage_options + FeatureGate 'token_capability_manager'). */
	public function check_tokens_permission(): bool {
		return $this->gate( 'tokens' );
	}

	/** Operations Explorer surface gate (manage_options + FeatureGate 'operations_explorer'). */
	public function check_operations_permission(): bool {
		return $this->gate( 'operations' );
	}

	/** Dashboard Overview surface gate (manage_options + FeatureGate 'dashboard_overview'). */
	public function check_dashboard_permission(): bool {
		return $this->gate( 'dashboard' );
	}

	/** Proposal Store surface gate (manage_options + FeatureGate 'proposal_store'). */
	public function check_proposals_permission(): bool {
		return $this->gate( 'proposals' );
	}

	/** AI Alt Text surface gate (manage_options + FeatureGate 'ai_alt_text'). */
	public function check_alt_text_permission(): bool {
		return $this->gate( 'alt_text' );
	}

	/** SEO Meta Generator surface gate (manage_options + FeatureGate 'seo_meta_generator'). */
	public function check_seo_permission(): bool {
		return $this->gate( 'seo' );
	}

	/**
	 * STEP 109.1 — Dashboard Overview read handler. Delegates to the read-only
	 * DashboardAdminQuery aggregator (a thin fan-out over the existing per-surface
	 * summaries). It never executes an operation and never writes.
	 */
	public function dashboard_overview(): \WP_REST_Response {
		return new \WP_REST_Response( ( new DashboardAdminQuery() )->overview(), 200 );
	}

	// ── STEP 110 (Task 5) — Proposal Store handlers (thin controllers) ─────────
	// Reads delegate shaping to ProposalAdminQuery (read-only); writes delegate to
	// ProposalApplyService (apply) and ProposalStore (create/edit/dismiss). No
	// query logic, no table writes, and no OperationExecutor call live here.

	/** GET /admin/proposals — paginated list; read-through pending rows on this page. */
	public function proposals_list( \WP_REST_Request $request ): \WP_REST_Response {
		$limit  = (int) ( $request->get_param( 'limit' ) ?: 20 );
		$offset = (int) ( $request->get_param( 'offset' ) ?: 0 );
		$filters = [];
		foreach ( [ 'status', 'operation_id', 'target_type', 'batch_id' ] as $k ) {
			$v = (string) $request->get_param( $k );
			if ( '' !== $v ) {
				$filters[ $k ] = sanitize_text_field( $v );
			}
		}

		$query = new ProposalAdminQuery();
		$env   = $query->list( $filters, $limit, $offset );

		// Read-through (Readiness C1): materialize pending_approval rows on this
		// page via ProposalSync; re-shape once if any advanced to a terminal state.
		$sync    = new ProposalSync();
		$changed = false;
		foreach ( $env['proposals'] as $row ) {
			if ( ProposalStore::STATUS_PENDING_APPROVAL === ( $row['status'] ?? '' ) ) {
				$res = $sync->sync( (string) $row['proposal_id'] );
				if ( ! is_wp_error( $res ) && ProposalStore::STATUS_PENDING_APPROVAL !== ( $res['status'] ?? '' ) ) {
					$changed = true;
				}
			}
		}
		if ( $changed ) {
			$env = $query->list( $filters, $limit, $offset );
		}

		return new \WP_REST_Response( $env, 200 );
	}

	/** GET /admin/proposals/{id} — read-through then shaped detail. */
	public function proposals_get( \WP_REST_Request $request ): \WP_REST_Response {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		// Read-through (Readiness C1): idempotent; no-op unless pending_approval.
		( new ProposalSync() )->sync( $id );

		$proposal = ( new ProposalAdminQuery() )->get( $id );
		if ( null === $proposal ) {
			return new \WP_REST_Response( [ 'error' => true, 'code' => 'wpcc_proposal_not_found', 'message' => __( 'Proposal not found.', 'wp-command-center' ) ], 404 );
		}
		return new \WP_REST_Response( $proposal, 200 );
	}

	/** POST /admin/proposals — create a draft (manual seed). */
	public function proposals_create( \WP_REST_Request $request ): \WP_REST_Response {
		$args = [
			'operation_id' => sanitize_text_field( (string) $request->get_param( 'operation_id' ) ),
			'action'       => sanitize_text_field( (string) $request->get_param( 'action' ) ),
			'target_type'  => sanitize_text_field( (string) $request->get_param( 'target_type' ) ),
			'target_id'    => sanitize_text_field( (string) $request->get_param( 'target_id' ) ),
			'payload'      => (array) $request->get_param( 'payload' ),
			'proposed_by'  => $this->admin_actor(),
		];
		foreach ( [ 'provider', 'model', 'session_id', 'batch_id' ] as $k ) {
			$v = (string) $request->get_param( $k );
			if ( '' !== $v ) {
				$args[ $k ] = sanitize_text_field( $v );
			}
		}

		$created = ( new ProposalStore() )->create( $args );
		return $this->proposal_write_response( $created, 201 );
	}

	/** PATCH /admin/proposals/{id} — edit final_payload (draft only). */
	public function proposals_update( \WP_REST_Request $request ): \WP_REST_Response {
		$id            = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$final_payload = (array) $request->get_param( 'final_payload' );
		$updated       = ( new ProposalStore() )->update_final_payload( $id, $final_payload );
		return $this->proposal_write_response( $updated, 200 );
	}

	/** POST /admin/proposals/{id}/apply — route through ProposalApplyService only. */
	public function proposals_apply( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$result  = ( new ProposalApplyService() )->apply( $id, [ 'actor' => $this->admin_actor() ] );
		return $this->proposal_write_response( $result, 200 );
	}

	/** POST /admin/proposals/{id}/dismiss — route through ProposalStore only. */
	public function proposals_dismiss( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$result = ( new ProposalStore() )->dismiss( $id );
		return $this->proposal_write_response( $result, 200 );
	}

	/** GET /admin/alt-text/scan — read-only Media Library alt-text audit (Task 7A). */
	public function alt_text_scan( \WP_REST_Request $request ): \WP_REST_Response {
		$limit   = (int) ( $request->get_param( 'limit' ) ?: 20 );
		$offset  = (int) ( $request->get_param( 'offset' ) ?: 0 );
		$filters = [
			'state'      => sanitize_key( (string) $request->get_param( 'state' ) ?: 'all' ),
			'with_usage' => rest_sanitize_boolean( $request->get_param( 'with_usage' ) ),
		];
		return new \WP_REST_Response( ( new AltTextScanQuery() )->audit( $filters, $limit, $offset ), 200 );
	}

	/** GET /admin/seo/audit — read-only SEO meta audit (GA#2 Slice 1). */
	public function seo_audit( \WP_REST_Request $request ): \WP_REST_Response {
		[ $limit, $offset ] = $this->list_paging( $request );
		$filters = [ 'state' => sanitize_key( (string) $request->get_param( 'state' ) ?: 'all' ) ];
		return new \WP_REST_Response( ( new SeoAuditQuery() )->audit( $filters, $limit, $offset ), 200 );
	}

	/** POST /admin/seo/generate — AI suggestion → governed SEO drafts (GA#2 Slice 2b). */
	public function seo_generate( \WP_REST_Request $request ): \WP_REST_Response {
		$ids    = array_map( 'intval', (array) $request->get_param( 'post_ids' ) );
		$result = ( new SeoMetaGenerator() )->generate( $ids, [ 'actor' => $this->admin_actor() ] );
		return new \WP_REST_Response( $result, 200 );
	}

	/** POST /admin/alt-text/generate — provider suggestion → governed drafts (Task 7C). */
	public function alt_text_generate( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = array_map( 'intval', (array) $request->get_param( 'attachment_ids' ) );
		$result = ( new AltTextGenerator() )->generate( $ids, [ 'actor' => $this->admin_actor() ] );
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /admin/alt-text/selection — S2.2.1 read-only "select all matching" resolve.
	 *
	 * Resolves the alt-text draft selection (criteria: media_manage drafts, or an
	 * explicit id set) into a BOUNDED, capability-scoped id list. Read-only: the
	 * client feeds the returned ids to the EXISTING per-item apply/dismiss routes.
	 * The criteria filter is fixed server-side to this surface's own scope; the
	 * caller cannot widen it to other operations.
	 */
	public function alt_text_selection( \WP_REST_Request $request ): \WP_REST_Response {
		$by = sanitize_key( (string) $request->get_param( 'by' ) ?: SelectionContract::BY_CRITERIA );

		$contract = SelectionContract::from_array( [
			'by'      => $by,
			'ids'     => array_map( 'strval', (array) $request->get_param( 'ids' ) ),
			// Fixed to this surface's governed scope — never caller-supplied.
			'filters' => [ 'operation_id' => 'media_manage', 'status' => ProposalStore::STATUS_DRAFT ],
			'cap'     => SelectionResolver::MAX_SELECTION,
		] );
		if ( is_wp_error( $contract ) ) {
			return new \WP_REST_Response(
				[ 'error' => true, 'code' => $contract->get_error_code(), 'message' => $contract->get_error_message() ],
				400
			);
		}

		$result = ( new SelectionResolver() )->resolve( $contract, [ 'allowed_operations' => [ 'media_manage' ] ] );
		return new \WP_REST_Response( $result, 200 );
	}

	/** Shape a ProposalStore/ApplyService write result (row or WP_Error) into a response. */
	private function proposal_write_response( mixed $result, int $ok_status ): \WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				[ 'error' => true, 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ],
				400
			);
		}
		$proposal_id = is_array( $result ) ? (string) ( $result['proposal_id'] ?? '' ) : '';
		$shaped      = '' !== $proposal_id ? ( new ProposalAdminQuery() )->get( $proposal_id ) : null;
		return new \WP_REST_Response( $shaped ?? $result, $ok_status );
	}

	public function list_pending( \WP_REST_Request $request ): \WP_REST_Response {
		$manager  = new OperationManager();
		$requests = $manager->list_requests( [
			'status' => OperationManager::STATUS_PENDING_REVIEW,
			'limit'  => 100,
		] );

		$registry = new OperationRegistry();
		$data     = array_map( fn( $r ) => $this->format_request( $r, $registry ), $requests );

		return new \WP_REST_Response( [ 'success' => true, 'requests' => $data, 'total' => count( $data ) ], 200 );
	}

	public function count_pending(): \WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			OperationManager::STATUS_PENDING_REVIEW
		) );
		return new \WP_REST_Response( [ 'count' => $count ], 200 );
	}

	public function handle_action( \WP_REST_Request $request, string $action ): \WP_REST_Response {
		$request_id = sanitize_text_field( $request->get_param( 'id' ) );
		$manager    = new OperationManager();
		$actor      = [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];

		if ( 'approve' === $action ) {
			// STEP 106.3 — destructive escalation parity with the 105.3 restore
			// modal. Approving a destructive request requires the admin to type the
			// DestructiveGuard phrase + a reason FIRST; until then we return
			// confirmation_required and DO NOT approve/execute (no state change).
			$row     = $manager->get_request( $request_id );
			$payload = $row ? ( json_decode( (string) ( $row['payload'] ?? '{}' ), true ) ?: [] ) : [];
			$destructive = $row ? DestructiveGuard::classify( (string) $row['operation_id'], $payload ) : null;

			if ( null !== $destructive ) {
				$confirm = rest_sanitize_boolean( $request->get_param( 'confirm' ) );
				$phrase  = (string) $request->get_param( 'confirmation_phrase' );
				$reason  = trim( (string) $request->get_param( 'reason' ) );

				if ( ! $confirm || ! hash_equals( (string) $destructive['phrase'], $phrase ) || '' === $reason ) {
					return new \WP_REST_Response( [
						'success'               => true,
						'status'                => 'confirmation_required',
						'confirmation_required' => true,
						'destructive'           => true,
						'confirmation_phrase'   => $destructive['phrase'],
						'target_parameter'      => $destructive['target_key'],
						'warning'               => $destructive['warning'],
						'message'               => sprintf(
							/* translators: %s: confirmation phrase */
							__( 'This is a destructive approval. Type the phrase "%s" and a reason to confirm.', 'wp-command-center' ),
							$destructive['phrase']
						),
					], 200 );
				}

				// Confirmed — fold the admin confirmation into the stored payload so
				// the engine's DestructiveGuard passes at execution, and carry the
				// reason into the audit context.
				$this->merge_request_payload( $request_id, [
					'confirm'             => true,
					'confirmation_phrase' => (string) $destructive['phrase'],
					'reason'              => $reason,
				] );
				$actor['confirmation_reason'] = $reason;
			}

			$approved = $manager->approve_request( $request_id, [ 'actor' => $actor ] );
			if ( is_wp_error( $approved ) ) {
				return new \WP_REST_Response( [ 'success' => false, 'error' => $approved->get_error_message() ], 400 );
			}

			( new AuditLog() )->record( 'admin.approval.approved', [
				'request_id' => $request_id,
				'destructive' => null !== $destructive,
				'actor'      => AuditLog::resolve_actor( $actor ),
			] );

			// Execute immediately (admin approve = approve + run in one step).
			$exec = $manager->execute_request( $request_id, $actor );
			if ( is_wp_error( $exec ) ) {
				return new \WP_REST_Response( [
					'success'  => true,
					'approved' => true,
					'executed' => false,
					'error'    => $exec->get_error_message(),
				], 200 );
			}

			return new \WP_REST_Response( [
				'success'  => true,
				'approved' => true,
				'executed' => true,
				'result'   => $exec['result'] ?? null,
			], 200 );
		}

		// reject
		$rejected = $manager->reject_request( $request_id, [ 'actor' => $actor ] );
		if ( is_wp_error( $rejected ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => $rejected->get_error_message() ], 400 );
		}

		( new AuditLog() )->record( 'admin.approval.rejected', [
			'request_id' => $request_id,
			'actor'      => AuditLog::resolve_actor( $actor ),
		] );

		return new \WP_REST_Response( [ 'success' => true, 'rejected' => true ], 200 );
	}

	// ── STEP 106.1 — Approval Center read handlers ──────────────────────

	/** GET /admin/approvals/summary — header counts. */
	public function approvals_summary(): \WP_REST_Response {
		return new \WP_REST_Response(
			[ 'success' => true, 'summary' => ( new ApprovalAdminQuery() )->summary() ],
			200
		);
	}

	/** GET /admin/approvals/history — all-status, filtered, paginated requests. */
	public function approvals_history( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [];

		$status = $request->get_param( 'status' );
		if ( is_array( $status ) ) {
			$filters['status'] = array_map( 'sanitize_text_field', $status );
		} elseif ( null !== $status && '' !== (string) $status ) {
			$filters['status'] = sanitize_text_field( (string) $status );
		}

		foreach ( [ 'risk', 'operation_id', 'actor' ] as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value ) {
				$filters[ $key ] = sanitize_text_field( $value );
			}
		}

		foreach ( [ 'from', 'to' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== (string) $value ) {
				$filters[ $key ] = (int) $value;
			}
		}

		[ $limit, $offset ] = $this->history_paging( $request );

		return new \WP_REST_Response(
			( new ApprovalAdminQuery() )->history( $filters, $limit, $offset ),
			200
		);
	}

	/** GET /admin/approvals/queue — queued/running/failed items (read-only). */
	public function approvals_queue( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [];
		foreach ( [ 'status', 'operation_id', 'request_id' ] as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value ) {
				$filters[ $key ] = sanitize_text_field( $value );
			}
		}

		[ $limit, $offset ] = $this->history_paging( $request );

		return new \WP_REST_Response(
			( new ApprovalAdminQuery() )->queue( $filters, $limit, $offset ),
			200
		);
	}

	/** GET /admin/approvals/{id} — full detail for one request. */
	public function approval_detail( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$detail = ( new ApprovalAdminQuery() )->detail( $id );

		if ( null === $detail ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => 'wpcc_request_not_found', 'message' => __( 'Operation request not found.', 'wp-command-center' ) ],
				404
			);
		}

		// STEP 106.2 — when the request is a patch_apply, attach the server-
		// rendered, escaped unified diff via the SHARED DiffRenderer (no fork,
		// same component the Patches + Change History views use). The view injects
		// this HTML only; it never parses diffs client-side.
		$detail['diff'] = $this->approval_diff( $detail );

		return new \WP_REST_Response( array_merge( [ 'success' => true ], $detail ), 200 );
	}

	/**
	 * Build the escaped diff payload for an approval detail. Honest about stored
	 * data: real unified diff when the patch + its file records still exist,
	 * a graceful "unavailable" note when the snapshot was cleaned, or none for
	 * non-patch requests.
	 *
	 * @param array<string,mixed> $detail
	 * @return array<string,mixed> { diff_kind, available, summary, html }
	 */
	private function approval_diff( array $detail ): array {
		$payload = is_array( $detail['payload'] ?? null ) ? $detail['payload'] : [];
		$request = is_array( $detail['request'] ?? null ) ? $detail['request'] : [];

		$is_patch = 'patch_manage' === ( $request['operation_id'] ?? '' ) && 'patch_apply' === ( $payload['action'] ?? '' );
		if ( ! $is_patch ) {
			return [ 'diff_kind' => 'none', 'available' => false, 'summary' => null, 'html' => '' ];
		}

		$patch_id = isset( $payload['patch_id'] ) ? (string) $payload['patch_id'] : '';
		$patch    = '' !== $patch_id ? ( new PatchManager() )->get( $patch_id ) : new \WP_Error( 'wpcc_no_patch', '' );

		if ( is_wp_error( $patch ) || empty( $patch['files'] ) ) {
			return [
				'diff_kind' => 'patch_unavailable',
				'available' => false,
				'summary'   => null,
				'html'      => '<p class="description">' . esc_html__( 'The diff for this patch is no longer available (its snapshot has been cleaned up).', 'wp-command-center' ) . '</p>',
			];
		}

		$files = array_map(
			static fn( $f ): array => [ 'path' => (string) ( $f['path'] ?? '' ), 'diff' => (string) ( $f['diff'] ?? '' ) ],
			$patch['files']
		);

		return [
			'diff_kind' => 'patch',
			'available' => true,
			'summary'   => DiffRenderer::summarize( $files ),
			'html'      => DiffRenderer::render_accordion( $files, false ),
		];
	}

	/**
	 * POST /admin/approvals/queue/{id}/retry — re-queue a FAILED item.
	 *
	 * Pure reuse: routes queue_retry through ApprovalRuntimeManager (the same
	 * control plane the MCP path uses), inheriting the STEP 80 human-approver
	 * guard and AuditLog. The cookie + manage_options route is the gate; the
	 * admin is a WP_User actor, so the human-approver requirement is satisfied.
	 */
	public function approval_retry( \WP_REST_Request $request ): \WP_REST_Response {
		$queue_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$actor    = [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];

		$result = ( new \WPCommandCenter\Operations\ApprovalRuntimeManager() )->run(
			[ 'action' => 'queue_retry', 'queue_id' => $queue_id ],
			[ 'actor' => $actor ]
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => $result->get_error_code(), 'error' => $result->get_error_message() ],
				400
			);
		}

		return new \WP_REST_Response( [ 'success' => true, 'item' => $result['item'] ?? null ], 200 );
	}

	/**
	 * Merge fields into a request's stored payload (used to fold an admin
	 * destructive confirmation into the request before execution).
	 *
	 * @param array<string,mixed> $add
	 */
	private function merge_request_payload( string $request_id, array $add ): void {
		global $wpdb;
		$row = ( new OperationManager() )->get_request( $request_id );
		if ( ! $row ) {
			return;
		}
		$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true ) ?: [];
		$payload = array_merge( $payload, $add );
		$wpdb->update(
			$wpdb->prefix . 'wpcc_operation_requests',
			[ 'payload' => wp_json_encode( $payload ) ],
			[ 'request_id' => $request_id ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	/** GET /admin/approvals/results/{id} — one execution result. */
	public function approval_result( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$row = ( new \WPCommandCenter\Operations\OperationResults() )->get_result( $id );

		if ( ! $row ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => 'wpcc_result_not_found', 'message' => __( 'Operation result not found.', 'wp-command-center' ) ],
				404
			);
		}

		return new \WP_REST_Response( [ 'success' => true, 'result' => $row ], 200 );
	}

	// ── STEP 105.1 — Change History read handlers ───────────────────────

	/** GET /admin/history — flat, filtered, cursor-paginated change list. */
	public function history_list( \WP_REST_Request $request ): \WP_REST_Response {
		$params           = $this->history_filters( $request );
		$params['action'] = 'history_list';
		return $this->history_response( $params );
	}

	/** GET /admin/history/timeline — chronological, time-windowed. */
	public function history_timeline( \WP_REST_Request $request ): \WP_REST_Response {
		$params           = $this->history_filters( $request );
		$params['action'] = 'history_timeline';
		return $this->history_response( $params );
	}

	/** GET /admin/history/{change_id} — single change (metadata; diff arrives in 105.2). */
	public function history_get( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->history_response( [
			'action'    => 'history_get',
			'change_id' => sanitize_text_field( (string) $request->get_param( 'change_id' ) ),
		] );
	}

	/**
	 * GET /admin/history/{change_id}/diff — server-rendered, escaped diff/what-
	 * changed HTML for one change. Read-only. Strategy is honest about the data
	 * we actually store:
	 *   - patch            → real unified diff via the shared DiffRenderer;
	 *   - patch_unavailable → snapshot rotated/missing → metadata note (degrade);
	 *   - metadata          → runtime/option change → "what changed" summary
	 *                          (no synthesized before/after diff);
	 *   - none              → not reversible / no diff.
	 */
	public function history_diff( \WP_REST_Request $request ): \WP_REST_Response {
		$change_id = sanitize_text_field( (string) $request->get_param( 'change_id' ) );

		$got = ( new ChangeHistoryRuntimeManager() )->run( [ 'action' => 'history_get', 'change_id' => $change_id ] );
		if ( is_array( $got ) && ! empty( $got['error'] ) ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => (string) ( $got['code'] ?? 'wpcc_change_not_found' ), 'message' => (string) ( $got['message'] ?? '' ) ],
				'wpcc_change_not_found' === ( $got['code'] ?? '' ) ? 404 : 400
			);
		}

		$change   = is_array( $got['change'] ?? null ) ? $got['change'] : [];
		$rollback = is_array( $change['rollback'] ?? null ) ? $change['rollback'] : [];
		$kind     = (string) ( $rollback['kind'] ?? 'none' );

		if ( 'patch' === $kind ) {
			$patch_id = (string) ( $rollback['rollback_id'] ?? '' );
			if ( '' === $patch_id ) {
				$patch_id = (string) ( $rollback['change_set_id'] ?? '' );
			}

			$patch = '' !== $patch_id ? ( new PatchManager() )->get( $patch_id ) : new \WP_Error( 'wpcc_no_patch', '' );
			if ( is_wp_error( $patch ) || empty( $patch['files'] ) ) {
				// Snapshot rotated/cleaned or no file records — degrade, never error.
				return $this->diff_payload( $change_id, 'patch_unavailable', false, null,
					'<p class="description">' . esc_html__( 'The diff for this change is no longer available (its snapshot has been cleaned up). The change metadata is shown above.', 'wp-command-center' ) . '</p>',
					__( 'Diff snapshot unavailable.', 'wp-command-center' )
				);
			}

			$files   = array_map(
				static fn( $f ): array => [ 'path' => (string) ( $f['path'] ?? '' ), 'diff' => (string) ( $f['diff'] ?? '' ) ],
				$patch['files']
			);
			$summary = DiffRenderer::summarize( $files );
			$html    = DiffRenderer::render_accordion( $files, false );

			return $this->diff_payload( $change_id, 'patch', true, $summary, $html, '' );
		}

		if ( 'none' === $kind ) {
			return $this->diff_payload( $change_id, 'none', false, null,
				'<p class="description">' . esc_html__( 'This change is not reversible and has no recorded diff.', 'wp-command-center' ) . '</p>',
				__( 'No diff for this change.', 'wp-command-center' )
			);
		}

		// runtime_option (and any other reversible non-patch kind): we do not store
		// before/after content, so present a structured "what changed" summary
		// rather than a synthesized diff.
		return $this->diff_payload( $change_id, 'metadata', false, null, $this->render_change_metadata( $change ),
			__( 'Field-level change — previous value is restorable, but no textual diff is stored.', 'wp-command-center' )
		);
	}

	/**
	 * Build the "what changed" HTML for a non-patch change from its stored
	 * target summary + counts. All values are escaped.
	 *
	 * @param array<string,mixed> $change
	 */
	private function render_change_metadata( array $change ): string {
		$html   = '<div class="wpcc-change-meta-summary">';
		$counts = is_array( $change['counts'] ?? null ) ? $change['counts'] : [];
		$html  .= '<p>' . esc_html( sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: errors */
			__( 'Created %1$d · Updated %2$d · Skipped %3$d · Errors %4$d', 'wp-command-center' ),
			(int) ( $counts['created'] ?? 0 ),
			(int) ( $counts['updated'] ?? 0 ),
			(int) ( $counts['skipped'] ?? 0 ),
			(int) ( $counts['error'] ?? 0 )
		) ) . '</p>';

		$summary = $change['target_summary'] ?? null;
		if ( is_array( $summary ) && ! empty( $summary ) ) {
			$html .= '<table class="widefat striped"><tbody>';
			foreach ( $summary as $key => $value ) {
				$rendered = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
				$html    .= '<tr><th>' . esc_html( (string) $key ) . '</th><td>' . esc_html( $rendered ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		} else {
			$html .= '<p class="description">' . esc_html__( 'No field-level detail was recorded for this change.', 'wp-command-center' ) . '</p>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * @param array<string,mixed>|null $summary
	 */
	private function diff_payload( string $change_id, string $diff_kind, bool $available, ?array $summary, string $html, string $note ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'change_id' => $change_id,
			'diff_kind' => $diff_kind,
			'available' => $available,
			'summary'   => $summary,
			'html'      => $html,
			'note'      => $note,
		], 200 );
	}

	/**
	 * POST /admin/history/{change_id}/rollback — reverse one change.
	 *
	 * Pure reuse: builds the rollback_target payload + an admin actor context
	 * and hands off to OperationExecutor::run( 'change_history', … ). That is the
	 * same chokepoint the token/MCP path uses, so capability, DestructiveGuard
	 * (the ROLLBACK_CHANGE handshake on high-risk-file patch reversals),
	 * security-mode approval, AuditLog and ChangeRecorder all apply unchanged.
	 *
	 * No token_scope/token_id is set: this is a cookie + manage_options request,
	 * the route is the gate, and omitting token_scope keeps the engine's
	 * read-only-token guard inapplicable (it is not a read-only token).
	 *
	 * The structured result is returned verbatim (HTTP 200) so the UI can branch
	 * on result.status — success | pending_approval | confirmation_required —
	 * exactly like the agent-facing surfaces.
	 */
	public function history_rollback( \WP_REST_Request $request ): \WP_REST_Response {
		$change_id = sanitize_text_field( (string) $request->get_param( 'change_id' ) );

		$payload = [ 'action' => 'rollback_target', 'change_id' => $change_id ];

		if ( null !== $request->get_param( 'confirm' ) ) {
			$payload['confirm'] = rest_sanitize_boolean( $request->get_param( 'confirm' ) );
		}
		$phrase = (string) $request->get_param( 'confirmation_phrase' );
		if ( '' !== $phrase ) {
			$payload['confirmation_phrase'] = sanitize_text_field( $phrase );
		}
		$reason = (string) $request->get_param( 'reason' );
		if ( '' !== $reason ) {
			$payload['reason'] = sanitize_textarea_field( $reason );
		}

		$user    = wp_get_current_user();
		$context = [
			'actor'  => AuditLog::resolve_actor( [
				'type'       => 'admin',
				'wp_user_id' => get_current_user_id(),
				'user_login' => $user ? $user->user_login : '',
				'source'     => 'admin_ui',
			] ),
			'source' => 'admin_ui',
		];

		$result = ( new OperationExecutor() )->run( 'change_history', $payload, $context );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /admin/history/sessions — session-grouped roll-up. Presentation-layer
	 * aggregation only (ChangeHistoryAdminQuery); not a runtime/MCP surface.
	 */
	public function history_sessions( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [
			'runtime' => sanitize_text_field( (string) $request->get_param( 'runtime' ) ),
			'status'  => sanitize_text_field( (string) $request->get_param( 'status' ) ),
			'since'   => $request->get_param( 'since' ),
			'until'   => $request->get_param( 'until' ),
		];

		[ $limit, $offset ] = $this->history_paging( $request );

		$result = ( new ChangeHistoryAdminQuery() )->sessions( $filters, $limit, $offset );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Collect the supported read filters from the request, dropping empties so
	 * the runtime manager only applies what was actually sent.
	 *
	 * @return array<string,mixed>
	 */
	private function history_filters( \WP_REST_Request $request ): array {
		$out = [];

		foreach ( [ 'runtime', 'operation_id', 'status', 'target', 'change_set_id', 'session_id', 'task_id', 'plan_id', 'cursor' ] as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value ) {
				$out[ $key ] = sanitize_text_field( $value );
			}
		}

		foreach ( [ 'since', 'until', 'limit' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== (string) $value ) {
				$out[ $key ] = (int) $value;
			}
		}

		if ( null !== $request->get_param( 'reversible_only' ) && '' !== (string) $request->get_param( 'reversible_only' ) ) {
			$out['reversible_only'] = rest_sanitize_boolean( $request->get_param( 'reversible_only' ) );
		}

		return $out;
	}

	/**
	 * @return array{0:int,1:int} [limit, offset]
	 */
	private function history_paging( \WP_REST_Request $request ): array {
		$limit  = (int) ( $request->get_param( 'limit' ) ?: 20 );
		$offset = 0;

		$cursor = (string) $request->get_param( 'cursor' );
		if ( '' !== $cursor ) {
			$decoded = json_decode( (string) base64_decode( $cursor, true ), true );
			if ( is_array( $decoded ) && isset( $decoded['offset'] ) ) {
				$offset = max( 0, (int) $decoded['offset'] );
			}
		}

		return [ $limit, $offset ];
	}

	/**
	 * Run a read action on the STEP 104 runtime manager and map its in-band
	 * { error: true, code, message } shape to an HTTP status.
	 *
	 * @param array<string,mixed> $params
	 */
	private function history_response( array $params ): \WP_REST_Response {
		$result = ( new ChangeHistoryRuntimeManager() )->run( $params );

		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			$code   = (string) ( $result['code'] ?? 'wpcc_history_error' );
			$status = str_contains( $code, 'not_found' ) ? 404 : 400;
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => $code, 'message' => (string) ( $result['message'] ?? '' ) ],
				$status
			);
		}

		return new \WP_REST_Response( $result, 200 );
	}

	private function format_request( array $r, OperationRegistry $registry ): array {
		$operation = $registry->get_operation( $r['operation_id'] ) ?? [];
		$payload   = json_decode( $r['payload'] ?? '{}', true ) ?: [];
		$action    = $payload['action'] ?? '';
		$risk      = SecurityModeManager::effective_risk( $operation, $action );

		// STEP 84 — flag destructive requests so the approval card can render the
		// irreversible-deletion warning prominently.
		$destructive = DestructiveGuard::classify( $r['operation_id'], $payload );

		return [
			'request_id'          => $r['request_id'],
			'operation_id'        => $r['operation_id'],
			'operation'           => $operation['title'] ?? $r['operation_id'],
			'action'              => $action,
			'risk_level'          => $risk,
			'status'              => $r['status'],
			'reason'              => $payload['reason'] ?? '',
			'destructive'         => null !== $destructive,
			'destructive_warning' => null !== $destructive ? $destructive['warning'] : '',
			'payload'             => $payload,
			'session_id'   => $r['session_id'],
			'plan_id'      => $r['plan_id'],
			'created_at'   => (int) $r['created_at'],
			'created_ago'  => human_time_diff( (int) $r['created_at'], time() ) . ' ago',
		];
	}

	// ── STEP 107.1 — Token & Capability Manager reads (read-only) ──────────────
	// All four handlers delegate to the thin TokenCapabilityAdminQuery aggregation
	// over the existing token manifest + capability assignments. No writes, no
	// engine calls, no new source of truth.

	/** GET /admin/tokens — enriched, server-paginated token list (no secrets; preview only). */
	public function tokens_list( \WP_REST_Request $request ): \WP_REST_Response {
		[ $limit, $offset ] = $this->list_paging( $request );
		return new \WP_REST_Response( ( new TokenCapabilityAdminQuery() )->tokens( [], $limit, $offset ), 200 );
	}

	/** GET /admin/tokens/{id} — one token + its 34-operation access matrix. */
	public function token_detail( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$detail = ( new TokenCapabilityAdminQuery() )->token( $id );

		if ( null === $detail ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'token_not_found' ], 404 );
		}

		return new \WP_REST_Response( $detail, 200 );
	}

	/** GET /admin/capabilities — the 23-capability catalogue + unlocked operations. */
	public function capabilities_catalogue(): \WP_REST_Response {
		return new \WP_REST_Response( ( new TokenCapabilityAdminQuery() )->capabilities(), 200 );
	}

	/** GET /admin/operations-map — the 34-entry operation→capability map. */
	public function operations_map(): \WP_REST_Response {
		return new \WP_REST_Response( ( new TokenCapabilityAdminQuery() )->operations_map(), 200 );
	}

	// ── STEP 108.1 — Operations Explorer reads (read-only) ─────────────────────
	// Both handlers delegate to the thin OperationExplorerAdminQuery aggregation
	// over the operation catalogue + capability map. No writes, no execution, no
	// engine dispatch, no new source of truth.

	/** GET /admin/operations — server-paginated, server-filtered operation catalogue. */
	public function operations_list( \WP_REST_Request $request ): \WP_REST_Response {
		[ $limit, $offset ] = $this->list_paging( $request );

		$filters = [];
		foreach ( [ 'search', 'risk' ] as $k ) {
			$v = (string) $request->get_param( $k );
			if ( '' !== $v ) {
				$filters[ $k ] = sanitize_text_field( $v );
			}
		}
		if ( null !== $request->get_param( 'available' ) && '' !== (string) $request->get_param( 'available' ) ) {
			$filters['available'] = rest_sanitize_boolean( $request->get_param( 'available' ) );
		}

		return new \WP_REST_Response( ( new OperationExplorerAdminQuery() )->operations( $filters, $limit, $offset ), 200 );
	}

	/**
	 * S2.1 — parse the canonical limit/offset paging params from a list request.
	 * Accepts an opaque `cursor` (base64 {offset}) as produced by the canonical
	 * `next_cursor`; it takes precedence over a raw `offset`. Read-only; mirrors the
	 * paging contract used by the proposals / history / approvals list reads.
	 *
	 * @return array{0:int,1:int} [ limit, offset ]
	 */
	private function list_paging( \WP_REST_Request $request ): array {
		$limit  = (int) ( $request->get_param( 'limit' ) ?: 20 );
		$offset = (int) ( $request->get_param( 'offset' ) ?: 0 );

		$cursor = (string) $request->get_param( 'cursor' );
		if ( '' !== $cursor ) {
			$decoded = json_decode( (string) base64_decode( $cursor, true ), true );
			if ( is_array( $decoded ) && isset( $decoded['offset'] ) ) {
				$offset = (int) $decoded['offset'];
			}
		}

		return [ max( 1, $limit ), max( 0, $offset ) ];
	}

	/** GET /admin/operations/summary — catalogue header counts (availability / risk / approval). */
	public function operations_summary(): \WP_REST_Response {
		return new \WP_REST_Response( ( new OperationExplorerAdminQuery() )->summary(), 200 );
	}

	/** GET /admin/operations/{id} — one operation's full detail (parameters / per-action risk / authorization). */
	public function operation_detail( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$detail = ( new OperationExplorerAdminQuery() )->operation( $id );

		if ( null === $detail ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'operation_not_found' ], 404 );
		}

		return new \WP_REST_Response( $detail, 200 );
	}

	// ── STEP 107.3 — capability write actions (engine reuse, no bypass) ────────
	// Both delegate to capability_write(), which routes capability_manage THROUGH
	// OperationExecutor::run. They never call CapabilityRegistry::assign()/remove()
	// directly.

	/** POST /admin/tokens/{id}/capabilities — assign one capability to a token. */
	public function token_assign_capability( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->capability_write(
			$request,
			'capability_assign',
			sanitize_text_field( (string) $request->get_param( 'capability' ) )
		);
	}

	/** DELETE /admin/tokens/{id}/capabilities/{cap} — remove one capability. */
	public function token_remove_capability( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->capability_write(
			$request,
			'capability_remove',
			sanitize_text_field( (string) $request->get_param( 'cap' ) )
		);
	}

	/**
	 * STEP 107.3 — shared capability write path. Builds the capability_manage
	 * payload + a token-LESS admin actor (source: admin_ui, no token_id/token_scope)
	 * and routes it THROUGH OperationExecutor::run('capability_manage', …). Because
	 * the admin actor carries no token_id, the engine's token-capability check is
	 * skipped and manage_options (enforced by the route) is the gate; the
	 * capability.admin classification, AuditLog, security-mode approval, and the
	 * CapabilityRegistry system.admin refusal guard all still apply. The structured
	 * executor result is returned verbatim (HTTP 200) so the UI branches on
	 * success / pending_approval / errors. No bypass: CapabilityRegistry is never
	 * touched from here.
	 */
	private function capability_write( \WP_REST_Request $request, string $action, string $capability ): \WP_REST_Response {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		// Read-only existence check (no mutation) so an unknown token 404s rather
		// than creating a stray assignment.
		if ( null === ( new TokenCapabilityAdminQuery() )->token( $id ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'token_not_found' ], 404 );
		}

		$payload = [
			'action'     => $action,
			'subject'    => 'token',
			'subject_id' => $id,
			'capability' => $capability,
		];

		$context = [
			'actor' => [
				'wp_user_id' => get_current_user_id(),
				'user_login' => wp_get_current_user()->user_login,
				'source'     => 'admin_ui',
			],
		];

		$result = ( new OperationExecutor() )->run( 'capability_manage', $payload, $context );

		return new \WP_REST_Response( $result, 200 );
	}

	// ── STEP 107.4 — token lifecycle (create / revoke / delete) ───────────────
	// Pure reuse of AuthTokens (STEP 10). Nothing is reimplemented: create()
	// auto-bootstraps the token's capability assignment, revoke()/delete()
	// auto-deprovision it. The route's manage_options + nonce is the gate. Each
	// action records an admin.token.* audit event (mirroring the admin.approval.*
	// precedent) so the lifecycle is auditable from the admin surface.

	/** POST /admin/tokens — create a token; returns the raw secret ONCE. */
	public function token_create( \WP_REST_Request $request ): \WP_REST_Response {
		$label   = sanitize_text_field( (string) $request->get_param( 'label' ) );
		$scope   = sanitize_key( (string) $request->get_param( 'scope' ) );
		$expires = sanitize_key( (string) $request->get_param( 'expires' ) );

		$expires_at = match ( $expires ) {
			'30d'   => time() + 30 * DAY_IN_SECONDS,
			'90d'   => time() + 90 * DAY_IN_SECONDS,
			'1y'    => time() + YEAR_IN_SECONDS,
			default => null,
		};

		$result = ( new AuthTokens() )->create( $label, $scope, $expires_at, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'errors'  => [ [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] ],
			], 400 );
		}

		$record = $result['record'];

		( new AuditLog() )->record( 'admin.token.created', [
			'token_id' => $record['id'],
			'label'    => $record['label'],
			'scope'    => $record['scope'],
			'actor'    => AuditLog::resolve_actor( $this->admin_actor() ),
		] );

		return new \WP_REST_Response( [
			'success' => true,
			'token'   => $result['token'], // raw secret — shown once, never stored in clear.
			'record'  => $this->safe_token_record( $record ),
		], 201 );
	}

	/** POST /admin/tokens/{id}/revoke — revoke a token (reuses AuthTokens::revoke). */
	public function token_revoke( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->token_lifecycle( $request, 'revoke' );
	}

	/** DELETE /admin/tokens/{id} — delete a token (reuses AuthTokens::delete). */
	public function token_delete( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->token_lifecycle( $request, 'delete' );
	}

	/**
	 * Shared revoke/delete path. Reuses the AuthTokens method of the same name
	 * (which also deprovisions the token's capability assignment) and records an
	 * admin.token.{revoked|deleted} audit event. Never reimplements token storage.
	 */
	private function token_lifecycle( \WP_REST_Request $request, string $op ): \WP_REST_Response {
		$id     = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$tokens = new AuthTokens();

		$result = 'revoke' === $op ? $tokens->revoke( $id ) : $tokens->delete( $id );

		if ( is_wp_error( $result ) ) {
			$status = 'wpcc_token_not_found' === $result->get_error_code() ? 404 : 400;
			return new \WP_REST_Response( [
				'success' => false,
				'errors'  => [ [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] ],
			], $status );
		}

		( new AuditLog() )->record( 'revoke' === $op ? 'admin.token.revoked' : 'admin.token.deleted', [
			'token_id' => $id,
			'actor'    => AuditLog::resolve_actor( $this->admin_actor() ),
		] );

		return new \WP_REST_Response( [
			'success'  => true,
			'token_id' => $id,
			'status'   => 'revoke' === $op ? 'revoked' : 'deleted',
		], 200 );
	}

	/** The cookie-authed admin actor descriptor (no token_id/token_scope). */
	private function admin_actor(): array {
		return [
			'wp_user_id' => get_current_user_id(),
			'user_login' => wp_get_current_user()->user_login,
			'source'     => 'admin_ui',
		];
	}

	/** A display-safe token record: the stored token_hash is dropped entirely. */
	private function safe_token_record( array $record ): array {
		unset( $record['token_hash'] );
		return $record;
	}
}
