<?php
require __DIR__.'/../includes/services/topology_config.php';
require __DIR__.'/../includes/rack_view_service.php';
$placements=[];$meta=[];$denied=false;$overlap=false;$transactions=[];
function icct_backend_current_user_id(){return 1;}
function icct_backend_require_management($realm){if($realm!==3)throw new RuntimeException('Wrong realm');}
function icct_backend_require_device_access($id){if($GLOBALS['denied'])throw new RuntimeException('Forbidden');}
function db_fetch_cell($sql){return 'fixture';}
function db_fetch_row_prepared($sql,$args){
 if(str_contains($sql,'FROM host'))return ['site_id'=>6];
 if(str_contains($sql,'FROM plugin_icct_nms_racks'))return ['id'=>$args[0],'site_id'=>6,'node_id'=>$args[0]===3?2:1,'unit_count'=>24];
 return $GLOBALS['placements'][$args[0]]??[];
}
function db_fetch_cell_prepared($sql,$args){
 if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK')||str_contains($sql,'FROM host'))return 1;
 if(str_contains($sql,'SELECT r.node_id FROM plugin_icct_nms_rack_devices'))return isset($GLOBALS['placements'][$args[0]])?1:0;
 if(str_contains($sql,'JOIN plugin_icct_nms_racks r ON m.meta_value'))return isset($GLOBALS['meta'][$args[0]])?1:0;
 if(str_contains($sql,'SELECT n.id FROM plugin_icct_nms_meta'))return (int)($GLOBALS['meta'][$args[0]]??0);
 if(str_contains($sql,'meta_value'))return $GLOBALS['meta'][$args[0]]??'';
 if(str_contains($sql,'rack_id=? AND start_unit<=?')) {
  foreach($GLOBALS['placements'] as $p)if($p['rack_id']===$args[0] && $p['start_unit']<=$args[1] && $p['start_unit']+$p['unit_height']-1>=$args[2])return 1;
  return 0;
 }
 if(str_contains($sql,'host_id !='))return $GLOBALS['overlap']?1:0;
 return 0;
}
function icct_backend_category_execute($sql,$args=[]){
 if($sql==='START TRANSACTION')$GLOBALS['snapshot']=[$GLOBALS['placements'],$GLOBALS['meta']];
 if($sql==='ROLLBACK')[$GLOBALS['placements'],$GLOBALS['meta']]=$GLOBALS['snapshot'];
 if(str_contains($sql,'INSERT INTO plugin_icct_nms_rack_devices'))$GLOBALS['placements'][$args[0]]=['rack_id'=>$args[1],'start_unit'=>$args[2],'unit_height'=>$args[3],'updated_at'=>'now'];
 elseif(str_contains($sql,'DELETE FROM plugin_icct_nms_rack_devices'))unset($GLOBALS['placements'][$args[0]]);
 elseif(str_contains($sql,'INSERT INTO plugin_icct_nms_meta'))$GLOBALS['meta'][$args[0]]=$args[1];
 elseif(str_contains($sql,'DELETE FROM plugin_icct_nms_meta'))unset($GLOBALS['meta'][$args[0]]);
 else $GLOBALS['transactions'][]=$sql;
}
function reject($callback){try{$callback();throw new LogicException('Invalid move accepted');}catch(InvalidArgumentException $expected){}}
if(icct_nms_rack_units(['4','3'],24)!==[3,2])throw new LogicException('Multi-unit range incorrect');
foreach([[],['0'],['25'],['3','5'],['3','3'],['1.5']] as $units)reject(fn()=>icct_nms_rack_units($units,24));
$revision=icct_nms_rack_revision(3);
icct_nms_rack_place(3,6,2,['3','4'],false,$revision);
if($placements[3]['unit_height']!==2)throw new LogicException('Range not persisted');
reject(fn()=>icct_nms_rack_place(3,6,2,['5'],false,$revision));
reject(fn()=>icct_nms_rack_place(3,7,2,['5']));
reject(fn()=>icct_nms_rack_place(3,6,3,['5']));
reject(fn()=>icct_nms_rack_place(3,6,3,[],true));
$overlap=true;reject(fn()=>icct_nms_rack_place(3,6,2,['5']));$overlap=false;
reject(fn()=>icct_nms_rack_place(3,6,2,['24','25']));
icct_nms_rack_place(3,6,2,[],true);
if(isset($placements[3])||($meta['rack_peripheral_3']??null)!=='2')throw new LogicException('Peripheral transition failed');
icct_nms_rack_place(3,6,1,['6','7']);
if(isset($meta['rack_peripheral_3'])||$placements[3]['rack_id']!==1)throw new LogicException('Physical transition failed');
icct_nms_rack_place(3,6,0,[]);
if(isset($placements[3])||isset($meta['rack_peripheral_3'])||isset($meta['device_node_id_3']))throw new LogicException('Unassignment failed');
$meta['device_node_id_3']='2';icct_nms_rack_place(3,6,0,[]);if($meta['device_node_id_3']!=='2')throw new LogicException('Manual save erased direct node membership');
reject(fn()=>icct_nms_rack_place(3,6,1,['1']));icct_nms_rack_place(3,6,0,[],false,null,true);if(isset($meta['device_node_id_3']))throw new LogicException('Explicit unassignment failed');
// Draft saves are atomic and reject stale or duplicate moves before changing slots.
$placements=[];$meta=[];
$move=fn($id,$units,$revision)=>['host_id'=>$id,'rack_id'=>1,'units'=>$units,'revision'=>$revision];
$draft=[$move(3,['2'],icct_nms_rack_revision(3)),$move(4,['4','5'],icct_nms_rack_revision(4))];
if(icct_nms_rack_save_draft($draft)!==[3,4]||$placements[4]['unit_height']!==2)throw new LogicException('Batch save failed');
$before=[$placements,$meta];
reject(fn()=>icct_nms_rack_save_draft($draft));
$valid=$move(3,['6'],icct_nms_rack_revision(3));
reject(fn()=>icct_nms_rack_save_draft([$valid,$valid]));
reject(fn()=>icct_nms_rack_save_draft([$valid,$move(4,['24','25'],icct_nms_rack_revision(4))]));
if([$placements,$meta]!==$before)throw new LogicException('Failed batch changed saved placements');
// Reservations persist without creating hosts and protect physical capacity.
$placements=[];$meta=[];
$reserve=fn($items,$revision)=>['rack_id'=>1,'revision'=>$revision,'items'=>$items];
$revision=icct_nms_rack_reservation_revision(1);
$item=['id'=>'reserved-test','start'=>3,'height'=>2];
icct_nms_rack_save_draft([],[$reserve([$item],$revision)]);
if(icct_nms_rack_reserved_units(1)!==[3,4])throw new LogicException('Reservation did not persist');
reject(fn()=>icct_nms_rack_place(3,6,1,['3']));
reject(fn()=>icct_nms_rack_save_draft([],[$reserve([],$revision)]));
$revision=icct_nms_rack_reservation_revision(1);
reject(fn()=>icct_nms_rack_save_draft([],[$reserve([$item,['id'=>'second','start'=>4,'height'=>1]],$revision)]));
reject(fn()=>icct_nms_rack_save_draft([],[$reserve([['id'=>'large','start'=>24,'height'=>2]],$revision)]));
icct_nms_rack_place(3,6,1,['6']);
$before=[$placements,$meta];
reject(fn()=>icct_nms_rack_save_draft([],[$reserve([['id'=>'collision','start'=>6,'height'=>1]],$revision)]));
reject(fn()=>icct_nms_rack_save_draft([$move(3,['3'],icct_nms_rack_revision(3))],[$reserve([$item],$revision)]));
if([$placements,$meta]!==$before)throw new LogicException('Failed reservation batch changed saved data');
icct_nms_rack_save_draft([],[$reserve([],$revision)]);
icct_nms_rack_place(3,6,1,['3']);
$denied=true;try{icct_nms_rack_place(3,6,1,['1']);throw new LogicException('Access bypass');}catch(RuntimeException $expected){}
if(!in_array('ROLLBACK',$transactions,true))throw new LogicException('Failed moves did not rollback');
echo "Rack units, overlap, capacity, stale edits, site, permissions, peripheral transitions and unassignment passed.\n";
