<?php
/** Native-site coordinate, ACL grouping and tile validation regression checks. */
require __DIR__ . '/../../plugins/nms/includes/topology/map.php';
/** Restrict fixture queries to one device. */
function nms_visible_host_sql() { return 'h.id IN (7)'; }
/** Use a native poll interval in the fixture. */
function nms_poller_interval() { return 300; }
/** Verify the ACL is in the live query and supply only the permitted device. */
function db_fetch_assoc($sql) {
    if (strpos($sql, 'h.id IN (7)') === false || strpos($sql, "h.deleted = ''") === false) throw new Exception('Missing ACL/deletion filter');
    if (strpos($sql, 'plugin_nms_device_parameters p') !== false || strpos($sql, 'plugin_nms_incidents i') !== false) return [];
    return [['id'=>7,'description'=>'Allowed','status'=>3,'disabled'=>'','site_id'=>2,'site_name'=>'Native site','latitude'=>12.5,'longitude'=>77.5]];
}
foreach ([[0,0], [91,1], [0,181], [null,null], ['bad',20]] as $point) if (nms_map_coordinates(...$point) !== null) throw new Exception('Invalid coordinate accepted');
if (nms_map_coordinates(0,10) !== [0.0,10.0]) throw new Exception('Equator rejected');
$data=nms_map_data();
if (count($data['sites']) !== 1 || $data['sites'][0]['devices'][0]['id'] !== 7) throw new Exception('Grouping failed');
$params=nms_map_wms_parameters(['bbox'=>'0,0,100,100','url'=>'https://evil.invalid','width'=>1024,'layers'=>'other'], 'nms:countries');
if ($params['layers'] !== 'nms:countries' || $params['width'] !== 1024 || isset($params['url'])) throw new Exception('Untrusted WMS input used');
foreach (['','0,0,0,0','1,1,0,0','nan,0,10,10','0,0,999999999,10'] as $bbox) {
    try { nms_map_wms_parameters(['bbox'=>$bbox], 'world'); } catch (InvalidArgumentException $e) { continue; }
    throw new Exception('Invalid bounds accepted');
}
echo "PASS: native site grouping, ACL query, coordinates and bounded WMS requests\n";

try { nms_map_wms_parameters(['bbox'=>'0,0,100,100','width'=>10000], 'world'); throw new Exception('Oversized image accepted'); } catch (InvalidArgumentException $e) {}

$m=nms_map_metrics(['ssCpuIdle'=>92,'mem_total'=>100,'mem_free'=>25]);
if ($m['cpu'] !== 8.0 || $m['memory'] !== 75.0 || $m['packet_loss'] !== null) throw new Exception('Metric conversion failed');
$m=nms_map_metrics(['load_1min'=>1.5,'cpu_percent'=>500,'mem_total'=>0]);
if ($m['cpu'] !== null || $m['memory'] !== null) throw new Exception('Invalid metric accepted');
echo "PASS: supported percentage sources; missing loss stays unknown
";
