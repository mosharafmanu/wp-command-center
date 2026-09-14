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

const assertAdminMark = async (targetPage, { selected = null, collapsed = null } = {}) => {
	const menu = targetPage.locator('#toplevel_page_wp-command-center').first();
	const mark = menu.locator('.wp-menu-image.svg').first();
	await mark.waitFor({ state: 'attached', timeout: 10000 });
	const details = await menu.evaluate(element => {
		const markElement = element.querySelector('.wp-menu-image.svg');
		const markStyle = getComputedStyle(markElement);
		const rect = markElement.getBoundingClientRect();
		return {
			background: markStyle.backgroundImage,
			backgroundSize: markStyle.backgroundSize,
			width: rect.width,
			height: rect.height,
			selected: element.classList.contains('wp-has-current-submenu') || element.classList.contains('current'),
			collapsed: document.body.classList.contains('folded'),
			label: element.querySelector('.wp-menu-name')?.textContent?.trim(),
		};
	});
	const menuIsVisible = (await targetPage.viewportSize()).width >= 783;
	if (!details.background.includes('data:image/svg+xml') || (menuIsVisible && (details.width < 16 || details.height < 16)) || details.label !== 'SiteRadian') {
		throw new Error(`Admin menu mark is not a usable labelled inline SVG: ${JSON.stringify(details)}`);
	}
	if (selected !== null && details.selected !== selected) {
		throw new Error(`Admin menu selected-state mismatch: ${JSON.stringify(details)}`);
	}
	if (collapsed !== null && menuIsVisible && details.collapsed !== collapsed) {
		throw new Error(`Admin menu collapsed-state mismatch: ${JSON.stringify(details)}`);
	}
};

const assertHeaderMark = async targetPage => {
	const mark = targetPage.locator('.wpcc-shell__brand-mark').first();
	await mark.waitFor({ state: 'visible', timeout: 10000 });
	const details = await mark.evaluate(element => ({
		src: element.currentSrc || element.src,
		loaded: Boolean(element.complete && element.naturalWidth > 0 && element.naturalHeight > 0),
		width: element.getBoundingClientRect().width,
		height: element.getBoundingClientRect().height,
		alt: element.getAttribute('alt'),
	}));
	if (!details.loaded || !details.src.includes('/assets/brand/wpcc-mark.svg') || details.width < 24 || details.height < 24 || details.alt !== 'SiteRadian') {
		throw new Error(`Page header is not using the full decorative SiteRadian mark: ${JSON.stringify(details)}`);
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
		const name = card.querySelector('.wpcc-ai-pick__name');
		const badge = card.querySelector('.wpcc-ai-badge');
		const iconRect = icon?.getBoundingClientRect();
		const nameRect = name?.getBoundingClientRect();
		const badgeRect = badge?.getBoundingClientRect();
		const nameStyle = name ? getComputedStyle(name) : null;
		return {
			name: name?.textContent?.trim(),
			selected: card.classList.contains('is-selected'),
			ariaCurrent: card.getAttribute('aria-current'),
			iconSrc: icon?.src,
			iconAlt: icon?.getAttribute('alt'),
			iconHidden: icon?.getAttribute('aria-hidden'),
			iconLoaded: Boolean(icon?.complete && icon.naturalWidth > 0 && icon.naturalHeight > 0),
			iconWidth: iconRect?.width,
			iconHeight: iconRect?.height,
			iconContentWidth: icon ? parseFloat(getComputedStyle(icon).width) : 0,
			nameLines: nameRect && nameStyle ? Math.round(nameRect.height / parseFloat(nameStyle.lineHeight)) : 0,
			cardWidth: card.getBoundingClientRect().width,
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
	if (details.some(item => Math.abs(item.iconWidth - 36) > 0.5 || Math.abs(item.iconHeight - 36) > 0.5 || Math.abs(item.iconContentWidth - 22) > 0.5 || item.overlapsBadge)) {
		throw new Error(`Connections icon sizing/overlap failure: ${JSON.stringify(details)}`);
	}
	const longNames = new Set(['Codex in ChatGPT Desktop', 'Continue for VS Code', 'GitHub Copilot in VS Code']);
	if (details.some(item => longNames.has(item.name) && item.nameLines > 2)) {
		throw new Error(`Long integration names exceed the deliberate two-line limit: ${JSON.stringify(details.filter(item => longNames.has(item.name)))}`);
	}
	const selected = details.filter(item => item.selected);
	if (selected.length !== 1 || selected[0].ariaCurrent !== 'page') {
		throw new Error(`Selected card contract changed: ${JSON.stringify(selected)}`);
	}

	const selectedCard = cards.filter({ hasText: selected[0].name }).first();
	await selectedCard.focus();
	const focusAndSelection = await selectedCard.evaluate(card => {
		const style = getComputedStyle(card);
		const confirmation = getComputedStyle(card, '::after');
		return {
			outline: style.outlineStyle,
			outlineWidth: style.outlineWidth,
			borderColor: style.borderColor,
			confirmationContent: confirmation.content,
			confirmationWidth: confirmation.width,
			confirmationColor: confirmation.backgroundColor,
		};
	});
	if (focusAndSelection.outline === 'none' || focusAndSelection.outlineWidth === '0px' || focusAndSelection.confirmationWidth !== '18px' || !focusAndSelection.confirmationContent.includes('✓') || focusAndSelection.confirmationColor === 'rgba(0, 0, 0, 0)') {
		throw new Error(`Keyboard focus or selected confirmation is not visible: ${JSON.stringify(focusAndSelection)}`);
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
	const grid = await targetPage.locator('.wpcc-ai-family-grid').evaluate(element => ({
		columns: getComputedStyle(element).gridTemplateColumns.split(' ').length,
		width: element.getBoundingClientRect().width,
	}));
	const editorGrid = await targetPage.locator('.wpcc-ai-family--wide .wpcc-ai-picks').evaluate(element => ({
		columns: getComputedStyle(element).gridTemplateColumns.split(' ').length,
		width: element.getBoundingClientRect().width,
	}));
	if (grid.width >= 900 && (grid.columns !== 3 || editorGrid.columns !== 4)) {
		throw new Error(`Wide Connections grid lost its 3-family/4-editor rhythm: ${JSON.stringify({ grid, editorGrid })}`);
	}
	if (grid.width < 560 && (grid.columns !== 1 || editorGrid.columns !== 1)) {
		throw new Error(`Narrow Connections grid must be single-column: ${JSON.stringify({ grid, editorGrid })}`);
	}
	await targetPage.evaluate(() => document.activeElement?.blur());
};

for (const [number, name, url, selector] of screens) {
	await page.goto(`${base}${url}`, { waitUntil: 'networkidle' });
	await waitForSettledProduct(selector);
	await assertAdminMark(page, { selected: true });
	await assertHeaderMark(page);
	if (name === 'Connections') await assertConnectionIcons(page);
	await page.evaluate(() => window.scrollTo(0, 0));
	await page.waitForTimeout(100);
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
		await assertAdminMark(page, { selected: true });
		await assertHeaderMark(page);
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

// The responsive menu glyph is checked in all real wp-admin modes. The full mark
// remains reserved for page headers; the optical 20px drawing belongs only in nav.
await page.setViewportSize({ width: 1440, height: 1000 });
await page.goto(`${base}/wp-admin/admin.php?page=wp-command-center`, { waitUntil: 'networkidle' });
await waitForSettledProduct('#wpcc-home-brandline');
await assertAdminMark(page, { selected: true, collapsed: false });
await assertHeaderMark(page);
await page.locator('#toplevel_page_wp-command-center .wp-menu-image').screenshot({ path: path.join(out, 'qa-admin-selected.png') });

await page.goto(`${base}/wp-admin/index.php`, { waitUntil: 'networkidle' });
await page.locator('#dashboard-widgets-wrap').waitFor({ state: 'attached', timeout: 10000 });
await assertAdminMark(page, { selected: false, collapsed: false });
await page.locator('#toplevel_page_wp-command-center .wp-menu-image').screenshot({ path: path.join(out, 'qa-admin-unselected.png') });

await page.locator('#collapse-button').click();
await page.waitForTimeout(180);
await assertAdminMark(page, { selected: false, collapsed: true });
await page.locator('#toplevel_page_wp-command-center .wp-menu-image').screenshot({ path: path.join(out, 'qa-admin-collapsed.png') });
await page.locator('#collapse-button').click();
await page.waitForTimeout(180);
console.log('PASS admin: selected, unselected, collapsed, and full header marks');

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
await assertAdminMark(retinaPage, { selected: true });
await assertHeaderMark(retinaPage);
await retinaPage.locator('#toplevel_page_wp-command-center .wp-menu-image').screenshot({ path: path.join(out, 'qa-admin-retina.png') });
await retinaContext.close();
console.log('PASS retina: Connections icons and admin mark at 2x density');

if (browserErrors.length) {
	throw new Error(`Browser errors:\n${browserErrors.join('\n')}`);
}
if (localNetworkIssues.length) {
	throw new Error(`Local HTTP failures:\n${localNetworkIssues.join('\n')}`);
}

await browser.close();
console.log('RESULT: browser visual QA passed');
