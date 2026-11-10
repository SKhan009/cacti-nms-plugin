const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/inventory.js'), 'utf8');
const start = source.indexOf('const protocolDefaultData');
const end = source.indexOf('// Read-only device tabs', start);
assert(start >= 0 && end > start, 'Default application code must exist');

function apply({ saved = true, newDevice = false, oldValue = '22', overrides = null, baseline = {}, variant = true, switchMode = false, editPort = false }) {
    const protocol = variant ? 'ssh' : 'syslog';
    const field = variant ? 'port' : 'transport';
    const current = { name: field, type: 'text', value: oldValue, dispatchEvent() {} };
    const auth = { name: 'auth_method', type: 'radio', value: 'key', checked: true };
    const password = { name: 'auth_method', type: 'radio', value: 'password', checked: false };
    const listeners = {};
    const form = {
        elements: variant ? [current, auth, password] : [current],
        dataset: {},
        addEventListener(type, listener) {
            (listeners[type] ??= []).push(listener);
        },
        querySelector() {
            return null;
        },
    };
    const data = {
        '#protocol-default-values': { [protocol]: variant ? { auth_method: 'password', port: 2222 } : { transport: 'tcp' } },
        '#protocol-device-overrides': { [protocol]: overrides },
        '#protocol-inheritance': {
            baseline: { [protocol]: { [variant ? 'key' : 'default']: baseline } },
            variants: variant ? { ssh: { field: 'auth_method' } } : {},
            profiles: variant ? { ssh: {
                key: { auth_method: 'key', port: 2200 },
                password: { auth_method: 'password', port: 2222 },
            } } : {},
        },
    };
    const context = {
        document: {
            querySelector(selector) {
                if (selector === '#device-wizard') return { dataset: { deviceId: newDevice ? '0' : '2' } };
                return data[selector] ? { textContent: JSON.stringify(data[selector]) } : null;
            },
            getElementById(id) {
                if (id !== 'protocol-' + protocol) return null;
                return { dataset: { saved: saved ? '1' : '0' }, querySelector: () => form };
            },
        },
        Event: class {},
    };
    vm.runInNewContext(source.slice(start, end), context);
    if (editPort) {
        current.value = '4444';
        for (const listener of listeners.input) listener({ isTrusted: true, target: current });
    }
    if (switchMode) {
        auth.checked = false;
        password.checked = true;
        for (const listener of listeners.change) listener({ isTrusted: true, target: password });
    }
    return { value: current.value, pending: form.dataset.presetSyncPending === '1', auth: auth.checked };
}

assert.deepEqual(apply({ overrides: [] }), { value: '2200', pending: true, auth: true });
assert.deepEqual(apply({ overrides: ['port'] }), { value: '22', pending: false, auth: true });
assert.equal(apply({ overrides: [], oldValue: '2200' }).pending, false);
assert.equal(apply({ baseline: { port: 22 } }).value, '2200');
assert.equal(apply({ baseline: { port: 22 }, oldValue: '2022' }).value, '2022');
assert.equal(apply({}).value, '22');
assert.equal(apply({ saved: false }).value, '2222');
assert.equal(apply({ newDevice: true }).pending, false);
assert.equal(apply({ variant: false, oldValue: 'both', baseline: { transport: 'both' } }).value, 'tcp');
assert.deepEqual(apply({ overrides: [], switchMode: true }), { value: '2222', pending: true, auth: false });
assert.equal(apply({ overrides: ['port'], switchMode: true }).value, '22');
assert.equal(apply({ overrides: [], switchMode: true, editPort: true }).value, '4444');
console.log('PASS: tracked inheritance, preserved overrides, legacy baseline, version isolation and explicit save state.');
