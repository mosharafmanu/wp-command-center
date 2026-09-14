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
	page.waitForURL(url => url.href.startsWith(base) && url.pathname.includes('/wp-admin/')),
	page.locator('#wp-submit').click(),
]);

const screens = [
	['1', 'Home', '/wp-admin/admin.php?page=wp-command-center', '#wpcc-home-brandline'],
	['2', 'Connections', '/wp-admin/admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants', '#wpcc-choose-app'],
	['4', 'Approvals', '/wp-admin/admin.php?page=wpcc-activity', '.wpcc-app'],
	['5', 'Changes', '/wp-admin/admin.php?page=wpcc-history', '.wpcc-app'],
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

const assertAdminMark = async () => {
	const mark = page.locator('#toplevel_page_wp-command-center .wp-menu-image.svg').first();
	await mark.waitFor({ state: 'attached', timeout: 10000 });
	const details = await mark.evaluate(element => ({
		background: getComputedStyle(element).backgroundImage,
		width: element.getBoundingClientRect().width,
		height: element.getBoundingClientRect().height,
	}));
	const menuIsVisible = (await page.viewportSize()).width >= 783;
	if (!details.background.includes('data:image/svg+xml') || (menuIsVisible && (details.width < 16 || details.height < 16))) {
		throw new Error(`Admin menu mark is not a usable inline SVG: ${JSON.stringify(details)}`);
	}
};

const expectedConnectionNames = [
	'Codex in ChatGPT Desktop',
	'Codex CLI',
	'Claude Desktop',
	'Claude Code',
	'Antigravity CLI',
	'Gemini CLI',
	'Cursor',
	'Continue for VS Code',
	'GitHub Copilot in VS Code',
	'OpenCode',
	'Command Code',
	'Muse Code',
];

const assertConnectionIcons = async targetPage => {
	const cards = targetPage.locator('.wpcc-ai-pick');
	const icons = targetPage.locator('.wpcc-ai-pick__icon');
	if (await cards.count() !== 12 || await icons.count() !== 12) {
		throw new Error(`Connections must render 12 cards and icons; got ${await cards.count()} cards / ${await icons.count()} icons.`);
	}

	const details = await cards.evaluateAll(nodes => nodes.map(card => {
		const icon = card.querySelector('.wpcc-ai-pick__icon');
		const badge = card.querySelector('.wpcc-ai-badge');
		const iconRect = icon?.getBoundingClientRect();
		const badgeRect = badge?.getBoundingClientRect();
		return {
			name: card.querySelector('.wpcc-ai-pick__name')?.textContent?.trim(),
			selected: card.classList.contains('is-selected'),
			ariaCurrent: card.getAttribute('aria-current'),
			iconSrc: icon?.src,
			iconAlt: icon?.getAttribute('alt'),
			iconHidden: icon?.getAttribute('aria-hidden'),
			iconLoaded: Boolean(icon?.complete && icon.naturalWidth > 0 && icon.naturalHeight > 0),
			iconWidth: iconRect?.width,
			iconHeight: iconRect?.height,
			overlapsBadge: Boolean(iconRect && badgeRect && !(iconRect.right <= badgeRect.left || badgeRect.right <= iconRect.left || iconRect.bottom <= badgeRect.top || badgeRect.bottom <= iconRect.top)),
		};
	}));

	if (JSON.stringify(details.map(item => item.name)) !== JSON.stringify(expectedConnectionNames)) {
		throw new Error(`Connections identity/order changed: ${JSON.stringify(details.map(item => item.name))}`);
	}
	if (details.some(item => !item.iconLoaded || item.iconAlt !== '' || item.iconHidden !== 'true')) {
		throw new Error(`Connections icon loading/accessibility failure: ${JSON.stringify(details)}`);
	}
	const baseUrl = new URL(base);
	if (details.some(item => {
		const iconUrl = new URL(item.iconSrc);
		return iconUrl.origin !== baseUrl.origin || !/^\/(?:.*\/)?wp-content\/plugins\/[^/]+\/assets\/integrations\/[^/]+$/.test(iconUrl.pathname);
	})) {
		throw new Error(`Connections icon is not package-local: ${JSON.stringify(details.map(item => item.iconSrc))}`);
	}
	if (details.some(item => Math.abs(item.iconWidth - 24) > 0.5 || Math.abs(item.iconHeight - 24) > 0.5 || item.overlapsBadge)) {
		throw new Error(`Connections icon sizing/overlap failure: ${JSON.stringify(details)}`);
	}
	const selected = details.filter(item => item.selected);
	if (selected.length !== 1 || selected[0].ariaCurrent !== 'page') {
		throw new Error(`Selected card contract changed: ${JSON.stringify(selected)}`);
	}

	const selectedCard = cards.filter({ hasText: selected[0].name }).first();
	await selectedCard.focus();
	const focusAndSelection = await selectedCard.evaluate(card => {
		const style = getComputedStyle(card);
		const accent = getComputedStyle(card, '::after');
		return {
			outline: style.outlineStyle,
			outlineWidth: style.outlineWidth,
			borderColor: style.borderColor,
			accentWidth: accent.width,
			accentColor: accent.backgroundColor,
		};
	});
	if (focusAndSelection.outline === 'none' || focusAndSelection.outlineWidth === '0px' || focusAndSelection.accentWidth === '0px' || focusAndSelection.accentColor === 'rgba(0, 0, 0, 0)') {
		throw new Error(`Keyboard focus or selected accent is not visible: ${JSON.stringify(focusAndSelection)}`);
	}

	const other = targetPage.locator('.wpcc-ai-family--other');
	const summary = other.locator('summary');
	if (await other.getAttribute('open') !== null) {
		throw new Error('Other / Experimental should start collapsed for the default client.');
	}
	await summary.focus();
	await targetPage.keyboard.press('Enter');
	if (await other.getAttribute('open') === null || !await other.locator('.wpcc-ai-pick__name', { hasText: 'Muse Code' }).isVisible()) {
		throw new Error('Other / Experimental did not expand from the keyboard.');
	}
	await targetPage.keyboard.press('Enter');
	if (await other.getAttribute('open') !== null) {
		throw new Error('Other / Experimental did not collapse from the keyboard.');
	}

	const familyRhythm = await targetPage.locator('.wpcc-ai-family:not(.wpcc-ai-family--other) .wpcc-ai-picks').evaluateAll(groups => groups.map(group => {
		const heights = Array.from(group.querySelectorAll('.wpcc-ai-pick')).map(card => card.getBoundingClientRect().height);
		return heights.length ? Math.max(...heights) - Math.min(...heights) : 0;
	}));
	if (familyRhythm.some(delta => delta > 1)) {
		throw new Error(`Connections cards lost equal row rhythm: ${JSON.stringify(familyRhythm)}`);
	}
	await targetPage.evaluate(() => document.activeElement?.blur());
};

for (const [number, name, url, selector] of screens) {
	await page.goto(`${base}${url}`, { waitUntil: 'networkidle' });
	await waitForSettledProduct(selector);
	await assertAdminMark();
	if (name === 'Connections') await assertConnectionIcons(page);
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
	for (const [surface, url, selector] of [
		['Home', '/wp-admin/admin.php?page=wp-command-center', '#wpcc-home-brandline'],
		['Connections', '/wp-admin/admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants', '#wpcc-choose-app'],
	]) {
		await page.goto(`${base}${url}`, { waitUntil: 'networkidle' });
		await waitForSettledProduct(selector);
		await assertAdminMark();
		if (surface === 'Connections') await assertConnectionIcons(page);
		const layout = await page.evaluate(expected => ({
			viewport: document.documentElement.clientWidth,
			scroll: document.documentElement.scrollWidth,
			surfaceVisible: Boolean(document.querySelector(expected)),
			clippedCode: Array.from(document.querySelectorAll('pre, code')).some(node => node.scrollWidth > node.clientWidth + 1 && getComputedStyle(node).overflowX === 'visible'),
		}), selector);
		if (!layout.surfaceVisible || layout.scroll > layout.viewport + 1 || layout.clippedCode) {
			throw new Error(`${surface} overflow at ${width}px: ${JSON.stringify(layout)}`);
		}
		console.log(`PASS responsive: ${surface} ${width}px`);
	}
}

// A separate high-density context verifies that SVG and raster marks stay crisp
// at their actual 24px CSS size rather than relying on source dimensions alone.
const storageState = await page.context().storageState();
const retinaContext = await browser.newContext({
	viewport: { width: 1180, height: 900 },
	deviceScaleFactor: 2,
	storageState,
});
const retinaPage = await retinaContext.newPage();
retinaPage.on('pageerror', error => browserErrors.push(`retina page: ${error.message}`));
retinaPage.on('console', message => {
	if (message.type() === 'error') browserErrors.push(`retina console: ${message.text()}`);
});
retinaPage.on('response', response => {
	if (response.status() >= 400 && response.url().startsWith(base)) {
		localNetworkIssues.push(`retina ${response.status()} ${response.url()}`);
	}
});
await retinaPage.goto(`${base}/wp-admin/admin.php?page=wpcc-settings&wpcc_tab=connections&cpane=assistants`, { waitUntil: 'networkidle' });
await retinaPage.locator('#wpcc-choose-app').waitFor({ state: 'visible' });
await assertConnectionIcons(retinaPage);
await retinaContext.close();
console.log('PASS retina: Connections icons at 2x density');

if (browserErrors.length) {
	throw new Error(`Browser errors:\n${browserErrors.join('\n')}`);
}
if (localNetworkIssues.length) {
	throw new Error(`Local HTTP failures:\n${localNetworkIssues.join('\n')}`);
}

await browser.close();
console.log('RESULT: browser visual QA passed');
