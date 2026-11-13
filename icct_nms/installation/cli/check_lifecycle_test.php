<?php

require dirname(__DIR__, 2) . '/setup.php';
$version = '1.2.30';
function db_fetch_cell($sql)
{
    return $GLOBALS['version'];
}
function db_fetch_assoc_prepared() {}
function api_plugin_register_hook() {}
function api_plugin_register_realm() {}
function api_plugin_enable_hooks() {}
function api_plugin_disable_hooks() {}
function cacti_log($message, $unused, $source)
{
    $GLOBALS['requirement_message'] = $message;
}
function db_execute_prepared($sql, $args)
{
    throw new LogicException('Requirements failure attempted a schema/registration write.');
}
if (icct_nms_dependency_issues()) {
    throw new RuntimeException('Cacti 1.2.30 with the required APIs was rejected.');
}
$version = '';
if (plugin_icct_nms_check_config() !== false) {
    throw new RuntimeException('Unsupported lifecycle accepted.');
}
if (
    strpos($GLOBALS['requirement_message'] ?? '', 'Cacti database version is unavailable') === false
) {
    throw new RuntimeException('No useful compatibility error.');
}
echo "PASS: compatible Cacti 1.2.30 passes and an unavailable database version stops before schema changes.\n";
