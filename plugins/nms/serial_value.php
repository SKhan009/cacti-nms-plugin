<?php
/** Cacti Script/Command input: <path_php_binary> -q <path_cacti>/plugins/nms/serial_value.php <host_id> <field_key> */
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
error_reporting(0);
ini_set('display_errors','0');
require __DIR__.'/../../include/cli_check.php';
require_once __DIR__.'/includes/database.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/diagnostics_queue.php';
require_once __DIR__.'/includes/configuration/monitoring.php';
try {
    if(!in_array(count($_SERVER['argv']),[3,5],true)) throw new InvalidArgumentException('Expected device ID and field key.');
    $host_id=nms_config_integer($_SERVER['argv'][1],1,16777215,'Device ID');
    $key=$_SERVER['argv'][2];
    if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$key)) throw new InvalidArgumentException('Invalid field key.');
    nms_diag_worker_database();
    if(!nms_database_ready() || (int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1) throw new RuntimeException('Plugin is not ready.');
    $model=isset($_SERVER['argv'][3])?nms_config_integer($_SERVER['argv'][3],1,2147483647,'Profile ID'):null;
    $revision=isset($_SERVER['argv'][4])?nms_config_integer($_SERVER['argv'][4],1,2147483647,'Revision'):null;
    print nms_config_graph_value($host_id,$key,nms_inventory_collector_id(),$model,$revision).PHP_EOL;
} catch(Throwable $e) { print 'U'.PHP_EOL; }
