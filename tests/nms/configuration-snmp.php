<?php
/** Isolated localhost Net-SNMP fixture. Random credentials exist only in a private temporary config. */
if(PHP_SAPI!=='cli') exit(1);
require __DIR__.'/../../plugins/nms/includes/configuration/runner.php';
function check($condition,$message) { if(!$condition) throw new RuntimeException($message); }
$dir=sys_get_temp_dir().'/nms-snmp-qa-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
$socket=stream_socket_server('udp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND);
check(is_resource($socket),'Cannot reserve fixture port');
$address=stream_socket_get_name($socket,false); $port=(int)substr(strrchr($address,':'),1); fclose($socket);
$read=bin2hex(random_bytes(16)); $write=bin2hex(random_bytes(16));
$v3user="qa".bin2hex(random_bytes(8)); $v3auth=bin2hex(random_bytes(16)); $v3priv=bin2hex(random_bytes(16));
file_put_contents($dir.'/snmpd.conf',"agentAddress udp:127.0.0.1:$port\nrocommunity $read 127.0.0.1\nrwcommunity $write 127.0.0.1\ncreateUser $v3user SHA $v3auth AES $v3priv\nrwuser $v3user priv\n"); chmod($dir.'/snmpd.conf',0600);
$process=proc_open(['/usr/sbin/snmpd','-f','-Lo','-C','-c',$dir.'/snmpd.conf','-p',$dir.'/snmpd.pid'],[0=>['pipe','r'],1=>['file',$dir.'/log','a'],2=>['file',$dir.'/log','a']],$pipes,null,['SNMP_PERSISTENT_DIR'=>$dir,'MIBS'=>'']);
check(is_resource($process),'Cannot launch isolated SNMP fixture');
$field=['key'=>'location','label'=>'Fixture location','unit'=>'','type'=>'string','writable'=>true,'max_length'=>100,'oid'=>'.1.3.6.1.2.1.1.6.0'];
$target=['host'=>['id'=>1001,'hostname'=>'127.0.0.1','snmp_version'=>2,'snmp_port'=>$port,'snmp_timeout'=>200,'snmp_community'=>$read,'snmp_context'=>''],'assignment'=>['credential_ref'=>'fixture']];
$config['nms_configuration_credentials']['fixture']=['host_ids'=>[1001],'snmp_version'=>2,'snmp_community'=>$write];
try {
    $initial=null;
    for($attempt=0;$attempt<20;$attempt++) {
        $initial=nms_config_snmp_execute($target,$field,'read');
        if($initial['status']==='read') break;
        usleep(100000);
    }
    check($initial['status']==='read','SNMP fixture did not become readable');
    $changed=nms_config_snmp_execute($target,$field,'write',$initial['value'],'NMS QA verified');
    check($changed['status']==='verified' && $changed['observed']==='NMS QA verified','SNMP SET was not confirmed by read-back');
    $current=nms_config_snmp_execute($target,$field,'read');
    check($current['value']==='NMS QA verified','Independent GET did not confirm changed value');
    $stale=nms_config_snmp_execute($target,$field,'write',$initial['value'],'Must not be written');
    check($stale['status']==='failed','Stale before-value was allowed to write');
    check(nms_config_snmp_execute($target,$field,'read')['value']==='NMS QA verified','Rejected stale write changed fixture');
    $denied=$target; $denied['host']['id']=1002;
    $result=nms_config_snmp_execute($denied,$field,'write','NMS QA verified','Unauthorised');
    check($result['status']==='failed','Credential scope allowed a different device');
    $readonly=$target; $readonly['assignment']['credential_ref']='read_only';
    $config['nms_configuration_credentials']['read_only']=['host_ids'=>[1001],'snmp_version'=>2,'snmp_community'=>$read];
    $result=nms_config_snmp_execute($readonly,$field,'write','NMS QA verified','Rejected by agent');
    check($result['status']==='unverified' && $result['observed']==='NMS QA verified','Rejected SET was falsely verified');
    check(strpos(json_encode([$changed,$stale,$result]),$read)===false && strpos(json_encode([$changed,$stale,$result]),$write)===false,'Credential appeared in persisted result');
    $v3=$target;
    $v3['host']=array_merge($target['host'],['snmp_version'=>3,'snmp_username'=>$v3user,'snmp_auth_protocol'=>'SHA','snmp_password'=>$v3auth,'snmp_priv_protocol'=>'AES','snmp_priv_passphrase'=>$v3priv]);
    $v3['assignment']['credential_ref']='v3_fixture';
    $config['nms_configuration_credentials']['v3_fixture']=array_merge($v3['host'],['host_ids'=>[1001]]);
    $v3read=nms_config_snmp_execute($v3,$field,'read');
    check($v3read['status']==='read' && $v3read['value']==='NMS QA verified','SNMPv3 authPriv GET failed');
    $v3write=nms_config_snmp_execute($v3,$field,'write',$v3read['value'],'NMS QA v3 verified');
    check($v3write['status']==='verified' && $v3write['observed']==='NMS QA v3 verified','SNMPv3 SET/read-back failed');
    check(nms_config_snmp_execute($v3,$field,'read')['value']==='NMS QA v3 verified','SNMPv3 independent GET failed');
    $v3denied=$v3; $v3denied['host']['id']=1002;
    $v3failure=nms_config_snmp_execute($v3denied,$field,'write','NMS QA v3 verified','Unauthorised');
    check($v3failure['status']==='failed','SNMPv3 write credential scope bypassed');
    foreach([$v3user,$v3auth,$v3priv] as $secret) check(strpos(json_encode([$v3read,$v3write,$v3failure]),$secret)===false,'SNMPv3 credential appeared in result');
    echo "PASS: isolated SNMPv3 SHA/AES authPriv GET/SET/read-back, device scope and result redaction\n";
    echo "PASS: isolated Net-SNMP GET/SET/read-back, stale preview rejection, credential device scope, read-only SET rejection and result credential redaction\n";
} finally {
    foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
    proc_terminate($process); proc_close($process);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) { if($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($dir);
}
