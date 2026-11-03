/* Each format has independent saved settings; switching keeps unsaved drafts in this page. */
(() => {
    'use strict';
    document.querySelectorAll('[data-netflow-profiles]').forEach(data => {
        const form = data.closest('form');
        const profiles = JSON.parse(data.textContent);
        const drafts = {};
        const version = form.elements.namedItem('version');
        const status = form.querySelector('[data-netflow-preset-status]');
        const fields = Object.keys(profiles[version.value] || {});
        const read = () => Object.fromEntries(fields.map(key => [key, form.elements.namedItem(key).value]));
        const equal = (a, b) => fields.every(key => String(a[key] ?? '') === String(b[key] ?? ''));
        let current = version.value;
        const update = () => {
            const saved = profiles[current];
            const label = current === '10' ? 'IPFIX' : 'NetFlow v' + current;
            status.textContent = (saved && equal(read(), saved) ? 'Saved preset · ' : 'Unsaved changes · ') + label;
        };
        form.addEventListener('input', update);
        version.addEventListener('change', () => {
            // The select already changed; preserve the previous draft's own version value.
            drafts[current] = { ...read(), version: current };
            current = version.value;
            const values = drafts[current] || profiles[current];
            if (values) fields.forEach(key => { form.elements.namedItem(key).value = values[key] ?? ''; });
            update();
        });
        update();
    });
})();
