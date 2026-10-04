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
    const nodeDialog=document.querySelector('#mapNodeDialog');
    const alarmColors={Critical:'#ff4148',Major:'#ff7226',Minor:'#ff9b17',Warning:'#43aa91',Information:'#277f9b'};
    function labelNodeMarker(marker,name){const element=marker.getElement();if(element){element.setAttribute('role','button');element.setAttribute('tabindex','0');element.setAttribute('aria-label','Node '+name);element.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();marker.fire('click');}});}}
    function nodeElement(tag,text){const element=document.createElement(tag);if(text!==undefined)element.textContent=text;return element;}
    function nodeSummary(site){
        const counts={total:site.devices.length,online:0,offline:0,disabled:0,other:0},fault_counts={};
        Object.keys(alarmColors).forEach(severity=>fault_counts[severity]=0);
        site.devices.forEach(device=>{counts[device.status==='Up'?'online':device.status==='Down'?'offline':device.status==='Disabled'?'disabled':'other']++;Object.keys(alarmColors).forEach(severity=>fault_counts[severity]+=Number(device.fault_counts?.[severity]||0));});
        return {...site,counts,fault_counts,status:counts.offline?'Offline':counts.online===counts.total?'Online':counts.disabled===counts.total?'Disabled':'Other'};
    }
    function renderNode(node){
        document.querySelector('#mapNodeTitle').textContent=node.name;
        document.querySelector('#mapNodeCoordinates').textContent=node.coordinates.map((value,index)=>Math.abs(value).toFixed(4)+'° '+(index===0?(value<0?'S':'N'):(value<0?'W':'E'))).join(', ');
        const status=document.querySelector('#mapNodeStatus');status.textContent=node.status;status.className='device-status '+(node.status==='Online'?'online':node.status==='Offline'?'offline':node.status==='Disabled'?'disabled':'other');
        const counts=document.querySelector('#mapNodeCounts');counts.replaceChildren();
        [['Total Devices','total'],['Online','online'],['Offline','offline'],['Disabled','disabled']].forEach(([label,key])=>{const row=nodeElement('div');row.append(nodeElement('dt',label),nodeElement('dd',String(node.counts[key])));counts.append(row);});
        const alarms=document.querySelector('#mapNodeAlarms');alarms.replaceChildren();Object.entries(alarmColors).forEach(([severity,color])=>{const badge=nodeElement('span'),dot=nodeElement('i');dot.style.background=color;badge.append(dot,document.createTextNode((severity==='Warning'?'Warn':severity==='Information'?'Info':severity)+': '+String(node.fault_counts[severity]||0).padStart(2,'0')));alarms.append(badge);});
        document.querySelector('#mapNodeTopology').href='topology.php?site_id='+encodeURIComponent(node.id)+'&view=topology';
    }
    function showNode(site){
        if(!nodeDialog)return;
        nodeDialog.dataset.nodeId=String(site.id);renderNode(nodeSummary(site));if(!nodeDialog.open)nodeDialog.showModal();
        const endpoint=document.querySelector('#icctMapData').dataset.summaryUrl;if(!endpoint)return;
        nodeDialog.dataset.summaryState='loading';
        fetch(endpoint+'?node_summary='+encodeURIComponent(site.id),{credentials:'same-origin',cache:'no-store'}).then(response=>{if(!response.ok)throw new Error('Node unavailable');return response.json();}).then(node=>{if(nodeDialog.open&&nodeDialog.dataset.nodeId===String(node.id)){renderNode(node);nodeDialog.dataset.summaryState='current';}}).catch(()=>{if(nodeDialog.dataset.nodeId===String(site.id))nodeDialog.dataset.summaryState='unavailable';});
    }
    document.addEventListener('icct:show-node',event=>{const site=data.sites.find(s=>Number(s.id)===Number(event.detail));if(site)showNode(site);});
    var markers = {};
    data.sites.forEach(function (site) {
        var down = site.devices.some(function (d) { return d.status === 'Down'; });
        var up = site.devices.every(function (d) { return d.status === 'Up'; });
        var label = document.createElement('span');
        label.textContent = site.name;
        markers[site.id] = L.circleMarker(site.coordinates, {radius: 10, color: '#fff', weight: 2,
            fillColor: down ? '#c83232' : up ? '#21864a' : site.devices.every(d=>d.status==='Disabled') ? '#777' : '#aa740a', fillOpacity: 1}).addTo(map)
            .bindTooltip(label, {direction: 'top', permanent: false, className: 'icct-map-site-label', offset: [0, -10]}).on('click',function(){ showNode(site); });
        markers[site.id].on('add',function(){labelNodeMarker(markers[site.id],site.name);});
        labelNodeMarker(markers[site.id],site.name);
    });
    document.getElementById('icctMapZoomIn').addEventListener('click', function () { map.zoomIn(); });
    document.getElementById('icctMapZoomOut').addEventListener('click', function () { map.zoomOut(); });
    document.getElementById('icctMapFit').addEventListener('click', function () { map.closePopup(); fitIndia(); });
    document.getElementById('icctMapFullscreen').addEventListener('click', function () { if (document.fullscreenElement) document.exitFullscreen(); else document.querySelector('.icct-map-panel').requestFullscreen().catch(function () { status.textContent = 'Fullscreen is unavailable in this browser.'; }); });
    var tabs = Array.from(document.querySelectorAll('.icct-view-tabs [role="tab"]'));
    function selectView(tab, updateUrl=true) {
        if (!document.dispatchEvent(new CustomEvent('icct:before-view-change', {cancelable: true, detail: {tab: tab}}))) return;
        const destination=new URL(window.location.href);
        destination.searchParams.set('view',tab.dataset.view);
        // Map View is the overview of every authorized node, even after a node drill-down.
        if(destination.searchParams.has('site_id') && tab.dataset.view==='map'){destination.searchParams.delete('site_id');window.location.assign(destination.href);return;}
        if(updateUrl && destination.href!==window.location.href)history.pushState(null,'',destination.href);
        tabs.forEach(function (item) { var selected = item === tab; item.setAttribute('aria-selected', String(selected)); item.tabIndex = selected ? 0 : -1; document.getElementById(item.getAttribute('aria-controls')).hidden = !selected; });
        status.hidden = tab.dataset.view !== 'map';
        document.querySelector('.icct-map-credits').hidden = tab.dataset.view !== 'map';
        if (tab.dataset.view === 'map') { map.invalidateSize({pan: false}); if (countryView) fitIndia(); }
    }
    window.addEventListener('popstate',function(){const view=new URL(window.location.href).searchParams.get('view')||'topology';selectView(tabs.find(tab=>tab.dataset.view===view)||tabs[0],false);});
    const initialView=new URL(window.location.href).searchParams.get('view')||'topology';
    selectView(tabs.find(tab=>tab.dataset.view===initialView)||tabs[0],false);
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { selectView(tab); });
        tab.addEventListener('keydown', function (event) {
            var next = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
            if (next !== null) { event.preventDefault(); tabs[next].focus(); selectView(tabs[next]); }
        });
    });
})();
