<?php
/** ICCT-owned discovery neighbors services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: nd column. */
function icct_backend_nd_column($values, $root, $column, $parts, $type)
{
    $out = [];
    $prefix = $root . '.' . $column . '.';
    foreach ($values as $oid => $v) {
        if (strpos($oid, $prefix) === 0) {
            $index = substr($oid, strlen($prefix));
            if (!preg_match('/^[0-9]+(?:\.[0-9]+){' . ($parts - 1) . '}$/D', $index)) {
                throw new RuntimeException('Malformed MIB table index at ' . $oid);
            }
            $out[$index] = icct_backend_nd_value($values, $oid, $type);
        }
    }
    return $out;
}

/** Reused Inventory service: nd interfaces. */
function icct_backend_nd_interfaces($values)
{
    $root = '1.3.6.1.2.1.2.2.1';
    $ports = [];
    foreach (icct_backend_nd_column($values, $root, 1, 1, 2) as $index => $value) {
        if ((string) $index !== (string) $value) {
            throw new RuntimeException('IF-MIB index does not match its row.');
        }
        $name = icct_backend_nd_value($values, '1.3.6.1.2.1.31.1.1.1.1.' . $index, 4, false);
        $alias = icct_backend_nd_value($values, '1.3.6.1.2.1.31.1.1.1.18.' . $index, 4, false);
        $mac = icct_backend_nd_value($values, $root . '.6.' . $index, 4, false);
        $speed = icct_backend_nd_value($values, $root . '.5.' . $index, 66, false);
        $high = icct_backend_nd_value($values, '1.3.6.1.2.1.31.1.1.1.15.' . $index, 66, false);
        $ports[$index] = [
            'index' => (int) $index,
            'if_type' => icct_backend_nd_value($values, $root . '.3.' . $index, 2, false),
            'name' => icct_backend_nd_octets($name),
            'name_hex' => $name === null ? '' : bin2hex($name),
            'alias' => icct_backend_nd_octets($alias),
            'in_octets' => icct_backend_nd_value(
                $values,
                '1.3.6.1.2.1.31.1.1.1.6.' . $index,
                70,
                false
            ),
            'out_octets' => icct_backend_nd_value(
                $values,
                '1.3.6.1.2.1.31.1.1.1.10.' . $index,
                70,
                false
            ),
            'discontinuity' => icct_backend_nd_value(
                $values,
                '1.3.6.1.2.1.31.1.1.1.19.' . $index,
                67,
                false
            ),
            'alias_hex' => $alias === null ? '' : bin2hex($alias),
            'mac_hex' => $mac === null ? '' : bin2hex($mac),
            'description' => icct_backend_nd_octets(
                icct_backend_nd_value($values, $root . '.2.' . $index, 4, false)
            ),
            'description_hex' => bin2hex(
                icct_backend_nd_value($values, $root . '.2.' . $index, 4, false) ?? ''
            ),
            'admin' => icct_backend_nd_value($values, $root . '.7.' . $index, 2, false),
            'oper' => icct_backend_nd_value($values, $root . '.8.' . $index, 2, false),
            'speed_bps' => $speed === null ? 0 : (int) $speed,
            'high_speed_mbps' => $high === null ? 0 : (int) $high
        ];
    }
    if (!$ports) {
        throw new RuntimeException('IF-MIB contains no readable ifIndex rows.');
    }
    return $ports;
}

/** Reused Inventory service: nd lldp identity. */
function icct_backend_nd_observation_history($previous, $current, $now)
{
    $merged = [];
    foreach ($previous as $key => $old) {
        if (($old['last_seen'] ?? 0) >= $now - 604800) {
            $old['present'] = false;
            $merged[$key] = $old;
        }
    }
    foreach ($current as $key => $row) {
        $row['first_seen'] = $previous[$key]['first_seen'] ?? $now;
        $row['last_seen'] = $now;
        $row['present'] = true;
        $merged[$key] = $row;
    }
    return $merged;
}

/** Reused Inventory service: nd octets. */
function icct_backend_nd_octets($value)
{
    if ($value === null) {
        return '';
    }
    return preg_match('//u', $value) && !preg_match('/[\x00-\x1f\x7f]/', $value)
        ? $value
        : 'hex:' . bin2hex($value);
}

/** Reused Inventory service: nd parse cdp. */
function icct_backend_nd_table_indices($values, $root, $parts)
{
    $indices = [];
    $prefix = $root . '.';
    foreach ($values as $oid => $v) {
        if (strpos($oid, $prefix) === 0) {
            $suffix = substr($oid, strlen($prefix));
            if (
                !preg_match(
                    '/^[0-9]+\.([0-9]+(?:\.[0-9]+){' . ($parts - 1) . '})$/D',
                    $suffix,
                    $match
                )
            ) {
                throw new RuntimeException('Malformed table row: ' . $oid);
            }
            $indices[$match[1]] = true;
        }
    }
    return array_keys($indices);
}

/** Reused Inventory service: nd value. */
function icct_backend_nd_value($values, $oid, $type, $required = true)
{
    if (!isset($values[$oid])) {
        if (!$required) {
            return null;
        }
        throw new RuntimeException(
            'Required MIB object is absent or excluded by the SNMP view: ' . $oid
        );
    }
    $v = $values[$oid];
    if ((int) $v['type'] !== $type) {
        throw new RuntimeException('Unexpected ASN.1 type at ' . $oid);
    }
    return $v['value'];
}

require_once __DIR__.'/../../../cdp/services/discovery_neighbors.php';

require_once __DIR__.'/../../../lldp/services/discovery_neighbors.php';
