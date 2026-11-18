/* Each format has independent saved settings; switching keeps unsaved drafts in this page. */
(() => {
    'use strict';
    document.querySelectorAll('[data-netflow-profiles]').forEach((data) => {
        const form = data.closest('form');
        const profiles = JSON.parse(data.textContent);
        const drafts = {};
        const version = form.elements.namedItem('version');
        const fields = [...new Set(Object.values(profiles).flatMap(Object.keys))]
            .filter((key) => form.elements.namedItem(key));
        const noFlowTimeout = form.elements.namedItem('no_flow_timeout');
        if (noFlowTimeout && !fields.includes(noFlowTimeout.name)) fields.push(noFlowTimeout.name);
        const read = () =>
            Object.fromEntries(fields.map((key) => [key, form.elements.namedItem(key).value]));
        let current = version.value;
        version.addEventListener('change', () => {
            // The select already changed; preserve the previous draft's own version value.
            drafts[current] = { ...read(), version: current };
            current = version.value;
            const values = drafts[current] || profiles[current];
            if (values)
                fields.forEach((key) => {
                    const input = form.elements.namedItem(key);
                    input.value = values[key] ?? input.defaultValue ?? '';
                });
        });
    });
    document.querySelectorAll('[data-netflow-wan-picker]').forEach((picker) => {
        const input = picker.form.elements.wan_interfaces;
        picker.addEventListener('change', () => {
            if (!picker.value) return;
            const indices = new Set(input.value.split(',').map((index) => index.trim()).filter(Boolean));
            indices.add(picker.value);
            const value = [...indices].join(',');
            picker.value = '';
            if (value.length > input.maxLength) {
                input.setCustomValidity('The WAN interface list is full.');
                input.reportValidity();
                input.setCustomValidity('');
                return;
            }
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
    const versionName = (version) => Number(version) === 10 ? 'IPFIX' : 'NetFlow v' + version;
    document.querySelectorAll('[data-netflow-reception]').forEach((panel) => {
        const button = panel.querySelector('[data-netflow-test]');
        const result = panel.querySelector('[data-netflow-test-result]');
        button.addEventListener('click', async () => {
            button.disabled = true;
            result.hidden = false;
            result.textContent = 'Checking the saved exporter configuration…';
            try {
                const query = new URLSearchParams({id: panel.dataset.netflowReception, reception: '1'});
                const response = await fetch('protocols/netflow/controllers/netflow.php?' + query, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.headers.get('content-type')?.includes('application/json')) {
                    throw new Error('Reload and sign in to check flow reception.');
                }
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Could not check flow reception.');
                if (!data.assignment) throw new Error('Save an exporter assignment before testing reception.');
                const assignment = data.assignment;
                const observed = data.observation;
                const exporters = observed?.exporters ?? [];
                const bind = data.listener
                    ? ' on ' + data.listener + ' (UDP ' + assignment.port + ')'
                    : '';
                const lines = [
                    'Listener: ' + data.listener_status + bind,
                    'Configured exporter: ' + assignment.exporter_address + ' · ' + versionName(assignment.version) +
                        ' · fallback sampling 1 in ' + assignment.sampling_rate,
                    'Configured active / inactive timeout: ' + assignment.active_timeout + ' / ' +
                        assignment.inactive_timeout + ' seconds',
                    'Exporter packets: ' + (exporters.length ? exporters.map((exporter) => {
                        const rates = exporter.sampling_rates;
                        const mismatch = Number(exporter.version) !== Number(assignment.version) ||
                            rates.some((rate) => Number(rate) !== Number(assignment.sampling_rate));
                        return exporter.source + ' · ' + versionName(exporter.version) + ' · ' + exporter.packets +
                            ' packets · sampling ' + (rates.length ? '1 in ' + rates.join(', ') : 'unreported') +
                            (mismatch ? ' (differs from configuration)' : '');
                    }).join('; ') : 'No packet count reported for this assignment'),
                    'Template decode: ' + (observed?.template_decoded ? 'Confirmed by decoded records' :
                        [9, 10].includes(Number(assignment.version)) ? 'Awaiting decoded records' : 'Fixed record format'),
                    'Flows stored: ' + (observed ? observed.stored + ' in the latest observed capture' : 'Awaiting capture evidence'),
                    'Reception: ' + data.health + ' · no-flow timeout ' + data.no_flow_timeout + ' seconds',
                ];
                if ([9, 10].includes(Number(assignment.version)) || observed?.template_decoded) {
                    lines.push('Template arrival time and unknown-template errors: unavailable per exporter');
                }
                if (observed) lines.push('Capture imported: ' + new Date(observed.checked_at * 1000).toLocaleString());
                if (data.last_data_epoch) {
                    lines.push('Last flow stored: ' + new Date(data.last_data_epoch * 1000).toLocaleString());
                }
                if (data.error) lines.push('Import error: ' + data.error);
                result.replaceChildren(...lines.map((line) => {
                    const row = document.createElement('span');
                    row.style.display = 'block';
                    row.textContent = line;
                    return row;
                }));
            } catch (error) {
                result.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });
    });
})();
