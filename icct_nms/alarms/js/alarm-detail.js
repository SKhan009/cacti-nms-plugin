(() => {
    'use strict';
    const root = document.querySelector('#alarm-detail-page');
    if (!root) return;
    let data = JSON.parse(document.querySelector('#alarm-detail-data').textContent),
        busy = false,
        historyPage = 1;
    const feedback = document.querySelector('#alarm-detail-feedback');
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
                occurred: date(r.occurred_epoch),
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

        const body = document.querySelector('#alarm-detail-history');
        body.replaceChildren();
        const window = historyWindow(
            data.history.length,
            Number(document.querySelector('#alarm-history-size').value),
            historyPage,
        );
        historyPage = window.page;
        for (const event of data.history.slice(window.start, window.end)) {
            const s = event.snapshot || {},
                tr = make('tr'),
                severity = s.severity || (colors[event.note] ? event.note : ''),
                sev = make('td');
            tr.title = event.event;
            if (colors[severity]) {
                const badge = make('span', names[severity] || severity, 'alarm-severity');
                badge.style.background = colors[severity];
                badge.style.color = severity === 'Information' ? '#fff' : '#161616';
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
                make('td', Number(event.created_epoch) ? date(event.created_epoch) : ''),
                sev,
                description,
                remarks,
                make('td', tableValue(s.occurrences)),
            );
            body.append(tr);
        }
        if (!data.history.length) {
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
        document.querySelector('#alarm-history-range').textContent = data.history.length
            ? window.start + 1 + '–' + window.end + ' of ' + data.history.length + ' items'
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
                make('time', date(event.created_epoch)),
                make('p', event.note),
            );
            timeline.append(item);
        }
        document.querySelector('#alarm-escalation-tab').disabled = !ticket.timeline.length;
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
        if (busy) return;
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
    document.querySelector('#alarm-detail-export').addEventListener('click', () => {
        const cell = (v) =>
            '"' +
            String(v ?? '')
                .replace(/^\s*(?=[=+@-])/g, "'")
                .replace(/"/g, '""') +
            '"';
        const r = data.alarm,
            rows = [
                ['Alarm', r.name],
                ['Device Name', r.device_name],
                ['Short Name', r.short_name],
                ['IP Address', r.address],
                ['Type of Network Element', r.network_type],
                ['Ack Status', Number(r.acknowledged) ? 'Ack' : 'Not_Ack'],
                ['Escalation Status', Number(r.escalated) ? 'Esc' : 'Not_Esc'],
                [],
                ['Alarm Occurred', 'Severity & Ack', 'Description & Fix', 'Ack & Remarks', 'Count'],
                ...data.history.map((e) => [
                    date(e.created_epoch),
                    e.snapshot?.severity || '',
                    (e.snapshot?.description || '') + '\n' + (e.snapshot?.fix || ''),
                    (e.snapshot?.acknowledged === undefined
                        ? ''
                        : Number(e.snapshot.acknowledged)
                          ? 'Ack'
                          : 'Not_Ack') +
                        '\n' +
                        (e.event === 'Acknowledged' ? e.note : e.snapshot?.ack_note || ''),
                    e.snapshot?.occurrences ?? '',
                ]),
                ...(data.ticket?.details
                    ? [
                          [],
                          ['Ticket Severity', data.ticket.details.severity],
                          ['Ticket Name', data.ticket.details.name],
                          ['Remarks', data.ticket.details.remarks],
                          [],
                          ['Ticket Status', 'Updated', 'Remarks'],
                          ...data.ticket.timeline.map((e) => [
                              e.label,
                              date(e.created_epoch),
                              e.note,
                          ]),
                      ]
                    : []),
            ],
            url = URL.createObjectURL(
                new Blob([rows.map((row) => row.map(cell).join(',')).join('\r\n')], {
                    type: 'text/csv;charset=utf-8',
                }),
            ),
            a = make('a');
        a.href = url;
        a.download = 'alarm-details.csv';
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    render();
    setInterval(refresh, 30000);
})();
