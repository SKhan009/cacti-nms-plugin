<?php
require dirname(__DIR__) . '/services/checks.php';
function readiness_assert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$healthy = ['status' => 'Synchronized', 'sample' => ['synchronized' => true], 'error' => ''];
readiness_assert(icct_nms_installation_ntp_status([$healthy, $healthy])['status'] === 'Ready', 'Current synchronized monitors must pass.');
foreach (['Stale', 'Settings changed', 'Not collected', 'Unsynchronized', 'Disabled', 'Unavailable'] as $status) {
    readiness_assert(icct_nms_installation_ntp_status([$healthy, ['status' => $status]])['status'] === 'Needs setup', 'A healthy monitor must not hide ' . $status);
}
readiness_assert(icct_nms_installation_ntp_status([])['status'] === 'Not configured', 'No observations must not pass.');
readiness_assert(icct_nms_installation_ntp_status([array_replace($healthy, ['sample' => null])])['status'] === 'Needs setup', 'A status label alone cannot establish readiness.');
readiness_assert(icct_nms_installation_ntp_status([array_replace($healthy, ['error' => 'permission denied'])])['status'] === 'Needs setup', 'Collection errors must remain visible.');
readiness_assert(icct_nms_installation_component_status(['installed'=>true]) === 'Installed', 'RPM ownership must be retained.');
readiness_assert(icct_nms_installation_component_status(['installed'=>false, 'runtime_available'=>true]) === 'Available outside package manager', 'Native installation must not be labelled missing or RPM-installed.');
readiness_assert(icct_nms_installation_component_status(['installed'=>false, 'runtime_available'=>false]) === 'Missing', 'Unavailable runtimes must not pass.');
readiness_assert(icct_nms_installation_component_status([]) === 'Not checked', 'Unknown package inventory must not become missing.');
readiness_assert(!icct_nms_installation_component_runtime(['runtime_verification'=>'strict-auth'], [__FILE__])['runtime_available'], 'An executable without a trusted receipt must not count as strict-auth.');
$receiverSpec = icct_nms_installation_manifest()['features']['Syslog'];
$report = ['hostname'=>'collector', 'time'=>1000, 'services'=>['rsyslog.service'=>'active']];
$fresh = icct_nms_installation_snapshot(json_encode($report), 'collector', 1001, 600);
readiness_assert(str_contains(icct_nms_installation_receiver_report($receiverSpec, 'Receiver', $fresh), 'rsyslog.service'), 'A fresh active receiver observation must resolve an inaccessible web binary.');
foreach ([['other-host',1001], ['collector',2000]] as [$host,$now]) {
    $invalid = icct_nms_installation_snapshot(json_encode($report), $host, $now, 600);
    readiness_assert(icct_nms_installation_receiver_report($receiverSpec, 'Receiver', $invalid) === '', 'A stale or other-host observation must not mark a receiver ready.');
}
$report['services']['rsyslog.service'] = 'failed';
readiness_assert(icct_nms_installation_receiver_report($receiverSpec, 'Receiver', $report) === '', 'Failed receivers must not pass.');
readiness_assert(icct_nms_installation_receiver_report($receiverSpec, 'Other tool', $fresh) === '', 'Service fallback applies only to declared receiver binaries.');
$oldPath = getenv('PATH');
$directory = sys_get_temp_dir() . '/icct-readiness-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $sourceDirectory = $directory . '/source';
    mkdir($sourceDirectory, 0755);
    $sourceFile = $sourceDirectory . '/code.php';
    file_put_contents($sourceFile, '<?php');
    chmod($sourceFile, 0644);
    readiness_assert(icct_nms_installation_source_permissions($sourceFile, $directory, false)['status'] === 'Ready', 'Protected source should pass.');
    chmod($sourceDirectory, 0777); clearstatcache();
    readiness_assert(icct_nms_installation_source_permissions($sourceFile, $directory, false)['status'] === 'Needs setup', 'Read-only source in a replaceable directory must fail.');
    chmod($sourceDirectory, 0755); chmod($sourceFile, 0666); clearstatcache();
    readiness_assert(icct_nms_installation_source_permissions($sourceFile, $directory, false)['status'] === 'Needs setup', 'World-writable source must fail.');
    unlink($sourceFile); rmdir($sourceDirectory);
    $binary = $directory . '/runtime-probe';
    file_put_contents($binary, '#!/bin/sh' . "\nexit 0\n");
    chmod($binary, 0700);
    putenv('PATH=' . $directory);
    readiness_assert(icct_nms_installation_binary('runtime-probe') === $binary, 'Binary discovery must use the process PATH.');
    chmod($binary, 0600); clearstatcache(true, $binary);
    readiness_assert(icct_nms_installation_binary('runtime-probe') === '', 'A non-executable tool must not be ready.');
    readiness_assert(icct_nms_installation_binary('runtime-probe', false) === $binary, 'Receiver presence checks need not grant PHP execution permission.');
    putenv('PATH=/no-such-icct-directory');
    readiness_assert(icct_nms_installation_binary('sh') !== '', 'Standard system binaries must be found with a restricted web PATH.');
    readiness_assert(icct_nms_installation_native_binary(['runtime_service' => 'absent.service']) === '', 'Missing service observations must not use a guessed daemon path.');
} finally {
    putenv('PATH=' . $oldPath);
    unlink($binary); rmdir($directory);
}
echo "PASS: NTP readiness, missing/stale/error observations, native-vs-RPM state, strict receipt rejection and dynamic binary discovery.\n";
