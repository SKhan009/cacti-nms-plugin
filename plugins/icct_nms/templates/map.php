<?php /** Same local Leaflet / GeoServer map stack as NMS; native Cacti locations. */ ?>
<section class="icct-map-page">

<section class="icct-map-panel">
<div class="icct-map-toolbar">
<div class="icct-view-tabs" role="tablist" aria-label="Dashboard views">
<?php foreach (['topology'=>'Topology','rack'=>'Rack View','image'=>'Image View','map'=>'Map View'] as $key=>$label): ?><button type="button" role="tab" id="icct-view-<?= $key ?>" aria-controls="icct-panel-<?= $key ?>" aria-selected="<?= $key===$dashboardView?'true':'false' ?>" tabindex="<?= $key===$dashboardView?'0':'-1' ?>" data-view="<?= $key ?>"><?= $label ?></button><?php endforeach; ?>
</div>
<?php if(isset($_GET['site_id'])): ?><a class="button" href="topology.php?view=topology">All Nodes</a><?php endif; ?>
<div class="icct-map-counts" aria-label="Device status totals"><span>Total: <?= $mapData['counts']['total'] ?></span><span class="online">Online: <?= $mapData['counts']['online'] ?></span><span class="offline">Offline: <?= $mapData['counts']['offline'] ?></span><span class="other">Other: <?= $mapData['counts']['other'] ?></span></div>
</div>
<p id="icctMapStatus" role="status" hidden></p>
<div class="icct-view-panel" id="icct-panel-map" role="tabpanel" aria-labelledby="icct-view-map" <?= $dashboardView==='map'?'':'hidden' ?>>
<div id="icctSiteMap" aria-label="Map of Cacti sites"></div>
<div class="icct-map-controls" aria-label="Map controls">
<button id="icctMapZoomIn" type="button" aria-label="Zoom in" title="Zoom in">+</button>
<button id="icctMapZoomOut" type="button" aria-label="Zoom out" title="Zoom out">−</button>
<button id="icctMapFit" type="button" aria-label="Reset map view" title="Reset map view">▣</button>
<button id="icctMapFullscreen" type="button" aria-label="Toggle map fullscreen" title="Fullscreen">⛶</button>
</div>
</div>
<?php $viewDevices=icct_nms_inventory(); if(isset($_GET['site_id']))$viewDevices=array_values(array_filter($viewDevices,static fn($device)=>(int)$device['site_id']===(int)$_GET['site_id'])); $viewTypes=icct_nms_device_types(); ?>
<?php foreach (['topology','rack','image'] as $view): ?>
<div class="icct-view-panel icct-device-view" id="icct-panel-<?= $view ?>" role="tabpanel" aria-labelledby="icct-view-<?= $view ?>" <?= $view===$dashboardView?'':'hidden' ?>>
<?php if ($view==='rack'): ?>
<?php require __DIR__.'/rack_view.php'; ?>
<?php elseif ($view==='topology'): require __DIR__.'/topology_view.php'; ?>
<?php else: ?><div class="icct-device-cards">
<?php foreach ($viewDevices as $device): $asset=''; foreach ($viewTypes as $type) if ((int)$type['category_id']===(int)$device['category_id'] && $type['name']===$device['device_type']) { $asset=icct_nms_type_asset($type,$view==='image'?'map':'network'); break; } ?>
<a class="icct-device-card" href="device.php?id=<?= (int)$device['id'] ?>"><?php if ($asset): ?><img src="<?= icct_nms_h($asset) ?>" alt=""><?php endif; ?><strong><?= icct_nms_h($device['description']) ?></strong><span><?= icct_nms_h($device['site_name'] ?: 'Unassigned site') ?></span><span class="device-status <?= icct_nms_status_class($device['status_label']) ?>"><?= icct_nms_h($device['status_label']) ?></span></a>
<?php endforeach; ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
<details class="icct-map-credits" hidden><summary title="Map credits" aria-label="Map credits">ⓘ</summary><span>Leaflet. State boundaries: geoBoundaries / DataMeet (CC BY 2.5 IN).</span></details>
</section>
<dialog id="mapNodeDialog" class="topology-device-dialog map-node-dialog" aria-labelledby="mapNodeTitle">
<header class="topology-summary-heading"><div><h2 id="mapNodeTitle"></h2><p id="mapNodeCoordinates"></p></div><span id="mapNodeStatus" class="device-status"></span><form method="dialog"><button class="button" aria-label="Close node summary">×</button></form></header>
<dl id="mapNodeCounts" class="topology-capacity map-node-counts"></dl>
<div id="mapNodeAlarms" class="topology-summary-alarms"></div>
<nav class="topology-summary-links map-node-links" aria-label="Node actions"><a id="mapNodeTopology" href="topology.php">View Topology <span aria-hidden="true">→</span></a><button type="button" disabled title="Node chat is not configured.">Chat <span aria-hidden="true">→</span></button></nav>
</dialog>
<script type="application/json" id="icctMapData" data-summary-url="topology.php"><?= json_encode($mapData+['states'=>'assets/maps/india-states.json','tiles'=>null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</section>
