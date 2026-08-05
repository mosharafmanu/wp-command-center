<?php
/**
 * Shared trust strip (Phase 2.5A → generalized in 2.5B).
 *
 * The single, canonical presentation of the Four Guarantees, surfaced on every write
 * screen so the customer always sees how WP Command Center stays safe — Reviewed ·
 * Requires approval · Audited · Reversible. Pure presentation: reuses CDS chip tokens,
 * states only real guarantees, implies no autonomous execution and no metrics. Include via:
 *   require WPCC_PLUGIN_DIR . 'includes/Admin/views/partials/trust-strip.php';
 *
 * MODE-AWARE. The approval chip used to read "Requires approval" on every site,
 * including one running in Development mode — where the engine applies AI changes
 * immediately and no approval is requested at all. This strip appears on SEO, Alt
 * Text and Content, so that was the product asserting its central safety promise,
 * falsely, on every screen where a customer generates something.
 *
 * The REVIEW chip follows the mode for the same reason. It was left hardcoded as
 * "Reviewed by you" beside the corrected approval chip, so a Development site read
 * "Reviewed by you · Runs immediately" — one fact stated twice, in opposite
 * directions. Review is the approval step; both now come from SecurityModeManager.
 *
 * REVERSIBILITY IS PER SCREEN, not universal. Set `$wpcc_trust_reversible = false`
 * before including this partial on a screen whose operation records no rollback
 * point. Safe Search & Replace is the case that matters: the engine reports
 * `rollback_available: false` and warns that rows "cannot be automatically
 * reverted", the change log stores `reversible=0 rollback_kind=none`, and the
 * Changes screen correctly renders no Undo — while this strip told the customer
 * "Every change is Reversible" on the very screen where they run it. Defaults to
 * true so the reversible screens (SEO, Alt Text, Content, Recommendations) are
 * unchanged.
 */

defined( 'ABSPATH' ) || exit;

use WPCommandCenter\Operations\SecurityModeManager;

$wpcc_ts_protected  = SecurityModeManager::is_protected();
$wpcc_ts_warning    = SecurityModeManager::dev_warning();
// Read, then reset, so a screen that sets it cannot leak the flag into a later
// include on the same request.
$wpcc_ts_reversible = ! isset( $wpcc_trust_reversible ) || (bool) $wpcc_trust_reversible;
$wpcc_trust_reversible = null;
?>
<div class="wpcc-bai-trust" role="note" aria-label="<?php esc_attr_e( 'How WP Command Center keeps changes safe', 'ai-command-center' ); ?>">
	<span class="wpcc-bai-trust__label"><?php esc_html_e( 'Every change is', 'ai-command-center' ); ?></span>
	<span class="wpcc-cds-chip <?php echo $wpcc_ts_protected ? 'wpcc-cds-chip--audited' : 'wpcc-cds-chip--irreversible'; ?>">
		<?php echo esc_html( SecurityModeManager::review_chip() ); ?>
	</span>
	<span class="wpcc-cds-chip <?php echo $wpcc_ts_protected ? 'wpcc-cds-chip--scoped' : 'wpcc-cds-chip--irreversible'; ?>">
		<?php echo esc_html( SecurityModeManager::approval_chip() ); ?>
	</span>
	<span class="wpcc-cds-chip wpcc-cds-chip--audited"><?php esc_html_e( 'Audited', 'ai-command-center' ); ?></span>
	<?php if ( $wpcc_ts_reversible ) : ?>
		<span class="wpcc-cds-chip wpcc-cds-chip--reversible"><?php esc_html_e( 'Reversible', 'ai-command-center' ); ?></span>
	<?php else : ?>
		<span class="wpcc-cds-chip wpcc-cds-chip--irreversible"><?php esc_html_e( 'Not reversible', 'ai-command-center' ); ?></span>
	<?php endif; ?>
</div>
<?php if ( '' !== $wpcc_ts_warning ) : ?>
	<p class="wpcc-bai-devnote" role="note">
		<?php echo esc_html( $wpcc_ts_warning ); ?>
		<a href="<?php echo esc_url( SecurityModeManager::settings_url() ); ?>"><?php esc_html_e( 'Change protection', 'ai-command-center' ); ?></a>
	</p>
<?php endif; ?>
