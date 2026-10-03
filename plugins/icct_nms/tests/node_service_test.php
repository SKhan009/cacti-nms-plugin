<?php
require __DIR__.'/../includes/node_service.php';
$rows=[1=>['id'=>1,'name'=>'Original','site_id'=>1,'node_kind'=>'vehicle']]; $racks=0; $duplicate=false; $writes=[];
function icct_backend_require_management($realm){}
function db_fetch_cell($sql){return str_contains($sql,'LAST_INSERT_ID')?2:'test';}
function icct_nms_apply_node_rack_preset(...$args){}
function icct_nms_id($value) { if(!is_scalar($value) || !ctype_digit((string)$value)) throw new InvalidArgumentException('Invalid ID'); return (int)$value; }
function icct_backend_classification_text($value,$max) { if(!is_string($value) || strlen($value)>$max) throw new InvalidArgumentException('Invalid name'); return trim($value); }
function icct_backend_current_user_id() { return 1; }
function db_fetch_row_prepared($sql,$args) { global $rows; return $rows[$args[0]] ?? []; }
function db_fetch_cell_prepared($sql,$args) { global $racks,$duplicate; if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return 1; if(str_contains($sql,'racks')) return $racks; if(str_contains($sql,'FROM sites')) return in_array($args[0],[1,2],true)?$args[0]:0; return $duplicate?1:0; }
function icct_backend_category_execute($sql,$args=[]) { global $writes; $writes[]=[$sql,$args]; }
function rejectNode($input) { try { icct_nms_save_node($input); } catch(InvalidArgumentException $e) { return; } throw new Exception('Invalid node action accepted'); }
$valid=['action'=>'save_node','node_id'=>'1','node_name'=>'Renamed','site_id'=>'2'];
if(icct_nms_save_node($valid)!=='Node updated.') throw new Exception('Update failed');
$update=$writes[count($writes)-2];
if(str_contains($update[0],'node_kind') || str_contains($update[0],'DELETE')) throw new Exception('Unrelated topology data changed');
if(icct_nms_save_node(array_replace($valid,['node_id'=>'0']))!=='Node added.') throw new Exception('Create failed');
rejectNode(array_replace($valid,['node_name'=>''])); rejectNode(array_replace($valid,['site_id'=>'999'])); rejectNode(array_replace($valid,['node_id'=>'99']));
$duplicate=true; rejectNode($valid); $duplicate=false;
$racks=1; rejectNode($valid); rejectNode(['action'=>'delete_node','node_id'=>'1']);
$racks=0; if(icct_nms_save_node(['action'=>'delete_node','node_id'=>'1'])!=='Node deleted.') throw new Exception('Delete failed');
rejectNode(['action'=>'delete_node','node_id'=>'0']);
echo "Node CRUD, site validation, duplicate names, rack protection and topology field preservation passed.\n";
