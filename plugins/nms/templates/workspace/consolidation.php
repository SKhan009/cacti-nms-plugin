<?php if($workspace_consolidation) { $plan=$workspace_consolidation; ?>
<section class="nms-panel"><h2>Review consolidation impact</h2><p>Preferred device: #<?php print (int)$plan['keep_id']; ?> · Other record: #<?php print (int)$plan['other_id']; ?>. Saving this assessment records your review; migration is still required.</p>
<?php foreach($plan['limitations'] as $limitation) { ?><p><?php print nms_h($limitation); ?></p><?php } ?>
<h3>Native asset-transfer preflight</h3>
<p>These checks identify structural blockers. Passing them does not verify permissions, RRD history or complete migration.</p>
<?php if($plan['transfer_preflight']['blockers']) { ?><ul><?php foreach($plan['transfer_preflight']['blockers'] as $blocker) { ?><li><?php print nms_h($blocker); ?></li><?php } ?></ul><?php } else { ?><p>No structural blocker found for the local regular-graph transfer path.</p><?php } ?>
<ul><?php foreach($plan['transfer_preflight']['remaining_checks'] as $check) { ?><li><?php print nms_h($check); ?></li><?php } ?></ul>
<h3>Permission preservation</h3>
<p><?php print nms_h($plan['permission_preflight']['scope']); ?></p>
<?php if($plan['permission_preflight']['blockers']) { ?><ul><?php foreach($plan['permission_preflight']['blockers'] as $blocker) { ?><li><?php print nms_h($blocker); ?></li><?php } ?></ul><?php } else { ?><p>Native device permission inputs are equivalent. They must be checked again immediately before and after transfer.</p><?php } ?>
<div class="nms-workspace-fields"><?php foreach($plan['devices'] as $device) { ?><article class="nms-workspace-finding"><h3><?php print nms_h($device['host']['description']); ?></h3><p><?php print nms_h($device['host']['hostname'].' · collector '.$device['host']['poller_id'].' · device #'.$device['host']['id']); ?></p><p><?php print count($device['graphs']); ?> graphs · <?php print count($device['data_sources']); ?> data-source metadata rows</p>
<details><summary>Graphs and RRD path metadata</summary><pre><?php print nms_h(json_encode(['graphs'=>$device['graphs'],'data_sources'=>$device['data_sources']],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)); ?></pre></details>
<details><summary>Reference counts and permission fingerprints</summary><pre><?php print nms_h(json_encode(['references'=>$device['references'],'permissions'=>$device['permissions']],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)); ?></pre></details></article><?php } ?></div>
<form method="post" action="<?php print nms_h(nms_workspace_url('duplicates',$workspace_id)); ?>"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="save_consolidation"><input type="hidden" name="keep_id" value="<?php print (int)$plan['keep_id']; ?>"><input type="hidden" name="other_id" value="<?php print (int)$plan['other_id']; ?>"><input type="hidden" name="revision" value="<?php print nms_h($plan['revision']); ?>"><label>Migration review note <input name="note" maxlength="1024"></label><button type="submit">Save consolidation assessment</button></form>
<h3>Transfer reviewed assets</h3>
<p>This transfers ordinary local graphs and data sources to the preferred record. Both device records remain disabled. Other device references remain attached to their existing record; review the dependency inventory and external integrations before proceeding. Device retirement is separate.</p>
<p>Both records must remain visible to your Cacti account while disabled. If they disappear, turn off Hide disabled devices in your Cacti preferences before reviewing the pair again.</p>
<?php if($plan['transfer_preflight']['status']==='structurally_eligible' && $plan['permission_preflight']['status']==='native_inputs_equivalent') { ?>
<form method="post" action="<?php print nms_h(nms_workspace_url('duplicates',$workspace_id)); ?>">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="transfer_consolidation">
<input type="hidden" name="keep_id" value="<?php print (int)$plan['keep_id']; ?>"><input type="hidden" name="other_id" value="<?php print (int)$plan['other_id']; ?>"><input type="hidden" name="revision" value="<?php print nms_h($plan['revision']); ?>">
<p><label><input type="checkbox" name="confirm_references" value="yes" required> I reviewed the references that remain on each record and any external permission integrations.</label></p>
<p><label><input type="checkbox" name="confirm_transfer" value="yes" required> Transfer the listed graphs and data sources to the preferred device. Keep both device records.</label></p>
<button type="submit">Request reviewed transfer</button><p>The collector verifies RRD files before applying changes. If a native step is interrupted, recovery review is required; the transfer is never repeated automatically.</p>
</form><?php } else { ?><p>Resolve the blockers above and rebuild this review to request a transfer.</p><?php } ?>
</section>
<?php } ?>
<section class="nms-panel"><h2>Consolidation review history</h2><p>Saved assessments record the dependency review only. Check Consolidation transfers for execution and verification results. An assessment alone does not mean assets were transferred.</p>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Preferred / other record</th><th>Reviewer / time</th><th>Note / status</th></tr></thead><tbody>
<?php foreach($workspace_consolidation_history as $item) { ?><tr><td><?php print nms_h($item['keep_name'].' / '.$item['other_name']); ?></td><td><?php print nms_h('#'.$item['user_id'].' · '.$item['created_at']); ?></td><td>Assessment saved<p><?php print nms_h($item['note']); ?></p></td></tr><?php } if(!$workspace_consolidation_history) { ?><tr><td colspan="3">No consolidation assessments in this selection.</td></tr><?php } ?>
</tbody></table></div></section>

<section class="nms-panel"><h2>Consolidation transfers</h2>
<p>These are asset transfers, not device deletion. Recovery recheck only reads current state; it does not repeat or undo native changes.</p>
<a href="<?php print nms_h(nms_workspace_url($workspace_section,$workspace_id)); ?>">Refresh transfers</a>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Preferred / source</th><th>Status / progress</th><th>Requested / finished</th><th>Result / action</th></tr></thead><tbody>
<?php foreach($workspace_consolidation_jobs as $transfer) { $progress=json_decode($transfer['progress_json'],true)?:[];$result=json_decode($transfer['result_json'],true)?:[]; ?>
<tr><td><?php print nms_h($transfer['keep_name'].' / '.$transfer['other_name']); ?><p>#<?php print (int)$transfer['id']; ?></p></td>
<td><?php print nms_h(str_replace('_',' ',$transfer['status'])); ?><p><?php print count($progress['completed_graphs']??[]); ?> graphs processed</p></td>
<td><?php print nms_h($transfer['requested_at'].' / '.($transfer['finished_at']?:'Not finished')); ?></td>
<td><?php if($transfer['error']!=='') { ?><p><?php print nms_h($transfer['error']); ?></p><?php } ?>
<?php if(!empty($result['device_records_retained'])) { ?><p>Both records retained. <?php print (int)($result['rrd_files_verified']??0); ?> RRD files verified. Layout: <?php print nms_h($result['layout']??'transferred'); ?>.</p><?php } ?>
<?php if(in_array($transfer['status'],['queued','review_required'],true)) { ?><form method="post" action="<?php print nms_h(nms_workspace_url($workspace_section,$workspace_id)); ?>">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="consolidation_job_id" value="<?php print (int)$transfer['id']; ?>">
<input type="hidden" name="workspace_action" value="<?php print $transfer['status']==='queued'?'cancel_consolidation':'recheck_consolidation'; ?>"><button type="submit"><?php print $transfer['status']==='queued'?'Cancel transfer':'Recheck recovery'; ?></button></form><?php } ?>
<?php if($transfer['status']==='review_required') { ?><p>Inspect graph/data ownership, retained references, permissions and RRD files. After manual repair, recheck. A partial or unverifiable layout stays blocked.</p><?php } ?></td></tr>
<?php } if(!$workspace_consolidation_jobs) { ?><tr><td colspan="4">No transfer requests in this selection.</td></tr><?php } ?></tbody></table></div></section>
