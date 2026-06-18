<?php
/**
 * STEP 108.1 — Operations Explorer admin aggregation read (presentation layer).
 *
 * Powers the wp-admin "Operations Explorer": an enriched, READ-ONLY roll-up of
 * the operation catalogue (OperationRegistry, STEP 15/80) joined with the
 * operation→capability authorization map (CapabilityRegistry, STEP 38/44/79) and
 * the current security posture (SecurityModeManager, STEP 80). It is a
 * presentation-layer helper — NOT a runtime API, NOT MCP-exposed, and NOT a new
 * source of truth. It never writes, never executes an operation, never adds
 * policy, and must never grow runtime/business logic: it only reads what
 * OperationRegistry and CapabilityRegistry already record and renders the
 * catalogue truthfully.
 *
 * Cheap by design: one OperationRegistry::get_operations() build (the same call
 * the token-authenticated /operations route makes — availability probes incl.
 * plugin-active / class_exists / WP-CLI included) joined in memory against the
 * CapabilityRegistry constants. No DB, no N+1, no caching, no persistence.
 *
 * The catalogue is authoritative and BROADER than the authorization map:
 * OperationRegistry::get_operations() returns every operation (incl. unrestricted
 * read-only / seed operations that carry NO required capability), whereas
 * CapabilityRegistry::OPERATION_MAP only maps the operations that require one. We
 * therefore LEFT JOIN: every catalogue operation is surfaced, and its
 * required_capability is null when the operation is unrestricted — rendered
 * honestly rather than implying a gate that does not exist.
 *
 * STEP 108.1 scope: the list + summary reads only. The per-operation detail read
 * arrives in STEP 108.2; execution is permanently out of scope for this surface.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\CapabilityRegistry;
use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class OperationExplorerAdminQuery {

	/** Display bound on the truncated list summary (full text lives in detail). */
	private const SUMMARY_CHARS = 160;

	private OperationRegistry $registry;

	public function __construct() {
		$this->registry = new OperationRegistry();
	}

	/**
	 * The operation catalogue (sorted by id), each row carrying its risk, approval
	 * requirement, live availability, required capability (null when unrestricted),
	 * read-only-scope eligibility, and a truncated description. The headline list
	 * read. Derived entirely from the registries — no policy added.
	 *
	 * @return array<string,mixed> { action, operations[], total, available_count, security_mode }
	 */
	public function operations(): array {
		$rows = array_map( fn( array $op ): array => $this->summarise_operation( $op ), $this->registry->get_operations() );

		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );

		$available = 0;
		foreach ( $rows as $row ) {
			if ( $row['available'] ) {
				$available++;
			}
		}

		return [
			'action'          => 'operations_list',
			'operations'      => $rows,
			'total'           => count( $rows ),
			'available_count' => $available,
			'security_mode'   => $this->security_mode(),
		];
	}

	/**
	 * STEP 108.2 — one operation's full detail: the catalogue metadata
	 * (description, parameters, per-action risk tiers, live availability) joined
	 * with its authorization (required capability or unrestricted, read-only-scope
	 * eligibility, the system.admin unlock) and its approval posture in the CURRENT
	 * security mode (overall + per action). Returns null when the id is unknown so
	 * the route can 404. READ-ONLY — it never executes the operation.
	 *
	 * @return array<string,mixed>|null
	 */
	public function operation( string $id ): ?array {
		$op = $this->registry->get_operation( $id );
		if ( null === $op ) {
			return null;
		}

		$risk = (string) ( $op['risk_level'] ?? SecurityModeManager::RISK_HIGH );

		return [
			'action'        => 'operation_detail',
			'operation'     => [
				'id'                => $id,
				'title'             => (string) ( $op['title'] ?? $id ),
				'description'       => (string) ( $op['description'] ?? '' ),
				'risk_level'        => $risk,
				'requires_approval' => (bool) ( $op['requires_approval'] ?? false ),
				'available'         => (bool) ( $op['available'] ?? false ),
				'parameters'        => $this->parameters( $op ),
				'action_risks'      => $this->action_risks( $op ),
			],
			'authorization' => [
				'required_capability' => $this->required_capability( $id ),
				'read_only_scope'     => $this->is_read_only_scope( $id ),
				'unlocked_by_admin'   => true,
			],
			'security'      => [
				'mode'              => $this->security_mode(),
				'requires_approval' => SecurityModeManager::requires_approval( $risk ),
			],
		];
	}

	/**
	 * Normalise the operation's declared parameters into a stable, display-safe
	 * list. Reflects the catalogue verbatim (including the auto-appended `reason`
	 * parameter on non-diagnostic operations) so the panel shows exactly what an
	 * agent receives. Nested schema (e.g. patch_manage files.items) is summarised,
	 * not expanded.
	 *
	 * @param array<string,mixed> $op
	 * @return array<int,array<string,mixed>>
	 */
	private function parameters( array $op ): array {
		$params = is_array( $op['parameters'] ?? null ) ? $op['parameters'] : [];
		$out    = [];

		foreach ( $params as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$enum = is_array( $p['enum'] ?? null ) ? array_values( array_map( 'strval', $p['enum'] ) ) : [];

			$out[] = [
				'name'        => (string) ( $p['name'] ?? '' ),
				'type'        => (string) ( $p['type'] ?? '' ),
				'required'    => (bool) ( $p['required'] ?? false ),
				'description' => (string) ( $p['description'] ?? '' ),
				'enum'        => $enum,
			];
		}

		return $out;
	}

	/**
	 * The per-action risk breakdown: each sub-action of the operation with its risk
	 * tier and whether THAT action requires approval in the current security mode.
	 * Sorted by action name. Empty when the operation declares no action_risks.
	 *
	 * @param array<string,mixed> $op
	 * @return array<int,array<string,mixed>>
	 */
	private function action_risks( array $op ): array {
		$risks = is_array( $op['action_risks'] ?? null ) ? $op['action_risks'] : [];
		$out   = [];

		foreach ( $risks as $action => $tier ) {
			$tier  = (string) $tier;
			$out[] = [
				'action'            => (string) $action,
				'risk_level'        => $tier,
				'requires_approval' => SecurityModeManager::requires_approval( $tier ),
			];
		}

		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['action'], $b['action'] ) );

		return $out;
	}

	/**
	 * Header counts for the Operations Explorer: catalogue total, availability
	 * split, risk distribution, how many require approval (in the CURRENT security
	 * mode), and how many carry no required capability (unrestricted). Read-only.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		$operations = $this->registry->get_operations();

		$by_risk = [
			SecurityModeManager::RISK_DIAGNOSTIC => 0,
			SecurityModeManager::RISK_LOW        => 0,
			SecurityModeManager::RISK_MEDIUM     => 0,
			SecurityModeManager::RISK_HIGH       => 0,
			SecurityModeManager::RISK_CRITICAL   => 0,
		];

		$available           = 0;
		$requires_approval   = 0;
		$unmapped            = 0;

		foreach ( $operations as $op ) {
			$id   = (string) ( $op['id'] ?? '' );
			$risk = (string) ( $op['risk_level'] ?? SecurityModeManager::RISK_HIGH );

			if ( isset( $by_risk[ $risk ] ) ) {
				$by_risk[ $risk ]++;
			}
			if ( ! empty( $op['available'] ) ) {
				$available++;
			}
			if ( ! empty( $op['requires_approval'] ) ) {
				$requires_approval++;
			}
			if ( null === $this->required_capability( $id ) ) {
				$unmapped++;
			}
		}

		$total = count( $operations );

		return [
			'action'                  => 'operations_summary',
			'total'                   => $total,
			'available'               => $available,
			'unavailable'             => $total - $available,
			'by_risk'                 => $by_risk,
			'requires_approval_count' => $requires_approval,
			'unmapped_count'          => $unmapped,
			'security_mode'           => $this->security_mode(),
		];
	}

	/**
	 * Normalise one catalogue operation into a display-safe list summary. Joins the
	 * required capability (null when unrestricted) and read-only-scope eligibility
	 * from CapabilityRegistry; truncates the description for the list.
	 *
	 * @param array<string,mixed> $op
	 * @return array<string,mixed>
	 */
	private function summarise_operation( array $op ): array {
		$id          = (string) ( $op['id'] ?? '' );
		$description = (string) ( $op['description'] ?? '' );
		$risks       = is_array( $op['action_risks'] ?? null ) ? $op['action_risks'] : [];

		return [
			'id'                  => $id,
			'title'               => (string) ( $op['title'] ?? $id ),
			'risk_level'          => (string) ( $op['risk_level'] ?? SecurityModeManager::RISK_HIGH ),
			'requires_approval'   => (bool) ( $op['requires_approval'] ?? false ),
			'available'           => (bool) ( $op['available'] ?? false ),
			'required_capability' => $this->required_capability( $id ),
			'read_only_scope'     => $this->is_read_only_scope( $id ),
			'action_count'        => count( $risks ),
			'summary'             => $this->truncate( $description ),
		];
	}

	/**
	 * The required capability for an operation, or null when the operation is
	 * unrestricted (not present in OPERATION_MAP — e.g. system_info or the seed
	 * operations). A pure LEFT JOIN against the registry constant; no policy added.
	 */
	private function required_capability( string $id ): ?string {
		return CapabilityRegistry::OPERATION_MAP[ $id ] ?? null;
	}

	/** Whether a read-only-scope token may call this operation (registry truth). */
	private function is_read_only_scope( string $id ): bool {
		return in_array( $id, CapabilityRegistry::READ_ONLY_SCOPE_OPERATIONS, true );
	}

	/** Current security posture (mode key + human label) for the header. */
	private function security_mode(): array {
		return [
			'mode'  => SecurityModeManager::current(),
			'label' => SecurityModeManager::label(),
		];
	}

	/** Truncate a description for the list view without splitting mid-word. */
	private function truncate( string $text ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( strlen( $text ) <= self::SUMMARY_CHARS ) {
			return $text;
		}
		$slice = substr( $text, 0, self::SUMMARY_CHARS );
		$space = strrpos( $slice, ' ' );
		if ( false !== $space && $space > 0 ) {
			$slice = substr( $slice, 0, $space );
		}
		return $slice . '…';
	}
}
