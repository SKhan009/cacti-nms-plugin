const { sourceIndex } = require('./support/source.cjs');
const fs = require('node:fs'),
    vm = require('./support/source.cjs'),
    assert = require('node:assert/strict');
const source = fs.readFileSync(
    require('node:path').join(__dirname, '../../dashboard/widgets/js/dashboard.js'),
    'utf8',
);
const begin = sourceIndex(source, 'let inventoryCursor='),
    end = sourceIndex(source, 'let refreshPending=', begin),
    messages = [];
const context = {
    readings: {
        inventory_changes: [{ id: 1, description: 'Existing device', event: 'Device changed' }],
    },
    window: { icctShowMessage: (message) => messages.push(message) },
};
vm.createContext(context);
vm.runInContext(source.slice(begin, end) + ';inventoryChanges();', context);
assert.equal(messages.length, 0, 'Opening the dashboard must not replay old notifications');
context.readings.inventory_changes.push({
    id: 2,
    description: 'Switch A',
    event: 'Device changed',
});
vm.runInContext('inventoryChanges();inventoryChanges();', context);
assert.equal(messages.length, 1, 'A new change must use the existing notification once');
assert(messages[0].text.includes('Switch A'));
console.log(
    'PASS: existing dashboard notifications show new inventory events once without replaying old history.',
);
