/* Native DOM only: saved device positions and explicit physical cables. */
(function () {
    'use strict';
    function init() {
        const root = document.getElementById('tp-canvas-app');
        if (!root || root.dataset.ready) return;
        root.dataset.ready = '1';
        let data = JSON.parse(document.getElementById('tp-canvas-data').textContent);
        const canvas = document.getElementById('tp-canvas');
        const wires = document.getElementById('tp-wires');
        const message = document.getElementById('tp-canvas-message');
        const save = document.getElementById('tp-save-layout');
        const arrange = document.getElementById('tp-arrange');
        const viewport = document.getElementById('tp-viewport'), stage = document.getElementById('tp-stage');
        const zoomIn = document.getElementById('tp-zoom-in'), zoomOut = document.getElementById('tp-zoom-out');
        const zoomLevel = document.getElementById('tp-zoom-level'), fit = document.getElementById('tp-fit');
        const disconnect = document.getElementById('tp-disconnect');
        let zoom = 1, offsetX = 0, offsetY = 0, cableSelection = null, initiallyFitted = false;
        const tokenForm = document.getElementById('tp-canvas-token');
        let dirty = false, busy = false, selected = null, portDrag = null, suppressClickUntil = 0;
        function disable(button, value) {
            button.disabled = value;
            if (window.jQuery && jQuery.fn.button) jQuery(button).button().button('option', 'disabled', value);
        }
        const cards = new Map(), buttons = new Map();
        const tell = (text, error = false) => { message.textContent = text; message.classList.toggle('tp-error', error); };
        const markDirty = () => { dirty = true; disable(save, busy || !data.editable); tell('Layout changed. Save Layout to keep these positions.'); };
        function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function place(device) { const card = cards.get(device.id); card.style.left = device.x + 'px'; card.style.top = device.y + 'px'; }
        function portPoint(id) {
            const button = buttons.get(id); if (!button) return null;
            const r = button.getBoundingClientRect(), c = canvas.getBoundingClientRect(), list = button.parentElement.getBoundingClientRect();
            return { x: (r.left - c.left + r.width / 2) / zoom, y: (Math.max(list.top + 5 * zoom, Math.min(list.bottom - 5 * zoom, r.top + r.height / 2)) - c.top) / zoom, halfWidth: r.width / (2 * zoom) };
        }
        function draw() {
            wires.replaceChildren();
            for (const cable of data.cables) {
                const a = portPoint(cable.port_a), b = portPoint(cable.port_b); if (!a || !b) continue;
                const direction = a.x <= b.x ? 1 : -1;
                a.x += direction * a.halfWidth; b.x -= direction * b.halfWidth;
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                const bend = Math.max(60, Math.abs(b.x - a.x) * 0.45);
                path.setAttribute('d', `M ${a.x} ${a.y} C ${a.x + direction * bend} ${a.y}, ${b.x - direction * bend} ${b.y}, ${b.x} ${b.y}`);
                path.classList.toggle('tp-selected-cable', cableSelection === cable.id);
                path.addEventListener('click', () => selectCable(cable.id));
                wires.append(path);
                const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                label.setAttribute('x', (a.x + b.x) / 2); label.setAttribute('y', (a.y + b.y) / 2 - 6); label.setAttribute('text-anchor', 'middle'); label.textContent = 'Configured'; wires.append(label);
            }
            if (portDrag?.moved) {
                const a = portPoint(portDrag.id), bounds = canvas.getBoundingClientRect();
                if (a) {
                    const preview = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    preview.setAttribute('d', `M ${a.x} ${a.y} L ${(portDrag.toX - bounds.left) / zoom} ${(portDrag.toY - bounds.top) / zoom}`);
                    preview.setAttribute('stroke-dasharray', '5 4'); wires.append(preview);
                }
            }
            const occupied = new Set(data.connectedPorts.concat(data.cables.flatMap(c => [c.port_a, c.port_b])));
            for (const [id, b] of buttons) {
                b.classList.toggle('tp-connected', occupied.has(id));
                b.querySelector('span').textContent = (occupied.has(id) ? '● ' : '○ ') + b.dataset.portLabel; b.classList.toggle('tp-selected', id === selected);
                b.draggable = false;
                b.setAttribute('aria-label', b.dataset.label + (occupied.has(id) ? ', connected' : ', available'));
            }
        }
        function applyZoom(next, centerX, centerY) {
            zoom = Math.max(0.2, Math.min(2, next));
            offsetX = Math.max(0, viewport.clientWidth / 2 - centerX * zoom);
            offsetY = Math.max(0, viewport.clientHeight / 2 - centerY * zoom);
            canvas.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${zoom})`;
            stage.style.width = Math.max(viewport.clientWidth, 2400 * zoom + offsetX) + 'px';
            stage.style.height = Math.max(viewport.clientHeight, 1400 * zoom + offsetY) + 'px';
            viewport.scrollLeft = centerX * zoom + offsetX - viewport.clientWidth / 2;
            viewport.scrollTop = centerY * zoom + offsetY - viewport.clientHeight / 2;
            zoomLevel.value = Math.round(zoom * 100) + '%';
            disable(zoomOut, zoom <= 0.2); disable(zoomIn, zoom >= 2);
            draw();
        }
        function changeZoom(factor) {
            if (portDrag) return;
            applyZoom(zoom * factor, (viewport.scrollLeft + viewport.clientWidth / 2 - offsetX) / zoom, (viewport.scrollTop + viewport.clientHeight / 2 - offsetY) / zoom);
        }
        function fitDevices() {
            if (!viewport.clientWidth || !viewport.clientHeight) return;
            if (!data.devices.length) { applyZoom(1, 0, 0); return; }
            const left = Math.min(...data.devices.map(d => d.x)), top = Math.min(...data.devices.map(d => d.y));
            const right = Math.max(...data.devices.map(d => d.x + cards.get(d.id).offsetWidth));
            const bottom = Math.max(...data.devices.map(d => d.y + cards.get(d.id).offsetHeight));
            applyZoom(Math.min(1, (viewport.clientWidth - 80) / (right - left), (viewport.clientHeight - 80) / (bottom - top)), (left + right) / 2, (top + bottom) / 2);
        }
        function selectCable(id) {
            if (!data.editable || busy) return;
            const cable = data.cables.find(c => c.id === id);
            if (!cable) { tell('The connected peer is outside this view. Show All Segments to edit its cable.'); return; }
            cableSelection = id; selected = null; disable(disconnect, false); draw();
            tell(buttons.get(cable.port_a).dataset.label + ' ↔ ' + buttons.get(cable.port_b).dataset.label + '. Select Disconnect to remove this cable.');
        }
        zoomIn.addEventListener('click', () => changeZoom(1.25));
        zoomOut.addEventListener('click', () => changeZoom(0.8));
        fit.addEventListener('click', fitDevices);
        disconnect.addEventListener('click', async () => {
            if (!data.editable || busy || cableSelection === null) return;
            const id = cableSelection; disable(disconnect, true);
            try { const next = await post('disconnect', {cable_id:id}); data.cables = next.cables; data.connectedPorts = next.connectedPorts; cableSelection = null; draw(); tell('Physical cable disconnected.'); }
            catch (e) { disable(disconnect, false); tell(e.message, true); }
        });
        async function post(action, values) {
            if (busy) throw new Error('Wait for the current save.');
            busy = true; disable(save, true);
            const body = new FormData(tokenForm); body.set('tp_action', action); body.set('format', 'json');
            for (const [k, v] of Object.entries(values)) body.set(k, String(v));
            try {
                const response = await fetch(tokenForm.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Cacti session expired or request rejected. Reload and sign in; unsaved positions are still shown.');
                const result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.error || 'Cacti could not save this change.');
                return result.data;
            } finally { busy = false; disable(save, !dirty || !data.editable); }
        }
        async function connect(a, b) {
            selected = null; draw();
            if (!data.editable || busy || a === b) return;
            try {
                const next = await post('connect', { port_a: a, port_b: b });
                // Keep locally moved positions and their revision until they are explicitly saved.
                data.cables = next.cables; data.connectedPorts = next.connectedPorts; draw(); tell('Physical cable saved. Source: Configured.');
            } catch (e) { tell(e.message, true); }
        }
        for (const device of data.devices) {
            const card = el('section', 'tp-device'); card.dataset.hostId = device.id;
            card.style.setProperty('--tp-category', /^#[0-9a-f]{6}$/i.test(device.categoryColor) ? device.categoryColor : '#64748b'); cards.set(device.id, card);
            const head = el('div', 'tp-device-head'); head.tabIndex = data.editable ? 0 : -1;
            head.setAttribute('aria-label', `Move ${device.name}. Use arrow keys; Shift moves faster.`);
            head.append(el('strong', '', device.name), el('div', 'tp-device-meta', [device.category, device.type, device.role].filter(Boolean).join(' · ')));
            const health = el('div', 'tp-device-health');
            const state = ['up','down','recovering','error','disabled','unknown'].includes(device.statusClass) ? device.statusClass : 'unknown';
            health.append(el('span', 'tp-status tp-status-' + state, '● ' + device.status));
            if (device.simulated) health.append(el('span', 'tp-simulated', 'Simulated'));
            head.append(health);
            card.append(head);
            let drag = null;
            head.addEventListener('pointerdown', e => { if (!data.editable || busy || e.button !== 0) return; drag = { x:e.clientX, y:e.clientY, left:device.x, top:device.y }; head.setPointerCapture(e.pointerId); });
            head.addEventListener('pointermove', e => { if (!drag) return; device.x = Math.max(0, Math.min(2160, Math.round(drag.left + (e.clientX - drag.x) / zoom))); device.y = Math.max(0, Math.min(1100, Math.round(drag.top + (e.clientY - drag.y) / zoom))); place(device); draw(); markDirty(); });
            head.addEventListener('pointerup', () => { drag = null; }); head.addEventListener('pointercancel', () => { drag = null; });
            head.addEventListener('keydown', e => { if (!data.editable || busy) return; const dirs = { ArrowLeft:[-1,0], ArrowRight:[1,0], ArrowUp:[0,-1], ArrowDown:[0,1] }; if (!dirs[e.key]) return; e.preventDefault(); const step = e.shiftKey ? 50 : 10; device.x = Math.max(0, Math.min(2160, device.x + dirs[e.key][0] * step)); device.y = Math.max(0, Math.min(1100, device.y + dirs[e.key][1] * step)); place(device); draw(); markDirty(); });
            const list = el('div', 'tp-port-list'); list.addEventListener('scroll', draw);
            for (const port of device.ports) {
                const b = el('button', 'tp-port'); b.type = 'button'; b.dataset.portId = port.id; b.dataset.label = device.name + ' / ' + port.label; b.dataset.portLabel = port.label;
                b.append(el('span', '', '○ ' + port.label), el('small', '', port.connector + (port.if_index ? ' · if' + port.if_index : '')));
                b.title = `${b.dataset.label} — ${port.if_index ? 'mapped to ifIndex ' + port.if_index : 'interface unmapped'}`;
                const occupied = () => data.connectedPorts.includes(port.id) || data.cables.some(c => c.port_a === port.id || c.port_b === port.id);
                b.addEventListener('pointerdown', e => {
                    if (!data.editable || busy || occupied() || e.button !== 0) return;
                    portDrag = {id:port.id, x:e.clientX, y:e.clientY, moved:false};
                    b.setPointerCapture(e.pointerId);
                });
                b.addEventListener('pointermove', e => {
                    if (!portDrag || portDrag.id !== port.id) return;
                    if (Math.hypot(e.clientX - portDrag.x, e.clientY - portDrag.y) > 5) portDrag.moved = true;
                    if (portDrag.moved) { selected = port.id; portDrag.toX = e.clientX; portDrag.toY = e.clientY; draw(); }
                });
                b.addEventListener('pointerup', e => {
                    if (!portDrag || portDrag.id !== port.id) return;
                    const moved = portDrag.moved; portDrag = null;
                    if (!moved) return;
                    suppressClickUntil = Date.now() + 200;
                    const target = document.elementFromPoint(e.clientX, e.clientY)?.closest('.tp-port');
                    const id = Number(target?.dataset.portId);
                    if (buttons.has(id) && id !== port.id) connect(port.id, id);
                    else { selected = null; draw(); tell('Drop onto a physical port on another device.'); }
                });
                b.addEventListener('pointercancel', () => { portDrag = null; selected = null; draw(); });
                b.addEventListener('click', () => { if (!data.editable || busy || Date.now() < suppressClickUntil) return; if (occupied()) { selectCable(data.cables.find(c => c.port_a === port.id || c.port_b === port.id)?.id); return; } if (selected && selected !== port.id) connect(selected, port.id); else { selected = selected === port.id ? null : port.id; draw(); tell(selected ? 'Select a port on another device to connect. Escape cancels.' : 'Connection selection cleared.'); } });
                buttons.set(port.id, b); list.append(b);
            }
            if (!device.ports.length) list.append(el('p', '', 'Physical ports are not configured.'));
            card.append(list);
            const foot = el('div', 'tp-device-foot');
            const config = el('a', 'ui-button ui-corner-all ui-widget', 'Configure ports'); config.href = `topo_ports.php?edit=apply&unit_id=${data.unitId}&host_id=${device.id}`;
            const host = el('a', 'ui-button ui-corner-all ui-widget', 'Cacti device'); host.href = `../../host.php?action=edit&id=${device.id}`;
            foot.append(config, host); card.append(foot); canvas.append(card); place(device);
        }
        root.addEventListener('keydown', e => { if (e.key === 'Escape') { selected = null; cableSelection = null; disable(disconnect, true); draw(); tell('Connection selection cleared.'); } });
        disable(arrange, !data.editable);
        arrange.addEventListener('click', () => { if (busy) return; data.devices.forEach((d,i) => { d.x = 40 + (i % 6) * 350; d.y = Math.min(1100, 40 + Math.floor(i / 6) * 330); place(d); }); draw(); markDirty(); });
        save.addEventListener('click', async () => {
            try { const next = await post('layout', { revision:data.revision, positions:JSON.stringify(data.devices.map(d => ({id:d.id,x:d.x,y:d.y}))) }); data.revision = next.revision; data.cables = next.cables; data.connectedPorts = next.connectedPorts; dirty = false; disable(save, true); draw(); tell('Topology layout saved.'); } catch (e) { tell(e.message, true); }
        });
        const categories = new Map(data.devices.map(d => [d.category, d.categoryColor]));
        const legend = document.getElementById('tp-category-legend');
        for (const [name, color] of categories) {
            const item = el('span', '', name);
            const swatch = el('i', 'tp-category-swatch'); swatch.style.backgroundColor = /^#[0-9a-f]{6}$/i.test(color) ? color : '#64748b';
            item.prepend(swatch); legend.append(item);
        }
        if (!data.devices.length) tell('No devices assigned. Add device assignments in Setup.');
        else if (!data.editable) tell('Read-only topology. Device management permission is required to edit.');
        // Cacti may initialize content while its AJAX container is hidden.
        // Recalculate endpoints when native layout and theme sizing become visible.
        const geometryObserver = new ResizeObserver(() => requestAnimationFrame(() => {
            if (!root.isConnected) { geometryObserver.disconnect(); window.removeEventListener('resize', draw); return; }
            if (!initiallyFitted && viewport.clientWidth && viewport.clientHeight) { initiallyFitted = true; fitDevices(); }
            else draw();
        }));
        geometryObserver.observe(viewport);
        geometryObserver.observe(canvas);
        for (const card of cards.values()) geometryObserver.observe(card);
        window.addEventListener('resize', draw);
        requestAnimationFrame(draw);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
})();
