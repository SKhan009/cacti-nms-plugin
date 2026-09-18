<?php
if (!is_realm_allowed(3)) {
	return;
}
$mh = db_fetch_assoc(
	"SELECT h.id,h.description FROM host h WHERE h.deleted='' AND h.disabled='' AND " .
		nms_visible_host_sql() .
		" ORDER BY h.description",
);
$selected_mib_host = (int) ($_SESSION["nms_mib_preview"]["host_id"] ?? 0);
$mp = $_SESSION["nms_mib_preview"] ?? null;
if ($mp && !is_device_allowed((int) $mp["host_id"])) {
	$mp = null;
	unset($_SESSION["nms_mib_preview"]);
}
?>

<?php $mib_uploads = db_fetch_assoc(
	"SELECT u.*,h.description FROM plugin_nms_mib_uploads u JOIN host h ON h.id=u.host_id WHERE h.deleted='' AND " .
		nms_visible_host_sql() .
		" ORDER BY u.id DESC",
); ?>
<section class="nms-panel nms-mib-panel"><div class="nms-panel-head"><h2>Uploaded MIB files</h2></div>
<p>Successful import history. Filenames and module names are retained; original MIB contents are not archived. Older imports remain in the core object report below and may have no recorded filename.</p>
<div style="max-width:100%;overflow-x:auto"><table class="nms-table" style="width:100%;min-width:0;table-layout:fixed"><thead><tr><th>Files</th><th>Modules</th><th>Cacti device</th><th>Metrics</th><th>Imported</th></tr></thead><tbody>
<?php
$shown = 0;
foreach ($mib_uploads as $upload) {

	if (!is_device_allowed((int) $upload["host_id"])) {
		continue;
	}
	$shown++;
	?>
<tr><td style="overflow-wrap:anywhere"><?php print nms_h(
	implode(", ", json_decode($upload["files_json"], true) ?: ["Filename unavailable"]),
); ?></td><td style="overflow-wrap:anywhere"><?php print nms_h(
	implode(", ", json_decode($upload["modules_json"], true) ?: []),
); ?></td><td style="overflow-wrap:anywhere"><a href="devices.php?tab=edit&amp;id=<?php print (int) $upload[
	"host_id"
]; ?>"><?php print nms_h($upload["description"]); ?></a></td><td><?php print (int) $upload[
	"metric_count"
]; ?></td><td><?php print nms_h($upload["created_at"]); ?></td></tr>
<?php
}
if (!$shown) { ?><tr><td colspan="5">No successful MIB uploads recorded since file history was enabled.</td></tr><?php }
?>
</tbody></table></div></section>

<details class="nms-panel" <?php if ($mp || !empty($page_error)) {
	print "open";
} ?>><summary style="padding:20px;cursor:pointer;font-weight:600">Upload MIB definitions</summary><section class="nms-panel nms-mib-panel"><div class="nms-panel-head"><h2>Upload MIB definitions</h2></div>
<p>Use existing Cacti device SNMP settings. Upload the vendor MIB and its dependencies. Preview validates numeric instances before creating native data templates, data sources, graph templates and graphs. No simulator is created.</p>
<form method="post" enctype="multipart/form-data" action="file_repository.php">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h(
	$nms_csrf_token,
); ?>"><input type="hidden" name="nms_action" value="mib_preview">
<label><span>Cacti device</span><select name="mib_host_id" required><?php foreach (
	$mh
	as $h
) { ?><option value="<?php print (int) $h["id"]; ?>"<?php if ((int) $h["id"] === $selected_mib_host) {
	print " selected";
} ?>><?php print nms_h($h["description"]); ?></option><?php } ?></select></label>
<label><span>MIB files (.mib / .txt, dependencies included)</span><input type="file" name="mib_files[]" accept=".mib,.txt" multiple required></label><button type="submit">Validate MIB and test device</button></form>
<p>Requires Net-SNMP snmptranslate and PHP SNMP on the assigned local collector. Up to 8 files, 128 definitions and 64 numeric instances. Counter values are graphed as rates; no unit scaling is inferred.</p>
<?php if ($mp && time() - $mp["created"] < 900) { ?>
<h3>Preview — no Cacti objects created yet</h3><p>Target device: <strong><?php print nms_h(
	db_fetch_cell_prepared("SELECT description FROM host WHERE id=?", [(int) $mp["host_id"]]),
); ?></strong> (#<?php print (int) $mp[
	"host_id"
]; ?>).</p><form method="post" action="file_repository.php"><input type="hidden" name="__csrf_magic" value="<?php print nms_h(
	$nms_csrf_token,
); ?>"><input type="hidden" name="nms_action" value="mib_create"><input type="hidden" name="mib_token" value="<?php print nms_h(
	$mp["token"],
); ?>">
<table class="nms-table"><thead><tr><th>Select</th><th>Metric</th><th>OID instance</th><th>Units</th><th>Graph behaviour</th></tr></thead><tbody>
<?php foreach (
	$mp["records"]
	as $i => $r
) { ?><tr><td><input type="checkbox" name="metrics[]" value="<?php print $i; ?>" checked></td><td><?php print nms_h(
	$r["section"],
); ?></td><td><?php print nms_h($r["oid"]); ?></td><td><?php print nms_h(
	$r["units"] ?: "Unspecified / raw",
); ?></td><td><?php print in_array($r["type"], [65, 70], true)
	? "Counter rate per second"
	: "Gauge (raw value)"; ?></td></tr><?php } ?></tbody></table>
<p>Each selected numeric instance receives its own graph template and graph. Separate units are not combined on one misleading scale.</p><button type="submit">Create selected Cacti objects</button></form>
<?php if ($mp["skipped"]) { ?><details><summary>Skipped definitions</summary><?php foreach ($mp["skipped"] as $skip) {
	print "<p>" . nms_h($skip) . "</p>";
} ?></details><?php }} ?>
</section></details>

<?php
$mib_rows = [];
foreach (
	db_fetch_assoc(
		'SELECT m.host_id,m.report_json FROM plugin_nms_mib_objects m JOIN host h ON h.id=m.host_id WHERE h.deleted=\'\' AND ' .
			nms_visible_host_sql(),
	)
	as $r
) {
	if (is_device_allowed((int) $r["host_id"])) {
		$mib_rows[] = json_decode($r["report_json"], true);
	}
}
if ($mib_rows) {
	print '<section class="nms-panel"><div class="nms-panel-head"><h2>MIB objects linked to Cacti core</h2></div>';
	nms_import_object_report($mib_rows);
	print "</section>";
}


?>
