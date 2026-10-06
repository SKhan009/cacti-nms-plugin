<?php
require __DIR__ . '/../presets/services/backend/categories.php';
require __DIR__ . '/../presets/services/connection_service.php';
$stored=null; $writes=0;
function db_fetch_cell($sql) { return 'test'; }
function db_fetch_cell_prepared($sql,$args) { global $stored; return str_contains($sql,'meta_value') ? $stored : 1; }
function db_execute_prepared($sql,$args) { global $stored,$writes; $stored=$args[1];$writes++;return true; }
function icct_nms_h($value) { return htmlspecialchars($value,ENT_QUOTES); }
$profiles=icct_nms_connections();if(count($profiles)!==6) throw new RuntimeException('Defaults mismatch');
$id=array_key_first($profiles);
$input=['action'=>'save_connection','connection_id'=>$id,'connection_name'=>'Ethernet','color'=>'#00ffaa','line_style'=>'dash-dot','symbol'=>'arrow'];
function reject_connection($change,$message) { global $input,$writes; $before=$writes;try{icct_nms_save_connection(array_replace($input,$change));throw new RuntimeException('Expected rejection');}catch(InvalidArgumentException $e){if(!str_contains($e->getMessage(),$message)||$writes!==$before)throw new RuntimeException('Invalid input wrote data');} }
reject_connection(['connection_name'=>''],'Enter');reject_connection(['connection_name'=>'Fiber'],'already exists');reject_connection(['color'=>'red;display:none'],'color');reject_connection(['line_style'=>'unknown'],'line style');reject_connection(['symbol'=>'<script>'],'symbol');reject_connection(['connection_id'=>'invalid'],'saved');
icct_nms_save_connection($input);$saved=icct_nms_connections()[$id];if($saved['symbol']!=='arrow'||$saved['color']!=='#00ffaa')throw new RuntimeException('Save failed');
$svg=icct_nms_connection_preview($saved);if(!str_contains($svg,'10 4 2 4')||!str_contains($svg,'m12 5'))throw new RuntimeException('Preview mismatch');
icct_nms_save_connection(['action'=>'delete_connection','connection_id'=>$id]);if(count(icct_nms_connections())!==5)throw new RuntimeException('Delete failed');
$stored='[]';if(icct_nms_connections())throw new RuntimeException('Empty catalogue must stay empty');
echo "Connection defaults, validation, styling and persistence passed.\n";
