#!/usr/bin/env node

/**
 * Capture real WordPress.org screenshots and exercise practical admin widths.
 *
 * Required environment: SR_QA_BASE, SR_QA_USER, SR_QA_PASSWORD.
 * Optional: SR_ASSET_DIR (defaults to wordpress-org-assets in this checkout).
 * The script never creates a token, prints credentials, or persists browser state.
 */

import { chromium } from '/usr/local/lib/node_modules/playwright/index.mjs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const base = String(process.env.SR_QA_BASE || '').replace(/\/$/, '');
const user = process.env.SR_QA_USER || '';
const password = process.env.SR_QA_PASSWORD || '';
const out = process.env.SR_ASSET_DIR || path.join(root, 'wordpress-org-assets');

if (!base || !user || !password) {
	throw new Error('Set SR_QA_BASE, SR_QA_USER, and SR_QA_PASSWORD.');
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
const browserErrors = [];
const networkIssues = [];
const localNetworkIssues = [];
page.on('pageerror', error => browserErrors.push(`page: ${error.message}`));
page.on('console', message => {
	if (message.type() === 'error') browserErrors.push(`console: ${message.text()}`);
});
page.on('requestfailed', request => {
	const issue = `failed ${request.url()}: ${request.failure()?.errorText || 'unknown'}`;
	networkIssues.push(issue);
	// Navigating between admin screens legitimately aborts deferred emoji/avatar
	// requests. Everything else from this isolated site remains release-significant.
	if (request.url().startsWith(base) && request.failure()?.errorText !== 'net::ERR_ABORTED') {
		localNetworkIssues.push(issue);
	}
});
page.on('response', response => {
	if (response.status() >= 400) {
		const issue = `${response.status()} ${response.url()}`;
		networkIssues.push(issue);
		if (response.url().startsWith(base)) localNetworkIssues.push(issue);
	}
});

await page.goto(`${base}/wp-login.php`, { waitUntil: 'networkidle' });
await page.locator('#user_login').fill(user);
await page.locator('#user_pass').fill(password);
await Promise.all([
	page.waitForURL(url => url.pathname.startsWith('/wp-admin/')),
	page.locator('#wp-submit').click(),
]);

const screens = [
	['1', 'Home', '/wp-admin/admin.php?page=wp-command-center', '#wpcc-home-brandline'],
	['2', 'Connections', '/wp-admin/admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants', '#wpcc-choose-app'],
	['4', 'Approvals', '/wp-admin/admin.php?page=wpcc-activity', '.wpcc-apr-clear'],
	['5', 'Changes', '/wp-admin/admin.php?page=wpcc-history', '.wpcc-empty-state'],
	['6', 'Protection', '/wp-admin/admin.php?page=wpcc-settings&wpcc_tab=security', '.wpcc-app'],
];

const waitForSettledProduct = async selector => {
	try {
		await page.locator(selector).first().waitFor({ state: 'visible', timeout: 10000 });
	} catch (error) {
		const excerpt = (await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(-1200);
		throw new Error(`Missing ${selector} at ${page.url()}: ${excerpt}\nNetwork: ${networkIssues.slice(-8).join(' | ')}`, { cause: error });
	}
	await page.waitForTimeout(250);
	const body = await page.locator('body').innerText();
	if (/Failed to load|Fatal error|Parse error|There has been a critical error/i.test(body)) {
		throw new Error('Rendered page contains a failure state.');
	}
};

for (const [number, name, url, selector] of screens) {
	await page.goto(`${base}${url}`, { waitUntil: 'networkidle' });
	await waitForSettledProduct(selector);
	await page.screenshot({ path: path.join(out, `screenshot-${number}.png`) });
	console.log(`PASS screenshot-${number}: ${name}`);
}

// Capture the real token-creation form without creating or exposing a credential.
await page.goto(`${base}/wp-admin/admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants`, { waitUntil: 'networkidle' });
await waitForSettledProduct('#wpcc-choose-app');
await page.locator('#wpcc-tokenmake-open').click();
await page.locator('.wpcc-tokenmake__panel').waitFor({ state: 'visible' });
await page.screenshot({ path: path.join(out, 'screenshot-3.png') });
console.log('PASS screenshot-3: Access setup (pre-credential form)');

// Built-in AI is part of browser QA even though the six-image listing set does
// not spend a screenshot slot on it.
await page.goto(`${base}/wp-admin/admin.php?page=wpcc-built-in-ai`, { waitUntil: 'networkidle' });
await waitForSettledProduct('.wpcc-app');
console.log('PASS browser: Built-in AI');

const widths = [1440, 1180, 900, 782, 480];
for (const width of widths) {
	await page.setViewportSize({ width, height: 900 });
	await page.goto(`${base}/wp-admin/admin.php?page=wp-command-center`, { waitUntil: 'networkidle' });
	await waitForSettledProduct('#wpcc-home-brandline');
	const layout = await page.evaluate(() => ({
		viewport: document.documentElement.clientWidth,
		scroll: document.documentElement.scrollWidth,
		heroVisible: Boolean(document.querySelector('#wpcc-home-brandline')),
	}));
	if (!layout.heroVisible || layout.scroll > layout.viewport + 1) {
		throw new Error(`Dashboard overflow at ${width}px: ${JSON.stringify(layout)}`);
	}
	console.log(`PASS responsive: ${width}px`);
}

if (browserErrors.length) {
	throw new Error(`Browser errors:\n${browserErrors.join('\n')}`);
}
if (localNetworkIssues.length) {
	throw new Error(`Local HTTP failures:\n${localNetworkIssues.join('\n')}`);
}

await browser.close();
console.log('RESULT: browser visual QA passed');
