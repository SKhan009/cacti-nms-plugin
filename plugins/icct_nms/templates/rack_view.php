<div class="rack-toolbar">
<label class="field"><span class="field-label">Node</span><select id="rackViewNode"><option value="">Select node</option></select></label>
<button type="button" class="rack-icon" id="rackViewEdit" aria-label="Edit rack placement" aria-pressed="false" title="Edit rack placement">✎</button>
<button type="button" class="rack-icon" id="rackViewZoomIn" aria-label="Enlarge racks" title="Enlarge racks">+</button>
<button type="button" class="rack-icon" id="rackViewZoomOut" aria-label="Reduce racks" title="Reduce racks">−</button>
<button type="button" class="rack-icon" id="rackViewFullscreen" aria-label="Rack fullscreen" title="Fullscreen">⛶</button>
</div>
<p id="rackViewMessage" role="status"></p>
<div id="rackManualPlacement" hidden>
<label class="field"><span class="field-label">Device</span><select id="rackMoveDevice"></select></label>
<label class="field"><span class="field-label">Rack Number</span><select id="rackMoveRack"></select></label>
<label class="field"><span class="field-label">Start Unit</span><input id="rackMoveStart" type="number" min="1" max="100" value="1"></label>
<label class="field"><span class="field-label">Units Occupied</span><input id="rackMoveHeight" type="number" min="1" max="100" value="1"></label>
<button type="button" class="button primary" id="rackMoveSave">Place Device</button>
</div>
<div class="rack-layout"><aside id="rackDevicePool" hidden><h3>Device List</h3><p>Drag a device into a unit or peripheral slot. Drop it here to unassign it.</p><div id="rackDeviceList"></div></aside><div id="rackCabinets"></div></div>
<form id="rackViewToken" hidden><?php icct_nms_token(); ?></form>
<script type="application/json" id="rackViewData"><?= json_encode(icct_nms_rack_view_data(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
