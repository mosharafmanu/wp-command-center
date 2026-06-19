<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7B: provider resolver.
 *
 * Selects the active AltTextProvider. Resolution is config-only — it reads
 * constants/options and consults a filter; it makes NO outbound calls and has no
 * side effects. Returns null when nothing is configured (null-safe), so callers
 * degrade gracefully to "connect a vision provider".
 *
 * Selection order (future-compatible):
 *   1. `wpcc_alt_text_provider` filter (explicit override → provider id).
 *   2. AnthropicVisionProvider when its key is configured.
 *   3. (future) AI Engine delegation, then a Pro proxy — registered here behind
 *      the same interface without touching callers.
 */

namespace WPCommandCenter\AltText;

defined( 'ABSPATH' ) || exit;

final class ProviderResolver {

	/**
	 * Registered providers, keyed by id. Order is the default preference.
	 * Future providers (ai-engine, proxy) are added here only.
	 *
	 * @return array<string,AltTextProvider>
	 */
	private function registry(): array {
		return [
			'anthropic' => new AnthropicVisionProvider(),
		];
	}

	/** The active provider, or null when none is configured. No outbound calls. */
	public function active(): ?AltTextProvider {
		$registry = $this->registry();

		// 1. Explicit override by id (if that provider exists and is configured).
		$forced = (string) apply_filters( 'wpcc_alt_text_provider', '' );
		if ( '' !== $forced && isset( $registry[ $forced ] ) && $registry[ $forced ]->is_configured() ) {
			return $registry[ $forced ];
		}

		// 2. First configured provider in preference order.
		foreach ( $registry as $provider ) {
			if ( $provider->is_configured() ) {
				return $provider;
			}
		}

		// 3. Nothing configured.
		return null;
	}

	/** True when a usable provider is available (config-only check). */
	public function has_active(): bool {
		return null !== $this->active();
	}

	/** Ids of all registered providers (for diagnostics/UI; not a config check). */
	public function available(): array {
		return array_keys( $this->registry() );
	}
}
