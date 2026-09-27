<?php
/** Isolated loopback sshd; no saved credentials, production sessions or Cacti records. */
if(PHP_SAPI!=='cli' || PHP_OS_FAMILY!=='Linux' || posix_geteuid()!==0) {
    fwrite(STDERR,"Run this isolated RHEL fixture as root with NMS_SSH_TEST_USER set to an existing non-root login account.\n"); exit(1);
}
require __DIR__.'/../../plugins/nms/ssh/vendor/autoload.php';
require __DIR__.'/../../plugins/nms/ssh/strict_ssh.php';
require __DIR__.'/../../plugins/nms/includes/ssh.php';
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
function command(array $args) {
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($p),'Fixture command could not start'); fclose($pipes[0]);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($p)===0,'Fixture command failed');
}
$user=getenv('NMS_SSH_TEST_USER');
check(is_string($user) && preg_match('/^[a-z_][a-z0-9_-]*$/D',$user),'An explicit test login account is required');
$account=posix_getpwnam($user);
check($account && $account['uid']>0 && !preg_match('~(?:nologin|false)$~',$account['shell']),'Test account must be a non-root login account');
$dir='/run/nms-ssh-regression-'.bin2hex(random_bytes(8));
mkdir($dir,0711); chmod($dir,0711); // sshd must read the public authorized key as the login user.
$server=null; $ssh=null;
try {
    foreach(['host','client'] as $key) command(['/usr/bin/ssh-keygen','-q','-t','rsa','-b','2048','-N','','-f',$dir.'/'.$key]);
    copy($dir.'/client.pub',$dir.'/authorized_keys'); chmod($dir.'/authorized_keys',0644);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    check(is_resource($socket),'Cannot reserve local fixture port');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $configuration="ListenAddress 127.0.0.1\nPort $port\nHostKey $dir/host\nPidFile $dir/sshd.pid\nAuthorizedKeysFile $dir/authorized_keys\nAllowUsers $user\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nAuthenticationMethods publickey\nPermitRootLogin no\nUsePAM no\nStrictModes yes\nAllowTcpForwarding no\nAllowAgentForwarding no\nX11Forwarding no\nPermitTunnel no\nLogLevel VERBOSE\n";
    file_put_contents($dir.'/sshd.conf',$configuration); chmod($dir.'/sshd.conf',0600);
    command(['/usr/sbin/sshd','-t','-f',$dir.'/sshd.conf']);
    $server=proc_open(['/usr/sbin/sshd','-D','-e','-f',$dir.'/sshd.conf'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/log','a'],2=>['file',$dir.'/log','a']],$pipes);
    check(is_resource($server),'Cannot launch isolated sshd');
    $ready=false;
    for($i=0;$i<30;$i++) {
        $probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1);
        if($probe) { fclose($probe); $ready=true; break; }
        if(!proc_get_status($server)['running']) break;
        usleep(100000);
    }
    check($ready,'Isolated sshd did not start');
    $ssh=new NmsStrictSsh('127.0.0.1',$port,5);
    $actual=$ssh->getServerPublicHostKey();
    check(is_string($actual) && hash_equals(nms_ssh_fingerprint(file_get_contents($dir.'/host.pub')),nms_ssh_fingerprint($actual)),'Pinned host key mismatch');
    $key=phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey(file_get_contents($dir.'/client'));
    check($ssh->login($user,$key),'Explicit key authentication failed: '.substr((string)file_get_contents($dir.'/log'),-3000));
    $ssh->setTimeout(5);
    $output=$ssh->exec(nms_ssh_linux_command());
    check(is_string($output) && $ssh->getExitStatus()===0,'Fixed Linux monitoring command failed');
    $sample=nms_ssh_linux_parse($output);
    foreach(['cpu_percent','memory_percent'] as $field) check($sample[$field]>=0 && $sample[$field]<=100,'Invalid Linux monitoring percentage');
    check($sample['uptime_seconds']>0 && $sample['os']==='Linux','Linux identity/uptime missing');
    foreach([str_replace("NMS_END\n",'',$output),str_repeat('x',65537)] as $invalid) {
        $rejected=false;
        try { nms_ssh_linux_parse($invalid); } catch(RuntimeException $e) { $rejected=true; }
        check($rejected,'Malformed monitoring output was accepted');
    }
    $rejected=false;
    try { $ssh->login($user); } catch(RuntimeException $e) { $rejected=true; }
    check($rejected,'Credential-free login was accepted');
    $ssh->disconnect(); $ssh=null;
    echo "PASS: isolated SSH pinned host key, explicit key authentication, fixed Linux monitoring command, real counter parsing and invalid-output rejection\n";
} finally {
    if($ssh) $ssh->disconnect();
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach(glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);
}
