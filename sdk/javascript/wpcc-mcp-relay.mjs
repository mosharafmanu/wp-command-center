#!/usr/bin/env node
/**
 * Action Steward — MCP stdio↔HTTP Relay
 *
 * Bridges Claude Desktop and other stdio-based MCP clients to the WPCC
 * HTTP MCP endpoint.
 *
 * Environment variables (set by client config):
 *   WPCC_MCP_URL     — Full URL of the WPCC MCP endpoint
 *   WPCC_TOKEN       — Bearer token for authentication
 *   WPCC_RELAY_VERSION — Relay version (for startup logging)
 *
 * JSON-RPC 2.0 §4.1: Notifications (messages without an "id") MUST NOT
 * receive a response. This relay silently drops notifications and only
 * writes responses for requests that carry a valid "id".
 */

import { createInterface } from 'node:readline';
import { randomUUID } from 'node:crypto';

const RELAY_VERSION = '2.1.0';
const MCP_URL       = process.env.WPCC_MCP_URL;
const TOKEN         = process.env.WPCC_TOKEN;
// Abort a request that hangs longer than this (default 120s) so the relay never
// blocks forever. Generous by default to avoid aborting legitimately long writes.
const TIMEOUT_MS    = Number(process.env.WPCC_RELAY_TIMEOUT_MS) || 120000;
// Initial attempt + one retry. Safe because every write (tools/call) carries an
// idempotency key, so a retry that actually reached the server is deduplicated
// server-side instead of executing twice.
const MAX_ATTEMPTS  = 2;

if (!MCP_URL || !TOKEN) {
	process.stderr.write('Action Steward MCP Relay: WPCC_MCP_URL and WPCC_TOKEN must be set\n');
	process.exit(1);
}

process.stderr.write(`Action Steward MCP Relay v${RELAY_VERSION} starting\n`);
process.stderr.write(`Action Steward MCP Relay: endpoint ${MCP_URL}\n`);

const rl = createInterface({ input: process.stdin, terminal: false });

/**
 * Forward a single JSON-RPC message to the WPCC HTTP endpoint.
 * Returns the parsed response, or null for notifications / empty replies.
 */
async function forward(request) {
	let response = null;

	// Attempt with a timeout; retry once on network failure / timeout. The same
	// `request` object (including its idempotency_key) is reused across attempts,
	// so the server deduplicates a retry rather than executing it twice.
	for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
		try {
			response = await fetch(MCP_URL, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Authorization': `Bearer ${TOKEN}`,
				},
				body: JSON.stringify(request),
				signal: controller.signal,
			});
			clearTimeout(timer);
			break; // Got an HTTP response (ok or not) — handle it below.
		} catch (err) {
			clearTimeout(timer);
			const aborted = err.name === 'AbortError';
			process.stderr.write(`WPCC relay: fetch ${aborted ? 'timed out' : 'failed'} (attempt ${attempt}/${MAX_ATTEMPTS}): ${err.message}\n`);
			if (attempt < MAX_ATTEMPTS) {
				continue;
			}
			// Synthesise a JSON-RPC error so the client gets a response
			// for requests (not notifications).
			if (request.id != null) {
				return { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: `Connection ${aborted ? 'timed out' : 'failed'}: ${err.message}` } };
			}
			return null;
		}
	}

	// HTTP 204 = notification was processed, no body expected.
	if (response.status === 204) {
		return null;
	}

	if (!response.ok) {
		process.stderr.write(`WPCC relay: HTTP ${response.status} for ${request.method || 'unknown'}\n`);
		if (request.id != null) {
			return { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: `Upstream HTTP ${response.status}` } };
		}
		return null;
	}

	const text = await response.text();
	if (!text || text === 'null') {
		return null;
	}

	let parsed;
	try {
		parsed = JSON.parse(text);
	} catch {
		process.stderr.write(`WPCC relay: invalid JSON response for ${request.method || 'unknown'}\n`);
		if (request.id != null) {
			return { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: 'Invalid upstream response' } };
		}
		return null;
	}

	// Guard: only JSON-RPC response objects with an "id" are written to stdout.
	if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed) || parsed.id == null) {
		process.stderr.write(`WPCC relay: non-RPC response for ${request.method || 'unknown'}, dropped\n`);
		return null;
	}

	return parsed;
}

rl.on('line', async (line) => {
	line = line.trim();
	if (!line) return;

	let request;
	try {
		request = JSON.parse(line);
	} catch {
		process.stderr.write('Action Steward relay: invalid JSON on stdin\n');
		return;
	}

	// Tag every tool call with a stable idempotency key (as a sibling of
	// `arguments`, so the operation never sees it). Generated once here and reused
	// across retries, it lets the server return the recorded result instead of
	// re-executing a call whose response was lost to a timeout.
	if (request && request.method === 'tools/call') {
		if (!request.params || typeof request.params !== 'object') {
			request.params = {};
		}
		if (request.params.idempotency_key == null) {
			request.params.idempotency_key = randomUUID();
		}
		// Log the key so a call that later times out can be reconciled via
		// change_history operation_status (did it commit?) instead of guessing.
		const tool = request.params && request.params.name ? request.params.name : 'unknown';
		process.stderr.write(`WPCC relay: tools/call ${tool} idempotency_key=${request.params.idempotency_key}\n`);
	}

	const response = await forward(request);
	if (response) {
		process.stdout.write(JSON.stringify(response) + '\n');
	}
});

rl.on('close', () => {
	process.exit(0);
});

process.on('SIGTERM', () => process.exit(0));
process.on('SIGINT', () => process.exit(0));
