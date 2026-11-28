<?php
require dirname(__DIR__) . '/services/package_jobs.php';
$manifest = icct_nms_installation_manifest();
$catalog = icct_nms_installation_package_catalog($manifest);
if (isset($catalog['redis']) || isset($manifest['optional_components']['redis'])) {
    throw new RuntimeException('An unused Redis profile must not appear in Installation Checks or Offline Packages.');
}
foreach ($catalog as $profile) {
    foreach ($profile['packages'] as $names) {
        if (array_intersect(['redis', 'redis-server', 'php-redis'], $names)) {
            throw new RuntimeException('An installable feature must not request unused Redis packages.');
        }
    }
}
$covered = [];
foreach ($catalog as $id => $profile) {
    if (isset($manifest['rhel_package_groups'][$id])) {
        if ($profile['version_profile'] !== 'base' ||
            array_diff($profile['packages']['rhel'], $manifest['packages']['rhel'])) {
            throw new RuntimeException('A feature requests packages outside its verified base bundle.');
        }
        $covered = array_merge($covered, $profile['packages']['rhel']);
    }
}
if (array_diff($manifest['packages']['rhel'], $covered) ||
    array_diff_key($manifest['optional_components'], $catalog)) {
    throw new RuntimeException('The catalog omits an installable dependency.');
}
if (array_keys($catalog)[0] !== 'cacti-framework' ||
    $catalog['neighbor-discovery']['packages']['rhel'] !== ['php-snmp', 'net-snmp-utils'] ||
    $catalog['pathchar']['packages']['rhel'] !== ['icct-nms-pathchar']) {
    throw new RuntimeException('Cacti, SNMP discovery and Pathchar package actions are misclassified.');
}
echo "PASS: Every feature maps to its verified RPM profile and all dependencies are represented.\n";
