/* Exercise the popup recovery states without a browser, network or diagnostics. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Element {
  constructor(tag = 'div') { this.tag = tag; this.children = []; this.events = {}; this.hidden = false; this.textContent = ''; this.value = ''; }
  append(...children) { this.children.push(...children); if (this.tag === 'select') for (const child of children) if (child.selected) this.value = child.value; }
  before(child) { this.beforeElement = child; }
  addEventListener(name, callback) { this.events[name] = callback; }
  replaceChildren() { this.children = []; this.value = ''; }
  get options() { return this.children; }
  querySelector(selector) { return this.nodes[selector]; }
  querySelectorAll() { return []; }
  showModal() { this.open = true; }
}
const nodes = Object.fromEntries(['dialog', 'form', 'tool', 'status', 'output', 'title'].map(key => [key, new Element(key === 'tool' ? 'select' : 'div')]));
const run = new Element('button'), actions = new Element();
nodes.dialog.nodes = { '.message-actions': actions };
nodes.form.nodes = { '[type=submit]': run };
nodes.form.elements = { host_id: { value: '' } };
const events = {};
let result = { ok: true, job_id: 5, status: 'failed', output: 'socket: Operation not permitted' };
const context = {
  document: {
    baseURI: 'http://localhost/cacti/plugins/icct_nms/',
    querySelector: selector => nodes[selector.replace('#shared-diagnostic-', '')] || null,
    createElement: tag => new Element(tag),
    addEventListener: (name, callback) => { events[name] = callback; }
  },
  location: { pathname: '/cacti/plugins/icct_nms/inventory/controllers/inventory.php', href: 'http://localhost/cacti/plugins/icct_nms/inventory/controllers/inventory.php', origin: 'http://localhost' },
  window: { addEventListener() {} }, URL, URLSearchParams, setTimeout, clearTimeout,
  FormData: class {}, Option: class extends Element { constructor(label, value, defaultSelected, selected) { super('option'); this.textContent = label; this.value = value; this.selected = selected; } },
  fetch: async (url, options) => ({ ok: true, headers: { get: () => 'application/json' }, json: async () => options?.method === 'POST' ? result : { ok: true, name: 'Fixture', tools: { pathchar: 'Pathchar', ping: 'Ping' }, tool: 'pathchar' } })
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/diagnostic-popup.js'), 'utf8'), context);
const link = { href: 'http://localhost/cacti/plugins/icct_nms/inventory/diagnostics/controllers/diagnostics.php?host_id=2&tool=pathchar', closest: () => null };
events.click({ target: { closest: () => link }, button: 0, preventDefault() {} });
(async () => {
  await new Promise(setImmediate);
  const recovery = actions.beforeElement;
  const service = recovery.children.find(child => child.tag === 'div');
  const pathchar = recovery.children.filter(child => child.tag === 'div')[1];
  assert.equal(recovery.hidden, false);
  assert.equal(service.hidden, true);
  assert.equal(pathchar.hidden, false);
  assert(pathchar.children.some(child => child.textContent.includes('sudo setcap cap_net_raw=ep /usr/local/bin/pchar')));
  nodes.tool.value = 'ping'; nodes.tool.events.change();
  assert.equal(recovery.hidden, true, 'Pathchar commands leaked into Ping');
  nodes.tool.value = 'pathchar'; nodes.tool.events.change();
  await nodes.form.events.submit({ preventDefault() {} });
  assert.equal(nodes.output.textContent, 'socket: Operation not permitted');
  assert.equal(pathchar.hidden, false, 'Permission failure hid the recovery commands');
  assert.equal(service.hidden, true);
  result = { ok: true, job_id: 6, status: 'expired', output: 'Diagnostic runner unavailable' };
  nodes.tool.value = 'ping';
  await nodes.form.events.submit({ preventDefault() {} });
  assert.equal(service.hidden, false, 'Runner failure hid the restart commands');
  assert.equal(pathchar.hidden, true);
  console.log('PASS: Pathchar help, permission failure, other-tool isolation and runner recovery');
})().catch(error => { console.error(error); process.exitCode = 1; });
