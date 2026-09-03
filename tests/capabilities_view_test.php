<?php
/** Static contract for the read-only, per-device FCAPS page. */
function capability_view_assert($pass, $message) { if (!$pass) throw new RuntimeException($message); }
$controller = file_get_contents(__DIR__ . '/../capabilities.php');
$template = file_get_contents(__DIR__ . '/../templates/capabilities.php');
$navigation = file_get_contents(__DIR__ . '/../templates/navigation.php');
capability_view_assert(strpos($controller, 'nms_visible_host_sql()') !== false, 'FCAPS device selection bypasses Cacti visibility');
capability_view_assert(strpos($controller, 'nms_device_capabilities($capability_device)') !== false, 'FCAPS evidence helper is not used');
capability_view_assert(strpos($controller . $template, 'INSERT ') === false && strpos($controller . $template, 'UPDATE ') === false && strpos($controller . $template, 'DELETE ') === false, 'FCAPS page must remain read-only');
capability_view_assert(strpos($template, 'name="host_id"') !== false, 'Per-device selector missing');
capability_view_assert(strpos($template, 'Configured collection is not proof') !== false, 'Evidence limitation is not visible');
capability_view_assert(strpos($navigation, 'href="capabilities.php"') !== false, 'Fault submenu link missing');
print "Per-device FCAPS view checks passed.\n";
