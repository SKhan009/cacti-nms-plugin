<section id="device-fcaps" class="device-fcaps" hidden>
<div class="fcaps-tabs" role="tablist" aria-label="FCAPS">
<?php foreach(['fault'=>'Fault','configuration'=>'Configuration','accounting'=>'Accounting','performance'=>'Performance','security'=>'Security'] as $key=>$label): ?>
<button type="button" role="tab" id="fcaps-tab-<?= $key ?>" aria-controls="fcaps-<?= $key ?>" aria-selected="<?= $key==='fault'?'true':'false' ?>" tabindex="<?= $key==='fault'?'0':'-1' ?>"><?= $label ?></button>
<?php endforeach; ?></div>
<section id="fcaps-fault" role="tabpanel" aria-labelledby="fcaps-tab-fault">
<h3>Fault thresholds</h3>
<p>Select an associated graph template and data source. A value below Minimum or above Maximum raises the selected severity; values equal to a threshold stay Normal. Add multiple rules for different severity levels.</p>
<p>Thresholds use the stored data source’s AVERAGE values before graph CDEF/VDEF transformations. Template data limits populate new rules where defined; review them before saving. Blank means no bound.</p>
<form id="fault-form" method="post">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="faults"><input type="hidden" name="fault_rules" id="fault-rules-value">
<div id="fault-rule-list"></div>
<button type="button" class="button" id="add-fault-rule">+ Add Fault Rule</button>
<div class="fcaps-form-actions"><button type="submit" class="button primary">Save</button></div>
</form>
<h3>Current faults</h3>
<p>Evaluated automatically by the assigned Cacti poller after Save. Missing or stale measurements are Unknown. No notification delivery is configured here.</p>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Graph</th><th>Data Source</th><th>Value</th><th>Severity / State</th><th>Sample Time</th></tr></thead><tbody>
<?php $faultCatalogue=icct_nms_fault_catalogue(); $faultRules=icct_nms_fault_rules($id); $faultObservations=$id?icct_nms_fault_observations($old):[]; foreach($faultObservations as $observation): $rule=$faultRules[$observation['rule']] ?? []; $metricLabel='';foreach($faultCatalogue as $metric)if((int)$metric['metric_id']===(int)($rule['metric_id'] ?? 0))$metricLabel=$metric['data_source_name']; ?>
<tr><td><?= icct_nms_h($observation['graph']) ?></td><td><?= icct_nms_h($metricLabel) ?></td><td><?= icct_nms_h($observation['value'] ?? 'Unknown') ?></td><td><?= icct_nms_h($observation['state']) ?></td><td><?= $observation['sample_time']?icct_nms_h(date('Y-m-d H:i:s',$observation['sample_time'])):'—' ?></td></tr>
<?php endforeach; if(!$faultObservations): ?><tr><td colspan="5">No current fault observations. Save rules and wait for the assigned poller.</td></tr><?php endif; ?>
</tbody></table></div>
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
