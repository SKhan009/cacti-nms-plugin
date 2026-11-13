/** Display native Cacti site locations over locally served India state boundaries. */
(function () {
    'use strict';
    var element = document.getElementById('icctMapData');
    if (!element || !window.L) return;
    var data = JSON.parse(element.textContent);
    var indiaBounds = L.latLngBounds([6, 68], [37.5, 97.5]);
    var page = document.querySelector('.icct-map-page');
    function sizePage() {
        var top = page.getBoundingClientRect().top + window.scrollY;
        page.style.setProperty(
            '--icct-map-page-height',
            Math.max(240, window.innerHeight - top) + 'px',
        );
    }
    sizePage();
    var map = L.map('icctSiteMap', {
        zoomControl: false,
        attributionControl: false,
        maxBoundsViscosity: 1,
        minZoom: 0,
        zoomSnap: 0,
        maxZoom: 23,
        zoomAnimation: false,
        markerZoomAnimation: false,
    });
    var countryView = true;
    function fitIndia() {
        map.setMinZoom(2);
        map.fitBounds(indiaBounds, { padding: [12, 12], animate: false });
        map.setMinZoom(map.getZoom());
        countryView = true;
    }
    fitIndia();

    map.on('dragstart zoomstart', function () {
        countryView = false;
    });
    // Recompute geographic fit when the screen or navigation rail changes size.
    window.addEventListener('resize', sizePage);
    var resizeObserver = new ResizeObserver(function () {
        if (document.getElementById('icct-panel-map').hidden) return;
        var refit = countryView;
        map.invalidateSize({ pan: false });

        if (refit) fitLnms();
    });
    resizeObserver.observe(document.getElementById('icctSiteMap'));
    var status = document.getElementById('icctMapStatus');
    var originalStatus = status.textContent;
    var stateError = '';
    var mapError = '';
    function showStatus() {
        status.textContent = [originalStatus, mapError, stateError].filter(Boolean).join(' ');
    }
    // LNMS sites may be outside India; do not clamp registered poller coordinates.
    showStatus();
    // Only India polygons are drawn; surrounding countries and ocean tiles are omitted.
    map.createPane('statePolygons');
    map.getPane('statePolygons').style.zIndex = 350;
    map.createPane('stateLabels');
    map.getPane('stateLabels').style.zIndex = 450;
    map.getPane('stateLabels').style.pointerEvents = 'none';
    fetch(data.states, { credentials: 'same-origin' })
        .then(function (response) {
            if (!response.ok) throw new Error('State data unavailable');
            return response.json();
        })
        .then(function (states) {
            var labels = [];
            L.geoJSON(states, {
                pane: 'statePolygons',
                attribution: 'States: geoBoundaries / DataMeet (CC BY 2.5 IN)',
                style: function (feature) {
                    return { color: '#8a8a8a', weight: 1, fillColor: '#dedede', fillOpacity: 1 };
                },
                onEachFeature: function (feature, layer) {
                    var name = document.createElement('span');
                    name.textContent = feature.properties.name;
                    layer.bindTooltip(name, { sticky: true });
                },
            }).addTo(map);
            function placeLabels() {
                var occupied = [];
                labels.forEach(function (label) {
                    var el = label.getElement();
                    el.style.visibility = 'visible';
                    var rect = el.firstElementChild.getBoundingClientRect();
                    if (
                        occupied.some(function (r) {
                            return (
                                rect.left < r.right + 5 &&
                                rect.right + 5 > r.left &&
                                rect.top < r.bottom + 3 &&
                                rect.bottom + 3 > r.top
                            );
                        })
                    )
                        el.style.visibility = 'hidden';
                    else occupied.push(rect);
                });
            }
            map.on('moveend zoomend resize', placeLabels);
            placeLabels();
        })
        .catch(function () {
            stateError = 'Local state boundaries unavailable.';
            showStatus();
        });
    let lnms = data.lnms || { nodes: [], counts: {} };
    let deviceCounts = data.counts || {};
    let activeView = new URL(location.href).searchParams.get('view') || 'topology';
    const nodeDialog = document.querySelector('#lnmsNodeDialog');
    function showLnms(node) {
        document.querySelector('#lnmsNodeTitle').textContent = node.name;
        document.querySelector('#lnmsNodeAddress').textContent = node.coordinates
            ? Math.abs(node.coordinates[0]).toFixed(4) +
              '° ' +
              (node.coordinates[0] < 0 ? 'S' : 'N') +
              ', ' +
              Math.abs(node.coordinates[1]).toFixed(4) +
              '° ' +
              (node.coordinates[1] < 0 ? 'W' : 'E')
            : 'Location unavailable';
        const badge = document.querySelector('#lnmsNodeStatus');
        badge.textContent = node.status;
        badge.className =
            'device-status ' +
            ({ Online: 'online', Offline: 'offline', Disabled: 'disabled' }[node.status] ||
                'other');
        const details = document.querySelector('#lnmsNodeDetails');
        details.replaceChildren();
        const counts = node.device_counts || {};
        const fields = {
            'Total Devices': counts.total ?? 0,
            Online: counts.online ?? 0,
            Offline: counts.offline ?? 0,
            Disabled: counts.disabled ?? 0,
        };
        Object.entries(fields).forEach(([name, value]) => {
            const row = document.createElement('div'),
                label = document.createElement('dt'),
                content = document.createElement('dd');
            label.textContent = name;
            content.textContent = value;
            row.append(label, content);
            details.append(row);
        });
        const alarms = document.querySelector('#lnmsNodeAlarms');
        alarms.replaceChildren();
        Object.entries(icctSeverityColors).forEach(([severity, color]) => {
            const pill = document.createElement('span');
            const dot = document.createElement('i');
            dot.style.background = color;
            dot.setAttribute('aria-hidden', 'true');
            const label = { Warning: 'Warn', Information: 'Info' }[severity] || severity;
            pill.append(
                dot,
                document.createTextNode(label + ': ' + (node.alarm_counts?.[severity] || 0)),
            );
            alarms.append(pill);
        });
        document.querySelector('#lnmsTopologyLink').href = node.topology_url;
        nodeDialog.dataset.collectorId = node.id;
        if (!nodeDialog.open) nodeDialog.showModal();
    }
    document.addEventListener('icct:show-lnms', (event) => {
        const node = lnms.nodes.find((n) => Number(n.id) === Number(event.detail));
        if (node) showLnms(node);
    });
    const markers = {};
    let pollerLinks = [];
    function statusTotals() {
        const target = document.querySelector('#dashboardStatusCounts');
        const counts = activeView === 'map' ? lnms.counts : deviceCounts;
        target.replaceChildren();
        target.setAttribute('aria-label', 'Device status totals');
        for (const [label, key] of [
            ['Total', 'total'],
            ['Online', 'online'],
            ['Offline', 'offline'],
            ['Disabled', 'disabled'],
        ]) {
            const span = document.createElement('span');
            span.className = key;
            span.textContent = label + ': ' + (counts[key] || 0);
            target.append(span);
        }
    }
    function labelSiteMarker(marker, label) {
        const element = marker.getElement();
        if (!element) return;
        element.setAttribute('role', 'button');
        element.setAttribute('tabindex', '0');
        element.setAttribute('aria-label', label);
        element.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                event.stopPropagation();
                marker.fire('click');
            }
        });
    }
    function drawLnms() {
        Object.values(markers).forEach((marker) => marker.remove());
        Object.keys(markers).forEach((key) => delete markers[key]);
        pollerLinks.forEach((line) => line.remove());
        pollerLinks = [];
        const notice = document.querySelector('#lnmsLocationNotice');
        notice.replaceChildren();
        notice.hidden = true;
        lnms.nodes.forEach((node) => {
            if (!node.coordinates) return;
            const label = document.createElement('span');
            label.textContent = node.name;
            markers[node.id] = L.circleMarker(node.coordinates, {
                radius: node.local ? 7 : 5,
                color: '#fff',
                weight: 1,
                fillColor:
                    { Online: '#21864a', Offline: '#c83232', Disabled: '#777' }[node.status] ||
                    '#aa740a',
                fillOpacity: 1,
            })
                .addTo(map)
                .bindTooltip(label, {
                    direction: 'top',
                    permanent: true,
                    className: 'icct-map-site-label',
                    offset: [0, -5],
                })
                .on('click', () => {
                    openSiteView(node);
                    showLnms(node);
                });
            labelSiteMarker(
                markers[node.id],
                node.name + ' · ' + node.address + ' · ' + node.status,
            );
        });
        const byId = Object.fromEntries(lnms.nodes.map((node) => [node.id, node]));
        (lnms.links || []).forEach((link) => {
            const a = byId[link.source],
                b = byId[link.target];
            if (!a?.coordinates || !b?.coordinates) return;
            const label = document.createElement('span');
            label.textContent = link.label + ' · ' + link.status;
            pollerLinks.push(
                L.polyline([a.coordinates, b.coordinates], {
                    color:
                        { Up: '#21864a', Down: '#c83232', Disabled: '#777' }[link.status] || '#777',
                    weight: 2,
                    dashArray: link.status === 'Unknown' ? '5 5' : null,
                })
                    .addTo(map)
                    .bindTooltip(label),
            );
        });
        statusTotals();
    }
    function openSiteView(site) {
        if (!site?.coordinates || !Number.isInteger(site.zoom) || site.zoom < 0 || site.zoom > 23)
            return false;
        map.setMinZoom(0);
        map.setView(site.coordinates, site.zoom, { animate: false });
        return true;
    }
    function fitLnms() {
        // A selected native site takes precedence, including sites without collectors.
        // The overview fits the country; opening a site applies its saved zoom.
        if (openSiteView(lnms.site_view)) {
            countryView = true;
            return;
        }
        const points = lnms.nodes
            .filter((node) => node.coordinates)
            .map((node) => node.coordinates);
        if (points.length && !points.every((point) => indiaBounds.contains(point))) {
            map.setMinZoom(2);
            map.fitBounds(L.latLngBounds(points), {
                padding: [36, 36],
                maxZoom: 10,
                animate: false,
            });
            countryView = true;
        } else fitIndia();
    }
    document.addEventListener('icct:device-counts', (event) => {
        deviceCounts = event.detail;
        statusTotals();
    });
    document.addEventListener('icct:lnms-readings', (event) => {
        lnms = event.detail;
        drawLnms();
        if (nodeDialog.open) {
            const current = lnms.nodes.find(
                (node) => String(node.id) === nodeDialog.dataset.collectorId,
            );
            if (current) showLnms(current);
            else nodeDialog.close();
        }
    });
    drawLnms();
    document.getElementById('icctMapZoomIn').addEventListener('click', function () {
        map.zoomIn();
    });
    document.getElementById('icctMapZoomOut').addEventListener('click', function () {
        map.zoomOut();
    });
    document.getElementById('icctMapFit').addEventListener('click', function () {
        map.closePopup();
        fitLnms();
    });
    document.getElementById('icctMapFullscreen').addEventListener('click', function () {
        if (document.fullscreenElement) document.exitFullscreen();
        else
            document
                .querySelector('.icct-map-panel')
                .requestFullscreen()
                .catch(function () {
                    status.textContent = 'Fullscreen is unavailable in this browser.';
                });
    });
    var tabs = Array.from(document.querySelectorAll('.icct-view-tabs [role="tab"]'));
    function selectView(tab, updateUrl = true) {
        if (
            !document.dispatchEvent(
                new CustomEvent('icct:before-view-change', {
                    cancelable: true,
                    detail: { tab: tab },
                }),
            )
        )
            return;
        const destination = new URL(window.location.href);
        destination.searchParams.set('view', tab.dataset.view);
        if (updateUrl && destination.href !== window.location.href)
            history.pushState(null, '', destination.href);
        tabs.forEach(function (item) {
            var selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        });
        activeView = tab.dataset.view;
        statusTotals();
        document.dispatchEvent(
            new CustomEvent('icct:view-change', { detail: { view: tab.dataset.view } }),
        );
        status.hidden = tab.dataset.view !== 'map';
        document.querySelector('.icct-map-credits').hidden = tab.dataset.view !== 'map';
        if (tab.dataset.view === 'map') {
            map.invalidateSize({ pan: false });
            if (countryView) fitLnms();
        }
    }
    window.addEventListener('popstate', function () {
        const view = new URL(window.location.href).searchParams.get('view') || 'topology';
        selectView(tabs.find((tab) => tab.dataset.view === view) || tabs[0], false);
    });
    const initialView = new URL(window.location.href).searchParams.get('view') || 'topology';
    selectView(tabs.find((tab) => tab.dataset.view === initialView) || tabs[0], false);
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            selectView(tab);
        });
        tab.addEventListener('keydown', function (event) {
            var next =
                event.key === 'ArrowRight'
                    ? (index + 1) % tabs.length
                    : event.key === 'ArrowLeft'
                      ? (index + tabs.length - 1) % tabs.length
                      : event.key === 'Home'
                        ? 0
                        : event.key === 'End'
                          ? tabs.length - 1
                          : null;
            if (next !== null) {
                event.preventDefault();
                tabs[next].focus();
                selectView(tabs[next]);
            }
        });
    });
})();
