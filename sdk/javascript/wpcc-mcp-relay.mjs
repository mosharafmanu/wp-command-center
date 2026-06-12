#!/usr/bin/env node
/**
 * WP Command Center — MCP stdio↔HTTP Relay
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

const RELAY_VERSION = '2.0.0';
const MCP_URL       = process.env.WPCC_MCP_URL;
const TOKEN         = process.env.WPCC_TOKEN;

if (!MCP_URL || !TOKEN) {
	process.stderr.write('WPCC MCP Relay: WPCC_MCP_URL and WPCC_TOKEN must be set\n');
	process.exit(1);
}

process.stderr.write(`WPCC MCP Relay v${RELAY_VERSION} starting\n`);
process.stderr.write(`WPCC MCP Relay: endpoint ${MCP_URL}\n`);

const rl = createInterface({ input: process.stdin, terminal: false });

/**
 * Forward a single JSON-RPC message to the WPCC HTTP endpoint.
 * Returns the parsed response, or null for notifications / empty replies.
 */
async function forward(request) {
	let response;
	try {
		response = await fetch(MCP_URL, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Authorization': `Bearer ${TOKEN}`,
			},
			body: JSON.stringify(request),
		});
	} catch (err) {
		process.stderr.write(`WPCC relay: fetch failed: ${err.message}\n`);
		// Synthesise a JSON-RPC error so the client gets a response
		// for requests (not notifications).
		if (request.id != null) {
			return { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: `Connection failed: ${err.message}` } };
		}
		return null;
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
		process.stderr.write('WPCC relay: invalid JSON on stdin\n');
		return;
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
