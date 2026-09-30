<?php
/**
 * Shared request helpers and strict integration boundary to the plugin-owned services and Cacti core.
 */

/**
 * Require enabled services and the existing schema; page requests never repair storage.
 */
function icct_nms_backend($lifecycle = false)
{
    global $config;
    if (!$lifecycle && !api_plugin_is_enabled('icct_nms')) {
        throw new RuntimeException('ICCT NMS is disabled. Enable it through Plugin Management.');
    }
    require_once __DIR__ . '/backend.php';
    if (!icct_backend_database_ready()) {
        throw new RuntimeException('Upgrade ICCT NMS through Cacti Plugin Management. Its own schema is not ready.');
    }
}

/**
 * Escape dynamic values for HTML text and quoted attributes.
 */
function icct_nms_h($value)
{
    return html_escape((string) $value);
}

/**
 * Validate a device identifier against the native supported integer range.
 */
function icct_nms_id($value)
{
    return icct_backend_topology_integer($value, 0, 16777215, 'Device ID');
}

/**
 * Require POST, native management permission and a valid Cacti CSRF token.
 */
function icct_nms_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    icct_backend_require_management(3);
    if (!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) {
        throw new RuntimeException('Invalid request token. Reload this page.');
    }
}

/**
 * Render the native CSRF token for an authenticated save form.
 */
function icct_nms_token()
{
    print '<input type="hidden" name="__csrf_magic" value="' . icct_nms_h(csrf_get_tokens()) . '">';
}

/**
 * Redirect after a successful write so refresh does not repeat the POST.
 */
function icct_nms_redirect($url)
{
    header('Location: ' . $url, true, 303);
    exit();
}

/**
 * Stop rendering with an escaped backend error rather than substitute data.
 */
function icct_nms_failure(Throwable $error)
{
    http_response_code(503);
    print '<!doctype html><html lang="en"><meta charset="utf-8"><title>Inventory unavailable</title><h1>Inventory unavailable</h1><p>' .
        icct_nms_h($error->getMessage()) .
        '</p></html>';
    exit();
}
