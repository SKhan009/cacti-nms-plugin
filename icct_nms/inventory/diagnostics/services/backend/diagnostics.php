<?php

require_once __DIR__ . '/../../../../protocols/ping/services/command.php';
/** ICCT-owned diagnostics services, derived from the existing ICCT NMS implementation. */

/** Read interface names from the collector itself; no network discovery is needed. */
function icct_backend_diag_local_interfaces($directory = '/sys/class/net')
{
    $interfaces = [];
    foreach (glob($directory . '/*') ?: [] as $path) {
        $name = basename($path);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,14}$/D', $name)) {
            $interfaces[$name] = $name;
        }
    }
    ksort($interfaces, SORT_NATURAL);
    return $interfaces;
}

/** Stable bits fit the existing profile column; no schema or package upgrade required. */
function icct_backend_diag_arp_types()
{
    return [1=>'Physical — Ethernet (copper or fiber)',2=>'Physical — Wi-Fi',4=>'Physical — USB Ethernet adapter',
        8=>'Software / Virtual — VLAN',16=>'Software / Virtual — Bridge',32=>'Software / Virtual — Bond',
        64=>'Software / Virtual — Virtual Ethernet pair',128=>'Software / Virtual — VM virtual NIC',
        256=>'Software / Virtual — TAP',512=>'Software / Virtual — MACVLAN',1024=>'Software / Virtual — VXLAN'];
}

function icct_backend_diag_arp_classify(array $link)
{
    if (($link['link_type'] ?? '') !== 'ether' || array_intersect(['NOARP','LOOPBACK'], $link['flags'] ?? [])) return 0;
    $kind = $link['linkinfo']['info_kind'] ?? '';
    $types = ['vlan'=>8,'bridge'=>16,'bond'=>32,'veth'=>64,'macvlan'=>512,'vxlan'=>1024];
    if (isset($types[$kind])) return $types[$kind];
    if ($kind === 'tun') return ($link['linkinfo']['info_data']['type'] ?? '') === 'tap' ? 256 : 0;
    if ($kind !== '') return 0; // Unknown tunnel/device kinds are not guessed from names.
    if (!empty($link['wireless'])) return 2;
    if (!empty($link['usb'])) return 4;
    if (!empty($link['vm_nic'])) return 128;
    return !empty($link['physical']) ? 1 : 0;
}

function icct_backend_diag_arp_interfaces()
{
    $ip = icct_backend_diag_program('ip');
    if (!$ip) return [];
    $result = icct_backend_diag_run_command([$ip, '-j', '-d', 'link', 'show'], 5);
    if ($result['exit'] !== 0) return [];
    $links = json_decode($result['stdout'], true);
    // DMI is optional on the collector, including on some ARM systems.
    $productPath = '/sys/class/dmi/id/product_name';
    $product = is_readable($productPath) ? (file_get_contents($productPath) ?: '') : '';
    $virtualMachine = preg_match('/KVM|VirtualBox|VMware|Virtual Machine|QEMU|Parallels/i', $product);
    $out = [];
    foreach (is_array($links) ? $links : [] as $link) {
        $name = $link['ifname'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,14}$/D', $name)) continue;
        $base = '/sys/class/net/' . $name;
        $device = realpath($base . '/device') ?: '';
        $driver = basename(realpath($base . '/device/driver') ?: '');
        $link['physical'] = $device !== '';
        $link['wireless'] = is_dir($base . '/wireless') || is_dir($base . '/phy80211');
        $link['usb'] = strpos($device, '/usb') !== false;
        $link['vm_nic'] = in_array($driver, ['virtio_net','vmxnet3','hv_netvsc','xen_netfront'], true) || strpos($device, '/virtio') !== false;
        // Emulated PCI NICs in a VM also use physical-looking drivers.
        if ($device !== '' && $virtualMachine) $link['vm_nic'] = true;
        $type = icct_backend_diag_arp_classify($link);
        if ($type) $out[$name] = $type;
    }
    return $out;
}

function icct_backend_diag_arp_inventory($collector)
{
    if ((int) $collector === icct_backend_inventory_collector_id()) return icct_backend_diag_arp_interfaces();
    $snapshot = json_decode((string) db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=? AND updated_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)',
        ['diagnostic_runner_' . (int) $collector]), true);
    return array_filter($snapshot['arp_interfaces'] ?? [], static fn($type) => isset(icct_backend_diag_arp_types()[$type]));
}

function icct_backend_diag_interface_choices($collector)
{
    return array_intersect_key(icct_backend_diag_arp_types(), array_flip(array_values(icct_backend_diag_arp_inventory($collector))));
}

function icct_backend_diag_arp_selected($value, $inventory)
{
    if (str_starts_with($value, '@arp:')) {
        $mask = (int) substr($value, 5);
        return array_keys(array_filter(icct_backend_diag_arp_types(), static fn($label, $bit) => ($mask & $bit) !== 0, ARRAY_FILTER_USE_BOTH));
    }
    return isset($inventory[$value]) ? [$inventory[$value]] : [];
}

function icct_backend_diag_arp_resolve($value, array $inventory)
{
    if ($value === '' || $value === '@arp:0') return array_keys($inventory);
    if (!str_starts_with($value, '@arp:')) return isset($inventory[$value]) ? [$value] : [];
    $mask = (int) substr($value, 5);
    return array_keys(array_filter($inventory, static fn($bit) => ($mask & $bit) !== 0));
}

function icct_backend_diag_arguments($row, $tool, $name)
{
    $target = icct_backend_diag_target($row['hostname']);
    icct_backend_diag_profile_validate([
        'diagnostic_profile_name' => $row['name'],
        'diagnostic_tools' => [$tool],
        'ping_count' => $row['ping_count'],
        'mtr_cycles' => $row['mtr_cycles'] ?? $row['ping_count'],
        'mtr_background' => 0,
        'mtr_interval' => $row['mtr_interval'] ?? 300,
        'arp_interface' => $row['arp_interface'] ?? '',
        'pathchar_hops' => $row['pathchar_hops'] ?? 20,
        'pathchar_timeout' => $row['pathchar_timeout'] ?? 60,
        'trace_hops' => $row['trace_hops'],
        'bandwidth_seconds' => $row['bandwidth_seconds'],
    ]);
    $ipv6 = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? ['-6'] : [];

    $timeout = 40;
    switch ($tool) {
        case 'arp':
            $args = ['-4', 'neigh', 'show'];
            if (!empty($row['arp_interface']) && !str_starts_with($row['arp_interface'], '@arp:')) {
                array_push($args, 'dev', $row['arp_interface']);
            }
            $timeout = 5;
            break;
        case 'ping':
            [$args, $timeout] = icct_backend_ping_command($row, $target);
            break;
        case 'traceroute':
            $args =
                $name === 'tracepath'
                    ? ['-n', '-m', (string) $row['trace_hops'], $target]
                    : ['-n', '-q', '1', '-m', (string) $row['trace_hops'], '-w', '2', $target];
            $timeout = 2 * $row['trace_hops'] + 5;
            break;
        case 'traceroute_icmp':
        case 'traceroute_tcp':
            $args = array_merge(
                $ipv6,
                ['-n', '-q', '1', '-m', (string) $row['trace_hops'], '-w', '2'],
                $tool === 'traceroute_tcp' ? ['-T', '-p', '443'] : ['-I'],
                [$target],
            );
            $timeout = 2 * $row['trace_hops'] + 5;
            break;
        case 'mtr_icmp':
        case 'mtr_tcp':
            $args = array_merge(
                $ipv6,
                [
                    '--report',
                    '--no-dns',
                    '--report-cycles',
                    (string) ($row['mtr_cycles'] ?? $row['ping_count']),
                    '--max-ttl',
                    (string) $row['trace_hops'],
                    '--interval',
                    '1',
                ],
                $tool === 'mtr_tcp' ? ['--tcp', '--port', '443'] : [],
                [$target],
            );
            $timeout = 60;
            break;
        case 'iperf3_udp':
        case 'iperf3':
            $args = ['-c', $target, '-p', '5201', '-t', (string) $row['bandwidth_seconds']];
            if ($tool === 'iperf3_udp') {
                array_push($args, '-u', '-b', '1M');
            }
            $timeout = $row['bandwidth_seconds'] + 10;
            break;
        case 'netperf':
            $args = [
                '-H',
                $target,
                '-p',
                '12865',
                '-t',
                'TCP_STREAM',
                '-l',
                (string) $row['bandwidth_seconds'],
            ];
            $timeout = $row['bandwidth_seconds'] + 10;
            break;
        case 'pathchar':
            $args =
                $name === 'pchar'
                    ? [
                        '-n',
                        '-H',
                        (string) ($row['pathchar_hops'] ?? 20),
                        '-R',
                        '3',
                        '-I',
                        '128',
                        $target,
                    ]
                    : ['-n', $target];
            $timeout = (int) ($row['pathchar_timeout'] ?? 60);
            break;
        default:
            throw new InvalidArgumentException('Unsupported diagnostic tool.');
    }
    return [$args, $timeout];
}

function icct_backend_diag_assignment($host_id, $tool)
{
    if (!isset(icct_backend_diag_labels()[$tool])) {
        throw new InvalidArgumentException('Unsupported diagnostic tool.');
    }
    $row = db_fetch_row_prepared(
        "SELECT h.id AS host_id, h.hostname, h.description, h.poller_id, h.disabled, p.*\n\t\tFROM " .
            "host h JOIN plugin_icct_nms_diagnostic_devices d ON d.host_id=h.id\n\t\tJOIN " .
            "plugin_icct_nms_diagnostic_profiles p ON p.id=d.profile_id\n\t\tWHERE h.id=? AND " .
            "h.deleted=''",
        [(int) $host_id],
    );
    if (!$row || $row['disabled'] !== '') {
        throw new RuntimeException('Select an enabled device with a diagnostic profile.');
    }
    if (!in_array($tool, icct_backend_diag_tools($row['tools']), true)) {
        throw new RuntimeException('This tool is not enabled by the assigned profile.');
    }
    $row['hostname'] = icct_backend_diag_target($row['hostname']);
    // Revalidate saved bounds as well as form input.
    icct_backend_diag_profile_validate([
        'diagnostic_profile_name' => $row['name'],
        'diagnostic_tools' => $row['tools'],
        'ping_count' => $row['ping_count'],
        'mtr_cycles' => $row['mtr_cycles'] ?? $row['ping_count'],
        'mtr_background' => $row['mtr_background'] ?? 0,
        'mtr_interval' => $row['mtr_interval'] ?? 300,
        'arp_interface' => $row['arp_interface'] ?? '',
        'pathchar_hops' => $row['pathchar_hops'] ?? 20,
        'pathchar_timeout' => $row['pathchar_timeout'] ?? 60,
        'trace_hops' => $row['trace_hops'],
        'bandwidth_seconds' => $row['bandwidth_seconds'],
    ]);
    return $row;
}

function icct_backend_diag_available_labels()
{
    return icct_backend_diag_labels();
}

/** One saved per-device selection for Inventory, Diagnostics and topology actions. */
function icct_backend_diag_selected_labels($host_id)
{
    icct_backend_require_device_access($host_id);
    $row = db_fetch_row_prepared(
        'SELECT h.disabled,p.tools FROM host h JOIN plugin_icct_nms_diagnostic_devices d ON ' .
            'd.host_id=h.id JOIN plugin_icct_nms_diagnostic_profiles p ON p.id=d.profile_id WHERE ' .
            'h.id=? AND h.deleted=\'\'',
        [(int) $host_id],
    );
    if (!$row || $row['disabled'] !== '') {
        return [];
    }
    return array_intersect_key(
        icct_backend_diag_available_labels(),
        array_flip(icct_backend_diag_tools($row['tools'])),
    );
}

function icct_backend_diag_command($row, $tool)
{
    [$name, $binary] = icct_backend_diag_executable($tool);
    if (!$binary && $tool === 'pathchar') {
        throw new RuntimeException(
            'Path capacity estimation requires pathchar or pchar on this collector. Install an ' .
                'approved build matching the collector OS and architecture; raw-socket permission is ' .
                'also required. Traceroute cannot replace this measurement.',
        );
    }
    if (!$binary) {
        throw new RuntimeException(
            $name .
                ' was not found or is not executable by this collector. Install the matching tool for its OS and architecture.',
        );
    }
    [$args, $timeout] = icct_backend_diag_arguments($row, $tool, $name);
    return [array_merge([$binary], $args), $timeout];
}

function icct_backend_diag_drain($pipe, &$buffer, &$truncated, $limit)
{
    // Bound work per iteration too: a noisy child must not starve the timeout check.
    for ($i = 0; $i < 8; $i++) {
        $chunk = fread($pipe, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $remaining = max(0, $limit - strlen($buffer));
        $buffer .= substr($chunk, 0, $remaining);
        $truncated = $truncated || strlen($chunk) > $remaining;
    }
}

function icct_backend_diag_executable($tool, $lookup = null)
{
    if (!isset(icct_backend_diag_labels()[$tool])) {
        throw new InvalidArgumentException('Unsupported diagnostic tool.');
    }
    $lookup = $lookup ?? 'icct_backend_diag_program';
    $name = $tool === 'arp' ? 'ip' : explode('_', $tool)[0];
    $binary = $lookup($name);
    if (!$binary && $tool === 'pathchar') {
        $name = 'pchar';
        $binary = $lookup($name);
    }
    // tracepath supports only the legacy UDP check, never TCP or ICMP modes.
    if (!$binary && $tool === 'traceroute') {
        $name = 'tracepath';
        $binary = $lookup($name);
    }
    return [$name, $binary];
}

function icct_backend_diag_execute($row, $tool)
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Diagnostics must run through the Cacti poller queue.');
    }
    if (PHP_OS_FAMILY !== 'Linux') {
        throw new RuntimeException('These diagnostic command options require a Linux collector.');
    }

    if (icct_backend_inventory_collector_id() !== (int) $row['poller_id']) {
        throw new RuntimeException('Diagnostic belongs to another collector.');
    }
    $arpNames = [];
    if ($tool === 'arp') {
        $arpNames = icct_backend_diag_arp_resolve($row['arp_interface'] ?? '', icct_backend_diag_arp_interfaces());
        if (!$arpNames) throw new RuntimeException('No ARP-capable interfaces match the selection on this collector.');
    }
    [$command, $timeout] = icct_backend_diag_command($row, $tool);

    if (
        in_array($tool, ['iperf3', 'iperf3_udp'], true) &&
        icct_backend_diag_collector_address($row['hostname'])
    ) {
        [$command, $result] = icct_backend_diag_iperf_self_test($command, $timeout);
    } elseif ($tool === 'netperf' && icct_backend_diag_collector_address($row['hostname'])) {
        [$command, $result] = icct_backend_diag_netperf_self_test($command, $timeout);
    } else {
        $result = icct_backend_diag_run_command($command, $timeout);
    }
    if ($tool === 'arp' && $result['exit'] === 0) {
        $lines = preg_split('/\r?\n/', trim($result['stdout']));
        $lines = array_filter($lines, static function ($line) use ($arpNames) {
            return preg_match('/\bdev\s+(\S+)/', $line, $m) && in_array($m[1], $arpNames, true);
        });
        // A legacy single-interface command omits "dev" in its output.
        if (empty($row['arp_interface']) || str_starts_with($row['arp_interface'], '@arp:')) {
            $result['stdout'] = implode("\n", $lines);
            $result['output'] = $result['stdout'];
        }
    }
    if ($tool === 'pathchar') {
        $result['self_test'] = icct_backend_diag_collector_address($row['hostname']);
    }
    return icct_backend_diag_result($row, $tool, $command, $result);
}

function icct_backend_diag_labels()
{
    return [
        'ping' => 'Ping ICMP',
        'traceroute' => 'Traceroute UDP',
        'traceroute_icmp' => 'Traceroute ICMP',
        'traceroute_tcp' => 'Traceroute TCP',
        'mtr_icmp' => 'MTR ICMP',
        'mtr_tcp' => 'MTR TCP',
        'arp' => 'ARP',
        'iperf3' => 'iPerf3',
        'iperf3_udp' => 'iPerf3 UDP',
        'netperf' => 'Netperf',
        'pathchar' => 'Pathchar',
    ];
}

function icct_backend_diag_profile_validate($input)
{
    $name = trim((string) ($input['diagnostic_profile_name'] ?? ''));
    if ($name === '' || strlen($name) > 100) {
        throw new InvalidArgumentException(
            'Enter a diagnostic profile name of up to 100 characters.',
        );
    }

    $tools = icct_backend_diag_tools($input['diagnostic_tools'] ?? []);
    if (!$tools) {
        throw new InvalidArgumentException('Select at least one diagnostic tool.');
    }

    $ping = (int) ($input['ping_count'] ?? 4);
    $mtr = (int) ($input['mtr_cycles'] ?? $ping);
    $hops = (int) ($input['trace_hops'] ?? 20);
    $seconds = (int) ($input['bandwidth_seconds'] ?? 10);
    if (
        $ping < 1 ||
        $ping > 10 ||
        $mtr < 1 ||
        $mtr > 30 ||
        $hops < 1 ||
        $hops > 30 ||
        $seconds < 1 ||
        $seconds > 30
    ) {
        throw new InvalidArgumentException(
            'Use 1–10 ping packets, 1–30 MTR readings, 1–30 hops, and a 1–30 second bandwidth test.',
        );
    }

    $background = empty($input['mtr_background']) ? 0 : 1;
    $interval = (int) ($input['mtr_interval'] ?? 300);
    if ($interval < 60 || $interval > 3600) {
        throw new InvalidArgumentException('Use an MTR monitoring interval of 60–3600 seconds.');
    }
    if ($background && !array_intersect($tools, ['mtr_icmp', 'mtr_tcp'])) {
        throw new InvalidArgumentException('Select an MTR method for automatic monitoring.');
    }
    $arpInterface = trim((string) ($input['arp_interface'] ?? ''));
    if (isset($input['arp_types_present'])) {
        $bits = $input['arp_types'] ?? [];
        if (!is_array($bits)) throw new InvalidArgumentException('Select valid ARP interface types.');
        $mask = 0;
        foreach ($bits as $bit) {
            if (!is_scalar($bit) || !ctype_digit((string) $bit) || !isset(icct_backend_diag_arp_types()[(int) $bit])) throw new InvalidArgumentException('Select valid ARP interface types.');
            $mask |= (int) $bit;
        }
        $arpInterface = '@arp:' . $mask;
    }
    if ($arpInterface !== '' && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,14}$/D', $arpInterface) &&
        !(preg_match('/^@arp:([0-9]{1,4})$/D', $arpInterface, $m) && (int) $m[1] <= 2047)) {
        throw new InvalidArgumentException('Select valid collector interface types.');
    }
    $pathHops = (int) ($input['pathchar_hops'] ?? 20);
    $pathTimeout = (int) ($input['pathchar_timeout'] ?? 60);
    if ($pathHops < 1 || $pathHops > 30 || $pathTimeout < 10 || $pathTimeout > 120) {
        throw new InvalidArgumentException(
            'Use 1–30 Pathchar hops and a 10–120 second time limit.',
        );
    }
    return [
        'name' => $name,
        'tools' => implode(',', $tools),
        'ping_count' => $ping,
        'trace_hops' => $hops,
        'bandwidth_seconds' => $seconds,
        'mtr_cycles' => $mtr,
        'mtr_background' => $background,
        'mtr_interval' => $interval,
        'arp_interface' => $arpInterface,
        'pathchar_hops' => $pathHops,
        'pathchar_timeout' => $pathTimeout,
    ];
}

function icct_backend_diag_program($name)
{
    foreach (
        ['/usr/bin/', '/usr/sbin/', '/usr/local/bin/', '/usr/local/sbin/', '/bin/', '/sbin/']
        as $directory
    ) {
        $candidate = $directory . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    $bundled = '/opt/icct-nms/native/pchar-1.5/sbin/pchar';
    return $name === 'pchar' && is_file($bundled) && is_executable($bundled) ? $bundled : '';
}

function icct_backend_diag_result($row, $tool, $command, $result)
{
    if ($tool === 'iperf3_udp' && $result['exit'] === 0 && empty($result['truncated'])) {
        $metrics = icct_backend_diag_udp_metrics($result['stdout']);
        if ($metrics === null) {
            $result['protocol_error'] = true;
            $result['output'] .=
                "\nThe client returned no complete iPerf3 UDP receiver measurement.";
        } else {
            $result['udp_metrics'] = $metrics;
            $result['output'] =
                sprintf(
                    "Receiver throughput: %s Mbit/s\nReceiver jitter: %s ms\nReceiver packet loss: %.6g%% (%d/%d datagrams)\n\n",
                    $metrics['throughput'],
                    $metrics['jitter'],
                    $metrics['loss'],
                    $metrics['lost'],
                    $metrics['total'],
                ) . $result['output'];
        }
    }
    if (in_array($tool, ['iperf3', 'iperf3_udp'], true) && !empty($result['truncated'])) {
        $result['protocol_error'] = true;
        $result['output'] .=
            "\nThe iPerf3 response was truncated; no complete bandwidth measurement is available.";
    }
    if (
        $tool === 'iperf3' &&
        empty($result['truncated']) &&
        $result['exit'] === 0 &&
        !preg_match(
            '/^\[\s*(?:[0-9]+|SUM)\]\s+[0-9.]+\s*-\s*[0-9.]+\s+sec\s+[0-9.]+\s+[KMGT]?Bytes\s+[0-9.]+\s+[KMGT]?bits\/sec[^\r\n]*\breceiver[ \t]*\r?$/m',
            $result['stdout'],
        )
    ) {
        $result['protocol_error'] = true;
        $result['output'] .= "\nThe client returned no complete iPerf3 receiver measurement.";
    }
    if (preg_match('/permission denied|operation not permitted/i', $result['output'])) {
        $result['output'] .=
            "\nThe collector runtime denied this operation. ICCT NMS cannot override host permissions; no OS settings were changed.";
    } elseif ($result['exit'] !== 0 && $tool === 'iperf3_udp') {
        $result['output'] .= "\nVerify iPerf3 and both TCP and UDP 5201 at the target.";
    } elseif ($result['exit'] !== 0 && in_array($tool, ['iperf3', 'iperf3_udp', 'netperf'], true)) {
        $result['output'] .=
            $tool === 'iperf3'
                ? "\nVerify an authorised iperf3 server at the target on TCP 5201 and the network path."
                : "\nVerify netserver at the target on TCP 12865 and permit its separate negotiated TCP data connection.";
    }
    if (
        $tool === 'arp' &&
        $result['exit'] === 0 &&
        trim($result['stdout']) === '' &&
        trim($result['stderr']) === ''
    ) {
        $result['output'] = 'No IPv4 ARP neighbours are cached on the selected collector interfaces.';
    }
    if (
        in_array($tool, ['iperf3', 'iperf3_udp', 'netperf'], true) &&
        !empty($result['self_test'])
    ) {
        $result['output'] =
            "Collector local-address self-test — this is not network-link bandwidth.\nTemporary local server stopped after the test.\n\n" .
            $result['output'];
    } elseif ($tool === 'iperf3' && stripos($result['output'], 'Bad file descriptor') !== false) {
        $result['output'] =
            'No bandwidth measurement completed. iPerf3 could not establish its control connection. ' .
            "Check that the target runs an iPerf3 server on TCP 5201.\n\n" .
            $result['output'];
    }
    $result['output'] =
        '$ ' . implode(' ', array_map('escapeshellarg', $command)) . "\n" . $result['output'];
    $result['tool'] = $tool;
    $result['target'] = $tool === 'arp' ? 'Collector neighbour cache' : $row['hostname'];
    $result['profile'] = $row['name'];
    $result['collector_id'] = (int) $row['poller_id'];
    $result['execution_host'] = gethostname() ?: 'Unknown';
    // Stream buffers are used for parsing only; store one bounded combined result.
    unset($result['stdout'], $result['stderr']);
    return $result;
}

/** Read the final UDP receiver row, using datagram counts for accurate loss thresholds. */
function icct_backend_diag_udp_metrics($stdout)
{
    if (
        !preg_match(
            '/^\[\s*\d+\]\s+[\d.]+\s*-\s*[\d.]+\s+sec\s+[\d.]+\s+[KMGT]?Bytes\s+' .
                '(\d+(?:\.\d+)?)\s+([KMGT]?)bits\/sec\s+(\d+(?:\.\d+)?)\s+ms\s+' .
                '(\d+)\s*\/\s*(\d+)\s+\([\d.]+%\)\s+receiver[ \t]*\r?$/m',
            $stdout,
            $row,
        )
    ) {
        return null;
    }
    $lost = (int) $row[4];
    $total = (int) $row[5];
    if (!$total || $lost > $total) {
        return null;
    }
    $scale = ['' => 0.000001, 'K' => 0.001, 'M' => 1, 'G' => 1000, 'T' => 1000000];
    return [
        'throughput' => (float) $row[1] * $scale[$row[2]],
        'jitter' => (float) $row[3],
        'loss' => (100 * $lost) / $total,
        'lost' => $lost,
        'total' => $total,
    ];
}

function icct_backend_diag_run_command($command, $timeout = 40, ?callable $heartbeat = null)
{
    if (!is_array($command) || !$command || !is_string($command[0]) || $command[0] === '') {
        throw new InvalidArgumentException('Invalid diagnostic command.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException(
            'Process execution is unavailable in the Cacti poller PHP runtime.',
        );
    }
    $timeout = max(1, min(120, (float) $timeout));
    $pipes = [];
    $process = @proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the diagnostic executable on this collector.');
    }
    $stdout = $stderr = '';
    $truncated = $timed_out = false;
    $exit = -1;
    $limit = 262144; // Per stream; enough for bounded iPerf3 JSON, never unbounded memory.
    $started = hrtime(true);
    $lastHeartbeat = 0;
    try {
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while (true) {
            icct_backend_diag_drain($pipes[1], $stdout, $truncated, $limit);
            icct_backend_diag_drain($pipes[2], $stderr, $truncated, $limit);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                if ($exit < 0 && !empty($status['signaled'])) {
                    $exit = 128 + (int) $status['termsig'];
                }
                break;
            }
            // The listener remains alive while waiting for a bounded worker subprocess.
            // Exceptions stop and reap the child through the existing finally block.
            if ($heartbeat !== null && hrtime(true) - $lastHeartbeat >= 1000000000) {
                $heartbeat();
                $lastHeartbeat = hrtime(true);
            }
            if ((hrtime(true) - $started) / 1e9 >= $timeout) {
                $timed_out = true;
                proc_terminate($process);
                usleep(100000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(20000);
        }
        // Drain the finite pipe tail after exit/termination, still under a time bound.
        $drain_until = hrtime(true) + 200000000;
        do {
            icct_backend_diag_drain($pipes[1], $stdout, $truncated, $limit);
            icct_backend_diag_drain($pipes[2], $stderr, $truncated, $limit);
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $drain_until);
    } finally {
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $closed = proc_close($process);
        if ($exit < 0) {
            $exit = $closed;
        }
    }
    $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    if ($timed_out) {
        $output .= "\nTest exceeded its {$timeout}-second limit. Partial output is shown.";
    }
    if ($truncated) {
        $output .= "\nOutput truncated at the capture limit.";
    }
    return [
        'exit' => $timed_out ? 124 : $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'output' => trim($output) ?: 'The executable returned no output.',
        'timed_out' => $timed_out,
        'truncated' => $truncated,
    ];
}

function icct_backend_diag_signature($row)
{
    return hash(
        'sha256',
        json_encode(
            array_intersect_key(
                $row,
                array_flip([
                    'host_id',
                    'hostname',
                    'poller_id',
                    'id',
                    'name',
                    'tools',
                    'ping_count',
                    'mtr_cycles',
                    'mtr_background',
                    'mtr_interval',
                    'arp_interface',
                    'pathchar_hops',
                    'pathchar_timeout',
                    'trace_hops',
                    'bandwidth_seconds',
                ]),
            ),
            JSON_THROW_ON_ERROR,
        ),
    );
}

function icct_backend_diag_target($value)
{
    $target = trim((string) $value);
    if (filter_var($target, FILTER_VALIDATE_IP)) {
        return $target;
    }
    if (
        $target === '' ||
        strlen($target) > 253 ||
        !preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.?$/D', $target)
    ) {
        throw new InvalidArgumentException(
            'Enter a valid device hostname or IP address; command options are not targets.',
        );
    }
    foreach (explode('.', rtrim($target, '.')) as $label) {
        if (
            strlen($label) > 63 ||
            !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label)
        ) {
            throw new InvalidArgumentException(
                'The device hostname contains an invalid DNS label.',
            );
        }
    }
    return $target;
}

function icct_backend_diag_tools($value)
{
    $parts = is_array($value) ? $value : explode(',', (string) $value);
    $valid = [];

    foreach ($parts as $tool) {
        $tool = trim((string) $tool);
        if (isset(icct_backend_diag_labels()[$tool])) {
            $valid[$tool] = $tool;
        }
    }

    return array_values($valid);
}

/** Execute bounded Ping/Traceroute/ARP immediately on the device's assigned local collector. */
function icct_backend_diag_run_instant($host_id, $tool)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($host_id);
    if (!in_array($tool, ['ping', 'traceroute', 'arp'], true)) {
        throw new InvalidArgumentException('Unsupported immediate diagnostic.');
    }
    $row = icct_backend_diag_assignment($host_id, $tool);
    if (
        PHP_OS_FAMILY !== 'Linux' ||
        icct_backend_inventory_collector_id() !== (int) $row['poller_id']
    ) {
        throw new RuntimeException('Run this diagnostic on the device’s assigned Linux collector.');
    }
    if (
        !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller WHERE id=? AND disabled=''", [
            (int) $row['poller_id'],
        ])
    ) {
        throw new RuntimeException('The assigned collector is disabled.');
    }
    $rpc = json_decode(
        (string) db_fetch_cell_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
            ['diagnostic_rpc_' . (int) $row['poller_id']],
        ),
        true,
    );
    if (!is_array($rpc) || empty($rpc['token']) || empty($rpc['port'])) {
        throw new RuntimeException('The collector is not ready for immediate diagnostics.');
    }
    $request = [
        'host_id' => (int) $host_id,
        'tool' => $tool,
        'user_id' => icct_backend_current_user_id(),
        'time' => time(),
        'nonce' => bin2hex(random_bytes(16)),
    ];
    $body = json_encode($request, JSON_THROW_ON_ERROR);
    $packet = json_encode(
        [
            'body' => $body,
            'signature' => hash_hmac('sha256', $body, $rpc['token']),
        ],
        JSON_THROW_ON_ERROR,
    );
    $lock = 'icct_diag_instant_' . (int) $row['poller_id'];
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
        throw new RuntimeException('A diagnostic is already running. Try again when it finishes.');
    }
    $socket = null;
    try {
        $socket = @stream_socket_client(
            'tcp://127.0.0.1:' . (int) $rpc['port'],
            $errno,
            $message,
            2,
        );
        if (!$socket) {
            throw new RuntimeException('The collector is unavailable. Try again shortly.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        set_time_limit(85);
        stream_set_timeout($socket, 80);
        fwrite($socket, $packet . "\n");
        $response = stream_get_contents($socket, 262144);
        $reply = json_decode($response, true);
        if (!is_array($reply)) {
            throw new RuntimeException('The collector did not return a result.');
        }
        if (empty($reply['ok'])) {
            throw new RuntimeException($reply['error'] ?? 'The diagnostic could not run.');
        }
        return $reply['result'];
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
