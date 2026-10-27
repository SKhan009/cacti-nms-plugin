<dialog id="mibUploadDialog" class="mib-upload-dialog" aria-labelledby="mibUploadTitle">
<header class="mib-upload-heading"><h2 id="mibUploadTitle">Upload MIB</h2><button type="button" data-mib-upload-close aria-label="Close MIB upload">×</button></header>
<div class="mib-upload-body">
<div class="mib-upload-notice"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 5.5v.5"/></svg><p>The application scans the MIB to identify OIDs, check Data Source eligibility, and flag existing Cacti configurations.</p></div>
<?php if(!empty($uploadChooser)): ?>
<form method="post" action="protocols/snmp/mibs/controllers/mib_repository.php" enctype="multipart/form-data" data-mib-upload-form id="mibUploadChooser">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="upload">
<label class="field"><span class="field-label">Device Type *</span><select name="type_id" required><option value="">Select device type</option><?php foreach($types as $key=>$type): ?><option value="<?= icct_nms_h($key) ?>"><?= icct_nms_h($type['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">MIB Files *</span><input type="file" name="mibs[]" accept=".mib,.my,.txt" multiple required></label>
<div class="mib-upload-actions"><button class="button" type="button" data-mib-upload-close>Cancel</button><button class="button primary" type="submit">Upload</button></div>
</form>
<?php endif; ?>
<section id="mibUploadProgress" hidden aria-label="MIB upload progress"><p>Please wait till the MIB file uploads....</p><p id="mibUploadFilename"></p><progress id="mibUploadBar" max="100" aria-label="Upload and scan progress"></progress><p id="mibUploadCount" role="status" aria-live="polite"></p></section>
<p id="mibUploadError" class="notice error" role="alert" hidden></p>
</div></dialog>
