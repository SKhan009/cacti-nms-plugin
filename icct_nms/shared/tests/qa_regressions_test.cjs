const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const vm = require('./support/source.cjs');
const { sourceIndex } = vm;
const read = (name) => fs.readFileSync(path.join(__dirname, '../..', name), 'utf8');

const tokens = {};
vm.runInNewContext(read('shared/js/browser-utils.js'), tokens);
// HTTP LAN environments can expose getRandomValues without exposing randomUUID.
tokens.crypto = require('node:crypto').webcrypto;
const first = vm.runInNewContext('icctRandomToken()', tokens);
const second = vm.runInNewContext('icctRandomToken()', tokens);
assert.match(first, /^[a-f0-9]{32}$/);
assert.notEqual(first, second);

const wizard = read('inventory/js/wizard.js');
const validation = wizard.slice(
    sourceIndex(wizard, 'function valid(form)'),
    sourceIndex(wizard, 'async function save('),
);
const actions = [];
const input = {
    closest: () => ({ id: 'fcaps-fault' }),
    focus: () => actions.push('focus'),
    scrollIntoView: () => actions.push('scroll'),
};
const form = {
    checkValidity: () => false,
    querySelector: () => input,
    closest: (selector) => selector === '#device-fcaps',
    reportValidity: () => actions.push('report'),
};
const context = {
    location: {},
    basic: {},
    document: { querySelector: () => ({ click: () => actions.push('tab') }) },
    showStep: () => actions.push('step'),
    form,
};
vm.runInNewContext(validation + ';valid(form)', context);
assert.equal(context.location.hash, 'fcaps');
assert.deepEqual(actions, ['step', 'tab', 'focus', 'scroll', 'report']);

const rack = read('dashboard/rack-view/js/rack-view.js');
const stage = rack.slice(
    sourceIndex(rack, 'function stageReservation('),
    sourceIndex(rack, 'function placement('),
);
const data = { racks: [{ id: 1, unit_count: 10, blocked: [], reservations: [] }], devices: [] };
let renders = 0;
const rackContext = {
    data,
    busy: false,
    message: {},
    render: () => renders++,
    trackReservations() {},
    icctRandomToken: () => {
        throw new Error('Secure randomness unavailable');
    },
};
vm.runInNewContext(stage + ";stageReservation('reserve:new',1,1)", rackContext);
assert.equal(data.racks[0].reservations.length, 0);
assert.equal(renders, 0);
assert.equal(rackContext.message.textContent, 'Secure randomness unavailable');
rackContext.icctRandomToken = () => first;
vm.runInNewContext("stageReservation('reserve:new',1,1)", rackContext);
assert.equal(data.racks[0].reservations[0].id, first);
async function checkScopedNtpSave() {
    const save = wizard.slice(
        sourceIndex(wizard, 'async function save('),
        sourceIndex(wizard, 'async function leave('),
    );
    const panel = (id) => ({ id, hidden: false, querySelector: () => ({ checked: true }) });
    const ntpPanel = panel('protocol-ntp');
    const sshPanel = panel('protocol-ssh');
    const protocolForm = (action, owner) => ({
        elements: { action: { value: action } },
        dataset: {},
        closest: () => owner,
    });
    const ntp = protocolForm('ntp', ntpPanel);
    const ssh = protocolForm('ssh', sshPanel);
    const panels = { [ntpPanel.id]: ntpPanel, [sshPanel.id]: sshPanel };
    const initial = new Map(Object.keys(panels).map((id) => [id, { hidden: true }]));
    const posted = [];
    const validated = [];
    const saved = new Map([[ssh, 'unsaved']]);
    const staged = new Map([['graph:1', new Map()]]);
    const result = await vm.runInNewContext(save + ';save(ntp)', {
        id: 2,
        busy: false,
        wizard: { querySelectorAll: () => [] },
        document: { querySelectorAll: () => [], getElementById: (id) => panels[id] },
        basic: { dataset: { staticPreview: true } },
        ntp,
        forms: [ntp, ssh],
        dirtyForms: () => [ntp, ssh],
        protocolChanges: () => initial,
        protocolInitial: initial,
        staged,
        baseline: saved,
        snapshot: () => 'saved',
        valid: (form) => {
            validated.push(form);
            return form === ntp;
        },
        FormData: class extends Map {
            constructor(form) {
                super(form ? [['action', form.elements.action.value]] : []);
            }
        },
        post: async (data) => posted.push(Object.fromEntries(data)),
    });
    assert.equal(result, true);
    assert.deepEqual(validated, [ntp]);
    assert.deepEqual(posted, [
        { protocol: 'ntp', action: 'toggle_protocol', enabled: '1' },
        { action: 'ntp' },
    ]);
    assert.equal(saved.get(ssh), 'unsaved');
    assert.equal(initial.get(sshPanel.id).hidden, true);
    assert.equal(staged.size, 1);
}

async function checkWizardSaveResponse() {
    const reader = wizard.slice(
        sourceIndex(wizard, 'async function readSaveResponse('),
        sourceIndex(wizard, 'async function post('),
    );
    const readResponse = vm.runInNewContext(reader + ';readSaveResponse', {});
    await assert.rejects(readResponse({ type: 'opaqueredirect' }), /Check your session/);
    await assert.rejects(readResponse({
        status: 200, headers: { get: () => 'text/html' },
    }), /unexpected response/);
    const json = (payload) => ({
        ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => payload,
    });
    await assert.rejects(readResponse(json({ responseText: 'Sign in again.' })), /Sign in again/);
    assert.equal((await readResponse(json({ ok: true, id: 2 }))).id, 2);
}

Promise.all([checkScopedNtpSave(), checkWizardSaveResponse()]).then(() => {
    console.log('PASS: LAN-safe tokens, nested-tab validation, atomic reservation drafts and independent NTP save.');
}).catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
