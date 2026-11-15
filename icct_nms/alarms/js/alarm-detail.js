(() => {
    'use strict';
    const root = document.querySelector('#alarm-detail-page');
    if (!root) return;
    let data = JSON.parse(document.querySelector('#alarm-detail-data').textContent),
        busy = false,
        historyPage = 1;
    const feedback = document.querySelector('#alarm-detail-feedback');
    const ackButton = document.querySelector('#alarm-detail-ack');
    const ticketMenu = document.querySelector('.alarm-detail-ticket-menu');
    if (root.dataset.success) icctToast({ title: 'Saved', text: root.dataset.success });
    const colors = icctSeverityColors,
        names = { Warning: 'Warn', Information: 'Info' };
    const make = (tag, text = '', cls = '') => {
        const n = document.createElement(tag);
        n.textContent = text;
        if (cls) n.className = cls;
        return n;
    };
    const value = (v) =>
        v === null || v === undefined || String(v).trim() === '' ? '-' : String(v);
    const tableValue = (v) =>
        v === null || v === undefined || String(v).trim() === '' ? '' : String(v);
    function date(epoch) {
        if (!Number(epoch)) return '-';
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
                .formatToParts(new Date(Number(epoch) * 1000))
                .map((p) => [p.type, p.value]),
        );
        return `${p.day}-${p.month}-${p.year} ${p.hour}:${p.minute}:${p.second}`;
    }
    function dateCell(epoch) {
        const cell = make('td');
        if (!Number(epoch)) return cell;
        const [day, time] = date(epoch).split(' ');
        cell.append(document.createTextNode(day), document.createElement('br'), document.createTextNode(time));
        return cell;
    }
    function tab(id) {
        const escalation =
            id === 'alarm-escalation' && !document.querySelector('#alarm-escalation-tab').disabled;
        for (const type of ['history', 'escalation']) {
            const selected = (type === 'escalation') === escalation;
            const button = document.querySelector('#alarm-' + type + '-tab');
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            document.querySelector('#alarm-' + type).hidden = !selected;
        }
    }
    for (const type of ['history', 'escalation'])
        document.querySelector('#alarm-' + type + '-tab').addEventListener('click', () => {
            const url = new URL(location.href);
            url.hash = 'alarm-' + type;
            history.replaceState(null, '', url.href);
            tab('alarm-' + type);
        });
    document.querySelector('.alarm-detail-tabs').addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const buttons = Array.from(root.querySelectorAll('[role=tab]:not(:disabled)'));
        const index = buttons.indexOf(document.activeElement),
            next =
                event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? buttons.length - 1
                      : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) %
                        buttons.length;
        buttons[next].click();
        buttons[next].focus();
    });
    function historyWindow(total, size, page) {
        const pages = Math.max(1, Math.ceil(total / size));
        page = Math.max(1, Math.min(page, pages));
        return { page, pages, start: (page - 1) * size, end: Math.min(page * size, total) };
    }
    function render() {
        const r = data.alarm,
            ack = data.acknowledgement,
            actor = ack
                ? (ack.actor_username ||
                      ack.actor_name ||
                      (ack.actor_id ? 'User ' + ack.actor_id : '')) +
                  (ack.actor_email ? ' (' + ack.actor_email + ')' : '')
                : '',
            fields = {
                ...r,
                occurred: date(r.occurred_epoch).replace(' ', '\n'),
                alarm_count: r.name + ' (' + r.occurrences + ')',
                ack_status: Number(r.acknowledged) ? 'Ack' : 'Not_Ack',
                escalation_status: Number(r.escalated) ? 'Esc' : 'Not_Esc',
                remarks: ack?.note,
                acknowledge_by: actor,
            };
        for (const n of root.querySelectorAll('[data-detail]')) {
            n.textContent = value(fields[n.dataset.detail]);
            if (n.dataset.detail === 'ack_status' || n.dataset.detail === 'escalation_status')
                n.classList.toggle(
                    'alarm-status-checked',
                    n.dataset.detail === 'ack_status'
                        ? !!Number(r.acknowledged)
                        : !!Number(r.escalated),
                );
        }
        const dot = document.querySelector('#alarm-device-dot');
        dot.className =
            'device-status-dot ' +
            (r.device_status === 'Up'
                ? 'online'
                : r.device_status === 'Down'
                  ? 'offline'
                  : 'other');
        dot.setAttribute('aria-label', r.device_status);
        if (ackButton) ackButton.disabled = !!Number(r.acknowledged) || r.state === 'Closed';

        const body = document.querySelector('#alarm-detail-history');
        body.replaceChildren();
        const window = historyWindow(
            data.occurrence_history.length,
            Number(document.querySelector('#alarm-history-size').value),
            historyPage,
        );
        historyPage = window.page;
        for (const event of data.occurrence_history.slice(window.start, window.end)) {
            const s = event.snapshot || {},
                tr = make('tr'),
                severity = s.severity || (colors[event.note] ? event.note : ''),
                sev = make('td');
            tr.title = event.event;
            if (colors[severity]) {
                const badge = make('span', names[severity] || severity, 'alarm-severity');
                icctSetSeverity(badge, severity);
                sev.append(badge);
            } else sev.textContent = '';
            const description = make('td', tableValue(s.description));
            description.append(make('small', tableValue(s.fix)));
            if (!Object.keys(s).length)
                description.append(
                    make('small', 'Historical content was not recorded.', 'alarm-legacy-note'),
                );
            const remarks = make('td'),
                ackStatus = make(
                    'span',
                    s.acknowledged === undefined ? '' : Number(s.acknowledged) ? 'Ack' : 'Not Ack',
                    Number(s.acknowledged) ? 'alarm-status-checked' : '',
                );
            remarks.append(
                ackStatus,
                make(
                    'small',
                    event.event === 'Acknowledged'
                        ? tableValue(event.note)
                        : tableValue(s.ack_note),
                ),
            );
            tr.append(
                dateCell(event.created_epoch),
                sev,
                description,
                remarks,
                make('td', tableValue(s.occurrences)),
            );
            body.append(tr);
        }
        if (!data.occurrence_history.length) {
            const tr = make('tr'),
                td = make('td', 'No alarm history recorded.');
            td.colSpan = 5;
            tr.append(td);
            body.append(tr);
        }
        const pagePicker = document.querySelector('#alarm-history-page');
        pagePicker.replaceChildren();
        for (let i = 1; i <= window.pages; i++) pagePicker.append(new Option(String(i), String(i)));
        pagePicker.value = String(window.page);
        document.querySelector('#alarm-history-pages').textContent =
            'of ' + window.pages + ' pages';
        document.querySelector('#alarm-history-range').textContent = data.occurrence_history.length
            ? window.start + 1 + '–' + window.end + ' of ' + data.occurrence_history.length + ' items'
            : '0 items';
        document.querySelector('#alarm-history-prev').disabled = window.page === 1;
        document.querySelector('#alarm-history-next').disabled = window.page === window.pages;
        const ticket = data.ticket || { details: null, timeline: [] };
        for (const field of root.querySelectorAll('[data-ticket]'))
            field.textContent = tableValue(ticket.details?.[field.dataset.ticket]);
        const timeline = document.querySelector('#alarm-ticket-timeline');
        timeline.replaceChildren();
        for (const event of ticket.timeline) {
            const item = make('li', '', 'alarm-ticket-event ' + event.kind);
            item.append(
                make('h3', event.label),
                make('time', date(event.created_epoch).replace(' ', '\n')),
                make('p', event.note),
            );
            timeline.append(item);
        }
        document.querySelector('#alarm-escalation-tab').disabled = !ticket.timeline.length;
        if (ticketMenu) {
            const escalated = !!Number(r.escalated);
            const state = r.ticket_state || 'Open';
            for (const button of ticketMenu.querySelectorAll('[data-ticket-action]')) {
                button.hidden = button.dataset.ticketAction === 'escalate' ? escalated : !escalated;
                button.disabled = {
                    escalate: escalated || r.state === 'Closed',
                    ticket_progress: state !== 'Open',
                    ticket_resolve: !['Open', 'In Progress'].includes(state),
                    ticket_reopen: !['Resolved', 'Closed'].includes(state),
                    ticket_close: state === 'Closed',
                }[button.dataset.ticketAction];
            }
        }
        tab(location.hash.slice(1));
    }
    async function request() {
        const response = await fetch(
            'alarms/controllers/alarms.php?view=' +
                encodeURIComponent(root.dataset.alarmKey) +
                '&readings=1',
            { credentials: 'same-origin' },
        );
        if (
            response.redirected ||
            !response.headers.get('content-type')?.includes('application/json')
        )
            throw Error('Session expired. Reload and sign in.');
        const result = await response.json();
        if (!response.ok) throw Error(result.error || 'Alarm unavailable.');
        return result;
    }
    async function refresh() {
        if (busy || ticketMenu?.open) return;
        busy = true;
        try {
            data = await request();
            render();
            feedback.textContent = '';
        } catch (e) {
            feedback.textContent = e.message;
        } finally {
            busy = false;
        }
    }
    document.querySelector('#alarm-history-size').addEventListener('change', () => {
        historyPage = 1;
        render();
    });
    if (ackButton) ackButton.addEventListener('click', () => {
        if (ackButton.disabled) return;
        location.assign('alarms/controllers/alarms.php?ack=' + encodeURIComponent(root.dataset.alarmKey));
    });
    document.querySelector('#alarm-history-page').addEventListener('change', (event) => {
        historyPage = Number(event.target.value);
        render();
    });
    document.querySelector('#alarm-history-prev').addEventListener('click', () => {
        historyPage--;
        render();
    });
    document.querySelector('#alarm-history-next').addEventListener('click', () => {
        historyPage++;
        render();
    });
    if (ticketMenu) {
        ticketMenu.addEventListener('click', (event) => {
            const button = event.target.closest('[data-ticket-action]');
            if (!button || button.disabled) return;
            if (button.dataset.ticketAction === 'escalate') {
                location.assign('alarms/controllers/alarms.php?escalate=' + encodeURIComponent(root.dataset.alarmKey));
                return;
            }
            location.assign(
                'alarms/controllers/alarms.php?ticket=' +
                    encodeURIComponent(root.dataset.alarmKey) +
                    '&ticket_action=' + encodeURIComponent(button.dataset.ticketAction) +
                    '&return=detail',
            );
        });
        document.addEventListener('click', (event) => {
            if (ticketMenu.open && !ticketMenu.contains(event.target)) ticketMenu.open = false;
        });
    }
    document.querySelector('#alarm-detail-export').addEventListener('click', async () => {
        try {
            await icctAlarmPdf.detail(data, { site: root.dataset.reportSite, user: root.dataset.reportUser });
        } catch (error) {
            feedback.textContent = error.message || 'Unable to generate PDF report.';
        }
    });
    render();
    setInterval(refresh, 30000);
})();
