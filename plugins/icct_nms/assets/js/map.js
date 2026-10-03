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
        page.style.setProperty('--icct-map-page-height', Math.max(240, window.innerHeight - top) + 'px');
    }
    sizePage();
    var map = L.map('icctSiteMap', {zoomControl: false, attributionControl: false, maxBoundsViscosity: 1, minZoom: 2, zoomSnap: 0, maxZoom: 18, zoomAnimation: false, markerZoomAnimation: false});
    var countryView = true;
    function fitIndia() {
        map.setMinZoom(2);
        map.fitBounds(indiaBounds, {padding: [12, 12], animate: false});
        map.setMinZoom(map.getZoom());
        countryView = true;
    }
    fitIndia();

    map.on('dragstart zoomstart', function () { countryView = false; });
    // Recompute geographic fit when the screen or navigation rail changes size.
    window.addEventListener('resize', sizePage);
    var resizeObserver = new ResizeObserver(function () {
        if (document.getElementById('icct-panel-map').hidden) return;
        var refit = countryView;
        map.invalidateSize({pan: false});

        if (refit) fitIndia();
    });
    resizeObserver.observe(document.getElementById('icctSiteMap'));
    var status = document.getElementById('icctMapStatus');
    var originalStatus = status.textContent;
    var stateError = '';
    function showStatus() {
        status.textContent = [originalStatus, stateError].filter(Boolean).join(' ');
    }
    map.setMaxBounds(indiaBounds);
    // Only India polygons are drawn; surrounding countries and ocean tiles are omitted.
    map.createPane('statePolygons');
    map.getPane('statePolygons').style.zIndex = 350;
    map.createPane('stateLabels');
    map.getPane('stateLabels').style.zIndex = 450;
    map.getPane('stateLabels').style.pointerEvents = 'none';
    fetch(data.states, {credentials: 'same-origin'}).then(function (response) {
        if (!response.ok) throw new Error('State data unavailable');
        return response.json();
    }).then(function (states) {
        var labels = [];
        L.geoJSON(states, {
            pane: 'statePolygons',
            attribution: 'States: geoBoundaries / DataMeet (CC BY 2.5 IN)',
            style: function (feature) {
                return {color: '#7d898d', weight: 1, fillColor: feature.properties.color, fillOpacity: 0.65};
            },
            onEachFeature: function (feature, layer) {
                var name = document.createElement('span'); name.textContent = feature.properties.name;
                layer.bindTooltip(name, {sticky: true});
                var point = feature.properties.label_coordinates;
                if (!point) return;
                var label = document.createElement('span'); label.textContent = feature.properties.name;
                labels.push(L.marker([point[1], point[0]], {pane: 'stateLabels', interactive: false,
                    keyboard: false, icon: L.divIcon({className: 'icct-state-label', html: label, iconSize: null})}).addTo(map));
            }
        }).addTo(map);
        function placeLabels() {
            var occupied = [];
            labels.forEach(function (label) {
                var el = label.getElement(); el.style.visibility = 'visible';
                var rect = el.firstElementChild.getBoundingClientRect();
                if (occupied.some(function (r) {
                    return rect.left < r.right + 5 && rect.right + 5 > r.left &&
                        rect.top < r.bottom + 3 && rect.bottom + 3 > r.top;
                })) el.style.visibility = 'hidden';
                else occupied.push(rect);
            });
        }
        map.on('moveend zoomend resize', placeLabels); placeLabels();
    }).catch(function () {
        stateError = 'Local state boundaries unavailable.'; showStatus();
    });
    var markers = {};
    data.sites.forEach(function (site) {
        var popup = document.createElement('div');
        popup.className = 'icct-map-popup';
        /** Show only the selected device's requested monitoring fields. */
        function renderDevice(device) {
            var body = popup.querySelector('.icct-map-device');
            if (body) body.remove();
            body = document.createElement('div'); body.className = 'icct-map-device';
            if (device.image) {
                var picture = document.createElement('img'); picture.src = device.image;
                picture.alt = device.name; picture.style.cssText = 'display:block;width:96px;height:64px;object-fit:contain;margin-bottom:8px';
                body.appendChild(picture);
            }
            var title = document.createElement('strong');
            title.className = 'icct-map-device-name'; title.textContent = device.name;
            var shape=document.createElement('span');shape.className='device-shape-symbol shape-'+(device.shape||'rectangle');shape.setAttribute('aria-label','Device shape: '+(device.shape||'rectangle'));title.prepend(shape);

            body.appendChild(title);
            var subtitle = document.createElement('p');
            subtitle.textContent = [device.alarm ? device.alarm.severity : device.status, device.address, device.system].filter(Boolean).join(' · ');
            body.appendChild(subtitle);
            var grid = document.createElement('dl'); grid.className = 'icct-map-device-grid';
            [['Availability', device.status], ['Category', device.category],
             ['Memory', device.memory == null ? null : device.memory + '%'], ['Serial number', device.serial],
             ['Response time', device.response_ms == null ? null : device.response_ms + ' ms'],
             ['Diagnostic packet loss', device.packet_loss == null ? (device.diagnostic_measurement?.state || 'Not measured') : device.packet_loss + '%'],
             ['Diagnostic latency', device.diagnostic_measurement?.latency_ms == null ? null : device.diagnostic_measurement.latency_ms + ' ms'],
             ['Diagnostic method / target', device.diagnostic_measurement?.method ? device.diagnostic_measurement.method + ' / ' + device.diagnostic_measurement.target : null],
             ['Diagnostic collector', device.diagnostic_measurement?.collector_id],
             ['Diagnostic collected', device.diagnostic_measurement?.collected_at],
             ['CPU', device.cpu == null ? null : device.cpu + '%']].forEach(function (field) {
                var cell = document.createElement('div');
                var label = document.createElement('dt'); label.textContent = field[0];
                var value = document.createElement('dd'); value.textContent = field[1] == null || field[1] === '' ? 'Not available' : field[1];
                if(field[0]==='Availability')value.className='device-status '+window.icctStatusClass(device.status);
                cell.append(label, value); grid.appendChild(cell);
            });
            body.appendChild(grid);
            var diagnosticActions=document.createElement('div');diagnosticActions.className='message-actions';
            Object.entries(device.diagnostics||{}).forEach(function(entry){var action=document.createElement('a');action.className='button';action.textContent=entry[1];action.href='diagnostics.php?host_id='+encodeURIComponent(device.id)+'&tool='+encodeURIComponent(entry[0]);diagnosticActions.appendChild(action);});
            body.appendChild(diagnosticActions);
            var alarm = document.createElement('div'); alarm.className = 'icct-map-device-alarm';
            var alarmLabel = document.createElement('strong'); alarmLabel.textContent = 'Recent alarm';
            var alarmText = document.createElement('p');
            alarmText.textContent = device.alarm ? (device.alarm.message || device.alarm.title) : 'No active alarms';
            alarm.append(alarmLabel, alarmText); body.appendChild(alarm); popup.appendChild(body);
        }
        if (site.devices.length > 1) {
            var picker = document.createElement('select'); picker.setAttribute('aria-label', 'Device at this site');
            site.devices.forEach(function (device, index) {
                var option = document.createElement('option'); option.value = index; option.textContent = device.name; picker.appendChild(option);
            });
            picker.addEventListener('change', function () { renderDevice(site.devices[Number(this.value)]); });
            popup.appendChild(picker);
        }
        renderDevice(site.devices[0]);
        var down = site.devices.some(function (d) { return d.status === 'Down'; });
        var up = site.devices.every(function (d) { return d.status === 'Up'; });
        var label = document.createElement('span');
        label.textContent = site.name;
        markers[site.id] = L.circleMarker(site.coordinates, {radius: 10, color: '#fff', weight: 2,
            fillColor: down ? '#c83232' : up ? '#21864a' : site.devices.every(d=>d.status==='Disabled') ? '#777' : '#aa740a', fillOpacity: 1}).addTo(map)
            .bindTooltip(label, {direction: 'top', permanent: false, className: 'icct-map-site-label', offset: [0, -10]}).bindPopup(popup, {className: 'icct-map-compact-popup', autoPan: true, autoPanPadding: [16, 16], keepInView: true, maxWidth: 260, minWidth: 220});
    });
    document.getElementById('icctMapZoomIn').addEventListener('click', function () { map.zoomIn(); });
    document.getElementById('icctMapZoomOut').addEventListener('click', function () { map.zoomOut(); });
    document.getElementById('icctMapFit').addEventListener('click', function () { map.closePopup(); fitIndia(); });
    document.getElementById('icctMapFullscreen').addEventListener('click', function () { if (document.fullscreenElement) document.exitFullscreen(); else document.querySelector('.icct-map-panel').requestFullscreen().catch(function () { status.textContent = 'Fullscreen is unavailable in this browser.'; }); });
    var tabs = Array.from(document.querySelectorAll('.icct-view-tabs [role="tab"]'));
    function selectView(tab) {
        if (!document.dispatchEvent(new CustomEvent('icct:before-view-change', {cancelable: true, detail: {tab: tab}}))) return;
        tabs.forEach(function (item) { var selected = item === tab; item.setAttribute('aria-selected', String(selected)); item.tabIndex = selected ? 0 : -1; document.getElementById(item.getAttribute('aria-controls')).hidden = !selected; });
        status.hidden = tab.dataset.view !== 'map';
        document.querySelector('.icct-map-credits').hidden = tab.dataset.view !== 'map';
        if (tab.dataset.view === 'map') { map.invalidateSize({pan: false}); if (countryView) fitIndia(); }
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { selectView(tab); });
        tab.addEventListener('keydown', function (event) {
            var next = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
            if (next !== null) { event.preventDefault(); tabs[next].focus(); selectView(tabs[next]); }
        });
    });
})();
