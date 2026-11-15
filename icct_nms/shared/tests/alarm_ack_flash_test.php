<?php
/** The acknowledgement notice is shown once without changing alarm records. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
function icct_nms_token(): void
{
    echo '<input type="hidden" name="__csrf_magic">';
}
function icct_nms_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
$management = false;
$alarmRows = [];
$_SESSION = ['icct_nms_alarm_ack_success' => true];
$template = __DIR__ . '/../../alarms/templates/list.php';
ob_start();
require $template;
$first = ob_get_clean();
if (!str_contains($first, 'Alarm acknowledged successfully.') || isset($_SESSION['icct_nms_alarm_ack_success'])) {
    throw new RuntimeException('Acknowledgement confirmation was not displayed and consumed.');
}
ob_start();
require $template;
$second = ob_get_clean();
if (str_contains($second, 'Alarm acknowledged successfully.')) {
    throw new RuntimeException('Acknowledgement confirmation appeared again after consumption.');
}
echo "PASS: acknowledgement confirmation appears once on the alarm list.\n";
$_SESSION = ['icct_nms_alarm_action_success' => 'Ticket opened successfully.'];
ob_start();
require $template;
$first = ob_get_clean();
if (!str_contains($first, 'Ticket opened successfully.') || isset($_SESSION['icct_nms_alarm_action_success'])) {
    throw new RuntimeException('Ticket confirmation was not displayed and consumed.');
}
ob_start();
require $template;
$second = ob_get_clean();
if (str_contains($second, 'Ticket opened successfully.')) {
    throw new RuntimeException('Ticket confirmation appeared again after consumption.');
}
echo "PASS: ticket confirmation appears once on the alarm list.\n";
