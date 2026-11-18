'use strict';
// Hidden JSON controls keep presets and device drafts compatible with one validated format.
(() => {
    const deviceIp = document.querySelector('#device-form [name="hostname"]');
    const sourceIp = document.querySelector('[data-syslog-device-ip]');
    if (deviceIp && sourceIp) {
        const showDeviceIp = () => {
            const address = deviceIp.value.trim();
            const ipv4 = address.split('.');
            const isV4 = ipv4.length === 4 && ipv4.every(
                part => /^(0|[1-9][0-9]{0,2})$/.test(part) && Number(part) <= 255
            );
            let isV6 = false;
            if (address.includes(':') && /^[a-f0-9:.]+$/i.test(address)) {
                try {
                    isV6 = Boolean(new URL('http://[' + address + ']').hostname);
                } catch {
                    // An incomplete IPv6 address stays blank while the user types.
                }
            }
            sourceIp.value = isV4 || isV6 ? address : '';
        };
        deviceIp.addEventListener('input', showDeviceIp);
        showDeviceIp();
    }
    const menus = [...document.querySelectorAll('.syslog-select-menu')];
    document.querySelectorAll('[data-syslog-selection]').forEach((section) => {
        const hidden = section.querySelector('input[type=hidden]'),
            checks = [...section.querySelectorAll('input[type=checkbox]')],
            count = section.querySelector('.syslog-selected-count');
        const render = () => {
            const selected = JSON.parse(hidden.value || '[]');
            checks.forEach((c) => (c.checked = selected.includes(Number(c.value))));
            count.textContent = String(selected.length);
        };
        const sync = () => {
            hidden.value = JSON.stringify(
                checks.filter((c) => c.checked).map((c) => Number(c.value)),
            );
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
        };
        checks.forEach((c) => c.addEventListener('change', sync));
        section.querySelector('.syslog-select-all').addEventListener('click', () => {
            checks.forEach((c) => (c.checked = true));
            sync();
        });
        section.querySelector('.syslog-select-none').addEventListener('click', () => {
            checks.forEach((c) => (c.checked = false));
            sync();
        });
        hidden.addEventListener('input', render);
        render();
    });
    document.querySelectorAll('[data-syslog-history-severity]').forEach((menu) => {
        const summary = menu.querySelector('#syslog-severity-summary');
        menu.addEventListener('change', () => {
            const count = menu.querySelectorAll('input:checked').length;
            summary.textContent = count ? `${count} selected` : 'All severities';
        });
    });
    menus.forEach((menu) => {
        menu.querySelector('summary').addEventListener('click', () => {
            if (!menu.open)
                menus.forEach((other) => {
                    if (other !== menu) other.open = false;
                });
        });
        menu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                menu.open = false;
                menu.querySelector('summary').focus();
            }
        });
    });
    document.addEventListener('click', (event) =>
        menus.forEach((menu) => {
            if (!menu.contains(event.target)) menu.open = false;
        }),
    );
    document.querySelectorAll('.syslog-keywords').forEach((section) => {
        const hidden = section.querySelector('input[type=hidden]'),
            input = section.querySelector('.syslog-keyword-input'),
            chips = section.querySelector('.syslog-keyword-chips'),
            counter = section.querySelector('.syslog-keyword-counter');
        let values = JSON.parse(hidden.value || '[]'),
            writing = false;
        const candidates = () => [
            ...new Set([
                ...values,
                ...input.value
                    .split(',')
                    .map((s) => s.trim())
                    .filter(Boolean),
            ]),
        ];
        const sync = () => {
            const next = candidates(),
                length = Array.from(next.join(', ')).length;
            input.setCustomValidity(
                length > 148 ? 'Match strings must total no more than 148 characters.' : '',
            );
            counter.textContent = length + '/148';
            if (length <= 148) {
                writing = true;
                hidden.value = JSON.stringify(next);
                hidden.dispatchEvent(new Event('input', { bubbles: true }));
                writing = false;
            }
        };
        const render = () => {
            chips.replaceChildren();
            values.forEach((value) => {
                const chip = document.createElement('span');
                chip.className = 'syslog-keyword-chip';
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Remove ' + value);
                remove.addEventListener('click', () => {
                    values = values.filter((v) => v !== value);
                    render();
                    sync();
                });
                chip.append(remove, document.createTextNode(value));
                chips.append(chip);
            });
            counter.textContent = Array.from(values.join(', ')).length + '/148';
        };
        const commit = () => {
            sync();
            if (input.validationMessage) return;
            values = candidates();
            input.value = '';
            render();
            sync();
        };
        input.addEventListener('input', sync);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                commit();
            }
        });
        input.addEventListener('blur', commit);
        hidden.addEventListener('input', () => {
            if (!writing) {
                values = JSON.parse(hidden.value || '[]');
                input.value = '';
                render();
            }
        });
        render();
    });
})();

(() => {
    const panel = document.querySelector('#view-syslog');
    if (!panel) return;
    const status = panel.querySelector('#syslog-live-status');
    let busy = false;
    const visible = () => !panel.hidden && !document.hidden;
    const ready = () => {
        if (!visible() || panel.dataset.syslogLive !== '1') return false;
        const results = panel.querySelector('#syslog-history-results');
        if (panel.dataset.syslogPage !== '1' || results.querySelector('details[open]') ||
            results.contains(document.activeElement)) {
            status.textContent = 'Live updates paused while reviewing history.';
            return false;
        }
        return true;
    };
    async function refresh() {
        if (busy || !ready()) return;
        busy = true;
        const url = new URL(location.href);
        url.hash = '';
        url.searchParams.set('fragment', 'syslog_history');
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok || response.redirected ||
                !response.headers.get('content-type')?.includes('text/html')) {
                throw new Error('Syslog refresh returned no history.');
            }
            const next = new DOMParser().parseFromString(await response.text(), 'text/html')
                .querySelector('#view-syslog');
            if (!next) throw new Error('Syslog history is missing from the response.');
            if (!ready()) return;
            panel.dataset.syslogLive = next.dataset.syslogLive;
            for (const selector of ['.syslog-summary', '#syslog-history-results']) {
                const current = panel.querySelector(selector);
                const updated = next.querySelector(selector);
                if (current.innerHTML !== updated.innerHTML) current.replaceWith(updated);
            }
            const normalStatus = next.querySelector('#syslog-live-status').textContent;
            if (status.textContent !== normalStatus) status.textContent = normalStatus;
        } catch {
            if (visible()) status.textContent = 'Unable to refresh Syslog. Use Refresh to retry.';
        } finally {
            busy = false;
        }
    }
    panel.addEventListener('change', (event) => {
        const form = event.target.closest('[data-syslog-history-pager]');
        if (!form) return;
        if (event.target.name === 'syslog_size') form.elements.syslog_page.value = '1';
        form.requestSubmit();
    });
    panel.addEventListener('toggle', (event) => {
        if (event.target.matches('.device-record-item') && !event.target.open) refresh();
    }, true);
    window.addEventListener('hashchange', refresh);
    window.addEventListener('pageshow', refresh);
    document.addEventListener('visibilitychange', refresh);
    setInterval(refresh, 30000);
})();
