<?php
/** Verify explicit service ownership without running a worker or changing Cacti. */
require __DIR__.'/../../plugins/nms/includes/diagnostics_queue.php';
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
$launches=[];
function exec_background($binary,$arguments) { global $launches; $launches[]=[$binary,$arguments]; }
if(PHP_OS_FAMILY!=='Linux') { echo "SKIP: Linux collector test\n"; exit; }
$dir=sys_get_temp_dir().'/nms-launcher-'.bin2hex(random_bytes(8));
mkdir($dir); mkdir($dir.'/lib'); file_put_contents($dir.'/lib/poller.php','<?php');
$config=['base_path'=>$dir];
try {
    nms_diag_dispatch();
    check(count($launches)===1,'Default poller stopped launching the listener');
    check($launches[0][0]===PHP_BINARY && strpos($launches[0][1],'diagnostic_listener.php')!==false,'Unexpected listener command');
    $config['nms_diagnostic_listener_launcher']='service';
    nms_diag_dispatch();
    check(count($launches)===1,'Service-managed collector launched a competing listener');
    $config['nms_diagnostic_listener_launcher']='invalid';
    $rejected=false;
    try { nms_diag_dispatch(); } catch(RuntimeException $e) { $rejected=true; }
    check($rejected && count($launches)===1,'Invalid launcher silently started a listener');
    $config['nms_diagnostic_listener_launcher']='poller';
    nms_diag_dispatch();
    check(count($launches)===2,'Explicit poller mode did not recover fallback behavior');
    echo "PASS: default/explicit poller launch, service ownership and invalid launcher rejection\n";
} finally { unlink($dir.'/lib/poller.php'); rmdir($dir.'/lib'); rmdir($dir); }
