<?php

/** Portable, non-secret starter values. Run only from plugin install/upgrade. */
require_once __DIR__ . '/../../netflow/services/netflow_service.php';
require_once __DIR__ . '/protocol_preset_service.php';
function icct_nms_protocol_starters()
{
    $cadence = max(60, (int) read_config_option('poller_interval'));
    $snmpDiscoveryInterval = intdiv(300 + $cadence - 1, $cadence) * $cadence;
    $hostname = gethostname() ?: 'localhost';
    if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        $hostname = 'localhost';
    }
    return [
        'snmp' => [
            'snmp_version' => 2,
            'snmp_port' => 161,
            'snmp_timeout' => 500,
            'max_oids' => 10,
            'availability_method' => 2,
            'ping_method' => 1,
            'ping_timeout' => 400,
            'ping_retries' => 1,
            'snmp_username' => '',
            'snmp_auth_protocol' => '[None]',
            'snmp_priv_protocol' => '[None]',
            'snmp_context' => '',
            'snmp_security_level' => 'noAuthNoPriv',
        ],
        'snmp_notifications' => [
            'trap_enabled' => '0',
            'trap_source_mode' => 'device',
        ],
        'snmp_discovery' => [
            'interval_seconds' => $snmpDiscoveryInterval,
            'stale_seconds' => 3 * $snmpDiscoveryInterval,
            'refresh_seconds' => 30,
        ],
        'cdp' => [
            'interval_seconds' => $cadence,
            'stale_seconds' => 3 * $cadence,
            'refresh_seconds' => 30,
        ],
        'lldp' => [
            'interval_seconds' => $cadence,
            'stale_seconds' => 3 * $cadence,
            'refresh_seconds' => 30,
        ],
        'ssh' => [
            'port' => 22,
            'connect_timeout' => 10,
            'command_timeout' => 30,
            'retries' => 1,
            'keepalive' => 30,
            'auth_method' => 'password',
            'username' => 'operator',
            'monitoring' => '0',
        ],
        'serial' => [
            'serial_interface' => 'rs485',
            'baud_rate' => 9600,
            'data_bits' => 8,
            'parity' => 'even',
            'stop_bits' => 1,
            'flow_control' => 'none',
            'response_timeout' => 3,
            'serial_retries' => 2,
            'serial_interval' => 60,
            'serial_protocol' => 'modbus_rtu',
        ],
        'syslog' => [
            'severity_codes' => json_encode(range(0, 7)),
            'facility_codes' => json_encode(range(0, 23)),
            'match_strings' => '[]',
            'transport' => 'both',
        ],
        'ntp' => [
            'method' => 'local',
            'timeout' => 5,
            'interval_seconds' => $cadence,
            'stale_seconds' => 3 * $cadence,
        ],
        'netflow' => array_replace(icct_nms_netflow_defaults(), ['collector' => $hostname]),
    ];
}
function icct_nms_seed_protocol_presets()
{
    $lock = 'icct_protocol_defaults';
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,5)', [$lock]) !== 1) {
        throw new RuntimeException('Protocol defaults are busy. Retry the upgrade.');
    }
    $execute = static function ($sql, $args = []) {
        if (!db_execute_prepared($sql, $args)) {
            throw new RuntimeException('Could not save protocol starter presets.');
        }
    };
    try {
        $before = icct_nms_protocol_presets();
        $presets = $before;
        $starters = icct_nms_protocol_starters();
        foreach ($starters as $protocol => $starter) {
            $allowed = array_flip(icct_nms_protocol_preset_fields($protocol));
            $presets[$protocol] = array_replace(
                $starter,
                array_intersect_key($before[$protocol] ?? [], $allowed),
            );
        }
        $oldProfiles = icct_nms_netflow_profiles();
        $profiles = [];
        foreach ([5, 7, 9, 10] as $version) {
            $base = array_replace($starters['netflow'], [
                'version' => $version,
                'port' => $version === 10 ? 4739 : 2055,
            ]);
            $existing =
                $oldProfiles[$version] ??
                ((int) ($before['netflow']['version'] ?? 0) === $version
                    ? $before['netflow'] ?? []
                    : []);
            $profiles[$version] = array_replace(
                $base,
                array_intersect_key($existing, array_flip(icct_nms_netflow_fields())),
                ['version' => $version],
            );
        }
        $oldVersions = icct_nms_protocol_profiles();
        $versions = [];
        foreach (icct_nms_protocol_variants() as $protocol => $variant) {
            foreach ($variant['labels'] as $value => $label) {
                $base = array_replace($starters[$protocol], [$variant['field'] => (string) $value]);
                if ($protocol === 'serial' && $value === 'modbus_ascii') {
                    $base['data_bits'] = 7;
                }
                $active = $before[$protocol] ?? [];
                $existing =
                    $oldVersions[$protocol][$value] ??
                    ((string) ($active[$variant['field']] ?? '') === (string) $value
                        ? $active
                        : []);
                $versions[$protocol][$value] = array_replace(
                    $base,
                    array_intersect_key(
                        $existing,
                        array_flip(icct_nms_protocol_preset_fields($protocol)),
                    ),
                    [$variant['field'] => (string) $value],
                );
            }
        }
        $execute('START TRANSACTION');
        try {
            foreach (
                [
                    'protocol_defaults' => [$before, $presets],
                    'protocol_netflow_versions' => [$oldProfiles, $profiles],
                    'protocol_versions' => [$oldVersions, $versions],
                ]
                as $key => [$old, $new]
            ) {
                if ($old === $new) {
                    continue;
                }
                $execute(
                    'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                        'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                    [$key, json_encode($new, JSON_THROW_ON_ERROR)],
                );
            }
            $execute('COMMIT');
        } catch (Throwable $e) {
            $execute('ROLLBACK');
            throw $e;
        }
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
