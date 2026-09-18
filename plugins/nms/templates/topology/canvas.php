<?php
require_once __DIR__ . "/../../includes/topology/canvas.php";
$canvas = nms_canvas_data($site_id);
?>
<section id="nms-hybrid" class="nms-hybrid" data-site="<?php print (int) $site_id; ?>" data-csrf="<?php print nms_h(
	$nms_csrf_token,
); ?>" data-edit="<?php print is_realm_allowed(3) ? "1" : "0"; ?>">
<div class="nms-canvas-toolbar"><strong>Network topology</strong><span id="nms-topology-count"></span><div class="nms-canvas-controls"><button type="button" id="nms-zoom-out" aria-label="Zoom out">−</button><button type="button" id="nms-zoom-in" aria-label="Zoom in">+</button><button type="button" id="nms-fit">Fit</button></div></div>
<p id="nms-canvas-notice" role="status">Drag devices to arrange; drag the background to pan. Select a device, port or link for details. Icons and port positions are schematic; only discovered ports are shown.</p>
<div class="nms-canvas-workspace"><aside class="nms-device-palette"><h3 id="nms-palette-title">Cacti devices</h3><input id="nms-device-search" type="search" placeholder="Search devices" aria-label="Search devices"><div id="nms-device-list"></div></aside><div class="nms-canvas-viewport"><svg id="nms-canvas-svg" viewBox="0 0 1200 700" role="img" aria-label="Interactive hybrid topology"></svg></div></div><section id="nms-port-details" aria-live="polite"><h3>Connected ports</h3><p>Select a device or a link to inspect discovered endpoints.</p></section>
<div class="nms-canvas-legend"><span class="up">● Up</span><span class="down">● Down</span><span class="recovering">● Recovering</span><span class="unknown">● Unknown</span><span>━ Current neighbour</span><span>┄ Historical neighbour</span><span>┈ Inferred endpoint</span></div>
<script type="application/json" id="nms-canvas-initial"><?php print json_encode(
	$canvas,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
); ?></script>
</section>
