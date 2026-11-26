'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
class Control {
    constructor(value = '') { this.value = value; this.dataset = {}; this.handlers = {}; this.options = []; }
    addEventListener(type, fn) { (this.handlers[type] ||= []).push(fn); }
    async fire(type) { await Promise.all((this.handlers[type] || []).map(fn => fn())); }
    setAttribute(key, value) { this[key] = value; }
    removeAttribute(key) { delete this[key]; }
    setCustomValidity(value) { this.validityMessage = value; }
    replaceChildren(...options) { this.options = options; this.value = options[0]?.value || ''; }
    append(option) { this.options.push(option); }
    get selectedOptions() { return this.options.filter(option => option.value === this.value); }
}
class Option {
    constructor(text, value) { this.textContent = text; this.value = value; this.dataset = {}; }
}
const source = fs.readFileSync(path.join(__dirname, '../../dashboard/topology/js/topology-configuration.js'), 'utf8');
function setup(restored = '') {
    const device = new Control(restored), select = new Control('0'), identity = new Control();
    const form = new Control(), page = new Control();
    form.elements = { source_identity: identity }; select.form = form;
    select.dataset.interfacePicker = 'source'; select.dataset.interfaceDevice = '';
    select.replaceChildren(new Option('Interface not mapped', '0'));
    const requests = [], timers = [];
    vm.runInNewContext(source, {
        document: { querySelector: () => null, querySelectorAll: selector => selector === '[data-interface-picker]' ? [select] : [], getElementById: () => device },
        window: page, URL, Option, AbortController,
        location: { href: 'http://example.test/cacti/plugins/icct_nms/dashboard/topology/controllers/topology_configuration.php?tab=connections' },
        setTimeout: fn => { timers.push(fn); return timers.length; }, clearTimeout() {},
        fetch: (url, options) => new Promise((resolve, reject) => {
            options.signal.addEventListener('abort', () => reject(new Error('aborted')));
            requests.push({ url, resolve, reject });
        }),
    });
    const respond = (index, interfaces) => requests[index].resolve({ ok: true, json: async () => ({ interfaces }) });
    return { device, select, identity, form, page, requests, timers, respond };
}
const ports = [{ index: 2, name: 'eth-test', identity: 'fingerprint', alias: '' }];
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
    const t = setup('12');
    assert.equal(t.requests.length, 1, 'Restored device loads without a change event');
    assert.equal(t.requests[0].url.searchParams.get('interface_device'), '12');
    t.respond(0, ports); await flush();
    t.select.value = '2'; await t.select.fire('input');
    assert.equal(t.identity.value, 'fingerprint');
    await t.device.fire('change');
    assert.equal(t.select.value, '2', 'Choosing the same device keeps the mapping');
    assert.equal(t.requests.length, 1, 'Input/change pair does not duplicate the request');
    t.identity.value = ''; await t.form.fire('submit');
    assert.equal(t.identity.value, 'fingerprint', 'Submit synchronizes a browser-restored port');
    t.device.value = '13'; const a = t.device.fire('change');
    t.device.value = '14'; const b = t.device.fire('change');
    t.respond(1, [{ index: 9, name: 'old', identity: 'old' }]);
    t.respond(2, ports); await Promise.all([a, b]);
    assert.equal(t.select.options.some(o => o.value === '9'), false, 'Late old response cannot replace the new device');
    assert.equal(t.identity.value, '', 'Switching devices clears the old identity');
    t.device.value = ''; await t.device.fire('change');
    assert.equal(t.select.options.length, 1);
    t.device.value = '15'; const failed = t.device.fire('change');
    t.requests[3].reject(new Error('offline')); await failed;
    assert.match(t.select.options[1].textContent, /unavailable/);
    const retry = t.device.fire('change'); t.respond(4, []); await retry;
    assert.match(t.select.options[1].textContent, /No collected/);
    const timeout = t.device.fire('change'); t.timers.at(-1)(); await timeout;
    assert.equal(t.select.validityMessage, '', 'A failed request never leaves the form stuck loading');
    t.device.value = '16'; const restored = t.page.fire('pageshow');
    t.respond(6, ports); await restored; await flush();
    assert.equal(t.select.options[1].value, '2', 'Back/forward restore reloads the matching device');
    console.log('PASS: restored devices, identity synchronization, same-device preservation, request races, errors, retry and timeout.');
})().catch(error => { console.error(error); process.exitCode = 1; });
