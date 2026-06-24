# PROGRAM-4C.0a — Bulk Rollback Forensic Report (Phase A)

> **Type:** source-verified forensic audit (no code changes in this phase). Report-only.
> **Method:** re-audited `includes/Operations/BulkRuntimeManager.php` directly; trusted no prior report. Every claim below cites the live source.
> **File blob identity:** `BulkRuntimeManager.php` = `5f92d1e` on production `a41a9d7`, on P4B `8550a4b`, and on current HEAD — **no Program-4 phase has modified Bulk.** The defects are present in production code.

---

## 1. Per-operation forensics

### 1.1 `bulk_publish` / `bulk_unpublish` → `bulk_status()` (`:52–58`)
- **Execution path:** `run()` (`:32–33`) dispatches `A_BULK_PUBLISH → bulk_status($p,'publish')` and `A_BULK_UNPUBLISH → bulk_status($p,'draft')`.
- **Captured state (`:55`):** `$before[$id] = $post->post_status;` → `before` is a `{id → post_status}` map.
- **Storage (`:56`):** `store_rollback("bulk_$status", "bulk_$status", ['ids'=>$ids,'before'=>$before], $cx)`. So `before_state = ['ids'=>[…], 'before'=>{id→status}]`. **Note the record `action` value:** publish → `"bulk_publish"`; unpublish → **`"bulk_draft"`** (string-interpolated from the *status*, not the bulk action name).
- **Rollback path (`:102–103`):**
  ```php
  $before=$rec['before_state']; $titles=$before['before']??[];      // {id→status}
  foreach($titles as $id=>$old_title){ ... wp_update_post(['ID'=>$id,'post_title'=>$old_title]); }
  ```
  The status map is iterated and each captured **status string is written into `post_title`**.
- **Restored state (actual):** `post_title` ← `"publish"`/`"draft"` (the captured status). **`post_status` is never restored.**
- **History behavior:** record marked `rollback_applied=true` (`:104`); response `{action:'bulk_rollback', rollback_id}` (`:105`) reports **success** regardless.

**Verdict:** **CONFIRMED CORRUPTION.** A status rollback (a) overwrites every targeted post's **title** with a status word, and (b) leaves the **status unreverted**. Two failures in one path: silent non-restoration of the intended field **and** active destruction of an unrelated field.

### 1.2 `bulk_content()` (`:44–50`)
- **Captured state (`:47`):** `$before[$id] = $post->post_title;` (title only) — even though the same loop may also change `post_content` (`:47`, `if(isset($fields['post_content']))…`).
- **Storage (`:48`):** `before_state = ['ids'=>…,'before'=>{id→title}]`, record `action="bulk_content"`.
- **Rollback (`:102–103`):** writes `post_title` from the `{id→title}` map — **correct for title**, but **`post_content` is never captured or restored**.
- **Verdict:** rollback is **correct but incomplete** — a content edit that changed `post_content` is only half-reversed (title back, body left mutated). Lossy, not corrupting.

### 1.3 `bulk_media()` (`:60–65`)
- **Execution:** updates attachment `post_title` (`:63`).
- **Rollback/storage:** **no `store_rollback()` call.** No record written; response carries **no `rollback_id`**.
- **Verdict:** **IRREVERSIBLE.** A bulk media rename cannot be undone.

### 1.4 `bulk_woo()` (`:67–73`)
- **Execution:** sets `regular_price` and/or `status` on each product and `save()` (`:71`).
- **Rollback/storage:** **no `store_rollback()` call.** No record, no `rollback_id`.
- **Verdict:** **IRREVERSIBLE.** Bulk price/status changes (e-commerce-sensitive) cannot be undone.

### 1.5 `bulk_acf()` (`:75–82`)
- **Execution:** `update_field($field,$value,$id)` per id (`:80`).
- **Rollback/storage:** **no `store_rollback()` call.** No record, no `rollback_id`.
- **Verdict:** **IRREVERSIBLE.** Bulk ACF value writes cannot be undone.

### 1.6 Cross-cutting: rollback_id never surfaced (`:91–95`, `:40–41`)
- `store_rollback()` returns `void` (`:91`); `bulk_content`/`bulk_status` call it but **discard** the generated id. `run()` (`:40–41`) merges only `{updated,results}` into the response. **No bulk operation returns its `rollback_id`.**
- **Verdict:** even for the operations that *do* write a record (content/status), the caller never receives the `rollback_id`, so the rollback endpoint is **practically unreachable** through the normal response flow.

### 1.7 Storage mechanics (`:91–95`, `:99–101`)
- Single option `wpcc_bulk_rollbacks`; one record per bulk *operation*; FIFO cap 200 (`:94`). Resolution is a linear scan by `id` (`:100`). `entity_id` holds the **action string**, not post ids.

---

## 2. Answers to the Phase-A questions

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | Is the reported corruption bug real? | **YES** | `:55` captures status; `:103` writes it into `post_title` |
| 2 | Can rollback write status into post_title? | **YES** | `bulk_status` record + `:103` unconditional `post_title` write |
| 3 | Can rollback silently fail? | **YES** | status never restored (`:103` only touches title); empty/absent `before` ⇒ loop no-ops but `:105` returns success |
| 4 | Are rollback records incomplete? | **YES** | `bulk_content` captures title only (not `post_content`, `:47`); media/woo/acf capture **nothing** |
| 5 | Are rollback ids lost? | **YES** | `store_rollback` returns void (`:91`); ids never surfaced (`:40–41`); media/woo/acf write no record at all; FIFO-200 can also evict (`:94`) |
| 6 | Is rollback history honest? | **NO** | `:105` reports `success` regardless of whether the correct field was restored or a title was clobbered |

---

## 3. Severity classification (input to Phase B)
- **CRITICAL — active corruption:** `bulk_status` rollback (title clobber + status non-restoration).
- **HIGH — coverage gap (irreversible):** `bulk_media`, `bulk_woo`, `bulk_acf` (no record).
- **MEDIUM — incomplete record:** `bulk_content` (post_content not captured).
- **MEDIUM — contract gap:** rollback_id never surfaced for any bulk op; dishonest success envelope.

All defects are within `BulkRuntimeManager.php`. Remediation requires **no** schema, DB_VERSION, capability, operation-registry, MCP, REST-contract, or security-model change (see Design). **No STOP condition triggered.**

---

## 4. Confirmation
The PROGRAM-4C finding is **independently re-confirmed from source** and is, if anything, understated: the status-rollback path does not merely no-op — it **actively corrupts the title field** while leaving status wrong, and the rollback_id is unreachable for every bulk action. Proceeding to Phase B (impact) and Phase C (design).
