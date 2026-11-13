<?php

// Run as the collector account. Only return feature names and counts to the package runner.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/../../include/cli_check.php';
require_once dirname(__DIR__, 2) . '/shared/services/backend.php';

$packages = array_slice($argv, 1);
$diagnostics = [
    'traceroute' => ['traceroute', 'traceroute_icmp', 'traceroute_tcp'],
    'mtr' => ['mtr_icmp', 'mtr_tcp'],
    'iperf3' => ['iperf3', 'iperf3_udp'],
    'netperf' => ['netperf'],
    'icct-nms-pathchar' => ['pathchar'],
];
$selected = array_intersect_key($diagnostics, array_flip($packages));
$blockers = [];
if ($selected) {
    $collector = icct_backend_inventory_collector_id();
    foreach ($selected as $package => $tools) {
        $conditions = implode(
            ' OR ',
            array_fill(0, count($tools), "FIND_IN_SET(?, REPLACE(p.tools, ' ', '')) > 0"),
        );
        $count = db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM host h
             JOIN plugin_icct_nms_diagnostic_devices d ON d.host_id=h.id
             JOIN plugin_icct_nms_diagnostic_profiles p ON p.id=d.profile_id
             WHERE h.poller_id=? AND h.disabled='' AND h.deleted='' AND ($conditions)",
            [$collector, ...$tools],
        );
        if (!is_numeric($count)) {
            throw new RuntimeException('Diagnostic assignments could not be checked.');
        }
        if ((int) $count > 0) {
            $blockers[] = "$package is enabled in diagnostic profiles assigned to $count monitored device(s).";
        }
    }
}
echo json_encode($blockers, JSON_THROW_ON_ERROR) . PHP_EOL;
