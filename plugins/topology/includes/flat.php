<?php
/** One internal collection/layout scope; it is not an operator-configured node. */
function tp_scope_id() {return 2147483647;}
function tp_flat_schema() {
    tp_exec('CREATE TABLE IF NOT EXISTS plugin_topology_meta (name VARCHAR(64) PRIMARY KEY,value MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if(db_fetch_cell('SELECT value FROM plugin_topology_meta WHERE name="flat_v1"'))return;
    if(db_fetch_cell('SELECT id FROM plugin_topology_jobs WHERE state="running" LIMIT 1'))throw new RuntimeException('Finish the active discovery job before upgrading topology.');
    require_once(__DIR__.'/discovery.php');$scope=tp_scope_id();tp_exec('START TRANSACTION');
    try {
        $policies=db_fetch_assoc('SELECT * FROM plugin_topology_discovery_sites');
        tp_exec('INSERT INTO plugin_topology_meta (name,value) VALUES ("legacy_policies",?)',array(json_encode($policies,JSON_THROW_ON_ERROR)));
        // Existing coordinates are retained for each device's current assignment.
        tp_exec('INSERT IGNORE INTO plugin_topology_positions (unit_id,host_id,x,y) SELECT ?,p.host_id,p.x,p.y FROM plugin_topology_positions p JOIN plugin_topology_devices d ON d.host_id=p.host_id AND d.unit_id=p.unit_id',array($scope));
        tp_exec('INSERT IGNORE INTO plugin_topology_scenes (unit_id,revision) VALUES (?,1)',array($scope));
        foreach(db_fetch_assoc('SELECT h.*,d.unit_id FROM host h JOIN plugin_topology_devices d ON d.host_id=h.id') as $h) {
            $oldHash=tp_discovery_hash($h);$h['unit_id']=$scope;$newHash=tp_discovery_hash($h);
            tp_exec('UPDATE plugin_topology_snapshots SET config_hash=? WHERE host_id=? AND config_hash=?',array($newHash,$h['id'],$oldHash));
        }
        foreach(array('devices','imports','snapshots','jobs','cables') as $t)tp_exec('UPDATE plugin_topology_'.$t.' SET unit_id=?',array($scope));
        tp_exec('UPDATE plugin_topology_discovery_sites SET enabled=0');
        if($policies) {
            $choices=array();foreach($policies as $p)$choices[$p['enabled'].'|'.$p['interval_seconds'].'|'.$p['stale_seconds'].'|'.$p['updated_by']]=$p;
            if(count($choices)===1){$p=reset($choices);tp_exec('INSERT INTO plugin_topology_discovery_sites (unit_id,enabled,interval_seconds,stale_seconds,updated_by,updated_at,last_queued) VALUES (?,?,?,?,?,?,?)',array($scope,$p['enabled'],$p['interval_seconds'],$p['stale_seconds'],$p['updated_by'],$p['updated_at'],$p['last_queued']));}
        }
        tp_exec('INSERT INTO plugin_topology_meta (name,value) VALUES ("flat_v1","1")');tp_exec('COMMIT');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}
