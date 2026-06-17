<?php
/**
 * STEP 105.4 — Feature gate seam (Free/Pro licensing switch point).
 *
 * The single, centralized place where future Free/Pro gating will be decided.
 * TODAY IT IS UNGATED: every feature is allowed, so there is no behavior change
 * and no licensing logic. When licensing arrives (roadmap A3), the decision is
 * flipped HERE (or via the `wpcc_feature_allowed` filter) — call sites never
 * change.
 *
 * Call sites pass a stable feature key (e.g. 'change_history'). Keep this class
 * free of any licensing/storage logic; it is a seam, not an implementation.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class FeatureGate {

	/**
	 * Whether a feature is available in the current edition.
	 *
	 * Ungated by design in STEP 105.4 — always true. The `wpcc_feature_allowed`
	 * filter is the documented extension point so a future licensing layer can
	 * gate features without touching any call site.
	 */
	public static function allows( string $feature ): bool {
		/**
		 * Filter whether a WPCC feature is permitted in the current edition.
		 *
		 * @param bool   $allowed Default true (no gating shipped yet).
		 * @param string $feature Stable feature key, e.g. 'change_history'.
		 */
		return (bool) apply_filters( 'wpcc_feature_allowed', true, $feature );
	}
}
