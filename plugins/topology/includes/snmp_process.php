<?php
/** Private CLI child: one native SNMP operation, then exit to discard all library USM state. */
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
require(__DIR__.'/../../../include/cli_check.php');
require_once(__DIR__.'/discovery.php');
try {
    $raw=stream_get_contents(STDIN,16385);
    if(strlen($raw)>16384) throw new RuntimeException('Isolated request exceeds the size limit.');
    $request=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($request) || !isset($request['host'],$request['operation'],$request['deadline']) || microtime(true)>$request['deadline']) throw new RuntimeException('Invalid or expired isolated SNMP request.');
    if($request['operation']==='probe') $data=tp_probe_direct($request['host']);
    elseif(in_array($request['operation'],array('lldp','cdp'),true)) $data=tp_collect_direct($request['host'],$request['operation'],$request['deadline']);
    else throw new RuntimeException('Unknown isolated SNMP operation.');
    echo json_encode(array('ok'=>true,'data'=>$data),JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    echo json_encode(array('ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'Isolated SNMP collection failed.'),JSON_THROW_ON_ERROR);
}
