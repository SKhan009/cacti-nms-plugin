'use strict';
/** Severity colours come from the locally bundled shared palette; never invent a level. */
function icctSetSeverity(element, severity) {
    element.dataset.severity = severity;
}
