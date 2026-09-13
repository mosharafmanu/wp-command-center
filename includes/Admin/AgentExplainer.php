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
				'q' => __( 'What is an AI agent?', 'siteradian' ),
				'a' => __( 'An AI assistant — like Claude — running in a separate app on your computer (for example the Claude desktop app). It is the thing that actually reads your site and suggests changes. SiteRadian does not include the AI itself; it safely connects one to your site.', 'siteradian' ),
			],
			[
				'q' => __( 'Why do I need one?', 'siteradian' ),
				'a' => __( 'SiteRadian is the safe doorway between an AI assistant and your WordPress site. Without an assistant connected, there is nothing to send work to. With one connected, it can do tasks like writing SEO titles or image alt text — and you stay in control of every change.', 'siteradian' ),
			],
			[
				'q' => __( 'What does the access token do?', 'siteradian' ),
				'a' => __( 'It is a password just for the AI assistant. You paste it into the assistant once, so it can talk to this site — and only do what the token allows. You can revoke it any time to instantly cut off access.', 'siteradian' ),
			],
			[
				'q' => __( 'What talks to what?', 'siteradian' ),
				'a' => __( 'Your AI assistant (on your computer) talks to SiteRadian (on this site) using the access token. SiteRadian then makes the change on WordPress — with approval when your protection mode requires it and a full record — and supported changes can be undone. You bring your own AI key for the assistant; this site never sends your content anywhere except the AI provider you chose.', 'siteradian' ),
			],
		];
	}

	/**
	 * A one-line "picture" of the flow, jargon-free.
	 */
	public static function flow_line(): string {
		return __( 'Your AI assistant  →  (access token)  →  SiteRadian  →  approval when required  →  WordPress  →  recorded & undoable', 'siteradian' );
	}
}
