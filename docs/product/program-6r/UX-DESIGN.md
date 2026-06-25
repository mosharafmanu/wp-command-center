# PROGRAM-6R — UX Design

## From "settings page" to "AI platform"
The screen is renamed and reframed from **AI Setup** to **AI Connections** — a managed-resource surface, the way Vercel/Railway/Supabase/OpenRouter present environments, not a one-time WordPress settings form.

## Surface structure
1. **Your connections** — a card per Connection:
   - Name · provider · dialect · tags.
   - Status badges, honest and independent: **DEFAULT** · **USED BY RUNTIME** (green) / **TESTABLE** (blue) / **STORED ONLY** (amber) · **Ready / No key yet**.
   - Endpoint + model shown; "Saved, not used by WPCC runtime yet" where true.
   - Inline edit (name/base-URL/model/deployment/tags), key (password, never shown), and an action row: **Test · Set default · Enable/Disable · Duplicate · Delete**.
2. **Empty state** — "No AI connections yet" → create.
3. **Add a connection** — provider select (each labelled *used by runtime* / *testable* / *stored only*), name, base URL (for local/gateway/Azure/custom), model, key, tags.
4. **Feature routing** — per-feature connection selector (only runtime-usable connections offered); "the seam where failover and cost routing will live."
5. **After-key guidance** (preserved from 5C) + **security note**.

## Required UX — coverage
Create unlimited connections ✅ · Duplicate ✅ (key omitted) · Enable/Disable ✅ · Set default ✅ · Rename ✅ · Tag ✅ · Select provider ✅ · Select model ✅ · Configure endpoint ✅ · Configure credentials ✅ · Test ✅ · Delete ✅ · See health (last test + Ready) ✅ · See last successful test ✅ · See capabilities (runtime/testable/stored badges) ✅ · See routing ✅ · See feature usage (routing selectors) ✅.

## Comprehension without docs (first-timer)
- The default Anthropic connection works out of the box (bootstrap migration), so a newcomer sees a working "USED BY RUNTIME" connection immediately if a key exists.
- Provider options are self-labelling ("used by runtime" / "testable" / "stored only") so the user understands capability before choosing.
- Plain-language framing kept from 5A–5C; the platform model is *progressive* — one connection reads like simple setup; many connections reveal environments + routing.

## Honesty principle (UX-level)
The UI **never** implies a stored-only provider will generate. Badges, the routing exclusion, and "Saved, not used by WPCC runtime yet" copy keep configuration and execution clearly separate. Configuration is allowed; faked execution is forbidden — and visibly so.

## Deliberately avoided
No chat UI (LibreChat/Open WebUI lane); no visual routing canvas (Langflow); no model marketplace (OpenRouter business); no content-generation product (AI Engine lane). WPCC stays the governed operations layer.
