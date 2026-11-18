<?php

const ICCT_MIB_SIM_STATE = '/var/lib/icct-nms-mib-simulator';
const ICCT_MIB_SIM_OID = '1.3.6.1.4.1.32473.20261116.1.0';

function icct_mib_simulator_devices()
{
    $devices = db_fetch_assoc_prepared(
        "SELECT h.id,h.description FROM host h WHERE h.deleted='' AND h.disabled='' AND h.poller_id=?
         AND h.hostname='127.0.0.1' AND h.snmp_port=1161 AND h.snmp_version=2 AND h.snmp_community='icct-mib-sim'
         AND EXISTS (SELECT 1 FROM poller_item p WHERE p.host_id=h.id AND p.action=0 AND TRIM(LEADING '.' FROM p.arg1)=?)
         ORDER BY h.description,h.id",
        [icct_backend_inventory_collector_id(), ICCT_MIB_SIM_OID],
    ) ?: [];
    return array_filter($devices, static fn($device) => is_device_allowed((int) $device['id']));
}

function icct_mib_simulator_running()
{
    return is_file(ICCT_MIB_SIM_STATE . '/cycle.json');
}

function icct_mib_simulator_control($action)
{
    if (!in_array($action, ['simulator_start', 'simulator_stop'], true)) {
        throw new InvalidArgumentException('Choose Start or Stop.');
    }
    if (!is_writable(ICCT_MIB_SIM_STATE)) {
        throw new RuntimeException('The MIB simulator has not been provisioned on this collector.');
    }
    if (!icct_mib_simulator_devices()) {
        throw new RuntimeException('An enabled Cacti device with the simulator MIB graph is required.');
    }
    $path = ICCT_MIB_SIM_STATE . '/cycle.json';
    if ($action === 'simulator_stop') {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not stop the simulation.');
        }
        return;
    }
    $cadence = (int) read_config_option('poller_interval');
    if ($cadence < 1) {
        throw new RuntimeException('The Cacti poller interval is unavailable.');
    }
    $state = json_encode(['interval' => $cadence, 'first_poll' => intdiv(time(), $cadence) + 1], JSON_THROW_ON_ERROR);
    $temporary = tempnam(ICCT_MIB_SIM_STATE, 'cycle-');
    try {
        if ($temporary === false || file_put_contents($temporary, $state) !== strlen($state) ||
            !chmod($temporary, 0640) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not start the simulation.');
        }
    } finally {
        if ($temporary !== false && is_file($temporary)) {
            unlink($temporary);
        }
    }
}
