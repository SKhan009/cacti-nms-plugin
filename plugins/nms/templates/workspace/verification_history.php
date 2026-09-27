<?php if(is_realm_allowed(3)) { ?>
<section class="nms-panel"><h2>Candidate verification history</h2>
<p>Latest 50 checks requested by your account for accessible reporting devices. A reply verifies reachability during the check, not physical identity.</p>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Reporter / target</th><th>Status / collector</th><th>Requested / finished</th><th>Result</th></tr></thead><tbody>
<?php foreach($workspace_checks as $check) { ?>
<tr><td><?php print nms_h($check['description'].' / '.$check['target']); ?></td><td><?php print nms_h($check['status'].' / '.$check['poller_id'].($check['cancel_requested']?' · cancellation requested':'')); ?></td><td><?php print nms_h($check['requested_at'].' / '.($check['finished_at']?:'Pending')); ?></td>
<td><?php if(in_array($check['status'],['queued','running'],true)) { ?>
<a href="<?php print nms_h(nms_workspace_url($workspace_section,$workspace_id)); ?>">Refresh verification</a>
<?php if(!$check['cancel_requested']) { ?><form method="post" action="<?php print nms_h(nms_workspace_url($workspace_section,$workspace_id)); ?>"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="cancel_candidate_check"><input type="hidden" name="check_id" value="<?php print (int)$check['id']; ?>"><button type="submit">Cancel verification</button></form><?php } ?>
<?php } elseif($check['status']==='complete') { $result=json_decode($check['result_json'],true)?:[]; ?>
<p><?php print !empty($result['ping']['reachable'])?'ICMP reply received.':'No ICMP reply; filtering or collector permissions may be involved.'; ?></p><p><?php print nms_h($result['identity_note']??'No identity summary.'); ?></p>
<?php if(!empty($result['identity_usable'])) { ?><form method="get" class="nms-workspace-fields">
<input type="hidden" name="tab" value="readings"><input type="hidden" name="section" value="neighbours"><input type="hidden" name="id" value="<?php print (int)$workspace_id; ?>"><input type="hidden" name="onboard_check" value="<?php print (int)$check['id']; ?>">
<label>Device name<input name="onboard_name" maxlength="150" required></label><label>Cacti device template<select name="onboard_template" required><option value="">Select template</option><?php foreach($workspace_templates as $template) { ?><option value="<?php print (int)$template['id']; ?>"><?php print nms_h($template['name']); ?></option><?php } ?></select></label><button type="submit">Review device addition</button></form><?php } ?>
<details><summary>Verification evidence</summary><pre><?php print nms_h(json_encode($result,JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)); ?></pre></details>
<?php } else { ?><p><?php print nms_h($check['error']); ?></p><?php } ?></td></tr>
<?php } if(!$workspace_checks) { ?><tr><td colspan="4">No candidate verification requests in this selection.</td></tr><?php } ?>
</tbody></table></div></section>
<?php } ?>
