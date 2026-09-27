<?php
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2])||empty($argv[3]))exit('Supply Cacti root, diagnostics source and listener source');
require $argv[1].'/include/cli_check.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[2];
if(!db_execute('CREATE TEMPORARY TABLE plugin_nms_meta(meta_key VARCHAR(191) PRIMARY KEY,meta_value MEDIUMTEXT,updated_at DATETIME)'))throw new RuntimeException('Fixture failed');
if(!db_execute('CREATE TEMPORARY TABLE plugin_config(directory VARCHAR(64) PRIMARY KEY,status INT)') || !db_execute("INSERT INTO plugin_config VALUES ('nms',1)"))throw new RuntimeException('Plugin fixture failed');
$lock='nms_heartbeat_acceptance_'.getmypid();$key='diagnostic_runner_90001';$tools=['ping'=>true];
if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)throw new RuntimeException('Fixture lock failed');
$connection=(int)db_fetch_cell('SELECT CONNECTION_ID()');
$source=file_get_contents($argv[3]);$start=strpos($source,'$heartbeat = function');$end=strpos($source,'    try {',$start);eval(substr($source,$start,$end-$start));
try {
    $ticks=0;
    $result=nms_diag_run_command([PHP_BINARY,'-r','usleep(6500000);'],8,function()use($heartbeat,&$ticks,$key){$heartbeat();$ticks++;if((int)db_fetch_cell_prepared('SELECT TIMESTAMPDIFF(SECOND,updated_at,NOW()) FROM plugin_nms_meta WHERE meta_key=?',[$key])>1)throw new RuntimeException('Heartbeat stale');});
    if($result['exit']!==0 || $ticks<6)throw new RuntimeException('Long child did not refresh heartbeat');
    echo "PASS: Native SQL heartbeat refreshed during a worker longer than admission threshold.\n";
    db_execute("UPDATE plugin_config SET status=4 WHERE directory='nms'");
    $rejected=false;try{$heartbeat();}catch(RuntimeException $e){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Disabled plugin was ignored');
    echo "PASS: Disabled plugin prevents heartbeat publication.\n";
    db_execute("UPDATE plugin_config SET status=1 WHERE directory='nms'");
    db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);
    $rejected=false;try{$heartbeat();}catch(RuntimeException $e){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Lost lock was ignored');
    echo "PASS: Native lock loss rejects heartbeat publication.\n";
}finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
