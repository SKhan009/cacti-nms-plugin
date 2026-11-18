(() => {
    'use strict';
    const status = document.querySelector('#alarm-syslog-refresh-status');
    const url = new URL(location.href);
    url.hash = '';
    url.searchParams.set('fragment', 'syslog_results');
    let busy = false;

    document.querySelector('#alarm-export').addEventListener('click', async () => {
        const table = document.querySelector('#alarm-syslog-results .alarm-syslog-table');
        const headers = Array.from(table.tHead.rows[0].cells, cell => cell.innerText.trim());
        const rows = Array.from(table.tBodies[0].rows)
            .filter(row => row.cells.length === headers.length)
            .map(row => Array.from(row.cells, cell => cell.innerText.trim()));
        const meta = table.closest('.alarm-syslog').dataset;
        try {
            await window.icctAlarmPdf.syslog(headers, rows, {site: meta.reportSite, user: meta.reportUser});
        } catch (error) {
            status.textContent = error.message || 'Unable to generate the Syslog PDF.';
        }
    });

    async function refresh() {
        if (busy || document.hidden) return;
        const results = document.querySelector('#alarm-syslog-results');
        if (results.contains(document.activeElement)) return;
        busy = true;
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok || response.redirected ||
                !response.headers.get('content-type')?.includes('text/html')) {
                throw new Error('Syslog results unavailable.');
            }
            const next = new DOMParser().parseFromString(await response.text(), 'text/html')
                .querySelector('#alarm-syslog-results');
            if (!next) throw new Error('Syslog results missing.');
            if (document.hidden || results.contains(document.activeElement)) return;
            if (results.innerHTML !== next.innerHTML) results.replaceWith(next);
            status.textContent = '';
        } catch {
            if (!document.hidden) status.textContent = 'Unable to refresh Syslog. The page will retry automatically.';
        } finally {
            busy = false;
        }
    }

    document.addEventListener('visibilitychange', refresh);
    setInterval(refresh, 30000);
})();
