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
    fetch(data.states, {credentials: 'same-origin'}).then(function (response) {
        if (!response.ok) throw new Error('State data unavailable');
        return response.json();
    }).then(function (states) {
        var labels = [];
        L.geoJSON(states, {
            pane: 'statePolygons',
            attribution: 'States: geoBoundaries / DataMeet (CC BY 2.5 IN)',
            style: function (feature) {
                return {color: '#8a8a8a', weight: 1, fillColor: '#dedede', fillOpacity: 1};
            },
            onEachFeature: function (feature, layer) {
                var name = document.createElement('span'); name.textContent = feature.properties.name;
                layer.bindTooltip(name, {sticky: true});

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
    let lnms=data.lnms||{nodes:[],counts:{}};
    const deviceCounts=document.querySelector('#dashboardStatusCounts').innerHTML;
    let activeView=new URL(location.href).searchParams.get('view')||'topology';
    const nodeDialog=document.querySelector('#lnmsNodeDialog');
    function showLnms(node){
        document.querySelector('#lnmsNodeTitle').textContent=node.name;
        document.querySelector('#lnmsNodeAddress').textContent=node.address;
        const badge=document.querySelector('#lnmsNodeStatus');badge.textContent=node.status;badge.className='device-status '+({Online:'online',Offline:'offline',Disabled:'disabled'}[node.status]||'other');
        const details=document.querySelector('#lnmsNodeDetails');details.replaceChildren();
        const fields={'Last heartbeat':node.last_seen||'Not received'};
        Object.entries(fields).forEach(([name,value])=>{const row=document.createElement('div'),label=document.createElement('dt'),content=document.createElement('dd');label.textContent=name;content.textContent=value;row.append(label,content);details.append(row);});
        const link=document.querySelector('#lnmsCollectorLink');link.hidden=!node.collector_url;if(node.collector_url)link.href=node.collector_url;
        if(!nodeDialog.open)nodeDialog.showModal();
    }
    document.addEventListener('icct:show-lnms',event=>{const node=lnms.nodes.find(n=>Number(n.id)===Number(event.detail));if(node)showLnms(node);});
    const markers={};
    function statusTotals(){
        const target=document.querySelector('#dashboardStatusCounts');
        if(activeView!=='map'){target.innerHTML=deviceCounts;target.setAttribute('aria-label','Device status totals');return;}
        target.replaceChildren();target.setAttribute('aria-label','Device status totals');
        for(const [label,key] of [['Total','total'],['Online','online'],['Offline','offline'],['Disabled','disabled']]){const span=document.createElement('span');span.className=key;span.textContent=label+': '+(lnms.counts[key]||0);target.append(span);}
    }
    function drawLnms(){
        Object.values(markers).forEach(marker=>marker.remove());Object.keys(markers).forEach(key=>delete markers[key]);
        const notice=document.querySelector('#lnmsLocationNotice');notice.replaceChildren();notice.hidden=true;
        lnms.nodes.forEach(node=>{
            if(!node.coordinates)return;
            const label=document.createElement('span');label.textContent=node.name;
            markers[node.id]=L.circleMarker(node.coordinates,{radius:node.local?7:5,color:'#fff',weight:1,fillColor:({Online:'#21864a',Offline:'#c83232',Disabled:'#777'})[node.status]||'#aa740a',fillOpacity:1}).addTo(map).bindTooltip(label,{direction:'top',permanent:true,className:'icct-map-site-label',offset:[0,-5]}).on('click',()=>showLnms(node));
            labelSiteMarker(markers[node.id],node.name+' · '+node.address+' · '+node.status);
        });
        statusTotals();
    }
    function fitLnms(){
        const points=lnms.nodes.filter(node=>node.coordinates).map(node=>node.coordinates);
        if(points.length){map.setMinZoom(2);map.fitBounds(L.latLngBounds(points),{padding:[36,36],maxZoom:10,animate:false});countryView=true;}
        else fitIndia();
    }
    document.addEventListener('icct:lnms-readings',event=>{lnms=event.detail;drawLnms();});
    drawLnms();
    document.getElementById('icctMapZoomIn').addEventListener('click', function () { map.zoomIn(); });
    document.getElementById('icctMapZoomOut').addEventListener('click', function () { map.zoomOut(); });
    document.getElementById('icctMapFit').addEventListener('click', function () { map.closePopup(); fitLnms(); });
    document.getElementById('icctMapFullscreen').addEventListener('click', function () { if (document.fullscreenElement) document.exitFullscreen(); else document.querySelector('.icct-map-panel').requestFullscreen().catch(function () { status.textContent = 'Fullscreen is unavailable in this browser.'; }); });
    var tabs = Array.from(document.querySelectorAll('.icct-view-tabs [role="tab"]'));
    function selectView(tab, updateUrl=true) {
        if (!document.dispatchEvent(new CustomEvent('icct:before-view-change', {cancelable: true, detail: {tab: tab}}))) return;
        const destination=new URL(window.location.href);
        destination.searchParams.set('view',tab.dataset.view);
        // Map View is the overview of every authorized site, even after a site drill-down.
        if(destination.searchParams.has('site_id') && tab.dataset.view==='map'){destination.searchParams.delete('site_id');window.location.assign(destination.href);return;}
        if(updateUrl && destination.href!==window.location.href)history.pushState(null,'',destination.href);
        tabs.forEach(function (item) { var selected = item === tab; item.setAttribute('aria-selected', String(selected)); item.tabIndex = selected ? 0 : -1; document.getElementById(item.getAttribute('aria-controls')).hidden = !selected; });
        activeView=tab.dataset.view;statusTotals();
        document.dispatchEvent(new CustomEvent('icct:view-change', {detail: {view: tab.dataset.view}}));
        status.hidden = tab.dataset.view !== 'map';
        document.querySelector('.icct-map-credits').hidden = tab.dataset.view !== 'map';
        if (tab.dataset.view === 'map') { map.invalidateSize({pan: false}); if (countryView) fitLnms(); }
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
