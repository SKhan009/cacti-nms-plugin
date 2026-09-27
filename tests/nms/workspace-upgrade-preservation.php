<?php
/** Runs the native upgrade twice. Back up the database before invoking on an authorized QA installation. */
if(PHP_SAPI!=='cli'||empty($argv[1]))exit('Supply Cacti root after taking a backup');
require $argv[1].'/include/cli_check.php';
require_once $argv[1].'/plugins/nms/setup.php';
require_once $argv[1].'/plugins/nms/includes/database.php';
function qa_upgrade_rows($sql) {
    $rows=db_execute_prepared($sql,[],true,false,'Row',false,'db_fetch_assoc_return');
    if(!is_array($rows))throw new RuntimeException('Inventory read failed');
    return $rows;
}
function qa_upgrade_inventory() {
    return [
        'devices'=>qa_upgrade_rows('SELECT id,description,hostname,poller_id,site_id,host_template_id,disabled FROM host ORDER BY id'),
        'graphs'=>qa_upgrade_rows('SELECT id,host_id,graph_template_id,snmp_query_id,snmp_index FROM graph_local ORDER BY id'),
        'data_sources'=>qa_upgrade_rows('SELECT id,host_id,data_template_id,snmp_query_id,snmp_index FROM data_local ORDER BY id'),
        'rrd_metadata'=>qa_upgrade_rows('SELECT id,local_data_id,data_source_path,data_source_profile_id,rrd_step FROM data_template_data ORDER BY id'),
        'graph_data_links'=>qa_upgrade_rows('SELECT id,local_graph_id,task_item_id FROM graph_templates_item ORDER BY id'),
        'networks'=>qa_upgrade_rows('SELECT id,name,subnet_range,poller_id,site_id,snmp_id,enabled,add_to_cacti,sched_type,start_at,recur_every,day_of_week,month,day_of_month,monthly_week,monthly_day FROM automation_networks ORDER BY id'),
        'plugin_state'=>qa_upgrade_rows("SELECT directory,status FROM plugin_config WHERE directory='nms'")
    ];
}
$before=qa_upgrade_inventory();
$schema=file_get_contents($argv[1].'/plugins/nms/includes/workspace/schema.php');
preg_match_all('/CREATE TABLE IF NOT EXISTS (plugin_nms_[a-z_]+)/',$schema,$matches);
if(!$matches[1])throw new RuntimeException('No expected workspace tables');
for($pass=1;$pass<=2;$pass++) {
    if(!plugin_nms_upgrade()||!nms_database_ready())throw new RuntimeException('Upgrade did not report readiness');
    foreach($matches[1] as $table) {
        if((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])!==1)throw new RuntimeException('Workspace table missing after upgrade: '.$table);
    }
    $after=qa_upgrade_inventory();
    foreach($before as $kind=>$rows)if($after[$kind]!==$rows)throw new RuntimeException('Upgrade changed '.$kind);
    echo 'PASS: upgrade '.$pass.'; '.count($matches[1]).' workspace tables present; native device/graph/data/RRD metadata, links, network settings and plugin enablement preserved.'."\n";
}
echo "RRD metadata checked; file contents and hardware behavior are outside this upgrade test.\n";
