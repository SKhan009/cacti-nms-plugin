<?php
require dirname(__DIR__) . '/services/checks.php';
foreach (
    [
        [[], 'Not checked'],
        [['path' => '', 'version' => '', 'executable' => false], 'Missing'],
        [
            ['path' => '/usr/bin/python3', 'version' => '3.8.20', 'executable' => true],
            'Needs setup',
        ],
        [
            ['path' => '/usr/bin/python3', 'version' => '3.12.3', 'executable' => false],
            'Needs setup',
        ],
        [['path' => '/usr/bin/python3', 'version' => '', 'executable' => true], 'Not checked'],
        [['path' => '/usr/bin/python3', 'version' => '3.9.0', 'executable' => true], 'Ready'],
        [['path' => '/usr/bin/python3', 'version' => '3.12.3', 'executable' => true], 'Ready'],
    ]
    as [$runtime, $expected]
) {
    if (icct_nms_installation_python_status($runtime, '3.9.0') !== $expected) {
        throw new RuntimeException('Python runtime readiness is incorrect.');
    }
}
echo "PASS: old, unmeasured and non-executable runtimes are never marked ready.\n";
