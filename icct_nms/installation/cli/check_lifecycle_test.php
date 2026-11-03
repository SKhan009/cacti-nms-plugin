<?php

require dirname(__DIR__, 2).'/setup.php';
function db_fetch_cell($sql)
{
    return '1.2.20';
}
function cacti_log($message, $unused, $source)
{
    $GLOBALS['requirement_message'] = $message;
}
function db_execute_prepared($sql, $args)
{
    throw new LogicException('Requirements failure attempted a schema/registration write.');
}
if (icct_nms_check_dependencies() !== false) {
    throw new RuntimeException('Unsupported Cacti accepted.');
}
if (plugin_icct_nms_check_config() !== false) {
    throw new RuntimeException('Unsupported lifecycle accepted.');
}
if (strpos($GLOBALS['requirement_message'] ?? '', '1.2.31') === false) {
    throw new RuntimeException('No useful compatibility error.');
}
echo "PASS: incompatible Cacti stops before schema mutation and reports requirements without a fatal error.\n";
