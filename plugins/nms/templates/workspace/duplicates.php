<?php
/** Evidence and human decisions; these do not merge Cacti records. */
$pairs=[];
foreach($workspace_identities as $id=>$identity)foreach($identity['matches'] as $match) {
    [$a,$b]=nms_identity_review_pair($id,$match['host_id']);
    $pairs[$a.':'.$b]=['a'=>$a,'b'=>$b,'state'=>$match['state'],'reason'=>$match['reason']];
}
foreach($workspace_reviews as $key=>$review)if(!isset($pairs[$key]))$pairs[$key]=['a'=>(int)$review['host_a'],'b'=>(int)$review['host_b'],'state'=>'Previously reviewed pair','reason'=>'No matching pair in current discovery evidence. Review the latest device details before changing this decision.'];
$labels=['same'=>'Same device','separate'=>'Separate devices','unresolved'=>'Unresolved'];$shown=0;
?>
<section class="nms-panel"><h2>Duplicate review</h2>
<p>Compare evidence and record a decision. Saving a review does not merge, delete, or change Cacti devices, graphs, or polling addresses.</p>
<p>Native Cacti automatic addition has no safely verified NMS admission hook in this installation. These decisions do not prevent native automatic additions; turn off “Automatically Add to Cacti” when additions require review.</p>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Existing device</th><th>Matching record</th><th>Evidence and review</th></tr></thead><tbody>
<?php foreach($pairs as $key=>$pair) {
if($workspace_id && !in_array($workspace_id,[$pair['a'],$pair['b']],true))continue;
$a=$workspace_discovery['hosts'][$pair['a']]??null;$b=$workspace_discovery['hosts'][$pair['b']]??null;
if(!$a || !$b)continue;$shown++;
$review=$workspace_reviews[$key]??null;
$hash=nms_identity_review_hash($pair['a'],$pair['b'],$workspace_discovery['hosts'],$workspace_identities);
?><tr>
<?php foreach([$a,$b] as $host) { ?><td><a href="<?php print nms_h(nms_workspace_url('identity',$host['id'])); ?>"><?php print nms_h($host['description']); ?></a><p><?php print nms_h($host['hostname']); ?></p><?php if(($host['disabled']??'')==='on') { ?><p>Polling paused. Identity evidence below is saved evidence, not a current reachability check.</p><?php } ?><p>Identity observed: <?php print nms_h($workspace_identities[$host['id']]['checked_at']?:'Not available'); ?></p></td><?php } ?>
<td><strong><?php print nms_h($pair['state']); ?></strong><p><?php print nms_h($pair['reason']); ?></p>
<?php if($review) { ?><p>Saved decision: <strong><?php print nms_h($labels[$review['decision']]??'Unresolved'); ?></strong> · <?php print nms_h($review['updated_at']); ?> · Account #<?php print (int)$review['user_id']; ?></p><p><?php print nms_h($review['note']); ?></p>
<?php if(!hash_equals($hash,$review['evidence_hash'])) { ?><p>Evidence has changed since this decision. Review it again before relying on the saved decision.</p><?php }} ?>
<?php if(is_realm_allowed(3)) { ?><form method="post" action="<?php print nms_h(nms_workspace_url('duplicates',$workspace_id)); ?>">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="review_identity">
<input type="hidden" name="host_a" value="<?php print $pair['a']; ?>"><input type="hidden" name="host_b" value="<?php print $pair['b']; ?>">
<input type="hidden" name="revision" value="<?php print (int)($review['revision']??0); ?>"><input type="hidden" name="evidence_hash" value="<?php print nms_h($hash); ?>">
<label>Decision <select name="decision"><?php foreach($labels as $value=>$label) { ?><option value="<?php print $value; ?>" <?php print ($review['decision']??'unresolved')===$value?'selected':''; ?>><?php print nms_h($label); ?></option><?php } ?></select></label>
<label>Review note <input type="text" name="note" maxlength="512" value="<?php print nms_h($review['note']??''); ?>"></label><button type="submit">Save review decision</button></form><?php } ?>
<?php if(is_realm_allowed(3) && ($review['decision']??'')==='same' && hash_equals($hash,$review['evidence_hash'])) { ?>
<p><a href="<?php print nms_h(nms_workspace_url('duplicates',$workspace_id,['consolidation_keep'=>$pair['a'],'consolidation_other'=>$pair['b']])); ?>">Review consolidation with <?php print nms_h($a['description']); ?> preferred</a></p>
<p><a href="<?php print nms_h(nms_workspace_url('duplicates',$workspace_id,['consolidation_keep'=>$pair['b'],'consolidation_other'=>$pair['a']])); ?>">Review consolidation with <?php print nms_h($b['description']); ?> preferred</a></p>
<?php } ?>
</td></tr><?php } if(!$shown) { ?><tr><td colspan="3">No matching or previously reviewed pairs are available in current visible discovery evidence. This does not certify every device is unique.</td></tr><?php } ?>
</tbody></table></div></section>

<?php if(is_realm_allowed(3))require __DIR__.'/consolidation.php'; ?>
