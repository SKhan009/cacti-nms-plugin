const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor(tag = 'div') { this.tagName = tag; this.children = []; this.attributes = {}; this.listeners = {}; this.open = false; }
  get classList() { return { contains: name => (this.className || '').split(' ').includes(name), toggle: (name, enabled) => { const classes = new Set((this.className || '').split(' ').filter(Boolean)); enabled ? classes.add(name) : classes.delete(name); this.className = [...classes].join(' '); } }; }
  setAttribute(name, value) { this.attributes[name] = value; }
  matches(selector) { return selector === '.field' && this.className === 'field'; }
  append(...nodes) { this.children.push(...nodes); nodes.forEach(node => node.parent = this); }
  prepend(node) { this.children.unshift(node); node.parent = this; }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  addEventListener(name, handler) { this.listeners[name] = handler; }
  focus() {}
  showModal() { this.open = true; }
  close(value) { this.returnValue = value; this.open = false; this.listeners.close?.(); }
  remove() { this.parent.children = this.parent.children.filter(node => node !== this); }
}
const body = new Element(), main = new Element(), dialog = new Element();
const ids = Object.fromEntries(['message-title','message-text','message-confirm','message-cancel'].map(id => [id,new Element()]));
const context = vm.createContext({ window: {}, document: { body, createElement: tag => new Element(tag), querySelector: selector => selector === '#message-dialog' ? dialog : ids[selector.slice(1)] || (selector === 'main' ? main : body.children.find(node => '#'+node.id === selector)) }, setTimeout: () => 1, clearTimeout() {}, sessionStorage: { setItem() {} } });
const source = fs.readFileSync('plugins/icct_nms/shared/js/inventory.js','utf8').split('if (messageDialog) {')[0];
vm.runInContext(source, context);
(async () => {
  await vm.runInContext("icctShowMessage({title:'Saved',text:'Device changes saved successfully.'})",context);
  assert.equal(dialog.open, false, 'Success must not open a popup');
  assert.equal(body.children[0].children[0].attributes.role, 'status');
  const form = new Element('form'); context.form = form;
  await vm.runInContext("icctShowMessage({title:'Unable to save',text:'Invalid port',danger:true,context:form})",context);
  assert.equal(form.children[0].attributes.role,'alert');
  assert.equal(form.children[0].children[1].textContent,'Invalid port');
  assert.equal(dialog.open,false,'Errors must stay inline');
  const confirmation = vm.runInContext("icctShowMessage({title:'Remove protocol',text:'Remove?',confirm:true,danger:true})",context);
  assert.equal(dialog.open,true,'Confirmation must use a popup');
  dialog.close('accepted'); assert.equal(await confirmation,true);
  console.log('Success toast, contextual inline error and confirmation popup passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
