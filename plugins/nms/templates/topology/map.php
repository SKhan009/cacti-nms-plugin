<?php
/** Native-site geographic view; markers are rendered from permission-filtered data. */
?>
<main class="nms-shell nms-map-page">
<div class="nms-map-heading"><h1>Topology map</h1>
<nav class="nms-map-tabs" aria-label="Topology views"><a href="topology.php?tab=discovered">Network view</a><a href="topology.php?tab=map" aria-current="page">Map view</a></nav></div>
<section class="nms-panel nms-map-panel">
<div class="nms-map-toolbar"><label for="nmsMapSite">Site</label><select id="nmsMapSite"><option value="">All located sites</option><?php foreach ($map_data['sites'] as $site) { ?><option value="<?php print (int) $site['id']; ?>"><?php print nms_h($site['name']); ?></option><?php } ?></select><button id="nmsMapFit" type="button">Fit sites</button><a class="nms-map-refresh" href="topology.php?tab=map" aria-label="Refresh map" title="Refresh map"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 7a7 7 0 0 1 11.6-1L20 9M4 15l2.3 3A7 7 0 0 0 17.9 17"/></svg></a></div>
<p id="nmsMapStatus" role="status"><?php print count($map_data['sites']); ?> located sites. <?php print count($map_data['unlocated']); ?> devices without a location.<?php if (!$map_configured) { ?> GeoServer is not configured; site markers remain available.<?php } ?></p>
<div id="nmsSiteMap" aria-label="Map of Cacti sites"></div>

<div class="nms-map-footer">
<?php if ($map_data['unlocated']) { ?><details class="nms-map-unlocated"><summary>Devices without a location (<?php print count($map_data['unlocated']); ?>)</summary><p>Assign a Cacti site with latitude and longitude. The default 0,0 is treated as unset.</p><ul><?php foreach ($map_data['unlocated'] as $device) { ?><li><?php print nms_h($device['name'] . ' — ' . $device['site']); ?></li><?php } ?></ul></details><?php } ?>
</div></section>
<script type="application/json" id="nmsMapData"><?php print json_encode($map_data + ['states' => nms_asset_url('css/nms-map-states.json'), 'tiles' => $map_configured ? 'topology.php?tab=map&map_tile=1' : null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
</main>
