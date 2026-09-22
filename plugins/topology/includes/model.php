<?php
require_once(__DIR__.'/flat.php');
/** Minimal plugin metadata. Cacti owns Sites, Devices, protocol settings and all graph history. */

/** Reject failed database writes without exposing SQL or credentials. */
function tp_exec($sql, $params = array()) {
    if (db_execute_prepared($sql, $params) === false) throw new RuntimeException('Topology database operation failed. Check the Cacti log.');
}
/** Save a core object through the same helper used by native Cacti editors. */
function tp_core_save($row, $table) {
    $id = (int) sql_save($row, $table);
    if (!$id) throw new RuntimeException('Cacti could not save the ' . $table . ' object.');
    return $id;
}
/** Install additive tables with explicit ownership; no foreign Cacti table is altered. */
function tp_schema() {
    $tables = array(
        'units' => 'site_id INT UNSIGNED NOT NULL PRIMARY KEY, kind VARCHAR(12) NOT NULL, updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL',
        'categories' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL, active TINYINT NOT NULL DEFAULT 1, UNIQUE KEY name (name)',
        'devices' => 'host_id MEDIUMINT UNSIGNED NOT NULL PRIMARY KEY, category_id INT UNSIGNED NOT NULL, device_type VARCHAR(100) NOT NULL, role VARCHAR(100) NOT NULL, protocol VARCHAR(12) NOT NULL, updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL',
        'imports' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, community VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, site_id INT UNSIGNED NOT NULL, category_id INT UNSIGNED NOT NULL, content MEDIUMTEXT NOT NULL, digest CHAR(64) NOT NULL, state VARCHAR(20) NOT NULL, host_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, template_id INT UNSIGNED NOT NULL DEFAULT 0, record_count INT UNSIGNED NOT NULL, metric_count INT UNSIGNED NOT NULL, created_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY community (community), UNIQUE KEY digest (digest)',
        'objects' => 'import_id INT UNSIGNED NOT NULL, object_type VARCHAR(24) NOT NULL, object_id INT UNSIGNED NOT NULL, oid VARCHAR(512) NOT NULL DEFAULT "", PRIMARY KEY (object_type,object_id), KEY import_id (import_id)',
        'audit' => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, action VARCHAR(64) NOT NULL, object_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL',
        'discovery_sites' => 'site_id INT UNSIGNED NOT NULL PRIMARY KEY, enabled TINYINT NOT NULL, interval_seconds INT UNSIGNED NOT NULL, stale_seconds INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL, last_queued DATETIME NULL',
        'jobs' => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, state VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL DEFAULT "", requested_at DATETIME NOT NULL, started_at DATETIME NULL, finished_at DATETIME NULL, KEY queue (state,id), KEY site (site_id,id)',
        'snapshots' => 'host_id MEDIUMINT UNSIGNED NOT NULL, protocol VARCHAR(8) NOT NULL, site_id INT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, error VARCHAR(255) NOT NULL DEFAULT "", attempted_at DATETIME NOT NULL, succeeded_at DATETIME NULL, config_hash CHAR(64) NOT NULL, data_json MEDIUMTEXT NOT NULL, PRIMARY KEY (host_id,protocol), KEY site (site_id)'
    );
    foreach ($tables as $name=>$definition) tp_exec('CREATE TABLE IF NOT EXISTS plugin_topology_'.$name.' ('.$definition.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    tp_schema_units();
    require_once(__DIR__.'/physical.php');
    tp_physical_schema();
    require_once(__DIR__.'/discovery_profiles.php');
    tp_discovery_profiles_schema();
    tp_flat_schema();
    foreach(array('auto_provision'=>'TINYINT NOT NULL DEFAULT 0','attempts'=>'INT UNSIGNED NOT NULL DEFAULT 0','last_attempt'=>'DATETIME NULL','last_error'=>'VARCHAR(255) NOT NULL DEFAULT ""') as $column=>$definition)
        if(!tp_column_exists('plugin_topology_imports',$column))tp_exec('ALTER TABLE plugin_topology_imports ADD '.$column.' '.$definition);

}
/** Read migration metadata without Cacti's per-request negative-result cache. */
function tp_column_exists($table,$column) {return (bool)db_fetch_row('SHOW COLUMNS FROM '.$table.' LIKE "'.$column.'"');}
function tp_index_exists($table,$index) {return (bool)db_fetch_row('SHOW INDEX FROM '.$table.' WHERE Key_name="'.$index.'"');}
/** Upgrade the old one-Site/one-unit mapping while preserving legacy IDs and evidence. */
function tp_schema_units() {
    $legacy=version_compare((string)(db_fetch_cell('SELECT version FROM plugin_config WHERE directory="topology"') ?: '0'),'0.4.0','<');
    if(!tp_column_exists('plugin_topology_units','id')) {
        tp_exec('ALTER TABLE plugin_topology_units ADD id INT UNSIGNED NOT NULL DEFAULT 0');
    }
    $siteColumn=db_fetch_row('SHOW COLUMNS FROM plugin_topology_units LIKE "site_id"');
    if($siteColumn['Key']==='PRI') {
        tp_exec('UPDATE plugin_topology_units SET id=site_id');
        tp_exec('ALTER TABLE plugin_topology_units DROP PRIMARY KEY, ADD PRIMARY KEY(id), MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
    }
    if(!tp_column_exists('plugin_topology_units','name')) tp_exec('ALTER TABLE plugin_topology_units ADD name VARCHAR(100) NOT NULL DEFAULT ""');
    tp_exec('UPDATE plugin_topology_units SET kind="node" WHERE kind<>"node"');
    tp_exec('UPDATE plugin_topology_units u JOIN sites s ON s.id=u.site_id SET u.name=LEFT(s.name,100) WHERE u.name=""');
    if(!tp_index_exists('plugin_topology_units','location_name')) tp_exec('ALTER TABLE plugin_topology_units ADD UNIQUE KEY location_name (site_id,name)');
    foreach(array('devices','imports') as $table) if(!tp_column_exists('plugin_topology_'.$table,'unit_id')) tp_exec('ALTER TABLE plugin_topology_'.$table.' ADD unit_id INT UNSIGNED NOT NULL DEFAULT 0, ADD KEY unit_id (unit_id)');
    foreach(array('discovery_sites','jobs','snapshots') as $table) if(!tp_column_exists('plugin_topology_'.$table,'unit_id')) tp_exec('ALTER TABLE plugin_topology_'.$table.' CHANGE site_id unit_id INT UNSIGNED NOT NULL');
    if($legacy) {
        // Legacy units retain their Site IDs, so schedules, jobs and snapshots keep their exact scope.
        tp_exec('UPDATE plugin_topology_devices d JOIN host h ON h.id=d.host_id JOIN plugin_topology_units u ON u.id=h.site_id SET d.unit_id=u.id WHERE d.unit_id=0');
        tp_exec('INSERT IGNORE INTO plugin_topology_devices (host_id,category_id,device_type,role,protocol,updated_by,updated_at,unit_id) SELECT h.id,0,"","","none",u.updated_by,NOW(),u.id FROM host h JOIN plugin_topology_units u ON u.id=h.site_id WHERE h.deleted=""');
        tp_exec('UPDATE plugin_topology_imports i JOIN plugin_topology_units u ON u.id=i.site_id SET i.unit_id=u.id WHERE i.unit_id=0');
        require_once(__DIR__.'/discovery.php');
        foreach(db_fetch_assoc('SELECT s.host_id,s.protocol,s.config_hash,s.unit_id AS snapshot_unit,h.*,d.unit_id FROM plugin_topology_snapshots s JOIN host h ON h.id=s.host_id JOIN plugin_topology_devices d ON d.host_id=h.id') as $host) {
            $previous=array();
            foreach(array('hostname','site_id','poller_id','disabled','snmp_version','snmp_community','snmp_username','snmp_password','snmp_auth_protocol','snmp_priv_passphrase','snmp_priv_protocol','snmp_context','snmp_engine_id','snmp_port','snmp_timeout') as $key) $previous[$key]=$host[$key];
            if((int)$host['snapshot_unit']===(int)$host['unit_id'] && hash_equals($host['config_hash'],hash('sha256',json_encode($previous,JSON_THROW_ON_ERROR)))) tp_exec('UPDATE plugin_topology_snapshots SET config_hash=? WHERE host_id=? AND protocol=?',array(tp_discovery_hash($host),$host['host_id'],$host['protocol']));
        }
    }
}
/** Fail explicitly when installation is missing; no runtime schema repair. */
function tp_ready() {
    if (!api_plugin_is_enabled('topology')) throw new RuntimeException('Enable Topology in Cacti Plugin Management.');
    foreach (array('units','categories','devices','imports','objects','audit','discovery_sites','jobs','snapshots','port_profiles','ports','cables','positions','scenes','discovery_profiles') as $name) {
        if (!db_table_exists('plugin_topology_'.$name)) throw new RuntimeException('Topology schema is incomplete. Run the plugin upgrade from Plugin Management.');
    }
    if(!db_table_exists('plugin_topology_meta') || !db_fetch_cell('SELECT value FROM plugin_topology_meta WHERE name="flat_v1"')) throw new RuntimeException('Run the Topology plugin upgrade to complete the simplified setup.');
    if(!tp_column_exists('plugin_topology_units','name') || !tp_column_exists('plugin_topology_snapshots','unit_id')) throw new RuntimeException('Run the Topology plugin upgrade to complete the current schema.');
}
/** Validate literal IDs without coercing malformed input into valid records. */
function tp_id($value, $zero = false) {
    if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value) || (float)$value > 2147483647 || (!$zero && (int)$value < 1)) throw new InvalidArgumentException('Select a valid record.');
    return (int)$value;
}
/** Validate human-entered labels without truncation or hidden substitutions. */
function tp_text($value, $max = 100, $required = true) {
    if (!is_string($value)) throw new InvalidArgumentException('Invalid text field.');
    $value = trim($value);
    if (($required && $value === '') || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/', $value) || mb_strlen($value) > $max) throw new InvalidArgumentException('Enter valid text of at most '.$max.' characters.');
    return $value;
}
/** Require a particular native management realm for writes that touch core objects. */
function tp_require_core($page) {
    if (!api_user_realm_auth($page)) throw new RuntimeException('Your Cacti account is not permitted to manage '.$page.'.');
}
/** Site managers may configure empty native Sites; other users see only Sites of permitted devices. */
function tp_sites() {
    if(api_user_realm_auth('sites.php')) return db_fetch_assoc('SELECT id,name FROM sites ORDER BY name');
    $ids=array();
    foreach(get_allowed_devices() as $host) $ids[(int)$host['site_id']]=true;
    return array_values(array_filter(db_fetch_assoc('SELECT id,name FROM sites ORDER BY name'),function($s)use($ids){return isset($ids[(int)$s['id']]);}));
}
/** Resolve a visible native site; no site is selected implicitly. */
function tp_site($id) {
    $id = tp_id($id);
    $sites = tp_sites();
    $found = false;
    foreach ($sites as $site) if ((int)$site['id'] === $id) $found = true;
    if (!$found) throw new InvalidArgumentException('Select an accessible Cacti Site.');
    return $id;
}
/** List visible nodes without exposing siblings merely because they share a location. */
function tp_units() {return array(array('id'=>tp_scope_id(),'site_id'=>0,'name'=>'Topology','location'=>''));}
/** Legacy callers use the same internal scope; no node selection is exposed. */
function tp_unit($id) {
    if(tp_id($id)!==tp_scope_id())throw new InvalidArgumentException('Reload the topology page to use the current configuration.');
    return tp_units()[0];
}
/** Check native device visibility and existence before reading or changing assignments. */
function tp_host($id) {
    $id = tp_id($id);
    if (!is_device_allowed($id)) throw new RuntimeException('Device access denied.');
    $host = db_fetch_row_prepared('SELECT * FROM host WHERE id=? AND deleted=""', array($id));
    if (!$host) throw new InvalidArgumentException('The Cacti device no longer exists.');
    return $host;
}
/** Resolve an active category and reject archived or missing choices. */
function tp_category($id) {
    $id = tp_id($id);
    if (!db_fetch_cell_prepared('SELECT id FROM plugin_topology_categories WHERE id=? AND active=1', array($id))) throw new InvalidArgumentException('Select an active category.');
    return $id;
}
/** Record operator actions, excluding credentials and record contents. */
function tp_audit($action, $id) {
    tp_exec('INSERT INTO plugin_topology_audit (user_id,action,object_id,created_at) VALUES (?,?,?,NOW())', array((int)($_SESSION['sess_user_id'] ?? 0), $action, $id));
}
/** Create or update independent device segments, including reversible archive. */
function tp_category_save($id, $name, $description, $active, $color = null) {
    $id=tp_id($id,true); $name=tp_text($name); $description=tp_text($description,255,false);
    if (!in_array((string)$active,array('0','1'),true)) throw new InvalidArgumentException('Invalid category state.');
    if ($id && !db_fetch_cell_prepared('SELECT id FROM plugin_topology_categories WHERE id=?',array($id))) throw new InvalidArgumentException('Category no longer exists.');
    if (db_fetch_cell_prepared('SELECT id FROM plugin_topology_categories WHERE name=? AND id<>?',array($name,$id))) throw new InvalidArgumentException('This category name already exists.');
    if($color===null) $color=$id?db_fetch_cell_prepared('SELECT color FROM plugin_topology_categories WHERE id=?',array($id)):'#64748b';
    if(!is_string($color)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$color)) throw new InvalidArgumentException('Use a six-digit category color, such as #2563eb.');
    $id=tp_core_save(array('id'=>$id,'name'=>$name,'description'=>$description,'active'=>(int)$active,'color'=>strtolower($color)),'plugin_topology_categories');
    tp_audit('save_category',$id);
    return $id;
}
/** Assign classification to a device whose Site already identifies the node; never move core devices silently. */
function tp_assignment_save($id,$category,$type,$role,$protocol,$unitId) {
    tp_require_core('host.php');
    require_once(__DIR__.'/physical.php');
    return tp_physical_write(function()use($id,$category,$type,$role,$protocol,$unitId){return tp_assignment_write($id,$category,$type,$role,$protocol,$unitId);});
}
function tp_assignment_write($id,$category,$type,$role,$protocol,$unitId) {
    $host=tp_host($id);$unit=tp_unit($unitId);$category=tp_category($category);
    $type=tp_text($type); $role=tp_text($role,100,false);
    if (!in_array($protocol,array('none','lldp','cdp','both'),true)) throw new InvalidArgumentException('Select an explicit discovery protocol preference.');
    $old=(int)db_fetch_cell_prepared('SELECT unit_id FROM plugin_topology_devices WHERE host_id=?',array($host['id']));
    $previous=db_fetch_row_prepared('SELECT category_id FROM plugin_topology_devices WHERE host_id=?',array($host['id']));
    if($previous && ((int)$previous['category_id']!==$category || $old!==(int)$unit['id']) && db_fetch_cell_prepared('SELECT id FROM plugin_topology_ports WHERE host_id=? LIMIT 1',array($host['id']))) throw new RuntimeException('Clear this device’s physical ports before changing its category. Connected ports must be disconnected first.');

        tp_exec('INSERT INTO plugin_topology_devices (host_id,category_id,device_type,role,protocol,updated_by,updated_at,unit_id) VALUES (?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),device_type=VALUES(device_type),role=VALUES(role),protocol=VALUES(protocol),unit_id=VALUES(unit_id),updated_by=VALUES(updated_by),updated_at=NOW()',array($host['id'],$category,$type,$role,$protocol,(int)$_SESSION['sess_user_id'],$unit['id']));
        if($old!==(int)$unit['id']) tp_exec('UPDATE plugin_topology_snapshots SET config_hash="",status="failed",error="Topology assignment changed; run discovery again." WHERE host_id=?',array($host['id']));
        tp_audit('classify_device',$host['id']);
}
