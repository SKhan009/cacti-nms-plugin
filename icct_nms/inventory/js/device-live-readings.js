(() => {
    'use strict';
    const table = document.querySelector('#device-live-readings');
    const ntpPanel = document.querySelector('#view-ntp');
    if (!table && !ntpPanel) return;
    const deviceId = new URLSearchParams(location.search).get('id');
    if (!deviceId) return;
    let busy = false;
    const active = () => !document.hidden &&
        ((table && !document.querySelector('#view-fcaps').hidden) ||
            (ntpPanel && !ntpPanel.hidden &&
                !!ntpPanel.querySelector('[data-ntp-live="offset_ms"], #ntp-history-results')));
    const display = (value) => value === null || value === undefined || value === '' ? '—' : String(value);
    function renderRows(rows) {
        if (!table) return;
        const fragment = document.createDocumentFragment();
        for (const row of rows) {
            const tr = document.createElement('tr');
            for (const key of ['source', 'name', 'units', 'reading', 'alarm_enabled']) {
                const td = document.createElement('td');
                td.textContent = display(row[key]);
                if (key === 'reading') td.style.whiteSpace = 'pre-line';
                tr.append(td);
            }
            fragment.append(tr);
        }
        if (!rows.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 5;
            td.textContent = 'No readings configured for this device.';
            tr.append(td);
            fragment.append(tr);
        }
        table.replaceChildren(fragment);
        const status = document.querySelector('#device-live-readings-status');
        if (status) status.textContent = 'Readings refreshed. Updates every 30 seconds while this tab is open.';
    }
    function renderNtp(ntp) {
        if (!ntpPanel || !ntp) return;
        const status = document.querySelector('#ntp-live-refresh-status');
        if (status) status.textContent = 'Readings refreshed. Checking every 30 seconds while this tab is open.';
        const sample = ntp.sample || {};
        for (const field of ntpPanel.querySelectorAll('[data-ntp-live]')) {
            const key = field.dataset.ntpLive;
            if (key === 'status') {
                field.textContent = ntp.status;
                field.dataset.logStatus = ntp.status;
            } else if (key === 'error') {
                field.textContent = ntp.error || '';
                field.hidden = !ntp.error;
            } else if (key === 'last_success') {
                field.textContent = display(ntp.last_success_display);
            } else if (key === 'last_attempt') {
                field.textContent = display(ntp.last_attempt_display);
            } else if (['measured_host', 'method_used', 'collector'].includes(key)) {
                field.textContent = display(ntp[key]);
            } else if (['offset_ms', 'jitter_ms', 'peer_delay_ms', 'rms_offset_ms', 'root_delay_ms'].includes(key)) {
                const value = sample[key];
                field.textContent = value === null || value === undefined || !Number.isFinite(Number(value))
                    ? '—' : Number(value).toFixed(3);
            } else {
                field.textContent = display(sample[key]);
            }
        }
    }
    async function refreshNtpHistory(ntp) {
        const history = document.querySelector('#ntp-history-results');
        if (!history || ntpPanel.hidden || !ntp) return;
        const attempted = String(ntp.attempted_epoch || 0);
        if (history.dataset.lastNtpAttempt === attempted) return;
        const response = await fetch(location.href.split('#')[0], {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (response.redirected || !response.headers.get('content-type')?.includes('text/html')) {
            throw new Error('Unable to refresh NTP history. Reload this page.');
        }
        const next = new DOMParser().parseFromString(await response.text(), 'text/html')
            .querySelector('#ntp-history-results');
        if (!next) throw new Error('Unable to refresh NTP history. Reload this page.');
        if (ntpPanel.hidden) return;
        history.replaceWith(next);
        const status = document.querySelector('#ntp-history-refresh-status');
        if (status) status.textContent = 'Observation history updated.';
    }
    async function refresh() {
        if (!active() || busy) return;
        busy = true;
        try {
            const query = new URLSearchParams({ id: deviceId, fragment: 'live_readings' });
            if (!table || document.querySelector('#view-fcaps').hidden) query.set('scope', 'ntp');
            const response = await fetch('inventory/controllers/device.php?' + query, {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Session expired. Reload and sign in.');
            }
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Unable to refresh readings.');
            if (data.rows) renderRows(data.rows);
            renderNtp(data.ntp);
            await refreshNtpHistory(data.ntp);
        } catch (error) {
            const status = document.querySelector('#device-live-readings-status');
            if (status) status.textContent = error.message;
            const ntpStatus = document.querySelector('#ntp-live-refresh-status');
            if (ntpStatus) ntpStatus.textContent = error.message;
            const historyStatus = document.querySelector('#ntp-history-refresh-status');
            if (historyStatus) historyStatus.textContent = error.message;
        } finally {
            busy = false;
        }
    }
    window.addEventListener('hashchange', refresh);
    document.addEventListener('visibilitychange', refresh);
    setInterval(refresh, 30000);
})();
