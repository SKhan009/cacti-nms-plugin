<div class="topology-actions" hidden><button class="button" id="topologyDiscard" type="button">Discard</button><button class="button primary" id="topologySave" type="button" disabled>Save</button></div>
<p id="topologyMessage" role="status"></p>
<div class="topology-layout">
<div class="topology-stage" id="topologyStage" aria-label="Device topology"><div id="topologyCanvas"><svg id="topologyLinks" aria-label="Discovered connections"></svg><div id="topologyDevices"></div></div></div>
<div class="rack-controls topology-controls">
<button class="rack-icon" type="button" id="topologyZoomIn" aria-label="Enlarge topology">+</button><button class="rack-icon" type="button" id="topologyZoomOut" aria-label="Reduce topology">−</button><button class="rack-icon" type="button" id="topologyFit" aria-label="Fit topology">▣</button><button class="rack-icon" type="button" id="topologyFullscreen" aria-label="Topology fullscreen">⛶</button><button class="rack-icon" type="button" id="topologyEdit" aria-label="Edit topology layout" aria-pressed="false">✎</button>
</div></div>
<div id="topologyAlarms" class="icct-map-counts" aria-label="Active fault totals"></div>
<form id="topologyToken" hidden><?php icct_nms_token(); ?></form>
<dialog id="topologyDraftDialog"><h2>Unsaved topology layout</h2><p>Save your changes before leaving edit mode?</p><div class="message-actions"><button class="button" type="button" data-topology-choice="cancel">Keep editing</button><button class="button" type="button" data-topology-choice="discard">Discard</button><button class="button primary" type="button" data-topology-choice="save">Save</button></div></dialog>
<script type="application/json" id="topologyData"><?= json_encode(icct_nms_topology_data($mapData),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>

<dialog id="topologyDeviceDialog" class="topology-device-dialog" aria-labelledby="topologyDeviceTitle"><h2 id="topologyDeviceTitle"></h2><p id="topologyDeviceAddress"></p><h3>Device diagnostics</h3><div id="topologyDeviceDiagnostics" class="message-actions"></div><h3>Topology discovery</h3><div class="table-wrap"><table class="site-table"><thead><tr><th>Method</th><th>Status</th><th>Current observations</th><th>Evidence</th></tr></thead><tbody id="topologyDeviceDiscovery"></tbody></table></div><form method="dialog"><button class="button">Close</button></form></dialog>
