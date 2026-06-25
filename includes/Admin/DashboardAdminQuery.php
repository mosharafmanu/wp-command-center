<?php
/**
 * STEP 109.1 — Dashboard Overview admin aggregation read (presentation layer).
 *
 * Powers the wp-admin "Dashboard Overview": a single at-a-glance roll-up of the
 * existing admin surfaces — Approval Center (STEP 106), Change History
 * (STEP 104/105), Tokens & Capabilities (STEP 107), Operations Explorer
 * (STEP 108) — plus the live security posture (SecurityModeManager, STEP 80) and
 * the platform invariants. It is a presentation-layer helper — NOT a runtime API,
 * NOT MCP-exposed, and NOT a new source of truth. It never writes, never executes
 * an operation, never dispatches the engine, and must never grow runtime/business
 * logic.
 *
 * Thin aggregator by design: it does NOT re-query the underlying tables. It fans
 * out to the EXISTING read-only AdminQuery classes' summary methods and composes
 * their results. Each subsystem remains the single owner of its own truth:
 *
 *   - Approvals      → ApprovalAdminQuery::summary()        (wpcc_operation_requests / _queue)
 *   - Operations     → OperationExplorerAdminQuery::summary() (OperationRegistry + CapabilityRegistry)
 *   - Tokens & caps  → TokenCapabilityAdminQuery::tokens()/::capabilities() (AuthTokens + CapabilityRegistry)
 *   - Change history → ChangeHistoryAdminQuery::sessions()  (wpcc_change_log)
 *   - Security mode  → SecurityModeManager::current()/label()
 *   - Invariants     → CapabilityRegistry constants + the operation catalogue count + Schema::DB_VERSION
 *
 * The MCP-tool count is NOT obtained by invoking the MCP runtime: McpServerRuntime
 * audits every tools/list call (a write side effect). Because the runtime builds
 * exactly one tool per catalogue operation (McpServerRuntime::tools_list iterates
 * OperationRegistry::get_operations() 1:1), the live MCP-tool count equals the
 * catalogue count by construction, read from the same registry with no side effect.
 *
 * STEP 109 is additive and read-only: this surface only summarises and links out.
 * (The legacy operational "Runtime" dashboard it once referenced was retired in the
 * Phase 2B Runtime migration; its tools now live in Settings › Tools and
 * Settings › Diagnostics › Recommendations.)
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\CapabilityRegistry;
use WPCommandCenter\Operations\SecurityModeManager;
use WPCommandCenter\Core\Schema;

defined( 'ABSPATH' ) || exit;

final class DashboardAdminQuery {

	/** How many recent change sessions the activity feed surfaces (STEP 109.2). */
	private const RECENT_LIMIT = 5;

	/**
	 * Phase B / W2 — the FeatureGate key that owns each surfaced sub-surface block.
	 *
	 * The Dashboard Overview re-checks the OWNING surface's gate before composing
	 * its block, so a sub-surface disabled by a future Free/Pro edition (or via the
	 * `wpcc_feature_allowed` filter) is never leaked through this aggregator. Keys
	 * mirror the per-surface REST gates in AdminRestApi exactly. Ungated today — when
	 * every gate is open the envelope is byte-identical to the pre-W2 behavior.
	 */
	private const FEATURE_KEYS = [
		'approvals'      => 'approval_center',
		'operations'     => 'operations_explorer',
		'tokens'         => 'token_capability_manager',
		'change_history' => 'change_history',
	];

	/** Marker returned in place of a sub-surface block whose own FeatureGate is closed. */
	private const GATED = [ 'gated' => true ];

	/**
	 * The Dashboard Overview envelope: the live security posture, the platform
	 * invariants, a compact summary of each existing subsystem (approvals,
	 * operations, tokens & capabilities, change history), and a recent change
	 * activity feed. Every number is derived from the subsystem that owns it —
	 * this method adds no policy and no new source of truth. Read-only.
	 *
	 * Phase B / W2: every sub-surface block is composed only after its OWNING
	 * surface's FeatureGate is re-checked (see FEATURE_KEYS). A gated-off sub-surface
	 * yields a {gated:true} marker (or an empty list for the activity feed) instead of
	 * its data, so the aggregator can never leak a feature the current edition has
	 * disabled. The security posture and the invariants strip are platform metadata
	 * (not sub-surface data) and are therefore never gated.
	 *
	 * @return array<string,mixed> {
	 *     action, security, invariants, approvals, operations, tokens,
	 *     change_history, recent_activity
	 * }
	 */
	public function overview(): array {
		$operations_open = $this->allows( 'operations' );
		$history_open    = $this->allows( 'change_history' );

		// Only query a sub-surface whose own gate is open; otherwise we neither
		// compute nor surface its data. The catalogue count for the invariant strip
		// is derived independently of the (gateable) Operations Explorer summary.
		$operations = $operations_open ? ( new OperationExplorerAdminQuery() )->summary() : null;

		// STEP 109.2 — a single bounded session roll-up feeds BOTH the change
		// history headline count (total_count) and the recent activity feed (the
		// returned rows). One call, reused — no second query, no new source of truth.
		$sessions = $history_open ? ( new ChangeHistoryAdminQuery() )->sessions( [], self::RECENT_LIMIT, 0 ) : null;

		return [
			'action'          => 'dashboard_overview',
			'security'        => $this->security(),
			'invariants'      => $this->invariants( $operations ),
			'approvals'       => $this->allows( 'approvals' ) ? $this->approvals() : self::GATED,
			'operations'      => $operations_open ? $this->operations( $operations ) : self::GATED,
			'tokens'          => $this->allows( 'tokens' ) ? $this->tokens() : self::GATED,
			'change_history'  => $history_open ? $this->change_history( $sessions ) : self::GATED,
			'recent_activity' => $history_open ? $this->recent_activity( $sessions ) : [],
		];
	}

	/**
	 * Phase B / W2 — whether a surfaced sub-surface block's owning FeatureGate is
	 * open. Unknown block keys default to allowed (only the FEATURE_KEYS blocks are
	 * gated; the security posture and invariants are platform metadata).
	 */
	private function allows( string $block ): bool {
		$key = self::FEATURE_KEYS[ $block ] ?? '';
		return '' === $key ? true : FeatureGate::allows( $key );
	}

	/** Current security posture (mode key + human label) for the header. */
	private function security(): array {
		return [
			'mode'  => SecurityModeManager::current(),
			'label' => SecurityModeManager::label(),
		];
	}

	/**
	 * The platform invariants strip. operation_map / capabilities are the
	 * CapabilityRegistry constant counts; catalogue is the live operation count
	 * (reused from the Operations Explorer summary to avoid a second registry
	 * build); mcp_tools equals the catalogue count by construction (one MCP tool
	 * per operation — see class docblock); db_version is the schema constant.
	 *
	 * Platform metadata, never gated: when the Operations Explorer summary is
	 * unavailable because that surface's gate is closed ($operations === null), the
	 * catalogue count is read directly from the registry so the strip stays accurate.
	 *
	 * @param array<string,mixed>|null $operations The OperationExplorerAdminQuery summary, or null when gated off.
	 * @return array<string,mixed>
	 */
	private function invariants( ?array $operations ): array {
		$catalogue = is_array( $operations ) && isset( $operations['total'] )
			? (int) $operations['total']
			: count( ( new OperationRegistry() )->get_operations() );

		return [
			'operation_map' => count( CapabilityRegistry::OPERATION_MAP ),
			'capabilities'  => count( CapabilityRegistry::ALL_CAPABILITIES ),
			'catalogue'     => $catalogue,
			'mcp_tools'     => $catalogue,
			'db_version'    => Schema::DB_VERSION,
		];
	}

	/**
	 * Approval Center headline counts — reused verbatim from the surface that owns
	 * the operation-request / queue tables. Read-only.
	 *
	 * @return array<string,int>
	 */
	private function approvals(): array {
		$summary = ( new ApprovalAdminQuery() )->summary();

		return [
			'pending'          => (int) ( $summary['pending'] ?? 0 ),
			'pending_critical' => (int) ( $summary['pending_critical'] ?? 0 ),
			'resolved'         => (int) ( $summary['resolved'] ?? 0 ),
			'queue_failed'     => (int) ( $summary['queue_failed'] ?? 0 ),
		];
	}

	/**
	 * Operation catalogue headline counts — a compact projection of the Operations
	 * Explorer summary (which owns the registry join). Read-only.
	 *
	 * @param array<string,mixed> $summary The OperationExplorerAdminQuery summary.
	 * @return array<string,mixed>
	 */
	private function operations( array $summary ): array {
		$by_risk = is_array( $summary['by_risk'] ?? null ) ? array_map( 'intval', $summary['by_risk'] ) : [];

		return [
			'total'             => (int) ( $summary['total'] ?? 0 ),
			'available'         => (int) ( $summary['available'] ?? 0 ),
			'unavailable'       => (int) ( $summary['unavailable'] ?? 0 ),
			'requires_approval' => (int) ( $summary['requires_approval_count'] ?? 0 ),
			'unrestricted'      => (int) ( $summary['unmapped_count'] ?? 0 ),
			'by_risk'           => $by_risk,
		];
	}

	/**
	 * Tokens & Capabilities headline counts — reused from the surface that owns the
	 * token store and the capability catalogue. Read-only.
	 *
	 * @return array<string,int>
	 */
	private function tokens(): array {
		$manager = new TokenCapabilityAdminQuery();

		return [
			'total'        => (int) ( $manager->tokens()['total'] ?? 0 ),
			'capabilities' => (int) ( $manager->capabilities()['total'] ?? 0 ),
		];
	}

	/**
	 * Change History headline count — the number of distinct change sessions
	 * recorded, read from the roll-up envelope's total_count. Read-only.
	 *
	 * @param array<string,mixed> $sessions The ChangeHistoryAdminQuery::sessions() envelope.
	 * @return array<string,int>
	 */
	private function change_history( array $sessions ): array {
		return [
			'sessions' => (int) ( $sessions['total_count'] ?? 0 ),
		];
	}

	/**
	 * STEP 109.2 — the recent change activity feed: a compact projection of the
	 * most recent change sessions (newest first), reused from the same roll-up the
	 * change history count comes from. Each row carries enough to render a line and
	 * deep-link into the session on the Change History Timeline. Read-only — it
	 * adds no source of truth and re-shapes nothing the surface does not already
	 * expose.
	 *
	 * @param array<string,mixed> $sessions The ChangeHistoryAdminQuery::sessions() envelope.
	 * @return array<int,array<string,mixed>>
	 */
	private function recent_activity( array $sessions ): array {
		$rows = is_array( $sessions['sessions'] ?? null ) ? $sessions['sessions'] : [];
		$out  = [];

		foreach ( $rows as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$runtimes = is_array( $s['runtimes'] ?? null ) ? array_values( array_map( 'strval', $s['runtimes'] ) ) : [];

			$out[] = [
				'session_id'       => (string) ( $s['session_id'] ?? '' ),
				'last_at'          => (int) ( $s['last_at'] ?? 0 ),
				'change_count'     => (int) ( $s['change_count'] ?? 0 ),
				'reversible_count' => (int) ( $s['reversible_count'] ?? 0 ),
				'actor_summary'    => (string) ( $s['actor_summary'] ?? '' ),
				'runtimes'         => $runtimes,
			];
		}

		return $out;
	}
}
