<?php
/**
 * Content-field AI generation — provider resolver.
 *
 * Selects the active ContentFieldProvider. Resolution is config-only — it reads a
 * filter and each provider's is_configured() check; it makes NO outbound calls and
 * has no side effects. Returns null when nothing is configured (null-safe), so callers
 * degrade gracefully to "connect an AI provider".
 *
 * Selection order (future-compatible):
 *   1. `wpcc_content_field_provider` filter (explicit override → provider id).
 *   2. AnthropicContentProvider when a key (shared or legacy) is configured.
 *   3. (future) AI Engine delegation / Pro proxy — registered here behind the same
 *      interface without touching callers.
 */

namespace WPCommandCenter\Content;

defined( 'ABSPATH' ) || exit;

// Not final: a thin config-only seam left extensible so tests (and a future
// alternate provider registry) can override active() without touching callers.
class ContentFieldProviderResolver {

	/**
	 * Registered providers, keyed by id. Order is the default preference.
	 *
	 * @return array<string,ContentFieldProvider>
	 */
	private function registry(): array {
		return [
			'anthropic' => new AnthropicContentProvider(),
		];
	}

	/** The active provider, or null when none is configured. No outbound calls. */
	public function active(): ?ContentFieldProvider {
		$registry = $this->registry();

		$forced = (string) apply_filters( 'wpcc_content_field_provider', '' );
		if ( '' !== $forced && isset( $registry[ $forced ] ) && $registry[ $forced ]->is_configured() ) {
			return $registry[ $forced ];
		}

		foreach ( $registry as $provider ) {
			if ( $provider->is_configured() ) {
				return $provider;
			}
		}

		return null;
	}

	/** True when a usable provider is available (config-only check). */
	public function has_active(): bool {
		return null !== $this->active();
	}
}
