<?php /** Native network status; configuration and scanning are managed in Cacti Automation. */ ?>
<section class="nms-panel"><h2>Networks &amp; IP ranges</h2>
<?php if(!$can_networks) { ?><p>Your account needs Cacti Automation permission to manage networks.</p><?php } else { ?>
<p>Manage networks, IP ranges and discovery schedules in Cacti Automation → Networks.</p>
<p><a href="<?php print nms_h($config['url_path'] . 'automation_networks.php'); ?>">Manage networks in Cacti</a></p>
<?php if(isset($_GET['saved'])) { ?><p role="status">Request saved. Refresh to see collector progress.</p><?php } ?>
<div class="nms-table-wrap nms-workspace-fit-table"><table class="nms-table"><thead><tr><th>Network / range</th><th>Native scan status</th><th>Last started / runtime</th><th>Up / SNMP hosts</th><th>Actions</th></tr></thead><tbody>
<?php foreach($workspace_networks as $network) { ?><tr><td><?php print nms_h($network['name']); ?><br><?php print nms_h($network['subnet_range']); ?></td><td><?php print nms_h(($network['enabled']==='on'?'Enabled':'Disabled').' · '.($network['active_processes']?'Native scan running':($network['last_status'] ?: 'Idle'))); ?></td><td><?php print nms_h($network['last_started'].' / '.round((float)$network['last_runtime'],2).' s'); ?></td><td><?php print (int)$network['up_hosts'].' / '.(int)$network['snmp_hosts']; ?></td><td><div class="nms-network-actions">
<a href="<?php print nms_h($config['url_path'].'automation_networks.php?action=edit&id='.(int)$network['id']); ?>">Edit</a>
<form method="post" action="<?php print nms_h(nms_workspace_url('networks',$workspace_id)); ?>">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
<input type="hidden" name="workspace_action" value="start_native_scan">
<input type="hidden" name="network_id" value="<?php print (int)$network['id']; ?>">
<input type="hidden" name="revision" value="<?php print nms_h(nms_workspace_network_revision($network)); ?>">
<button type="submit" <?php print $network['enabled']!=='on'?'disabled':''; ?>>Start native scan</button>
</form></div></td></tr><?php } if(!$workspace_networks) { ?><tr><td colspan="5">No Cacti networks configured.</td></tr><?php } ?></tbody></table></div>
<?php } ?></section>
<?php require __DIR__.'/scan_history.php'; ?>
