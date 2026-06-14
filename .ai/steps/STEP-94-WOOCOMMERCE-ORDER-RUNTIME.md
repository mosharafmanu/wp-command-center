# STEP 94 — WooCommerce Order Runtime

## Goal

Order and customer management over REST and MCP.

## Audit

`WooCommerceRuntimeManager` had read-only orders (`order_list/get/search`). All
six STEP-94 operations were missing and are added here.

## Added operations (REST `/operations/woocommerce_manage/run` + `/rollback`, MCP)

| Action | Risk | Rollback |
|--------|------|----------|
| `order_update` | medium | restores customer_note + billing fields |
| `order_note_add` | medium | deletes the added note |
| `order_status_change` | medium | restores the previous status |
| `refund_create` | high | deletes the refund |
| `customer_get` | diagnostic | — |
| `customer_search` | diagnostic | — |

- `order_update` — customer note and billing fields (name, email, phone, company,
  address). Full snapshot → rollback.
- `order_note_add` — `$order->add_order_note()`; returns `note_id`; private or
  customer-facing (`customer_note: true`). Rollback deletes the note.
- `order_status_change` — validates against `wc_get_order_statuses()`; records
  `previous_status`; optional note. `wpcc_invalid_order_status` on a bad status.
- `refund_create` — `wc_create_refund()` with amount + reason (defaults to the
  remaining refundable amount); `wpcc_invalid_refund_amount` / `wpcc_refund_failed`.
  Rollback deletes the refund (reverses it).
- `customer_get` — by `customer_id` or `email`; returns id, email, name,
  username, order count, total spent, registration date.
- `customer_search` — by login/email/display name; returns matching customers.

Structured errors: `wpcc_order_not_found`, `wpcc_invalid_order_status`,
`wpcc_empty_note`, `wpcc_invalid_refund_amount`, `wpcc_refund_failed`,
`wpcc_customer_not_found`.

## Acceptance tests — `tests/test-woocommerce-order-step94.sh` (23/23)

Workflow: create order (with a line item + customer) → read order → add note →
change status (+ rollback) → update order (+ rollback) → create refund (+
rollback) → customer lookups (id, email, search) → verify in WooCommerce. Plus
MCP parity and structured errors.

## Files changed

- `includes/Operations/WooCommerceRegistry.php` — 6 action constants + risk/
  approval/rollback maps (order writes medium, refund high, customer reads low).
- `includes/Operations/WooCommerceRuntimeManager.php` — 6 handlers,
  `format_customer`, dispatch, and order/refund rollback restore branches.
- `includes/Operations/OperationRegistry.php` — `woocommerce_manage` action_risks
  for the new actions (customer reads diagnostic, refund high).

## Test-environment note

WooCommerce 10.8.1 active on the dev site; exercised against real orders,
refunds, and customers. (A refund requires an order total > 0 — refunding an
empty order correctly returns `wpcc_refund_failed`.)

## Preserved guarantees

Backward compatible (additive). Security modes (order writes gated medium, refund
high), approval, rollback, audit, and REST/MCP parity intact.
