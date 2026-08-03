<?php
/**
 * The error code an operation uses to reject an action it does not have.
 *
 * Every runtime already answers an unknown action with its OWN self-describing
 * code — content_manage says `wpcc_invalid_content_action`, woocommerce_manage says
 * `wpcc_invalid_woo_action`, and so on for 32 runtimes. That is a public contract:
 * integrations branch on the code, and the uniform `wpcc_invalid_*_action` shape was
 * deliberate work.
 *
 * When the pre-approval guard started catching unknown actions BEFORE dispatch — so a
 * bogus action could no longer be filed as a pending approval that could only ever
 * fail — it answered with a single generic `wpcc_invalid_action`. The guard was right;
 * flattening the code was not. Callers that had switched on the specific code silently
 * stopped matching.
 *
 * This map lets the guard keep its timing and still speak in the runtime's own voice.
 * It is asserted against the runtimes by tests/test-invalid-action-contract.sh, so the
 * two cannot drift apart unnoticed.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class InvalidActionContract {

	/**
	 * operation id => the code that operation's runtime returns for an unknown action.
	 *
	 * Operations absent from this map have no runtime-specific code of their own and
	 * fall back to the generic one.
	 *
	 * Each entry is [ code, runtime noun, optional describe-action hint ]. The noun and
	 * hint reproduce the runtime's own phrasing through the shared
	 * InvalidAction::message() builder, so the guard's message is identical to the one
	 * the runtime would have produced.
	 *
	 * @var array<string,array{0:string,1:string,2:string}>
	 */
	private const CODES = [
		'acf_manage'          => [ 'wpcc_invalid_acf_action', 'ACF', 'acf_describe' ],
		'approval_manage'     => [ 'wpcc_invalid_approval_action', 'approval', '' ],
		'bulk_manage'         => [ 'wpcc_invalid_bulk_action', 'bulk', '' ],
		'cache_manage'        => [ 'wpcc_invalid_cache_action', 'cache', '' ],
		'capability_manage'   => [ 'wpcc_invalid_capability_action', 'capability', '' ],
		'change_history'      => [ 'wpcc_invalid_history_action', 'change_history', '' ],
		'code_search'         => [ 'wpcc_invalid_search_action', 'code search', '' ],
		'comments_manage'     => [ 'wpcc_invalid_comment_action', 'comment', '' ],
		'content_manage'      => [ 'wpcc_invalid_content_action', 'content', '' ],
		'cpt_manage'          => [ 'wpcc_invalid_cpt_action', 'CPT', '' ],
		'database_inspect'    => [ 'wpcc_invalid_db_action', 'database inspection', '' ],
		'elementor_manage'    => [ 'wpcc_invalid_elementor_action', 'Elementor', '' ],
		'file_manage'         => [ 'wpcc_invalid_file_action', 'file', '' ],
		'forms_manage'        => [ 'wpcc_invalid_forms_action', 'forms', '' ],
		'media_enhance'       => [ 'wpcc_invalid_media_enhance_action', 'media enhance', '' ],
		'media_manage'        => [ 'wpcc_invalid_media_action', 'media', '' ],
		'menu_manage'         => [ 'wpcc_invalid_menu_action', 'menu', '' ],
		'option_manage'       => [ 'wpcc_invalid_option_action', 'option', '' ],
		'patch_manage'        => [ 'wpcc_invalid_patch_action', 'patch', '' ],
		'plugin_manage'       => [ 'wpcc_invalid_plugin_action', 'plugin', '' ],
		'report_manage'       => [ 'wpcc_invalid_report_action', 'report', '' ],
		'rollback_manage'     => [ 'wpcc_invalid_rollback_action', 'rollback', '' ],
		'search_manage'       => [ 'wpcc_invalid_search_action', 'search', '' ],
		'seo_manage'          => [ 'wpcc_invalid_seo_action', 'SEO', '' ],
		'settings_manage'     => [ 'wpcc_invalid_settings_action', 'settings', '' ],
		'site_builder_manage' => [ 'wpcc_invalid_site_builder_action', 'site builder', '' ],
		'snapshot_manage'     => [ 'wpcc_invalid_snapshot_action', 'snapshot', '' ],
		'term_manage'         => [ 'wpcc_invalid_term_action', 'term', '' ],
		'theme_manage'        => [ 'wpcc_invalid_theme_action', 'theme', '' ],
		'user_manage'         => [ 'wpcc_invalid_user_action', 'user', '' ],
		'widgets_manage'      => [ 'wpcc_invalid_widgets_action', 'widgets', '' ],
		'woocommerce_manage'  => [ 'wpcc_invalid_woo_action', 'WooCommerce', 'woo_describe' ],
		'workflow_manage'     => [ 'wpcc_invalid_workflow_action', 'workflow', '' ],
	];

	/** The generic code, for operations with no runtime-specific one. */
	public const GENERIC = 'wpcc_invalid_action';

	/**
	 * The code this operation rejects an unknown action with.
	 */
	public static function code_for( string $operation_id ): string {
		return self::CODES[ $operation_id ][0] ?? self::GENERIC;
	}

	/**
	 * The message this operation's runtime would have produced.
	 *
	 * @param array<int,string> $valid The operation's declared actions.
	 */
	public static function message_for( string $operation_id, string $action, array $valid ): string {
		$entry = self::CODES[ $operation_id ] ?? null;

		if ( null === $entry ) {
			return sprintf(
				/* translators: 1: the action that was requested, 2: the operation id, 3: comma-separated list of valid actions. */
				__( 'Invalid action "%1$s" for %2$s. Valid actions: %3$s.', 'ai-command-center' ),
				$action,
				$operation_id,
				implode( ', ', $valid )
			);
		}

		return InvalidAction::message( $entry[1], $action, $valid, $entry[2] );
	}

	/**
	 * The whole map, for the contract test.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function all(): array {
		return self::CODES;
	}
}
