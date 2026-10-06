<?php
/** Protect the feature layout and static page dependencies after moving HTTP routes. */
$root = dirname(__DIR__, 2);
$directories = ['configuration', 'dashboard', 'fcaps', 'graphs', 'inventory', 'ports', 'presets', 'protocols', 'shared'];
$actual = array_values(array_filter(scandir($root), fn($name) => $name[0] !== '.' && is_dir($root . '/' . $name)));
sort($actual);
if ($actual !== $directories) throw new RuntimeException('Unexpected root directories: ' . implode(', ', $actual));
$entries = glob($root . '/*.php');
if (array_map('basename', $entries) !== ['setup.php']) throw new RuntimeException('Only setup.php belongs at the plugin root.');
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$count = 0;
foreach ($files as $file) {
    if ($file->getExtension() !== 'php' || strpos($file->getPathname(), '/templates/') === false) continue;
    preg_match_all('~(?:href|src|action)="([^"<>]+)"~', file_get_contents($file->getPathname()), $matches);
    foreach ($matches[1] as $url) {
        if (!preg_match('~^(?:inventory|dashboard|protocols|presets|ports|fcaps|graphs|configuration|shared)/~', $url)) continue;
        $path = parse_url(html_entity_decode($url), PHP_URL_PATH);
        if (!is_file($root . '/' . $path)) throw new RuntimeException('Missing page dependency: ' . $url . ' in ' . $file->getPathname());
        $count++;
    }
}
foreach (['inventory/diagnostics/cli/diagnostic_listener.php', 'inventory/diagnostics/cli/diagnostic_worker.php', 'protocols/syslog/cli/syslog_worker.php', 'shared/database/schema.sql', 'protocols/snmp/mibs/storage/.htaccess'] as $path) {
    if (!is_file($root . '/' . $path)) throw new RuntimeException('Missing runtime dependency: ' . $path);
}
echo "PASS: clean feature root, $count static page dependencies, CLI workers, schema and protected MIB storage.\n";

$rules = file_get_contents($root . '/.htaccess');
$config = file_get_contents($root . '/shared/deployment/apache-routes.conf');
if (strpos($config, $rules) === false) throw new RuntimeException('Apache and .htaccess routes must stay synchronized.');
preg_match_all('~^RewriteRule \^([a-z_]+)\\\.php\$ ([a-z/_]+\.php) \[END\]$~m', $rules, $routes, PREG_SET_ORDER);
if (count($routes) !== 14) throw new RuntimeException('Every previous HTTP endpoint needs a compatibility route.');
foreach ($routes as $route) {
    if (!is_file($root . '/' . $route[2])) throw new RuntimeException('Legacy URL has no controller: ' . $route[1]);
}
echo "PASS: all 14 legacy HTTP endpoints map to existing feature controllers.\n";
