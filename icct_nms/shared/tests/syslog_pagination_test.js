'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const handlers = {};
const status = { textContent: '' };
let current;
let next;
let fetchedUrl;
function fragment() {
    const form = {
        filters: { tab: 'syslog', device: '2', syslog_severity: ['3', '4'], syslog_search: 'link', sort: 'oldest' },
        submissions: [],
        requestSubmit(submitter) {
            this.submissions.push({ ...this.filters, size: select.value, ...(submitter ? { page: submitter.value } : {}) });
        },
    };
    const select = {
        name: 'size', value: '25', form,
        matches: (selector) => selector === '#alarm-syslog-results [data-syslog-pagination] select[name="size"]',
    };
    return { form, select, dataset: { refreshSeconds: '30' }, innerHTML: Math.random().toString(),
        contains: () => false, replaceWith: (replacement) => { current = replacement; } };
}
current = fragment();
const context = {
    URL, location: { href: 'https://example.test/alarms.php?tab=syslog&size=10&page=2&sort=oldest' },
    document: { hidden: false, activeElement: null,
        querySelector: (selector) => selector === '#alarm-syslog-results' ? current
            : selector === '#alarm-syslog-refresh-status' ? status : { addEventListener() {} },
        addEventListener: (type, handler) => { handlers[type] = handler; } },
    setInterval: () => 1, clearInterval() {},
    fetch: async (url) => { fetchedUrl = url; return { ok: true, redirected: false,
        headers: { get: () => 'text/html' }, text: async () => '<fragment>' }; },
    DOMParser: class { parseFromString() { return { querySelector: () => next }; } },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../alarms/js/syslog-console.js'), 'utf8'), context);
(async () => {
    handlers.change({ target: current.select });
    assert.deepEqual(current.form.submissions[0], {
        tab: 'syslog', device: '2', syslog_severity: ['3', '4'], syslog_search: 'link', sort: 'oldest', size: '25',
    }, 'Changing size submits immediately, retains filters/sort and omits the previous page');
    handlers.change({ target: { matches: () => false } });
    assert.equal(current.form.submissions.length, 1, 'Unrelated controls must not submit pagination');
    const original = current;
    next = fragment();
    await handlers.visibilitychange();
    assert.notEqual(current, original, 'The live refresh must replace the results in this regression');
    assert.equal(fetchedUrl.searchParams.get('page'), '2', 'Live refresh keeps the currently viewed page');
    assert.equal(fetchedUrl.searchParams.get('fragment'), 'syslog_results');
    current.select.value = '100';
    handlers.change({ target: current.select });
    assert.equal(current.form.submissions[0].size, '100', 'Pagination must work after fragment replacement');
    assert.equal(current.form.submissions[0].page, undefined, 'Changing size after refresh starts on page one');
    assert.equal(original.form.submissions.length, 1, 'Do not submit a detached form');
    console.log('PASS: Syslog page size applies immediately, resets page, preserves filters, ignores unrelated controls and survives live refresh.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
