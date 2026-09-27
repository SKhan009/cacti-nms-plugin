<?php
if (!is_realm_allowed(3)) {
	return;
}
$mp = $_SESSION["nms_mib_preview"] ?? null;
if ($mp && !empty($mp["host_id"]) && !is_device_allowed((int) $mp["host_id"])) {
	$mp = null;
	unset($_SESSION["nms_mib_preview"]);
}
?>

<div class="nms-mib-toolbar"><button type="button" onclick="document.getElementById('nmsMibUpload').showModal()">Upload MIB</button></div>
<?php if(isset($_GET['prepared'])) { ?><p role="status">Templates prepared. You can now add a device.</p><?php } ?>
<?php $bundles=nms_mib_template_bundles(); if($bundles) { ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Prepared device templates</h2></div><div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Device template</th><th>MIB files</th><th>Metrics</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($bundles as $bundle) { $ready=(bool)db_fetch_cell_prepared('SELECT id FROM host_template WHERE id=?',[$bundle['host_template_id']]); foreach($bundle['rows'] as $row)if(!db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id=?',[$row['graph_template_id']]) || !db_fetch_cell_prepared('SELECT id FROM data_template WHERE id=?',[$row['data_template_id']]))$ready=false; ?>
<tr><td><?php print nms_h($bundle['name']); ?></td><td><?php print nms_h(implode(', ',$bundle['files'])); ?></td><td><?php print count($bundle['rows']); ?></td><td><?php print $ready?'Templates ready':'Templates incomplete'; ?></td><td><?php if($ready) { ?><a class="nms-panel-action" href="devices.php?tab=add&amp;host_template_id=<?php print (int)$bundle['host_template_id']; ?>&amp;equipment_category_id=<?php print (int)$bundle['category_id']; ?>">Add device</a><?php } ?></td></tr>
<?php } ?></tbody></table></div></section><?php } ?>
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

<dialog id="nmsMibUpload" class="nms-upload-dialog nms-mib-upload-dialog" aria-labelledby="nmsMibTitle">
<div class="nms-panel-head nms-upload-dialog-head">
<div><h2 id="nmsMibTitle">Upload MIB</h2><p>Prepare reusable templates by device type.</p></div>
<button class="nms-popup-close nms-upload-dialog-close" type="button" onclick="document.getElementById('nmsMibUpload').close()" aria-label="Close MIB upload"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
</div>
<div class="nms-upload-dialog-body nms-mib-upload-body">
<?php if(!empty($page_error)) { ?><p role="alert" class="nms-form-message error"><?php print nms_h($page_error); ?></p><?php } ?>
<div class="nms-import-explainer nms-mib-workflow">
<div><strong>1. Validate MIB</strong><span>Select a device type and upload its definitions.</span></div>
<div><strong>2. Prepare templates</strong><span>Review the supported metrics and create templates.</span></div>
<div><strong>3. Add device</strong><span>Use the prepared template to start collecting readings.</span></div>
</div>
<p>Works offline using Net-SNMP installed on the Cacti server. Include imported vendor MIBs in the same upload. Select 1–8 .mib, .my or .txt files; maximum 1 MB each and 4 MB total.</p>
<form method="post" enctype="multipart/form-data" action="file_repository.php?kind=mib" class="nms-device-form nms-import-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="mib_preview"><input type="hidden" name="mib_host_id" value="0">
<div class="nms-form-grid">
<label><span>Device type</span><select name="mib_category_id" required><option value="">Select device type</option><?php foreach(nms_categories() as $category) { ?><option value="<?php print (int)$category['id']; ?>"><?php print nms_h($category['name']); ?></option><?php } ?></select></label>
<label><span>Device template name</span><input name="mib_template_name" required maxlength="150" placeholder="Vendor / model"></label>
<label class="nms-file-field nms-mib-files"><span>MIB files and dependencies</span><input type="file" name="mib_files[]" accept=".mib,.my,.txt" multiple required></label>
</div><div class="nms-form-actions"><button type="submit">Validate MIB</button></div></form>
<?php if ($mp && empty($mp['host_id']) && time()-$mp['created']<900) { ?>
<h3>Template preview — <?php print nms_h($mp['template_name']); ?></h3>
<form method="post" action="file_repository.php?kind=mib">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="mib_create"><input type="hidden" name="mib_token" value="<?php print nms_h($mp['token']); ?>">
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Select</th><th>Metric</th><th>OID</th><th>Units</th></tr></thead><tbody>
<?php foreach($mp['records'] as $i=>$record) { ?><tr><td><input type="checkbox" name="metrics[]" value="<?php print (int)$i; ?>" checked aria-label="<?php print nms_h('Include '.$record['section']); ?>"></td><td><?php print nms_h($record['section']); ?></td><td><?php print nms_h($record['oid']); ?></td><td><?php print nms_h($record['units']?:'Raw'); ?></td></tr><?php } ?></tbody></table></div>
<div class="nms-form-actions"><button type="submit">Create templates</button></div></form>
<?php if($mp['skipped']) { ?><details><summary>Definitions needing review (<?php print count($mp['skipped']); ?>)</summary><?php foreach($mp['skipped'] as $skip)print '<p>'.nms_h($skip).'</p>'; ?></details><?php } } ?>
</div></dialog>
<script>document.addEventListener('DOMContentLoaded',function(){<?php if($mp || !empty($page_error)) { ?>document.getElementById('nmsMibUpload').showModal();<?php } ?>});</script>

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
