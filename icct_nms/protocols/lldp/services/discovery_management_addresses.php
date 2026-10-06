<?php
/** LLDP discovery parsing. */

function icct_backend_nd_lldp_management_addresses($values)
{
    $root = '1.0.8802.1.1.2.1.4.2.1.';
    $rows = [];
    $errors = [];
    foreach ($values as $oid => $value) {
        if (strpos($oid, $root) !== 0) {
            continue;
        }
        try {
            $suffix = substr($oid, strlen($root));
            if (!preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $suffix)) {
                throw new RuntimeException('Malformed LLDP management-address index.');
            }
            $parts = explode('.', $suffix);
            $family = (int) ($parts[4] ?? 0);
            $length = (int) ($parts[5] ?? 0);
            if (count($parts) !== 6 + $length || $length < 1 || $length > 31) {
                throw new RuntimeException('Malformed LLDP management-address length.');
            }
            if (!in_array($family, [1, 2], true)) {
                continue;
            }
            $bytes = '';
            foreach (array_slice($parts, 6) as $octet) {
                if (strlen($octet) > 3 || (int) $octet > 255) {
                    throw new RuntimeException('Invalid LLDP address octet.');
                }
                $bytes .= chr((int) $octet);
            }
            $address = icct_backend_nd_management_address(
                $bytes,
                $family === 1 ? 4 : 6,
                'LLDP management address'
            );
            if ($address) {
                $rows[implode('.', array_slice($parts, 1, 3))][$address['address']] = $address;
            }
        } catch (RuntimeException $e) {
            $errors[$e->getMessage()] = true;
        }
    }
    foreach ($rows as &$row) {
        $row = array_values($row);
    }
    unset($row);
    return ['rows' => $rows, 'errors' => array_keys($errors)];
}

/** Reused Inventory service: nd management address. */
