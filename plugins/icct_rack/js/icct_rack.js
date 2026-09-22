(function () {
    'use strict';

    const configEl = document.getElementById('icct-rack-config');
    const cfg = window.ICCTRackConfig || (configEl ? {
        baseUrl: configEl.dataset.baseUrl || '',
        rackId: Number(configEl.dataset.rackId || 0),
        refreshSeconds: Number(configEl.dataset.refreshSeconds || 0),
        canManage: configEl.dataset.canManage === '1',
        csrfToken: configEl.dataset.csrfToken || window.csrfMagicToken || ''
    } : {});

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

    function statusLabelToClass(key) {
        return 'status-' + (key || 'unknown');
    }

    function allStatusClasses(el) {
        ['status-up','status-down','status-recovering','status-error','status-unknown','status-disabled','status-missing']
            .forEach(c => el.classList.remove(c));
    }

    function updateStatus() {
        if (!cfg.rackId || !cfg.baseUrl) return;
        fetch(cfg.baseUrl + '/ajax.php?action=status&rack_id=' + encodeURIComponent(cfg.rackId), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(r => r.json()).then(data => {
            if (!data.ok) return;
            (data.placements || []).forEach(item => {
                const el = qs('.icct-device[data-placement-id="' + item.placement_id + '"]');
                if (!el) return;
                allStatusClasses(el);
                el.classList.add(statusLabelToClass(item.status_key));
                const badge = qs('.icct-device-badge', el);
                if (badge) badge.textContent = item.status_label;
            });
            const refreshed = qs('#icct-rack-refreshed');
            if (refreshed) refreshed.textContent = data.refreshed_at || '';
            updateSummary(data.summary || {});
        }).catch(() => {});
    }

    function updateSummary(summary) {
        Object.keys(summary).forEach(key => {
            const el = qs('[data-summary-key="' + key + '"]');
            if (el) el.textContent = summary[key];
        });
    }

    function bindDeviceDetails() {
        qsa('.icct-device').forEach(el => {
            el.addEventListener('click', function (event) {
                if (event.defaultPrevented) return;
                const hostId = this.dataset.hostId;
                if (!hostId || !cfg.baseUrl) return;
                fetch(cfg.baseUrl + '/ajax.php?action=device&host_id=' + encodeURIComponent(hostId), {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                }).then(r => r.json()).then(data => {
                    if (!data.ok) return;
                    const panel = qs('#icct-device-panel');
                    if (!panel) return;
                    panel.classList.add('open');
                    const values = data.device || {};
                    Object.keys(values).forEach(k => {
                        const target = qs('[data-device-field="' + k + '"]', panel);
                        if (target) target.textContent = values[k] == null ? '' : String(values[k]);
                    });
                    const link = qs('[data-device-link]', panel);
                    if (link && data.edit_url) link.setAttribute('href', data.edit_url);
                }).catch(() => {});
            });
        });
    }

    function bindDragDrop() {
        if (!cfg.canManage) return;
        let dragged = null;

        qsa('.icct-device[draggable="true"]').forEach(el => {
            el.addEventListener('dragstart', function (event) {
                dragged = this;
                this.classList.add('dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', this.dataset.placementId || '');
                }
            });
            el.addEventListener('dragend', function () {
                this.classList.remove('dragging');
                qsa('.icct-drop-active').forEach(x => x.classList.remove('icct-drop-active'));
                dragged = null;
            });
        });

        qsa('.icct-rack-urow').forEach(row => {
            row.addEventListener('dragover', function (event) {
                if (!dragged) return;
                event.preventDefault();
                this.classList.add('icct-drop-active');
            });
            row.addEventListener('dragleave', function () {
                this.classList.remove('icct-drop-active');
            });
            row.addEventListener('drop', function (event) {
                event.preventDefault();
                this.classList.remove('icct-drop-active');
                if (!dragged) return;

                movePlacement(
                    dragged.dataset.placementId,
                    cfg.rackId,
                    this.dataset.u,
                    this.dataset.face
                );
            });
        });
    }

    function movePlacement(placementId, rackId, startU, face) {
        const body = new URLSearchParams();
        body.set('action', 'move');
        body.set('placement_id', placementId);
        body.set('rack_id', rackId);
        body.set('start_u', startU);
        body.set('face', face);
        // FIX 2026-09-15: Use the server-rendered Cacti token for drag/drop writes.
        if (cfg.csrfToken) body.set('__csrf_magic', cfg.csrfToken);

        fetch(cfg.baseUrl + '/ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(r => r.json()).then(data => {
            if (!data.ok) {
                alert((data.errors || [data.error || 'Unable to move device.']).join('\n'));
                return;
            }
            window.location.reload();
        }).catch(() => alert('Unable to contact the rack topology endpoint.'));
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindDeviceDetails();
        bindDragDrop();

        const close = qs('#icct-device-panel-close');
        if (close) close.addEventListener('click', () => qs('#icct-device-panel').classList.remove('open'));

        const rackSelect = qs('#icct-rack-selector');
        if (rackSelect) rackSelect.addEventListener('change', function () {
            const target = cfg.baseUrl + '/icct_rack.php?rack_id=' + encodeURIComponent(this.value);
            window.location.href = target;
        });

        const seconds = Number(cfg.refreshSeconds || 0);
        if (seconds > 0) {
            window.setInterval(updateStatus, seconds * 1000);
        }
    });
})();
