<?php
/**
 * Phase 4 — design-partner readiness (read-only).
 *
 * Answers one question honestly: "Can I run the first governed AI change now?" It is a
 * side-effect-free snapshot built from REAL state (security mode, provider key + last
 * test, enabled tools, test content, history availability). It performs NO writes, NO
 * external calls, and NEVER returns a secret. Consumed by the Home first-value panel
 * and the Built-in AI screen. Adds no routes, operations, capabilities, MCP tools, or
 * schema.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class DesignPartnerReadiness {

	/**
	 * The readiness checklist. Each item: key · label · status (pass|warning|blocked) ·
	 * detail · optional next action (label + url).
	 *
	 * @return array<int,array{key:string,label:string,status:string,detail:string,action_label:string,action_url:string}>
	 */
	public static function checklist(): array {
		$providers_url = admin_url( 'admin.php?page=wpcc-built-in-ai&wpcc_tab=providers' );
		$security_url  = admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=security' );
		$history_url   = admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes' );
		$approvals_url = admin_url( 'admin.php?page=wpcc-activity&wpcc_tab=approvals' );

		$client_safe = SecurityModeManager::requires_human_approver();
		$configured  = AdoptionStatus::ai_configured();
		$test        = AiSetupController::last_test();
		$tested      = is_array( $test ) && ! empty( $test['ok'] );
		$any_tool    = self::any_tool_enabled();
		$content     = self::test_content();

		$items = [];

		// 1. Approvals on (Client-safe mode).
		$items[] = [
			'key'          => 'security_mode',
			'label'        => __( 'Approvals are on (Client-safe mode)', 'wp-command-center' ),
			'status'       => $client_safe ? 'pass' : 'warning',
			'detail'       => $client_safe
				? __( 'Every AI change waits for your approval before it applies.', 'wp-command-center' )
				: __( 'You’re in Developer mode, which applies AI writes without approval. Switch to Client-safe mode before working on a real site.', 'wp-command-center' ),
			'action_label' => $client_safe ? '' : __( 'Set Client-safe mode', 'wp-command-center' ),
			'action_url'   => $security_url,
		];

		// 2. Provider connected.
		$items[] = [
			'key'          => 'provider_connected',
			'label'        => __( 'AI provider connected', 'wp-command-center' ),
			'status'       => $configured ? 'pass' : 'blocked',
			'detail'       => $configured
				? __( 'A provider key is configured.', 'wp-command-center' )
				: __( 'No provider yet. Add your AI provider key to start.', 'wp-command-center' ),
			'action_label' => $configured ? '' : __( 'Connect a provider', 'wp-command-center' ),
			'action_url'   => $providers_url,
		];

		// 3. Provider tested.
		$items[] = [
			'key'          => 'provider_tested',
			'label'        => __( 'Provider tested', 'wp-command-center' ),
			'status'       => $tested ? 'pass' : ( $configured ? 'warning' : 'blocked' ),
			'detail'       => $tested
				? __( 'Your provider key passed a connection test.', 'wp-command-center' )
				: __( 'Run “Test connection” on your provider to confirm the key works.', 'wp-command-center' ),
			'action_label' => $tested ? '' : __( 'Test the connection', 'wp-command-center' ),
			'action_url'   => $providers_url,
		];

		// 4. Generation supported (honest provider reality).
		$items[] = [
			'key'          => 'generation_supported',
			'label'        => __( 'Generation supported', 'wp-command-center' ),
			'status'       => $configured ? 'pass' : 'blocked',
			'detail'       => __( 'Generation runs on the provider you set as the default — Anthropic (Claude) or an OpenAI-compatible provider. Other providers can be connected and tested, but only the one you select will generate.', 'wp-command-center' ),
			'action_label' => '',
			'action_url'   => $providers_url,
		];

		// 5. At least one built-in AI tool enabled.
		$items[] = [
			'key'          => 'tool_enabled',
			'label'        => __( 'A built-in AI tool is on', 'wp-command-center' ),
			'status'       => $any_tool ? 'pass' : 'blocked',
			'detail'       => $any_tool
				? __( 'At least one tool (SEO, Alt Text, or Content) is turned on.', 'wp-command-center' )
				: __( 'Turn on SEO, Alt Text, or Content under Built-in AI › Providers.', 'wp-command-center' ),
			'action_label' => $any_tool ? '' : __( 'Turn on a tool', 'wp-command-center' ),
			'action_url'   => $providers_url,
		];

		// 6. Test content available.
		$items[] = [
			'key'          => 'test_content',
			'label'        => __( 'Test content available', 'wp-command-center' ),
			'status'       => ( $content['has_post'] && $content['has_image'] ) ? 'pass' : 'warning',
			'detail'       => self::content_detail( $content ),
			'action_label' => ( $content['has_post'] && $content['has_image'] ) ? '' : __( 'Add a post or image', 'wp-command-center' ),
			'action_url'   => admin_url( 'post-new.php' ),
		];

		// 7. Approvals workflow ready.
		$items[] = [
			'key'          => 'approvals_ready',
			'label'        => __( 'Approvals are ready', 'wp-command-center' ),
			'status'       => 'pass',
			'detail'       => __( 'Changes that need your sign-off appear in Activity › Approvals.', 'wp-command-center' ),
			'action_label' => __( 'Open Approvals', 'wp-command-center' ),
			'action_url'   => $approvals_url,
		];

		// 8. History / undo available.
		$history_ok = FeatureGate::allows( 'change_history' );
		$items[]    = [
			'key'          => 'history_ready',
			'label'        => __( 'History &amp; undo are ready', 'wp-command-center' ),
			'status'       => $history_ok ? 'pass' : 'warning',
			'detail'       => __( 'Every change is recorded in History, where you can undo what’s reversible.', 'wp-command-center' ),
			'action_label' => __( 'Open History', 'wp-command-center' ),
			'action_url'   => $history_url,
		];

		return $items;
	}

	/** True when no checklist item is blocked (warnings are advisory, not blocking). */
	public static function can_run_first_workflow(): bool {
		foreach ( self::checklist() as $item ) {
			if ( 'blocked' === $item['status'] ) {
				return false;
			}
		}
		return true;
	}

	/** Counts for a compact "N of M ready" summary. @return array{done:int,total:int} */
	public static function progress(): array {
		$items = self::checklist();
		$done  = count( array_filter( $items, static fn ( $i ) => 'pass' === $i['status'] ) );
		return [ 'done' => $done, 'total' => count( $items ) ];
	}

	/**
	 * The single most important next action toward the first governed AI change, or
	 * null when ready. Priority: connect → test → enable a tool → content → mode.
	 *
	 * @return array{key:string,label:string,action_label:string,action_url:string}|null
	 */
	public static function next_action(): ?array {
		$by_key = [];
		foreach ( self::checklist() as $item ) {
			$by_key[ $item['key'] ] = $item;
		}
		$order = [ 'provider_connected', 'provider_tested', 'tool_enabled', 'test_content', 'security_mode' ];
		foreach ( $order as $key ) {
			$item = $by_key[ $key ] ?? null;
			if ( $item && 'pass' !== $item['status'] && '' !== $item['action_label'] ) {
				return [
					'key'          => $item['key'],
					'label'        => $item['label'],
					'action_label' => $item['action_label'],
					'action_url'   => $item['action_url'],
				];
			}
		}
		return null;
	}

	private static function any_tool_enabled(): bool {
		foreach ( array_keys( BuiltinAiSettings::tools() ) as $key ) {
			if ( BuiltinAiSettings::is_on( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{has_post:bool,has_image:bool} */
	private static function test_content(): array {
		$posts = get_posts( [
			'post_type'        => 'post',
			'post_status'      => [ 'publish', 'draft' ],
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		] );
		$images = get_posts( [
			'post_type'        => 'attachment',
			'post_mime_type'   => 'image',
			'post_status'      => 'inherit',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		] );
		return [ 'has_post' => ! empty( $posts ), 'has_image' => ! empty( $images ) ];
	}

	private static function content_detail( array $content ): string {
		if ( $content['has_post'] && $content['has_image'] ) {
			return __( 'You have a post and an image to try SEO and Alt Text on.', 'wp-command-center' );
		}
		if ( ! $content['has_post'] && ! $content['has_image'] ) {
			return __( 'Add a draft post and upload an image so you have something to generate on.', 'wp-command-center' );
		}
		if ( ! $content['has_post'] ) {
			return __( 'Add a draft post to try SEO on.', 'wp-command-center' );
		}
		return __( 'Upload an image to try Alt Text on.', 'wp-command-center' );
	}
}
