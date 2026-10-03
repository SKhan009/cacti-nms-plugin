<div class="rack-toolbar">
<label class="field"><span class="field-label">Node</span><select id="rackViewNode"><option value="">Select node</option></select></label>
</div>
<p id="rackViewMessage" role="status"></p>
<div id="rackEditActions" hidden><button type="button" class="button primary" id="rackSaveDraft" disabled>Save</button></div>
<dialog id="rackDraftDialog" aria-labelledby="rackDraftTitle"><h2 id="rackDraftTitle">Unsaved rack placement</h2><p>Save your changes before leaving edit mode?</p><div class="rack-draft-actions"><button type="button" class="button" data-rack-choice="cancel">Keep editing</button><button type="button" class="button" data-rack-choice="discard">Discard</button><button type="button" class="button primary" data-rack-choice="save">Save</button></div></dialog>
<div class="rack-layout"><aside id="rackDevicePool" hidden><h3>Device List</h3><p>Drag a device into a unit or peripheral slot. Drop it here to unassign it. Click Save to keep your changes.</p><div id="rackDeviceList"></div></aside>
<div class="rack-stage"><button type="button" class="rack-slide previous" id="rackPrevious" aria-label="Previous racks" hidden>‹</button><div id="rackCabinets"></div><button type="button" class="rack-slide next" id="rackNext" aria-label="Next racks" hidden>›</button><p id="rackPageStatus" role="status"></p></div>
<div class="rack-controls"><button type="button" class="rack-icon" id="rackViewEdit" aria-label="Edit rack placement" aria-pressed="false" title="Edit rack placement">✎</button>
<button type="button" class="rack-icon" id="rackViewZoomIn" aria-label="Enlarge racks" title="Enlarge racks">+</button>
<button type="button" class="rack-icon" id="rackViewZoomOut" aria-label="Reduce racks" title="Reduce racks">−</button>
<button type="button" class="rack-icon" id="rackViewFit" aria-label="Fit racks" title="Fit racks">▣</button>
<button type="button" class="rack-icon" id="rackViewFullscreen" aria-label="Rack fullscreen" title="Fullscreen">⛶</button>
</div></div>
<form id="rackViewToken" hidden><?php icct_nms_token(); ?></form>
<script type="application/json" id="rackViewData"><?= json_encode(icct_nms_rack_view_data(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
