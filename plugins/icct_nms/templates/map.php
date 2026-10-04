<?php /** Same local Leaflet / GeoServer map stack as NMS; native Cacti locations. */ ?>
<section class="icct-map-page">

<div class="dashboard-toolbar"><label class="sr-only" for="dashboardSelect">Dashboard</label><select id="dashboardSelect"></select><div class="dashboard-widget-picker"><button type="button" class="dashboard-add-widget" id="dashboardAddWidget" aria-expanded="false" aria-controls="dashboardWidgetChoices">+ Add Widget <span aria-hidden="true">▾</span></button><div id="dashboardWidgetChoices" aria-label="Available dashboard cards" hidden></div></div><p id="dashboardSaveStatus" role="status"></p><button type="button" class="button primary" id="dashboardAdd">Add Dashboard (Max 5)</button></div>
<div class="dashboard-grid"><section class="icct-map-panel" data-widget="topology">
<div class="icct-map-toolbar"><button class="widget-remove topology-widget-remove" type="button" data-remove-widget="topology" aria-label="Remove topology widget" title="Remove topology widget">×</button>
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
<aside class="dashboard-widgets" id="dashboardWidgets">
<?php foreach(['birds'=>'Birds Eye View','alarms'=>'Alarm Overview','ack'=>'Ack Overview','escalation'=>'Escalation Overview'] as $key=>$label): ?>
<section class="dashboard-card" data-widget="<?= $key ?>"><header><button type="button" class="widget-grip" draggable="true" aria-label="Move <?= $label ?> widget" title="Drag to reorder; use arrow keys to move">⠿</button><h2><?= $label ?></h2><?php if($key==='birds'): ?><button type="button" class="widget-icon" id="birdsRefresh" aria-label="Refresh Birds Eye View" title="Refresh">↻</button><?php endif; ?><button type="button" class="widget-icon" data-remove-widget="<?= $key ?>" aria-label="Remove <?= $label ?> widget" title="Remove widget"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 9v9M14 9v9"/></svg></button></header>
<?php if($key==='birds'): ?><div class="birds-content"><svg id="birdsRadar" viewBox="0 0 320 320" role="group" aria-label="Birds Eye View centered on the Cacti server"></svg></div>
<?php elseif($key==='ack'||$key==='escalation'): ?><div class="alarm-content workflow-content"><div class="alarm-chart"><svg id="<?= $key ?>Donut" viewBox="0 0 180 180" role="img" aria-label="<?= $label ?>"></svg><ul id="<?= $key ?>Legend"></ul></div><p id="<?= $key ?>Note"></p></div>
<?php else: ?><div class="alarm-content"><div class="alarm-mode" role="group" aria-label="Alarm grouping"><button type="button" data-alarm-mode="severity" aria-pressed="true">Severity</button><button type="button" data-alarm-mode="segments" aria-pressed="false">Segment</button></div><div class="alarm-chart"><svg id="alarmDonut" viewBox="0 0 180 180" role="img" aria-label="Active alarms"></svg><ul id="alarmLegend"></ul></div><p id="alarmNote"></p></div><?php endif; ?></section>
<?php endforeach; ?>
</aside><div class="dashboard-extra-widgets" id="dashboardExtraWidgets"></div></div>

<form id="dashboardToken" hidden><?php icct_nms_token(); ?></form>
<script type="application/json" id="dashboardData"><?= json_encode(['preferences'=>$dashboardPreferences,'readings'=>$dashboardReadings,'user'=>icct_backend_current_user_id()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<dialog id="mapNodeDialog" class="topology-device-dialog map-node-dialog" aria-labelledby="mapNodeTitle">
<header class="topology-summary-heading"><div><h2 id="mapNodeTitle"></h2><p id="mapNodeCoordinates"></p></div><span id="mapNodeStatus" class="device-status"></span><form method="dialog"><button class="button" aria-label="Close node summary">×</button></form></header>
<dl id="mapNodeCounts" class="topology-capacity map-node-counts"></dl>
<div id="mapNodeAlarms" class="topology-summary-alarms"></div>
<nav class="topology-summary-links map-node-links" aria-label="Node actions"><a id="mapNodeTopology" href="topology.php">View Topology <span aria-hidden="true">→</span></a><button type="button" disabled title="Node chat is not configured.">Chat <span aria-hidden="true">→</span></button></nav>
</dialog>
<script type="application/json" id="icctMapData" data-summary-url="topology.php"><?= json_encode($mapData+['states'=>'assets/maps/india-states.json','tiles'=>null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</section>
