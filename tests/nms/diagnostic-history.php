<?php
require __DIR__ . '/../../plugins/nms/includes/diagnostic_history.php';
function nms_diag_labels() { return ['ping'=>'Ping']; }
function nms_current_user_id() { return 7; }
function nms_visible_host_sql($id) { return "$id IN (1,2)"; }
$queries=[];
function db_fetch_assoc_prepared($sql,$params) { global $queries; $queries[]=[$sql,$params]; return []; }
function db_fetch_cell_prepared($sql,$params) { global $queries; $queries[]=[$sql,$params]; return 26; }
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$h=nms_diag_history(['history_device'=>2,'history_tool'=>'ping','history_status'=>'failed','history_from'=>'2026-09-01','history_to'=>'2026-09-22','history_size'=>10,'history_page'=>999]);
check($h['page']===3 && $h['offset']===20,'Page clamped to last page');
check(strpos($queries[2][0],'LIMIT 10 OFFSET 20')!==false,'Bounded SQL pagination');
check(strpos($queries[2][0],'result_json')===false,'Output not loaded in lists');
check($queries[2][1]===[7,2,'ping','failed','2026-09-01 00:00:00','2026-09-22 00:00:00'],'Bound filter parameters');
check(strpos($queries[2][0],'j.user_id=?')!==false && strpos($queries[2][0],'h.id IN (1,2)')!==false,'Owner and device access applied');
$h=nms_diag_history(['history_tool'=>"' OR 1=1",'history_status'=>['bad'],'history_from'=>'2026-02-31','history_size'=>999,'history_page'=>-1]);
check($h['filters']['history_tool']==='' && $h['filters']['history_status']==='' && $h['filters']['history_from']==='' && $h['filters']['history_size']===10 && $h['page']===1,'Invalid input normalized');
check(strpos(nms_diag_history_url(['history_device'=>2],['history_page'=>3]),'history_device=2&history_page=3')!==false,'Navigation retains filters');
echo "7 history checks passed\n";
