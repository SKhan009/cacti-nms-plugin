<?php if(is_realm_allowed(3)) { if($workspace_onboarding_plan) { $plan=$workspace_onboarding_plan; ?>
<section class="nms-panel"><h2>Review device addition</h2>
<dl><dt>Device</dt><dd><?php print nms_h($plan['description'].' · '.$plan['target']); ?></dd><dt>Cacti template</dt><dd><?php print nms_h($plan['template_name']); ?></dd><dt>Collector / site</dt><dd><?php print (int)$plan['poller_id'].' / '.(int)$plan['site_id']; ?></dd><dt>Verified</dt><dd><?php print nms_h($plan['verified_at']); ?></dd></dl>
<p>This creates a new monitored Cacti device using the reporting device's SNMP and availability settings. Cacti applies the selected template and its normal automation. The collector rechecks this review before creation.</p>
<details><summary>Verified identity evidence</summary><pre><?php print nms_h(json_encode($plan['identity_evidence'],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)); ?></pre></details>
<p><?php print nms_h($plan['admission']['message']); ?></p>
<?php foreach($plan['admission']['matches'] as $match) { ?><p><a href="<?php print nms_h(nms_workspace_url('identity',$match['host_id'])); ?>">Review existing device</a> <?php print nms_h($match['state'].' — '.$match['reason']); ?></p><?php } ?>
<?php if(!$plan['admission']['blocked']) { ?><form method="post" action="<?php print nms_h(nms_workspace_url('neighbours',$workspace_id)); ?>"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="confirm_onboarding">
<?php foreach(['check_id','template_id','description','revision'] as $field) { ?><input type="hidden" name="<?php print $field; ?>" value="<?php print nms_h($plan[$field]); ?>"><?php } ?>
<button type="submit">Confirm and add device</button></form><?php } ?></section>
<?php } ?>
<section class="nms-panel"><h2>Reviewed onboarding history</h2><p>Latest 50 requests from your account. Interrupted native creation requires review; it is never automatically repeated.</p>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Device / reporter</th><th>Status</th><th>Requested / finished</th><th>Action</th></tr></thead><tbody>
<?php foreach($workspace_onboarding as $job) { ?><tr><td><?php print nms_h($job['description'].' · '.$job['target']); ?><p><?php print nms_h($job['reporter_name']); ?></p></td><td><?php print nms_h($job['status']); ?><p><?php print nms_h($job['error']); ?></p></td><td><?php print nms_h($job['requested_at'].' / '.($job['finished_at']?:'Pending')); ?></td><td>
<?php if($job['host_id'] && is_device_allowed((int)$job['host_id'])) { ?><a href="<?php print nms_h(nms_workspace_url('overview',$job['host_id'])); ?>">View device</a><?php } ?>
<?php if($job['status']==='queued') { ?><form method="post" action="<?php print nms_h(nms_workspace_url($workspace_section,$workspace_id)); ?>"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="cancel_onboarding"><input type="hidden" name="request_id" value="<?php print (int)$job['id']; ?>"><button type="submit">Cancel addition</button></form><?php } ?></td></tr><?php } if(!$workspace_onboarding) { ?><tr><td colspan="4">No reviewed onboarding requests in this selection.</td></tr><?php } ?>
</tbody></table></div></section><?php } ?>
