=== WP Command Center ===
Contributors: mosharafmanu
Tags: ai, mcp, claude, automation, approvals
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect an AI assistant to your WordPress site. You approve every change, everything is recorded, and supported changes can be undone.

== Description ==

WP Command Center lets you connect an AI assistant — Claude, Cursor, Codex, ChatGPT, Gemini, or any other MCP-compatible client — to one WordPress site, and then ask for changes in your own words — in whatever language you and your assistant use.

The point of the plugin is not the AI. It is the control around it:

* **You approve.** On the default Standard protection, anything that could affect your visitors waits for your explicit approval before it runs; low-risk edits go straight through. Choose Strict approval and every single change waits, including low-risk ones. Reading and diagnostics are never gated in any mode.
* **Everything is recorded.** Every change is written to an audit trail with who made it, when, and what it touched.
* **Supported changes can be undone.** Posts and pages, SEO details, media metadata, settings, comments, users, categories and several other areas record a reversible change you can restore. An undo runs through the same approval as any other change.
* **Access is scoped.** An assistant connects with an access token you create and can revoke at any time. Tokens are limited to what you allow, and can be read-only.

= How it works =

1. Install and activate. The site starts in **Standard protection** — changes need your approval.
2. Go to **Command Center → Settings → Connections**, pick your assistant, and create an access token.
3. Copy the generated setup into your assistant.
4. Ask your assistant to do something on the site.
5. Approve it under **Approvals**, then review or undo it under **Changes**.

= You do not need an AI provider API key =

Connecting an assistant over MCP uses *your assistant's* AI. WP Command Center does not need an API key of its own for this, and does not call any AI service.

There is a separate, entirely optional "Built-in AI" section where you can add your own provider key if you want the plugin itself to generate content for you. It is off unless you configure it.

= Honest limits =

* Not everything can be undone. Plugin and theme updates are not automatically reversible, and some areas — WooCommerce orders, for example — have no undo. You are told which is which before you approve.
* This is not a backup plugin. It records and reverses individual changes; it does not take full-site backups. Keep your usual backups.
* It manages one site, not a fleet.

== External services ==

**By default, this plugin contacts no external service.** Nothing leaves your site until you connect something.

= 1. Your AI assistant (MCP) =

When you connect an assistant, *the assistant connects to your site* — your site does not call the assistant. Your assistant reads and changes site data through this plugin's endpoint using the access token you created. What it can see and do is limited by that token's scope and by your approval setting.

The assistant is software you already run and have your own agreement with (for example Anthropic's Claude or OpenAI's ChatGPT). This plugin does not send it anything on its own.

= 2. Optional AI providers (Built-in AI only) =

If — and only if — you add a provider API key under **Settings → Built-in AI** and use a built-in generation tool, this plugin sends the relevant content (for example a post title, an excerpt, or an image) to the provider you chose, in order to generate a suggestion. Nothing is sent until you both add a key and run a tool.

Supported providers, each used only when selected and configured by you:

* Anthropic — https://www.anthropic.com/legal/consumer-terms · https://www.anthropic.com/legal/privacy
* OpenAI — https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* Google Gemini — https://ai.google.dev/gemini-api/terms · https://policies.google.com/privacy
* Azure OpenAI — https://azure.microsoft.com/support/legal/ · https://privacy.microsoft.com/privacystatement
* OpenRouter — https://openrouter.ai/terms · https://openrouter.ai/privacy
* Groq — https://groq.com/terms-of-use/ · https://groq.com/privacy-policy/
* Together AI — https://www.together.ai/terms-of-service · https://www.together.ai/privacy
* Fireworks AI — https://fireworks.ai/terms-of-service · https://fireworks.ai/privacy-policy
* DeepInfra — https://deepinfra.com/terms · https://deepinfra.com/privacy
* Mistral — https://mistral.ai/terms/ · https://mistral.ai/terms/#privacy-policy
* Perplexity — https://www.perplexity.ai/hub/legal/terms-of-service · https://www.perplexity.ai/hub/legal/privacy-policy
* xAI — https://x.ai/legal/terms-of-service · https://x.ai/legal/privacy-policy
* Ollama, LM Studio, vLLM, or a custom OpenAI-compatible endpoint — these run wherever you point them, including on your own machine or server. No third party is involved unless the endpoint you enter belongs to one.

No analytics, telemetry, or usage data is sent anywhere by this plugin.

== Privacy and data storage ==

Everything below is stored on your own site. Nothing is shared with the plugin author.

= Database tables =

The plugin creates tables for the change log, operation requests, results and queue, snapshots used for undo, agent sessions and tasks, recommendations, health checks, proposals, and patches. These hold the record of what was requested, what ran, and what it can be reverted to.

= Options =

Your protection setting, AI connection settings, and rollback records are stored in WordPress options.

= Files in your uploads folder =

Access tokens, the audit log, and undo snapshots are stored in protected directories under `wp-content/uploads` (`wpcc-tokens`, `wpcc-audit`, `wpcc-snapshots`, `wpcc-media-snapshots`, `wpcc-patches`, `wpcc-plugin-backups`). Each is protected against direct web access.

= Access tokens =

Access tokens are hashed before storage — the full token is shown to you once, at creation, and cannot be recovered afterwards. Revoke or delete a token at any time under **Settings → Connections**; it stops working on its next request.

= Provider API keys =

If you add an AI provider key, it is stored in the WordPress options table on your own site so the plugin can authenticate to that provider. It is never displayed again after saving, never written to logs or the audit trail, and never sent anywhere except to the provider it belongs to. Remove it at any time with the **Remove key** button. You can also define it as a constant in `wp-config.php` instead, in which case it is not stored in the database at all.

= When you delete the plugin =

By default your data is **kept**, so an audit trail is not destroyed just because the plugin was removed. If you want everything erased instead, tick *"Also delete all WP Command Center data when the plugin is deleted"* under **Settings → Security & Approvals** before deleting. With that set, uninstalling removes all of the plugin's tables, options, user settings, and upload directories. Scheduled jobs are always removed.

== Frequently Asked Questions ==

= Do I need an API key to use this? =

No. Connecting an AI assistant over MCP uses your assistant's own AI. A provider key is only needed for the optional Built-in AI generation tools.

= Which assistants work with it? =

Any MCP-compatible client. Setup instructions are provided for Claude, Cursor, Codex, ChatGPT, Gemini, and others, plus a generic option.

The setup the plugin generates runs a small connector (shipped inside the plugin, served from your own site — nothing is downloaded from npm). **Node.js must be installed on the computer running your assistant**, not on your web host. If your assistant connects but shows no tools, a missing Node is the usual cause.

= Can the AI change my site without asking? =

Not in the default setting. On a fresh install the site runs in Standard protection, where anything that could affect your visitors waits for your approval — low-risk edits still go straight through. Strict approval gates every change without exception. There is also a Development setting that removes the approval step for local and staging sites; it warns you before you switch to it, and it is never the default — including when the setting is missing or corrupt.

= Can I undo a change? =

Supported changes, yes — from **Changes**. An undo is itself a change, so on the default Standard protection it asks for your approval before it runs. Some changes cannot be reversed (plugin and theme updates, WooCommerce orders, and others). The plugin tells you before you approve, and never claims an undo it cannot perform.

= What happens if my token leaks? =

Revoke it under **Settings → Connections**. It stops working immediately on the next request. Tokens are hashed at rest, so nobody can read them out of your database.

= Does it work on WordPress Multisite? =

Version 1 is built and tested for a single site, and it now enforces that rather than
just recommending it: network activation is refused with an explanation, because it
would create the plugin's tables and settings for one site while showing the Command
Center on all of them. Activate it from each site's own Plugins screen instead. If a
network was already network-activated before this version, an admin notice says so.

= Can it create or delete files? =

No. It edits files that already exist — a patch is a change to a known file, taken with
a snapshot first so it can be undone. It will not create a new template or delete one,
and it says so if you ask: the request is refused rather than half-performed. Creating
and removing files stays with you, your editor, or your deployment process.

= What happens if my host blocks PHP process execution? =

Everything except the WP-CLI bridge keeps working. That one operation needs the host to
allow proc_open and a reachable WP-CLI binary; where a host disables it — which is
common on shared hosting — the operation reports itself as unavailable instead of
failing halfway, and the rest of the plugin is unaffected. You can see exactly what your
host allows under Command Center -> Settings -> Advanced -> Diagnostics.

= Does it need SSH, WP-CLI, or file permissions? =

You do not need to set anything up. Everything in the normal workflow — connecting an
assistant, approving a change, undoing it — happens inside WordPress with an
administrator account, and needs no shell access and no WP-CLI installation.

Two optional features do run a command on your own server, and only when you use them:

* **Patches.** Before a file change is applied, the plugin runs your own PHP binary in
  syntax-check mode (`php -l`) against the changed file, so a patch that would break
  your site is rejected instead of applied. Nothing is executed from the file itself.
* **The WP-CLI bridge.** If — and only if — you run a WP-CLI command through the
  plugin, it invokes your existing `wp` binary.

Both build their command from a fixed list and pass every argument through PHP's
`escapeshellarg()`; neither ever runs text supplied by an AI assistant as a shell
command. If your host disables `proc_open`/`shell_exec`, these two features report
that they are unavailable and the rest of the plugin works normally.

= Is my content sent to an AI company? =

Not by this plugin, unless you configure the optional Built-in AI with your own provider key. See the External services section.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wp-command-center`, or install it from the Plugins screen.
2. Activate it through the **Plugins** menu.
3. Open **Command Center** in the admin sidebar and follow the single next step shown on the Home screen.

== Changelog ==

= 1.0.0 =
* First public release.
* Connect any MCP-compatible AI assistant to a single WordPress site.
* Approval workflow with Standard protection and Strict approval settings; safe default that cannot fall back to unapproved execution.
* Scoped, revocable, hashed access tokens.
* Full change history with undo for supported changes.
* Plain-language approval and history — no operation identifiers required to make a decision.
* Optional Built-in AI generation tools, off by default.
* Documented uninstall behaviour: data is retained unless you opt in to deletion.

== Upgrade Notice ==

= 1.0.0 =
First public release.
