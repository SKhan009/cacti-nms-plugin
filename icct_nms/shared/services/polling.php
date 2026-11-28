<?php

/** ICCT collection hooks use only owned services and tables; core polling continues on errors. */
function icct_nms_poller_bottom()
{
    global $config;
    require_once __DIR__ . '/bootstrap.php';
    try {
        icct_nms_backend();
        icct_nms_snmp_reuse_reset();
        icct_nms_poller_collectors([
            'diagnostics'=>'icct_backend_diag_dispatch',
            'inventory'=>'icct_backend_collect_inventory_values',
            'identity'=>'icct_backend_collect_identity',
            'ports'=>'icct_backend_collect_ports',
            'port alarms'=>'icct_backend_collect_port_alarms',
            'faults'=>'icct_backend_collect_faults',
            'neighbors'=>'icct_backend_nd_poll',
            'sample cleanup'=>'icct_nms_snmp_shared_cleanup',
        ]);
    } catch (Throwable $error) {
        cacti_log('ICCT NMS collector: ' . $error->getMessage(), false, 'ICCT NMS');
    }
    try {
        require_once __DIR__ . '/../../protocols/ntp/services/ntp_collector.php';
        $ntpDeadline = microtime(true) + max(1, (int) read_config_option('poller_interval')) / 2;
        foreach (
            icct_nms_ntp_due(['local', 'auto', 'udp'], (int) $config['poller_id'])
            as $device
        ) {
            if (microtime(true) >= $ntpDeadline) {
                break;
            }
            icct_nms_ntp_collect((int) $device['host_id'], $device['method']);
        }
    } catch (Throwable $error) {
        cacti_log('ICCT NTP collector: ' . $error->getMessage(), false, 'ICCT NMS');
    }
    try {
        icct_nms_backend();
        icct_nms_collect_alarms();
    } catch (Throwable $error) {
        cacti_log('ICCT NMS alarm journal: ' . $error->getMessage(), false, 'ICCT NMS');
    }
    try {
        icct_nms_backend();
        icct_backend_syslog_collect_availability_events();
    } catch (Throwable $error) {
        cacti_log('ICCT NMS availability event bridge: ' . $error->getMessage(), false, 'ICCT NMS');
    }

    try {
        require_once __DIR__ . '/../../protocols/netflow/services/netflow_faults.php';
        icct_nms_netflow_sync_faults();
    } catch (Throwable $error) {
        cacti_log('ICCT NMS NetFlow fault bridge: ' . $error->getMessage(), false, 'ICCT NMS');
    }

    try {
        require_once __DIR__ . '/../../inventory/discovery/services/discovery_workflow.php';
        icct_nms_scan_dispatch();
    } catch (Throwable $error) {
        cacti_log('ICCT discovery dispatcher: ' . $error->getMessage(), false, 'ICCT NMS');
    }

    try {
        require_once __DIR__ . '/../../installation/services/checks.php';
        icct_nms_installation_save_services();
    } catch (Throwable $error) {
        cacti_log('ICCT installation checks: ' . $error->getMessage(), false, 'ICCT NMS');
    }

    try {
        require_once __DIR__ . '/../../inventory/discovery/services/inventory_events.php';
        icct_nms_inventory_observe((int) $config['poller_id']);
    } catch (Throwable $error) {
        cacti_log('ICCT inventory events: ' . $error->getMessage(), false, 'ICCT NMS');
    }
}

/** Preserve order and isolate failures; never retry a failed stage from another transport. */
function icct_nms_poller_collectors(array $collectors): void
{
    $start = microtime(true);
    foreach ($collectors as $name => $collector) {
        try {
            $collector();
        } catch (Throwable $error) {
            cacti_log('ICCT NMS ' . $name . ': ' . $error->getMessage(), false, 'ICCT NMS');
        }
    }
    $elapsed = microtime(true) - $start;
    if ($elapsed >= max(1, (int) read_config_option('poller_interval'))) {
        cacti_log(sprintf('ICCT NMS collectors exceeded the polling interval (%.2fs).', $elapsed), false, 'ICCT NMS');
    }
}

/** Native Cacti raw-output hook. Never alter or consume Cacti's samples. */
function icct_nms_poller_output($updates)
{
    require_once __DIR__ . '/bootstrap.php';
    try {
        icct_nms_backend();
        if (is_array($updates)) icct_nms_snmp_capture($updates);
    } catch (Throwable $error) {
        cacti_log('ICCT native sample observation failed: ' . $error->getMessage(), false, 'ICCT NMS');
    }
    return $updates;
}
