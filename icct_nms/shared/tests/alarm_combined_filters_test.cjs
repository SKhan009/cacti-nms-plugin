const fs = require('fs'),
    vm = require('node:vm'),
    assert = require('node:assert/strict');
const source = fs.readFileSync(
    require('node:path').join(__dirname, '../../alarms/js/alarms.js'),
    'utf8',
);
const code = source.slice(
    source.indexOf('    function filtered()'),
    source.indexOf('    function button('),
);
const controls = {
    device: { value: '2' },
    severity: { value: 'Warning' },
    source: { value: 'Syslog' },
    ack: { value: '0' },
    esc: { value: '' },
    search: { value: 'link' },
    since: { value: '2026-11-04T10:00' },
    until: { value: '2026-11-04T11:00' },
};
const epoch = new Date('2026-11-04T10:30').getTime() / 1000;
const base = {
    host_id: 1,
    severity: 'Major',
    source: 'Syslog',
    acknowledged: 0,
    escalated: 0,
    occurred_epoch: epoch,
    first_seen: '2026-11-04 10:30:00',
    alarm_key: '1',
    device_name: 'router',
    address: '192.0.2.1',
    name: 'link down',
    description: '',
    fix: '',
    short_name: '',
};
const context = {
    controls,
    rows: [
        base,
        { ...base, host_id: 2, alarm_key: '2', severity: 'Warning' },
        { ...base, host_id: 3 },
        { ...base, source: 'NTP' },
        { ...base, acknowledged: 1 },
        { ...base, occurred_epoch: epoch - 7200 },
    ],
    status: {},
    descending: true,
};
vm.createContext(context);
vm.runInContext(code + '; result=filtered();', context);
assert.equal(context.result.length, 1, 'Combined dropdown/source/time/ack filtering failed');
controls.device.value = '';
controls.severity.value = '';
vm.runInContext('result=filtered()', context);
assert.equal(context.result.length, 3, 'All must include every matching device and severity');
controls.until.value = '2026-11-04T09:00';
vm.runInContext('result=filtered()', context);
assert.equal(context.result.length, 0);
assert.match(context.status.textContent, /From/);
console.log(
    'PASS: combined source, Device/Severity dropdowns and All selections, acknowledgement, time windows and inverted-window validation.',
);
