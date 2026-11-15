(function () {
    'use strict';
    const root = document.querySelector('#view-ports');
    if (!root) return;
    let search, rows, count, empty;
    function readControls() {
        search = root.querySelector('#device-port-search');
        rows = Array.from(root.querySelectorAll('[data-port-row]'));
        count = root.querySelector('#device-port-count');
        empty = root.querySelector('#device-port-empty');
    }
    readControls();
    if (!search || !count || !empty) return; // Serial and alarm-history views have separate data.
    function filter() {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        for (const row of rows) {
            row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
            if (!row.hidden) visible++;
        }
        count.textContent = visible + ' of ' + rows.length + ' interfaces';
        empty.hidden = visible > 0;
        empty.textContent = rows.length
            ? 'No interfaces match your search.'
            : 'No interfaces reported for the current configuration.';
    }
    root.addEventListener('input', (event) => {
        if (event.target === search) filter();
    });
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-port-index]');
        if (button && !button.disabled) {
            search.value = '';
            filter();
            const row = rows.find((row) => row.dataset.portRow === button.dataset.portIndex);
            if (!row) return;
            rows.forEach((row) => row.classList.remove('port-row-selected'));
            row.classList.add('port-row-selected');
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else if (event.target.closest('#device-port-export')) {
            exportRows();
        }
    });
    function csvCell(value) {
        value = value.trim();
        if (/^[=+@-]/.test(value)) value = "'" + value;
        return '"' + value.replaceAll('"', '""') + '"';
    }
    function exportRows() {
        const header = root.querySelector('thead tr'),
            exportRows = [header, ...rows.filter((row) => !row.hidden)],
            csv = exportRows
                .map((row) =>
                    Array.from(row.cells)
                        .map((cell) => csvCell(cell.textContent))
                        .join(','),
                )
                .join('\r\n');
        const url = URL.createObjectURL(
                new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }),
            ),
            link = document.createElement('a');
        link.href = url;
        link.download = 'device-interfaces.csv';
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    filter();
    let loading = false, lastMarkup;
    async function refresh() {
        if (loading || root.hidden || document.hidden || root.contains(document.activeElement)) return;
        const url = new URL(location.href);
        url.hash = '';
        url.searchParams.set('fragment', 'ports');
        loading = true;
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) return;
            const markup = await response.text();
            if (markup === lastMarkup || root.hidden || root.contains(document.activeElement)) return;
            const next = new DOMParser().parseFromString(markup, 'text/html').querySelector('#view-ports');
            if (!next?.querySelector('#device-port-search')) return;
            const query = search.value;
            const selected = rows.find((row) => row.classList.contains('port-row-selected'))?.dataset.portRow;
            const open = new Set(rows.filter((row) => row.querySelector('details[open]')).map((row) => row.dataset.portRow));
            const selectors = ['.device-port-chassis', '.device-port-table-wrap'];
            const scroll = selectors.map((selector) => root.querySelector(selector)?.scrollLeft ?? 0);
            root.replaceChildren(...next.children);
            readControls();
            search.value = query;
            for (const row of rows) {
                if (row.dataset.portRow === selected) row.classList.add('port-row-selected');
                const details = row.querySelector('details');
                if (details && open.has(row.dataset.portRow)) details.open = true;
            }
            selectors.forEach((selector, index) => {
                const element = root.querySelector(selector);
                if (element) element.scrollLeft = scroll[index];
            });
            filter();
            lastMarkup = markup;
        } catch {
            // Keep the rendered observation if the refresh fails.
        } finally {
            loading = false;
        }
    }
    window.addEventListener('hashchange', refresh);
    document.addEventListener('visibilitychange', refresh);
    setInterval(refresh, 30000);
    refresh();
})();
