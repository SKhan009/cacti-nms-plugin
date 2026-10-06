<?php
/** Lifecycle-only schema creation. Ordinary requests never create or repair tables. */
function icct_nms_schema_install()
{
    $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '' && !db_execute($statement)) {
            throw new RuntimeException(
                'ICCT NMS schema installation failed. Check Cacti database logs.'
            );
        }
    }
    foreach (['severity_codes','facility_codes','match_strings'] as $column) {
        if (!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_syslog_devices LIKE '".$column."'")) {
            if(!db_execute("ALTER TABLE plugin_icct_nms_syslog_devices ADD `".$column."` TEXT DEFAULT NULL"))throw new RuntimeException('Syslog filter upgrade failed.');
        }
    }
    icct_nms_schema_diagnostic_mtr_migration();
    icct_nms_schema_mtr_background_migration();
    icct_nms_schema_sites_migration();
    icct_nms_schema_rack_catalogue_migration();
    icct_nms_schema_missing_preset_racks();
    if (
        !db_execute_prepared(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['icct_nms_schema_version', '1.2.8']
        )
    ) {
        throw new RuntimeException('ICCT NMS schema version could not be saved.');
    }
}

/** Lifecycle migration from the retired grouping feature to native site ownership. */
function icct_nms_schema_sites_migration() {
    $legacy=(bool)db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_racks LIKE 'node_id'");
    if(!$legacy)return;
    $groups=array_column(db_fetch_assoc('SELECT id,site_id FROM plugin_icct_nms_rack_nodes'), 'site_id','id');
    if(!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_racks LIKE 'site_id'"))icct_nms_schema_execute("ALTER TABLE plugin_icct_nms_racks ADD site_id INT UNSIGNED NOT NULL DEFAULT 0, ADD profile_id VARCHAR(16) NOT NULL DEFAULT ''");
    icct_nms_schema_execute("UPDATE plugin_icct_nms_racks r JOIN plugin_icct_nms_rack_nodes n ON n.id=r.node_id LEFT JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('node_rack_profile_',n.id) SET r.site_id=n.site_id,r.profile_id=COALESCE(m.meta_value,'')");
    if(db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_racks WHERE site_id=0'))throw new RuntimeException('A legacy rack has no site. Restore its site before upgrading.');
    foreach(db_fetch_assoc("SELECT meta_key,meta_value FROM plugin_icct_nms_meta WHERE meta_key LIKE 'dashboard_user_%'") as $row){
        $value=json_decode($row['meta_value'],true);if(!is_array($value))continue;
        $convert=static function($dashboard)use($value,$groups){
            if(!isset($dashboard['site_id']))$dashboard['site_id']=($value['node_scope']??'')==='preset'?(int)($groups[(int)($dashboard['node_id']??0)]??0):(int)($dashboard['node_id']??0);
            unset($dashboard['node_id']);return $dashboard;
        };
        $value['dashboards']=array_map($convert,$value['dashboards']??[]);
        if(isset($value['deleted']['dashboard']))$value['deleted']['dashboard']=$convert($value['deleted']['dashboard']);
        unset($value['node_scope']);$value['site_scope']='native';
        if(!db_execute_prepared('UPDATE plugin_icct_nms_meta SET meta_value=? WHERE meta_key=?',[json_encode($value,JSON_THROW_ON_ERROR),$row['meta_key']]))throw new RuntimeException('Dashboard migration failed.');
    }
    icct_nms_schema_execute("DELETE FROM plugin_icct_nms_meta WHERE meta_key LIKE 'device_node_id_%' OR meta_key LIKE 'node_rack_profile_%'");
    icct_nms_schema_execute('ALTER TABLE plugin_icct_nms_racks DROP INDEX node_rack, DROP COLUMN node_id, ADD KEY site_profile(site_id,profile_id,rack_number)');
    icct_nms_schema_execute('DROP TABLE plugin_icct_nms_rack_nodes');
}
function icct_nms_schema_execute($sql){if(!db_execute($sql))throw new RuntimeException('Site/rack migration failed. Check the Cacti database log.');}

/** Repair presets saved by older releases even after the catalogue migration ran.
 * Only create missing physical racks; existing IDs, capacity and placements stay intact.
 * Called by installation/upgrade, never by ordinary page reads.
 */
function icct_nms_schema_missing_preset_racks() {
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Rack upgrade is busy. Retry shortly.');
    try {
        icct_nms_schema_execute('START TRANSACTION');
        $raw=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_profiles']);
        $profiles=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];
        foreach($profiles as $key=>$profile) {
            if(db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=? LIMIT 1',[$key]))continue;
            if(!is_string($key)||!preg_match('/^[a-f0-9]{16}$/D',$key)||!is_array($profile)||!is_string($profile['name']??null)||trim($profile['name'])===''||strlen($profile['name'])>600||filter_var($profile['unit_count']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]])===false)throw new RuntimeException('A saved rack preset is invalid. Correct its name and capacity in Presets before upgrading.');
            if(!db_execute_prepared('INSERT INTO plugin_icct_nms_racks(site_id,profile_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(0,?,1,?,?,0,NOW())',[$key,$profile['name'],(int)$profile['unit_count']]))throw new RuntimeException('Saved rack presets could not be synchronized. Check the Cacti database log.');
        }
        icct_nms_schema_execute('COMMIT');
    } catch(Throwable $failure) {db_execute('ROLLBACK');throw $failure;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Convert legacy site/group copies to one catalogue row per physical rack.
 * Keep occupied rack IDs, reservations and placements; remove only empty copies.
 */
function icct_nms_schema_rack_catalogue_migration() {
    if(db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_catalogue_v1']))return;
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Rack upgrade is busy. Retry shortly.');
    $execute=static function($sql,$args=[]) { if(!db_execute_prepared($sql,$args))throw new RuntimeException('Rack catalogue upgrade failed.'); };
    try {
        $execute('START TRANSACTION');
        $raw=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_profiles']);
        $profiles=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];
        $racks=db_fetch_assoc('SELECT * FROM plugin_icct_nms_racks ORDER BY id');
        $execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['rack_catalogue_backup',json_encode(['profiles'=>$profiles,'racks'=>$racks],JSON_THROW_ON_ERROR)]);
        $groups=[];
        foreach($racks as $rack)$groups[$rack['profile_id']][]=$rack;
        $names=array_map(static fn($p)=>strtolower($p['name']),$profiles);
        foreach($groups as $key=>$copies) {
            $occupied=[];
            foreach($copies as $rack) {
                $used=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_rack_devices WHERE rack_id=?',[$rack['id']]) || (int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE (meta_key LIKE 'rack_peripheral_%' AND meta_value=?) OR (meta_key=? AND meta_value!='[]')",[(string)$rack['id'],'rack_reserved_'.$rack['id']]);
                if($used)$occupied[]=$rack;
            }
            $keep=$occupied ?: [$copies[0]];
            $kept=array_column($keep,'id');
            foreach($copies as $rack)if(!in_array($rack['id'],$kept,true)) {
                $execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_reserved_'.$rack['id']]);
                $execute('DELETE FROM plugin_icct_nms_racks WHERE id=?',[$rack['id']]);
            }
            foreach($keep as $number=>$rack) {
                $profileKey=$number===0&&isset($profiles[$key])?$key:bin2hex(random_bytes(8));
                $base=$profiles[$key]['name'] ?? $rack['name']; $name=$base;
                if($profileKey!==$key) {
                    $suffix=2;
                    while(in_array(strtolower($name),$names,true))$name=mb_substr($base,0,135).' '.$suffix++;
                    $names[] = strtolower($name);
                }
                $profiles[$profileKey]=['name'=>$name,'rack_count'=>1,'unit_count'=>(int)($profiles[$key]['unit_count'] ?? $rack['unit_count'])];
                $execute('UPDATE plugin_icct_nms_racks SET site_id=0,profile_id=?,rack_number=1,name=?,unit_count=? WHERE id=?',[$profileKey,$name,$profiles[$profileKey]['unit_count'],$rack['id']]);
            }
        }
        foreach($profiles as $key=>&$profile) {
            $profile['rack_count']=1;
            if(!db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=? LIMIT 1',[$key]))$execute('INSERT INTO plugin_icct_nms_racks(site_id,profile_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(0,?,1,?,?,0,NOW())',[$key,$profile['name'],$profile['unit_count']]);
        } unset($profile);
        $execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',json_encode($profiles,JSON_THROW_ON_ERROR)]);
        $execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['rack_catalogue_v1','1']);
        $execute('COMMIT');
    } catch(Throwable $failure) {$execute('ROLLBACK');throw $failure;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Preserve legacy MTR cycle counts when giving MTR its own device setting. */
function icct_nms_schema_diagnostic_mtr_migration(){
    if(!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_diagnostic_profiles LIKE 'mtr_cycles'")){
        if(!db_execute('ALTER TABLE plugin_icct_nms_diagnostic_profiles ADD mtr_cycles TINYINT UNSIGNED NOT NULL DEFAULT 4')||!db_execute('UPDATE plugin_icct_nms_diagnostic_profiles SET mtr_cycles=ping_count'))throw new RuntimeException('MTR settings upgrade failed.');
    }
}

/** Background MTR settings are private to each device diagnostic assignment. */
function icct_nms_schema_mtr_background_migration() {
    foreach ([
        ['diagnostic_profiles','mtr_background','TINYINT UNSIGNED NOT NULL DEFAULT 0'],
        ['diagnostic_profiles','mtr_interval','SMALLINT UNSIGNED NOT NULL DEFAULT 300'],
        ['diagnostic_jobs','is_background','TINYINT UNSIGNED NOT NULL DEFAULT 0'],
    ] as [$table,$column,$definition]) {
        if (!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_".$table." LIKE '".$column."'")) {
            icct_nms_schema_execute("ALTER TABLE plugin_icct_nms_".$table." ADD ".$column." ".$definition);
        }
    }
}
