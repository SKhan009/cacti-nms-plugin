/** Display native Cacti site locations over the configured GeoServer raster layer. */
(function () {
    'use strict';
    var element = document.getElementById('nmsMapData');
    if (!element || !window.L) return;
    var data = JSON.parse(element.textContent);
    var indiaBounds = L.latLngBounds([6, 68], [37.5, 97.5]);
    var page = document.querySelector('.nms-map-page');
    function sizePage() {
        var top = page.getBoundingClientRect().top + window.scrollY;
        page.style.setProperty('--nms-map-page-height', Math.max(240, window.innerHeight - top) + 'px');
    }
    sizePage();
    var map = L.map('nmsSiteMap', {minZoom: 2, zoomSnap: 0, maxZoom: 18, zoomAnimation: false, markerZoomAnimation: false});
    var countryView = true;
    function fitIndia() {
        map.fitBounds(indiaBounds, {padding: [12, 12], animate: false});
        countryView = true;
    }
    fitIndia();

    map.on('dragstart zoomstart', function () { countryView = false; });
    // Recompute geographic fit when the screen or navigation rail changes size.
    window.addEventListener('resize', sizePage);
    var resizeObserver = new ResizeObserver(function () {
        var refit = countryView;
        map.invalidateSize({pan: false});

        if (refit) fitIndia();
    });
    resizeObserver.observe(document.getElementById('nmsSiteMap'));
    var status = document.getElementById('nmsMapStatus');
    var originalStatus = status.textContent;
    var mapError = '', stateError = '';
    function showStatus() {
        status.textContent = [originalStatus, mapError, stateError].filter(Boolean).join(' ');
    }
    // Every runtime resource is served by the NMS web server or its configured WMS.
    var raster = null, pendingRaster = null, rasterTimer;
    function drawBasemap() {
        if (!data.tiles) return;
        if (pendingRaster) { pendingRaster.off(); map.removeLayer(pendingRaster); }
        var view = map.getBounds();
        var bounds = L.latLngBounds(
            [Math.max(-85.051128, view.getSouth()), Math.max(-179.99999, view.getWest())],
            [Math.min(85.051128, view.getNorth()), Math.min(179.99999, view.getEast())]);
        var sw = L.CRS.EPSG3857.project(bounds.getSouthWest());
        var ne = L.CRS.EPSG3857.project(bounds.getNorthEast());
        if (sw.x >= ne.x || sw.y >= ne.y) return;
        var size = map.getSize();
        var url = data.tiles + '&bbox=' + [sw.x, sw.y, ne.x, ne.y].join(',') +
            '&width=' + Math.min(2048, Math.max(1, Math.round(size.x))) +
            '&height=' + Math.min(2048, Math.max(1, Math.round(size.y)));
        var next = L.imageOverlay(url, bounds, {pane: 'tilePane', opacity: 0,
            attribution: 'GeoServer · Natural Earth'});
        pendingRaster = next;
        next.on('load', function () {
            if (pendingRaster !== next) return;
            if (raster) map.removeLayer(raster);
            raster = next; pendingRaster = null; next.setOpacity(1);
            mapError = ''; showStatus();
        });
        next.on('error', function () {
            if (pendingRaster !== next) return;
            map.removeLayer(next); pendingRaster = null;
            mapError = 'Local basemap unavailable. Site markers and state boundaries remain available.';
            showStatus();
        });
        next.addTo(map);
    }
    map.on('moveend resize', function () {
        clearTimeout(rasterTimer); rasterTimer = setTimeout(drawBasemap, 150);
    });
    map.setMaxBounds([[-85, -179.99], [85, 179.99]]);
    // Local polygons and labels do not depend on GeoServer or external fonts.
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
                    keyboard: false, icon: L.divIcon({className: 'nms-state-label', html: label, iconSize: null})}).addTo(map));
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
        popup.className = 'nms-map-popup';
        /** Show only the selected device's requested monitoring fields. */
        function renderDevice(device) {
            var body = popup.querySelector('.nms-map-device');
            if (body) body.remove();
            body = document.createElement('div'); body.className = 'nms-map-device';
            var title = document.createElement('a');
            title.className = 'nms-map-device-name'; title.textContent = device.name;
            title.href = 'devices.php?tab=edit&id=' + encodeURIComponent(device.id);
            body.appendChild(title);
            var subtitle = document.createElement('p');
            subtitle.textContent = [device.alarm ? device.alarm.severity : device.status, device.address, device.system].filter(Boolean).join(' · ');
            body.appendChild(subtitle);
            var grid = document.createElement('dl'); grid.className = 'nms-map-device-grid';
            [['Availability', device.status], ['Category', device.category],
             ['Memory', device.memory == null ? null : device.memory + '%'], ['Serial number', device.serial],
             ['Response time', device.response_ms == null ? null : device.response_ms + ' ms'],
             ['Packet loss', device.packet_loss == null ? null : device.packet_loss + '%'],
             ['CPU', device.cpu == null ? null : device.cpu + '%']].forEach(function (field) {
                var cell = document.createElement('div');
                var label = document.createElement('dt'); label.textContent = field[0];
                var value = document.createElement('dd'); value.textContent = field[1] == null || field[1] === '' ? 'Not available' : field[1];
                cell.append(label, value); grid.appendChild(cell);
            });
            body.appendChild(grid);
            var alarm = document.createElement('div'); alarm.className = 'nms-map-device-alarm';
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
            fillColor: down ? '#c83232' : up ? '#21864a' : '#aa740a', fillOpacity: 1}).addTo(map)
            .bindTooltip(label, {direction: 'top', permanent: false, className: 'nms-map-site-label', offset: [0, -10]}).bindPopup(popup, {className: 'nms-map-compact-popup', autoPan: true, autoPanPadding: [16, 16], keepInView: true, maxWidth: 260, minWidth: 220});
    });
    /** Fit all native site markers without inventing locations for unassigned devices. */
    function fitSites() {
        countryView = false;
        var points = data.sites.map(function (s) { return s.coordinates; });
        if (points.length) map.fitBounds(points, {padding: [40, 40], maxZoom: 8, animate: false});
    }
    document.getElementById('nmsMapFit').addEventListener('click', function () {
        document.getElementById('nmsMapSite').value = ''; map.closePopup(); fitSites();
    });
    document.getElementById('nmsMapSite').addEventListener('change', function () {
        var marker = markers[this.value];
        if (marker) { countryView = false; map.setView(marker.getLatLng(), 8, {animate: false}); marker.openPopup(); } else fitSites();
    });
    drawBasemap();
})();
