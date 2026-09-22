<?php
/** Explicit category port profiles and operator-configured cables. No SNMP inference. */
require_once(__DIR__.'/model.php');
function tp_physical_schema() {
    if(!tp_column_exists('plugin_topology_categories','color')) tp_exec("ALTER TABLE plugin_topology_categories ADD color CHAR(7) NOT NULL DEFAULT '#64748b'");
    $tables=array(
        'port_profiles'=>'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL, prefix VARCHAR(64) NOT NULL, first_number SMALLINT UNSIGNED NOT NULL, port_count SMALLINT UNSIGNED NOT NULL, connector VARCHAR(32) NOT NULL, UNIQUE KEY category_name(category_id,name)',
        'ports'=>'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, host_id MEDIUMINT UNSIGNED NOT NULL, profile_id INT UNSIGNED NOT NULL, label VARCHAR(100) NOT NULL, connector VARCHAR(32) NOT NULL, ordinal SMALLINT UNSIGNED NOT NULL, if_index INT UNSIGNED NULL, UNIQUE KEY host_label(host_id,label), UNIQUE KEY host_interface(host_id,if_index)',
        'cables'=>'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unit_id INT UNSIGNED NOT NULL, port_a INT UNSIGNED NOT NULL, port_b INT UNSIGNED NOT NULL, created_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, KEY node(unit_id), KEY a(port_a), KEY b(port_b)',
        'positions'=>'unit_id INT UNSIGNED NOT NULL, host_id MEDIUMINT UNSIGNED NOT NULL, x INT UNSIGNED NOT NULL, y INT UNSIGNED NOT NULL, PRIMARY KEY(unit_id,host_id)',
        'scenes'=>'unit_id INT UNSIGNED PRIMARY KEY, revision INT UNSIGNED NOT NULL DEFAULT 0'
    );
    foreach($tables as $name=>$definition) tp_exec('CREATE TABLE IF NOT EXISTS plugin_topology_'.$name.' ('.$definition.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
/** Serialize topology mutations, including classification, and commit their audit atomically. */
function tp_physical_write($callback) {
    tp_require_core('host.php');
    $lock='topology-physical-'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,5)',array($lock))!==1) throw new RuntimeException('Another topology edit is in progress. Try again.');
    try {tp_exec('START TRANSACTION');$result=$callback();tp_exec('COMMIT');return $result;}
    catch(Throwable $e) {db_execute('ROLLBACK');throw $e;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',array($lock));}
}
function tp_physical_hosts($unitId) {
    $unit=tp_unit($unitId);$out=array();
    foreach(db_fetch_assoc_prepared('SELECT h.id,h.description,h.status,h.disabled,h.site_id,d.category_id,d.device_type,d.role,c.name AS category,c.color AS category_color FROM host h JOIN plugin_topology_devices d ON d.host_id=h.id LEFT JOIN plugin_topology_categories c ON c.id=d.category_id WHERE d.unit_id=? AND h.deleted="" ORDER BY h.description',array($unit['id'])) as $h) if(is_device_allowed($h['id'])) $out[(int)$h['id']]=$h;
    return $out;
}
function tp_physical_host($hostId,$unitId) {
    $hosts=tp_physical_hosts($unitId);$hostId=tp_id($hostId);
    if(!isset($hosts[$hostId])) throw new InvalidArgumentException('Select an accessible device assigned to topology.');
    return $hosts[$hostId];
}
function tp_profile_save($id,$category,$name,$prefix,$first,$count,$connector) {
    return tp_physical_write(function()use($id,$category,$name,$prefix,$first,$count,$connector){
        $id=tp_id($id,true);$category=tp_category($category);$name=tp_text($name);$prefix=tp_text($prefix,64);$connector=tp_text($connector,32);$first=tp_id($first,true);$count=tp_id($count);
        if($count>128 || $first+$count>65535) throw new InvalidArgumentException('Use 1–128 ports and numbering below 65535.');
        if($id&&!db_fetch_cell_prepared('SELECT id FROM plugin_topology_port_profiles WHERE id=?',array($id))) throw new InvalidArgumentException('Profile no longer exists.');
        if(db_fetch_cell_prepared('SELECT id FROM plugin_topology_port_profiles WHERE category_id=? AND name=? AND id<>?',array($category,$name,$id))) throw new InvalidArgumentException('This category already has a profile with that name.');
        if($id&&db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_topology_ports p JOIN plugin_topology_devices d ON d.host_id=p.host_id WHERE p.profile_id=? AND d.category_id<>?',array($id,$category))) throw new RuntimeException('An applied profile cannot change device segment.');
        $id=tp_core_save(array('id'=>$id,'category_id'=>$category,'name'=>$name,'prefix'=>$prefix,'first_number'=>$first,'port_count'=>$count,'connector'=>$connector),'plugin_topology_port_profiles');
        tp_audit('save_port_profile',$id);return $id;
    });
}
function tp_ports_apply($unitId,$hostId,$profileId) {
    return tp_physical_write(function()use($unitId,$hostId,$profileId){return tp_ports_write($unitId,$hostId,$profileId);});
}
function tp_ports_write($unitId,$hostId,$profileId) {
        $host=tp_physical_host($hostId,$unitId);$profileId=tp_id($profileId,true);$desired=array();
        if($profileId) {
            $profile=db_fetch_row_prepared('SELECT * FROM plugin_topology_port_profiles WHERE id=? AND category_id=?',array($profileId,$host['category_id']));
            if(!$profile) throw new InvalidArgumentException('Choose a port profile belonging to the device’s category.');
            tp_category($profile['category_id']);
            for($i=0;$i<(int)$profile['port_count'];$i++) $desired[$profile['prefix'].((int)$profile['first_number']+$i)]=$i;
        }
        $old=db_fetch_assoc_prepared('SELECT * FROM plugin_topology_ports WHERE host_id=?',array($host['id']));
        foreach($old as $p) {
            $connected=db_fetch_cell_prepared('SELECT id FROM plugin_topology_cables WHERE port_a=? OR port_b=?',array($p['id'],$p['id']));
            if($connected&&(!isset($desired[$p['label']])||$p['connector']!==$profile['connector'])) throw new RuntimeException('Disconnect cables from ports that would be removed or change connector first.');
            if(!isset($desired[$p['label']])) tp_exec('DELETE FROM plugin_topology_ports WHERE id=?',array($p['id']));
        }
        foreach($desired as $label=>$ordinal) tp_exec('INSERT INTO plugin_topology_ports (host_id,profile_id,label,connector,ordinal) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),connector=VALUES(connector),ordinal=VALUES(ordinal)',array($host['id'],$profileId,$label,$profile['connector'],$ordinal));
        tp_audit($profileId?'apply_port_profile':'clear_physical_ports',$host['id']);
}
/** Native cached interface choices; mappings are explicit and may be cleared. */
function tp_interface_options($hostId) {
    tp_host($hostId);$out=array(''=>'Unmapped');
    foreach(db_fetch_assoc_prepared('SELECT snmp_index,field_name,field_value FROM host_snmp_cache WHERE host_id=? AND present=1 AND field_name IN ("ifDescr","ifName") ORDER BY field_name',array($hostId)) as $r) if(preg_match('/^[1-9][0-9]*$/D',$r['snmp_index'])&&(float)$r['snmp_index']<=2147483647) $out[(int)$r['snmp_index']]=$r['field_value'].' (ifIndex '.$r['snmp_index'].')';
    return $out;
}
function tp_port_map($unitId,$portId,$ifIndex) {
    tp_physical_write(function()use($unitId,$portId,$ifIndex){
        $p=tp_physical_port($portId,$unitId);$index=$ifIndex===''?null:tp_id($ifIndex);
        if($index!==null&&!isset(tp_interface_options($p['host_id'])[$index])) throw new InvalidArgumentException('Choose an interface currently present in the Cacti data query cache.');
        if($index!==null&&db_fetch_cell_prepared('SELECT id FROM plugin_topology_ports WHERE host_id=? AND if_index=? AND id<>?',array($p['host_id'],$index,$p['id']))) throw new InvalidArgumentException('That interface is already mapped to another physical port.');
        tp_exec('UPDATE plugin_topology_ports SET if_index=? WHERE id=?',array($index,$p['id']));tp_audit('map_physical_port',$p['id']);
    });
}
function tp_physical_port($id,$unitId) {
    $p=db_fetch_row_prepared('SELECT * FROM plugin_topology_ports WHERE id=?',array(tp_id($id)));
    if(!$p) throw new InvalidArgumentException('Physical port no longer exists.');
    tp_physical_host($p['host_id'],$unitId);return $p;
}
function tp_cable_add($unitId,$a,$b) {
    return tp_physical_write(function()use($unitId,$a,$b){
        $pa=tp_physical_port($a,$unitId);$pb=tp_physical_port($b,$unitId);
        if((int)$pa['host_id']===(int)$pb['host_id']) throw new InvalidArgumentException('Choose ports on two different devices.');
        if(db_fetch_cell_prepared('SELECT id FROM plugin_topology_cables WHERE port_a IN (?,?) OR port_b IN (?,?)',array($pa['id'],$pb['id'],$pa['id'],$pb['id']))) throw new RuntimeException('A selected physical port is already connected. Disconnect its cable first.');
        tp_exec('INSERT INTO plugin_topology_cables (unit_id,port_a,port_b,created_by,created_at) VALUES (?,?,?,?,NOW())',array(tp_id($unitId),$pa['id'],$pb['id'],(int)$_SESSION['sess_user_id']));
        $id=(int)db_fetch_insert_id();tp_audit('connect_physical_ports',$id);return $id;
    });
}
function tp_cable_delete($unitId,$id) {
    tp_physical_write(function()use($unitId,$id){
        $row=db_fetch_row_prepared('SELECT * FROM plugin_topology_cables WHERE id=? AND unit_id=?',array(tp_id($id),tp_id($unitId)));
        if(!$row) throw new InvalidArgumentException('Cable no longer exists in topology.');
        tp_physical_port($row['port_a'],$unitId);tp_physical_port($row['port_b'],$unitId);
        tp_exec('DELETE FROM plugin_topology_cables WHERE id=?',array($row['id']));tp_audit('disconnect_physical_ports',$row['id']);
    });
}
function tp_layout_save($unitId,$revision,$positions) {
    return tp_physical_write(function()use($unitId,$revision,$positions){
        $hosts=tp_physical_hosts($unitId);$unitId=tp_id($unitId);$revision=tp_id($revision,true);
        if(!is_array($positions)||count($positions)>500) throw new InvalidArgumentException('Invalid topology layout.');
        tp_exec('INSERT IGNORE INTO plugin_topology_scenes (unit_id,revision) VALUES (?,0)',array($unitId));
        if((int)db_fetch_cell_prepared('SELECT revision FROM plugin_topology_scenes WHERE unit_id=?',array($unitId))!==$revision) throw new RuntimeException('The layout changed in another session. Reload before editing again.');
        $seen=array();
        foreach($positions as $p) {
            if(!is_array($p)) throw new InvalidArgumentException('Invalid position.');
            $id=tp_id($p['id']??'');$x=tp_id($p['x']??'',true);$y=tp_id($p['y']??'',true);
            if(!isset($hosts[$id])||isset($seen[$id])||$x>2160||$y>1100) throw new InvalidArgumentException('Position is outside topology or canvas.');
            $seen[$id]=true;
            tp_exec('INSERT INTO plugin_topology_positions (unit_id,host_id,x,y) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE x=VALUES(x),y=VALUES(y)',array($unitId,$id,$x,$y));
        }
        tp_exec('UPDATE plugin_topology_scenes SET revision=revision+1 WHERE unit_id=?',array($unitId));tp_audit('save_node_layout',$unitId);return $revision+1;
    });
}
/** Send only display fields to the browser, never a native SNMP credential row. */
function tp_canvas_data($unitId,$category=0) {
    $hosts=tp_physical_hosts($unitId);if($category)$hosts=array_filter($hosts,function($h)use($category){return (int)$h['category_id']===$category;});$nodes=array();$ports=array();$i=0;
    $simulated=array_fill_keys(array_column(db_fetch_assoc('SELECT host_id FROM plugin_topology_imports WHERE host_id>0'),'host_id'),true);
    $positions=array_column(db_fetch_assoc_prepared('SELECT * FROM plugin_topology_positions WHERE unit_id=?',array($unitId)),null,'host_id');
    foreach($hosts as $id=>$h) {
        $pos=$positions[$id]??array('x'=>40+($i%6)*350,'y'=>40+(int)floor($i/6)*330);$i++;
        $list=array();
        foreach(db_fetch_assoc_prepared('SELECT id,label,connector,if_index FROM plugin_topology_ports WHERE host_id=? ORDER BY ordinal,id',array($id)) as $p) {$p['id']=(int)$p['id'];$list[]=$p;$ports[$p['id']]=true;}
        $nodes[]=array('id'=>$id,'name'=>$h['description'],'simulated'=>isset($simulated[$id]),'category'=>$h['category']?:'Unclassified','categoryColor'=>$h['category_color']?:'#64748b','type'=>$h['device_type'],'role'=>$h['role'],'status'=>strip_tags(get_colored_device_status($h['disabled'],$h['status'])),'statusClass'=>tp_canvas_status_class($h),'x'=>min(2160,(int)$pos['x']),'y'=>min(1100,(int)$pos['y']),'ports'=>$list);
    }
    $connected=array();foreach(db_fetch_assoc_prepared('SELECT port_a,port_b FROM plugin_topology_cables WHERE unit_id=?',array($unitId)) as $c)foreach(array($c['port_a'],$c['port_b']) as $id)if(isset($ports[$id]))$connected[(int)$id]=(int)$id;
    $cables=array();
    foreach(db_fetch_assoc_prepared('SELECT id,port_a,port_b FROM plugin_topology_cables WHERE unit_id=?',array($unitId)) as $c) if(isset($ports[$c['port_a']],$ports[$c['port_b']])) $cables[]=array_map('intval',$c);
    return array('unitId'=>(int)$unitId,'revision'=>(int)db_fetch_cell_prepared('SELECT revision FROM plugin_topology_scenes WHERE unit_id=?',array($unitId)),'devices'=>$nodes,'cables'=>$cables,'connectedPorts'=>array_values($connected),'editable'=>api_user_realm_auth('host.php'));
}

/** Native device status constants, independent of translated display labels. */
function tp_canvas_status_class($host) {
    if($host['disabled']) return 'disabled';
    $states=array(HOST_UP=>'up',HOST_DOWN=>'down',HOST_RECOVERING=>'recovering',HOST_ERROR=>'error');
    return $states[(int)$host['status']]??'unknown';
}
