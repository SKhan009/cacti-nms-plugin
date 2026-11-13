const { sourceIndex } = require('./support/source.cjs');
const fs = require('fs'),
    vm = require('./support/source.cjs'),
    assert = require('node:assert/strict');
class E {
    constructor(tag = 'div') {
        this.tagName = tag;
        this.dataset = {};
        this.children = [];
        this.attrs = {};
        this.listeners = {};
        this.value = '';
        this.className = '';
        this.classList = { add: () => {} };
    }
    append(...items) {
        items.forEach((x) => {
            x.parent = this;
            this.children.push(x);
        });
    }
    setAttribute(k, v) {
        this.attrs[k] = v;
    }
    addEventListener(n, f) {
        this.listeners[n] = f;
    }
    replaceChildren(...items) {
        this.children = [];
        this.append(...items);
    }
    closest(selector) {
        if (selector === '.fault-rule')
            return this.className === 'fault-rule' ? this : this.parent?.closest(selector);
        if (selector === '.field')
            return this.className === 'field' ? this : this.parent?.closest(selector);
        return null;
    }
    matches(sel) {
        return sel.startsWith('[data-')
            ? Object.hasOwn(this.attrs, sel.slice(1, -1))
            : (sel === '.field-label' || sel === '.field-title' || sel === '.field-info') &&
                  this.className === sel.slice(1);
    }
    querySelector(sel) {
        for (const x of this.children) {
            if (x.matches(sel)) return x;
            const found = x.querySelector(sel);
            if (found) return found;
        }
        return null;
    }
    remove() {
        this.parent.children = this.parent.children.filter((child) => child !== this);
    }
    dispatchEvent(event) {
        this.listeners[event.type]?.(event);
    }
    get options() {
        return this.children;
    }
}
const nodes = {};
for (const key of [
    '#device-fcaps',
    '#fault-form',
    '#fault-rule-list',
    '#fault-rules-value',
    '#add-fault-rule',
    '#fault-catalogue',
    '#saved-fault-rules',
    '#fault-current-readings',
    '.graph-accordion-list',
    '#fault-alarm-navigation',
    '#fault-alarm-empty',
    '#fault-oid-section',
    '#snmp-fault-rule-type',
    '#fault-oid-navigation',
    '#fault-graph-section',
    '#fault-ntp-section',
    '#fault-ntp-navigation',
    '#fault-mib-presets',
    '#fault-ntp-parameters',
    '#fault-ntp-readings',
    '#fault-mib-preset-values',
])
    nodes[key] = new E();
nodes['#fault-form'].elements = { fault_rules: nodes['#fault-rules-value'] };
nodes['#fault-catalogue'].textContent = JSON.stringify([
    {
        template_id: 1,
        metric_id: 2,
        template_name: 'CPU',
        data_source_name: 'cpu',
        rrd_minimum: 0,
        rrd_maximum: 100,
    },
]);
nodes['#saved-fault-rules'].textContent = JSON.stringify([
    {
        template_id: 1,
        metric_id: 2,
        maximum: 80,
        minimum: null,
        severity: 'Warning',
        enabled: true,
    },
    {
        source: 'snmp',
        parameter: 'Battery',
        oid: '1.3.6.1.4.1.99.1.0',
        units: '%',
        scale: 2,
        minimum: 20,
        maximum: null,
        condition: 'below',
        severity: 'Major',
    },
]);
nodes['#fault-current-readings'].textContent = '[]';
nodes['#fault-ntp-parameters'].textContent = JSON.stringify({
    absolute_offset_ms: { label: 'Absolute clock offset', units: 'ms' },
});
nodes['#fault-ntp-readings'].textContent = JSON.stringify({
    attempted_epoch: Date.now() / 1000,
    stale_seconds: 900,
    values: { absolute_offset_ms: 0 },
});
nodes['#fault-mib-preset-values'].textContent = JSON.stringify(
    [1, 2].map((n) => ({
        source: 'rrd',
        preset_key: String(n).repeat(64),
        name: 'MIB rule ' + n,
        template_id: 1,
        metric_id: 2,
        maximum: 80 + n,
        severity: 'Major',
        enabled: true,
    })),
);

nodes['#fault-parameters'] = new E();
nodes['#fault-parameters'].textContent = JSON.stringify([
    {
        oid: '1.3.6.1.4.1.99.1.0',
        parameter: 'Battery charge',
        units: '%',
        scale: 1,
        type_id: 'different-device-type',
        label: 'Battery charge',
        template_id: 1,
        metric_id: 2,
    },
]);
nodes['#device-wizard'] = new E();
nodes['#device-wizard'].dataset.deviceId = '2';
const token = new E('input');
token.value = 'valid-first-token';
const oldQuery = nodes['#fault-form'].querySelector.bind(nodes['#fault-form']);
nodes['#fault-form'].querySelector = (selector) =>
    selector === 'input[name="__csrf_magic"]' ? token : oldQuery(selector);
nodes['#fault-form'].elements.__csrf_magic = { value: '', length: 2 };
let reads = 0;
const sourceRadios = [new E('input'), new E('input'), new E('input')];
sourceRadios[0].value = 'rrd';
sourceRadios[1].value = 'snmp';
sourceRadios[2].value = 'ntp';
const snmpRuleRadios = [new E('input'), new E('input')];
snmpRuleRadios[0].value = 'snmp';
snmpRuleRadios[0].checked = true;
snmpRuleRadios[1].value = 'trap';
const code = fs.readFileSync(
    require('node:path').join(__dirname, '../../fcaps/js/fcaps.js'),
    'utf8',
);
vm.runInNewContext(code.slice(0, sourceIndex(code, '  const basic=')) + '})();', {
    document: {
        querySelector: (s) => nodes[s] || null,
        querySelectorAll: (selector) => {
            if (selector === '.fault-source-options input') return sourceRadios;
            if (selector === '[name="snmp_fault_view"]') return snmpRuleRadios;
            return [{ value: 1 }];
        },
        createElement: (tag) => new E(tag),
    },
    MutationObserver: class {
        observe() {}
    },
    Set,
    JSON,
    Number,
    String,
    Object,
    FormData,
    Event,
    queueMicrotask,
    fetch: async (url, opts) => {
        if (opts.body.get('source') === 'rrd') {
            assert.ok(opts.body.get('template_id'));
            assert.ok(opts.body.get('metric_id'));
            return {ok:true, redirected:false, headers:{get:()=> 'application/json'}, json:async()=>({readings:[{value:0,reason:''}]})};
        }
        reads++;
        assert.equal(opts.body.get('__csrf_magic'), 'valid-first-token');
        return {
            ok: true,
            redirected: false,
            headers: { get: () => 'application/json' },
            json: async () => ({ value: 42 }),
        };
    },
});

setTimeout(() => {
    assert.equal(nodes['#fault-rule-list'].children[0].querySelector('[data-reading]').value, '0 %', 'Native zero must remain a numeric current reading');
    const row = nodes['#fault-rule-list'].children[1];
    assert.equal(
        reads,
        1,
        'Opening a saved SNMP rule must fetch its reading without a change event',
    );
    assert.equal(row.querySelector('[data-reading]').value, '84 %');
    assert.equal(row.querySelector('[data-parameter-choice]').value, '0');
    assert.equal(
        row.querySelector('[data-scale]').value,
        2,
        'Reopening must preserve the saved conversion',
    );
    assert.equal(row.querySelector('[data-scale]').closest('.field').hidden, true);
    assert.equal(row.querySelector('[data-units]').closest('.field').hidden, true);
    assert.equal(row.querySelector('[data-minimum]').value, 20);
    console.log(
        'Reopening restores the SNMP parameter, fetches a numeric reading automatically, preserves thresholds and keeps scale hidden.',
    );
}, 0);

setTimeout(() => {
    const nav = nodes['#fault-alarm-navigation'],
        rows = nodes['#fault-rule-list'].children;
    assert.equal(nodes['#fault-graph-section'].hidden, false);
    assert.equal(nav.children[0].children[0].textContent, 'CPU');
    const sourceButton = nav.children[0].children[1].children[0];
    assert.equal(sourceButton.textContent, 'cpu');
    sourceButton.listeners.click();
    const before = rows.length;
    nodes['#add-fault-rule'].listeners.click();
    assert.equal(nodes['#fault-rule-list'].children.length, before + 1);
    const added = nodes['#fault-rule-list'].children[before];
    assert.equal(added.querySelector('[data-template]').value, '1');
    assert.equal(added.querySelector('[data-metric]').value, '2');
    assert.equal(rows[0].hidden, false);
    assert.equal(
        added.hidden,
        false,
        'Multiple severity rules for a measurement must remain visible',
    );
    sourceRadios[1].checked = true;
    sourceRadios[1].listeners.change();
    assert.equal(nodes['#fault-graph-section'].hidden, true);
    assert.equal(nodes['#fault-oid-section'].hidden, false);
    assert.equal(nav.children.length, 0, 'SNMP sidebar must not show alarm names');
    const snmp = nodes['#fault-rule-list'].children[1];
    snmp.querySelector('[data-name]').value = 'Keep this alarm name';
    snmp.listeners.input();
    const oidLinks = nodes['#fault-oid-navigation'].children;
    assert.equal(
        oidLinks.length,
        1,
        'A saved rule remains reachable when its MIB is scoped elsewhere',
    );
    oidLinks[0].listeners.click();
    assert.equal(snmp.querySelector('[data-name]').value, 'Keep this alarm name');
    assert.equal(snmp.querySelector('[data-minimum]').value, 20);
    assert.equal(
        JSON.parse(nodes['#fault-rules-value'].value).length,
        3,
        'Save must retain both sources and all severity rules',
    );
    console.log(
        'Graph template/data source hierarchy, OID-only navigation and multiple thresholds per measurement passed.',
    );
}, 0);

setTimeout(() => {
    const picker = nodes['#fault-mib-presets'];
    for (const label of [picker.children[0], picker.children[2]]) {
        const checkbox = label.children[0];
        checkbox.checked = true;
        checkbox.listeners.change();
    }
    let saved = JSON.parse(nodes['#fault-rules-value'].value);
    assert.equal(saved.filter((r) => r.preset_key).length, 2, 'MIB multi-selection lost');
    const first = nodes['#fault-mib-presets'].children[0].children[0];
    first.checked = false;
    first.listeners.change();
    first.checked = true;
    first.listeners.change();
    saved = JSON.parse(nodes['#fault-rules-value'].value);
    assert.equal(saved.filter((r) => r.preset_key).length, 2, 'Reselect duplicated MIB rule');
    assert.ok(
        saved
            .filter((r) => r.preset_key)
            .every((r) => r.source === 'rrd' && Number(r.metric_id) === 2),
        'Native MIB binding changed',
    );
    sourceRadios[2].checked = true;
    sourceRadios[2].listeners.change();
    assert.equal(nodes['#fault-ntp-section'].hidden, false);
    const before = nodes['#fault-rule-list'].children.length;
    nodes['#fault-ntp-navigation'].children[0].listeners.click();
    const row = nodes['#fault-rule-list'].children[before];
    assert.equal(row.querySelector('[data-reading]').value, '0 ms', 'Actual NTP zero lost');
    assert.equal(row.querySelector('[data-template]').required, false);
    assert.equal(row.querySelector('[data-oid]').required, false);
    nodes['#fault-ntp-navigation'].children[0].listeners.click();
    assert.equal(
        nodes['#fault-rule-list'].children.length,
        before + 1,
        'Parameter selection duplicated rule',
    );
    console.log(
        'NTP navigation, actual zero and MIB multi-select with native bindings and no duplicate rules passed.',
    );
}, 0);
