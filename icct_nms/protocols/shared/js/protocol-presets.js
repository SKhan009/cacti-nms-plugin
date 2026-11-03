/* Presets only: independent non-secret mode settings, with explicit save state. */
(() => {
    'use strict';
    const data = document.querySelector('#protocol-preset-profiles');
    if (!data) return;
    const config = JSON.parse(data.textContent);
    document.querySelectorAll('#protocol-defaults input[name="preset_protocol"]').forEach(input => {
        const protocol = input.value, fields = config.fields[protocol];
        if (!fields) return; // NetFlow owns its format picker; unavailable protocols have no form.
        const form = input.form, variant = config.variants[protocol], drafts = {};
        const controls = key => [...form.querySelectorAll(`[name="${key}"]`)];
        const get = key => {
            const all = controls(key), first = all[0];
            if (!first) return '';
            if (first.type === 'radio') return all.find(item => item.checked)?.value || '';
            return first.type === 'checkbox' ? (first.checked ? '1' : '0') : first.value;
        };
        const read = () => Object.fromEntries(fields.map(key => [key, get(key)]));
        let current = variant ? get(variant.field) : 'default', loading = false;
        const saved = key => variant ? config.profiles[protocol]?.[key] : config.saved[protocol];
        const status = document.createElement('p');
        status.dataset.protocolPresetStatus = protocol;
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        input.after(status);
        const update = () => {
            const baseline = saved(current), values = read();
            const same = baseline && fields.every(key => String(baseline[key] ?? '') === String(values[key] ?? ''));
            const label = variant ? variant.labels[current] : protocol.toUpperCase();
            status.textContent = (same ? 'Saved preset · ' : 'Unsaved changes · ') + label;
        };
        const set = (key, value) => controls(key).forEach(control => {
            if (control.type === 'radio') control.checked = control.value === String(value);
            else if (control.type === 'checkbox') control.checked = String(value) === '1';
            else control.value = value ?? '';
        });
        form.addEventListener('change', event => {
            if (loading) return;
            if (variant && event.target.name === variant.field) {
                drafts[current] = { ...read(), [variant.field]: current };
                current = get(variant.field);
                const values = drafts[current] || saved(current);
                if (values) {
                    loading = true;
                    fields.forEach(key => set(key, values[key]));
                    // Recalculate native credential visibility after all fields are restored.
                    controls(variant.field).forEach(control => control.dispatchEvent(new Event('change', { bubbles: true })));
                    loading = false;
                }
            }
            if (protocol === 'serial') {
                const ascii = get('serial_protocol') === 'modbus_ascii';
                form.querySelector('.serial-parameter-heading').textContent = ascii ? 'Modbus ASCII Parameters' : 'Modbus RTU Parameters';
                const seven = form.querySelector('[name="data_bits"] option[value="7"]');
                if (seven) seven.disabled = !ascii;
            }
            update();
        });
        form.addEventListener('input', () => { if (!loading) update(); });
        if (protocol === 'serial') controls(variant.field).find(control => control.checked)?.dispatchEvent(new Event('change', { bubbles: true }));
        update();
    });
})();
