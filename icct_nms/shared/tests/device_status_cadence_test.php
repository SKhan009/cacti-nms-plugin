<?php
require __DIR__.'/../services/backend/functions.php';
define('HOST_UNKNOWN',0);define('HOST_DOWN',1);define('HOST_RECOVERING',2);define('HOST_UP',3);define('HOST_ERROR',4);
$options=['poller_interval'=>10,'cron_interval'=>300];$nativeStep=300;$queries=0;$serial=null;
function read_config_option($name){return $GLOBALS['options'][$name]??'';}
function db_fetch_cell_prepared($sql,$args){$GLOBALS['queries']++;return $GLOBALS['nativeStep'];}
function icct_backend_config_connection_status($id){return $GLOBALS['serial'];}
$host=['id'=>1,'status'=>HOST_UP,'disabled'=>'','last_updated'=>date('Y-m-d H:i:s',time()-240),'polling_step'=>300];
if(icct_backend_device_status_name($host)!=='Up'||$queries)throw new RuntimeException('Five-minute profile falsely stale under ten-second poller');
if(icct_backend_device_status_name(array_replace($host,['polling_step'=>10]))!=='Stale')throw new RuntimeException('Fast profile retained genuinely old status');
$uncached=$host;unset($uncached['polling_step']);
if(icct_backend_device_status_name($uncached)!=='Up'||$queries!==1)throw new RuntimeException('Single-device view did not use native scheduled cadence');
if(icct_backend_device_status_name(array_replace($host,['last_updated'=>date('Y-m-d H:i:s',time()-700)]))!=='Stale')throw new RuntimeException('Expired five-minute status retained');
if(icct_backend_device_status_name(array_replace($host,['last_updated'=>date('Y-m-d H:i:s',time()+60)]))!=='Stale')throw new RuntimeException('Future timestamp accepted');
if(icct_backend_device_status_name(array_replace($host,['status'=>HOST_DOWN]))!=='Down')throw new RuntimeException('Native down status masked');
if(icct_backend_device_status_name(array_replace($host,['disabled'=>'on']))!=='Disabled')throw new RuntimeException('Disabled priority changed');
if(icct_backend_device_status_name(array_replace($host,['last_updated'=>'0000-00-00 00:00:00']))!=='Pending')throw new RuntimeException('Never-polled device not pending');
if(icct_backend_device_status_name(array_replace($host,['polling_step'=>null]))!=='Up')throw new RuntimeException('Scheduler fallback ignored');
$options['cron_interval']=60;
if(icct_backend_device_status_name(array_replace($host,['polling_step'=>null]))!=='Stale')throw new RuntimeException('Scheduler fallback did not adapt');
$serial=['status'=>'Unavailable'];if(icct_backend_device_status_name($host)!=='Unavailable')throw new RuntimeException('Serial status overridden');
echo "PASS: ten-second poller with mixed native profiles, per-device lookup, expiration, native down/disabled/pending, scheduler fallback and serial status.\n";
