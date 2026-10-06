<p id="rackViewMessage" role="status"></p>
<div id="rackEditActions" hidden><button type="button" class="button primary" id="rackSaveDraft" disabled>Save</button></div>
<dialog id="rackDraftDialog" aria-labelledby="rackDraftTitle"><h2 id="rackDraftTitle">Unsaved rack placement</h2><p>Save your changes before leaving edit mode?</p><div class="rack-draft-actions"><button type="button" class="button" data-rack-choice="cancel">Keep editing</button><button type="button" class="button" data-rack-choice="discard">Discard</button><button type="button" class="button primary" data-rack-choice="save">Save</button></div></dialog>
<div class="rack-layout"><aside id="rackDevicePool" hidden><h3>Device List</h3><div id="rackDeviceList"></div></aside>
<div class="rack-stage"><button type="button" class="rack-slide previous" id="rackPrevious" aria-label="Previous racks" hidden>‹</button><div id="rackCabinets"></div><button type="button" class="rack-slide next" id="rackNext" aria-label="Next racks" hidden>›</button><p id="rackPageStatus" role="status"></p></div>
<div class="rack-controls"><button type="button" class="rack-icon" id="rackViewEdit" aria-label="Edit rack placement" aria-pressed="false" title="Edit rack placement"><svg class="icct-edit-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m16 3 5 5L8 21l-5 1 1-5ZM14 5l5 5"/></svg></button>
<button type="button" class="rack-icon" id="rackViewZoomIn" aria-label="Enlarge racks" title="Enlarge racks"><svg class="view-control-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M12 5v14"/></svg></button>
<button type="button" class="rack-icon" id="rackViewZoomOut" aria-label="Reduce racks" title="Reduce racks"><svg class="view-control-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg></button>
<button type="button" class="rack-icon" id="rackViewFit" aria-label="Fit racks" title="Fit racks"><svg class="view-control-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4zM8 8h8v8H8z"/></svg></button>
<button type="button" class="rack-icon" id="rackViewFullscreen" aria-label="Rack fullscreen" title="Fullscreen"><svg class="view-control-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3H3v6M15 3h6v6M3 15v6h6M21 15v6h-6"/></svg></button>
</div></div>
<div id="rackAlarms" class="icct-map-counts" aria-label="Active rack fault totals"></div>
<form id="rackViewToken" hidden><?php icct_nms_token(); ?></form>
<script type="application/json" id="rackViewData"><?= json_encode(icct_nms_rack_view_data(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
