<?php
/** Own-address evidence only: ARP and neighbour management addresses are not ownership. */
function nms_nd_address_key($address)
{
    $packed = @inet_pton((string) $address);
    return $packed === false ? '' : inet_ntop($packed);
}

/** IP-MIB ipAddrTable and ipAddressTable (RFC 4293), including scoped addresses. */
function nms_nd_own_addresses($values)
{
    $rows = [];
    ksort($values, SORT_NATURAL); // Modern status/type supersedes the legacy IPv4 row.
    foreach ($values as $oid => $value) {
        $ip = ''; $zone = 0; $source = ''; $type = 1; $status = 1;
        if (preg_match('/^1\.3\.6\.1\.2\.1\.4\.20\.1\.2\.(.+)$/D', $oid, $m)) {
            $ip = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $m[1] : '';
            $source = 'ipAddrTable';
        } elseif (preg_match('/^1\.3\.6\.1\.2\.1\.4\.34\.1\.3\.(.+)$/D', $oid, $m)) {
            $arcs = explode('.', $m[1]);
            if (count($arcs) < 2 || preg_match('/[^0-9.]/', $m[1])) continue;
            $family = (int) array_shift($arcs); $length = (int) array_shift($arcs);
            $expected = [1 => 4, 2 => 16, 3 => 8, 4 => 20][$family] ?? 0;
            if (!$expected || $length !== $expected || count($arcs) !== $expected) continue;
            foreach ($arcs as $arc) if ((int) $arc > 255) continue 2;
            $bytes = pack('C*', ...array_map('intval', $arcs));
            if ($family === 3 || $family === 4) {
                $zone = unpack('N', substr($bytes, -4))[1];
                $bytes = substr($bytes, 0, -4);
            }
            $ip = inet_ntop($bytes);
            $source = 'ipAddressTable';
            $type = (int) ($values['1.3.6.1.2.1.4.34.1.4.' . $m[1]]['value'] ?? 0);
            $status = (int) ($values['1.3.6.1.2.1.4.34.1.7.' . $m[1]]['value'] ?? 0);
        }
        if (!$ip || (int) ($value['type'] ?? 0) !== 2 || !preg_match('/^[0-9]+$/D', (string) $value['value'])) continue;
        $index = (int) $value['value'];
        if ($index < 1 || $index > 2147483647) continue;
        $ip = nms_nd_address_key($ip);
        $rows[$ip . '%' . $zone . '|' . $index] = [
            'address' => $ip, 'zone' => $zone, 'ifindex' => $index,
            'source' => $source, 'type' => $type, 'status' => $status,
        ];
    }
    ksort($rows, SORT_NATURAL);
    return array_values($rows);
}

/** Link-local, loopback, multicast and unspecified addresses cannot identify another host. */
function nms_nd_address_matchable($row)
{
    if (($row['zone'] ?? 0) || ($row['type'] ?? 0) !== 1 || !in_array($row['status'] ?? 0, [1, 2], true)) return false;
    $ip = nms_nd_address_key($row['address'] ?? '');
    if (!$ip) return false;
    $bytes = unpack('C*', inet_pton($ip));
    if (count($bytes) === 4) return $bytes[1] !== 0 && $bytes[1] !== 127 && $bytes[1] < 224 && !($bytes[1] === 169 && $bytes[2] === 254);
    return $ip !== '::' && $ip !== '::1' && $bytes[1] !== 255 && !($bytes[1] === 254 && ($bytes[2] & 192) === 128);
}

/** Exact complete chassis sets: placeholder serials and model-only matches are not identity. */
function nms_nd_chassis_key($data)
{
    $keys = [];
    foreach ($data['hardware']['chassis'] ?? [] as $chassis) {
        $serial = trim((string) ($chassis['serial'] ?? ''));
        $model = trim((string) ($chassis['model'] ?? ''));
        if ($model === '' || $serial === '' || preg_match('/^(unknown|none|n\/a|na|not specified|not available|0+|-+)$/iD', $serial)) return '';
        $keys[] = [$model, $serial];
    }
    sort($keys);
    return $keys ? json_encode($keys) : '';
}

/** Caller supplies only permitted hosts and freshness/configuration-validated snapshots. No core writes. */
function nms_nd_device_identities($hosts, $snapshots)
{
    $result = []; $evidence = []; $index = [];
    foreach ($hosts as $host) {
        $id = (int) $host['id'];
        $result[$id] = ['addresses' => [], 'matches' => [], 'message' => 'No current own-address evidence. Run discovery with SNMP enabled.', 'checked_at' => ''];
        $evidence[$id] = ['scope' => json_encode([(int) ($host['site_id'] ?? 0), (int) ($host['poller_id'] ?? 0), (string) ($host['snmp_context'] ?? '')]), 'primary' => nms_nd_address_key($host['hostname']), 'addresses' => [], 'chassis' => '', 'lldp' => ''];
    }
    foreach ($snapshots as $s) {
        $id = (int) $s['host_id'];
        if (!isset($result[$id]) || empty($s['valid']) || $s['status'] !== 'success') continue;
        if ($s['protocol'] === 'lldp') $evidence[$id]['lldp'] = (string) ($s['data']['identity'] ?? '');
        if ($s['protocol'] !== 'identity') continue;
        $data = $s['data'];
        $result[$id]['addresses'] = $data['own_addresses'] ?? [];
        $result[$id]['checked_at'] = $s['succeeded_at'] ?? '';
        $result[$id]['message'] = !empty($data['own_address_errors']) ? 'Some address tables could not be read; the list may be incomplete.' : ($result[$id]['addresses'] ? 'Addresses reported by this device through SNMP.' : 'No own addresses reported. The device may not expose IP-MIB; older snapshots need a new discovery run.');
        $evidence[$id]['chassis'] = nms_nd_chassis_key($data);
        foreach ($result[$id]['addresses'] as $address) {
            if (nms_nd_address_matchable($address)) $evidence[$id]['addresses'][nms_nd_address_key($address['address'])] = true;
        }
    }
    foreach ($evidence as $id => $e) {
        $keys = array_keys($e['addresses']);
        if ($e['primary']) $keys[] = $e['primary'];
        foreach (array_unique($keys) as $key) $index[$e['scope'] . '|ip|' . $key][$id] = true;
        if ($e['chassis']) $index[$e['scope'] . '|chassis|' . $e['chassis']][$id] = true;
    }
    $pairs = [];
    foreach ($index as $members) {
        $ids = array_keys($members);
        foreach ($ids as $a) foreach ($ids as $b) if ($a < $b) $pairs[$a . ':' . $b] = [$a, $b];
    }
    foreach ($pairs as [$a, $b]) {
        $ea = $evidence[$a]; $eb = $evidence[$b];
        // A configured address alone is not evidence that either device owns it.
        $overlap = array_intersect_key($ea['addresses'], $eb['addresses']);
        $aOwnsB = isset($ea['addresses'][$eb['primary']]);
        $bOwnsA = isset($eb['addresses'][$ea['primary']]);
        $sameChassis = $ea['chassis'] !== '' && $ea['chassis'] === $eb['chassis'];
        if (!$overlap && !$aOwnsB && !$bOwnsA && !$sameChassis) continue;
        $conflict = $ea['chassis'] !== '' && $eb['chassis'] !== '' && !$sameChassis;
        $lldpConflict = $ea['lldp'] !== '' && $eb['lldp'] !== '' && $ea['lldp'] !== $eb['lldp'];
        $sameLldp = $ea['lldp'] !== '' && $ea['lldp'] === $eb['lldp'];
        $strong = !$conflict && !$lldpConflict && $sameChassis && (($aOwnsB && $bOwnsA) || $sameLldp);
        $state = $conflict || $lldpConflict ? 'Conflicting identity' : ($strong ? 'Matching device evidence' : 'Possible same device / shared address');
        $reason = $conflict || $lldpConflict ? 'Address or chassis evidence overlaps, but device identities disagree. Review shared or virtual addresses.' : ($strong ? 'Matching chassis serial/model sets, supported by reciprocal own addresses or LLDP identity.' : 'Additional identity evidence is needed; shared addresses and virtual devices can overlap.');
        foreach ([[$a, $b], [$b, $a]] as [$owner, $peer]) $result[$owner]['matches'][] = ['host_id' => $peer, 'state' => $state, 'reason' => $reason];
    }
    return $result;
}
