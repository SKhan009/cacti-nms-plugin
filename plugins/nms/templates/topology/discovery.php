<?php
require_once __DIR__ . "/../../includes/topology/discovery.php";
require_once __DIR__ . "/../../includes/discovery_display.php";
$discovery = nms_topology_discovery($site_id);
?>
<section class="nms-panel" id="nms-discovery-results" data-refresh-seconds="<?php print (int) $discovery[
	"refresh"
]; ?>" aria-label="Discovered LLDP and CDP topology">
 <div class="nms-panel-head"><div><h2>Discovered topology · LLDP / CDP</h2><p><?php print nms_h(
 	$discovery["message"],
 ); ?></p></div>
 <a class="nms-button" href="topology.php?tab=configuration&amp;site_id=<?php print (int) $site_id; ?>">Configure NMS presets</a></div>
 <?php if ($discovery["ready"]) {
 	$policy = $discovery["policy"]; ?>
 <p style="padding: 0 20px">Display refresh is controlled by assigned NMS presets. Viewing this page does not start collection.</p>
 <?php if (($config_section ?? "") === "view") {
 	require __DIR__ . "/../discovery/map.php";
 } ?>
 <div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Core device</th><th>Preset / protocol / timing</th><th>Evidence</th><th>Last successful collection</th></tr></thead><tbody>
 <?php
 foreach ($discovery["hosts"] as $id => $host) {
 	foreach (nms_nd_protocols($host["protocol"]) ?: ["none"] as $protocol) {
 		$snapshot = $discovery["snapshots"][$id . "|" . $protocol] ?? null; ?>
 <tr><td><?php print nms_h($host["description"]); ?></td><td><?php print nms_h(
	$host["preset_name"] .
		" · " .
		strtoupper($protocol) .
		" · " .
		($host["enabled"] ? "Scheduled" : "Disabled") .
		" · interval " .
		$host["interval_seconds"] .
		" seconds · stale after " .
		$host["stale_seconds"] .
		" seconds · refresh " .
		$host["refresh_seconds"] .
		" seconds",
); ?></td><td><?php print nms_h(
	nms_topology_discovery_state($snapshot, (int) $host["stale_seconds"], time()) .
		(!empty($snapshot["error"]) ? " · " . $snapshot["error"] : ""),
); ?></td><td><?php print nms_h($snapshot["succeeded_at"] ?? "Never"); ?></td></tr>
 <?php
 	}
 }
 if (
 	!$discovery["hosts"]
 ) { ?><tr><td colspan="4">No permitted devices at this site have NMS discovery assignments. Configure an NMS preset above.</td></tr><?php }
 ?>
 </tbody></table></div>
 <div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Device / local interface</th><th>Neighbour / remote interface</th><th>Protocol evidence</th><th>Connection state</th></tr></thead><tbody>
 <?php
 foreach ($discovery["links"] as $link) { ?><tr><td><?php print nms_h(
	$discovery["hosts"][$link["a"][0]]["description"] . " / ifIndex " . $link["a"][1],
); ?></td><td><?php print nms_h(
	$discovery["hosts"][$link["b"][0]]["description"] . " / ifIndex " . $link["b"][1],
); ?></td><td><?php print nms_h(
	strtoupper(implode(", ", array_keys($link["protocols"]))),
); ?></td><td><?php print nms_h($link["state"]); ?></td></tr><?php }
 if (
 	!$discovery["links"]
 ) { ?><tr><td colspan="4">No resolved connections at this site. Interface availability alone does not establish a neighbour.</td></tr><?php }
 ?>
 </tbody></table></div>

 <?php if (!empty($discovery["unresolved"])) { ?>
 <div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Reporting device</th><th>Observed neighbour / matching result</th></tr></thead><tbody>
 <?php foreach ($discovery["unresolved"] as $o) { ?><tr><td><?php print nms_h(
	$discovery["hosts"][$o["host_id"]]["description"] ?? "Device",
); ?></td><td><?php print nms_h(nms_nd_observation_text($o)); ?></td></tr><?php } ?>
 </tbody></table></div><?php } ?>
 <?php
 } ?>
 <p data-discovery-refresh-status role="status" style="padding: 0 20px"></p>
</section>
<script>
(function(){
 if(window.nmsDiscoveryRefresh)return; window.nmsDiscoveryRefresh=true;
 var busy=false;
 setTimeout(async function refresh(){
  if(document.hidden||busy){setTimeout(refresh,10000);return;}busy=true;
  try {var response=await fetch(location.href,{credentials:'same-origin',cache:'no-store'});
   if(!response.ok||response.redirected)throw new Error('Unavailable');
   var doc=new DOMParser().parseFromString(await response.text(),'text/html');
   var fresh=doc.getElementById('nms-discovery-results'),current=document.getElementById('nms-discovery-results');
   if(!fresh||!current)throw new Error('Unavailable');current.replaceWith(fresh);
  }catch(e){var status=document.querySelector('[data-discovery-refresh-status]');if(status)status.textContent='Refresh failed. Displayed results may be outdated; reload to verify access and collection status.';}
  finally{busy=false;var panel=document.getElementById('nms-discovery-results');setTimeout(refresh,1000*Number(panel?.dataset.refreshSeconds||30));}
 },1000*Number(document.getElementById('nms-discovery-results')?.dataset.refreshSeconds||30));
})();
</script>
