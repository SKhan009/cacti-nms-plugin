<?php

/** ICCT collection hooks use only owned services and tables; core polling continues on errors. */
function icct_nms_poller_bottom()
{
    global $config;
    require_once __DIR__ . '/bootstrap.php';
    try {
        icct_nms_backend();
        icct_backend_diag_dispatch();
        icct_backend_collect_inventory_values();
        icct_backend_collect_identity();
        icct_backend_collect_ports();
        icct_backend_collect_port_alarms();
        icct_backend_collect_faults();
        icct_backend_nd_poll();
    } catch (Throwable $error) {
        cacti_log('ICCT NMS collector: ' . $error->getMessage(), false, 'ICCT NMS');
    }
    try {
        require_once __DIR__ . '/../../protocols/ntp/services/ntp_collector.php';
        foreach (icct_nms_ntp_due(['local', 'auto', 'udp'], (int) $config['poller_id']) as $device) {
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
