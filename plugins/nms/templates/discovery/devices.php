<?php
/** Device assignment list uses permitted core devices already loaded by the NMS controller. */
$presets = db_fetch_assoc("SELECT id,name,protocol FROM plugin_nms_discovery_presets ORDER BY name");
$assignments = array_column(
	db_fetch_assoc("SELECT host_id,preset_id FROM plugin_nms_discovery_devices"),
	"preset_id",
	"host_id",
);
?>
<section class="nms-panel"><div class="nms-panel-head"><div><h2>Device discovery assignments</h2><p>Select a protocol preset and save. Test discovery uses the saved selection and runs on the next native poll cycle.</p></div></div>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Device</th><th>Address</th><th>Protocol preset</th><th>Action</th></tr></thead><tbody>
<?php
$device_rows = 0;
foreach ($devices as $d) {

	if ($d["disabled"] !== "") {
		continue;
	}
	$device_rows++;
	$form_id = "nms-discovery-device-" . (int) $d["id"];
	?>
<tr><td><?php print nms_h($d["description"]); ?></td><td><?php print nms_h($d["hostname"]); ?></td><td>
<form id="<?php print $form_id; ?>" method="post" action="<?php print nms_h(
	$base_url,
); ?>"><?php nms_topology_form_fields(
	"discovery_assignment",
); ?><input type="hidden" name="host_id" value="<?php print (int) $d["id"]; ?>">
<select name="preset_id" aria-label="Discovery preset for <?php print nms_h(
	$d["description"],
); ?>"><option value="0">Not assigned</option><?php foreach ($presets as $p) { ?><option value="<?php print (int) $p[
	"id"
]; ?>" <?php if ((int) ($assignments[$d["id"]] ?? 0) === (int) $p["id"]) {
	print "selected";
} ?>><?php print nms_h(
	strtoupper($p["protocol"]) . " · " . $p["name"],
); ?></option><?php } ?></select></form></td><td><button type="submit" form="<?php print $form_id; ?>">Save</button><form method="post" action="<?php print nms_h(
	$base_url,
); ?>"><?php nms_topology_form_fields(
	"discovery_test",
); ?><input type="hidden" name="host_id" value="<?php print (int) $d["id"]; ?>"><button type="submit" <?php if (
	empty($assignments[$d["id"]])
) {
	print "disabled";
} ?>>Test discovery</button></form></td></tr>
<?php
}
if (!$device_rows) { ?><tr><td colspan="4">No enabled devices are available at this site.</td></tr><?php }
?>
</tbody></table></div></section>
