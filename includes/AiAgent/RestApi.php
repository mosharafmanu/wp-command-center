<?php
/**
 * Layer 3 — AI Agent Engine REST API (Claude, Codex, GPT, MCP agents).
 *
 * Every route requires an `Authorization: Bearer <token>` header (see
 * Settings → API Tokens). Read-only tokens may call GET routes; the
 * Patch Engine write actions (create/approve/reject/apply/rollback)
 * require a full-access token.
 */

namespace WPCommandCenter\AiAgent;

use WPCommandCenter\Core\Schema;
use WPCommandCenter\Diagnostics\PerformanceDiagnostics;
use WPCommandCenter\Diagnostics\SecurityDiagnostics;
use WPCommandCenter\Diagnostics\WooCommerceDiagnostics;
use WPCommandCenter\Diagnostics\DebugLogViewer;
use WPCommandCenter\Operations\AcfSeed;
use WPCommandCenter\Operations\Cf7Seed;
use WPCommandCenter\Operations\ContentSeed;
use WPCommandCenter\Operations\WooProductSeed;
use WPCommandCenter\Operations\OperationExecutor;
use WPCommandCenter\Operations\OperationManager;
use WPCommandCenter\Operations\OperationQueue;
use WPCommandCenter\Operations\OperationResults;
use WPCommandCenter\Operations\OperationWorker;
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\SearchReplace;
use WPCommandCenter\Operations\MediaImport;
use WPCommandCenter\Operations\SafeUpdates;
use WPCommandCenter\Operations\WpCliBridge;
use WPCommandCenter\Operations\OptionRegistry;
use WPCommandCenter\Operations\OptionManager;
use WPCommandCenter\Operations\PluginRegistry;
use WPCommandCenter\Operations\PluginManager;
use WPCommandCenter\Operations\ThemeRegistry;
use WPCommandCenter\Operations\ThemeManager;
use WPCommandCenter\Operations\SnapshotRegistry;
use WPCommandCenter\Operations\SnapshotManager;
use WPCommandCenter\Operations\ContentRegistry;
use WPCommandCenter\Operations\ContentManager;
use WPCommandCenter\Operations\DatabaseRegistry;
use WPCommandCenter\Operations\DatabaseInspector;
use WPCommandCenter\Operations\CapabilityRegistry;
use WPCommandCenter\Operations\CapabilityManager;
use WPCommandCenter\Rollback\SnapshotManager as CoreSnapshotManager;
use WPCommandCenter\PatchSystem\PatchApproval;
use WPCommandCenter\PatchSystem\PatchManager;
use WPCommandCenter\Recommendations\RecommendationEngine;
use WPCommandCenter\Health\HealthVerificationEngine;
use WPCommandCenter\System\CleanupManager;
use WPCommandCenter\System\EnvironmentManager;
use WPCommandCenter\Integration\AIClientRegistry;
use WPCommandCenter\Integration\ClaudeIntegration;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Security\Redactor;
use WPCommandCenter\SiteIntelligence\SiteScanner;
use WPCommandCenter\Operations\MediaRegistry;
use WPCommandCenter\Operations\WooCommerceRegistry;
use WPCommandCenter\Operations\UserRegistry;
use WPCommandCenter\Operations\ACFRegistry;
use WPCommandCenter\Operations\FormsRegistry;
use WPCommandCenter\Operations\MenuRegistry;
use WPCommandCenter\Operations\SettingsRegistry;
use WPCommandCenter\Operations\SearchRegistry;
use WPCommandCenter\Operations\BulkRegistry;
use WPCommandCenter\Operations\WorkflowRegistry;
use WPCommandCenter\Operations\CommentsRuntimeManager;
use WPCommandCenter\Operations\WidgetsRegistry;
use WPCommandCenter\Operations\WidgetsRuntimeManager;
use WPCommandCenter\Operations\CPTRegistry;
use WPCommandCenter\Operations\CPTRuntimeManager;

defined( 'ABSPATH' ) || exit;

final class RestApi {

	private const NAMESPACE = 'wp-command-center/v1';
	private const SESSION_STATUS_ACTIVE  = 'active';
	private const SESSION_STATUS_CLOSED  = 'closed';
	private const SESSION_STATUS_EXPIRED = 'expired';
	private const SESSION_SOURCES        = [ 'claude', 'codex', 'gpt', 'api', 'manual' ];
	private const TASK_STATUS_DRAFT = 'draft';
	private const TASK_STATUSES     = [ 'draft', 'analyzing', 'patch_proposed', 'completed', 'failed', 'cancelled' ];
	private const PLAN_STATUS_DRAFT          = 'draft';
	private const PLAN_STATUS_PENDING_REVIEW = 'pending_review';
	private const PLAN_STATUS_APPROVED       = 'approved';
	private const PLAN_STATUS_REJECTED       = 'rejected';
	private const PLAN_STATUS_SUPERSEDED     = 'superseded';
	private const PLAN_STATUS_CANCELLED      = 'cancelled';
	private const PLAN_STATUSES              = [ 'draft', 'pending_review', 'approved', 'rejected', 'superseded', 'cancelled' ];
	private const PLAN_CREATE_STATUSES       = [ 'draft', 'pending_review' ];
	private const PLAN_APPROVABLE_STATUSES   = [ 'pending_review', 'draft' ];
	private const PLAN_REJECTABLE_STATUSES   = [ 'pending_review', 'draft' ];
	private const PLAN_CANCELLABLE_STATUSES  = [ 'draft', 'pending_review', 'approved' ];
	private const PLAN_STEP_STATUS_PENDING   = 'pending';
	private const PLAN_STEP_STATUSES         = [ 'pending', 'completed', 'skipped' ];
	private const ACTION_TYPES                = [ 'investigate', 'recommendation', 'diagnosis', 'code_change', 'configuration_change', 'maintenance' ];
	private const ACTION_STATUS_PROPOSED      = 'proposed';
	private const ACTION_STATUS_ACCEPTED      = 'accepted';
	private const ACTION_STATUS_REJECTED      = 'rejected';
	private const ACTION_STATUS_COMPLETED     = 'completed';
	private const ACTION_STATUS_CANCELLED     = 'cancelled';
	private const ACTION_STATUSES             = [ 'proposed', 'accepted', 'rejected', 'completed', 'cancelled' ];
	private const ACTION_ACCEPTABLE_STATUSES  = [ 'proposed' ];
	private const ACTION_REJECTABLE_STATUSES  = [ 'proposed' ];
	private const ACTION_CANCELLABLE_STATUSES = [ 'proposed', 'accepted' ];
	private const ACTION_COMPLETABLE_STATUSES = [ 'accepted' ];

	/**
	 * Schema version of the GET /agent/manifest response shape. Bump this
	 * when the manifest's structure changes (new/removed top-level keys),
	 * independent of manifest_hash, which reflects content changes.
	 */
    private const AGENT_MANIFEST_VERSION = '2.1.0';

	/**
	 * High-level feature flags for GET /agent/manifest "capabilities".
	 */
	private const AGENT_CAPABILITIES = [
		'site_intelligence' => true,
		'diagnostics'       => true,
		'file_access'       => true,
		'code_search'       => true,
		'patches'           => true,
		'rollback'          => true,
		'sessions'          => true,
		'tasks'             => true,
		'actions'           => true,
		'plans'             => true,
		'plan_approval'     => true,
		'recommendations'   => true,
		'health_verification' => true,
		'environment_management' => true,
		'cleanup'           => true,
		'wp_cli_operations' => true,
		'option_management' => true,
		'plugin_management' => true,
		'theme_management'  => true,
		'snapshot_management' => true,
		'content_management'  => true,
		'database_inspection' => true,
    'capability_management' => true,
    'mcp_server'           => true,
    'claude_integration'   => true,
    'ai_clients'           => true,
    'widgets_management'   => true,
    'cpt_management'       => true,
];

	/**
	 * Security posture for GET /agent/manifest "security".
	 * `human_approval_required` reflects the active Security Mode (Step 80A).
	 * True in Client and Enterprise modes; false in Developer mode (default).
	 */
	private static function get_agent_security(): array {
		return [
			'human_approval_required' => \WPCommandCenter\Operations\SecurityModeManager::requires_human_approver(),
			'patch_auto_apply'        => false,
			'rollback_supported'      => true,
			'secret_redaction'        => true,
		];
	}

	/**
	 * Ordered agent runtime workflow for GET /agent/manifest "workflow".
	 */
	private const AGENT_WORKFLOW = [
		'session',
		'task',
		'action',
		'plan',
		'plan_approval',
		'patch',
		'patch_approval',
		'apply',
		'rollback',
	];

	/**
	 * Known wpcc_* WP_Error codes and a human-readable description of each,
	 * for GET /agent/manifest "error_catalog". Codes that are returned with
	 * more than one message depending on call site use a single,
	 * generalized description here.
	 */
	private const ERROR_CATALOG = [
		'wpcc_action_create_failed'         => 'Failed to create the agent action.',
		'wpcc_action_not_found'             => 'Agent action not found.',
		'wpcc_action_update_failed'         => 'Failed to update the agent action.',
		'wpcc_binary_file'                  => 'Binary files cannot be previewed.',
		'wpcc_empty_query'                  => 'Please enter a search term.',
		'wpcc_file_blocked'                 => 'Access to this path is blocked for security reasons.',
		'wpcc_file_changed'                 => 'The file has changed on disk since the patch was generated.',
		'wpcc_patch_breaks_header'          => 'The patch would remove or invalidate a plugin (Plugin Name) or theme (Theme Name) bootstrap header, which would deactivate it.',
		'wpcc_file_too_large'               => 'File is too large to patch.',
		'wpcc_insufficient_scope'           => 'This API token is read-only and cannot perform this action.',
		'wpcc_invalid_action'               => 'Invalid action.',
		'wpcc_invalid_action_status'        => "The action's current status does not allow this operation.",
		'wpcc_invalid_action_type'          => 'Invalid action type. Use investigate, recommendation, diagnosis, code_change, configuration_change, or maintenance.',
		'wpcc_invalid_agent_action'         => 'Invalid agent action.',
		'wpcc_invalid_label'                => 'Please enter a label for this token.',
		'wpcc_invalid_media_enhance_action' => 'Invalid media enhance action.',
		'wpcc_media_no_files'               => 'The attachment has no files on disk to operate on.',
		'wpcc_thumbnail_snapshot_failed'    => 'Could not snapshot the attachment before regeneration; the operation was aborted without changes.',
		'wpcc_thumbnail_regenerate_failed'  => 'Thumbnail regeneration failed to produce the expected sizes; the pre-regeneration state was restored.',
		'wpcc_image_lib_unavailable'        => 'The required image library capability (e.g. WebP encoding via GD/Imagick) is unavailable on this server.',
		'wpcc_webp_unsupported_mime'        => 'WebP cannot be generated from this attachment\'s mime type (JPEG/PNG only).',
		'wpcc_webp_snapshot_failed'         => 'Could not snapshot the attachment before WebP generation; the operation was aborted without changes.',
		'wpcc_webp_generate_failed'         => 'No WebP files could be generated; the pre-generation state was restored.',
		'wpcc_optimize_unsupported_mime'    => 'Image optimization does not support this mime type (JPEG/PNG/WebP only).',
		'wpcc_optimize_snapshot_failed'     => 'Could not snapshot the attachment before optimization; the operation was aborted without changes.',
		'wpcc_optimize_failed'              => 'No files could be optimized; the pre-optimization state was restored.',
		'wpcc_media_cleanup_refused'        => 'Cleanup refused: the attachment is still referenced or is in a protected category (WooCommerce/theme/draft/revision/protected). Nothing was changed.',
		'wpcc_media_cleanup_snapshot_failed' => 'Could not snapshot the attachment before cleanup; the operation was aborted without changes.',
		'wpcc_media_cleanup_failed'         => 'Could not trash the attachment; the pre-cleanup state was restored.',
		'wpcc_invalid_path'                 => 'The supplied path is missing or invalid.',
		'wpcc_invalid_plan'                 => 'Plan title and objective are required.',
		'wpcc_invalid_plan_action'          => 'Invalid plan action.',
		'wpcc_invalid_plan_status'          => "The plan's current status does not allow this action.",
		'wpcc_invalid_plan_step'            => 'Each plan step needs a title and a valid status.',
		'wpcc_invalid_plan_steps'           => 'A plan must include at least one step.',
		'wpcc_invalid_risk_level'           => 'Invalid risk level.',
		'wpcc_invalid_scope'                => 'Invalid token scope.',
		'wpcc_invalid_session_expiry'       => 'The session expiry must be a future Unix timestamp.',
		'wpcc_invalid_session_source'       => 'Invalid session source. Use claude, codex, gpt, api, or manual.',
		'wpcc_invalid_session_status'       => 'Only active agent sessions can be closed.',
		'wpcc_invalid_source'               => 'Invalid patch source.',
		'wpcc_invalid_status'               => "The patch's current status does not allow this action.",
		'wpcc_invalid_task_source'          => 'Invalid task source. Use claude, codex, gpt, api, or manual.',
		'wpcc_invalid_task_status'          => 'Invalid task status.',
		'wpcc_invalid_token'                => 'Invalid API token.',
		'wpcc_invalid_type'                 => 'Invalid type parameter for this endpoint.',
		'wpcc_invalid_table'                => 'The specified table does not exist.',
		'wpcc_invalid_table_prefix'         => 'Table does not start with the required WordPress prefix.',
		'wpcc_invalid_url'                  => 'Invalid or unsafe source URL.',
		'wpcc_invalid_url_scheme'           => 'Only HTTP and HTTPS URLs are supported.',
		'wpcc_invalid_mime_type'            => 'Invalid or unsafe file content detected.',
		'wpcc_is_directory'                 => 'The requested path is a directory.',
		'wpcc_missing_action_title'         => 'The action title is required.',
		'wpcc_missing_path'                 => 'The path parameter is required.',
		'wpcc_missing_product_name'         => 'Product name is required.',
		'wpcc_missing_session_label'        => 'The session label is required.',
		'wpcc_missing_token'                => 'Missing API token. Provide an "Authorization: Bearer <token>" header.',
		'wpcc_missing_user_prompt'          => 'The user prompt is required.',
		'wpcc_mkdir_failed'                 => 'Failed to create a required storage directory.',
		'wpcc_no_fields_supplied'           => 'No fields supplied for seeding.',
		'wpcc_no_changes'                   => 'The patch does not change any file.',
		'wpcc_no_debug_log'                 => 'No debug.log file was found.',
		'wpcc_no_files'                     => 'A patch must include at least one file.',
		'wpcc_no_snapshots'                 => 'No snapshots are available for this patch.',
		'wpcc_no_tables_selected'           => 'No tables selected for search and replace.',
		'wpcc_not_a_directory'              => 'The requested path is not a directory.',
		'wpcc_not_found'                    => 'The requested path does not exist.',
		'wpcc_not_readable'                 => 'File not found or not readable.',
		'wpcc_not_writable'                 => 'The target file is not writable.',
		'wpcc_open_failed'                  => 'Failed to open debug.log.',
		'wpcc_operation_not_found'          => 'Operation not found.',
		'wpcc_patch_corrupt'                => 'Patch record could not be read.',
		'wpcc_patch_not_found'              => 'Patch not found.',
		'wpcc_unknown_patch_field'          => 'A supplied field is not recognized for this patch mode/operation. Check the schema; file contents belong inside files[].',
		'wpcc_missing_patch_field'          => 'A field required by the chosen patch mode is missing.',
		'wpcc_missing_patch_mode'           => "The patch mode could not be determined. Set files[].mode, or provide 'modified' for a whole-file replacement.",
		'wpcc_invalid_patch_mode'           => 'Invalid patch mode. Use whole_file, append, prepend, replace_text, replace_range, or unified_diff.',
		'wpcc_invalid_patch_field'          => 'A supplied patch field has an invalid value.',
		'wpcc_patch_text_not_found'         => 'The replace_text "find" string was not found in the file.',
		'wpcc_patch_range_invalid'          => 'The replace_range line range is invalid for this file (1-based, inclusive).',
		'wpcc_patch_diff_failed'            => 'The unified diff could not be applied cleanly to the current file.',
		'wpcc_invalid_patch_action'         => 'Invalid patch action. Use patch_preview, patch_create, patch_apply, patch_verify, or patch_status.',
		'wpcc_path_not_allowed'             => 'This path is outside the allowed directories (themes, plugins, mu-plugins).',
		'wpcc_plan_create_failed'           => 'Failed to create the agent plan.',
		'wpcc_plan_not_approved'            => 'Only an approved plan can be linked to a patch.',
		'wpcc_plan_not_found'               => 'Agent plan not found.',
		'wpcc_plan_step_create_failed'      => 'Failed to create an agent plan step.',
		'wpcc_plan_update_failed'           => 'Failed to update the agent plan.',
		'wpcc_read_failed'                  => 'Failed to read the file.',
		'wpcc_recommendation_create_failed' => 'Failed to create the recommendation.',
		'wpcc_recommendation_not_found'     => 'Recommendation not found.',
		'wpcc_recommendation_scan_failed'   => 'Recommendation scan failed.',
		'wpcc_recommendation_update_failed' => 'Failed to update the recommendation.',
		'wpcc_invalid_recommendation_status' => "The recommendation's current status does not allow this operation.",
		'wpcc_invalid_recommendation_severity' => 'Invalid recommendation severity.',
		'wpcc_request_create_failed'        => 'Failed to create the operation request.',
		'wpcc_request_not_approved'         => 'Only approved requests can be executed.',
		'wpcc_request_not_found'            => 'Operation request not found.',
		'wpcc_result_not_found'             => 'Operation result not found.',
		'wpcc_restore_failed'               => 'Failed to write the restored file.',
		'wpcc_rollback_verification_failed' => 'One or more files could not be verified after rollback. The files have been restored, but the patch status was not changed.',
		'wpcc_search_equals_replace'        => 'Search and replace strings cannot be identical.',
		'wpcc_empty_search'                 => 'Search string cannot be empty.',
		'wpcc_fatal_error'                  => 'Site returned a fatal error after update.',
		'wpcc_health_check_failed'          => 'Update succeeded, but health check failed. Rollback recommended.',
		'wpcc_health_verification_failed'   => 'The read-only health verification could not complete.',
		'wpcc_invalid_environment_mode'     => 'Environment mode must be development, staging, or production.',
		'wpcc_invalid_cleanup_resources'    => 'No valid cleanup resources were selected.',
		'wpcc_cleanup_confirmation_required'=> 'Live cleanup requires an explicit confirmation phrase.',
		'wpcc_production_cleanup_blocked'   => 'Production cleanup requires an explicit override and confirmation phrase.',
		'wpcc_invalid_update_type'          => 'Invalid update type. Supported: plugin, theme.',
		'wpcc_loopback_failed'              => 'Loopback check failed.',
		'wpcc_missing_slug'                 => 'Plugin or theme slug is required.',
		'wpcc_no_update_available'          => 'No update available for this plugin or theme.',
		'wpcc_plugin_not_found'             => 'Plugin not found.',
		'wpcc_theme_not_found'              => 'Theme not found.',
		'wpcc_update_failed'                => 'Plugin or theme update failed.',
		'wpcc_session_close_failed'         => 'Failed to close the agent session.',
		'wpcc_session_create_failed'        => 'Failed to create the agent session.',
		'wpcc_session_not_found'            => 'Agent session not found.',
		'wpcc_snapshot_missing'             => 'Snapshot file is missing on disk.',
		'wpcc_snapshot_not_found'           => 'Snapshot not found.',
		'wpcc_task_create_failed'           => 'Failed to create the agent task.',
		'wpcc_task_not_found'               => 'Agent task not found.',
		'wpcc_task_session_mismatch'        => 'The agent task does not belong to the supplied session.',
		'wpcc_task_update_failed'           => 'Failed to update the agent task.',
		'wpcc_token_expired'                => 'This API token has expired.',
		'wpcc_token_not_found'              => 'Token not found.',
		'wpcc_token_revoked'                => 'This API token has been revoked.',
		'wpcc_unreadable_debug_log'         => 'debug.log exists but is not readable.',
		'wpcc_unwritable_debug_log'         => 'debug.log exists but is not writable.',
		'wpcc_upload_dir_error'             => 'The WordPress uploads directory is not configured correctly.',
		'wpcc_unknown_acf_field'            => 'The specified ACF field was not found.',
		'wpcc_unsupported_acf_field_type'   => 'The ACF field type is not supported for seeding.',
		'wpcc_woo_inactive'                 => 'WooCommerce is not active.',
		'wpcc_woo_save_failed'              => 'Failed to save WooCommerce product.',
		'wpcc_invalid_product_price'        => 'Invalid regular price.',
		'wpcc_invalid_product_sale_price'   => 'Invalid sale price.',
		'wpcc_invalid_product_status'       => 'Invalid product status. Supported: draft, publish.',
		'wpcc_duplicate_sku'                => 'Product with this SKU already exists.',
		'wpcc_wp_cli_unavailable'           => 'WP-CLI bridge is not available on this server.',
		'wpcc_missing_wpcli_command'        => 'WP-CLI command_id is required.',
		'wpcc_invalid_wpcli_args'           => 'WP-CLI args must be an object.',
		'wpcc_invalid_wpcli_arg'            => 'Unknown WP-CLI argument supplied.',
		'wpcc_missing_wpcli_arg'            => 'Missing required WP-CLI argument.',
		'wpcc_invalid_wpcli_arg_value'      => 'Invalid value for WP-CLI argument.',
		'wpcc_invalid_wpcli_arg_pattern'    => 'WP-CLI argument does not match the required format.',
		'wpcc_wpcli_arg_too_long'           => 'WP-CLI argument value exceeds maximum length.',
		'wpcc_unsafe_wpcli_arg'             => 'WP-CLI argument contains unsafe shell characters.',
		'wpcc_wpcli_blocked'                => 'This WP-CLI command is permanently blocked for security.',
		'wpcc_wpcli_unavailable_cmd'        => 'This WP-CLI command is not available in the current environment.',
		'wpcc_write_failed'                 => 'Failed to store the snapshot.',
		'wpcc_missing_option_id'            => 'option_id is required.',
		'wpcc_invalid_option_id'            => 'Unknown option ID.',
		'wpcc_invalid_option_type'          => 'Option value type does not match the expected type.',
		'wpcc_invalid_option_value'         => 'Invalid value for this option.',
		'wpcc_invalid_option_action'        => 'Invalid action. Use option_get, option_update, or option_rollback.',
		'wpcc_option_value_too_short'       => 'Option value is too short.',
		'wpcc_option_value_too_long'        => 'Option value is too long.',
		'wpcc_option_value_too_small'       => 'Option value is below the minimum.',
		'wpcc_option_value_too_large'       => 'Option value exceeds the maximum.',
		'wpcc_invalid_timezone'             => 'Invalid timezone identifier.',
		'wpcc_invalid_email'                => 'Invalid email address.',
		'wpcc_invalid_page_id'              => 'The specified page does not exist or is not published.',
		'wpcc_option_update_failed'         => 'Failed to update the option.',
		'wpcc_missing_rollback_id'          => 'rollback_id is required for rollback.',
		'wpcc_rollback_not_found'           => 'Rollback record not found.',
		'wpcc_rollback_already_applied'     => 'Rollback has already been applied.',
		'wpcc_rollback_failed'              => 'Failed to restore the previous option value.',
		'wpcc_missing_plugin_slug'          => 'Plugin slug is required.',
		'wpcc_invalid_plugin_slug'          => 'Invalid plugin slug format.',
		'wpcc_invalid_plugin_action'        => 'Invalid plugin action.',
		'wpcc_plugin_not_found'             => 'Plugin not found.',
		'wpcc_plugin_already_installed'     => 'Plugin is already installed.',
		'wpcc_plugin_already_active'        => 'Plugin is already active.',
		'wpcc_plugin_already_inactive'      => 'Plugin is already inactive.',
		'wpcc_plugin_no_update'             => 'No update available for this plugin.',
		'wpcc_plugin_delete_active'         => 'Cannot delete an active plugin. Deactivate first.',
		'wpcc_plugin_api_error'             => 'Could not retrieve plugin information from WordPress.org.',
		'wpcc_plugin_install_failed'        => 'Plugin installation failed.',
		'wpcc_plugin_activate_failed'       => 'Plugin activation failed.',
		'wpcc_plugin_update_failed'         => 'Plugin update failed.',
		'wpcc_plugin_delete_failed'         => 'Plugin deletion failed.',
		'wpcc_missing_theme_slug'           => 'Theme slug is required.',
		'wpcc_invalid_theme_slug'           => 'Invalid theme slug format.',
		'wpcc_invalid_theme_action'         => 'Invalid theme action.',
		'wpcc_theme_not_found'              => 'Theme not found.',
		'wpcc_theme_already_installed'      => 'Theme is already installed.',
		'wpcc_theme_already_active'         => 'Theme is already active.',
		'wpcc_theme_no_update'              => 'No update available for this theme.',
		'wpcc_theme_delete_active'          => 'Cannot delete the active theme.',
		'wpcc_theme_api_error'              => 'Could not retrieve theme information from WordPress.org.',
		'wpcc_theme_install_failed'         => 'Theme installation failed.',
		'wpcc_theme_update_failed'          => 'Theme update failed.',
		'wpcc_theme_delete_failed'          => 'Theme deletion failed.',
		'wpcc_invalid_snapshot_action'      => 'Invalid snapshot action.',
		'wpcc_missing_snapshot_path'        => 'File path is required for snapshot creation.',
		'wpcc_missing_snapshot_id'          => 'Snapshot ID is required.',
		'wpcc_invalid_content_action'       => 'Invalid content action.',
		'wpcc_missing_content_id'           => 'Content ID is required.',
		'wpcc_content_not_found'            => 'Content not found.',
		'wpcc_missing_content_title'        => 'Title is required.',
		'wpcc_invalid_content_type'         => 'Invalid content type.',
		'wpcc_content_delete_failed'        => 'Failed to trash content.',
		'wpcc_missing_schedule_time'        => 'publish_at is required.',
		'wpcc_invalid_schedule_time'        => 'publish_at must be a future date/time.',
		'wpcc_missing_taxonomy_terms'       => 'Terms array is required.',
		'wpcc_invalid_taxonomy'             => 'Taxonomy does not exist.',
		'wpcc_missing_attachment_id'        => 'attachment_id is required.',
		'wpcc_invalid_attachment'           => 'Attachment not found.',
		'wpcc_not_an_image'                 => 'Attachment is not an image.',
		'wpcc_invalid_db_action'            => 'Invalid database inspection action.',
		'wpcc_invalid_db_table'             => 'Table not in the allowed core table list.',
		'wpcc_db_write_blocked'             => 'Write keywords are not allowed in database inspection.',
		'wpcc_missing_db_table'             => 'Table name is required.',
		'wpcc_db_table_not_found'           => 'Table not found.',
		'wpcc_invalid_capability_action'    => 'Invalid capability action.',
		'wpcc_missing_subject_id'           => 'Subject ID is required.',
		'wpcc_missing_capability'           => 'Capability name is required.',
		'wpcc_invalid_capability'           => 'Unknown capability.',
		'wpcc_cannot_assign_admin'          => 'system.admin can only be assigned via direct configuration.',
		'wpcc_capability_not_assigned'      => 'No capabilities assigned to this subject.',
		'wpcc_missing_operation'            => 'Operation name is required.',
		'wpcc_capability_denied'            => 'Operation denied due to missing capability.',
		'wpcc_approval_required'            => 'Operation requires approval via the request workflow.',
		'wpcc_operation_timeout'            => 'Operation exceeded the synchronous execution budget; queue long-running work instead.',
	];

	/**
	 * Machine-readable description of every route, for AI agent discovery
	 * via GET /manifest.
	 */
	private const ROUTE_MANIFEST = [
		[ 'method' => 'GET', 'path' => '/health', 'scope' => 'read_only', 'description' => 'Health check for the API gateway.' ],
		[ 'method' => 'POST', 'path' => '/health/verify', 'scope' => 'full', 'description' => 'Run read-only frontend, admin, REST, WPCC, WooCommerce, plugin, and theme health checks.' ],
		[ 'method' => 'GET', 'path' => '/health/results', 'scope' => 'read_only', 'description' => 'List persisted health verification results. Filters: status, limit, offset.' ],
		[ 'method' => 'GET', 'path' => '/system/environment', 'scope' => 'read_only', 'description' => 'Get the current WP Command Center environment mode.' ],
		[ 'method' => 'POST', 'path' => '/system/environment', 'scope' => 'full', 'description' => 'Set environment mode: development, staging, or production.' ],
		[ 'method' => 'POST', 'path' => '/system/cleanup', 'scope' => 'full', 'description' => 'Dry-run or delete age-qualified terminal runtime records with environment-aware safeguards.' ],
		[ 'method' => 'GET', 'path' => '/capabilities', 'scope' => 'read_only', 'description' => 'Capabilities of this API and the current token (file/patch/rollback access, server execution features).' ],
		[ 'method' => 'GET', 'path' => '/manifest', 'scope' => 'read_only', 'description' => 'Machine-readable description of this API for agent discovery.' ],
		[ 'method' => 'GET', 'path' => '/agent/manifest', 'scope' => 'read_only', 'description' => 'Agent discovery manifest: capabilities, security posture, workflow, endpoint catalog, error catalog, capability negotiation, and version info. Read-only; no file contents, secrets, tokens, or customer data.' ],
		[ 'method' => 'GET', 'path' => '/context', 'scope' => 'read_only', 'description' => 'Composite context bundle: site info, diagnostics, server capabilities, and file access map. Secrets are redacted as [REDACTED_SECRET].' ],
		[ 'method' => 'GET', 'path' => '/agent/context', 'scope' => 'read_only', 'description' => 'Metadata-only agent runtime context. Options: session_id, include_files=false, include_diagnostics=true. Secrets are redacted as [REDACTED_SECRET].' ],
		[ 'method' => 'GET', 'path' => '/agent/timeline', 'scope' => 'read_only', 'description' => 'Unified traceable timeline of the agent lifecycle. Supports filters: session_id, task_id, action_id, plan_id, patch_id, limit, offset.' ],
		[ 'method' => 'GET', 'path' => '/agent/tree', 'scope' => 'read_only', 'description' => 'Hierarchical agent runtime tree (Session -> Task -> Action -> Plan -> Patch). Supports filters: session_id, task_id, plan_id.' ],
		[ 'method' => 'GET', 'path' => '/site-intelligence', 'scope' => 'read_only', 'description' => 'WordPress, PHP, theme, plugin, cache, and server information.' ],
		[ 'method' => 'GET', 'path' => '/diagnostics', 'scope' => 'read_only', 'description' => 'Performance, security, or WooCommerce diagnostics. Use ?type=performance|security|woocommerce.' ],
		[ 'method' => 'GET', 'path' => '/diagnostics/debug-log', 'scope' => 'read_only', 'description' => 'Tail of wp-content/debug.log. Use ?lines=N. Secrets in log lines are redacted as [REDACTED_SECRET].' ],
		[ 'method' => 'GET', 'path' => '/recommendations', 'scope' => 'read_only', 'description' => 'List deterministic recommendations. Filters: type, severity, status, source, limit, offset.' ],
		[ 'method' => 'GET', 'path' => '/recommendations/{id}', 'scope' => 'read_only', 'description' => 'Get a recommendation by UUID.' ],
		[ 'method' => 'POST', 'path' => '/recommendations/scan', 'scope' => 'full', 'description' => 'Run a deterministic recommendation scan. Does not patch content or execute operations.' ],
		[ 'method' => 'POST', 'path' => '/recommendations/{id}/dismiss', 'scope' => 'full', 'description' => 'Dismiss an open recommendation.' ],
		[ 'method' => 'POST', 'path' => '/recommendations/{id}/resolve', 'scope' => 'full', 'description' => 'Resolve an open recommendation.' ],
		[ 'method' => 'POST', 'path' => '/recommendations/{id}/convert-to-action', 'scope' => 'full', 'description' => 'Convert an open recommendation to a proposed action: { session_id, task_id }.' ],
		[ 'method' => 'POST', 'path' => '/recommendations/{id}/create-plan', 'scope' => 'full', 'description' => 'Create a pending-review plan for a recommendation that has been converted to an action.' ],
		[ 'method' => 'GET', 'path' => '/files', 'scope' => 'read_only', 'description' => 'List files and directories under themes, plugins, or mu-plugins. Use ?path=. Blocked paths (.env, vendor/, .git/, keys, etc.) are omitted.' ],
		[ 'method' => 'GET', 'path' => '/files/meta', 'scope' => 'read_only', 'description' => 'Metadata (size, modified, hash, writable) for a single file. Use ?path=. Blocked paths return wpcc_file_blocked.' ],
		[ 'method' => 'GET', 'path' => '/files/content', 'scope' => 'read_only', 'description' => 'Read a file\'s contents (capped at 1 MB). Use ?path=. Blocked paths return wpcc_file_blocked; secrets are redacted as [REDACTED_SECRET].' ],
		[ 'method' => 'GET', 'path' => '/search', 'scope' => 'read_only', 'description' => 'Search code by text, function, class, or hook name. Use ?q=&path=&type=text|function|class|hook. Blocked files are skipped and secrets in matches are redacted as [REDACTED_SECRET].' ],
		[ 'method' => 'POST', 'path' => '/agent/sessions', 'scope' => 'full', 'description' => 'Create an agent session: { source, label, expires_at? }. Sessions expire after 24 hours by default.' ],
		[ 'method' => 'GET', 'path' => '/agent/sessions', 'scope' => 'read_only', 'description' => 'List agent sessions, newest first.' ],
		[ 'method' => 'GET', 'path' => '/agent/sessions/{id}', 'scope' => 'read_only', 'description' => 'Get an agent session by UUID.' ],
		[ 'method' => 'POST', 'path' => '/agent/sessions/{id}/close', 'scope' => 'full', 'description' => 'Close an active agent session.' ],
		[ 'method' => 'POST', 'path' => '/agent/tasks', 'scope' => 'full', 'description' => 'Create an agent task under an existing session: { session_id, source, user_prompt }.' ],
		[ 'method' => 'GET', 'path' => '/agent/tasks', 'scope' => 'read_only', 'description' => 'List agent tasks, newest first.' ],
		[ 'method' => 'GET', 'path' => '/agent/tasks/{id}', 'scope' => 'read_only', 'description' => 'Get an agent task by UUID.' ],
		[ 'method' => 'POST', 'path' => '/agent/tasks/{id}/status', 'scope' => 'full', 'description' => 'Update an agent task status: { status }.' ],
		[ 'method' => 'POST', 'path' => '/agent/actions', 'scope' => 'full', 'description' => 'Record an agent action under an existing session and task: { session_id, task_id, type, title, description? }. type must be one of investigate, recommendation, diagnosis, code_change, configuration_change, or maintenance. Always created with status=proposed. Metadata only; does not execute or create patches.' ],
		[ 'method' => 'GET', 'path' => '/agent/actions', 'scope' => 'read_only', 'description' => 'List agent actions, newest first.' ],
		[ 'method' => 'GET', 'path' => '/agent/actions/{id}', 'scope' => 'read_only', 'description' => 'Get an agent action by UUID.' ],
		[ 'method' => 'POST', 'path' => '/agent/actions/{id}/accept', 'scope' => 'full', 'description' => 'Accept a proposed action.' ],
		[ 'method' => 'POST', 'path' => '/agent/actions/{id}/reject', 'scope' => 'full', 'description' => 'Reject a proposed action.' ],
		[ 'method' => 'POST', 'path' => '/agent/actions/{id}/cancel', 'scope' => 'full', 'description' => 'Cancel a proposed or accepted action.' ],
		[ 'method' => 'POST', 'path' => '/agent/actions/{id}/complete', 'scope' => 'full', 'description' => 'Mark an accepted action as completed.' ],
		[ 'method' => 'POST', 'path' => '/agent/plans', 'scope' => 'full', 'description' => 'Create a plan under an existing session and task: { session_id, task_id, title, objective, status?, steps, action_id? }. status defaults to pending_review and may be draft or pending_review. action_id optionally links the plan to an existing agent action.' ],
		[ 'method' => 'GET', 'path' => '/agent/plans', 'scope' => 'read_only', 'description' => 'List agent plans with ordered steps, newest first.' ],
		[ 'method' => 'GET', 'path' => '/agent/plans/{id}', 'scope' => 'read_only', 'description' => 'Get an agent plan and its ordered steps by UUID.' ],
		[ 'method' => 'POST', 'path' => '/agent/plans/{id}/approve', 'scope' => 'full', 'description' => 'Approve a pending_review or draft plan.' ],
		[ 'method' => 'POST', 'path' => '/agent/plans/{id}/reject', 'scope' => 'full', 'description' => 'Reject a pending_review or draft plan.' ],
		[ 'method' => 'POST', 'path' => '/agent/plans/{id}/cancel', 'scope' => 'full', 'description' => 'Cancel a draft, pending_review, or approved plan.' ],
		[ 'method' => 'GET', 'path' => '/patches', 'scope' => 'read_only', 'description' => 'List all patches (summary records).' ],
		[ 'method' => 'POST', 'path' => '/patches', 'scope' => 'full', 'description' => 'Create a patch proposal: { files, explanation, risk_level, source, session_id?, task_id?, plan_id? }. If plan_id is supplied, the plan must exist and be approved.' ],
		[ 'method' => 'GET', 'path' => '/patches/{id}', 'scope' => 'read_only', 'description' => 'Get a single patch record, including diff and status history.' ],
		[ 'method' => 'POST', 'path' => '/patches/{id}/approve', 'scope' => 'full', 'description' => 'Approve a pending patch.' ],
		[ 'method' => 'POST', 'path' => '/patches/{id}/reject', 'scope' => 'full', 'description' => 'Reject a pending or approved patch.' ],
		[ 'method' => 'POST', 'path' => '/patches/{id}/apply', 'scope' => 'full', 'description' => 'Apply an approved patch (auto-snapshots affected files, verifies, auto-reverts on failure).' ],
		[ 'method' => 'POST', 'path' => '/patches/{id}/rollback', 'scope' => 'full', 'description' => 'Roll back an applied patch using its snapshot(s), with hash verification.' ],
		[ 'method' => 'GET', 'path' => '/operations', 'scope' => 'read_only', 'description' => 'List all supported WordPress operations (metadata only).' ],
		[ 'method' => 'GET', 'path' => '/operations/{id}', 'scope' => 'read_only', 'description' => 'Get detailed metadata for a specific operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/content_seed/run', 'scope' => 'full', 'description' => 'Execute the content seeding operation: { type, count, status, title_pattern?, content_template? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/acf_seed/run', 'scope' => 'full', 'description' => 'Populate ACF fields on a post: { post_id, fields: { name: value } }.' ],
		[ 'method' => 'POST', 'path' => '/operations/cf7_seed/run', 'scope' => 'full', 'description' => 'Generate a Contact Form 7 form: { title, form_template }.' ],
		[ 'method' => 'POST', 'path' => '/operations/woo_product_seed/run', 'scope' => 'full', 'description' => 'Create a simple WooCommerce product: { name, sku?, regular_price, sale_price?, status?, stock_quantity?, manage_stock?, categories? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/safe_search_replace/run', 'scope' => 'full', 'description' => 'Perform a safe database search and replace: { search, replace, dry_run?, tables, case_sensitive? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/media_import/run', 'scope' => 'full', 'description' => 'Import a remote image to the Media Library: { source_url, title?, alt?, caption?, description?, attach_to_post_id? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/safe_updates/run', 'scope' => 'full', 'description' => 'Safely update a plugin or theme with health verification: { type: plugin|theme, slug, dry_run? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/capability_manage/run', 'scope' => 'full', 'description' => 'Manage platform capabilities: { action: capability_list|capability_get|capability_assign|capability_remove|capability_validate, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/database_inspect/run', 'scope' => 'read_only', 'description' => 'Read-only database health and structure inspection. No INSERT/UPDATE/DELETE/DROP. No arbitrary SQL.' ],
		[ 'method' => 'POST', 'path' => '/operations/report_manage/run', 'scope' => 'read_only', 'description' => 'Read-only operational reports: { action: report_list|report_site_health|report_plugin_health|report_security|report_content|report_woocommerce|report_agent_activity|report_approval_activity|report_patch_activity, limit? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/media_enhance/run', 'scope' => 'read_only', 'description' => 'Media-enhancement runtime. Read diagnostics (read token): { action: media_enhance_capabilities|image_sizes_list|image_size_usage_audit|image_size_recommendations|image_size_verify|srcset_verify|responsive_image_audit|missing_sizes_audit|image_size_context_audit|thumbnail_verify, media_id?, limit? }. Reversible regeneration / WebP / optimization (full token): { action: thumbnail_regenerate|thumbnail_regenerate_attachment|thumbnail_regenerate_batch|webp_generate|webp_generate_batch|image_optimize|image_optimize_batch, media_id?, mode?, quality?, media_ids?, cursor?, limit? }. Guarded reversible cleanup (full token, DestructiveGuard CLEANUP_MEDIA — trash only, never permanent): { action: unused_media_cleanup, media_id, confirm, confirmation_phrase, reason }. WebP/optimization audits + usage analysis (read token): { action: webp_audit|webp_verify|image_optimize_audit|image_optimize_verify|media_usage_scan|media_usage_report|unused_media_find|orphaned_media_find, media_id?, limit? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/media_enhance/rollback', 'scope' => 'full', 'description' => 'Reverse a thumbnail regeneration (delete created files + restore the pre-regeneration snapshot byte-for-byte): { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/content_manage/run', 'scope' => 'full', 'description' => 'Safely inspect and manage WordPress content: { action: content_list|content_get|content_create|content_update|content_delete|content_publish|content_unpublish|content_schedule|taxonomy_assign|featured_image_assign, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/snapshot_manage/run', 'scope' => 'full', 'description' => 'Create, list, inspect, verify, and restore file snapshots: { action: snapshot_create|snapshot_list|snapshot_details|snapshot_restore|snapshot_verify, path?, label?, snapshot_id? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/file_manage/run', 'scope' => 'read_only', 'description' => 'Read files and browse the file tree (shared service with MCP file_manage): { action: file_read|file_tree|file_metadata, path? }. Blocked paths denied; secrets redacted.' ],
		[ 'method' => 'POST', 'path' => '/operations/code_search/run', 'scope' => 'read_only', 'description' => 'Search code (shared service with MCP code_search): { action: search_text|search_symbol|search_file, query, path?, max_results? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/patch_manage/run', 'scope' => 'full', 'description' => 'Patch Engine (shared service with MCP patch_manage): { action: patch_preview|patch_create|patch_apply|patch_verify|patch_status, files?, patch_id?, explanation?, risk_level?, source?, confirm?, confirmation_phrase?, reason? }. Each files[] entry picks a mode so small edits need not resend the whole file: whole_file { path, modified }, append/prepend { path, mode, content }, replace_text { path, mode, find, replace, count? }, replace_range { path, mode, start_line, end_line, content }, unified_diff { path, mode, diff }. Omitting mode defaults to whole_file (requires modified); unknown fields are rejected. Apply snapshots + verifies syntax + auto-reverts.' ],
		[ 'method' => 'POST', 'path' => '/operations/rollback_manage/run', 'scope' => 'full', 'description' => 'Rollback Engine (shared service with MCP rollback_manage): { action: rollback_list|rollback_get|rollback_apply|rollback_verify, patch_id? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/seo_manage/run', 'scope' => 'full', 'description' => 'Unified SEO runtime (Rank Math/Yoast): { action: seo_get|seo_update|seo_validate|seo_analyze|seo_restore, content_id?, seo?, rollback_id? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/site_builder_manage/run', 'scope' => 'full', 'description' => 'Site builder: { action: page_create|page_update|page_delete|page_get|page_list|template_assign|template_list|pattern_create|navigation_manage|menu_create|menu_update|menu_assign, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/site_builder_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a site builder operation: { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/elementor_manage/run', 'scope' => 'full', 'description' => 'Elementor: { action: elementor_get_page|elementor_export_structure|elementor_list_widgets|elementor_update_text|elementor_update_image|elementor_update_button, page_id, widget_id, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/elementor_manage/rollback', 'scope' => 'full', 'description' => 'Roll back an Elementor widget edit: { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/theme_manage/run', 'scope' => 'full', 'description' => 'Safely inspect and manage WordPress themes: { action: theme_list|theme_install|theme_activate|theme_update|theme_delete, slug? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/plugin_manage/run', 'scope' => 'full', 'description' => 'Safely inspect and manage WordPress plugins: { action: plugin_list|plugin_install|plugin_activate|plugin_deactivate|plugin_update|plugin_delete, slug? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/option_manage/run', 'scope' => 'full', 'description' => 'Safely inspect or update a registered WordPress option: { action: option_get|option_update|option_rollback, option_id, value?, rollback_id? }.' ],
		[ 'method' => 'POST', 'path' => '/operations/wp_cli_bridge/run', 'scope' => 'full', 'description' => 'Run a structured WP-CLI command: { command_id, args } or legacy { command }.' ],
		[ 'method' => 'POST', 'path' => '/operations/requests', 'scope' => 'full', 'description' => 'Create an operation request for human review: { operation_id, payload, session_id?, task_id?, action_id?, plan_id? }.' ],
		[ 'method' => 'GET', 'path' => '/operations/requests', 'scope' => 'read_only', 'description' => 'List operation requests. Filters: status, operation_id, session_id, task_id, plan_id, limit, offset.' ],
		[ 'method' => 'GET', 'path' => '/operations/requests/{id}', 'scope' => 'read_only', 'description' => 'Get detailed metadata for a specific operation request.' ],
		[ 'method' => 'POST', 'path' => '/operations/requests/{id}/approve', 'scope' => 'full', 'description' => 'Approve a pending operation request.' ],
		[ 'method' => 'POST', 'path' => '/operations/requests/{id}/reject', 'scope' => 'full', 'description' => 'Reject a pending operation request.' ],
		[ 'method' => 'POST', 'path' => '/operations/requests/{id}/execute', 'scope' => 'full', 'description' => 'Execute an approved operation request.' ],
		[ 'method' => 'POST', 'path' => '/operations/requests/{id}/queue', 'scope' => 'full', 'description' => 'Queue an approved operation request for later execution.' ],
		[ 'method' => 'GET', 'path' => '/operations/queue', 'scope' => 'read_only', 'description' => 'List queued operations. Filters: status, operation_id, request_id, limit, offset.' ],
		[ 'method' => 'GET', 'path' => '/operations/queue/{id}', 'scope' => 'read_only', 'description' => 'Get detailed metadata for a specific queue item.' ],
		[ 'method' => 'POST', 'path' => '/operations/queue/{id}/run', 'scope' => 'full', 'description' => 'Manually execute a queued operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/queue/{id}/cancel', 'scope' => 'full', 'description' => 'Cancel a queued operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/queue/{id}/retry', 'scope' => 'full', 'description' => 'Retry a failed queued operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/queue/process', 'scope' => 'full', 'description' => 'Manually trigger the background worker to process pending queue items. Payload: { limit?: int }.' ],
		[ 'method' => 'GET', 'path' => '/operations/results', 'scope' => 'read_only', 'description' => 'List operation execution results. Filters: operation_id, queue_id, request_id, status, limit, offset.' ],
		[ 'method' => 'GET', 'path' => '/operations/results/{id}', 'scope' => 'read_only', 'description' => 'Get detailed execution history for a specific operation result.' ],
		[ 'method' => 'GET', 'path' => '/claude/config', 'scope' => 'read_only', 'description' => 'Generate a dynamic Claude Desktop MCP configuration block.' ],
		[ 'method' => 'GET', 'path' => '/claude/discovery', 'scope' => 'read_only', 'description' => 'Claude discovery metadata: server info, tools, resources, capabilities, approval awareness, and WP-CLI status.' ],
		[ 'method' => 'GET', 'path' => '/claude/tools', 'scope' => 'read_only', 'description' => 'Claude-friendly tool grouping with approval and capability metadata per tool.' ],
		[ 'method' => 'GET', 'path' => '/claude/prompts', 'scope' => 'read_only', 'description' => 'Claude-specific helper prompt templates: inspect site, review recommendations, create content, maintenance, database review.' ],
		[ 'method' => 'GET', 'path' => '/ai-clients', 'scope' => 'read_only', 'description' => 'List all registered AI clients with compatibility, status, and configuration support.' ],
		[ 'method' => 'GET', 'path' => '/ai-clients/{client}/config', 'scope' => 'read_only', 'description' => 'Generate MCP configuration for a specific AI client: claude, codex, gemini, etc.' ],
		[ 'method' => 'POST', 'path' => '/operations/user_manage/run', 'scope' => 'full', 'description' => 'Safely manage WordPress users: { action: user_list|user_get|user_search|user_create|user_update|user_delete|user_suspend|user_reset_password|user_assign_role|user_remove_role, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/user_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a user management operation: { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/media_manage/run', 'scope' => 'full', 'description' => 'Safely manage WordPress media: { action: media_list|media_get|media_search|media_upload|media_replace|media_delete|media_restore|featured_image_assign|featured_image_remove|media_regenerate_metadata, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/media_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a media management operation: { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/woocommerce_manage/run', 'scope' => 'full', 'description' => 'Safely manage WooCommerce products, inventory, pricing, categories, attributes, variations, orders, and coupons.' ],
		[ 'method' => 'POST', 'path' => '/operations/woocommerce_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a WooCommerce operation: { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/acf_manage/run', 'scope' => 'full', 'description' => 'Manage Advanced Custom Fields: field groups, fields, locations, JSON sync, values, and inventory.' ],
		[ 'method' => 'POST', 'path' => '/operations/acf_manage/rollback', 'scope' => 'full', 'description' => 'Roll back an ACF operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/forms_manage/run', 'scope' => 'full', 'description' => 'Manage WordPress forms: list, get, create, update, delete, entries, notifications, analysis, submission stats.' ],
		[ 'method' => 'POST', 'path' => '/operations/forms_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a forms operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/menu_manage/run', 'scope' => 'full', 'description' => 'Manage WordPress menus: create, update, delete, items, locations, tree inspection, analysis, inventory.' ],
		[ 'method' => 'POST', 'path' => '/operations/menu_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a menu operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/settings_manage/run', 'scope' => 'full', 'description' => 'Manage WordPress core settings: general, reading, discussion, media, permalink, privacy. Read, update, analyze, inventory.' ],
		[ 'method' => 'POST', 'path' => '/operations/settings_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a settings operation.' ],
		[ 'method' => 'POST', 'path' => '/operations/search_manage/run', 'scope' => 'read_only', 'description' => 'Run universal search or generate site-wide reports: content, media, users, WooCommerce, forms, ACF, menus, orphans, unused media, inventory, site summary. Read-only, no approval required.' ],
		[ 'method' => 'POST', 'path' => '/operations/bulk_manage/run', 'scope' => 'full', 'description' => 'Execute bulk operations: bulk_content, bulk_publish, bulk_unpublish, bulk_media, bulk_woocommerce, bulk_acf, batch_execute. High-risk, requires approval.' ],
		[ 'method' => 'POST', 'path' => '/operations/bulk_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a bulk operation using a saved rollback_id.' ],
		[ 'method' => 'POST', 'path' => '/operations/workflow_manage/run', 'scope' => 'full', 'description' => 'Manage multi-step operation workflows: create, list, get, update, delete, execute, import, export, and history.' ],
		[ 'method' => 'POST', 'path' => '/operations/comments_manage/run', 'scope' => 'full', 'description' => 'Safely manage WordPress comments: list, get, approve, unapprove, spam, trash, delete, reply. WordPress comment API-based, approval-aware, rollback-capable for trash/delete.' ],
		[ 'method' => 'POST', 'path' => '/operations/comments_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a comment management operation: { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/widgets_manage/run', 'scope' => 'full', 'description' => 'Manage WordPress widgets and sidebar assignments: { action: widget_list|widget_get|widget_add|widget_update|widget_remove|sidebar_assign|sidebar_remove, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/widgets_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a widgets operation: { rollback_id }.' ],
		[ 'method' => 'POST', 'path' => '/operations/cpt_manage/run', 'scope' => 'full', 'description' => 'Manage WordPress custom post types and taxonomies: { action: cpt_list|cpt_get|cpt_create|cpt_update|cpt_disable|taxonomy_list|taxonomy_create|taxonomy_update, ... }.' ],
		[ 'method' => 'POST', 'path' => '/operations/cpt_manage/rollback', 'scope' => 'full', 'description' => 'Roll back a CPT operation: { rollback_id }.' ],
	];

	private AuthTokens $tokens;

	public function __construct() {
		$this->tokens = new AuthTokens();
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/health', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_health' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/health/verify', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'verify_health' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/health/results', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_health_results' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/system/environment', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_environment_mode' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'set_environment_mode' ],
				'permission_callback' => [ $this, 'require_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/system/cleanup', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'cleanup_system' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/capabilities', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_capabilities' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/manifest', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_manifest' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/manifest', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_manifest' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/context', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_context' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/context', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_context' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/timeline', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_timeline' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/tree', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_tree' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/site-intelligence', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_site_intelligence' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/diagnostics', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_diagnostics' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/diagnostics/debug-log', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_debug_log' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/recommendations', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_recommendations' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/recommendations/scan', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'scan_recommendations' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/recommendations/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_recommendation' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		foreach ( [ 'dismiss', 'resolve', 'convert-to-action', 'create-plan' ] as $recommendation_action ) {
			register_rest_route( self::NAMESPACE, "/recommendations/(?P<id>[a-f0-9-]{36})/{$recommendation_action}", [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $request ) use ( $recommendation_action ) {
					return $this->run_recommendation_action( $request, $recommendation_action );
				},
				'permission_callback' => [ $this, 'require_write' ],
			] );
		}

		register_rest_route( self::NAMESPACE, '/files', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_files' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/files/content', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'read_file' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/files/meta', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_file_meta' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'search_code' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/sessions', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_agent_sessions' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_agent_session' ],
				'permission_callback' => [ $this, 'require_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/agent/sessions/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_session' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/sessions/(?P<id>[a-f0-9-]{36})/close', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'close_agent_session' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/tasks', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_agent_tasks' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_agent_task' ],
				'permission_callback' => [ $this, 'require_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/agent/tasks/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_task' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/tasks/(?P<id>[a-f0-9-]{36})/status', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'update_agent_task_status' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/agent/actions', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_agent_actions' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_agent_action' ],
				'permission_callback' => [ $this, 'require_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/agent/actions/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_action' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		foreach ( [ 'accept', 'reject', 'cancel', 'complete' ] as $action ) {
			register_rest_route( self::NAMESPACE, "/agent/actions/(?P<id>[a-f0-9-]{36})/{$action}", [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $request ) use ( $action ) {
					return $this->run_agent_action_transition( $request, $action );
				},
				'permission_callback' => [ $this, 'require_write' ],
			] );
		}

		register_rest_route( self::NAMESPACE, '/agent/plans', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_agent_plans' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_agent_plan' ],
				'permission_callback' => [ $this, 'require_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/agent/plans/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agent_plan' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		foreach ( [ 'approve', 'reject', 'cancel' ] as $action ) {
			register_rest_route( self::NAMESPACE, "/agent/plans/(?P<id>[a-f0-9-]{36})/{$action}", [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $request ) use ( $action ) {
					return $this->run_agent_plan_action( $request, $action );
				},
				'permission_callback' => [ $this, 'require_write' ],
			] );
		}

		register_rest_route( self::NAMESPACE, '/patches', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_patches' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_patch' ],
				'permission_callback' => [ $this, 'require_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/patches/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_patch' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		foreach ( [ 'approve', 'reject', 'apply', 'rollback' ] as $action ) {
			register_rest_route( self::NAMESPACE, "/patches/(?P<id>[a-f0-9-]{36})/{$action}", [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $request ) use ( $action ) {
					return $this->run_patch_action( $request, $action );
				},
				'permission_callback' => [ $this, 'require_write' ],
			] );
		}

		register_rest_route( self::NAMESPACE, '/operations', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_operations' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/content_seed/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_content_seed' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/acf_seed/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_acf_seed' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/cf7_seed/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_cf7_seed' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/woo_product_seed/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_woo_product_seed' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/safe_search_replace/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_safe_search_replace' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/media_import/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_media_import' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/safe_updates/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_safe_updates' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/wp_cli_bridge/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_wp_cli_bridge' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/option_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_option_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/capability_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_capability_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/database_inspect/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_database_inspect' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		// STEP 98 — Reporting runtime (read-only).
		register_rest_route( self::NAMESPACE, '/operations/report_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_report_manage' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		// STEP 100.3/100.4 read diagnostics + STEP 100.5 reversible regeneration.
		// Per-action scope: read actions need a read token, write (regenerate)
		// actions need a full token (see require_media_enhance).
		register_rest_route( self::NAMESPACE, '/operations/media_enhance/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_media_enhance' ],
			'permission_callback' => [ $this, 'require_media_enhance' ],
		] );

		// STEP 100.5 — reverse a thumbnail regeneration (snapshot restore).
		register_rest_route( self::NAMESPACE, '/operations/media_enhance/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_media_enhance_rollback' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/content_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_content_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/snapshot_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_snapshot_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/theme_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_theme_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/plugin_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_plugin_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		// STEP 87 — File / Patch bridge: same shared services as MCP, via the
		// OperationExecutor. file_manage/code_search are read-only; patch/rollback
		// require write scope.
		register_rest_route( self::NAMESPACE, '/operations/file_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_file_manage' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/code_search/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_code_search' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/patch_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_patch_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/rollback_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_rollback_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		// STEP 91 — SEO runtime.
		register_rest_route( self::NAMESPACE, '/operations/seo_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_seo_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		// STEP 95 — Site Builder runtime.
		register_rest_route( self::NAMESPACE, '/operations/site_builder_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_site_builder_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/site_builder_manage/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_site_builder_rollback' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		// STEP 96 — Elementor runtime.
		register_rest_route( self::NAMESPACE, '/operations/elementor_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_elementor_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/elementor_manage/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_elementor_rollback' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/user_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_user_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/user_manage/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_user_rollback' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/media_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_media_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/media_manage/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_media_rollback' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/woocommerce_manage/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_woocommerce_manage' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/woocommerce_manage/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_woocommerce_rollback' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/acf_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_acf_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/acf_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_acf_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/forms_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_forms_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/forms_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_forms_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/menu_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_menu_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/menu_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_menu_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/settings_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_settings_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/settings_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_settings_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/search_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_search_manage' ], 'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/bulk_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_bulk_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/bulk_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_bulk_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/workflow_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_workflow_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/comments_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_comments_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/comments_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_comments_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/widgets_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_widgets_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/widgets_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_widgets_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/cpt_manage/run', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_cpt_manage' ], 'permission_callback' => [ $this, 'require_write' ],
		] );
		register_rest_route( self::NAMESPACE, '/operations/cpt_manage/rollback', [
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_cpt_rollback' ], 'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/requests', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_operation_requests' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_operation_request' ],
				'permission_callback' => [ $this, 'require_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/operations/requests/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_operation_request' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		foreach ( [ 'approve', 'reject', 'execute' ] as $action ) {
			register_rest_route( self::NAMESPACE, "/operations/requests/(?P<id>[a-f0-9-]{36})/{$action}", [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $request ) use ( $action ) {
					return $this->run_operation_request_action( $request, $action );
				},
				'permission_callback' => [ $this, 'require_write' ],
			] );
		}

		register_rest_route( self::NAMESPACE, '/operations/requests/(?P<id>[a-f0-9-]{36})/queue', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'queue_operation_request' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/queue', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_operation_queue' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/queue/process', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'process_operation_queue' ],
			'permission_callback' => [ $this, 'require_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/queue/(?P<id>[a-f0-9-]{36})', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_queue_item' ],
				'permission_callback' => [ $this, 'require_read' ],
			],
		] );

		foreach ( [ 'run', 'cancel', 'retry' ] as $action ) {
			register_rest_route( self::NAMESPACE, "/operations/queue/(?P<id>[a-f0-9-]{36})/{$action}", [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $request ) use ( $action ) {
					return $this->run_operation_queue_action( $request, $action );
				},
				'permission_callback' => [ $this, 'require_write' ],
			] );
		}

		register_rest_route( self::NAMESPACE, '/operations/results', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_operation_results' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/results/(?P<id>[a-f0-9-]{36})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_operation_result' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/operations/(?P<id>[a-z0-9_]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_operation' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		// ── Claude Desktop Integration ──
		register_rest_route( self::NAMESPACE, '/claude/config', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_claude_config' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/claude/discovery', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_claude_discovery' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/claude/tools', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_claude_tools' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/claude/prompts', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_claude_prompts' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/ai-clients', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_ai_clients' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/ai-clients/(?P<client>[a-z_]+)/config', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_ai_client_config' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );
	}

	/**
	 * Permission callback for read-only routes: any active, non-expired token.
	 */
	public function require_read( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->check_token( $request, AuthTokens::SCOPE_READ_ONLY );
	}

	/**
	 * Permission callback for write routes: requires a full-access token.
	 */
	public function require_write( \WP_REST_Request $request ): bool|\WP_Error {
		return $this->check_token( $request, AuthTokens::SCOPE_FULL );
	}

	/**
	 * STEP 100.5 — media_enhance has both read diagnostics and reversible write
	 * (regeneration) actions on one route. Gate per action: write actions require
	 * a full token, read actions a read token.
	 */
	public function require_media_enhance( \WP_REST_Request $request ): bool|\WP_Error {
		$action = (string) $request->get_param( 'action' );
		// Write (regeneration) actions — kept in sync with
		// MediaEnhancementRegistry::WRITE_ACTIONS. Listed inline here because the
		// registry class shares a file with the manager and isn't autoloadable on
		// its own at permission-callback time (before the handler is resolved).
		$write_actions = [ 'thumbnail_regenerate', 'thumbnail_regenerate_attachment', 'thumbnail_regenerate_batch', 'webp_generate', 'webp_generate_batch', 'image_optimize', 'image_optimize_batch', 'unused_media_cleanup' ];
		if ( in_array( $action, $write_actions, true ) ) {
			return $this->require_write( $request );
		}
		return $this->require_read( $request );
	}

	private function check_token( \WP_REST_Request $request, string $required_scope ): bool|\WP_Error {
		$header = $request->get_header( 'authorization' );

		if ( ! $header || ! preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return new \WP_Error(
				'wpcc_missing_token',
				__( 'Missing API token. Provide an "Authorization: Bearer <token>" header.', 'wp-command-center' ),
				[ 'status' => 401 ]
			);
		}

		$record = $this->tokens->validate( $matches[1] );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		// Ensure current user is set for permission checks (e.g. current_user_can).
		if ( ! empty( $record['user_id'] ) ) {
			wp_set_current_user( $record['user_id'] );
		}

		if ( AuthTokens::SCOPE_FULL === $required_scope && AuthTokens::SCOPE_FULL !== $record['scope'] ) {
			return new \WP_Error(
				'wpcc_insufficient_scope',
				__( 'This API token is read-only and cannot perform this action.', 'wp-command-center' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Re-validate the bearer token on the current request and return its
	 * stored record (id, label, scope, ...), or null if missing/invalid.
	 */
	private function validated_token( \WP_REST_Request $request ): ?array {
		$header = $request->get_header( 'authorization' );

		if ( ! $header || ! preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return null;
		}

		$record = $this->tokens->validate( $matches[1] );

		return is_wp_error( $record ) ? null : $record;
	}

	/**
	 * The scope ('read_only'|'full') of the token on the current request.
	 */
	private function token_scope( \WP_REST_Request $request ): ?string {
		return $this->validated_token( $request )['scope'] ?? null;
	}

	/**
	 * Build an audit-log actor descriptor from the current request's token.
	 *
	 * @return array<string, mixed>
	 */
	private function token_actor( \WP_REST_Request $request ): array {
		$record = $this->validated_token( $request );

		if ( null === $record ) {
			return [];
		}

		return [
			'type'  => 'token',
			'id'    => $record['id'],
			'label' => $record['label'],
		];
	}

	/**
	 * Add a default HTTP status to a WP_Error that doesn't already carry one.
	 */
	private function with_status( \WP_Error $error, int $default = 400 ): \WP_Error {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return $error;
		}

		$code = $error->get_error_code();

		if ( str_contains( $code, 'not_found' ) ) {
			$status = 404;
		} elseif ( 'wpcc_file_blocked' === $code ) {
			$status = 403;
		} else {
			$status = $default;
		}

		$error->add_data( [ 'status' => $status ] );

		return $error;
	}

	/**
	 * Record a security.* audit entry for the current request's actor.
	 *
	 * @param array<string, mixed> $context
	 */
	private function record_security_event( \WP_REST_Request $request, string $action, array $context ): void {
		$context['actor'] = AuditLog::resolve_actor( $this->token_actor( $request ) );

		( new AuditLog() )->record( $action, $context );
	}

	/**
	 * Redact a single string field of a response array in place. If any
	 * secrets are found, sets `redacted`/`redaction_count` on the response
	 * and records a security.content_redacted audit entry.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function redact_field( array $data, string $field, string $endpoint, \WP_REST_Request $request ): array {
		if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
			return $data;
		}

		$result = ( new Redactor() )->redact( $data[ $field ] );

		if ( $result['count'] > 0 ) {
			$data[ $field ]          = $result['text'];
			$data['redacted']        = true;
			$data['redaction_count'] = $result['count'];

			$this->record_security_event( $request, 'security.content_redacted', [
				'endpoint' => $endpoint,
				'count'    => $result['count'],
			] );
		}

		return $data;
	}

	/**
	 * Redact the `text` field of every item in a list (e.g. search matches
	 * or debug log lines), aggregating the total redaction count.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function redact_text_list( array $data, string $list_key, string $endpoint, \WP_REST_Request $request ): array {
		if ( ! isset( $data[ $list_key ] ) || ! is_array( $data[ $list_key ] ) ) {
			return $data;
		}

		$redactor = new Redactor();
		$total    = 0;

		foreach ( $data[ $list_key ] as &$item ) {
			if ( ! is_array( $item ) || ! isset( $item['text'] ) || ! is_string( $item['text'] ) ) {
				continue;
			}

			$result = $redactor->redact( $item['text'] );

			if ( $result['count'] > 0 ) {
				$item['text'] = $result['text'];
				$total       += $result['count'];
			}
		}
		unset( $item );

		if ( $total > 0 ) {
			$data['redacted']        = true;
			$data['redaction_count'] = $total;

			$this->record_security_event( $request, 'security.content_redacted', [
				'endpoint' => $endpoint,
				'count'    => $total,
			] );
		}

		return $data;
	}

	/**
	 * Recursively redact every string value in a response array.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function redact_response( array $data, string $endpoint, \WP_REST_Request $request ): array {
		$result = ( new Redactor() )->redact_recursive( $data );

		if ( $result['count'] > 0 ) {
			$data                    = $result['data'];
			$data['redacted']        = true;
			$data['redaction_count'] = $result['count'];

			$this->record_security_event( $request, 'security.content_redacted', [
				'endpoint' => $endpoint,
				'count'    => $result['count'],
			] );
		}

		return $data;
	}

	public function get_health(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'status'         => 'ok',
			'plugin_version' => WPCC_VERSION,
			'api_version'    => 'v1',
			'timestamp'      => time(),
		] );
	}

	public function get_capabilities( \WP_REST_Request $request ): \WP_REST_Response {
		$scope  = $this->token_scope( $request );
		$server = ( new SiteScanner() )->scan()['server'];

		return new \WP_REST_Response( [
			'file_read'         => true,
			'file_write'        => false,
			'patch_apply'       => AuthTokens::SCOPE_FULL === $scope,
			'rollback'          => AuthTokens::SCOPE_FULL === $scope,
			'shell_exec'        => $server['shell_exec_enabled'],
			'proc_open'         => $server['proc_open_enabled'],
			'wp_cli'            => $server['wp_cli_available'],
			'wp_cli_operations' => AuthTokens::SCOPE_FULL === $scope,
		] );
	}

	public function get_manifest(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'name'        => 'WP Command Center',
			'version'     => WPCC_VERSION,
			'api_version' => 'v1',
			'namespace'   => self::NAMESPACE,
			'base_url'    => rest_url( self::NAMESPACE ),
			'endpoints'   => self::ROUTE_MANIFEST,
		] );
	}

	/**
	 * Agent discovery manifest: a static, self-describing summary of this
	 * plugin's capabilities, security posture, workflow, endpoints, error
	 * codes, and capability negotiation, so an AI agent can operate without
	 * hardcoded knowledge of this API. Read-only; contains no file
	 * contents, secrets, tokens, or customer data.
	 */
	public function get_agent_manifest( \WP_REST_Request $request ): \WP_REST_Response {
		$manifest = $this->build_agent_manifest();
		$meta     = $this->manifest_version_and_hash( $manifest );

		$manifest['manifest_version'] = $meta['version'];
		$manifest['manifest_hash']    = $meta['hash'];

		return new \WP_REST_Response( $manifest );
	}

	/**
	 * Build the GET /agent/manifest payload, excluding manifest_version and
	 * manifest_hash (added separately so the hash can be computed over this
	 * content).
	 *
	 * @return array<string, mixed>
	 */
	private function build_agent_manifest(): array {
		$bridge  = new WpCliBridge();
		$options = new OptionRegistry();
		$plugins = new PluginRegistry();
		$themes  = new ThemeRegistry();
		$snaps   = new SnapshotRegistry();
		$content = new ContentRegistry();
		$db      = new DatabaseRegistry();
		$caps    = new CapabilityRegistry();

		return [
			'plugin'                 => [
				'name'        => 'WP Command Center',
				'version'     => WPCC_VERSION,
				'api_version' => 'v1',
				'db_version'  => Schema::DB_VERSION,
			],
			'capabilities'           => self::AGENT_CAPABILITIES,
			'security'               => self::get_agent_security(),
			'workflow'               => self::AGENT_WORKFLOW,
			'operations'             => ( new OperationRegistry() )->get_operations(),
			'wp_cli_bridge'          => [
				'available'           => $bridge->is_available(),
				'commands'            => $bridge->get_supported_commands(),
				'commands_by_risk'    => $bridge->count_by_risk(),
				'blocked_policy'      => $bridge->get_blocked_policy_summary(),
				'blocked_subcommands' => \WPCommandCenter\Operations\WpCliCommandRegistry::BLOCKED_SUBCOMMANDS,
			],
			'plugin_management'      => [
				'available'          => true,
				'plugins'            => $plugins->get_summary(),
				'supported_actions'  => PluginRegistry::ACTIONS,
				'risk_model'         => [
					'plugin_list'       => PluginRegistry::RISK_LOW,
					'plugin_install'    => PluginRegistry::RISK_MEDIUM,
					'plugin_activate'   => PluginRegistry::RISK_MEDIUM,
					'plugin_deactivate' => PluginRegistry::RISK_MEDIUM,
					'plugin_update'     => PluginRegistry::RISK_HIGH,
					'plugin_delete'     => PluginRegistry::RISK_CRITICAL,
				],
			],
			'theme_management'       => [
				'available'          => true,
				'themes'             => $themes->get_summary(),
				'supported_actions'  => ThemeRegistry::ACTIONS,
				'risk_model'         => [
					'theme_list'     => ThemeRegistry::RISK_LOW,
					'theme_install'  => ThemeRegistry::RISK_MEDIUM,
					'theme_activate' => ThemeRegistry::RISK_CRITICAL,
					'theme_update'   => ThemeRegistry::RISK_HIGH,
					'theme_delete'   => ThemeRegistry::RISK_CRITICAL,
				],
			],
			'snapshot_management'    => [
				'available'          => true,
				'supported_actions'  => SnapshotRegistry::ACTIONS,
				'risk_model'         => [
					'snapshot_list'    => SnapshotRegistry::RISK_LOW,
					'snapshot_details' => SnapshotRegistry::RISK_LOW,
					'snapshot_create'  => SnapshotRegistry::RISK_MEDIUM,
					'snapshot_verify'  => SnapshotRegistry::RISK_MEDIUM,
					'snapshot_restore' => SnapshotRegistry::RISK_CRITICAL,
				],
				'restore_supported'  => true,
			],
			'content_management'     => [
				'available'          => true,
				'supported_actions'  => ContentRegistry::ACTIONS,
				'supported_types'    => ContentRegistry::TYPES,
				'risk_model'         => [
					'content_list'      => ContentRegistry::RISK_LOW,
					'content_get'       => ContentRegistry::RISK_LOW,
					'content_create'    => ContentRegistry::RISK_MEDIUM,
					'content_update'    => ContentRegistry::RISK_MEDIUM,
					'content_delete'    => ContentRegistry::RISK_HIGH,
					'content_publish'   => ContentRegistry::RISK_HIGH,
					'content_unpublish' => ContentRegistry::RISK_HIGH,
					'content_schedule'  => ContentRegistry::RISK_HIGH,
					'taxonomy_assign'   => ContentRegistry::RISK_MEDIUM,
					'featured_image_assign' => ContentRegistry::RISK_MEDIUM,
				],
				'content_counts'     => $content->get_summary(),
			],
			'database_inspection'    => [
				'available'          => true,
				'read_only'          => true,
				'supported_actions'  => DatabaseRegistry::ACTIONS,
				'allowed_tables'     => DatabaseRegistry::CORE_TABLES,
				'prohibited_actions' => DatabaseRegistry::WRITE_KEYWORDS,
				'risk_model'         => [
					'db_table_list'        => DatabaseRegistry::RISK_LOW,
					'db_row_counts'        => DatabaseRegistry::RISK_LOW,
					'db_health_summary'    => DatabaseRegistry::RISK_LOW,
					'db_table_stats'       => DatabaseRegistry::RISK_MEDIUM,
					'db_table_size'        => DatabaseRegistry::RISK_MEDIUM,
					'db_autoload_analysis' => DatabaseRegistry::RISK_MEDIUM,
					'db_options_health'    => DatabaseRegistry::RISK_MEDIUM,
					'db_index_analysis'    => DatabaseRegistry::RISK_MEDIUM,
					'db_orphan_detection'  => DatabaseRegistry::RISK_MEDIUM,
				],
			],
			'capability_management'  => [
				'available'          => true,
				'capabilities'       => CapabilityRegistry::ALL_CAPABILITIES,
				'operation_map'      => CapabilityRegistry::OPERATION_MAP,
				'supported_actions'  => CapabilityRegistry::ACTIONS,
				'risk_model'         => [
					'capability_list'     => CapabilityRegistry::RISK_LOW,
					'capability_get'      => CapabilityRegistry::RISK_LOW,
					'capability_validate' => CapabilityRegistry::RISK_LOW,
					'capability_assign'   => CapabilityRegistry::RISK_HIGH,
					'capability_remove'   => CapabilityRegistry::RISK_HIGH,
				],
			],
			'mcp_server'             => [
				'available'    => true,
				'protocol'     => 'JSON-RPC 2.0',
				'version'      => '2024-11-05',
				'endpoint'     => rest_url( self::NAMESPACE . '/mcp' ),
				'resources'    => [ 'manifest', 'context', 'capabilities', 'operations', 'queue', 'results', 'recommendations' ],
				'tool_count'   => count( ( new OperationRegistry() )->get_operations() ),
			],
			'widgets_management'     => [
				'available'          => true,
				'supported_actions'  => WidgetsRegistry::ACTIONS,
				'risk_model'         => [
					'widget_list'    => WidgetsRegistry::RISK_LOW,
					'widget_get'     => WidgetsRegistry::RISK_LOW,
					'widget_add'     => WidgetsRegistry::RISK_HIGH,
					'widget_update'  => WidgetsRegistry::RISK_HIGH,
					'widget_remove'  => WidgetsRegistry::RISK_HIGH,
					'sidebar_assign' => WidgetsRegistry::RISK_HIGH,
					'sidebar_remove' => WidgetsRegistry::RISK_HIGH,
				],
			],
			'cpt_management'         => [
				'available'          => true,
				'supported_actions'  => CPTRegistry::ACTIONS,
				'risk_model'         => [
					'cpt_list'        => CPTRegistry::RISK_LOW,
					'cpt_get'         => CPTRegistry::RISK_LOW,
					'taxonomy_list'   => CPTRegistry::RISK_LOW,
					'cpt_create'      => CPTRegistry::RISK_HIGH,
					'cpt_update'      => CPTRegistry::RISK_HIGH,
					'cpt_disable'     => CPTRegistry::RISK_HIGH,
					'taxonomy_create' => CPTRegistry::RISK_HIGH,
					'taxonomy_update' => CPTRegistry::RISK_HIGH,
				],
			],
			'claude_integration'     => ClaudeIntegration::get_context_block()['ai_clients'],
			'ai_clients'              => [
				'available'   => true,
				'clients'     => array_map( static fn( $id, $c ) => [
					'id'     => $id,
					'name'   => $c['name'],
					'vendor' => $c['vendor'],
					'type'   => $c['type'],
					'status' => $c['status'],
				], array_keys( \WPCommandCenter\Integration\AIClientRegistry::get_clients() ), \WPCommandCenter\Integration\AIClientRegistry::get_clients() ),
				'client_count' => \WPCommandCenter\Integration\AIClientRegistry::get_counts()['total'],
				'active_count' => \WPCommandCenter\Integration\AIClientRegistry::get_counts()['active'],
			],
			'option_management'      => [
				'available'        => true,
				'options'          => $options->get_summary(),
				'options_by_risk'  => $options->count_by_risk(),
				'options_by_group' => array_keys( $options->get_by_group() ),
			],
			'recommendation_statuses'   => RecommendationEngine::STATUSES,
			'recommendation_severities' => RecommendationEngine::SEVERITIES,
			'endpoints'              => self::ROUTE_MANIFEST,
			'error_catalog'          => self::ERROR_CATALOG,
			'capability_negotiation' => $this->build_capability_negotiation(),
			'versions'               => [
				'plugin_version' => WPCC_VERSION,
				'api_version'    => 'v1',
				'db_version'     => Schema::DB_VERSION,
			],
		];
	}

	/**
	 * Server/plugin capability negotiation block for GET /agent/manifest.
	 * `shell_exec`, `proc_open`, and `wp_cli` reflect this server's
	 * environment; `file_access`, `patch_apply`, and `rollback` are plugin
	 * features that are always available (subject to token scope and the
	 * human-approval workflow at the per-action level).
	 *
	 * @return array<string, bool>
	 */
	private function build_capability_negotiation(): array {
		$server = ( new SiteScanner() )->scan()['server'];

		return [
			'shell_exec'  => $server['shell_exec_enabled'],
			'proc_open'   => $server['proc_open_enabled'],
			'wp_cli'      => $server['wp_cli_available'],
			'file_access' => true,
			'patch_apply' => true,
			'rollback'    => true,
		];
	}

	/**
	 * The agent manifest's schema version and a content hash, so agents can
	 * detect when the manifest changes (GET /agent/context exposes the same
	 * pair as manifest_version/manifest_hash).
	 *
	 * @param array<string, mixed>|null $manifest Pre-built manifest from
	 *                                             build_agent_manifest(), or null to build it.
	 * @return array{version: string, hash: string}
	 */
	private function manifest_version_and_hash( ?array $manifest = null ): array {
		$manifest                     = $manifest ?? $this->build_agent_manifest();
		$manifest['manifest_version'] = self::AGENT_MANIFEST_VERSION;

		return [
			'version' => self::AGENT_MANIFEST_VERSION,
			'hash'    => hash( 'sha256', (string) wp_json_encode( $manifest ) ),
		];
	}

	public function verify_health( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = ( new HealthVerificationEngine() )->verify( $this->token_actor( $request ) );
		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result, 500 );
		}
		return new \WP_REST_Response( $this->redact_response( $result, 'health/verify', $request ), 201 );
	}

	public function get_environment_mode(): \WP_REST_Response {
		return new \WP_REST_Response( [ 'mode' => ( new EnvironmentManager() )->get(), 'supported_modes' => EnvironmentManager::MODES ] );
	}

	public function set_environment_mode( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$manager  = new EnvironmentManager();
		$previous = $manager->get();
		$result   = $manager->set( (string) $request->get_param( 'mode' ) );
		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}
		( new AuditLog() )->record( 'system.environment.updated', [ 'previous_mode' => $previous, 'mode' => $result, 'actor' => AuditLog::resolve_actor( $this->token_actor( $request ) ) ] );
		return new \WP_REST_Response( [ 'mode' => $result, 'previous_mode' => $previous ] );
	}

	public function cleanup_system( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = ( new CleanupManager() )->cleanup( $request->get_params(), $this->token_actor( $request ) );
		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}
		return new \WP_REST_Response( $result );
	}

	public function list_health_results( \WP_REST_Request $request ): \WP_REST_Response {
		$results = ( new HealthVerificationEngine() )->list( [
			'status' => sanitize_key( (string) $request->get_param( 'status' ) ),
			'limit'  => $request->get_param( 'limit' ),
			'offset' => $request->get_param( 'offset' ),
		] );
		return new \WP_REST_Response( $this->redact_response( $results, 'health/results', $request ) );
	}

	public function get_context( \WP_REST_Request $request ): \WP_REST_Response {
		$context = ( new ContextBuilder() )->build();
		$context = $this->redact_response( $context, 'context', $request );

		return new \WP_REST_Response( $context );
	}

	public function get_agent_context( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$include_files       = $this->request_boolean( $request, 'include_files', false );
		$include_diagnostics = $this->request_boolean( $request, 'include_diagnostics', true );
		$session_id          = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$scan                = ( new SiteScanner() )->scan();
		$patches             = ( new PatchManager() )->list();

		$response = [
			'health'              => $this->get_health()->get_data(),
			'capabilities'        => $this->get_capabilities( $request )->get_data(),
			'site_summary'        => [
				'wordpress_version' => $scan['wordpress']['version'],
				'php_version'       => $scan['php']['version'],
				'theme'             => $scan['theme'],
				'active_plugins'    => count( $scan['plugins'] ),
				'woocommerce'      => $scan['woocommerce'],
				'debug_enabled'    => $scan['debug']['wp_debug'],
			],
			'context'             => ( new ContextBuilder() )->build( $include_files, $include_diagnostics ),
			'recent_patches'      => array_slice( $patches, 0, 10 ),
			'recent_actions'      => array_slice( $this->list_agent_actions_by(), 0, 10 ),
			'recent_audit_entries' => ( new AuditLog() )->tail( 20 ),
		];

		if ( '' !== $session_id ) {
			$session = $this->find_agent_session( $session_id );

			if ( null === $session ) {
				return $this->with_status( new \WP_Error( 'wpcc_session_not_found', __( 'Agent session not found.', 'wp-command-center' ) ) );
			}

			$task_rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $this->agent_tasks_table() . ' WHERE session_id = %s ORDER BY id DESC', $session_id ),
				ARRAY_A
			);

			$response['session']         = $session;
			$response['session_tasks']   = array_map( [ $this, 'normalize_agent_task' ], $task_rows ?: [] );
			$response['session_actions'] = $this->list_agent_actions_by( 'session_id', $session_id );
			$response['session_plans']   = $this->list_agent_plans_by( 'session_id', $session_id );
			$response['session_patches'] = array_values( array_filter(
				$patches,
				static fn ( array $patch ): bool => $session_id === ( $patch['session_id'] ?? null )
			) );
		}

		$response['operations'] = ( new OperationRegistry() )->get_operations();

		$op_manager = new OperationManager();
		$op_queue   = new OperationQueue();

		$response['pending_operation_requests'] = $op_manager->list_requests( [
			'session_id' => $session_id,
			'status'     => OperationManager::STATUS_PENDING_REVIEW,
			'limit'      => 10,
		] );
		$response['recent_operation_requests']  = $op_manager->list_requests( [
			'session_id' => $session_id,
			'limit'      => 10,
		] );

		$response['pending_queue_items'] = $op_queue->list_items( [
			'status' => OperationQueue::STATUS_QUEUED,
			'limit'  => 10,
		] );
		$response['recent_queue_items']  = $op_queue->list_items( [
			'limit' => 10,
		] );

		$failed_items = $op_queue->list_items( [
			'status' => OperationQueue::STATUS_FAILED,
			'limit'  => 50,
		] );
		$response['failed_queue_items']    = array_slice( $failed_items, 0, 10 );
		$response['retryable_queue_items'] = array_values( array_slice( array_filter( $failed_items, fn($i) => (int)$i['attempts'] < (int)$i['max_attempts'] ), 0, 10 ) );

		$worker_stats = ( new OperationWorker() )->get_stats();
		$response['queue_worker_status'] = $worker_stats['queue_worker_status'];
		$response['pending_queue_count'] = $worker_stats['pending_queue_count'];
		$response['running_queue_count'] = $worker_stats['running_queue_count'];
		$response['failed_queue_count']  = $worker_stats['failed_queue_count'];

		$response['recent_operation_results'] = ( new OperationResults() )->list_results( [
			'limit' => 10,
		] );

		$recommendations = new RecommendationEngine();
		$response['open_recommendations'] = $recommendations->list( [
			'status' => 'open',
			'limit'  => 50,
		] );
		$response['critical_recommendations'] = $recommendations->list( [
			'status'   => 'open',
			'severity' => 'critical',
			'limit'    => 50,
		] );
		$response['recent_recommendations'] = $recommendations->list( [
			'limit' => 10,
		] );
		$response['recommendation_summary'] = [
			'open'              => count( $recommendations->list( [ 'status' => 'open', 'limit' => 200 ] ) ),
			'awaiting_plan'     => count( $recommendations->list( [ 'status' => 'converted_to_action', 'limit' => 200 ] ) ),
			'awaiting_approval' => count( $recommendations->list( [ 'status' => 'plan_created', 'limit' => 200 ] ) ),
			'in_progress'       => count( $recommendations->list( [ 'status' => 'executing', 'limit' => 200 ] ) ),
			'resolved'          => count( $recommendations->list( [ 'status' => 'resolved', 'limit' => 200 ] ) ),
		];
		$response['recent_health_verifications'] = ( new HealthVerificationEngine() )->list( [ 'limit' => 5 ] );
		$response['environment_mode'] = ( new EnvironmentManager() )->get();

		$bridge = new WpCliBridge();
		$response['wp_cli_available']           = $bridge->is_available();
		$response['wp_cli_supported_commands']  = $bridge->get_supported_commands();
		$response['wp_cli_blocked_policy_summary'] = $bridge->get_blocked_policy_summary();
		$response['wp_cli_commands_by_risk']    = $bridge->count_by_risk();

		$options_registry = new OptionRegistry();
		$response['option_management_available']  = true;
		$response['supported_options']            = $options_registry->get_summary();
		$response['options_by_risk']              = $options_registry->count_by_risk();

		$plugins_registry = new PluginRegistry();
		$response['plugin_management_available'] = true;
		$response['plugin_state']               = $plugins_registry->count_by_state();
		$response['installed_plugins']          = $plugins_registry->get_summary()['plugins'];

		$theme_registry = new ThemeRegistry();
		$response['theme_management_available'] = true;
		$response['theme_state']               = $theme_registry->count_by_state();
		$response['active_theme']              = $theme_registry->get_active_theme();
		$response['installed_themes']          = array_map( static fn( $t ) => [
			'slug'    => $t['slug'],
			'name'    => $t['name'],
			'version' => $t['version'],
			'active'  => $t['active'],
			'parent'  => $t['parent'],
		], $theme_registry->get_themes() );

		$core_snaps = new \WPCommandCenter\Rollback\SnapshotManager();
		$all_snaps  = $core_snaps->list();
		$response['snapshot_management_available'] = true;
		$response['snapshot_count']               = count( $all_snaps );
		$response['latest_snapshot']              = empty( $all_snaps ) ? null : [
			'snapshot_id' => $all_snaps[0]['id'],
			'path'        => $all_snaps[0]['path'],
			'label'       => $all_snaps[0]['label'],
			'created_at'  => $all_snaps[0]['created_at'],
		];

		$content_registry = new ContentRegistry();
		$response['content_management_available'] = true;
		$response['content_counts']              = $content_registry->get_summary();

		global $wpdb;
		$db_size  = $wpdb->get_var( "SELECT ROUND(SUM(DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()" );
		$response['database_inspection_available'] = true;
		$response['database_size_mb']             = (float) ( $db_size ?? 0 );
		$response['database_core_table_count']    = count( DatabaseRegistry::CORE_TABLES );

		$cap_registry = new CapabilityRegistry();
		$ctx_actor    = $this->token_actor( $request );
		$ctx_tid      = $ctx_actor['token_id'] ?? ( $ctx_actor['id'] ?? '' );
		$response['capability_management_available'] = true;
		$response['assigned_capabilities']         = $ctx_tid ? $cap_registry->get_for_subject( 'token', $ctx_tid ) : [];
		$response['capability_enforcement']        = (bool) get_option( 'wpcc_enforce_capabilities', true );
		$response['mcp_server_available']          = true;
		$response['mcp_endpoint']                  = rest_url( self::NAMESPACE . '/mcp' );

		$response['claude_integration'] = ClaudeIntegration::get_context_block()['ai_clients'];
		$response['ai_clients']         = ClaudeIntegration::get_context_block()['ai_clients'];

		$manifest_meta = $this->manifest_version_and_hash();

		$response['manifest_version'] = $manifest_meta['version'];
		$response['manifest_hash']    = $manifest_meta['hash'];

		$response = $this->redact_response( $response, 'agent/context', $request );

		return new \WP_REST_Response( $response );
	}

	public function list_recommendations( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$filters = [
			'type'     => sanitize_key( (string) $request->get_param( 'type' ) ),
			'severity' => sanitize_key( (string) $request->get_param( 'severity' ) ),
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'source'   => sanitize_key( (string) $request->get_param( 'source' ) ),
			'limit'    => $request->get_param( 'limit' ),
			'offset'   => $request->get_param( 'offset' ),
		];

		if ( $filters['type'] && ! in_array( $filters['type'], RecommendationEngine::TYPES, true ) ) {
			return $this->with_status( new \WP_Error( 'wpcc_invalid_type', __( 'Invalid recommendation type.', 'wp-command-center' ) ) );
		}
		if ( $filters['severity'] && ! in_array( $filters['severity'], RecommendationEngine::SEVERITIES, true ) ) {
			return $this->with_status( new \WP_Error( 'wpcc_invalid_recommendation_severity', __( 'Invalid recommendation severity.', 'wp-command-center' ) ) );
		}
		if ( $filters['status'] && ! in_array( $filters['status'], RecommendationEngine::STATUSES, true ) ) {
			return $this->with_status( new \WP_Error( 'wpcc_invalid_recommendation_status', __( 'Invalid recommendation status.', 'wp-command-center' ) ) );
		}

		$data = ( new RecommendationEngine() )->list( array_filter( $filters, static fn ( $value ): bool => null !== $value && '' !== $value ) );
		return new \WP_REST_Response( $this->redact_response( $data, 'recommendations', $request ) );
	}

	public function get_recommendation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = ( new RecommendationEngine() )->get( (string) $request->get_param( 'id' ) );
		if ( ! $record ) {
			return $this->with_status( new \WP_Error( 'wpcc_recommendation_not_found', __( 'Recommendation not found.', 'wp-command-center' ) ) );
		}

		return new \WP_REST_Response( $this->redact_response( $record, 'recommendations/detail', $request ) );
	}

	public function scan_recommendations( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = ( new RecommendationEngine() )->scan( $this->token_actor( $request ) );
		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result, 500 );
		}

		return new \WP_REST_Response( $this->redact_response( $result, 'recommendations/scan', $request ) );
	}

	private function run_recommendation_action( \WP_REST_Request $request, string $action ): \WP_REST_Response|\WP_Error {
		$engine = new RecommendationEngine();
		$id     = (string) $request->get_param( 'id' );

		if ( 'convert-to-action' === $action ) {
			$result = $engine->convert_to_action(
				$id,
				sanitize_text_field( (string) $request->get_param( 'session_id' ) ),
				sanitize_text_field( (string) $request->get_param( 'task_id' ) ),
				$this->token_actor( $request )
			);
		} elseif ( 'create-plan' === $action ) {
			$result = $engine->create_plan( $id, $request->get_params(), $this->token_actor( $request ) );
		} elseif ( in_array( $action, [ 'dismiss', 'resolve' ], true ) ) {
			$result = $engine->transition( $id, 'dismiss' === $action ? 'dismissed' : 'resolved', $this->token_actor( $request ) );
		} else {
			$result = new \WP_Error( 'wpcc_invalid_action', __( 'Invalid recommendation action.', 'wp-command-center' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		return new \WP_REST_Response( $this->redact_response( $result, 'recommendations/' . $action, $request ) );
	}

	public function get_agent_timeline( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [
			'session_id' => sanitize_text_field( (string) $request->get_param( 'session_id' ) ),
			'task_id'    => sanitize_text_field( (string) $request->get_param( 'task_id' ) ),
			'action_id'  => sanitize_text_field( (string) $request->get_param( 'action_id' ) ),
			'plan_id'    => sanitize_text_field( (string) $request->get_param( 'plan_id' ) ),
			'patch_id'   => sanitize_text_field( (string) $request->get_param( 'patch_id' ) ),
			'limit'      => $request->get_param( 'limit' ),
			'offset'     => $request->get_param( 'offset' ),
		];

		$timeline = ( new TimelineBuilder() )->build( array_filter( $filters ) );

		return new \WP_REST_Response( $timeline );
	}

	public function get_agent_tree( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$params     = $request->get_query_params();
		$session_id = sanitize_text_field( (string) ( $params['session_id'] ?? '' ) );
		$task_id    = sanitize_text_field( (string) ( $params['task_id'] ?? '' ) );
		$plan_id    = sanitize_text_field( (string) ( $params['plan_id'] ?? '' ) );

		$sessions = [];

		if ( ! empty( $session_id ) ) {
			$session = $this->find_agent_session( $session_id );
			if ( $session ) {
				$sessions[] = $session;
			}
		} elseif ( ! empty( $task_id ) ) {
			$task = $this->find_agent_task( $task_id );
			if ( $task ) {
				$session = $this->find_agent_session( $task['session_id'] );
				if ( $session ) {
					$sessions[] = $session;
				}
			}
		} elseif ( ! empty( $plan_id ) ) {
			$plan = $this->find_agent_plan( $plan_id );
			if ( $plan ) {
				$session = $this->find_agent_session( $plan['session_id'] );
				if ( $session ) {
					$sessions[] = $session;
				}
			}
		} else {
			// No filter: return last N sessions
			$limit    = isset( $params['limit'] ) ? max( 1, (int) $params['limit'] ) : 5;
			$offset   = isset( $params['offset'] ) ? max( 0, (int) $params['offset'] ) : 0;
			$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->agent_sessions_table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A );
			$sessions = array_map( [ $this, 'normalize_agent_session' ], $rows ?: [] );
		}

		$tree = array_map( function ( array $session ) use ( $task_id, $plan_id ): array {
			$session_id = $session['session_id'];
			$tasks      = $this->list_agent_tasks_by( 'session_id', $session_id );
			$actions    = $this->list_agent_actions_by( 'session_id', $session_id );
			$plans      = $this->list_agent_plans_by( 'session_id', $session_id );
			$patches    = $this->list_patches_by( 'session_id', $session_id );

			// Filter tasks if task_id provided
			if ( $task_id ) {
				$tasks = array_values( array_filter( $tasks, fn( $t ) => $t['task_id'] === $task_id ) );
			}

			foreach ( $tasks as &$task ) {
				$tid             = $task['task_id'];
				$task['actions'] = array_values( array_filter( $actions, fn( $a ) => $a['task_id'] === $tid ) );

				foreach ( $task['actions'] as &$action ) {
					$aid             = $action['action_id'];
					$action['plans'] = array_values( array_filter( $plans, fn( $p ) => $p['action_id'] === $aid ) );

					// Filter plans if plan_id provided
					if ( $plan_id ) {
						$action['plans'] = array_values( array_filter( $action['plans'], fn( $p ) => $p['plan_id'] === $plan_id ) );
					}

					foreach ( $action['plans'] as &$plan ) {
						$pid             = $plan['plan_id'];
						$plan['patches'] = array_values( array_filter( $patches, fn( $p ) => $p['plan_id'] === $pid ) );
					}
				}

				// Plans without actions (orphan plans)
				$task['orphan_plans'] = array_values( array_filter( $plans, fn( $p ) => $p['task_id'] === $tid && empty( $p['action_id'] ) ) );

				if ( $plan_id ) {
					$task['orphan_plans'] = array_values( array_filter( $task['orphan_plans'], fn( $p ) => $p['plan_id'] === $plan_id ) );
				}

				foreach ( $task['orphan_plans'] as &$plan ) {
					$pid             = $plan['plan_id'];
					$plan['patches'] = array_values( array_filter( $patches, fn( $p ) => $p['plan_id'] === $pid ) );
				}
			}

			$session['tasks'] = $tasks;

			return $session;
		}, $sessions );

		$response = $this->redact_response( [ 'sessions' => $tree ], 'agent/tree', $request );

		return new \WP_REST_Response( $response );
	}

	public function list_operations( \WP_REST_Request $request ): \WP_REST_Response {
		$operations = ( new OperationRegistry() )->get_operations();
		$response   = $this->redact_response( $operations, 'operations', $request );

		return new \WP_REST_Response( $response );
	}

	public function get_operation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id        = (string) $request->get_param( 'id' );
		$operation = ( new OperationRegistry() )->get_operation( $id );

		if ( null === $operation ) {
			return $this->with_status( new \WP_Error( 'wpcc_operation_not_found', __( 'Operation not found.', 'wp-command-center' ) ) );
		}

		$response = $this->redact_response( $operation, 'operations/detail', $request );

		return new \WP_REST_Response( $response );
	}

	public function list_operation_requests( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [
			'status'       => sanitize_key( (string) $request->get_param( 'status' ) ),
			'operation_id' => sanitize_key( (string) $request->get_param( 'operation_id' ) ),
			'session_id'   => sanitize_text_field( (string) $request->get_param( 'session_id' ) ),
			'task_id'      => sanitize_text_field( (string) $request->get_param( 'task_id' ) ),
			'plan_id'      => sanitize_text_field( (string) $request->get_param( 'plan_id' ) ),
			'limit'        => $request->get_param( 'limit' ),
			'offset'       => $request->get_param( 'offset' ),
		];

		$requests = ( new OperationManager() )->list_requests( array_filter( $filters ) );
		$response = $this->redact_response( $requests, 'operations/requests', $request );

		return new \WP_REST_Response( $response );
	}

	public function get_operation_request( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (string) $request->get_param( 'id' );
		$manager = new OperationManager();
		$row     = $manager->get_request( $id );

		if ( ! $row ) {
			return $this->with_status( new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'wp-command-center' ) ) );
		}

		$response = $this->redact_response( $row, 'operations/requests/detail', $request );

		return new \WP_REST_Response( $response );
	}

	public function create_operation_request( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$op_id   = sanitize_key( (string) ( $params['operation_id'] ?? '' ) );
		$payload = (array) ( $params['payload'] ?? [] );
		$actor   = $this->token_actor( $request );

		if ( empty( $op_id ) ) {
			return $this->with_status( new \WP_Error( 'wpcc_missing_operation_id', __( 'Operation ID is required.', 'wp-command-center' ) ) );
		}

		$meta = [
			'session_id' => sanitize_text_field( (string) ( $params['session_id'] ?? '' ) ),
			'task_id'    => sanitize_text_field( (string) ( $params['task_id'] ?? '' ) ),
			'action_id'  => sanitize_text_field( (string) ( $params['action_id'] ?? '' ) ),
			'plan_id'    => sanitize_text_field( (string) ( $params['plan_id'] ?? '' ) ),
			'actor'      => $actor,
		];

		$manager = new OperationManager();
		$result  = $manager->create_request( $op_id, $payload, $meta );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		( new AuditLog() )->record( 'operation.request.created', [
			'request_id'   => $result['request_id'],
			'operation_id' => $op_id,
			'session_id'   => $meta['session_id'],
			'task_id'      => $meta['task_id'],
			'action_id'    => $meta['action_id'],
			'plan_id'      => $meta['plan_id'],
			'risk_level'   => $result['risk_level'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		$response = $this->redact_response( $result, 'operations/requests/create', $request );

		return new \WP_REST_Response( $response, 201 );
	}

	public function run_operation_request_action( \WP_REST_Request $request, string $action ): \WP_REST_Response|\WP_Error {
		$id      = (string) $request->get_param( 'id' );
		$manager = new OperationManager();
		$actor   = $this->token_actor( $request );
		$audit   = new AuditLog();

		$row = $manager->get_request( $id );
		if ( ! $row ) {
			return $this->with_status( new \WP_Error( 'wpcc_request_not_found', __( 'Operation request not found.', 'wp-command-center' ) ) );
		}

		$result = null;
		$log_action = "operation.request.{$action}d";

		switch ( $action ) {
			case 'approve':
				$result = $manager->approve_request( $id, [ 'actor' => $actor ] );
				break;
			case 'reject':
				$result = $manager->reject_request( $id );
				break;
			case 'execute':
				$log_action = 'operation.request.executed';
				$audit->record( 'operation.request.executing', [
					'request_id'   => $id,
					'operation_id' => $row['operation_id'],
					'actor'        => AuditLog::resolve_actor( $actor ),
				] );
				$result = $manager->execute_request( $id, $actor );
				break;
		}

		if ( is_wp_error( $result ) ) {
			if ( 'execute' === $action ) {
				$audit->record( 'operation.request.failed', [
					'request_id' => $id,
					'error'      => $result->get_error_code(),
					'actor'      => AuditLog::resolve_actor( $actor ),
				] );
			}
			return $this->with_status( $result );
		}

		$audit->record( $log_action, [
			'request_id'   => $id,
			'operation_id' => $row['operation_id'],
			'session_id'   => $row['session_id'],
			'task_id'      => $row['task_id'],
			'action_id'    => $row['action_id'],
			'plan_id'      => $row['plan_id'],
			'actor'        => AuditLog::resolve_actor( $actor ),
		] );

		return new \WP_REST_Response( [
			'id'     => $id,
			'status' => $action === 'execute' ? OperationManager::STATUS_EXECUTED : ( $action === 'approve' ? OperationManager::STATUS_APPROVED : OperationManager::STATUS_REJECTED ),
			'result' => is_array( $result ) ? $result : null,
		] );
	}

	public function queue_operation_request( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (string) $request->get_param( 'id' );
		$params  = $request->get_params();
		$actor   = $this->token_actor( $request );
		$manager = new OperationQueue();

		$result = $manager->enqueue( $id, (int) ( $params['priority'] ?? 10 ), [ 'actor' => $actor ] );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		$response = $this->redact_response( $result, 'operations/queue/create', $request );

		return new \WP_REST_Response( $response, 201 );
	}

	public function list_operation_queue( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [
			'status'       => sanitize_key( (string) $request->get_param( 'status' ) ),
			'operation_id' => sanitize_key( (string) $request->get_param( 'operation_id' ) ),
			'request_id'   => sanitize_text_field( (string) $request->get_param( 'request_id' ) ),
			'limit'        => $request->get_param( 'limit' ),
			'offset'       => $request->get_param( 'offset' ),
		];

		$items    = ( new OperationQueue() )->list_items( array_filter( $filters ) );
		$response = $this->redact_response( $items, 'operations/queue', $request );

		return new \WP_REST_Response( $response );
	}

	public function process_operation_queue( \WP_REST_Request $request ): \WP_REST_Response {
		$limit  = (int) $request->get_param( 'limit' );
		$actor  = $this->token_actor( $request );
		$worker = new OperationWorker();

		// Default to 5 if not provided or invalid
		if ( $limit <= 0 ) {
			$limit = 5;
		}

		$result = $worker->process( $limit, [ 'actor' => $actor ] );

		return new \WP_REST_Response( $result );
	}

	public function get_queue_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (string) $request->get_param( 'id' );
		$item = ( new OperationQueue() )->get_item( $id );

		if ( ! $item ) {
			return $this->with_status( new \WP_Error( 'wpcc_queue_item_not_found', __( 'Queue item not found.', 'wp-command-center' ) ) );
		}

		$response = $this->redact_response( $item, 'operations/queue/detail', $request );

		return new \WP_REST_Response( $response );
	}

	public function run_operation_queue_action( \WP_REST_Request $request, string $action ): \WP_REST_Response|\WP_Error {
		$id      = (string) $request->get_param( 'id' );
		$queue   = new OperationQueue();
		$actor   = $this->token_actor( $request );
		$audit   = new AuditLog();

		$item = $queue->get_item( $id );
		if ( ! $item ) {
			return $this->with_status( new \WP_Error( 'wpcc_queue_item_not_found', __( 'Queue item not found.', 'wp-command-center' ) ) );
		}
		$operation_request = ( new OperationManager() )->get_request( $item['request_id'] );
		$links = [
			'request_id' => $item['request_id'],
			'session_id' => $operation_request['session_id'] ?? null,
			'task_id'    => $operation_request['task_id'] ?? null,
			'action_id'  => $operation_request['action_id'] ?? null,
			'plan_id'    => $operation_request['plan_id'] ?? null,
		];

		$result = null;

		if ( 'run' === $action ) {
			$audit->record( 'operation.queue.running', array_merge( $links, [
				'queue_id'     => $id,
				'operation_id' => $item['operation_id'],
				'actor'        => AuditLog::resolve_actor( $actor ),
			] ) );

			$result = $queue->run_item( $id, [ 'actor' => $actor ] );

			if ( is_wp_error( $result ) ) {
				$audit->record( 'operation.queue.failed', array_merge( $links, [
					'queue_id' => $id,
					'error'    => $result->get_error_code(),
					'actor'    => AuditLog::resolve_actor( $actor ),
				] ) );
				return $this->with_status( $result );
			}

			$audit->record( 'operation.queue.completed', array_merge( $links, [
				'queue_id'     => $id,
				'operation_id' => $item['operation_id'],
				'actor'        => AuditLog::resolve_actor( $actor ),
			] ) );

		} elseif ( 'cancel' === $action ) {
			$result = $queue->cancel_item( $id );

			if ( is_wp_error( $result ) ) {
				return $this->with_status( $result );
			}

			$audit->record( 'operation.queue.cancelled', array_merge( $links, [
				'queue_id' => $id,
				'actor'    => AuditLog::resolve_actor( $actor ),
			] ) );
		} elseif ( 'retry' === $action ) {
			$audit->record( 'operation.queue.retry_requested', array_merge( $links, [
				'queue_id'     => $id,
				'operation_id' => $item['operation_id'],
				'actor'        => AuditLog::resolve_actor( $actor ),
			] ) );

			$result = $queue->retry_item( $id );

			if ( is_wp_error( $result ) ) {
				$audit->record( 'operation.queue.retry_failed', array_merge( $links, [
					'queue_id' => $id,
					'error'    => $result->get_error_code(),
					'actor'    => AuditLog::resolve_actor( $actor ),
				] ) );
				return $this->with_status( $result );
			}

			$audit->record( 'operation.queue.retry_queued', array_merge( $links, [
				'queue_id'     => $id,
				'operation_id' => $item['operation_id'],
				'actor'        => AuditLog::resolve_actor( $actor ),
			] ) );
		}

		$status = match ( $action ) {
			'run'    => OperationQueue::STATUS_COMPLETED,
			'cancel' => OperationQueue::STATUS_CANCELLED,
			'retry'  => OperationQueue::STATUS_QUEUED,
			default  => 'unknown',
		};

		return new \WP_REST_Response( [
			'id'     => $id,
			'status' => $status,
			'result' => is_array( $result ) ? $result : null,
		] );
	}

	public function list_operation_results( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [
			'status'       => sanitize_key( (string) $request->get_param( 'status' ) ),
			'operation_id' => sanitize_key( (string) $request->get_param( 'operation_id' ) ),
			'queue_id'     => sanitize_text_field( (string) $request->get_param( 'queue_id' ) ),
			'request_id'   => sanitize_text_field( (string) $request->get_param( 'request_id' ) ),
			'limit'        => $request->get_param( 'limit' ),
			'offset'       => $request->get_param( 'offset' ),
		];

		$results  = ( new OperationResults() )->list_results( array_filter( $filters ) );
		$response = $this->redact_response( $results, 'operations/results', $request );

		return new \WP_REST_Response( $response );
	}

	public function get_operation_result( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (string) $request->get_param( 'id' );
		$result = ( new OperationResults() )->get_result( $id );

		if ( ! $result ) {
			return $this->with_status( new \WP_Error( 'wpcc_result_not_found', __( 'Operation result not found.', 'wp-command-center' ) ) );
		}

		$response = $this->redact_response( $result, 'operations/results/detail', $request );

		return new \WP_REST_Response( $response );
	}

	public function run_content_seed( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params   = $request->get_params();
		$context  = [ 'actor' => $this->token_actor( $request ) ];
		$executor = new OperationExecutor();
		$result   = $executor->run( 'content_seed', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_acf_seed( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params   = $request->get_params();
		$context  = [ 'actor' => $this->token_actor( $request ) ];
		$executor = new OperationExecutor();
		$result   = $executor->run( 'acf_seed', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_cf7_seed( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params   = $request->get_params();
		$context  = [ 'actor' => $this->token_actor( $request ) ];
		$executor = new OperationExecutor();
		$result   = $executor->run( 'cf7_seed', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_woo_product_seed( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params   = $request->get_params();
		$context  = [ 'actor' => $this->token_actor( $request ) ];
		$executor = new OperationExecutor();
		$result   = $executor->run( 'woo_product_seed', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_safe_search_replace( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params   = $request->get_params();
		$context  = [ 'actor' => $this->token_actor( $request ) ];
		$executor = new OperationExecutor();
		$result   = $executor->run( 'safe_search_replace', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_media_import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params   = $request->get_params();
		$context  = [ 'actor' => $this->token_actor( $request ) ];
		$executor = new OperationExecutor();
		$result   = $executor->run( 'media_import', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_safe_updates( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params   = $request->get_params();
		$context  = [ 'actor' => $this->token_actor( $request ) ];
		$executor = new OperationExecutor();
		$result   = $executor->run( 'safe_updates', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_wp_cli_bridge( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];

		if ( $request->get_param( 'session_id' ) ) {
			$context['session_id'] = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		}
		if ( $request->get_param( 'task_id' ) ) {
			$context['task_id'] = sanitize_text_field( (string) $request->get_param( 'task_id' ) );
		}
		if ( $request->get_param( 'action_id' ) ) {
			$context['action_id'] = sanitize_text_field( (string) $request->get_param( 'action_id' ) );
		}
		if ( $request->get_param( 'plan_id' ) ) {
			$context['plan_id'] = sanitize_text_field( (string) $request->get_param( 'plan_id' ) );
		}

		$executor = new OperationExecutor();
		$result   = $executor->run( 'wp_cli_bridge', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_option_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];

		if ( $request->get_param( 'session_id' ) ) {
			$context['session_id'] = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		}
		if ( $request->get_param( 'task_id' ) ) {
			$context['task_id'] = sanitize_text_field( (string) $request->get_param( 'task_id' ) );
		}
		if ( $request->get_param( 'action_id' ) ) {
			$context['action_id'] = sanitize_text_field( (string) $request->get_param( 'action_id' ) );
		}
		if ( $request->get_param( 'plan_id' ) ) {
			$context['plan_id'] = sanitize_text_field( (string) $request->get_param( 'plan_id' ) );
		}

		$executor = new OperationExecutor();
		$result   = $executor->run( 'option_manage', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_plugin_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];

		if ( $request->get_param( 'session_id' ) ) {
			$context['session_id'] = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		}
		if ( $request->get_param( 'task_id' ) ) {
			$context['task_id'] = sanitize_text_field( (string) $request->get_param( 'task_id' ) );
		}
		if ( $request->get_param( 'action_id' ) ) {
			$context['action_id'] = sanitize_text_field( (string) $request->get_param( 'action_id' ) );
		}
		if ( $request->get_param( 'plan_id' ) ) {
			$context['plan_id'] = sanitize_text_field( (string) $request->get_param( 'plan_id' ) );
		}

		$executor = new OperationExecutor();
		$result   = $executor->run( 'plugin_manage', $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	// ── STEP 87 — File / Patch bridge REST callbacks ──
	// Each runs the operation through the SAME OperationExecutor + shared services
	// that MCP uses, including the token_scope so read-only tokens are enforced
	// identically across both transports.

	public function run_file_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run_bridge_operation( 'file_manage', $request );
	}

	public function run_code_search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run_bridge_operation( 'code_search', $request );
	}

	public function run_patch_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run_bridge_operation( 'patch_manage', $request );
	}

	public function run_rollback_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run_bridge_operation( 'rollback_manage', $request );
	}

	public function run_seo_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run_bridge_operation( 'seo_manage', $request );
	}

	public function run_site_builder_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run_bridge_operation( 'site_builder_manage', $request );
	}

	public function run_site_builder_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
		}
		$result = ( new \WPCommandCenter\Operations\SiteBuilderRuntimeManager() )->rollback( $params, $context );
		if ( ! empty( $result['error'] ) ) return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		return new \WP_REST_Response( $result );
	}

	public function run_elementor_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run_bridge_operation( 'elementor_manage', $request );
	}

	public function run_elementor_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
		}
		$result = ( new \WPCommandCenter\Operations\ElementorRuntimeManager() )->rollback( $params, $context );
		if ( ! empty( $result['error'] ) ) return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		return new \WP_REST_Response( $result );
	}

	/**
	 * Shared dispatcher for the STEP 87 file/patch operations: builds the actor +
	 * continuity context (and token_scope, so READ_ONLY_SCOPE_OPERATIONS is
	 * enforced), runs the executor, and maps the structured result to a response.
	 */
	private function run_bridge_operation( string $operation_id, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$record  = $this->validated_token( $request ) ?: [];
		$context = [
			'actor'       => $this->token_actor( $request ),
			'token_id'    => $record['id'] ?? '',
			'token_scope' => $record['scope'] ?? '',
		];

		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $key ) {
			if ( $request->get_param( $key ) ) {
				$context[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
			}
		}

		$result = ( new OperationExecutor() )->run( $operation_id, $params, $context );

		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}

		return new \WP_REST_Response( $result['result'] );
	}

	public function run_theme_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		if ( $request->get_param( 'session_id' ) ) {
			$context['session_id'] = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		}
		if ( $request->get_param( 'task_id' ) ) {
			$context['task_id'] = sanitize_text_field( (string) $request->get_param( 'task_id' ) );
		}
		if ( $request->get_param( 'action_id' ) ) {
			$context['action_id'] = sanitize_text_field( (string) $request->get_param( 'action_id' ) );
		}
		if ( $request->get_param( 'plan_id' ) ) {
			$context['plan_id'] = sanitize_text_field( (string) $request->get_param( 'plan_id' ) );
		}
		$executor = new OperationExecutor();
		$result   = $executor->run( 'theme_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_snapshot_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new OperationExecutor();
		$result   = $executor->run( 'snapshot_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_content_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new OperationExecutor();
		$result   = $executor->run( 'content_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_database_inspect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new OperationExecutor();
		$result   = $executor->run( 'database_inspect', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_report_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new OperationExecutor();
		$result   = $executor->run( 'report_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_media_enhance( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new OperationExecutor();
		$result   = $executor->run( 'media_enhance', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_media_enhance_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$manager = new \WPCommandCenter\Operations\MediaEnhancementRuntimeManager();
		$result  = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) {
			return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		}
		return new \WP_REST_Response( $result );
	}

	public function run_capability_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new OperationExecutor();
		$result   = $executor->run( 'capability_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	private function request_boolean( \WP_REST_Request $request, string $name, bool $default ): bool {
		$value = $request->get_param( $name );

		if ( null === $value || '' === $value ) {
			return $default;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	public function get_site_intelligence( \WP_REST_Request $request ): \WP_REST_Response {
		$force_refresh = (bool) $request->get_param( 'refresh' );

		return new \WP_REST_Response( ( new SiteScanner() )->scan( $force_refresh ) );
	}

	public function get_diagnostics( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = sanitize_key( (string) $request->get_param( 'type' ) ) ?: 'performance';
		$scan = ( new SiteScanner() )->scan();

		switch ( $type ) {
			case 'performance':
				$checks = ( new PerformanceDiagnostics() )->analyze( $scan );
				break;

			case 'security':
				$checks = ( new SecurityDiagnostics() )->analyze( $scan );
				break;

			case 'woocommerce':
				$checks = ( new WooCommerceDiagnostics() )->analyze();
				break;

			default:
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_type',
					__( 'Invalid diagnostics type. Use performance, security, or woocommerce.', 'wp-command-center' )
				) );
		}

		return new \WP_REST_Response( [ 'type' => $type, 'checks' => $checks ] );
	}

	public function get_debug_log( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$lines  = max( 1, (int) ( $request->get_param( 'lines' ) ?: 200 ) );
		$result = ( new DebugLogViewer() )->tail( $lines );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		$result = $this->redact_text_list( $result, 'lines', 'diagnostics/debug-log', $request );

		return new \WP_REST_Response( $result );
	}

	public function list_files( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$path   = (string) $request->get_param( 'path' );
		$result = ( new FileAccessApi() )->list_directory( $path );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		return new \WP_REST_Response( $result );
	}

	public function read_file( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$path = (string) $request->get_param( 'path' );

		if ( '' === $path ) {
			return $this->with_status( new \WP_Error( 'wpcc_missing_path', __( 'The path parameter is required.', 'wp-command-center' ) ) );
		}

		$result = ( new FileAccessApi() )->read( $path );

		if ( is_wp_error( $result ) ) {
			if ( 'wpcc_file_blocked' === $result->get_error_code() ) {
				$this->record_security_event( $request, 'security.file_blocked', [
					'path'     => $path,
					'endpoint' => 'files/content',
				] );
			}

			return $this->with_status( $result );
		}

		$result = $this->redact_field( $result, 'contents', 'files/content', $request );

		return new \WP_REST_Response( $result );
	}

	public function get_file_meta( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$path = (string) $request->get_param( 'path' );

		if ( '' === $path ) {
			return $this->with_status( new \WP_Error( 'wpcc_missing_path', __( 'The path parameter is required.', 'wp-command-center' ) ) );
		}

		$result = ( new FileAccessApi() )->meta( $path );

		if ( is_wp_error( $result ) ) {
			if ( 'wpcc_file_blocked' === $result->get_error_code() ) {
				$this->record_security_event( $request, 'security.file_blocked', [
					'path'     => $path,
					'endpoint' => 'files/meta',
				] );
			}

			return $this->with_status( $result );
		}

		return new \WP_REST_Response( $result );
	}

	public function search_code( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$query = (string) $request->get_param( 'q' );
		$args  = [
			'path'        => (string) $request->get_param( 'path' ),
			'max_results' => $request->get_param( 'max_results' ) ? (int) $request->get_param( 'max_results' ) : 100,
			'type'        => (string) ( $request->get_param( 'type' ) ?: 'text' ),
		];

		$result = ( new CodeSearch() )->search( $query, $args );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		$result = $this->redact_text_list( $result, 'matches', 'search', $request );

		return new \WP_REST_Response( $result );
	}

	public function create_agent_session( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$source = sanitize_key( (string) ( $request->get_param( 'source' ) ?: 'api' ) );
		$label  = sanitize_text_field( (string) $request->get_param( 'label' ) );

		if ( ! in_array( $source, self::SESSION_SOURCES, true ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_session_source',
				__( 'Invalid session source. Use claude, codex, gpt, api, or manual.', 'wp-command-center' )
			) );
		}

		if ( '' === $label ) {
			return $this->with_status( new \WP_Error(
				'wpcc_missing_session_label',
				__( 'The session label is required.', 'wp-command-center' )
			) );
		}

		$now        = time();
		$expires_at = $request->get_param( 'expires_at' ) ? (int) $request->get_param( 'expires_at' ) : $now + DAY_IN_SECONDS;

		if ( $expires_at <= $now ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_session_expiry',
				__( 'The session expiry must be a future Unix timestamp.', 'wp-command-center' )
			) );
		}

		$session_id = wp_generate_uuid4();
		$inserted   = $wpdb->insert(
			$this->agent_sessions_table(),
			[
				'session_id' => $session_id,
				'source'     => $source,
				'label'      => $label,
				'status'     => self::SESSION_STATUS_ACTIVE,
				'created_at' => $now,
				'updated_at' => $now,
				'expires_at' => $expires_at,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%d' ]
		);

		if ( false === $inserted ) {
			return $this->with_status( new \WP_Error(
				'wpcc_session_create_failed',
				__( 'Failed to create the agent session.', 'wp-command-center' )
			), 500 );
		}

		return new \WP_REST_Response( $this->find_agent_session( $session_id ), 201 );
	}

	public function list_agent_sessions(): \WP_REST_Response {
		global $wpdb;

		$this->expire_agent_sessions();
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->agent_sessions_table() . ' ORDER BY id DESC', ARRAY_A );

		return new \WP_REST_Response( array_map( [ $this, 'normalize_agent_session' ], $rows ?: [] ) );
	}

	public function get_agent_session( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$this->expire_agent_sessions();
		$session_id = (string) $request->get_param( 'id' );
		$session    = $this->find_agent_session( $session_id );

		if ( null === $session ) {
			return $this->with_status( new \WP_Error( 'wpcc_session_not_found', __( 'Agent session not found.', 'wp-command-center' ) ) );
		}

		$session['tasks']   = $this->list_agent_tasks_by( 'session_id', $session_id );
		$session['actions'] = $this->list_agent_actions_by( 'session_id', $session_id );
		$session['plans']   = $this->list_agent_plans_by( 'session_id', $session_id );
		$session['patches'] = $this->list_patches_by( 'session_id', $session_id );

		$response = $this->redact_response( $session, 'agent/sessions/detail', $request );

		return new \WP_REST_Response( $response );
	}

	public function close_agent_session( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$this->expire_agent_sessions();
		$session_id = (string) $request->get_param( 'id' );
		$session    = $this->find_agent_session( $session_id );

		if ( null === $session ) {
			return $this->with_status( new \WP_Error( 'wpcc_session_not_found', __( 'Agent session not found.', 'wp-command-center' ) ) );
		}

		if ( self::SESSION_STATUS_CLOSED === $session['status'] ) {
			return new \WP_REST_Response( $session );
		}

		if ( self::SESSION_STATUS_ACTIVE !== $session['status'] ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_session_status',
				__( 'Only active agent sessions can be closed.', 'wp-command-center' )
			) );
		}

		$updated = $wpdb->update(
			$this->agent_sessions_table(),
			[ 'status' => self::SESSION_STATUS_CLOSED, 'updated_at' => time() ],
			[ 'session_id' => $session_id ],
			[ '%s', '%d' ],
			[ '%s' ]
		);

		if ( false === $updated ) {
			return $this->with_status( new \WP_Error(
				'wpcc_session_close_failed',
				__( 'Failed to close the agent session.', 'wp-command-center' )
			), 500 );
		}

		( new AuditLog() )->record( 'session.status_updated', [
			'session_id'      => $session_id,
			'previous_status' => $session['status'],
			'status'          => self::SESSION_STATUS_CLOSED,
			'actor'           => AuditLog::resolve_actor( $this->token_actor( $request ) ),
		] );

		return new \WP_REST_Response( $this->find_agent_session( $session_id ) );
	}

	private function agent_sessions_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpcc_agent_sessions';
	}

	private function list_agent_tasks_by( ?string $field = null, ?string $value = null ): array {
		global $wpdb;

		$table = $this->agent_tasks_table();
		$sql   = "SELECT * FROM {$table}";

		if ( null !== $field && in_array( $field, [ 'session_id' ], true ) && null !== $value ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$field} = %s ORDER BY id DESC", $value );
		} else {
			$sql .= ' ORDER BY id DESC';
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'normalize_agent_task' ], $rows ?: [] );
	}

	private function list_patches_by( string $field, string $value ): array {
		return ( new PatchManager() )->list_by( $field, $value );
	}

	private function expire_agent_sessions(): void {
		global $wpdb;

		$now   = time();
		$table = $this->agent_sessions_table();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = %s, updated_at = %d WHERE status = %s AND expires_at <= %d",
			self::SESSION_STATUS_EXPIRED,
			$now,
			self::SESSION_STATUS_ACTIVE,
			$now
		) );
	}

	private function find_agent_session( string $session_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->agent_sessions_table() . ' WHERE session_id = %s', $session_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_agent_session( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_agent_session( array $row ): array {
		foreach ( [ 'id', 'created_at', 'updated_at', 'expires_at' ] as $integer_field ) {
			$row[ $integer_field ] = (int) $row[ $integer_field ];
		}

		return $row;
	}

	public function create_agent_task( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$source      = sanitize_key( (string) ( $request->get_param( 'source' ) ?: 'api' ) );
		$user_prompt = sanitize_textarea_field( (string) $request->get_param( 'user_prompt' ) );

		if ( null === $this->find_agent_session( $session_id ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_session_not_found',
				__( 'Agent session not found.', 'wp-command-center' )
			) );
		}

		if ( ! in_array( $source, self::SESSION_SOURCES, true ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_task_source',
				__( 'Invalid task source. Use claude, codex, gpt, api, or manual.', 'wp-command-center' )
			) );
		}

		if ( '' === $user_prompt ) {
			return $this->with_status( new \WP_Error(
				'wpcc_missing_user_prompt',
				__( 'The user prompt is required.', 'wp-command-center' )
			) );
		}

		$task_id  = wp_generate_uuid4();
		$now      = time();
		$inserted = $wpdb->insert(
			$this->agent_tasks_table(),
			[
				'task_id'     => $task_id,
				'session_id'  => $session_id,
				'source'      => $source,
				'user_prompt' => $user_prompt,
				'status'      => self::TASK_STATUS_DRAFT,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
		);

		if ( false === $inserted ) {
			return $this->with_status( new \WP_Error(
				'wpcc_task_create_failed',
				__( 'Failed to create the agent task.', 'wp-command-center' )
			), 500 );
		}

		( new AuditLog() )->record( 'task.created', [
			'task_id'     => $task_id,
			'session_id'  => $session_id,
			'source'      => $source,
			'user_prompt' => $user_prompt,
			'status'      => self::TASK_STATUS_DRAFT,
			'actor'       => AuditLog::resolve_actor( $this->token_actor( $request ) ),
		] );

		return new \WP_REST_Response( $this->find_agent_task( $task_id ), 201 );
	}

	public function list_agent_tasks(): \WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->agent_tasks_table() . ' ORDER BY id DESC', ARRAY_A );

		return new \WP_REST_Response( array_map( [ $this, 'normalize_agent_task' ], $rows ?: [] ) );
	}

	public function get_agent_task( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$task_id = (string) $request->get_param( 'id' );
		$task    = $this->find_agent_task( $task_id );

		if ( null === $task ) {
			return $this->with_status( new \WP_Error( 'wpcc_task_not_found', __( 'Agent task not found.', 'wp-command-center' ) ) );
		}

		$task['session'] = $this->find_agent_session( $task['session_id'] );
		$task['actions'] = $this->list_agent_actions_by( 'task_id', $task_id );
		$task['plans']   = $this->list_agent_plans_by( 'task_id', $task_id );
		$task['patches'] = $this->list_patches_by( 'task_id', $task_id );

		$response = $this->redact_response( $task, 'agent/tasks/detail', $request );

		return new \WP_REST_Response( $response );
	}

	public function update_agent_task_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$task_id = (string) $request->get_param( 'id' );
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );
		$task    = $this->find_agent_task( $task_id );

		if ( null === $task ) {
			return $this->with_status( new \WP_Error( 'wpcc_task_not_found', __( 'Agent task not found.', 'wp-command-center' ) ) );
		}

		if ( ! in_array( $status, self::TASK_STATUSES, true ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_task_status',
				__( 'Invalid task status.', 'wp-command-center' )
			) );
		}

		if ( $status === $task['status'] ) {
			return new \WP_REST_Response( $task );
		}

		$updated = $wpdb->update(
			$this->agent_tasks_table(),
			[ 'status' => $status, 'updated_at' => time() ],
			[ 'task_id' => $task_id ],
			[ '%s', '%d' ],
			[ '%s' ]
		);

		if ( false === $updated ) {
			return $this->with_status( new \WP_Error(
				'wpcc_task_update_failed',
				__( 'Failed to update the agent task.', 'wp-command-center' )
			), 500 );
		}

		( new AuditLog() )->record( 'task.status_updated', [
			'task_id'        => $task_id,
			'session_id'     => $task['session_id'],
			'previous_status' => $task['status'],
			'status'          => $status,
			'actor'           => AuditLog::resolve_actor( $this->token_actor( $request ) ),
		] );

		return new \WP_REST_Response( $this->find_agent_task( $task_id ) );
	}

	private function agent_tasks_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpcc_agent_tasks';
	}

	private function find_agent_task( string $task_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->agent_tasks_table() . ' WHERE task_id = %s', $task_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_agent_task( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_agent_task( array $row ): array {
		foreach ( [ 'id', 'created_at', 'updated_at' ] as $integer_field ) {
			$row[ $integer_field ] = (int) $row[ $integer_field ];
		}

		return $row;
	}

	/**
	 * Record a new agent action: { session_id, task_id, type, title, description? }.
	 * Always created with status=proposed. Metadata only — does not execute
	 * anything or create a patch.
	 */
	public function create_agent_action( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$task_id     = sanitize_text_field( (string) $request->get_param( 'task_id' ) );
		$type        = sanitize_key( (string) $request->get_param( 'type' ) );
		$title       = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );

		$relationship = $this->validate_session_task_relationship( $session_id, $task_id );

		if ( is_wp_error( $relationship ) ) {
			return $this->with_status( $relationship );
		}

		if ( ! in_array( $type, self::ACTION_TYPES, true ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_action_type',
				__( 'Invalid action type. Use investigate, recommendation, diagnosis, code_change, configuration_change, or maintenance.', 'wp-command-center' )
			) );
		}

		if ( '' === $title ) {
			return $this->with_status( new \WP_Error(
				'wpcc_missing_action_title',
				__( 'The action title is required.', 'wp-command-center' )
			) );
		}

		$action_id = wp_generate_uuid4();
		$now       = time();
		$inserted  = $wpdb->insert(
			$this->agent_actions_table(),
			[
				'action_id'   => $action_id,
				'session_id'  => $session_id,
				'task_id'     => $task_id,
				'type'        => $type,
				'title'       => $title,
				'description' => $description,
				'status'      => self::ACTION_STATUS_PROPOSED,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
		);

		if ( false === $inserted ) {
			return $this->with_status( new \WP_Error(
				'wpcc_action_create_failed',
				__( 'Failed to create the agent action.', 'wp-command-center' )
			), 500 );
		}

		( new AuditLog() )->record( 'action.created', [
			'action_id'   => $action_id,
			'session_id'  => $session_id,
			'task_id'     => $task_id,
			'type'        => $type,
			'title'       => $title,
			'description' => $description,
			'status'      => self::ACTION_STATUS_PROPOSED,
			'actor'       => AuditLog::resolve_actor( $this->token_actor( $request ) ),
		] );

		return new \WP_REST_Response( $this->find_agent_action( $action_id ), 201 );
	}

	public function list_agent_actions(): \WP_REST_Response {
		return new \WP_REST_Response( $this->list_agent_actions_by() );
	}

	public function get_agent_action( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$action_id = (string) $request->get_param( 'id' );
		$action    = $this->find_agent_action( $action_id );

		if ( null === $action ) {
			return $this->with_status( new \WP_Error( 'wpcc_action_not_found', __( 'Agent action not found.', 'wp-command-center' ) ) );
		}

		$action['task']    = $this->find_agent_task( $action['task_id'] );
		$action['plans']   = $this->list_agent_plans_by( 'action_id', $action_id );
		$action['patches'] = [];

		foreach ( $action['plans'] as $plan ) {
			$action['patches'] = array_merge( $action['patches'], $this->list_patches_by( 'plan_id', $plan['plan_id'] ) );
		}

		$response = $this->redact_response( $action, 'agent/actions/detail', $request );

		return new \WP_REST_Response( $response );
	}

	/**
	 * Handle accept/reject/cancel/complete transitions for an agent action.
	 * No automatic execution and no automatic patch creation — these
	 * transitions only update the action's status and audit trail.
	 */
	private function run_agent_action_transition( \WP_REST_Request $request, string $transition ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$action_id = (string) $request->get_param( 'id' );
		$action    = $this->find_agent_action( $action_id );

		if ( null === $action ) {
			return $this->with_status( new \WP_Error( 'wpcc_action_not_found', __( 'Agent action not found.', 'wp-command-center' ) ) );
		}

		if ( 'accept' === $transition ) {
			if ( ! in_array( $action['status'], self::ACTION_ACCEPTABLE_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_action_status',
					__( 'Only proposed actions can be accepted.', 'wp-command-center' )
				) );
			}

			$status = self::ACTION_STATUS_ACCEPTED;
			$event  = 'action.accepted';
		} elseif ( 'reject' === $transition ) {
			if ( ! in_array( $action['status'], self::ACTION_REJECTABLE_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_action_status',
					__( 'Only proposed actions can be rejected.', 'wp-command-center' )
				) );
			}

			$status = self::ACTION_STATUS_REJECTED;
			$event  = 'action.rejected';
		} elseif ( 'cancel' === $transition ) {
			if ( ! in_array( $action['status'], self::ACTION_CANCELLABLE_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_action_status',
					__( 'Only proposed or accepted actions can be cancelled.', 'wp-command-center' )
				) );
			}

			$status = self::ACTION_STATUS_CANCELLED;
			$event  = 'action.cancelled';
		} elseif ( 'complete' === $transition ) {
			if ( ! in_array( $action['status'], self::ACTION_COMPLETABLE_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_action_status',
					__( 'Only accepted actions can be completed.', 'wp-command-center' )
				) );
			}

			$status = self::ACTION_STATUS_COMPLETED;
			$event  = 'action.completed';
		} else {
			return $this->with_status( new \WP_Error( 'wpcc_invalid_agent_action', __( 'Invalid agent action.', 'wp-command-center' ) ) );
		}

		$updated = $wpdb->update(
			$this->agent_actions_table(),
			[ 'status' => $status, 'updated_at' => time() ],
			[ 'action_id' => $action_id ],
			[ '%s', '%d' ],
			[ '%s' ]
		);

		if ( false === $updated ) {
			return $this->with_status( new \WP_Error(
				'wpcc_action_update_failed',
				__( 'Failed to update the agent action.', 'wp-command-center' )
			), 500 );
		}

		( new AuditLog() )->record( $event, [
			'action_id'       => $action_id,
			'session_id'      => $action['session_id'],
			'task_id'         => $action['task_id'],
			'previous_status' => $action['status'],
			'status'          => $status,
			'actor'           => AuditLog::resolve_actor( $this->token_actor( $request ) ),
		] );

		return new \WP_REST_Response( $this->find_agent_action( $action_id ) );
	}

	private function agent_actions_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpcc_agent_actions';
	}

	private function find_agent_action( string $action_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->agent_actions_table() . ' WHERE action_id = %s', $action_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_agent_action( $row ) : null;
	}

	private function list_agent_actions_by( ?string $field = null, ?string $value = null ): array {
		global $wpdb;

		$table = $this->agent_actions_table();
		$sql   = "SELECT * FROM {$table}";

		if ( null !== $field && in_array( $field, [ 'session_id', 'task_id' ], true ) && null !== $value ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$field} = %s ORDER BY id DESC", $value );
		} else {
			$sql .= ' ORDER BY id DESC';
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'normalize_agent_action' ], $rows ?: [] );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_agent_action( array $row ): array {
		foreach ( [ 'id', 'created_at', 'updated_at' ] as $integer_field ) {
			$row[ $integer_field ] = (int) $row[ $integer_field ];
		}

		return $row;
	}

	public function create_agent_plan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$session_id      = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$task_id         = sanitize_text_field( (string) $request->get_param( 'task_id' ) );
		$title           = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$objective       = sanitize_textarea_field( (string) $request->get_param( 'objective' ) );
		$status          = sanitize_key( (string) ( $request->get_param( 'status' ) ?: self::PLAN_STATUS_PENDING_REVIEW ) );
		$steps           = $request->get_param( 'steps' );
		$action_id_param = sanitize_text_field( (string) $request->get_param( 'action_id' ) );
		$action_id       = '' !== $action_id_param ? $action_id_param : null;

		$relationship = $this->validate_session_task_relationship( $session_id, $task_id );

		if ( is_wp_error( $relationship ) ) {
			return $this->with_status( $relationship );
		}

		if ( null !== $action_id && null === $this->find_agent_action( $action_id ) ) {
			return $this->with_status( new \WP_Error( 'wpcc_action_not_found', __( 'Agent action not found.', 'wp-command-center' ) ) );
		}

		if ( '' === $title || '' === $objective ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_plan',
				__( 'Plan title and objective are required.', 'wp-command-center' )
			) );
		}

		if ( ! in_array( $status, self::PLAN_CREATE_STATUSES, true ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_plan_status',
				__( 'New plans must be draft or pending_review.', 'wp-command-center' )
			) );
		}

		if ( ! is_array( $steps ) || empty( $steps ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_invalid_plan_steps',
				__( 'A plan must include at least one step.', 'wp-command-center' )
			) );
		}

		$normalized_steps = [];

		foreach ( array_values( $steps ) as $index => $step ) {
			$step_title  = sanitize_text_field( (string) ( $step['title'] ?? '' ) );
			$description = sanitize_textarea_field( (string) ( $step['description'] ?? '' ) );
			$step_status = sanitize_key( (string) ( $step['status'] ?? self::PLAN_STEP_STATUS_PENDING ) );

			if ( '' === $step_title || ! in_array( $step_status, self::PLAN_STEP_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_plan_step',
					__( 'Each plan step needs a title and a valid status.', 'wp-command-center' )
				) );
			}

			$normalized_steps[] = [
				'step_order'  => $index + 1,
				'title'       => $step_title,
				'description' => $description,
				'status'      => $step_status,
			];
		}

		$plan_id = wp_generate_uuid4();
		$now     = time();
		$wpdb->query( 'START TRANSACTION' );

		$inserted = $wpdb->insert(
			$this->agent_plans_table(),
			[
				'plan_id'    => $plan_id,
				'session_id' => $session_id,
				'task_id'    => $task_id,
				'action_id'  => $action_id,
				'title'      => $title,
				'objective'  => $objective,
				'status'     => $status,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );

			return $this->with_status( new \WP_Error(
				'wpcc_plan_create_failed',
				__( 'Failed to create the agent plan.', 'wp-command-center' )
			), 500 );
		}

		foreach ( $normalized_steps as $step ) {
			$step_inserted = $wpdb->insert(
				$this->agent_plan_steps_table(),
				array_merge( [ 'plan_id' => $plan_id ], $step ),
				[ '%s', '%d', '%s', '%s', '%s' ]
			);

			if ( false === $step_inserted ) {
				$wpdb->query( 'ROLLBACK' );

				return $this->with_status( new \WP_Error(
					'wpcc_plan_step_create_failed',
					__( 'Failed to create an agent plan step.', 'wp-command-center' )
				), 500 );
			}
		}

		$wpdb->query( 'COMMIT' );

		( new AuditLog() )->record( 'plan.created', [
			'plan_id'    => $plan_id,
			'session_id' => $session_id,
			'task_id'    => $task_id,
			'action_id'  => $action_id,
			'title'      => $title,
			'objective'  => $objective,
			'status'     => $status,
			'actor'      => AuditLog::resolve_actor( $this->token_actor( $request ) ),
		] );

		return new \WP_REST_Response( $this->find_agent_plan( $plan_id ), 201 );
	}

	public function list_agent_plans(): \WP_REST_Response {
		return new \WP_REST_Response( $this->list_agent_plans_by() );
	}

	public function get_agent_plan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$plan_id = (string) $request->get_param( 'id' );
		$plan    = $this->find_agent_plan( $plan_id );

		if ( null === $plan ) {
			return $this->with_status( new \WP_Error( 'wpcc_plan_not_found', __( 'Agent plan not found.', 'wp-command-center' ) ) );
		}

		$plan['session'] = $this->find_agent_session( $plan['session_id'] );
		$plan['task']    = $this->find_agent_task( $plan['task_id'] );
		$plan['action']  = $plan['action_id'] ? $this->find_agent_action( $plan['action_id'] ) : null;
		$plan['patches'] = $this->list_patches_by( 'plan_id', $plan_id );

		$response = $this->redact_response( $plan, 'agent/plans/detail', $request );

		return new \WP_REST_Response( $response );
	}

	private function run_agent_plan_action( \WP_REST_Request $request, string $action ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$plan_id = (string) $request->get_param( 'id' );
		$plan    = $this->find_agent_plan( $plan_id );

		if ( null === $plan ) {
			return $this->with_status( new \WP_Error( 'wpcc_plan_not_found', __( 'Agent plan not found.', 'wp-command-center' ) ) );
		}

		if ( 'approve' === $action ) {
			if ( ! in_array( $plan['status'], self::PLAN_APPROVABLE_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_plan_status',
					__( 'Only pending_review or draft plans can be approved.', 'wp-command-center' )
				) );
			}

			$status = self::PLAN_STATUS_APPROVED;
			$event  = 'plan.approved';
		} elseif ( 'reject' === $action ) {
			if ( ! in_array( $plan['status'], self::PLAN_REJECTABLE_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_plan_status',
					__( 'Only pending_review or draft plans can be rejected.', 'wp-command-center' )
				) );
			}

			$status = self::PLAN_STATUS_REJECTED;
			$event  = 'plan.rejected';
		} elseif ( 'cancel' === $action ) {
			if ( ! in_array( $plan['status'], self::PLAN_CANCELLABLE_STATUSES, true ) ) {
				return $this->with_status( new \WP_Error(
					'wpcc_invalid_plan_status',
					__( 'Only draft, pending_review, or approved plans can be cancelled.', 'wp-command-center' )
				) );
			}

			$status = self::PLAN_STATUS_CANCELLED;
			$event  = 'plan.cancelled';
		} else {
			return $this->with_status( new \WP_Error( 'wpcc_invalid_plan_action', __( 'Invalid plan action.', 'wp-command-center' ) ) );
		}

		$updated = $wpdb->update(
			$this->agent_plans_table(),
			[ 'status' => $status, 'updated_at' => time() ],
			[ 'plan_id' => $plan_id ],
			[ '%s', '%d' ],
			[ '%s' ]
		);

		if ( false === $updated ) {
			return $this->with_status( new \WP_Error(
				'wpcc_plan_update_failed',
				__( 'Failed to update the agent plan.', 'wp-command-center' )
			), 500 );
		}

		( new AuditLog() )->record( $event, [
			'plan_id'        => $plan_id,
			'session_id'     => $plan['session_id'],
			'task_id'        => $plan['task_id'],
			'previous_status' => $plan['status'],
			'status'          => $status,
			'actor'           => AuditLog::resolve_actor( $this->token_actor( $request ) ),
		] );

		if ( 'approve' === $action ) {
			( new RecommendationEngine() )->sync_plan_status( $plan_id, 'approved', $this->token_actor( $request ) );
		}

		return new \WP_REST_Response( $this->find_agent_plan( $plan_id ) );
	}

	private function validate_session_task_relationship( string $session_id, string $task_id ): bool|\WP_Error {
		$session = $this->find_agent_session( $session_id );

		if ( null === $session ) {
			return new \WP_Error( 'wpcc_session_not_found', __( 'Agent session not found.', 'wp-command-center' ) );
		}

		$task = $this->find_agent_task( $task_id );

		if ( null === $task ) {
			return new \WP_Error( 'wpcc_task_not_found', __( 'Agent task not found.', 'wp-command-center' ) );
		}

		if ( $task['session_id'] !== $session_id ) {
			return new \WP_Error(
				'wpcc_task_session_mismatch',
				__( 'The agent task does not belong to the supplied session.', 'wp-command-center' )
			);
		}

		return true;
	}

	private function agent_plans_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpcc_agent_plans';
	}

	private function agent_plan_steps_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpcc_agent_plan_steps';
	}

	private function find_agent_plan( string $plan_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->agent_plans_table() . ' WHERE plan_id = %s', $plan_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_agent_plan( $row ) : null;
	}

	private function list_agent_plans_by( ?string $field = null, ?string $value = null ): array {
		global $wpdb;

		$table = $this->agent_plans_table();
		$sql   = "SELECT * FROM {$table}";

		if ( null !== $field && in_array( $field, [ 'session_id', 'task_id', 'action_id' ], true ) && null !== $value ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$field} = %s ORDER BY id DESC", $value );
		} else {
			$sql .= ' ORDER BY id DESC';
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'normalize_agent_plan' ], $rows ?: [] );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_agent_plan( array $row ): array {
		global $wpdb;

		foreach ( [ 'id', 'created_at', 'updated_at' ] as $integer_field ) {
			$row[ $integer_field ] = (int) $row[ $integer_field ];
		}

		$steps = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . $this->agent_plan_steps_table() . ' WHERE plan_id = %s ORDER BY step_order ASC', $row['plan_id'] ),
			ARRAY_A
		);

		$row['steps'] = array_map(
			static function ( array $step ): array {
				$step['id']         = (int) $step['id'];
				$step['step_order'] = (int) $step['step_order'];

				return $step;
			},
			$steps ?: []
		);

		return $row;
	}

	public function list_patches(): \WP_REST_Response {
		return new \WP_REST_Response( ( new PatchManager() )->list() );
	}

	public function get_patch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = ( new PatchManager() )->get( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		$result['session_id'] ??= null;
		$result['task_id']    ??= null;
		$result['plan_id']    ??= null;
		$result['action_id']    = null;

		if ( $result['plan_id'] ) {
			$plan = $this->find_agent_plan( $result['plan_id'] );
			if ( $plan ) {
				$result['action_id'] = $plan['action_id'];
			}
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Create a new patch. Body: { files: [{path, modified}], explanation,
	 * risk_level, source, session_id, task_id }. Relationships are optional;
	 * `source` defaults to "api".
	 */
	public function create_patch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_param( 'files' );

		if ( ! is_array( $files ) || empty( $files ) ) {
			return $this->with_status( new \WP_Error(
				'wpcc_no_files',
				__( 'The "files" parameter must be a non-empty array of {path, modified}.', 'wp-command-center' )
			) );
		}

		$normalized = [];

		foreach ( $files as $file ) {
			$normalized[] = [
				'path'     => isset( $file['path'] ) ? sanitize_text_field( (string) $file['path'] ) : '',
				'modified' => isset( $file['modified'] ) ? (string) $file['modified'] : '',
			];
		}

		$explanation = sanitize_textarea_field( (string) $request->get_param( 'explanation' ) );
		$risk_level  = sanitize_key( (string) ( $request->get_param( 'risk_level' ) ?: PatchManager::RISK_LOW ) );
		$source      = sanitize_key( (string) ( $request->get_param( 'source' ) ?: PatchManager::SOURCE_API ) );
		$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) ) ?: null;
		$task_id     = sanitize_text_field( (string) $request->get_param( 'task_id' ) ) ?: null;
		$plan_id     = sanitize_text_field( (string) $request->get_param( 'plan_id' ) ) ?: null;

		$result = ( new PatchManager() )->create( $normalized, $explanation, $risk_level, $source, $this->token_actor( $request ), $session_id, $task_id, $plan_id );

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		return new \WP_REST_Response( $result, 201 );
	}

	private function run_patch_action( \WP_REST_Request $request, string $action ): \WP_REST_Response|\WP_Error {
		$id      = (string) $request->get_param( 'id' );
		$service = new PatchApproval();
		$actor   = $this->token_actor( $request );
		$patch   = ( new PatchManager() )->get( $id );

		if ( 'apply' === $action && ! empty( $patch['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $patch['plan_id'], 'executing', $actor );
		}

		$result = match ( $action ) {
			'approve'  => $service->approve( $id, $actor ),
			'reject'   => $service->reject( $id, $actor ),
			'apply'    => $service->apply( $id, $actor ),
			'rollback' => $service->rollback( $id, $actor ),
			default    => new \WP_Error( 'wpcc_invalid_action', __( 'Invalid action.', 'wp-command-center' ) ),
		};

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		if ( 'apply' === $action && ! empty( $patch['plan_id'] ) ) {
			( new RecommendationEngine() )->sync_plan_status( $patch['plan_id'], 'resolved', $actor );
		}

		return new \WP_REST_Response( $result );
	}

	public function get_claude_config( \WP_REST_Request $request ): \WP_REST_Response {
		$config = ClaudeIntegration::generate_mcp_config();
		ClaudeIntegration::audit( 'claude.config.generated', [
			'endpoint' => rest_url( self::NAMESPACE . '/mcp' ),
		], [ 'actor' => $this->token_actor( $request ) ] );

		return new \WP_REST_Response( $config );
	}

	public function get_claude_discovery( \WP_REST_Request $request ): \WP_REST_Response {
		$data = ClaudeIntegration::get_discovery_metadata();
		ClaudeIntegration::audit( 'claude.discovery', [
			'tool_count'  => count( $data['tools'] ),
			'group_count' => count( $data['tool_groups'] ),
		], [ 'actor' => $this->token_actor( $request ) ] );

		return new \WP_REST_Response( $this->redact_response( $data, 'claude/discovery', $request ) );
	}

	public function get_claude_tools( \WP_REST_Request $request ): \WP_REST_Response {
		$data = [
			'tool_groups' => ClaudeIntegration::get_tool_groups(),
			'meta'        => [
				'group_count' => count( ClaudeIntegration::TOOL_GROUPS ),
				'read_only'   => true,
			],
		];

		return new \WP_REST_Response( $data );
	}

	public function get_claude_prompts( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( ClaudeIntegration::get_prompt_templates() );
	}

	public function list_ai_clients( \WP_REST_Request $request ): \WP_REST_Response {
		$all     = AIClientRegistry::get_clients();
		$clients = [];
		foreach ( $all as $id => $c ) {
			$clients[ $id ] = [
				'id'                   => $id,
				'name'                 => $c['name'],
				'vendor'               => $c['vendor'],
				'type'                 => $c['type'],
				'status'               => $c['status'],
				'certification_level'  => $c['certification_level'] ?? $c['status'],
				'certification_label'  => AIClientRegistry::CERT_LABELS[ $c['certification_level'] ?? $c['status'] ] ?? $c['status'],
				'last_validated_at'    => $c['last_validated_at'] ?? null,
				'compatible'           => $c['compatible'],
				'mcp_support'          => $c['mcp_support'],
			];
		}
		return new \WP_REST_Response( [
			'clients'              => $clients,
			'compatibility_matrix' => AIClientRegistry::get_compatibility_matrix(),
			'counts'               => AIClientRegistry::get_counts(),
			'note'                 => 'All clients connect through the same MCP endpoint. No per-client runtimes.',
		] );
	}

	public function get_ai_client_config( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client_id = sanitize_key( (string) $request->get_param( 'client' ) );
		$client    = AIClientRegistry::get_client( $client_id );

		if ( ! $client ) {
			return $this->with_status( new \WP_Error(
				'wpcc_client_not_found',
				sprintf( __( 'AI Client "%s" not found.', 'wp-command-center' ), $client_id ),
			), 404 );
		}

		if ( ! $client['config_generator'] ) {
			return $this->with_status( new \WP_Error(
				'wpcc_client_not_configured',
				sprintf( __( 'Configuration generator not yet implemented for "%s".', 'wp-command-center' ), $client['name'] ),
			), 501 );
		}

		$config = call_user_func( $client['config_generator'] );

		ClaudeIntegration::audit( 'ai_client.config.generated', [
			'client'   => $client_id,
			'endpoint' => rest_url( self::NAMESPACE . '/mcp' ),
		], [ 'actor' => $this->token_actor( $request ) ] );

		return new \WP_REST_Response( [
			'client' => $client_id,
			'name'   => $client['name'],
			'config' => $config,
		] );
	}

	public function run_user_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'user_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_user_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$manager = new \WPCommandCenter\Operations\UserManager();
		$result  = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) {
			return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		}
		return new \WP_REST_Response( $result );
	}

	public function run_media_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'media_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_media_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$manager = new \WPCommandCenter\Operations\MediaRuntimeManager();
		$result  = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) {
			return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		}
		return new \WP_REST_Response( $result );
	}

	public function run_woocommerce_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'woocommerce_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}
	public function run_woocommerce_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
		}
		$manager = new \WPCommandCenter\Operations\WooCommerceRuntimeManager();
		$result  = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		return new \WP_REST_Response( $result );
	}
	public function run_acf_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$executor = new \WPCommandCenter\Operations\OperationExecutor(); $result = $executor->run( 'acf_manage', $params, $context );
		if ( ! $result['success'] ) { $error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ]; return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) ); }
		return new \WP_REST_Response( $result['result'] );
	}
	public function run_acf_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$manager = new \WPCommandCenter\Operations\ACFRuntimeManager(); $result = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		return new \WP_REST_Response( $result );
	}
	public function run_forms_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$executor = new \WPCommandCenter\Operations\OperationExecutor(); $result = $executor->run( 'forms_manage', $params, $context );
		if ( ! $result['success'] ) { $error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ]; return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) ); }
		return new \WP_REST_Response( $result['result'] );
	}
	public function run_forms_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$manager = new \WPCommandCenter\Operations\FormsRuntimeManager(); $result = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		return new \WP_REST_Response( $result );
	}
	public function run_menu_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$executor = new \WPCommandCenter\Operations\OperationExecutor(); $result = $executor->run( 'menu_manage', $params, $context );
		if ( ! $result['success'] ) { $error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ]; return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) ); }
		return new \WP_REST_Response( $result['result'] );
	}
	public function run_menu_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$manager = new \WPCommandCenter\Operations\MenuRuntimeManager(); $result = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		return new \WP_REST_Response( $result );
	}

	public function run_settings_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$executor = new \WPCommandCenter\Operations\OperationExecutor(); $result = $executor->run( 'settings_manage', $params, $context );
		if ( ! $result['success'] ) { $error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ]; return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) ); }
		return new \WP_REST_Response( $result['result'] );
	}
	public function run_settings_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$manager = new \WPCommandCenter\Operations\SettingsRuntimeManager(); $result = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		return new \WP_REST_Response( $result );
	}

	public function run_search_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params(); $context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) { if ( $request->get_param( $k ) ) $context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) ); }
		$executor = new \WPCommandCenter\Operations\OperationExecutor(); $result = $executor->run( 'search_manage', $params, $context );
		if ( ! $result['success'] ) { $error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ]; return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) ); }
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_bulk_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'bulk_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_bulk_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$manager = new \WPCommandCenter\Operations\BulkRuntimeManager();
		$result  = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) {
			return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		}
		return new \WP_REST_Response( $result );
	}

	public function run_workflow_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'workflow_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_comments_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'comments_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}
	public function run_comments_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$manager = new CommentsRuntimeManager();
		$result  = $manager->rollback( $params, $context );
		if ( isset( $result['error'] ) && $result['error'] ) {
			return $this->with_status( new \WP_Error( $result['code'], $result['message'] ) );
		}
		return new \WP_REST_Response( $result );
	}

	public function run_widgets_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'widgets_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_widgets_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = [ 'action' => 'widgets_rollback', 'rollback_id' => sanitize_text_field( (string) $request->get_param( 'rollback_id' ) ) ];
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'widgets_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_cpt_manage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'cpt_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}

	public function run_cpt_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = [ 'action' => 'cpt_rollback', 'rollback_id' => sanitize_text_field( (string) $request->get_param( 'rollback_id' ) ) ];
		$context = [ 'actor' => $this->token_actor( $request ) ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id' ] as $k ) {
			if ( $request->get_param( $k ) ) {
				$context[ $k ] = sanitize_text_field( (string) $request->get_param( $k ) );
			}
		}
		$executor = new \WPCommandCenter\Operations\OperationExecutor();
		$result   = $executor->run( 'cpt_manage', $params, $context );
		if ( ! $result['success'] ) {
			$error = $result['errors'][0] ?? [ 'code' => 'execution_failed', 'message' => 'Unknown error' ];
			return $this->with_status( new \WP_Error( $error['code'], $error['message'] ) );
		}
		return new \WP_REST_Response( $result['result'] );
	}
}
