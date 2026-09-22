<?php
/** Three reusable setup steps; devices and their Sites remain owned by Cacti. */
require_once(__DIR__.'/physical.php');
require_once(__DIR__.'/assign.php');
function tp_flow_steps(){return array(1=>'Device Segments',2=>'Port Profiles',3=>'Assign Devices');}
function tp_flow_context($unitId=0,$siteId=0){return array('site'=>0,'unit'=>tp_scope_id());}
function tp_flow_url($step,$site=0,$unit=0){return 'topo_start.php?step='.min(3,max(1,(int)$step));}
function tp_flow_target($path,$step,$site=0,$unit=0){return $path.(strpos($path,'?')===false?'?':'&').'flow_step='.min(3,max(1,(int)$step));}
function tp_flow_button($text,$url){return tp_browse_button($text,$url);}
function tp_flow_state($site=0,$unit=0){
    $hosts=tp_physical_hosts(tp_scope_id());$ported=0;
    foreach($hosts as $h)if(db_fetch_cell_prepared('SELECT id FROM plugin_topology_ports WHERE host_id=? LIMIT 1',array($h['id'])))$ported++;
    return array('hosts'=>$hosts,'count'=>count($hosts),'ready'=>array(1=>(int)db_fetch_cell('SELECT COUNT(*) FROM plugin_topology_categories WHERE active=1')>0,2=>(int)db_fetch_cell('SELECT COUNT(*) FROM plugin_topology_port_profiles p JOIN plugin_topology_categories c ON c.id=p.category_id WHERE c.active=1')>0,3=>count($hosts)>0&&$ported===count($hosts)));
}
function tp_flow_bar($step,$site=0,$unit=0,$editor=false){
    global $tp_flow;$step=min(3,max(1,(int)$step));$tp_flow=array('step'=>$step,'site'=>0,'unit'=>tp_scope_id());$steps=tp_flow_steps();$ready=tp_flow_state()['ready'];
    $items=array();foreach($steps as $n=>$label)$items[$n]=array('url'=>tp_flow_url($n),'label'=>$n.'. '.$label.($ready[$n]?' ✓':''));
    tp_submenu($items,$step,'Topology setup steps','step');html_start_box(tp_h('Step '.$step.' of 3 — '.$steps[$step]),'100%',false,3,'center','');
    if($editor)print '<tr><td>'.tp_flow_button('Back to '.$steps[$step],tp_flow_url($step)).'</td></tr>';html_end_box();
}
function tp_flow_editor($step,$unit=0,$site=0){tp_flow_bar($step,0,tp_scope_id(),true);return tp_flow_context();}
function tp_flow_return($step,$unit=0,$site=0){if(!isset($_POST['flow_step']))return false;header('Location: '.tp_flow_url($step));return true;}
function tp_flow_page(){
    $step=min(3,tp_id($_GET['step']??3));if(!isset($_GET['step']))$step=1;
    tp_flow_bar($step);$url=function($path)use($step){return tp_flow_target($path,$step);};
    if($step===1){
        $rows=array();foreach(db_fetch_assoc('SELECT * FROM plugin_topology_categories ORDER BY name') as $r)$rows[]=array(tp_h($r['name']),$r['active']?'Active':'Archived',tp_flow_button('Edit Category and Color',$url('topo_setup.php?edit=category&id='.$r['id'])));
        tp_table('Device Segments',array('Segment','State','Action'),$rows,$url('topo_setup.php?edit=category'));
    }elseif($step===2){
        $rows=array();foreach(db_fetch_assoc('SELECT p.*,c.name AS category FROM plugin_topology_port_profiles p JOIN plugin_topology_categories c ON c.id=p.category_id WHERE c.active=1 ORDER BY c.name,p.name') as $r)$rows[]=array(tp_h($r['category']),tp_h($r['name']),tp_h($r['prefix'].$r['first_number'].' – '.$r['prefix'].($r['first_number']+$r['port_count']-1)),tp_h($r['connector']),tp_flow_button('Edit Profile',$url('topo_ports.php?edit=profile&id='.$r['id'])));
        tp_table('Segment Port Profiles',array('Device Segment','Profile','Physical Ports','Connector','Action'),$rows,$url('topo_ports.php?edit=profile'));
    }else tp_assignment_page();
    print '<p>';if($step>1)print tp_flow_button('← Back',tp_flow_url($step-1)).' ';
    if($step<3)print tp_flow_button('Continue to '.tp_flow_steps()[$step+1].' →',tp_flow_url($step+1));
    else print tp_flow_button('Open Topology View','topo_canvas.php').' '.tp_flow_button('Configure Discovery','topo_discovery.php');
    print '</p>';
}
