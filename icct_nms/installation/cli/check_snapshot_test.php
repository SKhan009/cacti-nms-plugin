<?php

require dirname(__DIR__).'/services/checks.php';
$snapshot = json_encode(['hostname' => 'collector-a','time' => 1000,'services' => ['rsyslog.service' => 'active']]);
if (!icct_nms_installation_snapshot($snapshot, 'collector-a', 1100, 600)) {
    throw new RuntimeException('Fresh local service state lost.');
}
foreach ([['collector-b',1100,600],['collector-a',1601,600],['collector-a',999,600]] as $args) {
    if (icct_nms_installation_snapshot($snapshot, ...$args)) {
        throw new RuntimeException('Foreign, stale or future service state accepted.');
    }
}
if (icct_nms_installation_snapshot('invalid', 'collector-a', 1100, 600)) {
    throw new RuntimeException('Corrupt report accepted.');
}
echo "PASS: service checks reject another collector, expired observations, future timestamps and corrupt reports.\n";
foreach(['Ready','Unavailable','Needs setup'] as $status) {
    if(icct_nms_installation_action(['status'=>$status,'item'=>'Receiver','feature'=>'Syslog','detail'=>''])) throw new RuntimeException('Install shown for an installed/unavailable component.');
}
if(icct_nms_installation_action(['status'=>'Needs setup','installed'=>true,'item'=>'worker.service','feature'=>'Syslog','detail'=>'inactive'])) throw new RuntimeException('Installed inactive service offered reinstall.');
if(icct_nms_installation_action(['status'=>'Needs setup','installed'=>false,'item'=>'worker.service','feature'=>'Syslog','detail'=>'inactive'])!=='packages') throw new RuntimeException('Missing service has no Install action.');
if(icct_nms_installation_action(['status'=>'Missing','item'=>'Receiver','feature'=>'Syslog','detail'=>'Install rsyslogd'])!=='packages') throw new RuntimeException('Missing package has no Install action.');
echo "PASS: Install actions are hidden for installed components and unsupported backends, and offered for missing packages/services.\n";
