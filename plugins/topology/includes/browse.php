<?php
/** Read-only list landing pages with native filters and explicit node actions. */
require_once(__DIR__.'/ui.php');
require_once(__DIR__.'/physical.php');
function tp_browse_options() {
    $site=tp_id($_GET['list_site']??0,true);if($site)tp_site($site);
    $search=tp_text($_GET['search']??'',100,false);
    $rows=tp_id($_GET['rows']??25);if(!in_array($rows,array(25,50,100,250),true))throw new InvalidArgumentException('Select a supported row count.');
    return array('list_site'=>$site,'search'=>$search,'rows'=>$rows,'page'=>tp_id($_GET['page']??1),'category_id'=>tp_id($_GET['category_id']??0,true));
}
function tp_browse_button($label,$url) {return '<a data-topology-list="true" class="ui-button ui-corner-all ui-widget" href="'.tp_h($url).'">'.tp_h($label).'</a>';}
function tp_browse_matches($query,$values) {return $query===''||mb_stripos(implode(' ', $values),$query)!==false;}
function tp_browse_data($kind,$filter) {
    $out=array();$locations=tp_site_options();
    foreach(tp_physical_hosts(tp_scope_id()) as $h){
        if($filter['list_site']&&(int)$h['site_id']!==$filter['list_site'])continue;
        if(!empty($filter['category_id'])&&(int)$h['category_id']!==$filter['category_id'])continue;
        if(tp_browse_matches($filter['search'],array($locations[$h['site_id']]??'',$h['description'],$h['category'],$h['device_type'],$h['role'])))$out[]=array('host'=>$h);
    }
    return $out;
}
function tp_browse_filter($title,$f,$extra,$categoryFilter=false) {
    $path=basename($_SERVER['SCRIPT_NAME']);
    html_start_box(tp_h($title),'100%',false,3,'center','');
    print '<tr><td><form id="tp-list-filter" method="get" action="'.tp_h($path).'"><table class="filterTable"><tr><td><label for="tp-search">Search</label></td><td><input class="ui-state-default ui-corner-all" type="search" id="tp-search" name="search" maxlength="100" size="25" value="'.tp_h($f['search']).'"></td><td><label for="tp-list-site">Site</label></td><td><select id="tp-list-site" name="list_site"><option value="0">All Sites</option>';
    foreach(tp_sites() as $s)print '<option value="'.(int)$s['id'].'"'.((int)$s['id']===$f['list_site']?' selected':'').'>'.tp_h($s['name']).'</option>';
    if($categoryFilter){print '</select></td><td><label for="tp-list-category">Device Segment</label></td><td><select id="tp-list-category" name="category_id"><option value="0">All Segments</option>';foreach(tp_category_options() as $id=>$name)if($id)print '<option value="'.(int)$id.'"'.((int)$id===(int)($f['category_id']??0)?' selected':'').'>'.tp_h($name).'</option>';}
    print '</select></td><td><label for="tp-list-rows">Rows</label></td><td><select id="tp-list-rows" name="rows">';
    foreach(array(25,50,100,250) as $n)print '<option value="'.$n.'"'.($n===$f['rows']?' selected':'').'>'.$n.'</option>';
    print '</select></td><td><button class="ui-button ui-corner-all ui-widget" type="submit">Go</button> '.tp_browse_button('Clear',$path.($extra?'?'.http_build_query($extra):'')).'</td></tr></table>';
    foreach($extra as $key=>$value)print '<input type="hidden" name="'.tp_h($key).'" value="'.tp_h($value).'">';
    print '</form></td></tr>';html_end_box();
    print '<script '.CactiSecureHeaders::getNonceAttribute().'>$(function(){$("#tp-list-site,#tp-list-rows,#tp-list-category").on("selectmenuchange",function(){document.getElementById("tp-list-filter").requestSubmit();});});</script>';
}
function tp_browse_pager($f,$total,$extra=array()){
    $query=$extra+$f;unset($query['page']);
    $object=basename($_SERVER['SCRIPT_NAME'])==='topo_sim.php'?'Imports':'Devices';
    print html_nav_bar(basename($_SERVER['SCRIPT_NAME']).'?'.http_build_query($query),MAX_DISPLAY_PAGES,$f['page'],$f['rows'],$total,30,$object,'page','main');
}

function tp_browse_page($kind,$extra=array()){
    $title=$kind==='ports'?'Device Port Configuration':'Device Inventory';
    $f=tp_browse_options();$all=tp_browse_data($kind,$f);$total=count($all);$f['page']=min($f['page'],max(1,(int)ceil($total/$f['rows'])));
    tp_browse_filter($title,$f,$extra,true);$rows=array();$locations=tp_site_options();
    foreach(array_slice($all,($f['page']-1)*$f['rows'],$f['rows']) as $r){
        $h=$r['host'];$id=(int)$h['id'];$count=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_topology_ports WHERE host_id=?',array($id));
        $actions=tp_browse_button('Device / SNMP',tp_url('host.php?action=edit&id='.$id)).' '.tp_browse_button('Configure Ports','topo_ports.php?edit=apply&host_id='.$id);
        $rows[]=array(tp_h($h['description']),tp_h($locations[$h['site_id']]??'Unassigned'),tp_h($h['category']),get_colored_device_status($h['disabled'],$h['status']),$count,$actions);
    }
    tp_table($title,array('Device','Site','Device Segment','Cacti Status','Physical Ports','Actions'),$rows,'topo_start.php?step=3','Assign devices in the Setup Guide to display them here.',false);
    tp_browse_pager($f,$total,$extra);
}
