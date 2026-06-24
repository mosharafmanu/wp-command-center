<?php
/**
 * PROGRAM-5C — Plain-language "what is an AI agent?" explainer (read-only copy).
 *
 * The single source of the non-technical answers a WordPress agency owner needs
 * before the "Connect an AI Agent" step makes sense. No assumptions of MCP /
 * Claude / developer knowledge. Pure presentation content — no writes, no calls,
 * no routes, operations, capabilities, MCP tools, or schema.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class AgentExplainer {

	/**
	 * The core questions a newcomer asks, answered in plain language.
	 *
	 * @return array<int,array{q:string,a:string}>
	 */
	public static function faq(): array {
		return [
			[
				'q' => __( 'What is an AI agent?', 'wp-command-center' ),
				'a' => __( 'An AI assistant — like Claude — running in a separate app on your computer (for example the Claude desktop app). It is the thing that actually reads your site and suggests changes. WP Command Center does not include the AI itself; it safely connects one to your site.', 'wp-command-center' ),
			],
			[
				'q' => __( 'Why do I need one?', 'wp-command-center' ),
				'a' => __( 'WP Command Center is the safe doorway between an AI assistant and your WordPress site. Without an assistant connected, there is nothing to send work to. With one connected, it can do tasks like writing SEO titles or image alt text — and you stay in control of every change.', 'wp-command-center' ),
			],
			[
				'q' => __( 'What does the access token do?', 'wp-command-center' ),
				'a' => __( 'It is a password just for the AI assistant. You paste it into the assistant once, so it can talk to this site — and only do what the token allows. You can revoke it any time to instantly cut off access.', 'wp-command-center' ),
			],
			[
				'q' => __( 'What talks to what?', 'wp-command-center' ),
				'a' => __( 'Your AI assistant (on your computer) talks to WP Command Center (on this site) using the access token. WP Command Center then makes the change on WordPress — after your approval, with a full record and one-click undo. You bring your own AI key for the assistant; this site never sends your content anywhere except the AI provider you chose.', 'wp-command-center' ),
			],
		];
	}

	/**
	 * A one-line "picture" of the flow, jargon-free.
	 */
	public static function flow_line(): string {
		return __( 'Your AI assistant  →  (access token)  →  WP Command Center  →  your approval  →  WordPress  →  recorded & undoable', 'wp-command-center' );
	}
}
