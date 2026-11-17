(() => {
    'use strict';
    const status = document.querySelector('#alarm-syslog-refresh-status');
    let seconds = Number(document.querySelector('#alarm-syslog-results').dataset.refreshSeconds);
    let normalStatus = status.textContent;
    const url = new URL(location.href);
    url.hash = '';
    url.searchParams.set('fragment', 'syslog_results');
    let busy = false;

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
            const nextSeconds = Number(next.dataset.refreshSeconds);
            if (!Number.isSafeInteger(nextSeconds) || nextSeconds <= 0) {
                throw new Error('Invalid Syslog refresh interval.');
            }
            if (nextSeconds !== seconds) {
                seconds = nextSeconds;
                normalStatus = `Checking for new Syslog readings every ${seconds} seconds.`;
                clearInterval(timer);
                timer = setInterval(refresh, seconds * 1000);
            }
            if (results.innerHTML !== next.innerHTML || results.dataset.refreshSeconds !== next.dataset.refreshSeconds) {
                results.replaceWith(next);
            }
            if (status.textContent !== normalStatus) status.textContent = normalStatus;
        } catch {
            if (!document.hidden) status.textContent = 'Unable to refresh Syslog. The page will retry automatically.';
        } finally {
            busy = false;
        }
    }

    document.addEventListener('visibilitychange', refresh);
    let timer = setInterval(refresh, seconds * 1000);
})();
