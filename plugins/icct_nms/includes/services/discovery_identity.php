<?php
/** ICCT-owned discovery identity services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: nd address key. */
function icct_backend_nd_address_key($address)
{
    $packed = @inet_pton((string) $address);
    return $packed === false ? '' : inet_ntop($packed);
}

/** Reused Inventory service: nd own addresses. */
function icct_backend_nd_own_addresses($values)
{
    $rows = [];
    ksort($values, SORT_NATURAL); // Modern status/type supersedes the legacy IPv4 row.
    foreach ($values as $oid => $value) {
        $ip = '';
        $zone = 0;
        $source = '';
        $type = 1;
        $status = 1;
        if (preg_match('/^1\.3\.6\.1\.2\.1\.4\.20\.1\.2\.(.+)$/D', $oid, $m)) {
            $ip = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $m[1] : '';
            $source = 'ipAddrTable';
        } elseif (preg_match('/^1\.3\.6\.1\.2\.1\.4\.34\.1\.3\.(.+)$/D', $oid, $m)) {
            $arcs = explode('.', $m[1]);
            if (count($arcs) < 2 || preg_match('/[^0-9.]/', $m[1])) {
                continue;
            }
            $family = (int) array_shift($arcs);
            $length = (int) array_shift($arcs);
            $expected = [1 => 4, 2 => 16, 3 => 8, 4 => 20][$family] ?? 0;
            if (!$expected || $length !== $expected || count($arcs) !== $expected) {
                continue;
            }
            foreach ($arcs as $arc) {
                if ((int) $arc > 255) {
                    continue 2;
                }
            }
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
        if (
            !$ip ||
            (int) ($value['type'] ?? 0) !== 2 ||
            !preg_match('/^[0-9]+$/D', (string) $value['value'])
        ) {
            continue;
        }
        $index = (int) $value['value'];
        if ($index < 1 || $index > 2147483647) {
            continue;
        }
        $ip = icct_backend_nd_address_key($ip);
        $rows[$ip . '%' . $zone . '|' . $index] = [
            'address' => $ip,
            'zone' => $zone,
            'ifindex' => $index,
            'source' => $source,
            'type' => $type,
            'status' => $status
        ];
    }
    ksort($rows, SORT_NATURAL);
    return array_values($rows);
}
