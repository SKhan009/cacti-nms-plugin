<?php
/** Native API acceptance on a temporary collector-loopback device; exact fixture cleanup. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged worker');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
$source=file_get_contents($argv[2]);$start=strpos($source,'function nms_workspace_onboarding_marker');$end=strpos($source,'/** Persist');eval(substr($source,$start,$end-$start));
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$host=db_fetch_row('SELECT * FROM host WHERE id=2');if(!$host || $host['hostname']!=='127.0.0.1' || (int)$host['poller_id']!==1)throw new RuntimeException('Expected QA loopback source missing');
$job=['id'=>999999901,'target'=>'127.0.0.1','poller_id'=>1,'site_id'=>$host['site_id'],'template_id'=>$host['host_template_id'],'description'=>'QA reviewed onboarding native loopback'];
$marker=nms_workspace_onboarding_marker($job['id']);
if(db_fetch_cell_prepared('SELECT id FROM host WHERE external_id=? OR description=?',[$marker,$job['description']]))throw new RuntimeException('Existing fixture requires review, not replacement');
$before=db_fetch_assoc('SELECT id,hostname,poller_id,site_id FROM host ORDER BY id');
$graphs=db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id');
$id=0;
try {
    $id=nms_workspace_onboarding_native_create($job,$host);
    $row=db_fetch_row_prepared('SELECT * FROM host WHERE id=?',[$id]);
    if(!$id || $row['external_id']!==$marker || $row['hostname']!=='127.0.0.1' || (int)$row['host_template_id']!==(int)$job['template_id'])throw new RuntimeException('Native API returned unexpected device');
    echo "PASS: native Cacti API created a loopback device with selected template and recovery reference.\n";
}finally {
    $fixture=db_fetch_row_prepared('SELECT id,hostname,description FROM host WHERE external_id=?',[$marker]);
    if($fixture) {
        if($fixture['hostname']!=='127.0.0.1' || $fixture['description']!==$job['description'])throw new RuntimeException('Fixture identity changed; refusing cleanup');
        // Native API removes the exact test device and its associated query/template references.
        api_device_remove((int)$fixture['id']);
    }
}
if($before!==db_fetch_assoc('SELECT id,hostname,poller_id,site_id FROM host ORDER BY id') || $graphs!==db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id'))throw new RuntimeException('Existing inventory/graph association changed; review required');
echo "PASS: exact fixture removed; existing devices and graph associations unchanged.\n";
