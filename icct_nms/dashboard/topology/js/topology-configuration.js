(() => {
    'use strict';
    if (document.querySelector('[data-live-discovery]'))
        setInterval(() => {
            if (!document.hidden) location.reload();
        }, 5000);
    document.querySelectorAll('[data-interface-picker]').forEach((select) => {
        const end = select.dataset.interfacePicker;
        const device = document.getElementById(end);
        const identity = select.form.elements[end + '_identity'];
        const describe = (message) => {
            select.title = message;
            select.setAttribute('aria-description', message);
        };
        let request = 0;
        let loadedDevice = select.dataset.interfaceDevice || '';
        let pending;
        const updateIdentity = () => {
            identity.value = select.selectedOptions[0]?.dataset.identity || '';
        };
        select.addEventListener('change', updateIdentity);
        const load = async () => {
            const id = device.value;
            // Browsers may restore devices after navigation without firing change.
            // Re-selecting the same device must not discard an existing mapping.
            if (id === loadedDevice) return;
            loadedDevice = id;
            const current = ++request;
            pending?.abort();
            pending = new AbortController();
            const controller = pending;
            select.replaceChildren(new Option('Interface not mapped', '0'));
            identity.value = '';
            select.setCustomValidity(id ? 'Wait for discovered interfaces to load.' : '');
            describe(id ? 'Loading discovered interfaces…' : 'Select a device first.');
            select.removeAttribute('aria-busy');
            if (!id) return;
            select.setAttribute('aria-busy', 'true');
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const url = new URL(location.href);
                url.search = '';
                url.searchParams.set('interface_device', id);
                const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!Array.isArray(data.interfaces)) throw new Error();
                if (current !== request || device.value !== id) return;
                for (const port of data.interfaces) {
                    const option = new Option(port.name + ' · ifIndex ' + port.index +
                        (port.alias ? ' · ' + port.alias : ''), String(port.index));
                    option.dataset.identity = port.identity;
                    select.append(option);
                }
                if (!data.interfaces.length) {
                    const status = new Option(data.message || 'No collected interfaces available', '');
                    status.disabled = true;
                    select.append(status);
                    loadedDevice = null; // Allow selecting the device again after collection completes.
                }
                describe(data.message || '');
                select.setCustomValidity('');
            } catch {
                if (current !== request) return;
                loadedDevice = null;
                const status = new Option('Interfaces unavailable — select the device again to retry', '');
                status.disabled = true;
                select.append(status);
                describe(status.textContent);
                // Keep the explicit unmapped choice available; never reuse another device’s port.
                select.setCustomValidity('');
            } finally {
                clearTimeout(timeout);
                if (current === request) select.removeAttribute('aria-busy');
            }
        };
        device.addEventListener('input', load);
        device.addEventListener('change', load);
        // Synchronize restored port choices with their hidden identity before submit.
        select.addEventListener('input', updateIdentity);
        select.form.addEventListener('submit', updateIdentity);
        window.addEventListener('pageshow', () => { load(); updateIdentity(); });
        load();
        updateIdentity();
    });
    document.querySelectorAll('.connection-device-select').forEach((select) => {
        const picker = document.createElement('div');
        picker.className = 'connection-picker';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'connection-picker-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        const label =
            select.id === 'source'
                ? 'Source Device'
                : select.id === 'target'
                  ? 'Target Device'
                  : 'Discovery Device';
        toggle.setAttribute('aria-label', label);
        const panel = document.createElement('div');
        panel.className = 'connection-picker-panel';
        panel.hidden = true;
        panel.id = select.id + 'Options';
        toggle.setAttribute('aria-controls', panel.id);
        const search = document.createElement('input');
        search.type = 'search';
        search.placeholder = 'Search device name or address';
        search.setAttribute(
            'aria-label',
            'Search ' +
                (select.id === 'source'
                    ? 'source'
                    : select.id === 'target'
                      ? 'target'
                      : 'discovery') +
                ' devices',
        );
        const list = document.createElement('div');
        list.className = 'connection-picker-options';
        const empty = document.createElement('p');
        empty.textContent = 'No matching devices.';
        empty.hidden = true;
        const close = () => {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        };
        const choices = [...select.options].map((option) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent =
                option.textContent + (option.dataset.address ? ' — ' + option.dataset.address : '');
            button.dataset.search = button.textContent.toLowerCase();
            button.addEventListener('click', () => {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                close();
                toggle.focus();
            });
            list.append(button);
            return button;
        });
        const filter = () => {
            let count = 0;
            choices.forEach((button) => {
                button.hidden = !button.dataset.search.includes(search.value.trim().toLowerCase());
                if (!button.hidden) count++;
            });
            empty.hidden = count > 0;
        };
        const update = () => {
            toggle.textContent = select.selectedOptions[0]?.textContent || 'Select device';
        };
        toggle.addEventListener('click', () => {
            const opening = panel.hidden;
            close();
            if (opening) {
                panel.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
                search.value = '';
                filter();
                search.focus();
            }
        });
        search.addEventListener('input', filter);
        picker.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
                toggle.focus();
            }
        });
        document.addEventListener('click', (event) => {
            if (!picker.contains(event.target)) close();
        });
        select.addEventListener('change', update);
        select.addEventListener('invalid', () => {
            toggle.focus();
        });
        select.classList.add('sr-only');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select.insertAdjacentElement('afterend', picker);
        panel.append(search, list, empty);
        picker.append(toggle, panel);
        update();
    });
    const connectionSearch = document.querySelector('#connectionSearch'),
        origin = document.querySelector('#connectionOrigin');
    if (connectionSearch) {
        const rows = [...document.querySelectorAll('#connectionResults tr[data-origin]')],
            size = document.querySelector('#connectionSize'),
            pageSelect = document.querySelector('#connectionPageNumber'),
            previous = document.querySelector('#connectionPrevious'),
            next = document.querySelector('#connectionNext');
        let page = 1,
            filtered = [];
        const render = () => {
            const limit = Number(size.value),
                pages = Math.max(1, Math.ceil(filtered.length / limit));
            page = Math.max(1, Math.min(page, pages));
            rows.forEach((row) => (row.hidden = true));
            filtered.slice((page - 1) * limit, page * limit).forEach((row) => (row.hidden = false));
            document.querySelector('#connectionEmpty').hidden = filtered.length > 0;
            document.querySelector('#connectionRange').textContent =
                (filtered.length
                    ? `${(page - 1) * limit + 1}–${Math.min(page * limit, filtered.length)}`
                    : '0') + ` of ${filtered.length} results`;
            pageSelect.replaceChildren(
                ...Array.from(
                    { length: pages },
                    (_, i) => new Option(String(i + 1), String(i + 1), false, i + 1 === page),
                ),
            );
            document.querySelector('#connectionPageCount').textContent = `of ${pages} pages`;
            previous.disabled = page === 1;
            next.disabled = page === pages;
        };
        const filter = () => {
            page = 1;
            filtered = rows.filter(
                (row) =>
                    (!origin.value || row.dataset.origin === origin.value) &&
                    row.textContent
                        .toLowerCase()
                        .includes(connectionSearch.value.trim().toLowerCase()),
            );
            render();
        };
        connectionSearch.addEventListener('input', filter);
        origin.addEventListener('change', filter);
        size.addEventListener('change', filter);
        previous.addEventListener('click', () => {
            page--;
            render();
        });
        next.addEventListener('click', () => {
            page++;
            render();
        });
        pageSelect.addEventListener('change', () => {
            page = Number(pageSelect.value);
            render();
        });
        filter();
        const deviceMatch = location.hash.match(/^#(?:connection|discovery)-device-(\d+)$/);
        if (deviceMatch) {
            const reporter = rows.find((row) => row.dataset.reporterId === deviceMatch[1]);
            if (reporter) {
                connectionSearch.value = reporter.cells[0].textContent.split(' / ')[0].trim();
                filter();
                document.querySelector('#connectionResults').scrollIntoView({ block: 'start' });
            }
        }
    }

    const editor = document.querySelector('#networkEditor');
    if (editor) {
        const schedule = document.querySelector('#network_sched_type'),
            ping = document.querySelector('#network_ping_method'),
            notify = document.querySelector('#network_notification_enabled');
        function visibility() {
            editor
                .querySelectorAll('[data-network-schedules]')
                .forEach(
                    (field) =>
                        (field.hidden = !field.dataset.networkSchedules
                            .split(',')
                            .includes(schedule.value)),
                );
            editor
                .querySelectorAll('[data-network-ping]')
                .forEach(
                    (field) =>
                        (field.hidden = !field.dataset.networkPing.split(',').includes(ping.value)),
                );
            editor
                .querySelectorAll('[data-network-notification]')
                .forEach((field) => (field.hidden = !notify.checked));
        }
        [schedule, ping, notify].forEach((input) => input.addEventListener('change', visibility));
        visibility();
    }
    const search = document.querySelector('#discoveredSearch');
    if (!search) return;
    const source = document.querySelector('#discoveredSource'),
        method = document.querySelector('#discoveredProtocol'),
        network = document.querySelector('#discoveredNetwork'),
        size = document.querySelector('#discoveredSize'),
        previous = document.querySelector('#discoveredPrevious'),
        next = document.querySelector('#discoveredNext'),
        pageNumber = document.querySelector('#discoveredPageNumber'),
        rows = [...document.querySelectorAll('#discoveredTable tr[data-method]')];
    let page = 1,
        filtered = [];
    function render() {
        const limit = Number(size.value),
            pages = Math.max(1, Math.ceil(filtered.length / limit));
        page = Math.max(1, Math.min(page, pages));
        rows.forEach((row) => (row.hidden = true));
        filtered.slice((page - 1) * limit, page * limit).forEach((row) => (row.hidden = false));
        document.querySelector('#discoveredCount').textContent =
            (filtered.length
                ? `${(page - 1) * limit + 1}–${Math.min(page * limit, filtered.length)}`
                : '0') + ` of ${filtered.length} results`;
        document.querySelector('#discoveredRange').textContent =
            document.querySelector('#discoveredCount').textContent;
        pageNumber.replaceChildren(
            ...Array.from(
                { length: pages },
                (_, i) => new Option(String(i + 1), String(i + 1), false, i + 1 === page),
            ),
        );
        document.querySelector('#discoveredPage').textContent = `of ${pages} pages`;
        document.querySelector('#discoveredEmpty').hidden = filtered.length > 0;
        previous.disabled = page === 1;
        next.disabled = page === pages;
    }
    function filter() {
        page = 1;
        filtered = rows.filter(
            (row) =>
                (!source.value || row.dataset.source === source.value) &&
                (!method.value || row.dataset.method.split('|').includes(method.value)) &&
                (!network.value || row.dataset.network === network.value) &&
                row.textContent.toLowerCase().includes(search.value.trim().toLowerCase()),
        );
        render();
    }
    search.addEventListener('input', filter);
    [source, method, network, size].forEach((control) => control.addEventListener('change', filter));
    previous.addEventListener('click', () => {
        page--;
        render();
    });
    next.addEventListener('click', () => {
        page++;
        render();
    });
    pageNumber.addEventListener('change', () => {
        page = Number(pageNumber.value);
        render();
    });
    filter();
})();
