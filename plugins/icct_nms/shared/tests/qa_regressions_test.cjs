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
    sourceIndex(wizard, 'async function save()'),
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
console.log('PASS: LAN-safe secure tokens, nested-tab validation and atomic reservation drafts.');
