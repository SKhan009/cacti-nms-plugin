'use strict';
/** Severity colours come from the locally bundled shared palette; never invent a level. */
function icctSetSeverity(element, severity) {
    element.dataset.severity = severity;
}

const icctSeveritySelects = new WeakSet();

/** Native controls keep their normal validation and keyboard behaviour. */
function icctBindSeveritySelect(select) {
    if (icctSeveritySelects.has(select)) return;
    icctSeveritySelects.add(select);
    select.dataset.severityControl = '';
    for (const option of select.options) {
        if (Object.prototype.hasOwnProperty.call(icctSeverityColors, option.value)) {
            icctSetSeverity(option, option.value);
        }
    }
    const update = () => icctSetSeverity(select, select.multiple ? '' : select.value);
    select.addEventListener('change', update);
    update();
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[data-severity-control]').forEach(icctBindSeveritySelect);
});
