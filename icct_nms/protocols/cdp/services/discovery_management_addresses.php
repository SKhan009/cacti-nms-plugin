<?php
/** CDP discovery parsing. */

function icct_backend_nd_cdp_management_addresses($values, $root, $index)
{
    $addresses = [];
    $errors = [];
    foreach (
        [[19, 20, 'CDP primary management address'], [3, 4, 'CDP advertised network address']]
        as [$typeColumn, $addressColumn, $source]
    ) {
        try {
            $type = icct_backend_nd_value(
                $values,
                $root . '.' . $typeColumn . '.' . $index,
                2,
                false
            );
            $bytes = icct_backend_nd_value(
                $values,
                $root . '.' . $addressColumn . '.' . $index,
                4,
                false
            );
            if ($type === null || $bytes === null) {
                continue;
            }
            if ((int) $type !== 1) {
                $errors[] = 'Advertised CDP address protocol is not supported for onboarding.';
                continue;
            }
            $address = icct_backend_nd_management_address($bytes, 4, $source);
            if ($address && !isset($addresses[$address['address']])) {
                $addresses[$address['address']] = $address;
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    return [
        'addresses' => array_values($addresses),
        'errors' => array_values(array_unique($errors))
    ];
}

/** Reused Inventory service: nd lldp management addresses. */
