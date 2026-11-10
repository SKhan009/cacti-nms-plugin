const vm = require('node:vm');
const colors = require('../../assets/severity.json');
const severityHelpers = require('node:fs').readFileSync(
    require('node:path').join(__dirname, '../../js/severity.js'),
    'utf8',
);

function sourceIndex(source, marker, start = 0) {
    const tokens = marker.match(/[A-Za-z_$][\w$]*|[^\s]/g) || [];
    const pattern = tokens
        .map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('\\s*');
    const match = new RegExp(pattern).exec(source.slice(start));
    if (!match) throw new Error('Source boundary missing: ' + marker);
    return start + match.index;
}

const initializedContexts = new WeakSet();

function prepareContext(context, options) {
    if (!initializedContexts.has(context)) {
        // Load the shared browser helpers once, as the PHP shell does on each page.
        context.icctSeverityColors = colors;
        vm.createContext(context, options);
        vm.runInContext(severityHelpers, context);
        initializedContexts.add(context);
    }
    return context;
}

module.exports = {
    ...vm,
    sourceIndex,
    runInNewContext(code, context = {}, options) {
        return vm.runInContext(code, prepareContext(context, options), options);
    },
    createContext(context = {}, options) {
        return prepareContext(context, options);
    },
};
