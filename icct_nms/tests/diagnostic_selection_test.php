<?php
require __DIR__ . '/../inventory/diagnostics/services/backend/diagnostics.php';
$selection=['disabled'=>'','tools'=>'ping,traceroute_tcp,retired_method'];
function icct_backend_require_device_access($id){if($id===9)throw new RuntimeException('Denied');}
function db_fetch_row_prepared($sql,$args){return $GLOBALS['selection'];}
$labels=icct_backend_diag_selected_labels(2);
if(array_keys($labels)!==['ping','traceroute_tcp'])throw new RuntimeException('Unselected/unknown diagnostics exposed');
$selection['tools']='arp';
if(array_keys(icct_backend_diag_selected_labels(2))!==['arp'])throw new RuntimeException('Selection change not synchronized');
$selection['disabled']='on';if(icct_backend_diag_selected_labels(2))throw new RuntimeException('Disabled device offered diagnostics');
$selection=[];if(icct_backend_diag_selected_labels(2))throw new RuntimeException('Missing profile exposed diagnostics');
try{icct_backend_diag_selected_labels(9);throw new LogicException('Unauthorized diagnostics exposed');}catch(RuntimeException $e){}
echo "Saved methods, changed selection, disabled devices, missing profiles and access checks passed.\n";
