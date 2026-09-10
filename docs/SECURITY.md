# Security model

Four independent layers stand between an assistant and your database. A request must
pass all of them.

```
token scope  →  capability  →  protection mode (approval)  →  destructive guard
```

---

## 1. Authentication — bearer tokens

Tokens are created in **Settings → Connections → Tokens**. Only a hash is stored; the raw
token is displayed once at creation and never again.

- Tokens carry a scope, an optional expiry, and a creating user.
- Tokens can be revoked or deleted at any time; revocation takes effect immediately.
- Token storage lives in `wp-content/uploads/wpcc-tokens/`, protected from direct web
  access by `.htaccess` and an `index.php` stub.

**Header handling.** Several server configurations (commonly Apache with CGI/FastCGI) never
populate `$_SERVER['HTTP_AUTHORIZATION']`, so WordPress's own header lookup returns
nothing even when a valid token was sent. Action Steward checks the same alternative sources core
and the wider ecosystem use. This changes no authorization policy — whatever is found is
still validated exactly as before. It only stops a correct token being discarded before it
is ever checked.

## 2. Scope — read-only means read-only

| Scope | Effect |
|---|---|
| `read_only` | Limited to search, file reads and history lookups. |
| `full` | May request any operation the capabilities allow. |

Scope is enforced **at the MCP transport**, before dispatch. A read-only token calling a
write tool receives `wpcc_token_read_only` and the operation is never reached.

## 3. Capabilities — 23 of them

Each operation maps to a capability; a token may be restricted to a subset.

```
content.manage      database.inspect   plugin.manage     theme.manage
option.manage       snapshot.manage    wpcli.execute     system.admin
capability.admin    user.manage        media.manage      woocommerce.manage
acf.manage          forms.manage       menu.manage       settings.manage
search.manage       bulk.manage        workflow.manage   comments.manage
widgets.manage      cpt.manage         history.read
```

Enforcement is on by default (`wpcc_enforce_capabilities`). A denied call returns
`wpcc_capability_denied` naming the capability required. See
[CAPABILITIES.md](CAPABILITIES.md).

## 4. Protection modes — when a human decides

Three modes. The default on a fresh install is **Standard protection**.

| Risk tier | Standard protection | Strict approval | Development |
|---|---|---|---|
| diagnostic | immediate | immediate | immediate |
| low | immediate | **approval** | immediate |
| medium | **approval** | **approval** | immediate |
| high | **approval** | **approval** | immediate |
| critical | **approval** | **approval** | immediate |

- **Standard protection** (`client`) — the default. Reads are free; anything that changes
  the site waits for you.
- **Strict approval** (`enterprise`) — even low-risk writes wait. For regulated or
  multi-operator sites.
- **Development** (`developer`) — nothing waits. **For local and staging only.** The UI
  badges it "Local & staging only", footnotes "Never use this mode on a live production
  website", requires a typed confirmation to switch into, and the Home screen states
  "Approvals are off — AI changes will apply immediately" for as long as it is active.

An approval request records the operation, the full payload, and the risk tier. Approving
executes it; rejecting discards it. An **undo is itself a change** and is governed by the
same table.

## 5. Destructive guard

Before any permanent delete, in every mode, a confirmation handshake is required: an
explicit confirmation phrase, a reason, and the target. A pre-delete backup is taken and
verified first.

## 6. Audit trail

Two independent records:

- **`wpcc_change_log`** — the structured change history behind the Changes screen, with
  before/after values and rollback handles.
- **`wp-content/uploads/wpcc-audit/audit.log`** — append-only JSONL of the operation
  lifecycle. Rotated at 50 MB, five segments retained, protected from web access.

Auditing is best-effort by design: a failure to write an audit record must never abort the
operation it is recording. Correspondingly, a malformed actor is normalised rather than
thrown, so an audit problem can never strand a governed change.

## 7. Secret redaction

Audit context passes through a redactor before it is written. Recognised and replaced with
`[REDACTED_SECRET]`:

- PEM private key blocks
- JWTs
- AWS access key IDs (`AKIA` + 16)
- Anthropic keys (`sk-ant-…`)
- OpenAI keys (`sk-…`, excluding `sk-ant-`)
- Stripe secret/publishable/restricted keys, live and test
- `Authorization:` headers and bare bearer tokens

## 8. File boundary

File operations are confined to the WordPress installation. Path traversal is rejected.
The patch engine **cannot create or delete files** — it modifies existing ones, taking a
per-file snapshot first so any patch can be reversed. `wp-config.php` and other sensitive
targets are blocked.

## 9. What leaves your site

Nothing, unless you configure an AI provider key. Action Steward makes no outbound request of its
own — no telemetry, no phone-home, no licence check. When you do configure a provider, the
only outbound calls are to that provider, for the feature you invoked.

## Reporting a vulnerability

Please report security issues privately to the plugin author rather than in a public
issue tracker.
