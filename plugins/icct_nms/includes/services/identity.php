<?php
/** Protocol identity belongs to collector observations, never to manual metadata. */
function icct_backend_identity_mac($value)
{
    $value = preg_replace('/^Hex-STRING:\s*/i', '', trim((string) $value, " \t\r\n\""));
    $hex = preg_replace('/[:\s-]/', '', $value);
    if (!preg_match('/^[a-f0-9]{12}$/iD', $hex) || $hex === '000000000000' || (hexdec(substr($hex, 0, 2)) & 1)) return '';
    return strtolower(implode(':', str_split($hex, 2)));
}

function icct_backend_identity_signature($host)
{
    return hash('sha256', json_encode([$host['hostname'], $host['snmp_version'], $host['snmp_port'], $host['snmp_context'], $host['host_template_id'], $host['poller_id'], $host['snmp_username'], hash('sha256', $host['snmp_community'] . "\0" . $host['snmp_password'] . "\0" . $host['snmp_priv_passphrase'])]));
}

/** Only current observations from the same configured endpoint may fill empty fields. */
function icct_backend_identity_observed($host)
{
    $row = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', ['identity_snapshot_' . (int) $host['id']]);
    $snapshot = json_decode((string) $row, true);
    if (!is_array($snapshot) || ($snapshot['signature'] ?? '') !== icct_backend_identity_signature($host) || (int) ($snapshot['time'] ?? 0) > time() || time() - (int) ($snapshot['time'] ?? 0) > 2 * (int) read_config_option('poller_interval')) return [];
    return $snapshot['values'] ?? [];
}

/** Use Cacti SNMP with saved host credentials on the assigned collector only. */
function icct_backend_collect_identity($only_host_id = 0)
{
    global $config;
    require_once $config['base_path'] . '/lib/snmp.php';
    $collector = icct_backend_inventory_collector_id();
    $hosts = db_fetch_assoc_prepared("SELECT * FROM host WHERE deleted='' AND disabled='' AND snmp_version>0 AND poller_id=?" . ($only_host_id ? ' AND id=?' : ''), $only_host_id ? [$collector, (int) $only_host_id] : [$collector]);
    $count = 0;
    foreach ($hosts as $host) {
        $get = function ($oid, $format = SNMP_STRING_OUTPUT_ASCII) use ($host) {
            return cacti_snmp_get($host['hostname'], $host['snmp_community'], $oid, $host['snmp_version'], $host['snmp_username'], $host['snmp_password'], $host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'], $host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'], (int) read_config_option('snmp_retries'), 'ICCT Identity', $host['snmp_engine_id'], $format);
        };
        $values = [];
        // Test the configured endpoint first; an IP alone is not protocol configuration.
        if (!icct_backend_inventory_snmp_failed($get('.1.3.6.1.2.1.1.3.0'))) {
            $mapped = db_fetch_assoc_prepared("SELECT inventory_key,observed_value FROM plugin_icct_nms_device_inventory WHERE host_id=? AND inventory_key IN ('mac_address','serial_number','chassis_id') AND status IN ('ok','changed') AND last_success>=DATE_SUB(NOW(), INTERVAL 2 MINUTE)", [$host['id']]);
            foreach ($mapped as $row) {
                $value = trim((string) $row['observed_value']);
                if ($row['inventory_key'] === 'mac_address') $value = icct_backend_identity_mac($value);
                if ($value !== '') $values[$row['inventory_key']] = $value;
            }
            // Resolve the interface owning the configured IPv4 address, rather than guessing an interface.
            if (empty($values['mac_address']) && filter_var($host['hostname'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $index = trim((string) $get('.1.3.6.1.2.1.4.20.1.2.' . $host['hostname']));
                if (ctype_digit($index)) {
                    $mac = icct_backend_identity_mac($get('.1.3.6.1.2.1.2.2.1.6.' . $index, SNMP_STRING_OUTPUT_HEX));
                    if ($mac !== '') $values['mac_address'] = $mac;
                }
            }
            // LLDP's local chassis identifier is used as a chassis ID, never a serial number.
            if (empty($values['chassis_id'])) {
                $chassis = $get('.1.0.8802.1.1.2.1.3.2.0');
                if (!icct_backend_inventory_snmp_failed($chassis)) {
                    $subtype = trim((string) $get('.1.0.8802.1.1.2.1.3.1.0'));
                    $chassis = $subtype === '4' ? icct_backend_identity_mac($get('.1.0.8802.1.1.2.1.3.2.0', SNMP_STRING_OUTPUT_HEX)) : trim((string) $chassis, " \t\r\n\"");
                    if ($chassis !== '') {
                        $values['chassis_id'] = $chassis;
                        // Subtype 4 explicitly identifies the device's chassis MAC.
                        if ($subtype === '4' && empty($values['mac_address'])) $values['mac_address'] = $chassis;
                    }
                }
            }
            // ENTITY-MIB can contain many component serials: accept exactly one physical chassis.
            if (empty($values['serial_number'])) {
                $classes = cacti_snmp_walk($host['hostname'], $host['snmp_community'], '.1.3.6.1.2.1.47.1.1.1.1.5', $host['snmp_version'], $host['snmp_username'], $host['snmp_password'], $host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'], $host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'], (int) read_config_option('snmp_retries'), (int) $host['max_oids'], 'ICCT Identity', $host['snmp_engine_id']);
                $chassisRows = array_filter(is_array($classes) ? $classes : [], function ($row) { return preg_match('/^(?:3|chassis(?:\(3\))?)$/i', trim((string) $row['value'])); });
                if (count($chassisRows) === 1) {
                    $row = array_values($chassisRows)[0];
                    $index = substr($row['oid'], strrpos($row['oid'], '.') + 1);
                    if (ctype_digit($index)) {
                        $serial = $get('.1.3.6.1.2.1.47.1.1.1.1.11.' . $index);
                        if (!icct_backend_inventory_snmp_failed($serial) && trim((string) $serial, " \t\r\n\"") !== '') $values['serial_number'] = trim((string) $serial, " \t\r\n\"");
                    }
                }
            }
        }
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()', ['identity_snapshot_' . $host['id'], json_encode(['signature' => icct_backend_identity_signature($host), 'time' => time(), 'values' => $values], JSON_THROW_ON_ERROR)]);
        $count++;
    }
    return $count;
}
