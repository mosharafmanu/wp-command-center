<?php
/**
 * Provider provenance guard — a customer never reviews placeholder output as AI output.
 *
 * The Built-in AI generators take their provider through an injectable resolver seam,
 * which is how the test suites drive them deterministically: they hand the generator a
 * stub provider that answers "STUB TITLE" / "STUB EXCERPT" and reports itself as
 * provider `stub`, model `stub-model`. Those runs write REAL draft rows through the
 * real ProposalStore, and nothing distinguished them afterwards — so the drafts sat in
 * the Content queue looking exactly like genuine suggestions, and a first-time customer
 * met "STUB TITLE" attributed to "stub-model" on their own posts.
 *
 * The shipped resolvers can only ever return a catalogued provider, so production could
 * not reach a stub on its own. This class makes that guarantee explicit rather than
 * incidental: a generator asks whether the provider in its hand is one the product
 * actually ships, and a provider from outside the catalogue is refused unless the site
 * has deliberately opted in by defining WPCC_ALLOW_TEST_AI_PROVIDER. Test suites define
 * it; no shipped code path does.
 *
 * It performs NO I/O, reads no options, and makes no selection or fallback decision —
 * it answers one yes/no question about an id that was already chosen elsewhere.
 */

namespace WPCommandCenter\Ai;

use WPCommandCenter\Ai\Platform\ProviderCatalog;

defined( 'ABSPATH' ) || exit;

final class ProviderProvenance {

	/**
	 * Opt-in constant a test/development context defines to allow an uncatalogued
	 * (stub/fake) provider to produce drafts. Never defined by shipped code.
	 */
	public const TEST_CONSTANT = 'WPCC_ALLOW_TEST_AI_PROVIDER';

	/** Skip reason reported when an uncatalogued provider is refused. */
	public const REASON = 'provider_not_shipped';

	/** True when this site has explicitly opted in to non-catalogued providers. */
	public static function test_providers_allowed(): bool {
		return defined( self::TEST_CONSTANT ) && (bool) constant( self::TEST_CONSTANT );
	}

	/**
	 * Whether output from this provider id may become a customer-facing draft.
	 *
	 * A catalogued provider is always accepted. Anything else — a stub, a fake, a
	 * half-registered experiment — is accepted only under the explicit test constant.
	 */
	public static function accepts( string $provider_id ): bool {
		if ( ProviderCatalog::is_valid( $provider_id ) ) {
			return true;
		}
		return self::test_providers_allowed();
	}
}
