const { sourceIndex } = require('./support/source.cjs');
const assert = require('node:assert/strict'),
    fs = require('node:fs'),
    vm = require('./support/source.cjs'),
    path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../alarms/js/alarm-detail.js'), 'utf8');
const context = {};
vm.createContext(context);
vm.runInContext(
    source.slice(
        sourceIndex(source, 'function historyWindow('),
        sourceIndex(source, '\nfunction render('),
    ) + ';globalThis.paginate=historyWindow;',
    context,
);
for (const [total, size, page, start, end, pages] of [
    [0, 100, 1, 0, 0, 1],
    [100, 100, 1, 0, 100, 1],
    [101, 100, 2, 100, 101, 2],
    [235, 100, 3, 200, 235, 3],
    [235, 25, 10, 225, 235, 10],
    [2, 100, 9, 0, 2, 1],
]) {
    const result = context.paginate(total, size, page);
    assert.equal(result.start, start);
    assert.equal(result.end, end);
    assert.equal(result.pages, pages);
    const records = Array.from({ length: total }, (_, i) => i);
    assert.equal(records.slice(result.start, result.end).length, end - start);
}
console.log(
    'PASS: history page boundaries, exact page sizes, final partial page, empty data and refresh page clamping.',
);
