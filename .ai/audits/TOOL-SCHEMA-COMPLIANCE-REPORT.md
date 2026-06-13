# Tool Schema Compliance Report — Finding A

**Date:** 2026-06-13
**Scope:** `tools/list` JSON Schema compliance (MCP `inputSchema.required`)
**Status:** ✅ FIXED, regression-tested, all suites green

---

## 1. Executive Summary

Finding A (from the Claude Desktop MCP registration debugging session) identified
that `McpServerRuntime::tools_list()` generated an **invalid `inputSchema.required`**
field for nearly every tool in `tools/list`: instead of an array of parameter-name
strings (as required by JSON Schema and by the strict Zod validators used by MCP
clients such as Claude Desktop), it emitted an array of **numeric array indices**.

Example — before the fix:

```json
"required": [0]
```

Expected / after the fix:

```json
"required": ["type"]
```

This is very likely a contributing cause of Claude Desktop showing the WPCC MCP
server as "running" while never registering it as a connected app with usable
tools — `tools/list` is one of the first calls a client makes during capability
negotiation, and a schema that fails Zod's `z.array(z.string())` validation can
cause the entire tool (or the entire `tools/list` response) to be silently
rejected.

The fix has been applied, a new dedicated regression suite was added, every one
of the 28 tools exposed via `tools/list` was validated, and the existing MCP test
suites were re-run with no regressions.

---

## 2. Root Cause Analysis

### 2.1 Where the bug lived

`includes/Mcp/McpServerRuntime.php`, method `tools_list()`, inside the
`inputSchema` construction for each tool.

### 2.2 The buggy expression

```php
'required' => array_keys( array_filter( $op['parameters'], static fn( $p ) => ! empty( $p['required'] ) ) ),
```

### 2.3 Why this is wrong

`OperationRegistry::get_operations()` returns each operation's `parameters` as a
**sequential array of associative arrays**, e.g.:

```php
'parameters' => [
    0 => [ 'name' => 'name',          'type' => 'string', 'required' => true  ],
    1 => [ 'name' => 'sku',           'type' => 'string', 'required' => false ],
    2 => [ 'name' => 'regular_price', 'type' => 'string', 'required' => true  ],
    // ...
],
```

- `array_filter()` preserves the **original numeric keys** of the surviving
  elements — it does not renumber them.
- `array_keys()` of that filtered array therefore returns the **original
  positional indices** of the required parameters (e.g. `[0, 2]`), not their
  `name` values (`['name', 'regular_price']`).

The result was a JSON Schema `required` array containing **integers** instead of
**strings** — a direct violation of the JSON Schema spec (`required` items MUST be
strings) and of MCP client-side validators that assert
`required: z.array(z.string()).optional()`.

### 2.4 A secondary trap that had to be avoided

A naive fix — mapping to `name` without `array_values()` — would have introduced
a *second* bug:

```php
// WRONG: still broken for non-contiguous required params
'required' => array_map( static fn( $p ) => $p['name'], array_filter( ... ) ),
```

`array_filter()` preserves keys, so for a tool like `woo_product_seed` (required
params at positions 0 and 2), this produces the PHP array
`[0 => 'name', 2 => 'regular_price']`. PHP's `json_encode()` renders an array
whose keys are **not** a contiguous `0..n-1` sequence as a JSON **object**:

```json
"required": {"0": "name", "2": "regular_price"}
```

...which is also invalid (an object where an array is expected). `array_values()`
is therefore required to re-index the filtered/mapped array back to `0..n-1`
before encoding.

---

## 3. The Fix

**File:** `includes/Mcp/McpServerRuntime.php` (`tools_list()`)

### Before

```php
$tools[] = [
    'name'        => $op['id'],
    'description' => ContextModeOptimizer::COMPACT === $mode ? $op['title'] : $op['title'] . ': ' . $op['description'],
    'inputSchema' => [
        'type'       => 'object',
        'properties' => $props,
        'required'   => array_keys( array_filter( $op['parameters'], static fn( $p ) => ! empty( $p['required'] ) ) ),
    ],
];
```

### After

```php
$tools[] = [
    'name'        => $op['id'],
    'description' => ContextModeOptimizer::COMPACT === $mode ? $op['title'] : $op['title'] . ': ' . $op['description'],
    'inputSchema' => [
        'type'       => 'object',
        'properties' => $props,
        'required'   => array_values( array_map( static fn( $p ) => $p['name'], array_filter( $op['parameters'], static fn( $p ) => ! empty( $p['required'] ) ) ) ),
    ],
];
```

**One-line summary:** `array_keys(array_filter(...))` (indices) →
`array_values(array_map(fn($p) => $p['name'], array_filter(...)))`
(parameter-name strings, re-indexed to a JSON-array-safe sequence).

---

## 4. Before / After Examples

### 4.1 Single required parameter — `content_seed`

| | `required` |
|---|---|
| **Before** | `[0]` |
| **After**  | `["type"]` |

Live `tools/list` output (after fix):

```json
{
  "name": "content_seed",
  "description": "Content Seeding",
  "inputSchema": {
    "type": "object",
    "properties": {
      "type": { "type": "string" },
      "count": { "type": "number" },
      "status": { "type": "string" },
      "title_pattern": { "type": "string" },
      "content_template": { "type": "string" },
      "context_mode": { "type": "string", "enum": ["compact", "standard", "verbose"], "default": "compact" }
    },
    "required": ["type"]
  }
}
```

### 4.2 Contiguous multi-parameter — `acf_seed`

| | `required` |
|---|---|
| **Before** | `[0,1]` |
| **After**  | `["post_id","fields"]` |

### 4.3 Non-contiguous required params (the "object instead of array" trap) — `woo_product_seed`

| | `required` |
|---|---|
| **Before** | `[0,2]` |
| **After**  | `["name","regular_price"]` |

Live `tools/list` output (after fix):

```json
{
  "name": "woo_product_seed",
  "description": "WooCommerce Product Seeder",
  "inputSchema": {
    "type": "object",
    "properties": {
      "name": { "type": "string" },
      "sku": { "type": "string" },
      "regular_price": { "type": "string" },
      "sale_price": { "type": "string" },
      "status": { "type": "string" },
      "stock_quantity": { "type": "number" },
      "manage_stock": { "type": "string" },
      "categories": { "type": "string" },
      "context_mode": { "type": "string", "enum": ["compact", "standard", "verbose"], "default": "compact" }
    },
    "required": ["name", "regular_price"]
  }
}
```

### 4.4 Non-contiguous, 3 required of 5 — `safe_search_replace`

| | `required` |
|---|---|
| **Before** | `[0,1,3]` |
| **After**  | `["search","replace","tables"]` |

---

## 5. Full Validation Matrix — All 28 Tools

Computed directly from `OperationRegistry::get_operations()` (old vs. new logic,
side by side) and cross-checked against the live `tools/list` response.

| Tool | Before (`required`) | After (`required`) |
|---|---|---|
| `content_seed` | `[0]` | `["type"]` |
| `acf_seed` | `[0,1]` | `["post_id","fields"]` |
| `cf7_seed` | `[]` | `[]` |
| `woo_product_seed` | `[0,2]` | `["name","regular_price"]` |
| `safe_search_replace` | `[0,1,3]` | `["search","replace","tables"]` |
| `media_import` | `[0]` | `["source_url"]` |
| `safe_updates` | `[0,1]` | `["type","slug"]` |
| `capability_manage` | `[0]` | `["action"]` |
| `database_inspect` | `[0]` | `["action"]` |
| `content_manage` | `[0]` | `["action"]` |
| `snapshot_manage` | `[0]` | `["action"]` |
| `theme_manage` | `[0]` | `["action"]` |
| `plugin_manage` | `[0]` | `["action"]` |
| `option_manage` | `[0,1]` | `["action","option_id"]` |
| `wp_cli_bridge` | `[]` | `[]` |
| `user_manage` | `[0]` | `["action"]` |
| `media_manage` | `[0]` | `["action"]` |
| `woocommerce_manage` | `[0]` | `["action"]` |
| `acf_manage` | `[0]` | `["action"]` |
| `forms_manage` | `[0]` | `["action"]` |
| `menu_manage` | `[0]` | `["action"]` |
| `settings_manage` | `[0]` | `["action"]` |
| `search_manage` | `[0]` | `["action"]` |
| `bulk_manage` | `[0]` | `["action"]` |
| `workflow_manage` | `[0]` | `["action"]` |
| `comments_manage` | `[0]` | `["action"]` |
| `widgets_manage` | `[0]` | `["action"]` |
| `cpt_manage` | `[0]` | `["action"]` |

**Result:** 26 of 28 tools were emitting invalid (`number[]`) `required` arrays.
The remaining 2 (`cf7_seed`, `wp_cli_bridge`) have no required parameters, so
`required: []` was already valid in both versions — but they are included in the
matrix as confirmation that the new generic `array_map`/`array_values` logic
degrades correctly to an empty array (no special-casing needed).

---

## 6. Regression Test Suite

**New file:** `tests/test-mcp-tool-schema-compliance.sh`

Follows the existing `tests/*.sh` conventions (`wpcc-env.sh`, `pass()`/`fail()`/
`assert_eq()`/`assert_true()`, `mcp()` JSON-RPC helper).

Rather than hardcoding the full expected output of every tool (a brittle,
stale-spec-prone pattern flagged in the 2026-06-12 audit as a LOW finding), the
suite is **primarily generic** — it validates structural JSON Schema invariants
across *every* tool returned by `tools/list`, plus a handful of stable spot
checks for the cases that best demonstrate the bug:

1. `tools/list` returns at least one tool.
2. `inputSchema.type === "object"` for every tool.
3. `inputSchema.properties` is a JSON object for every tool.
4. `inputSchema.required` is a JSON **array** for every tool.
5. Every `required[]` entry is a **string** (not a numeric index) for every tool.
6. Every `required[]` entry names a property actually declared in
   `inputSchema.properties` (no orphaned/typo'd required names) for every tool.
7. Spot checks: `content_seed` (single), `acf_seed` (contiguous pair),
   `woo_product_seed` and `safe_search_replace` (non-contiguous — the cases that
   most clearly exposed the original bug).

It also prints the full tool/`required` matrix used to build Section 5 above.

---

## 7. Test Results

### 7.1 New compliance suite — `tests/test-mcp-tool-schema-compliance.sh`

```
== 1. Baseline ==
  PASS: tools/list returns tools
== 2. inputSchema.type == 'object' for every tool ==
  PASS: all tools: inputSchema.type is object
== 3. inputSchema.properties is an object for every tool ==
  PASS: all tools: inputSchema.properties is an object
== 4. inputSchema.required is an array for every tool ==
  PASS: all tools: inputSchema.required is an array
== 5. Every required[] entry is a string, not a numeric index ==
  PASS: all tools: required[] entries are strings
== 6. Every required[] entry names a declared property ==
  PASS: all tools: required[] entries reference declared properties
== 7. Spot checks — single / contiguous / non-contiguous required params ==
  PASS: content_seed: required == [type]
  PASS: acf_seed: required == [post_id,fields]
  PASS: woo_product_seed: required == [name,regular_price] (non-contiguous indices 0,2)
  PASS: safe_search_replace: required == [search,replace,tables] (non-contiguous indices 0,1,3)

== Summary ==
  10 passed, 0 failed
```

### 7.2 Existing MCP suites — re-run for regressions

```
tests/test-mcp-runtime.sh
== Summary ==
  42 passed, 0 failed

tests/test-mcp-scope-enforcement.sh
== Summary ==
  15 passed, 0 failed
```

No regressions introduced by the fix. (An unrelated pre-existing environment
artifact — `wpcc_enforce_approval` left at `1` by a prior test run — caused 3
transient failures across these two suites on the first re-run; this is
test-harness state, unrelated to `tools/list`/`inputSchema`, and was restored to
its baseline value of `0` before the final clean run shown above.)

---

## 8. Compliance Verdict

- ✅ `inputSchema.required` is now `string[]` for all 28 tools — valid JSON
  Schema.
- ✅ All `required[]` entries reference real, declared `inputSchema.properties`
  keys.
- ✅ Non-contiguous required-parameter cases (`woo_product_seed`,
  `safe_search_replace`) no longer risk `json_encode()` emitting a JSON object in
  place of an array.
- ✅ `tools/list` output now passes strict Zod-style validation
  (`required: z.array(z.string()).optional()`), removing one concrete,
  code-confirmed reason Claude Desktop could silently reject the server's tool
  list during capability negotiation.

**Finding A is resolved.** Finding B (the `${WPCC_TOKEN}` literal placeholder in
`ClaudeIntegration::generate_mcp_config()`) remains open and out of scope for this
change.
