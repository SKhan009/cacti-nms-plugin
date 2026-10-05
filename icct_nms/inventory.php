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
    if (($_GET['view'] ?? '') === 'tree' && $devices) {
        icct_nms_redirect('device.php?id='.(int)$devices[0]['id'].'&view=1&tree=1');
    }
} catch (Throwable $error) {
    icct_nms_failure($error);
}
$title = 'Inventory';
$management = is_realm_allowed(3);
$notice = $_SESSION['icct_nms_notice'] ?? '';
unset($_SESSION['icct_nms_notice']);
require __DIR__ . '/templates/header.php';
if (($_GET['view'] ?? '') === 'tree') {
    $id = 0;
    require __DIR__.'/templates/inventory_tree.php';
    echo '<p class="empty-state">No devices available.</p></div></div>';
} else {
    require __DIR__ . '/templates/list.php';
}
require __DIR__ . '/templates/footer.php';
