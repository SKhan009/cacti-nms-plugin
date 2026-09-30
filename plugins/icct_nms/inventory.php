<?php
/**
 * Inventory controller: authenticate, load permitted saved devices and render the list.
 */

require __DIR__ . '/../../include/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory.php';
try {
    icct_nms_backend();
    $devices = icct_nms_inventory();
} catch (Throwable $error) {
    icct_nms_failure($error);
}
$title = 'Inventory';
$management = is_realm_allowed(3);
$notice = $_SESSION['icct_nms_notice'] ?? '';
unset($_SESSION['icct_nms_notice']);
require __DIR__ . '/templates/header.php';
require __DIR__ . '/templates/list.php';
require __DIR__ . '/templates/footer.php';
