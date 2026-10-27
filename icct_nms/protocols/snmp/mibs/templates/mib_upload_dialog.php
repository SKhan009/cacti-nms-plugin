<dialog id="mibUploadDialog" class="mib-upload-dialog" aria-labelledby="mibUploadTitle">
<header class="mib-upload-heading"><h2 id="mibUploadTitle">Upload MIB</h2><button type="button" data-mib-upload-close aria-label="Close MIB upload">×</button></header>
<div class="mib-upload-body">
<div class="mib-upload-notice" id="mibUploadIntro"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 5.5v.5"/></svg><p>The application scans the MIB to identify OIDs, check Data Source eligibility, and flag existing Cacti configurations.</p></div>
<?php if(!empty($uploadChooser)): ?>
<form method="post" action="protocols/snmp/mibs/controllers/mib_repository.php" enctype="multipart/form-data" data-mib-upload-form id="mibUploadChooser">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="upload">

<label class="field"><span class="field-label">MIB File *</span><input type="file" name="mibs[]" accept=".mib,.my,.txt" required></label>
<p class="mib-upload-file-help">.mib, .my or .txt · Maximum 1 MiB</p>
<div class="mib-upload-actions"><button class="button" type="button" data-mib-upload-close>Cancel</button><button class="button primary" type="submit">Upload</button></div>
</form>
<?php endif; ?>
<section id="mibUploadProgress" hidden aria-label="MIB upload progress"><p>Please wait till the MIB file uploads....</p><p id="mibUploadFilename"></p><progress id="mibUploadBar" max="100" aria-label="Upload and scan progress"></progress><p id="mibUploadCount" role="status" aria-live="polite"></p></section>
<section id="mibUploadNext" hidden aria-label="Choose next step">
<table class="mib-upload-summary" aria-label="Uploaded MIB summary"><thead><tr><th>MIB Name</th><th>No. of OIDs</th><th>Supports Data Source</th><th>Existing Data Source</th></tr></thead><tbody><tr><td data-upload-summary="name"></td><td data-upload-summary="object_count"></td><td data-upload-summary="supported_count"></td><td data-upload-summary="existing_count"></td></tr></tbody></table>
<p>The MIB file is ready. How would you like to proceed?</p>
<form id="mibUploadNextForm">
<label class="mib-upload-choice"><input type="radio" name="upload_next" value="automatic" checked><span><strong>Recommended - Option 1: Configure Automatically</strong><small>Use the MIB/OID definitions to initiate the automated creation of the required Data Sources, Graphs, and Device Template associations for monitoring.</small></span><div class="mib-upload-notice mib-upload-duplicate-note"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 5.5v.5"/></svg><p>Existing Data Sources will not be duplicated. You can modify their settings after adding them to a device.</p></div></label>
<label class="mib-upload-choice"><input type="radio" name="upload_next" value="repository"><span><strong>Option 2: Add to Repository Only</strong><small>Save the MIB file to the repository for future use. You can initiate the monitoring configuration process later.</small></span></label>
<button type="submit" class="button primary" id="mibUploadProceed">Proceed</button>
</form></section>
<p id="mibUploadError" class="notice error" role="alert" hidden></p>
</div></dialog>
