const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../../dashboard/map/js/map.js', 'utf8');
const start = source.indexOf('    function openSiteView(');
const end = source.indexOf("    document.addEventListener('icct:device-counts'", start);
function run(lnms) {
    const calls = [];
    const context = {
        lnms,
        countryView: false,
        indiaBounds: {
            contains: ([lat, lon]) => lat >= 6 && lat <= 37.5 && lon >= 68 && lon <= 97.5,
        },
        map: {
            setMinZoom: (z) => calls.push(['min', z]),
            setView: (coords, z) => calls.push(['view', coords, z]),
            fitBounds: () => calls.push(['bounds']),
        },
        L: { latLngBounds: (p) => p },
        fitIndia: () => calls.push(['india']),
    };
    vm.runInNewContext(source.slice(start, end) + '\nfitLnms();', context);
    return calls;
}
for (const zoom of [0, 12, 23]) {
    assert.deepEqual(run({ nodes: [], site_view: { coordinates: [28.6448, 77.216721], zoom } }), [
        ['min', 0],
        ['view', [28.6448, 77.216721], zoom],
    ]);
}
assert.deepEqual(run({ nodes: [{ site_id: 3, coordinates: [28, 77], zoom: 12 }] }), [['india']]);
assert.deepEqual(
    run({
        nodes: [
            { site_id: 3, coordinates: [28, 77], zoom: 12 },
            { site_id: 4, coordinates: [12, 77], zoom: 15 },
        ],
    }),
    [['india']],
);
assert.deepEqual(run({ nodes: [{ coordinates: [51, -0.1] }] }), [['min', 2], ['bounds']]);
for (const zoom of [null, -1, 24, '12']) {
    assert.deepEqual(run({ nodes: [], site_view: { coordinates: [28, 77], zoom } }), [['india']]);
}
assert.deepEqual(run({ nodes: [], site_view: { coordinates: null, zoom: 12 } }), [['india']]);
console.log(
    'PASS native zoom 0/12/23, empty selected site, centered single/multi-site overview, overseas fit, invalid-value fallback',
);
