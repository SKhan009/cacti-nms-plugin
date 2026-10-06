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
    icct_nms_schema_sites_migration();
    if (
        !db_execute_prepared(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['icct_nms_schema_version', '1.2.0']
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
