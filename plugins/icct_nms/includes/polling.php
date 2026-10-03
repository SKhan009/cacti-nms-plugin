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
        icct_backend_collect_faults();
        icct_backend_nd_poll();
    } catch (Throwable $error) {
        cacti_log('ICCT NMS collector: ' . $error->getMessage(), false, 'ICCT NMS');
    }
}
