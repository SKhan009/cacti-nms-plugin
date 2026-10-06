<?php
/** ICCT-owned discovery management addresses services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: nd cdp management addresses. */
function icct_backend_nd_management_address($bytes, $family, $source)
{
    if (
        !is_string($bytes) ||
        !in_array($family, [4, 6], true) ||
        strlen($bytes) !== ($family === 4 ? 4 : 16)
    ) {
        throw new RuntimeException('Malformed advertised management address.');
    }
    $address = inet_ntop($bytes);
    if ($bytes === str_repeat("\0", strlen($bytes))) {
        return null;
    }
    $scoped = $family === 6 && ord($bytes[0]) === 254 && (ord($bytes[1]) & 192) === 128;
    $unicast = $family === 4 ? ord($bytes[0]) < 224 : ord($bytes[0]) !== 255;
    return [
        'address' => $address,
        'family' => $family,
        'source' => $source,
        'requires_scope' => $scoped,
        'eligible_target' => $unicast && !$scoped,
        'verified' => false
    ];
}

require_once __DIR__.'/../../../cdp/services/discovery_management_addresses.php';

require_once __DIR__.'/../../../lldp/services/discovery_management_addresses.php';
