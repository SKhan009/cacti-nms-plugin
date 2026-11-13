<?php
/** Native preview preserves unknowns, zero and graph authorization. */
require __DIR__ . '/../../fcaps/services/fcaps_service.php';
$fixture = sys_get_temp_dir() . '/icct-reading-' . bin2hex(random_bytes(6));
mkdir($fixture . '/lib', 0700, true);
file_put_contents($fixture . '/lib/rrd.php', '<?php');
$config = ['base_path' => $fixture];
$allowed = [['local_graph_id' => 62]];
$monitoring = 1;
$points = [time() => 0];
function icct_nms_device_graphs($id) { return $GLOBALS['allowed']; }
function read_config_option($name) { return 300; }
function db_fetch_assoc_prepared($sql, $params) {
    if ($params !== [860, 2, 502]) throw new Exception('Source binding changed');
    return [['graph_id'=>62,'local_data_id'=>68,'data_source_name'=>'cpu_percent','units'=>'Percent',
        'input_string'=>'<path_php_binary> -q <path_cacti>/plugins/icct_nms/protocols/ssh/metrics.php 2 cpu_percent']];
}
function db_fetch_cell_prepared($sql, $params) { return $GLOBALS['monitoring']; }
function rrdtool_function_fetch(...$args) {
    if ($args[0] !== 68) throw new Exception('Wrong RRD');
    return ['data_source_names'=>['cpu_percent'],'values'=>[$GLOBALS['points']]];
}
function preview() { return icct_nms_fault_rrd_read(['id'=>2,'disabled'=>''],502,860)[0]; }
function verify($ok) { if (!$ok) throw new Exception('Current reading regression'); }
try {
    verify(preview()['value'] === 0.0);
    verify(preview()['units'] === 'Percent');
    $points = [time()=>null, time()-300=>45];
    verify(preview()['value'] === null); // Never conceal a missing current sample with an older one.
    $points = [time()-601=>45];
    verify(preview()['value'] === null);
    $monitoring=0;
    verify(str_contains(preview()['reason'], 'SSH monitoring is disabled'));
    $points = [time()=>45];
    verify(icct_nms_fault_rrd_read(['id'=>2,'disabled'=>'on'],502,860)[0]['value'] === null);
    $allowed=[];
    verify(str_contains(preview()['reason'], 'No accessible graph'));
    echo "Current reading: zero, latest unknown, stale sample, disabled monitoring/device and graph permissions passed.\n";
} finally {
    unlink($fixture . '/lib/rrd.php'); rmdir($fixture . '/lib'); rmdir($fixture);
}
