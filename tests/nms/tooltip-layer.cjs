const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('plugins/nms/js/nms-tooltips.js', 'utf8');
const functions = source.slice(source.indexOf('\tfunction openTooltip('), source.indexOf('\t/** Place the tooltip above'));
for (const mode of ['native', 'unsupported', 'throws']) {
    const body = {appendChild(t) {t.parentNode = this;}};
    const dialog = {appendChild(t) {t.parentNode = this;}};
    const attrs = new Map();
    let open = false;
    const tooltip = {
        parentNode: body,
        setAttribute(k,v) {attrs.set(k,v);},
        removeAttribute(k) {attrs.delete(k);},
        matches() {return open;},
    };
    if (mode !== 'unsupported') {
        tooltip.showPopover = () => {
            if (mode === 'throws') throw new Error('Unavailable');
            open = true;
        };
        tooltip.hidePopover = () => {open = false;};
    }
    const context = {tooltip, document: {body}};
    vm.createContext(context);
    vm.runInContext(functions, context);
    context.openTooltip({closest: () => dialog});
    assert.equal(tooltip.parentNode, dialog, mode + ': modal ownership');
    assert.equal(attrs.has('popover'), mode === 'native', mode + ': fallback is not browser-hidden');
    context.closeTooltip();
    assert.equal(open, false);
    context.openTooltip({closest: () => null});
    assert.equal(tooltip.parentNode, body, mode + ': normal page ownership');
}
console.log('PASS: native, unsupported and failing popovers stay in their owning layer');
