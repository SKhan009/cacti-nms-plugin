const { sourceIndex } = require('./support/source.cjs');
const fs = require('node:fs'),
    vm = require('./support/source.cjs'),
    assert = require('node:assert/strict'),
    path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../js/inventory.js'), 'utf8');
const begin = sourceIndex(source, 'let pendingMibInvalidFocus='),
    end = sourceIndex(source, "document.addEventListener('input'", begin);
const actions = [],
    frames = [],
    pane = { dataset: { editorPane: 'fault' } },
    card = {
        classList: { contains: () => false },
        querySelector: () => ({ click: () => actions.push('edit') }),
    };
const prepared = {
    dataset: { preparedPanel: '4' },
    querySelectorAll: () => [{ dataset: { editorTab: 'fault' }, click: () => actions.push('tab') }],
};
const field = {
    closest: (s) =>
        s === '[data-prepared-panel]'
            ? prepared
            : s === '[data-editor-pane]'
              ? pane
              : s === '.mib-fault-card'
                ? card
                : null,
    getAttribute: () => 'Threshold',
    setAttribute() {},
    validationMessage: 'Enter a number',
    focus: () => actions.push('focus'),
    scrollIntoView: () => actions.push('scroll'),
};
let invalid;
vm.runInNewContext(source.slice(begin, end), {
    document: {
        addEventListener: (name, handler) => (invalid = handler),
        querySelectorAll: () => [
            { dataset: { preparedOpen: '4' }, click: () => actions.push('oid') },
        ],
    },
    requestAnimationFrame: (fn) => frames.push(fn),
    icctInlineMessage: () => actions.push('feedback'),
});
invalid({ preventDefault() {}, target: field });
invalid({ preventDefault() {}, target: field });
assert.equal(frames.length, 1, 'Reveal only the first invalid field in a validation pass');
frames[0]();
assert.deepEqual(actions.slice(-5), ['oid', 'tab', 'edit', 'focus', 'scroll']);
console.log(
    'PASS: Next validation reveals the affected OID, Fault tab and editor without changing entered values.',
);
