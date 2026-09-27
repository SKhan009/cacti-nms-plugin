<?php
/** QA-only rendering against real freshness/ACL helpers and temporary serial tables. */
require __DIR__.'/serial-profiles-integration.php';
require_once __DIR__.'/../../plugins/nms/includes/configuration/monitoring.php';
// This test isolates serial evidence; RRD and battery data are covered separately.
function nms_device_battery_readings($id) { return ['readings'=>[]]; }
function nms_reading_collector_evidence($rows) { return ''; }
function nms_reading_raw_evidence($rows,$snapshots) { return ''; }
$host_id=(int)$hosts[0]['id'];
function render_serial_readings($host_id) {
    $edit_device=db_fetch_row_prepared('SELECT * FROM host WHERE id=?',[$host_id]);
    $edit_device['poller_name']='QA collector';
    $device_readings=[]; $device_discovery_readings=[]; $nms_workspace_embedded=true;
    set_error_handler(function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
    ob_start();
    try { require __DIR__.'/../../plugins/nms/templates/devices/readings.php'; return ob_get_contents(); }
    finally { ob_end_clean(); restore_error_handler(); }
}
function assert_serial_view($host_id,$state,$problem) {
    $html=render_serial_readings($host_id);
    check(strpos($html,'SNMPv')===false && strpos($html,'● Live reading')===false,'Serial device rendered as native SNMP evidence');
    check(strpos($html,'data-reading-tab="all">All <span>1</span>')!==false,'All count excludes serial row');
    check(strpos($html,'data-reading-tab="problems">Problems <span>'.$problem.'</span>')!==false,'Problem count disagrees with serial state');
    check(strpos($html,'data-category="serial" data-protocol="serial" data-problem="'.$problem.'"')!==false,'Serial row not included in filters');
    check(strpos($html,'>'.$state.'</span>')!==false,'Expected serial state absent: '.$state);
    check(strpos($html,'<strong>17 </strong>')!==false ? $state==='Current' : $state!=='Current','Old or missing value presented as current');
    check(strpos($html,'value="serial">Serial</option>')!==false,'Serial filter missing');
    return $html;
}
$target=nms_config_target($host_id);
nms_config_store_reading($target,'limit',['status'=>'read','value'=>17]);
assert_serial_view($host_id,'Current',0);
nms_category_execute('UPDATE plugin_nms_serial_readings SET observed_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE host_id=?',[$host_id]);
assert_serial_view($host_id,'Stale',1);
nms_config_store_reading($target,'limit',['status'=>'failed']);
assert_serial_view($host_id,'Failed',1);
nms_category_execute('DELETE FROM plugin_nms_serial_readings WHERE host_id=?',[$host_id]);
$html=assert_serial_view($host_id,'Unavailable',1);
check(strpos($html,'<strong>Not recorded</strong>')!==false,'Native host timestamp substituted for missing serial evidence');
nms_category_execute('DELETE FROM plugin_nms_config_devices WHERE host_id=?',[$host_id]);
assert_serial_view($host_id,'Unavailable',1);
echo "PASS: serial readings current/stale/failed/missing/model-missing states, value suppression, counts and filter attributes; temporary data only\n";
