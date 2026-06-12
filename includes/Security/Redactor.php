<?php
/**
 * Step 10 — Credential Protection & Redaction Layer.
 *
 * Scans text for common secret formats (API keys, tokens, passwords,
 * private key blocks, etc.) and replaces matches with [REDACTED_SECRET].
 */

namespace WPCommandCenter\Security;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	/**
	 * Replacement marker for any detected secret.
	 */
	public const PLACEHOLDER = '[REDACTED_SECRET]';

	/**
	 * Each entry is [pattern, replacement]. Applied in order: structural
	 * and vendor-prefixed secrets (private keys, JWTs, cloud/API provider
	 * keys, auth headers) are matched first, with a generic key=value/
	 * key:value fallback last to catch SMTP/DB/JWT/PayPal-style config
	 * assignments that don't have a recognizable token format.
	 */
	private const PATTERNS = [
		// PEM-encoded private key blocks.
		[ '/-----BEGIN(?: [A-Z0-9]+)? PRIVATE KEY-----[\s\S]*?-----END(?: [A-Z0-9]+)? PRIVATE KEY-----/', '[REDACTED_SECRET]' ],

		// JWTs (header.payload.signature).
		[ '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[REDACTED_SECRET]' ],

		// AWS access key IDs.
		[ '/\bAKIA[0-9A-Z]{16}\b/', '[REDACTED_SECRET]' ],

		// Anthropic API keys.
		[ '/\bsk-ant-[A-Za-z0-9_-]{20,}\b/', '[REDACTED_SECRET]' ],

		// OpenAI API keys (sk-... / sk-proj-..., but not sk-ant-...).
		[ '/\bsk-(?!ant-)[A-Za-z0-9_-]{20,}\b/', '[REDACTED_SECRET]' ],

		// Stripe keys (secret/publishable/restricted, live or test).
		[ '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b/', '[REDACTED_SECRET]' ],

		// Authorization headers (any scheme).
		[ '/(Authorization\s*:\s*)\S.*/i', '$1[REDACTED_SECRET]' ],

		// Standalone bearer tokens.
		[ '/\b(Bearer\s+)[A-Za-z0-9._\-]{8,}/i', '$1[REDACTED_SECRET]' ],

		// Basic-auth credentials embedded in URLs (scheme://user:pass@host).
		[ '#(://[^/\s:@]+:)[^/\s@]+(@)#', '$1[REDACTED_SECRET]$2' ],

		// Generic password/secret/token/key assignments. Covers SMTP and
		// database passwords, JWT secrets, PayPal client secrets, and other
		// "name = value" / "name: value" config-style pairs.
		[ '/((?:passwd|password|pwd|secret|api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|private[_-]?key)\s*[=:]\s*[\'"]?)[^\'"\s,;]{4,}([\'"]?)/i', '$1[REDACTED_SECRET]$2' ],
	];

	/**
	 * Redact a single string. Returns the redacted text and how many
	 * substitutions were made.
	 *
	 * @return array{text: string, count: int}
	 */
	public function redact( string $text ): array {
		$count = 0;

		foreach ( self::PATTERNS as [ $pattern, $replacement ] ) {
			$replaced = preg_replace( $pattern, $replacement, $text, -1, $matches );

			if ( null !== $replaced ) {
				$text   = $replaced;
				$count += $matches;
			}
		}

		return [ 'text' => $text, 'count' => $count ];
	}

	/**
	 * Recursively redact every string value within an array (or scalar).
	 * Array keys are left untouched.
	 *
	 * @param mixed $data
	 * @return array{data: mixed, count: int}
	 */
	public function redact_recursive( mixed $data ): array {
		if ( is_string( $data ) ) {
			$result = $this->redact( $data );

			return [ 'data' => $result['text'], 'count' => $result['count'] ];
		}

		if ( is_array( $data ) ) {
			$count = 0;
			$out   = [];

			foreach ( $data as $key => $value ) {
				$result      = $this->redact_recursive( $value );
				$out[ $key ] = $result['data'];
				$count      += $result['count'];
			}

			return [ 'data' => $out, 'count' => $count ];
		}

		return [ 'data' => $data, 'count' => 0 ];
	}
}
