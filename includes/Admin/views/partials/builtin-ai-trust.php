<?php
/**
 * Shared Built-in AI trust strip (Phase 2.5A).
 *
 * Surfaces the Four Guarantees consistently at the top of every Built-in AI screen so
 * the customer always sees how AI stays safe — Reviewed · Requires approval · Audited ·
 * Reversible. Pure presentation: reuses CDS chip tokens, states only real guarantees,
 * implies no autonomous execution and no metrics. Include via:
 *   require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/builtin-ai-trust.php';
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wpcc-bai-trust" role="note" aria-label="<?php esc_attr_e( 'How WP Command Center keeps AI safe', 'wp-command-center' ); ?>">
	<span class="wpcc-bai-trust__label"><?php esc_html_e( 'Every AI change is', 'wp-command-center' ); ?></span>
	<span class="wpcc-cds-chip wpcc-cds-chip--audited"><?php esc_html_e( 'Reviewed by you', 'wp-command-center' ); ?></span>
	<span class="wpcc-cds-chip wpcc-cds-chip--scoped"><?php esc_html_e( 'Requires approval', 'wp-command-center' ); ?></span>
	<span class="wpcc-cds-chip wpcc-cds-chip--audited"><?php esc_html_e( 'Audited', 'wp-command-center' ); ?></span>
	<span class="wpcc-cds-chip wpcc-cds-chip--reversible"><?php esc_html_e( 'Reversible', 'wp-command-center' ); ?></span>
</div>
