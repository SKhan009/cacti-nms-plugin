<?php
// Run against a test Cacti installation; all mutations are rolled back.
chdir($argv[1] ?? '/var/www/html/cacti'); require 'include/global.php';
require_once 'plugins/nms/includes/database.php'; require_once 'plugins/nms/includes/topology/canvas.php';
$_SESSION['sess_user_id']=1;
function check_classification($ok,$message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$canvas=nms_canvas_data(null); $links=array_values(array_filter($canvas['links'],fn($l)=>empty($l['manual'])));
check_classification(count($links)>0,'discovery records available');
$link=$links[0]; $key=nms_connection_link_key($link);
$reverse=$link;
foreach (['','_port','_ifindex'] as $suffix) { $reverse['a'.$suffix]=$link['b'.$suffix] ?? ''; $reverse['b'.$suffix]=$link['a'.$suffix] ?? ''; }
check_classification(nms_connection_link_key($reverse)===$key,'direction-independent key');
db_execute('START TRANSACTION');
try {
 nms_connection_save(['nms_action'=>'connection_classify','link_key'=>$key,'type'=>'VSAT / Leased-line']);
 $after=nms_connection_apply_classifications([$link])[0];
 check_classification($after['connection_type']==='VSAT / Leased-line','classification saved and applied to map');
 foreach (['a','b','a_port','b_port','state','current','protocols'] as $field) check_classification(($after[$field] ?? null)===($link[$field] ?? null),'preserves '.$field);
 try { nms_connection_save(['nms_action'=>'connection_type_delete','type'=>'VSAT / Leased-line']); throw new RuntimeException('accepted deletion of used type'); } catch (InvalidArgumentException $e) { echo "PASS classified type protected\n"; }
 try { nms_connection_save(['nms_action'=>'connection_classify','link_key'=>str_repeat('0',64),'type'=>'Fiber']); throw new RuntimeException('accepted unknown link'); } catch (InvalidArgumentException $e) { echo "PASS unknown link rejected\n"; }
 nms_connection_save(['nms_action'=>'connection_classify','link_key'=>$key,'type'=>'__unclassified__']);
 check_classification(!db_fetch_cell_prepared('SELECT type FROM plugin_nms_link_classification WHERE link_key=?',[$key]),'classification can be cleared');
} finally { db_execute('ROLLBACK'); }
echo "Temporary classification changes rolled back\n";
