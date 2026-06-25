# Final UX Copy Polish — Connection Wizard (copy only)

No runtime / provider-execution / discovery / backend change. Only user-facing wording in `includes/Admin/views/ai-setup.php`, plus one presentation-only flag (`$wpcc_lists`) used solely to choose between two truthful sentences.

## 1. UX copy review (problem)
The architecture is correct (wizard = curated; test = discovery; edit = recommended + discovered), but a first-time user couldn't tell *why* the wizard shows only a few models, when discovery happens, or why a healthy OpenAI connection isn't selectable for routing. The flow needed to explain itself.

## 2. Updated wording

### Step 4 — Model (wizard)
> "Recommended models are shown during setup. After you save and test this connection, WP Command Center automatically adds any other models your account exposes — you'll find them in this connection's Model list under "Edit". Providers that don't publish a model list simply keep the recommended set. You can change the model any time."

Truthful: it does **not** promise universal discovery — it names the caveat (providers that don't publish a list keep the recommended set).

### Edit Connection — clear sections
- Optgroups relabelled: **Recommended** · **Discovered from your account (N)** · **Custom model ID…**
- When discovered models exist, a one-line legend: *"Recommended = our defaults. Discovered from your account = pulled live from your last connection test. Custom = enter any model id."*

### No discovered models yet (discovery-capable provider)
> "Test this connection once to discover the additional models available to your account."

### Discovery unavailable (provider doesn't list models, e.g. Anthropic)
> "This provider offers the recommended models only — it doesn't publish an account model list to discover. Nothing is broken; use Custom to enter any model id."

(The view picks between these two with `$wpcc_lists` — a copy-selection flag derived from the dialect; it changes no behavior.)

### Routing page (wording reviewed for a non-technical owner)
- Intro: *"Right now WP Command Center can only run AI tasks through Anthropic (Claude), so only Anthropic connections appear here. Other providers can still be saved and tested — they'll appear here automatically once WP Command Center can run them."*
- Ineligible connections shown as disabled: *"{name} — healthy, but WP Command Center can't run it yet."*
- Note: *"… connected and tested successfully — WP Command Center simply can't run AI tasks through them yet (today it runs through Anthropic / Claude only). They'll appear as selectable the moment that changes. Nothing is hidden or faked."*
- Empty state: *"You have N connection(s) that connected and tested fine (…), but WP Command Center can only run AI through Anthropic (Claude) right now …"*

A non-technical agency owner now reads: **why Anthropic is selectable, why a healthy OpenAI is not, and that nothing is hidden or faked.**

## 3. Validation report
- **Copy-only:** the sole changed file is `ai-setup.php`; the diff is text strings + one comment + the `$wpcc_lists` presentation flag. No JS behavior, no backend, no contracts.
- **Unchanged vs `main`:** runtime/discovery/storage logic (`ConnectionTester`, `ConnectionStore`, `ConnectionController`, `AnthropicClient`, `Dialect`, `CredentialStore`) — not in this changeset.
- **Tests:** `test-connection-discovery-routing.sh` **29/0** (updated copy assertions + 4 new copy-guidance checks); `test-wizard-ux-cleanup.sh` 26/0; `test-wizard-provider-metadata.sh` 29/0; `test-ai-platform-ux-6s.sh` 44/0; `-6r.sh` 38/0; **`test-ai-assist.sh` 92/0**. No regression.

## Result
The connection workflow is now self-explanatory end-to-end, every message is truthful (no discovery promised where unsupported, nothing implied broken, no faked provider support), and not a line of runtime or backend logic changed.
