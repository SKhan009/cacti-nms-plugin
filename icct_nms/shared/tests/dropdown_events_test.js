'use strict';
// Execute the production binding sections with change-only native controls. No network or writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');
function between(file, start, end) {
    const code = read(file), from = code.indexOf(start), to = code.indexOf(end, from + start.length);
    assert(from >= 0 && to > from, 'Production binding section exists: ' + file);
    return code.slice(from, to);
}
class Control {
    constructor(name = '') { this.name = name; this.value = ''; this.handlers = {}; }
    addEventListener(type, handler) { (this.handlers[type] ||= []).push(handler); }
    fire(type, target = this) { for (const handler of this.handlers[type] || []) handler({ target }); }
    matches(selector) { return selector.includes('select'); }
}
for (const [file, start, end, control, counter] of [
    ['shared/js/inventory.js', "    size.addEventListener('change'", "    document.querySelector('#page-previous')", 'size', 'page'],
    ['protocols/snmp/mibs/js/mib-list.js', "    size.addEventListener('change'", "    list.querySelector('[data-list-prev]')", 'size', 'page'],
    ['dashboard/topology/js/topology-configuration.js', "        size.addEventListener('change'", '        previous.addEventListener', 'size', 'page'],
    ['protocols/snmp/mibs/js/mib-repository.js', "        pager.querySelector('[data-mib-size]').addEventListener", "        pager.querySelector('[data-mib-prev]')", 'size', 'objectPage'],
    ['alarms/js/alarms.js', "    document.querySelector('#alarm-page-size').addEventListener", "    document.querySelector('#alarm-prev')", 'size', 'page'],
    ['alarms/js/alarm-detail.js', "    document.querySelector('#alarm-history-size').addEventListener", '    if (ackButton)', 'size', 'historyPage'],
    ['protocols/netflow/js/netflow-view.js', "    document.querySelector('#netflow-size').addEventListener", "    document.querySelector('#netflow-prev')", 'size', 'page'],
]) {
    const size = new Control(), pageSelect = new Control();
    let renders = 0;
    const controls = selector => selector.includes('size') ? size : pageSelect;
    const context = { size, pageSelect, document: { querySelector: controls }, pager: { querySelector: controls },
        render: () => renders++, renderObjects: () => renders++, load: () => renders++, filter: () => { context.page = 1; renders++; } };
    context[counter] = 7;
    vm.runInNewContext(between(file, start, end), context);
    size.value = '50'; size.fire('change');
    assert.equal(context[counter], 1, file + ': size resets to page one');
    assert.equal(renders, 1, file + ': native change updates the results');
    if (pageSelect.handlers.change) {
        pageSelect.value = '2'; pageSelect.fire('change');
        assert.equal(context[counter], 2, file + ': page selection applies');
    }
}
for (const [file, start, end, name, sizeName, pageName] of [
    ['shared/js/inventory.js', 'const diagnosticPager =', null, 'diagnosticPager', 'diagnostics_size', 'diagnostics_page'],
    ['presets/js/presets.js', 'const typePager =', null, 'typePager', 'per_page', 'page'],
]) {
    const pager = new Control(); pager.elements = { [pageName]: { value: '6' } };
    let submits = 0; pager.requestSubmit = () => submits++;
    const context = { document: { querySelector: () => pager, getElementById: () => pager } };
    vm.runInNewContext(read(file).slice(read(file).indexOf(start)), context);
    pager.fire('change', { name: sizeName });
    assert.equal(pager.elements[pageName].value, '1', name + ': size resets page');
    assert.equal(submits, 1, name + ': one submission on change');
    pager.elements[pageName].value = '3'; pager.fire('change', { name: pageName });
    assert.equal(pager.elements[pageName].value, '3', name + ': page selection retained');
}
{
    const form = new Control(); let renders = 0;
    const context = { form, page: 7, render: () => renders++ };
    vm.runInNewContext(between('alarms/js/alarms.js', '    const applyFilters =', "    deviceSearch.addEventListener('input'"), context);
    form.fire('change'); assert.equal(context.page, 1); assert.equal(renders, 1);
    form.fire('input'); assert.equal(renders, 1, 'Select input/change pair must render only once');
    form.fire('input', { matches: () => false }); assert.equal(renders, 2, 'Text search remains live');
}
{
    const editors = new Control(); let writes = 0;
    vm.runInNewContext(between('fcaps/js/fcaps.js', '        const updateNetflowRule =', "        editors.addEventListener('click'"),
        { editors, sync: () => writes++, render() {} });
    editors.fire('change'); assert.equal(writes, 1, 'NetFlow dropdown updates serialized rules');
    editors.fire('input'); assert.equal(writes, 1, 'No duplicate serialization');
}
{
    const source = { record: { label: 'Traffic' } }; let stored;
    const context = { el: () => Object.assign(new Control(), { setAttribute() {}, append() {} }),
        value: () => 'initial', set: (s, key, value) => { stored = value; } };
    vm.runInNewContext(between('protocols/snmp/mibs/js/mib-repository.js', '    function control(source, key, choices)', '    function extraItems'), context);
    const input = context.control(source, 'graph_type', { line: 'Line', area: 'Area' });
    input.value = 'area'; input.fire('change'); assert.equal(stored, 'area', 'MIB graph native dropdown commits on change');
}
console.log('PASS: seven client pagers, two server pagers, alarm filters, NetFlow rules and MIB graph dropdown native change events.');
(async () => {
    const size = { value: '25' }, requests = [], rendered = [];
    const context = { page: 1, activeView: 'records', busy: false, pending: false,
        applied: new Map([['records', new URLSearchParams()]]), document: { querySelector: () => size },
        filterQuery: () => new URLSearchParams(), feedback: {},
        fetch: (url) => new Promise(resolve => requests.push({ url, resolve })),
        render: result => rendered.push(result.size) };
    vm.runInNewContext(between('protocols/netflow/js/netflow-view.js', '    async function load()', '    function showCustomDates'), context);
    const initial = context.load(); size.value = '10'; await context.load();
    requests[0].resolve({ ok: true, redirected: false, headers: { get: () => 'application/json' }, json: async () => ({ size: 25 }) });
    await initial;
    assert.deepEqual(rendered, [], 'Previous NetFlow page size cannot overwrite a newer selection');
    assert.match(requests[1].url, /size=10/, 'Queued request uses the latest page size');
    requests[1].resolve({ ok: true, redirected: false, headers: { get: () => 'application/json' }, json: async () => ({ size: 10 }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(rendered, [10]);
    console.log('PASS: NetFlow concurrent size changes discard old results and load the final selection.');
})().catch(error => { console.error(error); process.exitCode = 1; });
{
    const form = new Control(), row = { querySelector: () => ({ value: '.1.3.6.1' }) };
    let syncs = 0, payload;
    const context = { passiveForm: form, untouchedTrapDraft: row, syncPassive: () => syncs++,
        updateTrapPayload: (r, value) => { payload = value; } };
    vm.runInNewContext(between('fcaps/js/fcaps.js', '        const updatePassiveRule =', '        addTrapRule ='), context);
    const target = { matches: () => true, closest: () => row, dataset: { field: 'varbind' } };
    form.fire('change', target);
    assert.equal(syncs, 1); assert.equal(payload, '.1.3.6.1'); assert.equal(context.untouchedTrapDraft, null);
    form.fire('input', target); assert.equal(syncs, 1, 'Trap dropdown serializes once on native change');
    console.log('PASS: SNMP trap payload and severity dropdown changes synchronize the saved rule draft.');
}
{
    const controls = Array.from({ length: 4 }, () => new Control()); let filters = 0;
    const [source, method, network, size] = controls;
    vm.runInNewContext(between('dashboard/topology/js/topology-configuration.js', '    [source, method, network, size].forEach', "    previous.addEventListener('click'"),
        { source, method, network, size, filter: () => filters++ });
    controls.forEach(c => c.fire('change'));
    assert.equal(filters, 4, 'Discovery filters and page size all react to native change');
}
{
    const panel = new Control(); let submits = 0;
    const form = { elements: { syslog_page: { value: '8' } }, requestSubmit: () => submits++ };
    vm.runInNewContext(between('protocols/syslog/js/syslog-settings.js', "    panel.addEventListener('change'", "    panel.addEventListener('toggle'"), { panel });
    panel.fire('change', { name: 'syslog_size', closest: () => form });
    assert.equal(form.elements.syslog_page.value, '1'); assert.equal(submits, 1);
    // Delegation finds a new pager after the live results fragment is replaced.
    const replacement = { elements: { syslog_page: { value: '4' } }, requestSubmit: () => submits++ };
    panel.fire('change', { name: 'syslog_page', closest: () => replacement });
    assert.equal(replacement.elements.syslog_page.value, '4'); assert.equal(submits, 2);
    panel.fire('change', { closest: () => null }); assert.equal(submits, 2);
}
{
    const field = new Control(); field.dataset = { rule: 'severity' }; let syncs = 0;
    const card = { querySelectorAll: () => [field] };
    vm.runInNewContext(between('protocols/snmp/mibs/js/mib-repository.js', "            card.querySelectorAll('[data-rule]').forEach", "            card.querySelector('[data-prepared-fault-remove]')"),
        { card, rule: {}, display() {}, sync: () => syncs++ });
    field.fire('change'); assert.equal(syncs, 1, 'MIB fault dropdown synchronizes rule JSON on change');
}
console.log('PASS: discovery filters, live-replaced device Syslog pager and MIB fault dropdowns.');
