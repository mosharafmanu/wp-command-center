# PROGRAM-6S — Connection Health

`Ai\Platform\Health` (read-only) turns a connection's stored last-test + config into a human status, color, dot, and **next recommended action** — no calls, no runtime.

## States (honest, derived)
| State | When | Color | Next action |
|---|---|---|---|
| **Disabled** | `enabled=false` | grey | Enable to use it. |
| **Needs a key** | no usable credential | amber | Add a key (or define the constant). |
| **Not tested yet** | key present, never tested / `untested` | blue | Run a test. |
| **Healthy** | last test `ok`, latency < 4s | green | Nothing to do. |
| **Healthy — slow** | `ok` but latency ≥ 4s | amber | Consider a faster model/region. |
| **Authentication failed** | `api_error_401/403` | red | Paste a new key, test again. |
| **Rate limited** | `api_error_429` | amber | Wait / check plan. |
| **Unreachable** | `request_failed` / `no_endpoint` | red | Check base URL + server. |
| **Needs attention** | other failure | red | Review + re-test. |

## Where it shows
- **Per card:** a colored dot + label in the top-right, plus the "next action" line under the metadata.
- **Dashboard rollup:** `Health::summary()` powers the "Healthy" KPI + the attention warning banner.
- **Telemetry (honest):** when a test runs, the controller measures **latency (ms)** and the tester reports **discovered model count** from the *same* `/models` response — both shown on the card ("Last test 2 min ago · 320 ms · 47 models"). No extra calls; no faked metrics.

## Honesty
Health is derived **only** from data the platform already stores (the last test the user ran). It never invents a status. "Healthy" requires an actual passing test — an untested connection says "Not tested yet," never "Healthy."
