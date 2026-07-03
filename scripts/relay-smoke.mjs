#!/usr/bin/env node
/**
 * WPCC MCP Relay smoke test — the relay must BOOT and ANSWER, not merely lint.
 *
 * `node --check` only validates syntax; it does not catch a relay that dies on
 * boot (bad import, config path, top-level await, port bind). This harness
 * exercises the real thing end-to-end and exits non-zero on any failure, so a
 * relay change that ships syntax-clean-but-dead cannot pass review.
 *
 * Phases (all mandatory unless the env for a live phase is absent):
 *   BOOT   — spawn the relay; it must stay up and complete initialize.
 *   TOOLS  — initialize → tools/list must return EXACTLY expected count (42).
 *   CALL   — a real tools/call (system_info) must return a structured result.
 *   TIMEOUT— point the relay at a socket that hangs; the AbortController must
 *            fire and the relay must return a structured JSON-RPC timeout error
 *            (fully offline / deterministic — no live site needed).
 *   AUDIT  — fire an unknown action at every action-based tool; each must return
 *            a STRUCTURED error within 5s, and any wpcc_invalid_*_action must
 *            enumerate valid actions in its message (Task 3a contract).
 *
 * Config via env:
 *   WPCC_MCP_URL, WPCC_TOKEN   required for TOOLS/CALL/AUDIT (live phases)
 *   RELAY                      path to relay .mjs (default: ./sdk/javascript/wpcc-mcp-relay.mjs)
 *   EXPECTED_TOOLS             expected tools/list count (default: 42)
 *   SMOKE_SKIP_LIVE=1          run only BOOT + TIMEOUT (offline)
 */
import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const RELAY = process.env.RELAY || resolve(__dirname, '..', 'sdk', 'javascript', 'wpcc-mcp-relay.mjs');
const EXPECTED_TOOLS = Number(process.env.EXPECTED_TOOLS || 42);
const MCP_URL = process.env.WPCC_MCP_URL;
const TOKEN = process.env.WPCC_TOKEN;
const SKIP_LIVE = process.env.SMOKE_SKIP_LIVE === '1';
// AUDIT mode: 'strict' (default) → invalid-action mismatches are fatal (the
// Task 3a contract, for post-deploy proof). 'report' → mismatches print as
// warnings but do not fail the run (pre-deploy relay-health gate against a
// target that may not yet carry the runtime fix). 'off' → skip the audit.
const AUDIT_MODE = (process.env.SMOKE_AUDIT || 'strict').toLowerCase();

// Action-based tools that must reject an unknown action with a structured error.
// Non-action tools (system_info, *_seed, safe_*, media_import) are intentionally
// excluded — they take no `action` param.
const ACTION_TOOLS = [
  'content_manage', 'acf_manage', 'term_manage', 'cache_manage', 'snapshot_manage',
  'theme_manage', 'plugin_manage', 'option_manage', 'user_manage', 'media_manage',
  'woocommerce_manage', 'forms_manage', 'menu_manage', 'settings_manage', 'approval_manage',
  'search_manage', 'bulk_manage', 'workflow_manage', 'comments_manage', 'widgets_manage',
  'cpt_manage', 'seo_manage', 'site_builder_manage', 'elementor_manage', 'report_manage',
  'media_enhance', 'change_history', 'capability_manage', 'database_inspect', 'wp_cli_bridge',
  'file_manage', 'code_search', 'patch_manage', 'rollback_manage',
];

let failures = 0;
const pass = (m) => console.log(`  \x1b[32m✓\x1b[0m ${m}`);
const fail = (m) => { console.log(`  \x1b[31m✗ ${m}\x1b[0m`); failures++; };
const phase = (m) => console.log(`\n\x1b[1m${m}\x1b[0m`);

/** Spawn a relay process and return an RPC driver over its stdio. */
function spawnRelay(env) {
  const child = spawn(process.execPath, [RELAY], { stdio: ['pipe', 'pipe', 'pipe'], env: { ...process.env, ...env } });
  let stderr = '';
  child.stderr.on('data', (d) => { stderr += d.toString(); });
  const rl = createInterface({ input: child.stdout });
  const waiters = new Map();
  rl.on('line', (line) => {
    line = line.trim(); if (!line) return;
    let o; try { o = JSON.parse(line); } catch { return; }
    if (o.id != null && waiters.has(o.id)) { waiters.get(o.id)(o); waiters.delete(o.id); }
  });
  const send = (o) => child.stdin.write(JSON.stringify(o) + '\n');
  const rpc = (id, method, params, timeoutMs = 15000) => new Promise((res) => {
    const t = setTimeout(() => { waiters.delete(id); res({ __timeout: true }); }, timeoutMs);
    waiters.set(id, (o) => { clearTimeout(t); res(o); });
    send({ jsonrpc: '2.0', id, method, params });
  });
  return { child, send, rpc, exited: () => child.exitCode, stderr: () => stderr, close: () => { try { child.stdin.end(); child.kill(); } catch {} } };
}

async function main() {
  console.log(`relay:  ${RELAY}`);
  console.log(`target: ${MCP_URL || '(none)'}  expect ${EXPECTED_TOOLS} tools`);

  // ── PHASE: TIMEOUT (offline, deterministic) ──────────────────────────────
  phase('PHASE TIMEOUT — AbortController must fire on a hung endpoint');
  await new Promise((done) => {
    const hung = createServer((sock) => { /* accept, never respond */ sock.on('error', () => {}); });
    hung.listen(0, '127.0.0.1', async () => {
      const port = hung.address().port;
      const r = spawnRelay({ WPCC_MCP_URL: `http://127.0.0.1:${port}/mcp`, WPCC_TOKEN: 'smoke', WPCC_RELAY_TIMEOUT_MS: '1200' });
      const t0 = Date.now();
      // MAX_ATTEMPTS=2 × 1200ms ≈ 2.4s; allow generous margin.
      const resp = await r.rpc(1, 'tools/call', { name: 'system_info', arguments: {} }, 8000);
      const dt = Date.now() - t0;
      if (resp.__timeout) fail(`relay never returned on a hung endpoint (>8s) — AbortController did not fire`);
      else if (resp.error && /tim(e|ed)\s*out|timeout/i.test(resp.error.message || '')) pass(`structured timeout error in ${dt}ms: "${resp.error.message}"`);
      else if (resp.error) pass(`structured error in ${dt}ms (abort surfaced): "${resp.error.message}"`);
      else fail(`expected a JSON-RPC error, got: ${JSON.stringify(resp).slice(0, 200)}`);
      r.close(); hung.close(); done();
    });
  });

  if (SKIP_LIVE || !MCP_URL || !TOKEN) {
    if (!SKIP_LIVE) fail('WPCC_MCP_URL / WPCC_TOKEN not set — live phases (BOOT/TOOLS/CALL/AUDIT) skipped');
    else console.log('\n(SMOKE_SKIP_LIVE=1 — live phases skipped)');
    return finish();
  }

  // ── PHASE: BOOT + TOOLS ──────────────────────────────────────────────────
  phase('PHASE BOOT + TOOLS — initialize then tools/list must equal expected count');
  const live = spawnRelay({ WPCC_MCP_URL: MCP_URL, WPCC_TOKEN: TOKEN });
  const initR = await live.rpc(0, 'initialize', { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'smoke', version: '0' } }, 20000);
  if (initR.__timeout || !initR.result) { fail(`initialize did not return (relay boot failed). stderr: ${live.stderr().slice(-300)}`); live.close(); return finish(); }
  pass(`initialize ok — server ${initR.result.serverInfo?.name} v${initR.result.serverInfo?.version}`);
  live.send({ jsonrpc: '2.0', method: 'notifications/initialized' });

  const listR = await live.rpc(1, 'tools/list', {}, 20000);
  const tools = listR.result?.tools || [];
  if (tools.length === EXPECTED_TOOLS) pass(`tools/list returned exactly ${tools.length} tools`);
  else fail(`tools/list returned ${tools.length}, expected ${EXPECTED_TOOLS}`);

  // Completeness critic: any *_manage tool not in the audit list is a coverage gap.
  const names = new Set(tools.map((t) => t.name));
  const uncovered = [...names].filter((n) => n.endsWith('_manage') && !ACTION_TOOLS.includes(n));
  if (uncovered.length) fail(`audit list is missing action tools: ${uncovered.join(', ')}`);

  // ── PHASE: CALL ──────────────────────────────────────────────────────────
  phase('PHASE CALL — a real tools/call must return a structured result');
  const sysR = await live.rpc(2, 'tools/call', { name: 'system_info', arguments: {} }, 20000);
  const sysText = sysR.result?.content?.[0]?.text;
  let sysOk = false;
  try { const j = JSON.parse(sysText); sysOk = !!j.site_url && !sysR.result?.isError; } catch {}
  if (sysOk) pass(`system_info returned structured live data (${JSON.parse(sysText).site_url})`);
  else fail(`system_info did not return a structured result: ${JSON.stringify(sysR).slice(0, 200)}`);

  // ── PHASE: AUDIT ─────────────────────────────────────────────────────────
  if (AUDIT_MODE === 'off') { live.close(); return finish(); }
  phase(`PHASE AUDIT (${AUDIT_MODE}) — every action tool must reject an unknown action (structured, <5s)`);
  const auditFail = (m) => { if (AUDIT_MODE === 'report') console.log(`  \x1b[33m! ${m}\x1b[0m`); else fail(m); };
  let id = 100;
  for (const tool of ACTION_TOOLS.filter((t) => names.has(t))) {
    const t0 = Date.now();
    const r = await live.rpc(id++, 'tools/call', { name: tool, arguments: { action: '__wpcc_smoke_bogus__' } }, 6000);
    const dt = Date.now() - t0;
    if (r.__timeout) { auditFail(`${tool}: HANG (>6s, no response)`); continue; }
    const text = r.result?.content?.[0]?.text ?? JSON.stringify(r.result ?? r.error ?? r);
    let code = '', msg = '', structured = false;
    try { const p = JSON.parse(text); code = p.code || ''; msg = p.message || ''; structured = p.isError === true || p.error === true || !!p.code; }
    catch { structured = false; }
    const isError = r.result?.isError === true || !!r.error;
    if (dt >= 5000) { auditFail(`${tool}: responded in ${dt}ms (≥5s)`); continue; }
    if (!(structured || isError)) { auditFail(`${tool}: not a structured error: ${text.slice(0, 160)}`); continue; }
    // If it reports an invalid-action code, the message MUST enumerate valid actions.
    if (/wpcc_invalid_\w*_?action/.test(code)) {
      if (/valid actions:/i.test(msg)) pass(`${tool}: ${code} +list (${dt}ms)`);
      else auditFail(`${tool}: ${code} but NO valid-actions list: "${msg}"`);
    } else {
      // Precondition short-circuit (e.g. elementor_inactive, operation_not_available) — acceptable.
      pass(`${tool}: structured "${code}" (${dt}ms, precondition)`);
    }
  }
  live.close();
  finish();
}

function finish() {
  phase(failures === 0 ? '\x1b[32mSMOKE PASS\x1b[0m' : `\x1b[31mSMOKE FAIL — ${failures} failure(s)\x1b[0m`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => { console.error('smoke harness crashed:', e); process.exit(2); });
