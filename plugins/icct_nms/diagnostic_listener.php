<?php
/** Independent collector listener launched by this plugin's native Cacti poller hook. */
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require __DIR__ . "/../../include/cli_check.php";
require_once __DIR__ . "/includes/bootstrap.php";
try {
    icct_nms_backend();
    icct_backend_diag_worker_database();
    $collector = icct_backend_inventory_collector_id();
    $lock = "icct_backend_diag_listener_" . $collector;
    if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,0)", [$lock]) !== 1) {
        exit();
    }
    $connection = (int) db_fetch_cell("SELECT CONNECTION_ID()");
    $revision = hash_file("sha256", __FILE__);
    $key = "diagnostic_runner_" . $collector;
    $tools = [];
    $heartbeat = function () use ($lock, $connection, $key, &$tools) {
        if (
            (int) db_fetch_cell_prepared("SELECT IS_USED_LOCK(?)", [$lock]) !==
                $connection ||
            (int) db_fetch_cell_prepared(
                "SELECT status FROM plugin_config WHERE directory=?",
                ["icct_nms"],
            ) !== 1
        ) {
            throw new RuntimeException("ICCT collector stopped.");
        }
        icct_backend_category_execute(
            "INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()",
            [$key, json_encode(["tools" => $tools])],
        );
    };
    // A loopback-only authenticated request channel executes without a stored queue job.
    $rpcServer = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if (!$rpcServer) {
        throw new RuntimeException(
            "Could not open immediate diagnostic channel.",
        );
    }
    $rpcToken = bin2hex(random_bytes(32));
    $rpcPort = (int) substr(
        strrchr(stream_socket_get_name($rpcServer, false), ":"),
        1,
    );
    $rpcKey = "diagnostic_rpc_" . $collector;
    icct_backend_category_execute(
        "REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())",
        [$rpcKey, json_encode(["port" => $rpcPort, "token" => $rpcToken])],
    );
    $lastTools = 0;
    $lastSerial = 0;
    while (
        (int) db_fetch_cell_prepared(
            "SELECT status FROM plugin_config WHERE directory=?",
            ["icct_nms"],
        ) === 1
    ) {
        clearstatcache(true, __FILE__);
        if (hash_file("sha256", __FILE__) !== $revision) {
            break;
        }
        if (time() - $lastTools >= 60) {
            foreach (icct_backend_diag_labels() as $tool => $label) {
                [, $binary] = icct_backend_diag_executable($tool);
                $tools[$tool] = (bool) $binary;
            }
            $lastTools = time();
        }
        $heartbeat();
        if (
            db_fetch_cell_prepared(
                "SELECT id FROM plugin_icct_nms_diagnostic_jobs WHERE poller_id=? AND status IN ('queued','running') LIMIT 1",
                [$collector],
            )
        ) {
            icct_backend_diag_run_command(
                [PHP_BINARY, "-q", __DIR__ . "/diagnostic_worker.php"],
                75,
                $heartbeat,
            );
        }
        if (time() - $lastSerial >= 10) {
            icct_backend_config_monitor_once($collector);
            $lastSerial = time();
        }
        $client = @stream_socket_accept($rpcServer, 0);
        if ($client) {
            try {
                stream_set_timeout($client, 2);
                $packet = json_decode((string) fgets($client, 4096), true);
                if (
                    !is_array($packet) ||
                    !is_string($packet["body"] ?? null) ||
                    !hash_equals(
                        hash_hmac("sha256", $packet["body"], $rpcToken),
                        (string) ($packet["signature"] ?? ""),
                    )
                ) {
                    throw new RuntimeException("Invalid collector request.");
                }
                $request = json_decode($packet["body"], true);
                if (
                    !is_array($request) ||
                    abs(time() - (int) ($request["time"] ?? 0)) > 5 ||
                    !in_array(
                        $request["tool"] ?? "",
                        ["ping", "traceroute", "arp"],
                        true,
                    )
                ) {
                    throw new RuntimeException(
                        "Expired or unsupported diagnostic request.",
                    );
                }
                icct_backend_diag_authorize_job([
                    "user_id" => (int) ($request["user_id"] ?? 0),
                    "host_id" => (int) $request["host_id"],
                ]);
                $row = icct_backend_diag_assignment(
                    (int) $request["host_id"],
                    $request["tool"],
                );
                if ((int) $row["poller_id"] !== $collector) {
                    throw new RuntimeException(
                        "Device belongs to another collector.",
                    );
                }
                [$command, $timeout] = icct_backend_diag_command(
                    $row,
                    $request["tool"],
                );
                $result = icct_backend_diag_result(
                    $row,
                    $request["tool"],
                    $command,
                    icct_backend_diag_run_command(
                        $command,
                        $timeout,
                        $heartbeat,
                    ),
                );
                fwrite(
                    $client,
                    json_encode(
                        ["ok" => true, "result" => $result],
                        JSON_INVALID_UTF8_SUBSTITUTE,
                    ),
                );
            } catch (Throwable $exception) {
                fwrite(
                    $client,
                    json_encode(
                        ["ok" => false, "error" => $exception->getMessage()],
                        JSON_INVALID_UTF8_SUBSTITUTE,
                    ),
                );
            } finally {
                fclose($client);
            }
        }
        usleep(100000);
    }
    db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
} catch (Throwable $error) {
    cacti_log(
        "ICCT NMS collector listener: " . $error->getMessage(),
        false,
        "ICCT NMS",
    );
    exit(1);
}
