<?php
/**
 * Inventory controller: authenticate, load permitted saved devices and render the list.
 */

require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/inventory/services/inventory.php';
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
require dirname(__DIR__, 2) . '/shared/templates/header.php';
if (($_GET['view'] ?? '') === 'tree') {
    $id = 0;
    require dirname(__DIR__, 2) . '/inventory/templates/inventory_tree.php';
    echo '<p class="empty-state">No devices available.</p></div></div>';
} else {
    require dirname(__DIR__, 2) . '/inventory/templates/list.php';
}
require dirname(__DIR__, 2) . '/shared/templates/footer.php';
