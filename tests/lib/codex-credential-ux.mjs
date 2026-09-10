// Execute the rendered production token-fill and next-step handlers against a small DOM double.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {copyCredential} from './codex-copy-handler.mjs';
let passed = 0;
for (const client of ['codex', 'chatgpt']) {
  const html = fs.readFileSync(`${process.argv[2]}/${client}.html`, 'utf8');
  const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
  const decode = s => s.replaceAll('&quot;', '"').replaceAll('&#039;', "'").replaceAll('&gt;', '>').replaceAll('&lt;', '<').replaceAll('&amp;', '&');
  const slots = [...html.matchAll(/<(?:code|pre)\b[^>]*data-wpcc-token-slot[^>]*>([\s\S]*?)<\/(?:code|pre)>/g)].map(m => ({textContent: decode(m[1])}));
  const config = {textContent: decode(html.match(/id="wpcc-config-block">([\s\S]*?)<\/pre>/)[1])};
  const original = config.textContent;
  const input = null;
  const next = {getAttribute: () => '#wpcc-guided-setup', addEventListener(_, fn) {this.click = fn;}};
  let focused = false, scrolled = false;
  const panel = {querySelector: () => ({focus: () => {focused = true;}}), scrollIntoView: () => {scrolled = true;}, classList: {add(){}, remove(){}}, offsetWidth: 1};
  const document = {
    getElementById: id => ({'wpcc-token-fill':input, 'wpcc-config-block':config, 'wpcc-token-next':next}[id] || null),
    querySelectorAll: () => slots,
    querySelector: selector => selector === '#wpcc-guided-setup' ? panel : null,
  };
  const context = {document, window: {location: {hash:''}, matchMedia: () => ({matches:true})}};
  vm.runInNewContext(script.slice(script.indexOf('var tokenFill ='), script.indexOf('\n\t/*\n\t * Copy buttons')), context);
  assert.equal(config.textContent, original);
  assert(!slots.some(s => s.textContent.includes('export WPCC_TOKEN') || s.textContent.includes('launchctl setenv')));
  const copied = await copyCredential(html);
  assert(copied.includes('REDACTED_ONE_TIME_TOKEN'));
  assert(!copied.includes('${WPCC_TOKEN}'));
  assert(!slots.some(s => s.textContent.includes('codex mcp add') && s.textContent.includes('REDACTED_ONE_TIME_TOKEN')));
  passed += 5;

  // A deliberate card selection advances once; an ordinary initial load does not.
  const selectionSource = script.slice(
    script.indexOf('var accessTitle ='),
    script.indexOf('\n\t/*\n\t * Token creation dialog')
  );
  const runSelection = href => {
	const stats = {focusCount: 0, scrollCount: 0, replaceCount: 0, click: null};
    const title = {
	  focus: options => { assert.equal(options.preventScroll, true); stats.focusCount++; },
	  scrollIntoView: options => { assert.equal(options.block, 'start'); stats.scrollCount++; },
    };
	const selectionDocument = {getElementById: id => id === 'wpcc-create-access-title' ? title : null};
    const location = {href};
    const selectionWindow = {
      location,
	  history: {replaceState: () => { stats.replaceCount++; }},
      requestAnimationFrame: fn => fn(),
      matchMedia: () => ({matches: true}),
    };
    vm.runInNewContext(selectionSource, {document: selectionDocument, window: selectionWindow, URL});
	return stats;
  };
  const deliberate = runSelection('https://example.test/wp-admin/?client=codex&wpcc_next=access');
  assert.equal(deliberate.focusCount, 1);
  assert.equal(deliberate.scrollCount, 1);
  assert.equal(deliberate.replaceCount, 1);
  const initial = runSelection('https://example.test/wp-admin/?client=codex');
  assert.equal(initial.focusCount, 0);
  assert.equal(initial.scrollCount, 0);
  assert.equal(initial.replaceCount, 0);
	assert.equal(html.includes('wpcc-selection-next'), false);
	assert.equal(html.includes('Next: Create access token'), false);
	assert.equal((html.match(/id="wpcc-tokenmake-open"/g) || []).length, 1);
	passed += 9;

  vm.runInNewContext(script.slice(script.indexOf('var nextBtn ='), script.indexOf('\n\t// Live, browser-only')), context);
  next.click({preventDefault(){}});
  assert(focused && scrolled);
  passed++;
  console.log(`PASS: ${client} Copy handler copies complete rendered command; CTA navigates to shared setup`);
}
console.log(`${passed} passed, 0 failed`);
