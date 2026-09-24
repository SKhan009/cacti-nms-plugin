<?php
require_once __DIR__ . "/../../includes/topology/canvas.php";
try {
    $node_id = nms_node_id($_GET['node_id'] ?? 0);
    $canvas = nms_canvas_data($site_id, $node_id);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    print '<p role="alert">'.nms_h($e->getMessage()).'</p>';
    return;
}
$containers = nms_nodes_list();
?>
<section id="nms-hybrid" class="nms-hybrid" data-node="<?php print $node_id; ?>" data-site="<?php print (int) $site_id; ?>" data-csrf="<?php print nms_h(
	$nms_csrf_token,
); ?>" data-edit="<?php print is_realm_allowed(3) ? "1" : "0"; ?>">
<form method="get" class="nms-canvas-node-filter"><input type="hidden" name="tab" value="discovered"><label for="nms-node-filter">Node</label> <select name="node_id" id="nms-node-filter"><option value="0">All devices in topology scope</option><?php foreach($containers as $container){ ?><option value="<?php print (int)$container['id']; ?>" <?php print (int)$container['id']===$node_id?'selected':''; ?>><?php print nms_h($container['name'].' · '.$container['site_name']); ?></option><?php } ?></select> <button type="submit">Show</button><?php if($node_id){ ?> <a href="devices.php?tab=nodes&amp;node_id=<?php print $node_id; ?>">Node details</a><span> Enabled member devices and connections between them. Membership does not create links.</span><?php } ?></form>
<div class="nms-canvas-toolbar"><strong>Network topology</strong><span id="nms-topology-count"></span><div class="nms-canvas-controls"><?php if (is_realm_allowed(3)) { ?><button type="button" id="nms-edit-mode" aria-pressed="false">Edit mode</button><button type="button" id="nms-auto-arrange">Auto arrange</button><button type="button" id="nms-undo" aria-label="Undo device move" title="Undo device move" disabled>↶</button><button type="button" id="nms-redo" aria-label="Redo device move" title="Redo device move" disabled>↷</button><a class="nms-catalog-button nms-edit-connections" target="_blank" rel="noopener noreferrer" aria-label="Connections (opens in a new tab)" href="topology.php?tab=connections&amp;node_id=<?php print $node_id; ?>">Connections</a><?php } ?><button type="button" id="nms-zoom-out" aria-label="Zoom out">−</button><button type="button" id="nms-zoom-in" aria-label="Zoom in">+</button><button type="button" id="nms-fit">Fit</button><button type="button" id="nms-map-refresh">Refresh</button><button type="button" id="nms-fullscreen" aria-pressed="false">Full screen</button></div></div>
<p id="nms-canvas-notice" role="status">Drag devices to arrange; drag the background to pan. Select a device, port or link for details. Icons and port positions are schematic; only discovered ports are shown.</p>
<div class="nms-canvas-workspace"><aside class="nms-device-palette"><h3 id="nms-palette-title">Cacti devices</h3><input id="nms-device-search" type="search" placeholder="Search devices" aria-label="Search devices"><div id="nms-device-list"></div></aside><div class="nms-canvas-viewport"><svg id="nms-canvas-svg" viewBox="0 0 1200 700" role="img" aria-label="Interactive hybrid topology"></svg><section id="nms-port-details" hidden role="region" aria-labelledby="nms-detail-title" aria-live="polite"></section></div></div>
<div class="nms-canvas-legend"><span class="up">● Up</span><span class="down">● Down</span><span class="recovering">● Recovering</span><span class="unknown">● Unknown</span><span>━ Current neighbour</span><span>┄ Historical neighbour</span><span>┈ Inferred endpoint</span><span>○ Manual connection (configured style)</span></div>
<script type="application/json" id="nms-canvas-initial"><?php print json_encode(
	$canvas,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
); ?></script>
</section>
