const vm = require('node:vm');
const colors = require('../../assets/severity.json');

function sourceIndex(source, marker, start = 0) {
    const tokens = marker.match(/[A-Za-z_$][\w$]*|[^\s]/g) || [];
    const pattern = tokens
        .map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('\\s*');
    const match = new RegExp(pattern).exec(source.slice(start));
    if (!match) throw new Error('Source boundary missing: ' + marker);
    return start + match.index;
}

// Production receives this shell-owned constant from the shared PHP header.
module.exports = {
    ...vm,
    sourceIndex,
    runInNewContext(code, context = {}, options) {
        context.icctSeverityColors = colors;
        return vm.runInNewContext(code, context, options);
    },
    createContext(context = {}, options) {
        context.icctSeverityColors = colors;
        return vm.createContext(context, options);
    },
};
