<div class="topology-actions" hidden><button class="button" id="topologyDiscard" type="button">Discard</button><button class="button primary" id="topologySave" type="button" disabled>Save</button></div>
<p id="topologyMessage" role="status"></p>
<div class="topology-layout">
<div class="topology-stage" id="topologyStage" aria-label="Device topology"><div id="topologyCanvas"><svg id="topologyLinks" aria-label="Discovered connections"></svg><div id="topologyDevices"></div></div></div>
<div class="rack-controls topology-controls">
<button class="rack-icon" type="button" id="topologyZoomIn" aria-label="Enlarge topology">+</button><button class="rack-icon" type="button" id="topologyZoomOut" aria-label="Reduce topology">−</button><button class="rack-icon" type="button" id="topologyFit" aria-label="Fit topology">▣</button><button class="rack-icon" type="button" id="topologyFullscreen" aria-label="Topology fullscreen">⛶</button><button class="rack-icon" type="button" id="topologyEdit" aria-label="Edit topology layout" aria-pressed="false"><svg class="icct-edit-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m16 3 5 5L8 21l-5 1 1-5ZM14 5l5 5"/></svg></button>
</div></div>
<div id="topologyAlarms" class="icct-map-counts" aria-label="Active fault totals"></div>
<form id="topologyToken" hidden><?php icct_nms_token(); ?></form>
<dialog id="topologyDraftDialog"><h2>Unsaved topology layout</h2><p>Save your changes before leaving edit mode?</p><div class="message-actions"><button class="button" type="button" data-topology-choice="cancel">Keep editing</button><button class="button" type="button" data-topology-choice="discard">Discard</button><button class="button primary" type="button" data-topology-choice="save">Save</button></div></dialog>
<script type="application/json" id="topologyData" data-summary-url="dashboard/controllers/topology.php"><?= json_encode(icct_nms_topology_data($mapData),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>

<dialog id="topologyDeviceDialog" class="topology-device-dialog" aria-labelledby="topologyDeviceTitle">
<header class="topology-summary-heading"><div><h2 id="topologyDeviceTitle"></h2><p id="topologyDeviceAddress"></p></div><span id="topologyDeviceStatus" class="device-status"></span><img id="topologyDeviceImage" alt=""><form method="dialog"><button type="submit" class="button" aria-label="Close device summary">×</button></form></header>
<dl id="topologyDeviceCapacity" class="topology-capacity"></dl>
<dl id="topologyDeviceSummary" class="topology-summary"></dl>
<div id="topologyDeviceAlarms" class="topology-summary-alarms"></div>
<nav id="topologyDeviceLinks" class="topology-summary-links" aria-label="Device sections"></nav>
</dialog>
