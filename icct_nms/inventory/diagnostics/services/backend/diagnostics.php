<?php
require_once __DIR__.'/../../../../protocols/ping/services/command.php';
/** ICCT-owned diagnostics services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: diag arguments. */
function icct_backend_diag_arguments($row, $tool, $name)
{
    $target = icct_backend_diag_target($row["hostname"]);
    icct_backend_diag_profile_validate([
        "diagnostic_profile_name" => $row["name"],
        "diagnostic_tools" => [$tool],
        "ping_count" => $row["ping_count"],
        "mtr_cycles" => $row["mtr_cycles"] ?? $row["ping_count"],
        "mtr_background" => 0,
        "mtr_interval" => $row["mtr_interval"] ?? 300,
        "arp_interface" => $row["arp_interface"] ?? '',
        "pathchar_hops" => $row["pathchar_hops"] ?? 20,
        "pathchar_timeout" => $row["pathchar_timeout"] ?? 60,
        "trace_hops" => $row["trace_hops"],
        "bandwidth_seconds" => $row["bandwidth_seconds"],
    ]);
    if (
        strpos($tool, "hping3_") === 0 &&
        filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
    ) {
        throw new InvalidArgumentException(
            "hping3 supports IPv4 targets only. Choose Nping or MTR for IPv6.",
        );
    }
    // Nping accepts octet ranges as targets; a device check must remain single-host.
    if (
        strpos($tool, "nping_") === 0 &&
        preg_match('/^[0-9.-]+$/D', $target) &&
        strpos($target, "-") !== false
    ) {
        throw new InvalidArgumentException(
            "Nping requires one device address, not an address range.",
        );
    }
    $ipv6 = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ? ["-6"]
        : [];

    $timeout = 40;
    switch ($tool) {
        case "arp":
            $args = ["neigh", "show"];
            if (!empty($row["arp_interface"])) array_push($args, "dev", $row["arp_interface"]);
            $timeout = 5;
            break;
        case "ping":
            [$args, $timeout] = icct_backend_ping_command($row, $target);
            break;
        case "traceroute":
            $args =
                $name === "tracepath"
                    ? ["-n", "-m", (string) $row["trace_hops"], $target]
                    : [
                        "-n",
                        "-q",
                        "1",
                        "-m",
                        (string) $row["trace_hops"],
                        "-w",
                        "2",
                        $target,
                    ];
            $timeout = 2 * $row["trace_hops"] + 5;
            break;
        case "traceroute_icmp":
        case "traceroute_tcp":
            $args = array_merge(
                $ipv6,
                ["-n", "-q", "1", "-m", (string) $row["trace_hops"], "-w", "2"],
                $tool === "traceroute_tcp" ? ["-T", "-p", "443"] : ["-I"],
                [$target],
            );
            $timeout = 2 * $row["trace_hops"] + 5;
            break;
        case "mtr_icmp":
        case "mtr_tcp":
            $args = array_merge(
                $ipv6,
                [
                    "--report",
                    "--no-dns",
                    "--report-cycles",
                    (string) ($row["mtr_cycles"] ?? $row["ping_count"]),
                    "--max-ttl",
                    (string) $row["trace_hops"],
                    "--interval",
                    "1",
                ],
                $tool === "mtr_tcp" ? ["--tcp", "--port", "443"] : [],
                [$target],
            );
            $timeout = 60;
            break;
        case "nping_icmp":
        case "nping_tcp":
            // Nping has no -n switch. --privileged permits capability-based raw sockets; it grants no privilege itself.
            $args = array_merge(
                $ipv6,
                [
                    "--privileged",
                    "-c",
                    (string) $row["ping_count"],
                    "--delay",
                    "1s",
                ],
                $tool === "nping_tcp"
                    ? ["--tcp", "--flags", "syn", "-p", "443"]
                    : ["--icmp"],
                [$target],
            );
            $timeout = $row["ping_count"] + 10;
            break;
        case "hping3_icmp":
        case "hping3_tcp":
            $args = array_merge(
                ["-n", "-c", (string) $row["ping_count"], "-i", "1"],
                $tool === "hping3_tcp" ? ["-S", "-p", "443"] : ["-1"],
                [$target],
            );
            $timeout = $row["ping_count"] + 15;
            break;
        case "iperf3":
            $args = [
                "-c",
                $target,
                "-p",
                "5201",
                "-t",
                (string) $row["bandwidth_seconds"],
                "-J",
            ];
            $timeout = $row["bandwidth_seconds"] + 10;
            break;
        case "netperf":
            $args = [
                "-H",
                $target,
                "-p",
                "12865",
                "-t",
                "TCP_STREAM",
                "-l",
                (string) $row["bandwidth_seconds"],
            ];
            $timeout = $row["bandwidth_seconds"] + 10;
            break;
        case "pathchar":
            $args =
                $name === "pchar"
                    ? [
                        "-n",
                        "-H",
                        (string) ($row["pathchar_hops"] ?? 20),
                        "-R",
                        "3",
                        "-I",
                        "128",
                        $target,
                    ]
                    : ["-n", $target];
            $timeout = (int) ($row["pathchar_timeout"] ?? 60);
            break;
        default:
            throw new InvalidArgumentException("Unsupported diagnostic tool.");
    }
    return [$args, $timeout];
}

/** Reused Inventory service: diag assignment. */
function icct_backend_diag_assignment($host_id, $tool)
{
    if (!isset(icct_backend_diag_labels()[$tool])) {
        throw new InvalidArgumentException("Unsupported diagnostic tool.");
    }
    $row = db_fetch_row_prepared(
        "SELECT h.id AS host_id, h.hostname, h.description, h.poller_id, h.disabled, p.*
		FROM host h JOIN plugin_icct_nms_diagnostic_devices d ON d.host_id=h.id
		JOIN plugin_icct_nms_diagnostic_profiles p ON p.id=d.profile_id
		WHERE h.id=? AND h.deleted=''",
        [(int) $host_id],
    );
    if (!$row || $row["disabled"] !== "") {
        throw new RuntimeException(
            "Select an enabled device with a diagnostic profile.",
        );
    }
    if (!in_array($tool, icct_backend_diag_tools($row["tools"]), true)) {
        throw new RuntimeException(
            "This tool is not enabled by the assigned profile.",
        );
    }
    $row["hostname"] = icct_backend_diag_target($row["hostname"]);
    // Revalidate saved bounds as well as form input.
    icct_backend_diag_profile_validate([
        "diagnostic_profile_name" => $row["name"],
        "diagnostic_tools" => $row["tools"],
        "ping_count" => $row["ping_count"],
        "mtr_cycles" => $row["mtr_cycles"] ?? $row["ping_count"],
        "mtr_background" => $row["mtr_background"] ?? 0,
        "mtr_interval" => $row["mtr_interval"] ?? 300,
        "arp_interface" => $row["arp_interface"] ?? '',
        "pathchar_hops" => $row["pathchar_hops"] ?? 20,
        "pathchar_timeout" => $row["pathchar_timeout"] ?? 60,
        "trace_hops" => $row["trace_hops"],
        "bandwidth_seconds" => $row["bandwidth_seconds"],
    ]);
    return $row;
}

/** Reused Inventory service: diag available labels. */
function icct_backend_diag_available_labels()
{
    return array_diff_key(
        icct_backend_diag_labels(),
        array_flip(["nping_icmp", "nping_tcp", "hping3_icmp", "hping3_tcp"]),
    );
}

/** One saved per-device selection for Inventory, Diagnostics and topology actions. */
function icct_backend_diag_selected_labels($host_id)
{
    icct_backend_require_device_access($host_id);
    $row=db_fetch_row_prepared("SELECT h.disabled,p.tools FROM host h JOIN plugin_icct_nms_diagnostic_devices d ON d.host_id=h.id JOIN plugin_icct_nms_diagnostic_profiles p ON p.id=d.profile_id WHERE h.id=? AND h.deleted=''",[(int)$host_id]);
    if(!$row||$row['disabled']!=='')return [];
    return array_intersect_key(icct_backend_diag_available_labels(),array_flip(icct_backend_diag_tools($row['tools'])));
}

/** Reused Inventory service: diag command. */
function icct_backend_diag_command($row, $tool)
{
    [$name, $binary] = icct_backend_diag_executable($tool);
    if (!$binary && $tool === "pathchar") {
        throw new RuntimeException(
            "Path capacity estimation requires pathchar or pchar on this collector. Install an approved build matching the collector OS and architecture; raw-socket permission is also required. Traceroute cannot replace this measurement.",
        );
    }
    if (!$binary) {
        throw new RuntimeException(
            $name .
                " was not found or is not executable by this collector. Install the matching tool for its OS and architecture.",
        );
    }
    [$args, $timeout] = icct_backend_diag_arguments($row, $tool, $name);
    return [array_merge([$binary], $args), $timeout];
}

/** Reused Inventory service: diag drain. */
function icct_backend_diag_drain($pipe, &$buffer, &$truncated, $limit)
{
    // Bound work per iteration too: a noisy child must not starve the timeout check.
    for ($i = 0; $i < 8; $i++) {
        $chunk = fread($pipe, 8192);
        if ($chunk === false || $chunk === "") {
            break;
        }
        $remaining = max(0, $limit - strlen($buffer));
        $buffer .= substr($chunk, 0, $remaining);
        $truncated = $truncated || strlen($chunk) > $remaining;
    }
}

/** Reused Inventory service: diag executable. */
function icct_backend_diag_executable($tool, $lookup = null)
{
    if (!isset(icct_backend_diag_labels()[$tool])) {
        throw new InvalidArgumentException("Unsupported diagnostic tool.");
    }
    $lookup = $lookup ?? "icct_backend_diag_program";
    $name = $tool === "arp" ? "ip" : explode("_", $tool)[0];
    $binary = $lookup($name);
    if (!$binary && $tool === "pathchar") {
        $name = "pchar";
        $binary = $lookup($name);
    }
    // tracepath supports only the legacy UDP check, never TCP or ICMP modes.
    if (!$binary && $tool === "traceroute") {
        $name = "tracepath";
        $binary = $lookup($name);
    }
    return [$name, $binary];
}

/** Reused Inventory service: diag execute. */
function icct_backend_diag_execute($row, $tool)
{
    if (PHP_SAPI !== "cli") {
        throw new RuntimeException(
            "Diagnostics must run through the Cacti poller queue.",
        );
    }
    if (PHP_OS_FAMILY !== "Linux") {
        throw new RuntimeException(
            "These diagnostic command options require a Linux collector.",
        );
    }

    if (icct_backend_inventory_collector_id() !== (int) $row["poller_id"]) {
        throw new RuntimeException("Diagnostic belongs to another collector.");
    }
    [$command, $timeout] = icct_backend_diag_command($row, $tool);

    if (
        $tool === "iperf3" &&
        icct_backend_diag_collector_address($row["hostname"])
    ) {
        [$command, $result] = icct_backend_diag_iperf_self_test(
            $command,
            $timeout,
        );
    } elseif (
        $tool === "netperf" &&
        icct_backend_diag_collector_address($row["hostname"])
    ) {
        [$command, $result] = icct_backend_diag_netperf_self_test(
            $command,
            $timeout,
        );
    } else {
        $result = icct_backend_diag_run_command($command, $timeout);
    }
    if ($tool === "pathchar") {
        $result["self_test"] = icct_backend_diag_collector_address(
            $row["hostname"],
        );
    }
    return icct_backend_diag_result($row, $tool, $command, $result);
}

/** Reused Inventory service: diag labels. */
function icct_backend_diag_labels()
{
    return [
        "ping" => "Ping ICMP",
        "traceroute" => "Traceroute UDP",
        "traceroute_icmp" => "Traceroute ICMP",
        "traceroute_tcp" => "Traceroute TCP",
        "mtr_icmp" => "MTR ICMP",
        "mtr_tcp" => "MTR TCP",
        "nping_icmp" => "Nping ICMP",
        "nping_tcp" => "Nping TCP",
        "hping3_icmp" => "hping3 ICMP",
        "hping3_tcp" => "hping3 TCP",
        "arp" => "ARP",
        "iperf3" => "iPerf3",
        "netperf" => "Netperf",
        "pathchar" => "Pathchar",
    ];
}

/** Reused Inventory service: diag profile validate. */
function icct_backend_diag_profile_validate($input)
{
    $name = trim((string) ($input["diagnostic_profile_name"] ?? ""));
    if ($name === "" || strlen($name) > 100) {
        throw new InvalidArgumentException(
            "Enter a diagnostic profile name of up to 100 characters.",
        );
    }

    $tools = icct_backend_diag_tools($input["diagnostic_tools"] ?? []);
    if (!$tools) {
        throw new InvalidArgumentException(
            "Select at least one diagnostic tool.",
        );
    }

    $ping = (int) ($input["ping_count"] ?? 4);
    $mtr = (int) ($input["mtr_cycles"] ?? $ping);
    $hops = (int) ($input["trace_hops"] ?? 20);
    $seconds = (int) ($input["bandwidth_seconds"] ?? 10);
    if (
        $ping < 1 ||
        $ping > 10 ||
        $mtr < 1 || $mtr > 30 ||
        $hops < 1 ||
        $hops > 30 ||
        $seconds < 1 ||
        $seconds > 30
    ) {
        throw new InvalidArgumentException(
            "Use 1–10 ping packets, 1–30 MTR readings, 1–30 hops, and a 1–30 second bandwidth test.",
        );
    }

    $background = empty($input["mtr_background"]) ? 0 : 1;
    $interval = (int) ($input["mtr_interval"] ?? 300);
    if ($interval < 60 || $interval > 3600) {
        throw new InvalidArgumentException("Use an MTR monitoring interval of 60–3600 seconds.");
    }
    if ($background && !array_intersect($tools, ["mtr_icmp", "mtr_tcp"])) {
        throw new InvalidArgumentException("Select an MTR method for automatic monitoring.");
    }
    $arpInterface = trim((string) ($input['arp_interface'] ?? ''));
    if ($arpInterface !== '' && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,14}$/D', $arpInterface)) {
        throw new InvalidArgumentException('Enter a collector interface name of up to 15 characters.');
    }
    $pathHops = (int) ($input['pathchar_hops'] ?? 20);
    $pathTimeout = (int) ($input['pathchar_timeout'] ?? 60);
    if ($pathHops < 1 || $pathHops > 30 || $pathTimeout < 10 || $pathTimeout > 120) {
        throw new InvalidArgumentException('Use 1–30 Pathchar hops and a 10–120 second time limit.');
    }
    return [
        "name" => $name,
        "tools" => implode(",", $tools),
        "ping_count" => $ping,
        "trace_hops" => $hops,
        "bandwidth_seconds" => $seconds,
        "mtr_cycles" => $mtr,
        "mtr_background" => $background,
        "mtr_interval" => $interval,
        "arp_interface" => $arpInterface,
        "pathchar_hops" => $pathHops,
        "pathchar_timeout" => $pathTimeout,
    ];
}

/** Reused Inventory service: diag program. */
function icct_backend_diag_program($name)
{
    foreach (
        [
            "/usr/bin/",
            "/usr/sbin/",
            "/usr/local/bin/",
            "/usr/local/sbin/",
            "/bin/",
            "/sbin/",
        ]
        as $directory
    ) {
        $candidate = $directory . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return "";
}

/** Reused Inventory service: diag result. */
function icct_backend_diag_result($row, $tool, $command, $result)
{
    if ($tool === "iperf3" && !empty($result["truncated"])) {
        $result["protocol_error"] = true;
        $result["output"] .=
            "\nThe iPerf3 response was truncated; no complete bandwidth measurement is available.";
    }
    if ($tool === "iperf3" && empty($result["truncated"])) {
        $data = json_decode($result["stdout"], true);
        if (is_array($data) && !empty($data["error"])) {
            $result["output"] =
                "iPerf3: " . (string) $data["error"] . "\n" . $result["output"];
            $result["protocol_error"] = true;
        } elseif (
            $result["exit"] === 0 &&
            is_array($data) &&
            isset($data["end"]["sum_received"]["bits_per_second"])
        ) {
            $result["output"] =
                "Receiver throughput: " .
                number_format(
                    (float) $data["end"]["sum_received"]["bits_per_second"] /
                        1000000,
                    2,
                ) .
                " Mbit/s\n\n" .
                $result["output"];
        } elseif ($result["exit"] === 0) {
            $result["protocol_error"] = true;
            $result["output"] .=
                "\nThe client returned no complete iPerf3 receiver measurement.";
        }
    }
    if (
        preg_match(
            "/permission denied|operation not permitted/i",
            $result["output"],
        )
    ) {
        $result["output"] .=
            "\nThe collector runtime denied this operation. ICCT NMS cannot override host permissions; no OS settings were changed.";
    } elseif (
        $result["exit"] !== 0 &&
        in_array($tool, ["iperf3", "netperf"], true)
    ) {
        $result["output"] .=
            $tool === "iperf3"
                ? "\nVerify an authorised iperf3 server at the target on TCP 5201 and the network path."
                : "\nVerify netserver at the target on TCP 12865 and permit its separate negotiated TCP data connection.";
    }
    if (
        $tool === "arp" &&
        $result["exit"] === 0 &&
        trim($result["stdout"]) === "" &&
        trim($result["stderr"]) === ""
    ) {
        $result["output"] =
            "No IPv4 or IPv6 neighbours are cached by this collector.";
    }
    if (
        in_array($tool, ["iperf3", "netperf"], true) &&
        !empty($result["self_test"])
    ) {
        $result["output"] =
            "Collector local-address self-test — this is not network-link bandwidth.\nTemporary local server stopped after the test.\n\n" .
            $result["output"];
    } elseif (
        $tool === "iperf3" &&
        stripos($result["output"], "Bad file descriptor") !== false
    ) {
        $result["output"] =
            "No bandwidth measurement completed. iPerf3 could not establish its control connection. Check that the target runs an iPerf3 server on TCP 5201.\n\n" .
            $result["output"];
    }
    $result["output"] =
        '$ ' .
        implode(" ", array_map("escapeshellarg", $command)) .
        "\n" .
        $result["output"];
    $result["tool"] = $tool;
    $result["target"] =
        $tool === "arp" ? "Collector neighbour cache" : $row["hostname"];
    $result["profile"] = $row["name"];
    $result["collector_id"] = (int) $row["poller_id"];
    $result["execution_host"] = gethostname() ?: "Unknown";
    // Stream buffers are used for parsing only; store one bounded combined result.
    unset($result["stdout"], $result["stderr"]);
    return $result;
}

/** Reused Inventory service: diag run command. */
function icct_backend_diag_run_command(
    $command,
    $timeout = 40,
    ?callable $heartbeat = null,
) {
    if (
        !is_array($command) ||
        !$command ||
        !is_string($command[0]) ||
        $command[0] === ""
    ) {
        throw new InvalidArgumentException("Invalid diagnostic command.");
    }
    if (!function_exists("proc_open")) {
        throw new RuntimeException(
            "Process execution is unavailable in the Cacti poller PHP runtime.",
        );
    }
    $timeout = max(1, min(75, (float) $timeout));
    $pipes = [];
    $process = @proc_open(
        $command,
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
        null,
        null,
        ["bypass_shell" => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            "Could not start the diagnostic executable on this collector.",
        );
    }
    $stdout = $stderr = "";
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
            if (!$status["running"]) {
                $exit = (int) $status["exitcode"];
                if ($exit < 0 && !empty($status["signaled"])) {
                    $exit = 128 + (int) $status["termsig"];
                }
                break;
            }
            // The listener remains alive while waiting for a bounded worker subprocess.
            // Exceptions stop and reap the child through the existing finally block.
            if (
                $heartbeat !== null &&
                hrtime(true) - $lastHeartbeat >= 1000000000
            ) {
                $heartbeat();
                $lastHeartbeat = hrtime(true);
            }
            if ((hrtime(true) - $started) / 1e9 >= $timeout) {
                $timed_out = true;
                proc_terminate($process);
                usleep(100000);
                $status = proc_get_status($process);
                if ($status["running"]) {
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
        if ($status["running"]) {
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
    $output = trim($stdout . ($stderr !== "" ? "\n" . $stderr : ""));
    if ($timed_out) {
        $output .= "\nTest exceeded its {$timeout}-second limit. Partial output is shown.";
    }
    if ($truncated) {
        $output .= "\nOutput truncated at the capture limit.";
    }
    return [
        "exit" => $timed_out ? 124 : $exit,
        "stdout" => $stdout,
        "stderr" => $stderr,
        "output" => trim($output) ?: "The executable returned no output.",
        "timed_out" => $timed_out,
        "truncated" => $truncated,
    ];
}

/** Reused Inventory service: diag signature. */
function icct_backend_diag_signature($row)
{
    return hash(
        "sha256",
        json_encode(
            array_intersect_key(
                $row,
                array_flip([
                    "host_id",
                    "hostname",
                    "poller_id",
                    "id",
                    "name",
                    "tools",
                    "ping_count",
                    "mtr_cycles",
                    "mtr_background",
                    "mtr_interval",
                    "arp_interface",
                    "pathchar_hops",
                    "pathchar_timeout",
                    "trace_hops",
                    "bandwidth_seconds",
                ]),
            ),
            JSON_THROW_ON_ERROR,
        ),
    );
}

/** Reused Inventory service: diag target. */
function icct_backend_diag_target($value)
{
    $target = trim((string) $value);
    if (filter_var($target, FILTER_VALIDATE_IP)) {
        return $target;
    }
    if (
        $target === "" ||
        strlen($target) > 253 ||
        !preg_match(
            '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.?$/D',
            $target,
        )
    ) {
        throw new InvalidArgumentException(
            "Enter a valid device hostname or IP address; command options are not targets.",
        );
    }
    foreach (explode(".", rtrim($target, ".")) as $label) {
        if (
            strlen($label) > 63 ||
            !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label)
        ) {
            throw new InvalidArgumentException(
                "The device hostname contains an invalid DNS label.",
            );
        }
    }
    return $target;
}

/** Reused Inventory service: diag tools. */
function icct_backend_diag_tools($value)
{
    $parts = is_array($value) ? $value : explode(",", (string) $value);
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
    if (!in_array($tool, ["ping", "traceroute", "arp"], true)) {
        throw new InvalidArgumentException("Unsupported immediate diagnostic.");
    }
    $row = icct_backend_diag_assignment($host_id, $tool);
    if (
        PHP_OS_FAMILY !== "Linux" ||
        icct_backend_inventory_collector_id() !== (int) $row["poller_id"]
    ) {
        throw new RuntimeException(
            "Run this diagnostic on the device’s assigned Linux collector.",
        );
    }
    if (
        !(int) db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM poller WHERE id=? AND disabled=''",
            [(int) $row["poller_id"]],
        )
    ) {
        throw new RuntimeException("The assigned collector is disabled.");
    }
    $rpc = json_decode(
        (string) db_fetch_cell_prepared(
            "SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?",
            ["diagnostic_rpc_" . (int) $row["poller_id"]],
        ),
        true,
    );
    if (!is_array($rpc) || empty($rpc["token"]) || empty($rpc["port"])) {
        throw new RuntimeException(
            "The collector is not ready for immediate diagnostics.",
        );
    }
    $request = [
        "host_id" => (int) $host_id,
        "tool" => $tool,
        "user_id" => icct_backend_current_user_id(),
        "time" => time(),
        "nonce" => bin2hex(random_bytes(16)),
    ];
    $body = json_encode($request, JSON_THROW_ON_ERROR);
    $packet = json_encode(
        [
            "body" => $body,
            "signature" => hash_hmac("sha256", $body, $rpc["token"]),
        ],
        JSON_THROW_ON_ERROR,
    );
    $lock = "icct_diag_instant_" . (int) $row["poller_id"];
    if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?, 0)", [$lock]) !== 1) {
        throw new RuntimeException(
            "A diagnostic is already running. Try again when it finishes.",
        );
    }
    $socket = null;
    try {
        $socket = @stream_socket_client(
            "tcp://127.0.0.1:" . (int) $rpc["port"],
            $errno,
            $message,
            2,
        );
        if (!$socket) {
            throw new RuntimeException(
                "The collector is unavailable. Try again shortly.",
            );
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
            throw new RuntimeException(
                "The collector did not return a result.",
            );
        }
        if (empty($reply["ok"])) {
            throw new RuntimeException(
                $reply["error"] ?? "The diagnostic could not run.",
            );
        }
        return $reply["result"];
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
        db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
    }
}
