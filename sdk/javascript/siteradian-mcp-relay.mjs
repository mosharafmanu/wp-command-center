#!/usr/bin/env node
/**
 * SiteRadian — MCP stdio↔HTTP Relay
 *
 * New configurations use SITERADIAN_MCP_URL and SITERADIAN_TOKEN. The legacy WPCC_*
 * names remain accepted so previously generated configurations keep working.
 */

import { createInterface } from 'node:readline';
import { randomUUID } from 'node:crypto';

const RELAY_VERSION = '2.1.0';
const MCP_URL = process.env.SITERADIAN_MCP_URL || process.env.WPCC_MCP_URL;
const TOKEN = process.env.SITERADIAN_TOKEN || process.env.WPCC_TOKEN;
const TIMEOUT_MS = Number(process.env.SITERADIAN_RELAY_TIMEOUT_MS || process.env.WPCC_RELAY_TIMEOUT_MS) || 120000;
const MAX_ATTEMPTS = 2;

if (!MCP_URL || !TOKEN) {
	process.stderr.write('SiteRadian MCP Relay: SITERADIAN_MCP_URL and SITERADIAN_TOKEN must be set\n');
	process.exit(1);
}

process.stderr.write(`SiteRadian MCP Relay v${RELAY_VERSION} starting\n`);
process.stderr.write(`SiteRadian MCP Relay: endpoint ${MCP_URL}\n`);

const rl = createInterface({ input: process.stdin, terminal: false });

async function forward(request) {
	let response = null;
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
			break;
		} catch (err) {
			clearTimeout(timer);
			const aborted = err.name === 'AbortError';
			process.stderr.write(`SiteRadian relay: fetch ${aborted ? 'timed out' : 'failed'} (attempt ${attempt}/${MAX_ATTEMPTS}): ${err.message}\n`);
			if (attempt < MAX_ATTEMPTS) continue;
			if (request.id != null) {
				return { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: `Connection ${aborted ? 'timed out' : 'failed'}: ${err.message}` } };
			}
			return null;
		}
	}

	if (response.status === 204) return null;
	if (!response.ok) {
		process.stderr.write(`SiteRadian relay: HTTP ${response.status} for ${request.method || 'unknown'}\n`);
		return request.id != null
			? { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: `Upstream HTTP ${response.status}` } }
			: null;
	}

	const text = await response.text();
	if (!text || text === 'null') return null;

	let parsed;
	try {
		parsed = JSON.parse(text);
	} catch {
		process.stderr.write(`SiteRadian relay: invalid JSON response for ${request.method || 'unknown'}\n`);
		return request.id != null
			? { jsonrpc: '2.0', id: request.id, error: { code: -32603, message: 'Invalid upstream response' } }
			: null;
	}

	if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed) || parsed.id == null) {
		process.stderr.write(`SiteRadian relay: non-RPC response for ${request.method || 'unknown'}, dropped\n`);
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
		process.stderr.write('SiteRadian relay: invalid JSON on stdin\n');
		return;
	}

	if (request && request.method === 'tools/call') {
		if (!request.params || typeof request.params !== 'object') request.params = {};
		if (request.params.idempotency_key == null) request.params.idempotency_key = randomUUID();
		const tool = request.params.name || 'unknown';
		process.stderr.write(`SiteRadian relay: tools/call ${tool} idempotency_key=${request.params.idempotency_key}\n`);
	}

	const response = await forward(request);
	if (response) process.stdout.write(JSON.stringify(response) + '\n');
});

rl.on('close', () => process.exit(0));
process.on('SIGTERM', () => process.exit(0));
process.on('SIGINT', () => process.exit(0));
