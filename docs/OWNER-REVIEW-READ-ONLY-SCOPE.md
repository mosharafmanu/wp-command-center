# Owner review — the Read-only scope allowlist

**Raised:** 2026-08-05, during the RC hardening sprint.
**Status:** recorded for owner decision. **No code change made.**
**Scope of this note:** the breadth of the read-only allowlist only. It does not
revisit the token default, which is settled: Read-only is the default on both
token forms, and Full access always requires an explicit selection.

---

## Why this is being recorded

Read-only is now the default scope on both surfaces that mint a token:

- Settings › Connections › Access tokens
- Settings › Connections › Assistants (the connect-an-assistant dialog)

That is the correct safety posture and it is not in question here. What follows
is the consequence a customer will meet, written down so the decision to accept
or change it is made deliberately rather than discovered in a support ticket.

## What Read-only currently permits

`CapabilityRegistry::READ_ONLY_SCOPE_OPERATIONS` — six operations:

| Operation | What it covers |
|---|---|
| `database_inspect` | Inspect database structure/contents |
| `search_manage` | Search across the site |
| `file_manage` | Read files |
| `code_search` | Search code |
| `change_history` | Read the change history |
| `term_manage` | Read taxonomy terms |

These resolve to three capabilities: `database.inspect`, `search.manage`,
`history.read`.

The operation catalogue has **34** entries. A read-only token can reach 5 of
them (verified by the existing suite: *"read_only token: exactly 5 ops
allowed"*). The allowlist is fail-closed by design.

## The consequence

Several questions a first-time customer is most likely to ask are **not**
covered by the allowlist, because the operations that answer them are not on it:

- "What plugins are installed?"
- "What pages do I have?"
- "Show me my recent posts."

With a Read-only token these are refused. The refusal is correct behaviour — the
token genuinely lacks the capability — but the customer experiences it as the
assistant not working, on the first thing they try, immediately after a
successful connection.

This is the trade-off that previously motivated defaulting the Assistants screen
to Full access. That default has now been reversed per the approved requirement.
The trade-off itself has not gone away; it has moved to a place where the
customer meets it.

## Mitigation already shipped

The Read-only option states the limit up front rather than leaving it to be
discovered:

> **Read-only** — Inspect the site without requesting changes. This covers a
> small set of site details, so many ordinary questions will be refused.

And Full access states what it means:

> **Full access** — May request or perform changes according to the active
> protection mode.

So the customer can make an informed choice at the moment of creation. What they
cannot do is find out later *why* a specific question was refused without
visiting the access matrix.

## Options for the owner

1. **Accept as-is.** Safest default; customers who need more choose Full access
   at creation time, having been told what each option means. No code change.
2. **Widen the allowlist to read-only *inspection* operations.** Add the read
   paths for content and plugin/theme inventory so the common first questions
   succeed without granting any write capability. This is a real security-surface
   change: it needs its own review of each candidate operation's read/write
   split, because several `*_manage` operations carry both, and the allowlist is
   currently keyed by operation, not by action.
3. **Surface the reason on refusal.** Leave the allowlist alone and make a
   scope-refusal explain itself ("this token is Read-only; Full access is needed
   to list plugins") with a link to the token. Smallest security surface, best
   customer outcome, but it is a runtime change, not a UI one.

**Recommendation:** option 3 for v1.1, option 1 for v1.0. Option 2 should not be
taken as a quick fix — widening a fail-closed allowlist deserves its own review
pass, not a hardening sprint.

## What was explicitly NOT done

- `READ_ONLY_SCOPE_OPERATIONS` is unchanged.
- No capability profile was changed.
- No operation's risk tier or approval requirement was changed.
- No currently documented read-only operation was found broken, so nothing met
  the bar for changing the allowlist during this task.
