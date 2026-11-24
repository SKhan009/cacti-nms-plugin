'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../installation/js/installation.js'), 'utf8');
async function fixture(states, authorized = true, watchedJob) {
    const timers = [];
    const handlers = {};
    const formHandlers = {};
    const note = { dataset: watchedJob ? { syslogSaveJob: watchedJob } : {}, hidden: true, textContent: '' };
    const input = { value: '1514', readOnly: true, required: false, name: '',
        addEventListener: (name, handler) => { handlers[name] = handler; },
        removeAttribute: (name) => { if (name === 'name') input.name = ''; },
        form: { addEventListener: (name, handler) => { formHandlers[name] = handler; } } };
    let fetches = 0;
    const context = {
        document: { querySelector: (selector) => selector === '[data-syslog-runner]' ? (authorized ? input : null)
            : selector === '[data-syslog-runner-status]' ? note : null },
        fetch: async () => { fetches++; const state = states.shift(); if (state instanceof Error) throw state; return { ok: true, json: async () => state }; },
        window: { setTimeout: (handler) => timers.push(handler) },
    };
    vm.runInNewContext(source, context);
    const settle = () => new Promise(setImmediate);
    await settle();
    return { input, note, handlers, formHandlers, fetches: () => fetches,
        next: async () => { assert.ok(timers.length); await timers.shift()(); await settle(); } };
}
(async () => {
    const ready = { syslog_available: true, available: false, syslog_port: 1514, job: null };
    const f = await fixture([{ syslog_available: false }, ready, new Error('offline'), ready]);
    assert.equal(f.input.readOnly, true);
    await f.next();
    assert.equal(f.input.readOnly, false, 'A ready Syslog-only runner must unlock the preset without RPM media');
    assert.equal(f.input.name, 'receiver_port');
    f.input.value = '2514'; f.handlers.input();
    await f.next();
    assert.equal(f.input.readOnly, true, 'A failed status request must preserve the read-only boundary');
    assert.equal(f.input.name, '');
    let prevented = false; f.formHandlers.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true, 'Do not silently drop an edited port while the runner is unavailable');
    await f.next();
    assert.equal(f.input.readOnly, false, 'Runner recovery must unlock automatically');
    assert.equal(f.input.value, '2514', 'A status refresh must preserve a user-entered port');
    const denied = await fixture([ready], false);
    assert.equal(denied.fetches(), 0, 'Read-only device fields and unauthorized users must not gain editing');
    const bad = await fixture([{ ...ready, syslog_port: 0 }, { ...ready, syslog_port: 65536 }]);
    assert.equal(bad.input.readOnly, true); await bad.next(); assert.equal(bad.input.readOnly, true);
    const job = await fixture([{ ...ready, job: { id: 'job', status: 'running' } },
        { ...ready, syslog_port: 2514, job: { id: 'job', status: 'complete' } },
        { ...ready, syslog_port: 2514, job: { id: 'another-job', status: 'complete' } }], true, 'job');
    assert.equal(job.input.readOnly, true); await job.next();
    assert.equal(job.input.readOnly, false); assert.equal(job.input.value, '2514');
    assert.match(job.note.textContent, /2514/);
    await job.next(); assert.equal(job.input.readOnly, false, 'A later job must not lock a completed port change');
    console.log('PASS: Automatic readiness/recovery, no-media Syslog, permissions, draft preservation, invalid ports and queued-job completion.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
