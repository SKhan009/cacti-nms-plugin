/* Missing flow observations remain gaps; actual measured zeros remain visible. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('./support/source.cjs');
const { sourceIndex } = vm;
const source = fs.readFileSync(
    path.join(__dirname, '../../protocols/netflow/js/netflow-view.js'),
    'utf8',
);
const formatters = source.slice(
    sourceIndex(source, 'const numeric ='),
    sourceIndex(source, 'const date ='),
);
const chartCode = source.slice(
    sourceIndex(source, 'function drawChart('),
    sourceIndex(source, 'function render(data)'),
);
const totalsCode = source.slice(
    sourceIndex(source, "const totals = document.querySelector('#netflow-totals')"),
    sourceIndex(source, "document.querySelector('#netflow-chart-interval')"),
);
class Element {
    constructor(tag, text = '') {
        this.tag = tag;
        this.textContent = text;
        this.attrs = {};
        this.children = [];
    }
    setAttribute(name, value) {
        this.attrs[name] = value;
    }
    append(...elements) {
        this.children.push(...elements);
    }
    replaceChildren(...elements) {
        this.children = elements;
    }
}
function chart(rows, interval = 300) {
    const svg = new Element('svg');
    vm.runInNewContext(formatters + chartCode + ';drawChart(rows, interval)', {
        document: {
            querySelector: () => svg,
            createElementNS: (_, tag) => new Element(tag),
        },
        rows,
        interval,
        date: String,
    });
    return svg.children;
}
function totals(data) {
    const container = new Element('div');
    vm.runInNewContext(formatters + totalsCode, {
        document: { querySelector: () => container },
        make: (tag, text) => new Element(tag, text),
        data,
    });
    return container.children.map((block) => block.children[1].textContent);
}
const missing = [null, undefined, '', ' ', false, NaN, Infinity, 'invalid'];
for (const value of missing) {
    const result = vm.runInNewContext(formatters + ';({count:number(value), size:bytes(value)})', {
        value,
    });
    assert.equal(result.count, '—');
    assert.equal(result.size, '—');
}
let elements = chart([
    { epoch: 1000, bps: 0, traffic_scope: 'LAN' },
    { epoch: 1600, bps: 100, traffic_scope: 'LAN' },
]);
assert.equal(elements.filter((node) => node.tag === 'circle').length, 2);
assert.equal(elements.filter((node) => node.tag === 'polyline').length, 0);
assert.equal(elements.find((node) => node.tag === 'circle').attrs.cy, 188);
elements = chart([
    { epoch: 1000, bps: 0, traffic_scope: 'LAN' },
    { epoch: 1300, bps: 100, traffic_scope: 'LAN' },
]);
assert.equal(elements.filter((node) => node.tag === 'circle').length, 2);
assert.equal(elements.filter((node) => node.tag === 'polyline').length, 1);
assert.equal(chart([]).filter((node) => node.tag === 'circle').length, 0);
assert.throws(() => chart([{ epoch: 1000, bps: null, traffic_scope: 'LAN' }]), /Incomplete/);
assert.throws(() => chart([{ epoch: 1000, bps: 1, traffic_scope: 'LAN' }], 0), /interval/);
assert.deepEqual(totals({ totals: { bytes: 0, packets: 0, flows: 0, sources: 0 } }), [
    '—',
    '—',
    '—',
    '—',
]);
assert.deepEqual(totals({ totals: { bytes: 0, packets: 0, flows: 1, sources: 1 } }), [
    '0 B',
    '0',
    '1',
    '1',
]);
console.log(
    'PASS: missing observations stay unavailable, chart gaps stay empty, and real zero values are preserved.',
);
