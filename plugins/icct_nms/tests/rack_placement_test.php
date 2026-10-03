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
 if(str_contains($sql,'host_id !='))return $GLOBALS['overlap']?1:0;
 return 0;
}
function icct_backend_category_execute($sql,$args=[]){
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
$denied=true;try{icct_nms_rack_place(3,6,1,['1']);throw new LogicException('Access bypass');}catch(RuntimeException $expected){}
if(!in_array('ROLLBACK',$transactions,true))throw new LogicException('Failed moves did not rollback');
echo "Rack units, overlap, capacity, stale edits, site, permissions, peripheral transitions and unassignment passed.\n";
