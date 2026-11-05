<?php

/** Read the bundled requirements without starting Cacti or accessing a repository. */
function icct_nms_installation_manifest()
{
    return json_decode(
        file_get_contents(__DIR__ . '/../dependencies.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

function icct_nms_php_requirement_issues()
{
    // JSON is built in on the supported PHP baseline.
    if (!extension_loaded('json')) {
        return ['PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ') is missing JSON support.'];
    }
    $requirements = icct_nms_installation_manifest();
    $runtime = 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
    $issues = [];
    if (version_compare(PHP_VERSION, $requirements['php_min'], '<')) {
        $issues[] = $runtime . ' is below the required version ' . $requirements['php_min'] . '.';
    }
    $missing = array_filter(
        $requirements['php_extensions'],
        static fn($ext) => !extension_loaded($ext),
    );
    if ($missing) {
        $issues[] = $runtime . ' is missing extensions: ' . implode(', ', $missing) . '.';
    }
    return $issues;
}

function icct_nms_cacti_requirement_issues()
{
    $apis = [
        'db_fetch_cell',
        'db_fetch_assoc_prepared',
        'db_execute_prepared',
        'api_plugin_register_hook',
        'api_plugin_register_realm',
        'api_plugin_enable_hooks',
        'api_plugin_disable_hooks',
    ];
    $missing = array_filter($apis, static fn($api) => !function_exists($api));
    if ($missing) {
        return ['Cacti is missing native APIs: ' . implode(', ', $missing) . '.'];
    }
    if (!(string) db_fetch_cell('SELECT cacti FROM version LIMIT 1')) {
        return ['Cacti database version is unavailable. Complete Cacti installation or upgrade.'];
    }
    return [];
}
