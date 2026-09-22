<?php
/** Atomic category + physical profile assignment, referencing native Cacti hosts. */
require_once(__DIR__.'/physical.php');
require_once(__DIR__.'/browse.php');
function tp_assign_devices($category,$profile,$ids) {
    return tp_physical_write(function()use($category,$profile,$ids){
        $category=tp_category($category);$profile=tp_id($profile);
        if(!is_array($ids)||!$ids||count($ids)>250)throw new InvalidArgumentException('Select 1–250 devices.');
        $ids=array_unique(array_map('tp_id',$ids));
        if(!db_fetch_cell_prepared('SELECT id FROM plugin_topology_port_profiles WHERE id=? AND category_id=?',array($profile,$category)))throw new InvalidArgumentException('Choose a port profile for the selected device segment.');
        $name=db_fetch_cell_prepared('SELECT name FROM plugin_topology_categories WHERE id=?',array($category));
        foreach($ids as $id) {
            tp_host($id);
            $old=db_fetch_row_prepared('SELECT * FROM plugin_topology_devices WHERE host_id=?',array($id));
            tp_assignment_write($id,$category,!empty($old['device_type'])?$old['device_type']:$name,$old['role']??'',$old['protocol']??'none',tp_scope_id());
            tp_ports_write(tp_scope_id(),$id,$profile);
        }
        return count($ids);
    });
}
function tp_assignment_page() {
    $category=tp_id($_GET['category_id']??$_POST['category_id']??0,true);
    if($category)tp_category($category);
    $f=tp_browse_options();$f['category_id']=$category;
    tp_browse_filter('Assign Existing Cacti Devices',$f,array('step'=>3),true);
    $assigned=array_column(db_fetch_assoc('SELECT d.*,c.name AS category FROM plugin_topology_devices d LEFT JOIN plugin_topology_categories c ON c.id=d.category_id'),null,'host_id');
    $locations=tp_site_options();$hosts=array();
    foreach(get_allowed_devices() as $h) {
        $a=$assigned[$h['id']]??array();
        if(!empty($h['deleted'])||($f['list_site']&&(int)$h['site_id']!==$f['list_site']))continue;
        if($category&&!empty($a['category_id'])&&(int)$a['category_id']!==$category&&db_fetch_cell_prepared('SELECT id FROM plugin_topology_ports WHERE host_id=? LIMIT 1',array($h['id'])))continue;
        if(tp_browse_matches($f['search'],array($h['description'],$h['hostname'],$a['category']??'')))$hosts[]=$h;
    }
    $total=count($hosts);$pages=max(1,(int)ceil($total/$f['rows']));$f['page']=min($f['page'],$pages);
    $profiles=array(''=>'Select a port profile');
    if($category)foreach(db_fetch_assoc_prepared('SELECT id,name,port_count FROM plugin_topology_port_profiles WHERE category_id=? ORDER BY name',array($category)) as $p)$profiles[$p['id']]=$p['name'].' — '.$p['port_count'].' ports';
    print '<form method="post" id="tp-assign" action="topo_start.php?step=3"><input type="hidden" name="tp_action" value="bulk"><input type="hidden" name="category_id" value="'.$category.'">';
    html_start_box('Category and Port Profile','100%',false,3,'center','');
    print '<tr><td>Device Segment: <strong>'.tp_h($category?tp_category_options()[$category]:'Choose a segment in the filter above').'</strong> &nbsp; <label for="profile_id">Port Profile</label> <select name="profile_id" id="profile_id">';
    foreach($profiles as $id=>$name)print '<option value="'.tp_h($id).'"'.((string)$id===(string)($_POST['profile_id']??'')?' selected':'').'>'.tp_h($name).'</option>';
    print '</select> <button type="submit" class="ui-button ui-corner-all ui-widget"'.(!$category||count($profiles)===1?' disabled':'').'>Apply to Selected Devices</button></td></tr>';html_end_box();
    $rows=array();foreach(array_slice($hosts,($f['page']-1)*$f['rows'],$f['rows']) as $h) {
        $id=(int)$h['id'];$a=$assigned[$id]??array();$ports=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_topology_ports WHERE host_id=?',array($id));
        $rows[]=array('<input type="checkbox" name="host_ids[]" value="'.$id.'" aria-label="Select '.tp_h($h['description']).'">',tp_h($h['description']),tp_h($h['hostname']),tp_h($locations[$h['site_id']]??'Unassigned'),tp_h($a['category']??'Unassigned'),$ports,tp_browse_button('Device / SNMP',tp_url('host.php?action=edit&id='.$id)));
    }
    html_start_box('Cacti Devices','100%',false,3,'center',tp_url('host.php?action=edit'));
    print '<tr class="tableHeader"><th><input id="tp-select-all" type="checkbox" aria-label="Select all displayed devices"></th><th>Device</th><th>Address</th><th>Site</th><th>Current Category</th><th>Ports</th><th>Action</th></tr>';
    foreach($rows as $i=>$row)print '<tr class="'.($i%2?'even':'odd').'"><td>'.implode('</td><td>',array_map('tp_list_links',$row)).'</td></tr>';
    if(!$rows)print '<tr><td colspan="7">No matching devices.</td></tr>';
    html_end_box();print '</form>';
    tp_browse_pager($f,$total,array('step'=>3));
    print '<script '.CactiSecureHeaders::getNonceAttribute().'>$(function(){$("#tp-select-all").on("change",function(){$("#tp-assign input[name=\"host_ids[]\"]").prop("checked",this.checked);});});</script>';
}
