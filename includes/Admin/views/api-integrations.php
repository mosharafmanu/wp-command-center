<?php
/**
 * Connect › API & Integrations (Door 3 — Remote Apps over REST).
 *
 * A read-only landing/explainer for connecting an app, SaaS, or automation to this
 * site over the governed REST API. It surfaces ONLY honest, existing facts — the
 * real Base URL (`wp-command-center/v1`), the Bearer-token auth contract, and a real
 * read-only example call (`GET /operations`). It creates NOTHING: token issuance and
 * management live in Settings › Access (the single token surface), and this page links
 * there. No REST route, operation, capability, MCP tool, or schema is added here.
 *
 * Per the UX Master Blueprint (§4, §5) the one question this screen answers is:
 * "Get a Base URL + token and a working example." Writes through the API stay
 * governed (they enter Approvals per the security mode) — stated plainly, never faked.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Security\AuthTokens;

$wpcc_base    = esc_url_raw( rtrim( rest_url( 'wp-command-center/v1' ), '/' ) );
$wpcc_tokens  = ( new AuthTokens() )->list();
$wpcc_active  = array_filter( $wpcc_tokens, static fn ( $t ) => ( $t['status'] ?? '' ) === 'active' );
$wpcc_have_tok = count( $wpcc_active ) > 0;

$wpcc_access_url = admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=access' );
$wpcc_clients_url = admin_url( 'admin.php?page=wpcc-connect&wpcc_tab=clients' );

$wpcc_example = "curl {$wpcc_base}/operations \\\n  -H \"Authorization: Bearer \$WPCC_TOKEN\"";
?>
<div class="wpcc-apiint" style="max-width:880px;">
	<h1><?php esc_html_e( 'API & Integrations', 'wp-command-center' ); ?></h1>
	<p class="description" style="max-width:680px;font-size:14px;">
		<?php esc_html_e( 'Let your own app, service, or automation drive this WordPress site over a governed REST API. Your software sends requests with an access token; reads are instant, and any change is approved, audited, and reversible — exactly like every other door into WP Command Center.', 'wp-command-center' ); ?>
	</p>

	<?php if ( ! $wpcc_have_tok ) : ?>
		<div class="wpcc-cds-empty" role="status" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:6px;padding:18px 20px;margin:16px 0;">
			<p style="margin:0 0 4px;font-size:15px;"><strong><?php esc_html_e( 'Create an access token to connect an app.', 'wp-command-center' ); ?></strong></p>
			<p class="description" style="margin:0 0 12px;"><?php esc_html_e( 'Apps authenticate with a Base URL and a token. Reads stay instant; changes stay governed.', 'wp-command-center' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( $wpcc_access_url ); ?>"><?php esc_html_e( 'Create a token in Settings → Access', 'wp-command-center' ); ?></a>
		</div>
	<?php else : ?>
		<p style="margin:14px 0;">
			<span class="dashicons dashicons-yes" aria-hidden="true" style="color:#00a32a;"></span>
			<?php
			printf(
				/* translators: %d: number of active tokens */
				esc_html( _n( 'You have %d active token. Manage tokens and scopes in Settings → Access.', 'You have %d active tokens. Manage tokens and scopes in Settings → Access.', count( $wpcc_active ), 'wp-command-center' ) ),
				(int) count( $wpcc_active )
			);
			?>
			<a href="<?php echo esc_url( $wpcc_access_url ); ?>"><?php esc_html_e( 'Manage access →', 'wp-command-center' ); ?></a>
		</p>
	<?php endif; ?>

	<h2 style="margin-top:24px;"><?php esc_html_e( 'Connection details', 'wp-command-center' ); ?></h2>
	<table class="widefat striped" style="max-width:680px;">
		<tbody>
			<tr>
				<th scope="row" style="width:140px;"><?php esc_html_e( 'Base URL', 'wp-command-center' ); ?></th>
				<td><code style="font-size:13px;"><?php echo esc_html( $wpcc_base ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authentication', 'wp-command-center' ); ?></th>
				<td><code style="font-size:13px;">Authorization: Bearer &lt;token&gt;</code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Token', 'wp-command-center' ); ?></th>
				<td><a href="<?php echo esc_url( $wpcc_access_url ); ?>"><?php esc_html_e( 'Created & managed in Settings → Access', 'wp-command-center' ); ?></a></td>
			</tr>
		</tbody>
	</table>

	<h2 style="margin-top:24px;"><?php esc_html_e( 'Try a read-only call', 'wp-command-center' ); ?></h2>
	<p class="description" style="max-width:680px;">
		<?php esc_html_e( 'This lists the operations the API exposes — a safe, read-only request that changes nothing. Set WPCC_TOKEN to an active token first.', 'wp-command-center' ); ?>
	</p>
	<pre style="background:#1d2327;color:#c3c4c7;padding:16px;border-radius:4px;font-size:13px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-all;"><code><?php echo esc_html( $wpcc_example ); ?></code></pre>

	<div style="margin-top:20px;padding:14px 16px;background:#f6f7f7;border-left:3px solid #2271b1;border-radius:0 4px 4px 0;max-width:680px;">
		<strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'How changes stay safe', 'wp-command-center' ); ?></strong>
		<p style="margin:0;font-size:13px;color:#50575e;">
			<?php esc_html_e( 'Read requests return immediately. Write requests run through the same engine as every other door: they are capability-scoped to the token, wait for approval when your security mode requires it, are recorded in History, and can be undone where reversible.', 'wp-command-center' ); ?>
		</p>
	</div>

	<p style="margin-top:18px;font-size:13px;">
		<?php
		printf(
			/* translators: %1$s, %2$s: opening/closing link tags */
			esc_html__( 'Connecting a desktop AI assistant (Claude, Cursor, Codex…) instead? Use %1$sAI Clients%2$s.', 'wp-command-center' ),
			'<a href="' . esc_url( $wpcc_clients_url ) . '">',
			'</a>'
		);
		?>
	</p>
</div>
