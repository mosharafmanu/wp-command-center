<?php
/**
 * Phase D Safety — Universal AI Provider Runtime: outbound endpoint guard (SSRF).
 *
 * Validates an admin-supplied AI endpoint BEFORE any outbound generation request,
 * to stop server-side request forgery against internal infrastructure. It:
 *   - allows only http / https,
 *   - blocks hosts that resolve to loopback / private / link-local / reserved IPs
 *     by DEFAULT,
 *   - allows those ranges ONLY when the provider is a declared local provider
 *     (Ollama / LM Studio / vLLM) and the caller explicitly intends it,
 *   - is the validation half; the transport additionally disables redirects so a
 *     public host cannot 3xx-bounce into private space.
 *
 * Errors as DATA (never thrown), no secrets in any message. Pure: no options, no
 * generation, no engine/governance interaction.
 *
 * Residual (documented): a host that resolves public at check time but private at
 * connect time (DNS rebinding) is not fully pinned here; redirects are disabled by
 * the transport and the check covers the common cases. IP pinning is a later step.
 */

namespace WPCommandCenter\Ai\Http;

defined( 'ABSPATH' ) || exit;

final class AiEndpointGuard {

	/**
	 * @return array{ok:bool,code:string,message:string}
	 */
	public static function validate( string $url, bool $allow_local = false ): array {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return self::block( 'invalid_scheme', __( 'The endpoint must use http or https.', 'wp-command-center' ) );
		}

		$host = (string) ( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return self::block( 'invalid_host', __( 'The endpoint has no host.', 'wp-command-center' ) );
		}

		$ips = self::resolve( $host );
		if ( empty( $ips ) ) {
			return self::block( 'unresolvable_host', __( 'The endpoint host could not be resolved.', 'wp-command-center' ) );
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				if ( $allow_local ) {
					continue; // a declared local provider may use a loopback/private address.
				}
				return self::block( 'private_endpoint', __( 'The endpoint resolves to a private, local, or reserved address, which is not allowed for this provider.', 'wp-command-center' ) );
			}
		}

		return [ 'ok' => true, 'code' => '', 'message' => '' ];
	}

	/**
	 * Resolve a host to candidate IPs (IPv4 + IPv6). IP literals pass through.
	 *
	 * @return array<int,string>
	 */
	private static function resolve( string $host ): array {
		$literal = trim( $host, '[]' ); // strip IPv6 brackets
		if ( false !== filter_var( $literal, FILTER_VALIDATE_IP ) ) {
			return [ $literal ];
		}

		$ips = [];
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = array_merge( $ips, $v4 );
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $rec ) {
					if ( ! empty( $rec['ipv6'] ) ) {
						$ips[] = (string) $rec['ipv6'];
					}
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/** True only for a routable public IP (private/loopback/link-local/reserved excluded). */
	private static function is_public_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * @return array{ok:bool,code:string,message:string}
	 */
	private static function block( string $code, string $message ): array {
		return [ 'ok' => false, 'code' => $code, 'message' => $message ];
	}
}
