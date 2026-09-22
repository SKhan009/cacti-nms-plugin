<?php
/** Shared native Cacti submenu definitions and markup. */
function tp_sidebar_items() {
    return array('topo_start.php'=>'Setup Guide','topo_canvas.php'=>'Topology View','topo_discovery.php'=>'Discovery','topo_sim.php'=>'Simulator');
}
function tp_submenu($items,$selected,$label='Topology navigation',$current='page') {
    print '<div class="tabs"><nav aria-label="'.tp_h($label).'"><ul role="tablist">';
    foreach($items as $key=>$item)print '<li class="subTab"><a'.((string)$key===(string)$selected?' class="selected" aria-current="'.tp_h($current).'"':'').' href="'.tp_h($item['url']).'">'.tp_h($item['label']).'</a></li>';
    print '</ul></nav></div>';
}
function tp_sim_tabs($selected) {
    tp_submenu(array('imports'=>array('label'=>'Imports','url'=>'topo_sim.php?tab=imports'),'upload'=>array('label'=>'Upload SNMP Record','url'=>'topo_sim.php?tab=upload')),$selected,'Simulator');
}

/** Discovery sections share the native submenu template. */
function tp_discovery_tabs($selected){
    $items=array();foreach(array('settings'=>'Collection Settings','protocols'=>'Device Protocols','results'=>'Run & Results','profiles'=>'Saved Profiles') as $key=>$label)$items[$key]=array('label'=>$label,'url'=>'topo_discovery.php?tab='.$key);
    print '<div id="tp-discovery-tabs">';tp_submenu($items,$selected,'Discovery sections');print '</div>';
}
