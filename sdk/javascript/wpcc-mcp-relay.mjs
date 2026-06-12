#!/usr/bin/env node
/**
 * WP Command Center — MCP stdio↔HTTP Relay
 *
 * Bridges Claude Desktop (stdio MCP transport) to WPCC (HTTP MCP endpoint).
 * Reads JSON-RPC messages from stdin, forwards to the WPCC MCP HTTP endpoint,
 * writes responses to stdout.
 *
 * Environment variables (set by Claude Desktop config):
 *   WPCC_MCP_URL  — Full URL of the WPCC MCP endpoint
 *   WPCC_TOKEN    — Bearer token for authentication
 *
 * Usage (in claude_desktop_config.json):
 *   { "command": "node", "args": ["/path/to/wpcc-mcp-relay.mjs"], "env": { ... } }
 */

import { createInterface } from 'node:readline';

const MCP_URL = process.env.WPCC_MCP_URL;
const TOKEN   = process.env.WPCC_TOKEN;

if (!MCP_URL || !TOKEN) {
	process.stderr.write('WPCC MCP Relay: WPCC_MCP_URL and WPCC_TOKEN must be set\n');
	process.exit(1);
}

const rl = createInterface({ input: process.stdin, terminal: false });

async function forward(request) {
	const response = await fetch(MCP_URL, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'Authorization': `Bearer ${TOKEN}`,
		},
		body: JSON.stringify(request),
	});

	if (!response.ok) {
		process.stderr.write(`WPCC relay: HTTP ${response.status} for ${request.method || 'unknown'}\n`);
		if (request.id !== undefined && request.id !== null) {
			return {
				jsonrpc: '2.0',
				id: request.id,
				error: { code: -32603, message: `Upstream HTTP ${response.status}` },
			};
		}
		return null;
	}

	const text = await response.text();
	if (!text) return null;

	try {
		return JSON.parse(text);
	} catch {
		process.stderr.write(`WPCC relay: invalid JSON response for ${request.method || 'unknown'}\n`);
		if (request.id !== undefined && request.id !== null) {
			return { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: 'Invalid upstream response' } };
		}
		return null;
	}
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
