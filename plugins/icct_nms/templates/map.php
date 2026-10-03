<?php /** Same local Leaflet / GeoServer map stack as NMS; native Cacti locations. */ ?>
<section class="icct-map-page">
<div class="titlebar"><h1>Dashboard</h1><span>Map Topology</span></div>
<section class="icct-map-panel">
<div class="icct-map-toolbar">
<label class="field"><span class="field-label">Site</span><select id="icctMapSite"><option value="">All located sites</option><?php foreach ($mapData['sites'] as $site): ?><option value="<?= (int)$site['id'] ?>"><?= icct_nms_h($site['name']) ?></option><?php endforeach; ?></select></label>
<button id="icctMapFit" type="button" class="button">Fit sites</button>
<button id="icctMapIndia" type="button" class="button">India overview</button>
<a href="topology.php" class="button" aria-label="Refresh map">↻</a>
<button id="icctMapFullscreen" type="button" class="button" aria-label="Toggle map fullscreen">⛶</button>
<div class="icct-map-counts" aria-label="Device status totals"><span>Total: <?= $mapData['counts']['total'] ?></span><span class="online">Online: <?= $mapData['counts']['online'] ?></span><span class="offline">Offline: <?= $mapData['counts']['offline'] ?></span><span class="other">Other: <?= $mapData['counts']['other'] ?></span></div>
</div>
<p id="icctMapStatus" role="status"><?= count($mapData['sites']) ?> located sites. <?= count($mapData['unlocated']) ?> devices without a location.<?php if (!$mapConfigured): ?> GeoServer is not configured; local boundaries and site markers remain available.<?php endif; ?></p>
<div id="icctSiteMap" aria-label="Map of Cacti sites"></div>
<div class="icct-map-footer"><?php if ($mapData['unlocated']): ?><details><summary>Devices without a location (<?= count($mapData['unlocated']) ?>)</summary><p>Select a site with latitude and longitude in Add/Edit Device. Site coordinates 0,0 are treated as unset.</p><ul><?php foreach ($mapData['unlocated'] as $device): ?><li><a href="device.php?id=<?= $device['id'] ?>"><?= icct_nms_h($device['name']) ?></a> — <?= icct_nms_h($device['site']) ?></li><?php endforeach; ?></ul></details><?php endif; ?></div>
</section>
<script type="application/json" id="icctMapData"><?= json_encode($mapData+['states'=>'assets/maps/india-states.json','tiles'=>$mapConfigured?'topology.php?map_tile=1':null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</section>
