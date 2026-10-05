<section id="device-fcaps" class="device-fcaps" hidden>
<div class="fcaps-tabs" role="tablist" aria-label="FCAPS">
<?php foreach(['fault'=>'Fault','configuration'=>'Configuration','accounting'=>'Accounting','performance'=>'Performance','security'=>'Security'] as $key=>$label): ?>
<button type="button" role="tab" id="fcaps-tab-<?= $key ?>" aria-controls="fcaps-<?= $key ?>" aria-selected="<?= $key==='fault'?'true':'false' ?>" tabindex="<?= $key==='fault'?'0':'-1' ?>"><?= $label ?></button>
<?php endforeach; ?></div>
<section id="fcaps-fault" role="tabpanel" aria-labelledby="fcaps-tab-fault">
<form id="fault-form" method="post">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="faults"><input type="hidden" name="fault_rules" id="fault-rules-value">
<fieldset class="fault-source-options"><legend>Measurement Source <span class="field-info" tabindex="0" role="img" aria-label="Information about Measurement Source" data-tooltip="Choose collected Cacti data or direct SNMP readings. Select a graph data source or SNMP OID from the sidebar."><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span></legend>
<label><input type="radio" name="alarm_source_view" value="rrd" checked> Cacti Data Source</label>
<label><input type="radio" name="alarm_source_view" value="snmp"> SNMP OID</label>
</fieldset>
<div class="fault-workspace">
<aside class="fault-alarm-sidebar" aria-label="Measurement list">
<section id="fault-graph-section"><h3>Graph Templates</h3><div id="fault-alarm-navigation"></div></section>
<section id="fault-oid-section" hidden><h3>OID Details</h3><div id="fault-oid-navigation"></div></section>
</aside>
<div class="fault-alarm-editor"><p id="fault-alarm-empty" hidden>Select a data source or OID to configure thresholds.</p><div id="fault-rule-list"></div></div>
</div>
<div class="fcaps-form-actions"><button type="button" class="button" id="add-fault-rule">+ Add Threshold</button><button type="submit" class="button primary">Save</button></div>
</form>
<?php $faultCatalogue=icct_nms_fault_catalogue(); $faultRules=icct_nms_fault_rules($id); $faultObservations=$id?icct_nms_fault_observations($old):[]; ?>
<script type="application/json" id="fault-current-readings"><?= json_encode($faultObservations,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</section>
<section id="fcaps-configuration" role="tabpanel" aria-labelledby="fcaps-tab-configuration" hidden>
<h3>Configuration summary</h3>
<p>Read-only summary of the current form values, including unsaved changes. Edit settings in Basic Information and Protocol Config. Changes are saved for this device only.</p>
<dl id="configuration-device-summary" class="configuration-summary"></dl>
<h3>Selected protocols</h3>
<div id="configuration-protocol-summary"></div>
<h3>NMS settings backups and change history</h3>
<p>Backups contain saved device settings, protocol parameters, graph associations and fault rules. Credentials are excluded. These are NMS settings snapshots, not configurations retrieved from the device.</p>
<p><?= $id ? 'A snapshot is recorded automatically after settings are saved. You can also create a manual backup of saved settings.' : 'Backup and change history are enabled. Save this device to create its first snapshot.' ?></p>
<button type="button" class="button primary" id="configuration-backup" <?= $id ? '' : 'disabled' ?>>Back Up Saved Settings</button>
<p id="configuration-backup-result" role="status"></p>
<?php $configurationHistory=icct_nms_configuration_history($id); ?>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Changes / Backup</th></tr></thead><tbody id="configuration-history-rows">
<?php foreach($configurationHistory as $event): ?>
<tr><td><?= icct_nms_h($event['time']) ?></td><td><?= icct_nms_h($event['user'] ?: 'User '.$event['user_id']) ?></td><td><?= icct_nms_h($event['action']) ?></td><td>
<button type="button" class="button configuration-download" data-backup-id="<?= icct_nms_h($event['id']) ?>">Download Backup</button>
<details><summary><?= count($event['changes']) ?> changed settings</summary><table class="site-table"><thead><tr><th>Setting</th><th>Before</th><th>After</th></tr></thead><tbody><?php foreach($event['changes'] as $field=>$change): ?><tr><td><?= icct_nms_h($field) ?></td><td><?= icct_nms_h($change['before'] ?? 'Not set') ?></td><td><?= icct_nms_h($change['after'] ?? 'Not set') ?></td></tr><?php endforeach; ?></tbody></table></details>
</td></tr>
<?php endforeach; if(!$configurationHistory): ?><tr><td colspan="4"><?= $id ? 'No snapshots yet. Create a backup or save changed settings to start history.' : 'History appears after this device is saved.' ?></td></tr><?php endif; ?>
</tbody></table></div>
<p>Showing the latest 100 snapshots. Earlier snapshots remain stored. Changes to excluded credentials are not included in this history.</p>
<script type="application/json" id="configuration-backups"><?= json_encode($configurationHistory,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</section>
<section id="fcaps-accounting" role="tabpanel" aria-labelledby="fcaps-tab-accounting" hidden><h3>Accounting</h3><p>Accounting policies and usage reports are not configured for this device yet.</p></section>
<section id="fcaps-performance" role="tabpanel" aria-labelledby="fcaps-tab-performance" hidden><h3>Performance</h3><p>Selected graph templates provide performance measurements. View graph definitions in Graphs and interface status in Port Config.</p><ul><?php foreach($graphAssociations as $template): ?><li><?= icct_nms_h($template['name']) ?></li><?php endforeach; ?></ul></section>
<section id="fcaps-security" role="tabpanel" aria-labelledby="fcaps-tab-security" hidden><h3>Security</h3><p>Security monitoring policies are not configured yet. Manage device credentials and enabled protocols in Protocol Config.</p></section>
<script type="application/json" id="fault-catalogue"><?= json_encode($faultCatalogue,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script type="application/json" id="saved-fault-rules"><?= json_encode($faultRules,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
</section>

<script type="application/json" id="fault-parameters"><?= json_encode(icct_nms_fault_parameters($id),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
