// Run the production Copy handler with an in-memory clipboard; never log its contents.
import vm from 'node:vm';
export async function copyCredential(html) {
  const decode = s => s.replaceAll('&quot;', '"').replaceAll('&#039;', "'").replaceAll('&gt;', '>').replaceAll('&lt;', '<').replaceAll('&amp;', '&');
  const match = html.match(/<pre[^>]*id="wpcc-credential-macos"[^>]*data-wpcc-credential-command="macos"[^>]*>([\s\S]*?)<\/pre>/);
  if (!match) throw new Error('Missing rendered macOS command');
  const code = {textContent: decode(match[1])};
  const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
  new vm.Script(script); // Validate the complete rendered script, not only the handler.
  let copied;
  const parent = {querySelector: () => null};
  const button = {dataset: {}, textContent:'Copy', parentNode:parent,
    hasAttribute: () => false, getAttribute: name => name === 'data-copy-target' ? 'wpcc-credential-macos' : null, setAttribute(){},
    addEventListener(_, fn){this.click = fn;}};
  const context = {document:{querySelectorAll: () => [button], getElementById: id => id === 'wpcc-credential-macos' ? code : null},
    navigator:{clipboard:{writeText: async text => {copied = text;}}}, setTimeout:()=>0, clearTimeout(){}};
  const start = script.indexOf("document.querySelectorAll('.wpcc-copy-btn')");
  const end = script.indexOf("document.querySelectorAll('.wpcc-select-token-btn')");
  vm.runInNewContext(script.slice(start,end),context);
  button.click.call(button);
  await Promise.resolve();
  if (copied !== code.textContent) throw new Error('Copy did not match rendered command');
  return copied;
}
