(() => {
    'use strict';
    const section = document.querySelector('#view-netflow');
    if (!section) return;
    const feedback = section.querySelector('#netflow-feedback'),
        tabs = [...section.querySelectorAll('[role="tab"]')],
        forms = [...section.querySelectorAll('[data-netflow-view]')],
        applied = new Map(forms.map((form) => [form.dataset.netflowView, new URLSearchParams(new FormData(form))]));
    const linkedFinding = section.querySelector('#netflow-linked-finding');
    const linkedAlarm = new URLSearchParams(location.search).get('netflow_alarm');
    if (/^[a-f0-9]{64}$/.test(linkedAlarm || '')) {
        applied.get('anomalies').set('netflow_alarm', linkedAlarm);
        linkedFinding.hidden = false;
    }
    let page = 1,
        busy = false,
        pending = false,
        activeView = 'overview';
    const make = (tag, text = '', cls = '') => {
        const n = document.createElement(tag);
        n.textContent = text;
        if (cls) n.className = cls;
        return n;
    };
    // Missing observations stay missing; a measured numeric zero remains valid.
    const numeric = (value) => {
        if (
            (typeof value !== 'number' && typeof value !== 'string') ||
            (typeof value === 'string' && value.trim() === '')
        )
            return null;
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    };
    const number = (value) => {
        const parsed = numeric(value);
        return parsed === null ? '—' : parsed.toLocaleString('en-IN');
    };
    const bytes = (value) => {
        let parsed = numeric(value);
        if (parsed === null) return '—';
        for (const unit of ['B', 'KB', 'MB', 'GB', 'TB']) {
            if (parsed < 1000 || unit === 'TB') {
                return parsed.toLocaleString('en-IN', { maximumFractionDigits: 2 }) + ' ' + unit;
            }
            parsed /= 1000;
        }
    };
    const date = (epoch) =>
        Number(epoch)
            ? new Date(Number(epoch) * 1000).toLocaleString('en-GB', {
                  timeZone: 'Asia/Kolkata',
                  hour12: false,
              })
            : '—';
    const proto = (v) => ({ 1: 'ICMP', 6: 'TCP', 17: 'UDP', 58: 'ICMPv6' })[v] || String(v);
    function cell(text, small) {
        const td = make('td', text);
        if (small) td.append(make('small', small));
        return td;
    }
    function drawChart(rows, interval) {
        const svg = document.querySelector('#netflow-chart');
        svg.replaceChildren();
        const ns = 'http://www.w3.org/2000/svg',
            add = (tag, attrs, text) => {
                const n = document.createElementNS(ns, tag);
                for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
                if (text) n.textContent = text;
                svg.append(n);
                return n;
            };
        if (!rows.length) {
            add(
                'text',
                { x: 450, y: 110, 'text-anchor': 'middle', fill: '#777' },
                'No flow readings in this time range.',
            );
            return;
        }
        if (numeric(interval) === null || Number(interval) <= 0) {
            throw new Error('Invalid flow chart interval.');
        }
        if (rows.some((row) => numeric(row.epoch) === null || numeric(row.bps) === null)) {
            throw new Error('Incomplete flow chart observation.');
        }
        const times = rows.map((r) => Number(r.epoch)),
            start = Math.min(...times),
            end = Math.max(...times),
            max = Math.max(1, ...rows.map((r) => Number(r.bps))),
            divisor = max >= 1e9 ? 1e9 : max >= 1e6 ? 1e6 : max >= 1e3 ? 1e3 : 1,
            unit = max >= 1e9 ? 'Gbps' : max >= 1e6 ? 'Mbps' : max >= 1e3 ? 'kbps' : 'bps';
        for (let i = 0; i <= 4; i++) {
            const y = 20 + i * 42;
            add('line', { x1: 80, y1: y, x2: 880, y2: y, stroke: '#e5e5e5' });
            add(
                'text',
                { x: 70, y: y + 4, 'text-anchor': 'end', fill: '#777', 'font-size': 12 },
                (((1 - i / 4) * max) / divisor).toFixed(2) + ' ' + unit,
            );
        }
        for (const [scope, color] of [
            ['LAN', '#155cff'],
            ['WAN', '#42aa8b'],
            ['Unknown', '#888'],
        ]) {
            const map = new Map(
                    rows
                        .filter((r) => r.traffic_scope === scope)
                        .map((r) => [Number(r.epoch), Number(r.bps)]),
                ),
                points = [];
            // End the line at an absent bucket; never invent traffic for missing intervals/scopes.
            const drawSegment = () => {
                if (points.length > 1) {
                    add('polyline', {
                        points: points.map((point) => point.join(',')).join(' '),
                        fill: 'none',
                        stroke: color,
                        'stroke-width': 2,
                    });
                }
                for (const [x, y] of points) add('circle', { cx: x, cy: y, r: 2, fill: color });
                points.length = 0;
            };
            for (let t = start; t <= end; t += Number(interval)) {
                if (!map.has(t)) {
                    drawSegment();
                    continue;
                }
                points.push([
                    80 + ((t - start) / Math.max(300, end - start)) * 800,
                    188 - (Number(map.get(t)) / max) * 168,
                ]);
            }
            drawSegment();
        }
        add('text', { x: 80, y: 215, fill: '#777', 'font-size': 12 }, date(start));
        add(
            'text',
            { x: 880, y: 215, 'text-anchor': 'end', fill: '#777', 'font-size': 12 },
            date(end),
        );
    }
    function render(data) {
        document.querySelector('#netflow-health').textContent =
            data.health +
            (data.last_data_epoch ? ' · Last received ' + date(data.last_data_epoch) : '');
        document.querySelector('#netflow-health').className =
            'netflow-health ' + (data.health === 'Receiving' ? 'online' : '');
        const a = data.assignment;
        document.querySelector('#netflow-configuration').textContent = a
            ? (data.loopback_exporter ? 'Local loopback exporter ' : 'Exporter ') +
              a.exporter_address +
              ' → ' +
              a.collector +
              ':' +
              a.port +
              ' · ' +
              (Number(a.version) === 10 ? 'IPFIX' : 'NetFlow v' + a.version)
            : 'Configure NetFlow under Protocol Config to associate this device with an exporter.';
        feedback.textContent = data.error || '';
        if (activeView === 'overview') renderOverview(data);
        if (activeView === 'breakdown') renderBreakdown(data);
        if (activeView === 'records') renderRecords(data);
        if (activeView === 'anomalies') renderAnomalies(data);
    }
    function renderOverview(data) {
        const live = data.live_readings;
        const liveStatus = document.querySelector('#netflow-live-status');
        const liveMessage = live?.flows
            ? 'Last 5 minutes · ' + number(live.flows) + ' flow records · Latest observation ' + date(live.latest_epoch) + ' IST'
            : 'No flow records in the last 5 minutes.';
        if (liveStatus.textContent !== liveMessage) liveStatus.textContent = liveMessage;
        section.querySelectorAll('[data-netflow-live]').forEach((reading) => {
            const value = numeric(live?.values?.[reading.dataset.netflowLive]);
            reading.textContent = value === null ? '—' :
                value.toLocaleString('en-IN', { maximumFractionDigits: 6 }) + ' ' + reading.dataset.unit;
        });
        const totals = document.querySelector('#netflow-totals');
        totals.replaceChildren();
        const hasReadings = numeric(data.totals.flows) !== null && Number(data.totals.flows) > 0;
        for (const [label, value] of [
            ['Traffic', hasReadings ? bytes(data.totals.bytes) : '—'],
            ['Packets', hasReadings ? number(data.totals.packets) : '—'],
            ['Flows', hasReadings ? number(data.totals.flows) : '—'],
            ['Source Hosts', hasReadings ? number(data.totals.sources) : '—'],
        ]) {
            const block = make('div');
            block.append(make('span', label), make('strong', value));
            totals.append(block);
        }
        document.querySelector('#netflow-chart-interval').textContent =
            Number(data.chart_interval) / 60 +
            '-minute average throughput from exported flow records.';
        drawChart(data.chart, data.chart_interval);
    }
    function renderBreakdown(data) {
        const rankings = document.querySelector('#netflow-rankings');
        rankings.replaceChildren();
        for (const [key, label] of [
            ['hosts', 'Top Source Hosts'],
            ['destinations', 'Top Destination Hosts'],
            ['protocols', 'Protocols'],
            ['applications', 'Applications'],
            ['conversations', 'Conversations'],
        ]) {
            const panel = make('div', '', 'netflow-ranking'),
                table = make('table', '', 'site-table'),
                head = make('thead'),
                hr = make('tr'),
                body = make('tbody');
            hr.append(make('th', label), make('th', 'Bytes'), make('th', 'Flows'));
            head.append(hr);
            for (const r of data.rankings[key]) {
                const tr = make('tr');
                const labelCell = cell(key === 'protocols' ? proto(r.label) : r.label),
                    bar = make('span', '', 'netflow-bar');
                bar.setAttribute('aria-hidden', 'true');
                bar.style.width =
                    (100 * Number(r.bytes)) / Math.max(1, Number(data.rankings[key][0]?.bytes)) +
                    '%';
                labelCell.append(bar);
                tr.append(labelCell, cell(bytes(r.bytes)), cell(number(r.flows)));
                body.append(tr);
            }
            if (!body.children.length) {
                const tr = make('tr'),
                    td = cell('No readings.');
                td.colSpan = 3;
                tr.append(td);
                body.append(tr);
            }
            table.append(head, body);
            panel.append(table);
            rankings.append(panel);
        }
    }
    function renderRecords(data) {
        const records = document.querySelector('#netflow-records');
        records.replaceChildren();
        for (const r of data.rows) {
            const tr = make('tr');
            tr.append(
                cell(date(r.first_epoch), date(r.last_epoch)),
                cell(Number(r.version) === 10 ? 'IPFIX' : 'v' + r.version),
                cell(r.src_addr, String(r.src_port)),
                cell(r.dst_addr, String(r.dst_port)),
                cell(proto(r.protocol)),
                cell(
                    bytes(r.bytes),
                    number(r.packets) + ' packets' + (Number(r.sampled) ? ' · sampled' : ''),
                ),
                cell(r.application, r.application_basis),
                cell(r.traffic_scope === 'Unknown' ? 'Unclassified' : r.traffic_scope),
            );
            records.append(tr);
        }
        if (!records.children.length) {
            const tr = make('tr'),
                td = cell('No flow records match the selected filters.');
            td.colSpan = 8;
            tr.append(td);
            records.append(tr);
        }
        page = data.page;
        const picker = document.querySelector('#netflow-page');
        picker.replaceChildren();
        for (let i = 1; i <= data.pages; i++) picker.append(new Option(String(i), String(i)));
        picker.value = String(page);
        document.querySelector('#netflow-pages').textContent = 'of ' + data.pages + ' pages';
        document.querySelector('#netflow-range').textContent = Number(data.totals.flows)
            ? (page - 1) * data.size +
              1 +
              '–' +
              Math.min(page * data.size, Number(data.totals.flows)) +
              ' of ' +
              number(data.totals.flows) +
              ' items'
            : '0 items';
        document.querySelector('#netflow-prev').disabled = page === 1;
        document.querySelector('#netflow-next').disabled = page === data.pages;
    }
    function renderAnomalies(data) {
        const events = document.querySelector('#netflow-events');
        events.replaceChildren();
        for (const r of data.events) {
            const tr = make('tr'),
                sev = make('span', r.severity, 'alarm-severity');
            icctSetSeverity(sev, r.severity);
            const td = cell('');
            td.append(sev);
            const alarm = cell(r.alarm_key ? '' : '—');
            if (r.alarm_key) {
                const link = make('a', 'View alarm');
                link.href = 'alarms/controllers/alarms.php?view=' + encodeURIComponent(r.alarm_key);
                alarm.append(link);
            }
            tr.append(
                cell(date(r.opened_epoch), date(r.updated_epoch)),
                td,
                cell(r.state),
                cell(r.message),
                alarm,
            );
            events.append(tr);
        }
        if (!events.children.length) {
            let message = 'No recorded anomalies match the selected filters.';
            if (!applied.get('anomalies').has('netflow_alarm')) {
                if (!data.assignment) message = 'No exporter is configured for this device. Add one in Protocol Config to start checking for anomalies.';
                else if (data.health === 'Waiting for flows' && !data.totals?.flows)
                    message = 'Waiting for flow records. Anomalies appear when collected traffic matches a rule.';
            }
            const tr = make('tr'),
                td = cell(message);
            td.colSpan = 5;
            tr.append(td);
            events.append(tr);
        }
    }
    function filterQuery(filters) {
        const query = new URLSearchParams(filters);
        query.set('id', forms[0].dataset.deviceId);
        return query;
    }
    async function load() {
        if (busy) {
            pending = true;
            return;
        }
        busy = true;
        const view = activeView;
        const filters = applied.get(view);
        const requestedPage = page;
        const isCurrent = () => activeView === view && applied.get(view) === filters &&
            (view !== 'records' || page === requestedPage);
        const query = filterQuery(filters);
        if (view === 'overview') query.set('live', '1');
        if (view === 'records') {
            query.set('page', String(requestedPage));
            query.set('size', document.querySelector('#netflow-size').value);
        }
        try {
            const response = await fetch('protocols/netflow/controllers/netflow.php?' + query, {
                credentials: 'same-origin',
            });
            if (
                response.redirected ||
                !response.headers.get('content-type')?.includes('application/json')
            )
                throw Error('Session expired. Reload and sign in.');
            const result = await response.json();
            if (!response.ok) throw Error(result.error || 'Unable to load readings.');
            if (isCurrent()) render(result);
        } catch (e) {
            if (isCurrent()) feedback.textContent = e.message;
        } finally {
            busy = false;
            if (pending) {
                pending = false;
                load();
            }
        }
    }
    function showCustomDates(form) {
        const custom = form.elements.hours.value === 'custom';
        form.querySelectorAll('[data-netflow-custom-date]').forEach((label) => {
            label.hidden = !custom;
            const input = label.querySelector('input');
            input.disabled = !custom;
            input.required = custom;
        });
    }
    function applyFilters(form, reset) {
        if (!form.checkValidity()) return;
        const view = form.dataset.netflowView;
        const filters = new URLSearchParams(new FormData(form));
        if (filters.get('hours') === 'custom') filters.delete('hours');
        const previous = applied.get(view);
        if (previous.has('netflow_alarm')) {
            if (reset) {
                const url = new URL(location.href);
                url.searchParams.delete('netflow_alarm');
                history.replaceState(history.state, '', url);
                linkedFinding.hidden = true;
            } else {
                filters.set('netflow_alarm', previous.get('netflow_alarm'));
            }
        }
        if (!reset && filters.toString() === previous.toString()) return;
        applied.set(view, filters);
        if (view === 'records') {
            page = 1;
            document.querySelector('#netflow-export').href =
                'protocols/netflow/controllers/netflow.php?' + filterQuery(filters) + '&export=1';
        }
        if (view === 'breakdown') {
            document.querySelector('#netflow-breakdown-export').href =
                'protocols/netflow/controllers/netflow.php?' + filterQuery(filters) + '&export=breakdown';
        }
        if (activeView === view) load();
    }
    forms.forEach((form) => {
        let inputTimer;
        const apply = (reset = false) => {
            clearTimeout(inputTimer);
            showCustomDates(form);
            applyFilters(form, reset);
        };
        showCustomDates(form);
        form.addEventListener('change', () => apply());
        form.addEventListener('input', (event) => {
            if (!event.target.matches('input[type="text"], input:not([type]), input[type="number"]')) return;
            clearTimeout(inputTimer);
            inputTimer = setTimeout(apply, 350);
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            apply();
        });
        form.addEventListener('reset', () => {
            clearTimeout(inputTimer);
            setTimeout(() => apply(true), 0);
        });
    });
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            activeView = forms[index].dataset.netflowView;
            tabs.forEach((button, i) => {
                button.setAttribute('aria-selected', String(i === index));
                button.tabIndex = i === index ? 0 : -1;
                forms[i].closest('[role="tabpanel"]').hidden = i !== index;
            });
            feedback.textContent = '';
            load();
        });
        tab.addEventListener('keydown', (event) => {
            const next = {
                ArrowLeft: (index + tabs.length - 1) % tabs.length,
                ArrowRight: (index + 1) % tabs.length,
                Home: 0,
                End: tabs.length - 1,
            }[event.key];
            if (next === undefined) return;
            event.preventDefault();
            tabs[next].focus();
            tabs[next].click();
        });
    });
    document.querySelector('#netflow-size').addEventListener('change', () => {
        page = 1;
        load();
    });
    document.querySelector('#netflow-page').addEventListener('change', (e) => {
        page = Number(e.target.value);
        load();
    });
    document.querySelector('#netflow-prev').addEventListener('click', () => {
        page--;
        load();
    });
    document.querySelector('#netflow-next').addEventListener('click', () => {
        page++;
        load();
    });
    document.querySelector('#netflow-refresh').addEventListener('click', load);
    window.addEventListener('hashchange', () => {
        if (location.hash === '#view-netflow') load();
    });
    setInterval(() => {
        if (!section.hidden && !document.hidden) load();
    }, 30000);
    if (location.hash === '#view-netflow') {
        if (applied.get('anomalies').has('netflow_alarm')) {
            tabs[forms.findIndex((form) => form.dataset.netflowView === 'anomalies')].click();
        } else {
            load();
        }
    }
})();
