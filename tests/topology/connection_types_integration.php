<?php
// Run on a test Cacti installation: php connection_types_integration.php /var/www/html/cacti
chdir($argv[1] ?? '/var/www/html/cacti'); require 'include/global.php';
require 'plugins/nms/includes/database.php'; require_once 'plugins/nms/includes/topology/connections.php';
$_SESSION['sess_user_id']=1;
function check82($ok,$message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
db_execute('START TRANSACTION');
try {
 $type=['nms_action'=>'connection_type_save','original_type'=>'','type'=>'QA temporary 82','color'=>'#123456','line_style'=>'dash-dot','symbol'=>'arrow'];
 nms_connection_save($type);
 check82(db_fetch_cell_prepared('SELECT color FROM plugin_nms_connection_types WHERE name=?',[$type['type']])==='#123456','create custom type');
 $row=db_fetch_row('SELECT * FROM plugin_nms_manual_connections LIMIT 1');
 check82((bool)$row,'existing connection available');
 db_execute_prepared('UPDATE plugin_nms_manual_connections SET type=? WHERE id=?',[$type['type'],$row['id']]);
 try { nms_connection_save(['nms_action'=>'connection_type_delete','type'=>$type['type']]); throw new RuntimeException('in-use delete accepted'); } catch (InvalidArgumentException $e) { echo "PASS in-use deletion blocked\n"; }
 nms_connection_save(array_merge($type,['original_type'=>$type['type'],'type'=>'QA renamed 82']));
 check82(db_fetch_cell_prepared('SELECT type FROM plugin_nms_manual_connections WHERE id=?',[$row['id']])==='QA renamed 82','rename preserves connections');
 db_execute_prepared('UPDATE plugin_nms_manual_connections SET type=? WHERE id=?',[$row['type'],$row['id']]);
 nms_connection_save(['nms_action'=>'connection_type_delete','type'=>'QA renamed 82']);
 check82(!db_fetch_cell("SELECT name FROM plugin_nms_connection_types WHERE name='QA renamed 82'"),'unused type deleted');
} finally { db_execute('ROLLBACK'); }
echo "All temporary changes rolled back\n";
