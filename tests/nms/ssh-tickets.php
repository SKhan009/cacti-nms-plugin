<?php
/** CLI component regression: real Cacti ACLs, temporary tables, no SSH or credential I/O. */
if (PHP_SAPI !== 'cli') exit(1);
$bootstrap=getenv('NMS_CACTI_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('Set NMS_CACTI_BOOTSTRAP.');
define('IN_CACTI_INSTALL',true);
require $bootstrap;
api_plugin_load_realms();
require_once __DIR__.'/../../plugins/nms/includes/functions.php';
require_once __DIR__.'/../../plugins/nms/includes/ssh.php';
require_once __DIR__.'/../../plugins/nms/ssh/vendor/autoload.php';
require_once __DIR__.'/../../plugins/nms/ssh/runtime.php';
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function rejected($operation,$expected) {
    try { $operation(); } catch (RuntimeException $e) {
        check(strpos($e->getMessage(),$expected)!==false,'Unexpected rejection: '.$e->getMessage()); return;
    }
    throw new RuntimeException('Request was not rejected: '.$expected);
}
$nativeUser=db_fetch_row('SELECT * FROM user_auth WHERE id=1');
check($nativeUser && $nativeUser['enabled']==='on','Requires enabled QA administrator');
foreach(['user_auth','plugin_nms_ssh_sessions'] as $table) {
    $ddl=db_fetch_row('SHOW CREATE TABLE '.$table);
    check(db_execute(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl['Create Table'])),'Cannot isolate '.$table);
}
check(db_execute_prepared('INSERT INTO user_auth (`'.implode('`,`',array_keys($nativeUser)).'`) VALUES ('.implode(',',array_fill(0,count($nativeUser),'?')).')',array_values($nativeUser)),'Cannot seed isolated account');
$dir=sys_get_temp_dir().'/nms-ssh-ticket-'.bin2hex(random_bytes(8));
check(mkdir($dir,0700),'Cannot create fixture directory');
$config['nms_ssh_config']=$dir.'/config.json';
file_put_contents($config['nms_ssh_config'],json_encode(['control_socket'=>$dir.'/control.sock','store_dir'=>$dir.'/unused-store','master_key'=>$dir.'/unused-key','origin'=>'https://qa.invalid','poller_id'=>1,'guacd_listen'=>'127.0.0.1:4822']));
chmod($config['nms_ssh_config'],0600);
if (session_status()===PHP_SESSION_NONE) session_id(bin2hex(random_bytes(16)));
$_SESSION=['sess_user_id'=>1];
$_SERVER['HTTPS']='on'; $_SERVER['HTTP_ORIGIN']='https://qa.invalid';
try {
    // Credential tickets exercise the common admission path without a real device or stored secret.
    $ticket=nms_ssh_ticket('credential');
    $row=db_fetch_row_prepared('SELECT * FROM plugin_nms_ssh_sessions WHERE id=?',[$ticket['id']]);
    check($row['ticket_hash']===hash('sha256',$ticket['token']) && $row['ticket_hash']!==$ticket['token'],'Plain ticket stored');
    rejected(function() use($ticket) { nms_ssh_consume(array_replace($ticket,['token'=>str_repeat('0',64)]),'rpc'); },'expired, consumed or invalid');
    nms_ssh_consume($ticket,'rpc');
    check(db_fetch_cell_prepared('SELECT ticket_hash FROM plugin_nms_ssh_sessions WHERE id=?',[$ticket['id']])==='','Consumed token retained');
    rejected(function() use($ticket) { nms_ssh_consume($ticket,'rpc'); },'expired, consumed or invalid');
    $expired=nms_ssh_ticket('credential');
    db_execute_prepared('UPDATE plugin_nms_ssh_sessions SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$expired['id']]);
    rejected(function() use($expired) { nms_ssh_consume($expired,'rpc'); },'expired, consumed or invalid');
    $revoked=nms_ssh_ticket('credential');
    db_execute("UPDATE user_auth SET enabled='' WHERE id=1");
    rejected(function() use($revoked) { nms_ssh_consume($revoked,'rpc'); },'account disabled');
    db_execute("UPDATE user_auth SET enabled='on' WHERE id=1");
    $console=nms_ssh_ticket('credential');
    db_execute_prepared("UPDATE plugin_nms_ssh_sessions SET kind='console' WHERE id=?",[$console['id']]);
    rejected(function() use($console) { nms_ssh_consume($console,'rpc'); },'expired, consumed or invalid');
    rejected(function() use($console) { nms_ssh_consume($console,'console'); },'another browser session');
    $_SERVER['HTTPS']='off';
    rejected(function() { nms_ssh_ticket('credential'); },'require HTTPS');
    $_SERVER['HTTPS']='on'; $_SERVER['HTTP_ORIGIN']='https://wrong.invalid';
    rejected(function() { nms_ssh_ticket('credential'); },'origin rejected');
    echo "PASS: SSH ticket hashing, one-time consumption, wrong token, expiry, revoked account, RPC/console separation, browser binding and HTTPS/origin admission; temporary tables, no credentials or network I/O\n";
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_abort();
    unlink($config['nms_ssh_config']); rmdir($dir);
}
