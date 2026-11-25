<?php

require_once __DIR__ . '/../../../protocols/snmp/services/native_samples.php';

/** Bind evidence to the actual network and current credential set, without storing secrets. */
function icct_nms_scan_native_signature(array $network): string
{
    $fields = array_intersect_key($network, array_flip(['subnet_range','poller_id','snmp_id',
        'ping_method','ping_port','ping_timeout','ping_retries','enabled']));
    $credentials = db_fetch_assoc_prepared('SELECT * FROM automation_snmp_items WHERE snmp_id=? ORDER BY sequence',
        [(int) $network['snmp_id']]);
    return hash('sha256', json_encode([$fields,$credentials], JSON_THROW_ON_ERROR));
}

/** No competing scan while native automation is starting/running. A failed start stays visible. */
function icct_nms_scan_native_context(array $job, array $v): ?array
{
    if (empty($job['network_id'])) return [];
    $network = db_fetch_row_prepared('SELECT *,UNIX_TIMESTAMP(last_started) AS native_started FROM automation_networks WHERE id=?',
        [(int) $job['network_id']]);
    if (!$network || $network['enabled'] !== 'on' || (int) $network['poller_id'] !== (int) $job['poller_id'] ||
        (isset($v['native_signature']) && !hash_equals($v['native_signature'], icct_nms_scan_native_signature($network)))) {
        throw new RuntimeException('Native discovery settings changed. Start a new discovery run.');
    }
    $after = (int) ($v['native_after'] ?? (strtotime($job['created_at']) - 1));
    if (isset($v['native_run']) && (int) $v['native_run'] !== (int) $network['native_started']) {
        throw new RuntimeException('A different native discovery run replaced this job. Start a new discovery run.');
    }
    if ((int) $network['native_started'] <= $after || (int) $network['native_started'] > time() ||
        db_fetch_cell_prepared('SELECT COUNT(*) FROM automation_processes WHERE network_id=?', [(int) $job['network_id']])) {
        if (time() - strtotime($job['created_at']) > (int) $v['run_limit']) {
            throw new RuntimeException('Native Cacti discovery did not finish within the configured runtime. Review native discovery and retry.');
        }
        return null;
    }
    return $network;
}

/** Compare the security fields relevant to the selected SNMP version. */
function icct_nms_scan_credential_matches(array $host, array $credential): bool
{
    $fields = ['snmp_version','snmp_port','snmp_context'];
    if ((int) $host['snmp_version'] === 3) {
        $fields = array_merge($fields, ['snmp_username','snmp_password','snmp_auth_protocol',
            'snmp_priv_passphrase','snmp_priv_protocol','snmp_engine_id']);
    } else $fields[] = 'snmp_community';
    foreach ($fields as $field) if ((string) ($host[$field] ?? '') !== (string) ($credential[$field] ?? '')) return false;
    return true;
}

/** Only an unambiguous literal endpoint on the assigned collector may supply existing evidence. */
function icct_nms_scan_known_host(string $address, array $v): ?array
{
    $rows = db_fetch_assoc_prepared("SELECT * FROM host WHERE hostname=? AND poller_id=? AND disabled='' AND deleted=''",
        [$address,(int) $v['poller_id']]);
    if (count($rows) !== 1) return null;
    try { icct_backend_require_device_access((int) $rows[0]['id']); } catch (Throwable $error) { return null; }
    return $rows[0];
}

/** Resolve native discovery evidence before any active probe; never treat absence as success. */
function icct_nms_scan_reuse(string $address, array $v, float $deadline): array
{
    $host = icct_nms_scan_known_host($address, $v);
    $out = ['host'=>$host, 'icmp'=>null, 'icmp_owned'=>false, 'snmp'=>null];
    $network = $v['native_context'] ?? [];
    $native = $network ? db_fetch_row_prepared('SELECT * FROM automation_devices WHERE network_id=? AND ip=? AND time>=? AND time<=?',
        [(int) $network['id'],$address,(int) $network['native_started'],time()]) : [];
    if ($native && (int) $network['ping_method'] === PING_ICMP) $out['icmp'] = (bool) $native['up'];
    // Missing native rows are unknown, not proof of a negative response or permission to retry.
    $out['icmp_owned'] = !$host && $network && (int) $network['ping_method'] === PING_ICMP;
    $hostTime = $host ? strtotime($host['last_updated'] ?? '') : 0;
    if ($host && icct_nms_snmp_sample_fresh(['time'=>$hostTime]) && (int) $host['ping_method'] === PING_ICMP &&
        in_array((int) $host['availability_method'],[AVAIL_PING,AVAIL_SNMP_AND_PING],true) &&
        (int) $host['status'] === HOST_UP) $out['icmp'] = true;
    if (!array_intersect($v['methods'],['snmp','cdp','lldp'])) return $out;
    if ($native && empty($native['snmp']) && !empty($native['up'])) { $out['snmp'] = ['ok'=>false]; return $out; }
    $credentials = db_fetch_assoc_prepared('SELECT * FROM automation_snmp_items WHERE snmp_id=? ORDER BY sequence', [(int) $v['snmp_id']]);
    $credential = null;
    foreach ($credentials as $candidate) {
        if (($host && icct_nms_scan_credential_matches($host,$candidate)) ||
            (!$host && $native && !empty($native['snmp']) && icct_nms_scan_credential_matches($native,$candidate))) {
            $credential = $candidate; break;
        }
    }
    if (!$credential) return $out;
    $endpoint = $host ?: array_replace($credential,['id'=>0,'hostname'=>$address,'poller_id'=>$v['poller_id'],'disabled'=>'']);
    $state = &icct_nms_snmp_samples($endpoint);
    $profile = $host ? json_decode((string) db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['cacti_snmp_profile_'.(int) $host['id']]),true) : null;
    $hostFresh = $host && ($profile['signature'] ?? '') === icct_nms_snmp_profile($host) &&
        $hostTime > (int) ($profile['time'] ?? PHP_INT_MAX) && icct_nms_snmp_sample_fresh(['time'=>$hostTime]);
    $device = [];
    foreach (['sysName'=>'1.3.6.1.2.1.1.5.0','sysDescr'=>'1.3.6.1.2.1.1.1.0',
        'sysObjectID'=>'1.3.6.1.2.1.1.2.0','sysLocation'=>'1.3.6.1.2.1.1.6.0',
        'sysContact'=>'1.3.6.1.2.1.1.4.0','sysUptime'=>'1.3.6.1.2.1.1.3.0'] as $field=>$oid) {
        $cached = null;
        $hostField = $field === 'sysUptime' ? 'snmp_sysUpTimeInstance' : 'snmp_'.$field;
        if ($native && !empty($native['snmp']) && array_key_exists($field,$native)) $cached = ['value'=>$native[$field],'time'=>(int) $native['time']];
        elseif ($hostFresh && array_key_exists($hostField,$host)) $cached = ['value'=>$host[$hostField],'time'=>$hostTime];
        if ($cached && in_array($field,['sysName','sysDescr','sysObjectID'],true) &&
            icct_backend_inventory_snmp_failed($cached['value'])) $cached = null;
        // Cacti may derive sysUptime from engine time: do not relabel it as a raw sysUpTime OID.
        if ($cached && $field !== 'sysUptime' && !isset($state['owners'][$oid])) {
            $state['values'][$oid] = $cached + ['source'=>'query','step'=>(int) read_config_option('poller_interval')];
        }
        if (microtime(true) >= $deadline) throw new RuntimeException('Discovery time limit reached.');
        $value = $cached ? $cached['value'] : icct_nms_snmp_get($endpoint,$oid);
        if (icct_backend_inventory_snmp_failed($value)) $value = '';
        $device['snmp_'.$field] = $value === false ? '' : $value;
    }
    $out['snmp'] = ['ok'=>!empty($device['snmp_sysObjectID']) || !empty($device['snmp_sysDescr']) || !empty($device['snmp_sysName']),
        'device'=>$device,'credential'=>$credential,'host'=>$endpoint];
    return $out;
}

/** Assigned neighbour collection owns its snapshots; discovery does not race it or repeat failed samples. */
function icct_nms_scan_neighbours(array $host, string $method, float $deadline): array
{
    if (!empty($host['id'])) {
        foreach (icct_backend_nd_hosts() as $assigned) {
            if ((int) $assigned['id'] !== (int) $host['id'] || empty($assigned['enabled']) ||
                empty($assigned['collection_enabled']) || !in_array($method,icct_backend_nd_host_methods($assigned),true)) continue;
            $snapshot = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol=?',[$host['id'],$method]);
            if ($snapshot && $snapshot['status'] === 'success' && hash_equals(icct_backend_nd_hash($assigned),$snapshot['config_hash']) &&
                strtotime($snapshot['succeeded_at'] ?? '') >= time() - (int) $assigned['stale_seconds']) {
                return json_decode($snapshot['data_json'],true,512,JSON_THROW_ON_ERROR);
            }
            return ['neighbors'=>[],'warning'=>'Waiting for the assigned collector to publish fresh '.strtoupper($method).' evidence.'];
        }
    }
    // An explicit range-scan request keeps its existing semantics for unassigned methods.
    // The zero ID denotes a discovery candidate, not a second monitored device.
    return icct_backend_nd_collect_direct(array_replace($host,['id'=>0]),$method,$deadline);
}
