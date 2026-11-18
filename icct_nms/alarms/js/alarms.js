(() => {
    'use strict';
    const root = document.querySelector('#alarm-page');
    if (!root) return;
    const form = document.querySelector('#alarm-filters'),
        body = document.querySelector('#alarm-rows'),
        status = document.querySelector('#alarm-status');
    let rows = JSON.parse(document.querySelector('#alarm-data').textContent),
        page = 1,
        descending = true,
        sortKey = 'occurred',
        refreshing = false;
    const selectedDevices = new Set(),
        devicePicker = document.querySelector('#alarm-device-picker'),
        deviceSummary = document.querySelector('#alarm-device-summary'),
        deviceSearch = document.querySelector('#alarm-device-search'),
        deviceOptions = document.querySelector('#alarm-device-options');
    const node = (tag, text = '', cls = '') => {
        const e = document.createElement(tag);
        e.textContent = text;
        if (cls) e.className = cls;
        return e;
    };
    const controls = form.elements,
        management = root.dataset.management === '1';
    if (root.dataset.success) icctToast({ title: 'Saved', text: root.dataset.success });
    function ticketStatus(r) {
        if (!Number(r.escalated)) return '—';
        return {
            Open: 'OPENED',
            'In Progress': 'IN-PROGRESS',
            Resolved: 'RESOLVED',
            Closed: 'CLOSED',
        }[r.ticket_state] || 'OPENED';
    }
    function time(value) {
        if (!Number(value)) return '—';
        const p = Object.fromEntries(
            new Intl.DateTimeFormat('en-GB', {
                timeZone: 'Asia/Kolkata',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23',
            })
                .formatToParts(new Date(Number(value) * 1000))
                .map((p) => [p.type, p.value]),
        );
        return `${p.day}-${p.month}-${p.year} ${p.hour}:${p.minute}:${p.second}`;
    }
    function timeCell(value) {
        const td = node('td'),
            [day, clock] = time(value).split(' ');
        td.append(node('div', day));
        if (clock) td.append(node('div', clock));
        return td;
    }
    function devices() {
        const choices = new Map(rows
            .map((r) => [String(r.host_id), r.device_name]));
        for (const id of selectedDevices) if (!choices.has(id)) selectedDevices.delete(id);
        deviceOptions.replaceChildren();
        const all = node('button', 'All devices', 'alarm-device-all');
        all.type = 'button';
        all.setAttribute('aria-pressed', String(selectedDevices.size === 0));
        all.addEventListener('click', () => {
            selectedDevices.clear();
            devices();
            page = 1;
            render();
        });
        deviceOptions.append(all);
        for (const [id, name] of [...choices].sort((a, b) => a[1].localeCompare(b[1]))) {
            const label = node('label', '', 'alarm-device-option');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = id;
            checkbox.setAttribute('aria-label', name);
            checkbox.checked = selectedDevices.has(id);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) selectedDevices.add(id);
                else selectedDevices.delete(id);
                deviceSummary.textContent = selectedDevices.size === 0 ? 'All devices'
                    : selectedDevices.size === 1 ? choices.get([...selectedDevices][0])
                    : `${selectedDevices.size} devices`;
                all.setAttribute('aria-pressed', String(selectedDevices.size === 0));
                page = 1;
                render();
            });
            label.append(checkbox, node('span', name));
            deviceOptions.append(label);
        }
        const names = [...selectedDevices].map((id) => choices.get(id));
        deviceSummary.textContent = names.length === 0 ? 'All devices'
            : names.length === 1 ? names[0] : `${names.length} devices`;
        filterDeviceOptions();
    }
    function eventSources() {
        const select = controls.source;
        const selected = select.value.toLowerCase() === 'syslog' ? '' : select.value;
        const sources = [...new Set(rows.map((row) => row.source)
            .filter(source => source && source.toLowerCase() !== 'syslog'))];
        if (selected && !sources.includes(selected)) sources.push(selected);
        sources.sort((a, b) => a.localeCompare(b));
        if (sources.length === select.options.length - 1 &&
            sources.every((source, index) => source === select.options[index + 1].value)) return;
        select.replaceChildren(select.options[0], ...sources.map((source) => new Option(source, source)));
        select.value = selected;
    }
    function filterDeviceOptions() {
        const query = deviceSearch.value.trim().toLowerCase();
        for (const option of deviceOptions.querySelectorAll('.alarm-device-option')) {
            option.hidden = !option.textContent.toLowerCase().includes(query);
        }
    }
    function filtered() {
        const search = controls.search.value.trim().toLowerCase();
        const severity = controls.severity.value;
        const since = controls.since.value ? new Date(controls.since.value).getTime() / 1000 : null;
        const until = controls.until.value ? new Date(controls.until.value).getTime() / 1000 : null;
        if (since !== null && until !== null && since > until) {
            status.textContent = 'From must not be later than Until.';
            return [];
        }
        if (status.textContent === 'From must not be later than Until.') status.textContent = '';
        return rows
            .filter(
                (r) =>
                    (!selectedDevices.size || selectedDevices.has(String(r.host_id))) &&
                    (!severity || severity === r.severity) &&
                    (!controls.source.value || controls.source.value === r.source) &&
                    (since === null || Number(r.occurred_epoch) >= since) &&
                    (until === null || Number(r.occurred_epoch) <= until) &&
                    (!search ||
                        [r.device_name, r.address, r.name, r.description, r.fix, r.short_name]
                            .join(' ')
                            .toLowerCase()
                            .includes(search)),
            )
            .sort((a, b) => {
                const severityRank = { Information: 0, Warning: 1, Minor: 2, Major: 3, Critical: 4 };
                const escalationRank = { '—': 0, OPENED: 1, 'IN-PROGRESS': 2, RESOLVED: 3, CLOSED: 4 };
                const value = (r) => ({
                    occurred: Number(r.occurred_epoch),
                    device: String(r.device_name || '').toLowerCase(),
                    severity: severityRank[r.severity] ?? -1,
                    escalation: escalationRank[ticketStatus(r)] ?? 0,
                })[sortKey];
                const left = value(a), right = value(b);
                const order = typeof left === 'number' ? left - right : left.localeCompare(right);
                const ackOrder = sortKey === 'severity'
                    ? Number(a.acknowledged) - Number(b.acknowledged) : 0;
                return (descending ? -1 : 1) * order ||
                    (descending ? -1 : 1) * ackOrder ||
                    Number(b.occurred_epoch) - Number(a.occurred_epoch) ||
                    String(a.alarm_key).localeCompare(String(b.alarm_key));
            });
    }
    function button(label, fn, disabled = false) {
        const b = node('button', label, 'alarm-action');
        b.type = 'button';
        b.disabled = disabled;
        b.addEventListener('click', fn);
        return b;
    }
    function closeIcon() {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.innerHTML = '<path d="M9 4h12v16H9l-6-7v-2l6-7Zm3 5 6 6m0-6-6 6"/>';
        svg.classList.add('alarm-close-icon');
        return svg;
    }
    function showDetails(r) {
        location.assign('alarms/controllers/alarms.php?view=' + encodeURIComponent(r.alarm_key));
    }
    function history(r) {
        location.assign(
            'alarms/controllers/alarms.php?view=' +
                encodeURIComponent(r.alarm_key) +
                '#alarm-history',
        );
    }
    async function exportPdf(work) {
        try {
            await work();
        } catch (error) {
            status.textContent = error.message || 'Unable to generate PDF report.';
        }
    }
    function render() {
        for (const button of root.querySelectorAll('[data-alarm-sort]')) {
            const active = button.dataset.alarmSort === sortKey;
            button.classList.toggle('is-active', active);
            button.classList.toggle('is-descending', active && descending);
            button.closest('th').setAttribute('aria-sort', active ? (descending ? 'descending' : 'ascending') : 'none');
            const nextDescending = active && !descending;
            const direction = button.dataset.alarmSort === 'occurred'
                ? (nextDescending ? 'newest first' : 'oldest first')
                : (nextDescending ? 'descending' : 'ascending');
            const label = `Sort ${button.querySelector('span').textContent}, ${direction}`;
            button.setAttribute('aria-label', label);
            button.title = label;
        }
        const result = filtered(),
            size = Number(document.querySelector('#alarm-page-size').value),
            pages = Math.max(1, Math.ceil(result.length / size));
        page = Math.min(page, pages);
        body.replaceChildren();
        for (const r of result.slice((page - 1) * size, page * size)) {
            const tr = node('tr');
            tr.append(timeCell(r.occurred_epoch));
            tr.dataset.source = r.source;
            const device = node('td'),
                link = node('a', r.device_name);
            link.href = 'inventory/controllers/device.php?id=' + r.host_id + '&view=1';
            device.append(link, node('small', r.short_name || ''));
            tr.append(device);
            tr.append(node('td', r.source || '—'));
            const sev = node('td'),
                badge = node(
                    'span',
                    { Warning: 'Warn', Information: 'Info' }[r.severity] || r.severity,
                    'alarm-severity',
                );
            icctSetSeverity(badge, r.severity);
            sev.append(
                badge,
                node(
                    'small',
                    Number(r.acknowledged) ? 'Ack' : 'Not_Ack',
                    Number(r.acknowledged) ? 'alarm-status-checked' : '',
                ),
            );
            tr.append(sev);
            const esc = node('td');
            esc.append(
                node(
                    'span',
                    Number(r.escalated) ? 'Esc' : 'Not_Esc',
                    Number(r.escalated) ? 'alarm-status-checked' : '',
                ),
            );
            esc.append(node('strong', ticketStatus(r), 'alarm-ticket-status'));
            const eventName = node('td', r.name + ' (' + r.occurrences + ')');
            tr.append(esc, eventName);
            const text = node('td', r.description || '—');
            text.append(node('small', r.fix || '—'));
            tr.append(text);
            const actions = node('td'),
                group = node('div', '', 'alarm-actions');
            group.append(button('◉', () => showDetails(r)));
            group.lastChild.innerHTML =
                '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
            group.lastChild.setAttribute('aria-label', 'View ' + r.name);
            if (management) {
                const ack = button(
                    '♧',
                    () => location.assign(
                        'alarms/controllers/alarms.php?ack=' + encodeURIComponent(r.alarm_key),
                    ),
                    !!Number(r.acknowledged) || r.state === 'Closed',
                );
                ack.innerHTML =
                    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10H3v11h4m0-11 5-8c3 0 3 4 1 7h6c2 0 2 2 1 4l-2 8H7V10Z"/></svg>';
                ack.setAttribute('aria-label', 'Acknowledge ' + r.name);
                group.append(ack);
            }
            const menu = node('details'),
                summary = node('summary', '⋮');
            summary.setAttribute('aria-label', 'More actions for ' + r.name);
            menu.append(summary);
            const items = node('div', '', 'alarm-action-menu');
            menu.addEventListener('toggle', () => {
                if (!menu.open) return;
                for (const other of body.querySelectorAll('.alarm-actions details[open]')) {
                    if (other !== menu) other.open = false;
                }
                const trigger = summary.getBoundingClientRect();
                const width = items.offsetWidth;
                const height = items.offsetHeight;
                items.style.left = Math.max(8, Math.min(trigger.right - width, innerWidth - width - 8)) + 'px';
                items.style.top = (trigger.bottom + height + 8 > innerHeight
                    ? Math.max(8, trigger.top - height - 4)
                    : trigger.bottom + 4) + 'px';
            });
            items.append(
                button('Alarm History', () => {
                    menu.open = false;
                    history(r);
                }),
            );
            items.append(
                button('Download alarm report (PDF)', () => {
                    menu.open = false;
                    exportPdf(async () => {
                        const detail = await request('?view=' + encodeURIComponent(r.alarm_key) + '&readings=1');
                        await icctAlarmPdf.detail(detail, { site: root.dataset.reportSite, user: root.dataset.reportUser });
                    });
                }),
            );
            if (management) {
                for (const [type, label, disabled] of [
                    [
                        'escalate',
                        'Escalate Alarm',
                        !!Number(r.escalated) || r.state === 'Closed',
                    ],
                    [
                        'ticket_progress',
                        'In progress Ticket',
                        !Number(r.escalated) || r.ticket_state !== 'Open',
                    ],
                    [
                        'ticket_resolve',
                        'Resolve Ticket',
                        !Number(r.escalated) || !['Open', 'In Progress'].includes(r.ticket_state),
                    ],
                    [
                        'ticket_reopen',
                        'Re-Open Ticket',
                        !Number(r.escalated) || !['Resolved', 'Closed'].includes(r.ticket_state),
                    ],
                    [
                        'ticket_close',
                        'Close Ticket',
                        !Number(r.escalated) || r.ticket_state === 'Closed',
                    ],
                ]) {
                    const item = button(
                        label,
                        () => {
                            menu.open = false;
                            if (type === 'escalate') {
                                location.assign(
                                    'alarms/controllers/alarms.php?escalate=' +
                                        encodeURIComponent(r.alarm_key),
                                );
                            } else {
                                location.assign(
                                    'alarms/controllers/alarms.php?ticket=' +
                                        encodeURIComponent(r.alarm_key) +
                                        '&ticket_action=' + encodeURIComponent(type),
                                );
                            }
                        },
                        disabled,
                    );
                    if (type === 'ticket_close') {
                        item.classList.add('alarm-close-action');
                        item.append(closeIcon());
                    }
                    if (type === 'ticket_close') item.classList.add('alarm-ticket-close');
                    items.append(item);
                }
            }
            menu.append(items);
            group.append(menu);
            actions.append(group);
            tr.append(actions);
            body.append(tr);
        }
        if (!result.length) {
            const cell = node('td', 'No alarms match the current filters.');
            cell.colSpan = 8;
            const tr = node('tr');
            tr.append(cell);
            body.append(tr);
        }
        document.querySelector('#alarm-page-count').textContent = result.length
            ? (page - 1) * size +
              1 +
              '–' +
              Math.min(page * size, result.length) +
              ' of ' +
              result.length +
              ' items'
            : '0 items';
        document.querySelector('#alarm-page-number').textContent = page + ' of ' + pages + ' pages';
        document.querySelector('#alarm-prev').disabled = page === 1;
        document.querySelector('#alarm-next').disabled = page === pages;
        document.querySelector('#alarm-clear').disabled = !selectedDevices.size &&
            ![...new FormData(form).values()].some(Boolean);
    }
    async function request(query, options) {
        const response = await fetch('alarms/controllers/alarms.php' + query, {
            credentials: 'same-origin',
            ...options,
        });
        if (
            response.redirected ||
            !response.headers.get('content-type')?.includes('application/json')
        )
            throw Error('Session expired. Reload and sign in.');
        const data = await response.json();
        if (!response.ok) throw Error(data.error || 'Unable to load alarms.');
        return data;
    }
    async function refresh() {
        if (refreshing || devicePicker.open || body.querySelector('details[open]')) return;
        refreshing = true;
        try {
            rows = await request('?readings=1');
            devices();
            eventSources();
            status.textContent = '';
            render();
        } catch (e) {
            status.textContent = e.message;
        } finally {
            refreshing = false;
        }
    }
    document.addEventListener('click', (event) => {
        if (devicePicker.open && !devicePicker.contains(event.target)) devicePicker.open = false;
        for (const menu of body.querySelectorAll('.alarm-actions details[open]')) {
            if (!menu.contains(event.target) && !menu.querySelector('.alarm-action-menu').contains(event.target)) {
                menu.open = false;
            }
        }
    });
    form.addEventListener('input', () => {
        page = 1;
        render();
    });
    deviceSearch.addEventListener('input', (event) => {
        event.stopPropagation();
        filterDeviceOptions();
    });
    form.addEventListener('reset', (event) => {
        event.preventDefault();
        for (const input of Array.from(form.elements)) if (input.name) input.value = '';
        selectedDevices.clear();
        deviceSearch.value = '';
        devices();
        eventSources();
        page = 1;
        render();
    });
    for (const button of root.querySelectorAll('[data-alarm-sort]')) {
        button.addEventListener('click', () => {
            const nextKey = button.dataset.alarmSort;
            descending = nextKey === sortKey ? !descending : false;
            sortKey = nextKey;
            page = 1;
            render();
        });
    }
    document.querySelector('#alarm-page-size').addEventListener('change', () => {
        page = 1;
        render();
    });
    document.querySelector('#alarm-prev').addEventListener('click', () => {
        --page;
        render();
    });
    document.querySelector('#alarm-next').addEventListener('click', () => {
        ++page;
        render();
    });
    document.querySelector('#alarm-export').addEventListener('click', () => {
        exportPdf(() => icctAlarmPdf.list(filtered(), {
            site: root.dataset.reportSite,
            user: root.dataset.reportUser,
            selectedDevice: selectedDevices.size === 1 ? deviceSummary.textContent : '',
        }));
    });
    devices();
    eventSources();
    render();
    setInterval(refresh, 5000);
})();
