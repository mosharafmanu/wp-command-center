// Execute the production browser-only token gate against small DOM doubles.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const html = fs.readFileSync(`${process.argv[2]}/muse-reload.html`, 'utf8');
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
new vm.Script(script);

const decode = value => value
  .replaceAll('&quot;', '"').replaceAll('&#039;', "'")
  .replaceAll('&gt;', '>').replaceAll('&lt;', '<').replaceAll('&amp;', '&');
const makeAttrNode = (attrs = {}) => ({
  attrs: {...attrs}, hidden: Object.hasOwn(attrs, 'hidden'), disabled: Object.hasOwn(attrs, 'disabled'),
  hasAttribute(name) { return Object.hasOwn(this.attrs, name); },
  getAttribute(name) { return this.attrs[name] ?? null; },
  setAttribute(name, value) { this.attrs[name] = String(value); },
});
const slotMatches = [...html.matchAll(/<pre\b([^>]*)data-wpcc-token-slot([^>]*)>([\s\S]*?)<\/pre>/g)];
const slots = slotMatches.map(match => {
  const template = decode(match[3]);
  const node = makeAttrNode({});
  node.textContent = template;
  if ((match[1] + match[2]).includes('data-wpcc-token-template=')) {
    const attr = (match[1] + match[2]).match(/data-wpcc-token-template="([\s\S]*?)"/);
    node.attrs['data-wpcc-token-template'] = decode(attr?.[1] ?? template);
  }
  return node;
});
const controls = [...html.matchAll(/<(?:button|a)\b[^>]*data-wpcc-requires-token-control[^>]*>/g)].map(() => makeAttrNode({disabled: '', 'aria-disabled': 'true'}));
const previews = [...html.matchAll(/<details\b[^>]*data-wpcc-requires-token-preview[^>]*>/g)].map(() => makeAttrNode({hidden: ''}));
const payloads = [...html.matchAll(/<pre\b[^>]*data-wpcc-requires-token-payload[^>]*>/g)].map(() => makeAttrNode({hidden: ''}));
const messages = [...html.matchAll(/<[^>]+data-wpcc-token-needed[^>]*>/g)].map(() => makeAttrNode({}));
let inputHandler;
const input = {value: '', addEventListener(type, fn) { if (type === 'input') inputHandler = fn; }, focus() {}};
const selectorMap = {
  '[data-wpcc-token-slot]': slots,
  '[data-wpcc-requires-token-control]': controls,
  '[data-wpcc-requires-token-preview]': previews,
  '[data-wpcc-requires-token-payload]': payloads,
  '[data-wpcc-token-needed]': messages,
};
const document = {
  querySelectorAll(selector) { return selectorMap[selector] ?? []; },
  getElementById(id) { return id === 'wpcc-token-fill' ? input : null; },
};
const start = script.indexOf('var tokenFill =');
const end = script.indexOf("\n\t/*\n\t * Cursor's official install link");
vm.runInNewContext(script.slice(start, end), {document, window: {location: {hash: ''}}});

assert(inputHandler, 'production input handler was registered');
assert(controls.length > 0 && controls.every(control => control.disabled));
assert(previews.every(preview => preview.hidden));
assert(payloads.every(payload => payload.hidden));
assert(messages.every(message => !message.hidden));

const saved = 'wpcc_SYNTHETIC_BROWSER_ONLY_TOKEN';
input.value = saved;
inputHandler();
assert(controls.every(control => !control.disabled && control.attrs['aria-disabled'] === 'false'));
assert(previews.every(preview => !preview.hidden));
assert(payloads.every(payload => !payload.hidden));
assert(messages.every(message => message.hidden));
assert(slots.every(slot => !slot.textContent.includes('${WPCC_TOKEN}')));
assert(slots.some(slot => slot.textContent.includes(saved)));

input.value = '';
inputHandler();
assert(controls.every(control => control.disabled));
assert(slots.every(slot => slot.textContent.includes('${WPCC_TOKEN}') || !slot.textContent.includes('Authorization')));

console.log('12 passed, 0 failed');
