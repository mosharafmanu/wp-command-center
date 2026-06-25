# PROGRAM-6S — Connection Wizard

Replaces the intimidating one-shot form with a **5-step guided flow** (progress bar + Back/Next/Create).

| Step | Title | Content |
|---|---|---|
| 1 | **Choose a provider** | Provider select grouped **Cloud / Local / Gateway-Custom** (optgroups), each labelled *used by runtime / testable / stored only*. |
| 2 | **Name & where it runs** | Connection name + Base URL (only for local/gateway/Azure/custom). |
| 3 | **Credentials** | API key (password; "stored on this site, never shown again"; local needs no key). |
| 4 | **Model** | Model id (blank = provider default) + tags. |
| 5 | **Create & test** | Plain explanation: we create it, then "Test" verifies the key; "adding a key does not turn AI features on by itself." |

## Design properties
- **Progressive & low-intimidation:** one decision per step; a progress bar; focus moves to each step's heading (`tabindex=-1` + `focus()`) for screen-reader/keyboard flow.
- **Honest:** the provider list never hides capability — each option states runtime/testable/stored.
- **No new architecture:** the wizard IS the existing create form, stepped by JS; on finish it POSTs `wpcc_conn_action=create` exactly as before (same controller, nonce, audit).
- **Progressive enhancement:** without JS the form degrades to a normal labelled form (all fields reachable); with JS it becomes the stepped wizard and starts collapsed behind "+ New connection."
- **Test placement (honest):** the connection is created first, then tested from its card — because a live test needs a saved connection and WPCC adds no new AJAX/REST endpoint (scope rule). Step 5 sets that expectation clearly.

## Why not a modal
A modal would trap focus and fight WP-admin chrome; an inline expanding panel is calmer, accessible, and scrolls naturally on mobile.
