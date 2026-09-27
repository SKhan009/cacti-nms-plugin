<?php
/** Native SQL against connection-local ACL/inventory copies; persistent access never changes. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';require_once $argv[1].'/plugins/nms/includes/functions.php';require_once $argv[1].'/plugins/nms/includes/workspace/admission.php';
$source=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/consolidation_permissions.php'));eval(substr($source,5));
foreach(['host','user_auth','user_auth_perms','user_auth_group_perms','graph_tree_items'] as $table) {
 $rows=db_fetch_assoc('SELECT * FROM '.$table);$ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',array_values(db_fetch_row('SHOW CREATE TABLE '.$table))[1]);
 if(!db_execute($ddl))throw new RuntimeException('Temporary fixture creation failed');
 foreach($rows as $row){$keys=array_keys($row);nms_category_execute('INSERT INTO '.$table.' (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($row));}
}
$count=0;function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
$hosts=db_fetch_assoc("SELECT id,host_template_id,site_id FROM host WHERE deleted='' ORDER BY id LIMIT 2");if(count($hosts)!==2)throw new RuntimeException('Two fixture hosts required');
$a=(int)$hosts[0]['id'];$b=(int)$hosts[1]['id'];$user=(int)db_fetch_cell('SELECT id FROM user_auth ORDER BY id LIMIT 1');
nms_category_execute("UPDATE host SET disabled='on',host_template_id=?,site_id=? WHERE id IN (?,?)",[$hosts[0]['host_template_id'],$hosts[0]['site_id'],$a,$b]);
foreach(['user_auth_perms','user_auth_group_perms'] as $table)nms_category_execute('DELETE FROM '.$table.' WHERE type=3 AND item_id IN (?,?)',[$a,$b]);
nms_category_execute('DELETE FROM graph_tree_items WHERE host_id IN (?,?)',[$a,$b]);
$baseline=nms_workspace_consolidation_permissions($a,$b);check($baseline['status']==='native_inputs_equivalent','Equal native permission inputs accepted for further checks');
nms_category_execute('INSERT INTO user_auth_perms(user_id,item_id,type) VALUES (?,?,3)',[$user,$b]);
$p=nms_workspace_consolidation_permissions($a,$b);check(isset($p['blockers']['user_rules'])&&$p['fingerprint']!==$baseline['fingerprint'],'Asymmetric user rule blocks and changes fingerprint');
nms_category_execute('INSERT INTO user_auth_perms(user_id,item_id,type) VALUES (?,?,3)',[$user,$a]);
$p=nms_workspace_consolidation_permissions($a,$b);check($p['status']==='native_inputs_equivalent','Matching device exceptions preserve equivalent inputs');
nms_category_execute('INSERT INTO user_auth_group_perms(group_id,item_id,type) VALUES (?,?,3)',[999999,$b]);
check(isset(nms_workspace_consolidation_permissions($a,$b)['blockers']['group_rules']),'Asymmetric group rule blocks');
nms_category_execute('INSERT INTO graph_tree_items(graph_tree_id,host_id) VALUES (?,?)',[999999,$b]);
check(isset(nms_workspace_consolidation_permissions($a,$b)['blockers']['tree_membership']),'Different host tree memberships block');
$before=nms_workspace_consolidation_permissions($a,$b);
nms_category_execute('UPDATE user_auth SET policy_graphs=IF(policy_graphs=1,2,1) WHERE id=?',[$user]);
check(nms_workspace_consolidation_permissions($a,$b)['fingerprint']!==$before['fingerprint'],'Inherited policy change invalidates reviewed fingerprint');
nms_category_execute('UPDATE host SET site_id=999999 WHERE id=?',[$b]);
check(isset(nms_workspace_consolidation_permissions($a,$b)['blockers']['device_context']),'Different device site requires explicit access review');
check(!isset($p['users'])&&!isset($p['user_rules']),'Public assessment does not disclose account or rule identities');
echo "$count native ACL input assertions passed; persistent permissions unchanged.\n";
