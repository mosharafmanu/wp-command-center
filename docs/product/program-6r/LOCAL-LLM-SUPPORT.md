# PROGRAM-6R — Local LLM & Gateway Support

## The most-demanded near-term feature, now configurable
6X identified that "use my own / local model" was the likely first technical-partner request and that Program-6 **could not represent it** (no endpoint field). 6R fixes this structurally.

## How local/self-hosted/gateway providers work now
All speak the **openai-compatible** dialect over a **base URL**. The Connection's `endpoint` field carries it; the single openai-compatible tester verifies it; status is honest.

| Provider | Catalogue entry | Default endpoint | Key | Today |
|---|---|---|---|---|
| **Ollama** | `ollama` (local) | `http://localhost:11434/v1` | optional | **Configurable + connection-testable** |
| **LM Studio** | `lmstudio` (local) | `http://localhost:1234/v1` | optional | Configurable + testable |
| **vLLM** | `vllm` (self-hosted) | (set yours) | optional | Configurable + testable |
| **Custom / gateway** | `custom-openai` | (set yours) | optional | Configurable + testable (LiteLLM, Portkey, internal proxy) |
| **OpenRouter / Groq / Together / Fireworks / DeepInfra** | dedicated entries | each provider's base | required | Configurable + testable |

- **Keyless local models:** `key_optional` providers don't require a key; `has_secret()` treats them as configured; the tester sends no `Authorization` header. The "Test" works against a running local server.
- **Honest runtime status:** all of these are **TESTABLE** but **not USED BY RUNTIME** (the openai-compatible *transport* for WPCC's feature generators is a future, localized addition). The UI says exactly that ("Saved, not used by WPCC runtime yet"). **No faked execution.**

## What turns them "runtime-usable" later — without a rewrite
When the openai-compatible transport adapter ships (one adapter, ~3 total), `Dialect::openai-compatible.runtime_supported` flips to true and **every** openai-compatible connection — including every local model and gateway — becomes selectable as a default and a feature route. **Zero** new per-provider code; the connections already configured by users light up. That is the architectural payoff of dialect-centric design.

## Functionally verified (6R test)
- Creating an `ollama` connection with a `localhost:11434/v1` endpoint and no key → opaque id, dialect=openai-compatible, endpoint stored, `runtime_usable=false`, cannot be default/route (honest). ✅
