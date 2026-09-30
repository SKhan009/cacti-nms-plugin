<?php
/**
 * Separate native POST forms for discovery, SNMP, SSH, serial assignments and diagnostics.
 */
?>
<div class="titlebar">
    <h1><?= icct_nms_h($host["description"]) ?></h1>
    <a class="button" href="inventory.php">Done</a>
</div>
<nav class="steps">
    <ol>
        <li>
            <a href="device.php?id=<?= $id ?>">
                Basic Information
                <small>1/7</small>
            </a>
        </li>
        <li class="current" data-protocol-step="1">
            <a href="#protocol">
                Protocol Config
                <small>2/7</small>
            </a>
        </li>
        <li data-protocol-step="2">
            <a href="#diagnostics">
                Device Diagnostics
                <small>3/7</small>
            </a>
        </li>
        <li data-protocol-step="3"><a href="protocol.php?id=<?= $id ?>#graphs">Graphs<small>4/7</small></a></li>
        <li data-protocol-step="4"><a href="#data-query">Data Query<small>5/7</small></a></li>
        <?php foreach (
            ["Port Config", "FCAPS"]
            as $i => $label
        ): ?>
        <li aria-disabled="true">
            <span>
                <?= $label ?>
                <small><?= $i + 6 ?>/7</small>
            </span>
        </li>
        <?php endforeach; ?>
    </ol>
</nav>
<?php
$protocolStates = [];
foreach (["cdp", "lldp", "snmp", "ssh", "serial"] as $key) {
    $protocolStates[$key] = icct_backend_protocol_enabled($id, $key);
}
?>
<script type="application/json" id="protocol-states"><?= json_encode(
    $protocolStates,
    JSON_HEX_TAG | JSON_HEX_AMP,
) ?></script>
<form method="post" id="toggle-protocol-form" hidden>
    <?php icct_nms_token(); ?>
    <input type="hidden" name="action" value="toggle_protocol" />
    <input type="hidden" name="protocol" value="" />
    <input type="hidden" name="enabled" value="" />
</form>
<div class="device-form protocol-form">
    <div id="protocol-workspace">
        <button class="add-protocol" id="add-protocol" type="button" aria-expanded="false">
            + Add Protocol
        </button>
        <form method="post" id="remove-protocol-form" hidden>
            <?php icct_nms_token(); ?>
            <input type="hidden" name="action" value="remove_protocol" />
            <input type="hidden" name="protocol" value="" />
            <input type="hidden" name="assignment_revision" value="<?= (int) ($serial[
                "revision"
            ] ?? 0) ?>" />
        </form>
        <div id="protocol-picker" class="protocol-picker" hidden>
            <div id="protocol-options">
                <?php foreach (
                    [
                        "cdp" => "CDP (Cisco Discovery Protocol)",
                        "lldp" => "LLDP (Link Layer Discovery Protocol)",
                        "snmp" => "SNMP",
                        "ssh" => "SSH",
                        "serial" => "Serial Communication",
                        "syslog" => "Syslog",
                        "netflow" => "Netflow",
                        "ntp" => "NTP",
                        "tacacs" => "TACACS+",
                    ]
                    as $key => $label
                ): ?>
                <label class="check-row">
                    <input type="checkbox" data-protocol-target="<?= $key ?>" />
                    <span><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <button class="button" type="button" id="confirm-protocols">Add</button>
        </div>
        <?php
        $savedMethods = $discovery
            ? icct_backend_nd_host_methods($discovery, false)
            : [];
        foreach (
            [
                "cdp" => "CDP (Cisco Discovery Protocol)",
                "lldp" => "LLDP (Link Layer Discovery Protocol)",
            ]
            as $protocolKey => $protocolLabel
        ):
            $isSaved = in_array($protocolKey, $savedMethods, true); ?>
        <details class="protocol-item" id="protocol-<?= $protocolKey ?>" data-saved="<?= $isSaved
    ? "1"
    : "0" ?>" <?= $isSaved ? "" : "hidden" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span><?= $protocolLabel ?></span>
                <button type="button" class="delete-protocol" aria-label="Remove <?= strtoupper(
                    $protocolKey,
                ) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg></button>
            </summary>
            <div class="protocol-content">
                <form method="post">
                    <?php icct_nms_token(); ?>
                    <input type="hidden" name="action" value="discovery" />
                    <input type="hidden" name="discovery_protocol" value="<?= $protocolKey ?>" />
                    <div class="protocol-grid cols-4">
                        <?php
                        icct_nms_select(
                            "Collection Mode",
                            "mode",
                            ["snmp" => "SNMP"],
                            "snmp",
                            "disabled",
                        );
                        foreach (
                            [
                                "interval_seconds" =>
                                    "Collection Interval (Sec)",
                                "stale_seconds" => "Stale (Seconds)",
                                "refresh_seconds" =>
                                    "Display Refresh (Seconds)",
                            ]
                            as $key => $label
                        ) {
                            icct_nms_input(
                                $label,
                                $key,
                                $discovery[$key] ?? "",
                                "number",
                                'required min="1"',
                            );
                        }
                        ?>
                    </div>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form>
            </div>
        </details>
        <?php
        endforeach;
        ?>
        <details class="protocol-item" id="protocol-snmp" data-saved="<?= (int) $host[
            "snmp_version"
        ] > 0
            ? "1"
            : "0" ?>" <?= (int) $host["snmp_version"] > 0
    ? "open"
    : "hidden" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span>SNMP (Simple Network Management Protocol)</span>
                <button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg>
                </button>
            </summary>
            <div class="protocol-content">
                <form method="post">
                    <?php
                    icct_nms_token();
                    $values = $host;
                    $readonly = false;
                    ?>
                    <input type="hidden" name="action" value="snmp" />
                    <?php require __DIR__ . "/snmp.php"; ?>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form>
            </div>
        </details>
        <details class="protocol-item" id="protocol-ssh" data-saved="<?= $ssh
            ? "1"
            : "0" ?>" <?= $ssh ? "" : "hidden" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span>SSH</span>
                <button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg>
                </button>
            </summary>
            <div class="protocol-content">
                <form method="post" enctype="multipart/form-data">
                    <?php icct_nms_token(); ?>
                    <input type="hidden" name="action" value="ssh" />
                    <div class="protocol-grid cols-5">
                        <?php
                        icct_nms_input(
                            "Port Number",
                            "port",
                            $ssh["port"] ?? "",
                            "number",
                            'required min="1" max="65535"',
                        );
                        icct_nms_input(
                            "Timeout (Sec)",
                            "connect_timeout",
                            $ssh["connect_timeout"] ?? "",
                            "number",
                            'required min="1" max="120"',
                        );
                        icct_nms_select(
                            "Authentication Method",
                            "auth_method",
                            [
                                "password" => "Username & Password",
                                "key" => "Private Key",
                            ],
                            $ssh["auth_method"] ?? "password",
                        );
                        icct_nms_input(
                            "Username",
                            "username",
                            $ssh["username"] ?? "",
                            "text",
                            "required",
                        );
                        icct_nms_input(
                            "Password",
                            "secret",
                            "",
                            "password",
                            'autocomplete="new-password" placeholder="Blank retains saved credential"',
                        );
                        ?>
                    </div>
                    <div class="protocol-grid cols-3 ssh-common-settings">
                        <?php
                        icct_nms_input(
                            "Command Timeout (Sec)",
                            "command_timeout",
                            $ssh["command_timeout"] ?? "",
                            "number",
                            'required min="1" max="300"',
                        );
                        icct_nms_input(
                            "Retries",
                            "retries",
                            $ssh["retries"] ?? "",
                            "number",
                            'required min="0" max="2"',
                        );
                        icct_nms_input(
                            "Keepalive (Sec)",
                            "keepalive",
                            $ssh["keepalive"] ?? "",
                            "number",
                            'required min="0" max="300"',
                        );
                        ?>
                    </div>
                    <div class="ssh-private-key">
                        <h3>Upload files</h3>
                        <p>
                            Maximum 64 KB. Supported
                            formats: .ppk, PEM, OpenSSH.
                        </p>
                        <label class="upload-zone">
                            Upload +
                            <input
                                name="key_file"
                                type="file"
                                accept=".ppk,.pem,.key"
                                aria-label="Upload private key"
                            />
                        </label>
                        <span class="selected-file" aria-live="polite"></span>
                        <?php icct_nms_input(
                            "Key Passphrase",
                            "passphrase",
                            "",
                            "password",
                            'autocomplete="new-password"',
                        ); ?>
                    </div>
                    <label class="check-row">
                        <input name="monitoring" type="checkbox" <?= !empty(
                            $ssh["monitoring"]
                        )
                            ? "checked"
                            : "" ?> />
                        Enable SSH monitoring
                    </label>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form>
            </div>
        </details>
        <details class="protocol-item" id="protocol-serial" data-saved="<?= $serial
            ? "1"
            : "0" ?>" <?= $serial ? "" : "hidden" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span>Serial Communication</span>
                <button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg>
                </button>
            </summary>
            <div class="protocol-content">
                <form method="post">
                    <?php icct_nms_token(); ?>
                    <input type="hidden" name="action" value="serial" />
                    <input type="hidden" name="assignment_revision" value="<?= (int) ($serial[
                        "revision"
                    ] ?? 0) ?>" />
                    <?php require __DIR__ . "/serial.php"; ?>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form>
            </div>
        </details>
        <?php foreach (
            [
                "syslog" => "Syslog",
                "netflow" => "Netflow",
                "ntp" => "NTP",
                "tacacs" => "TACACS+",
            ]
            as $key => $label
        ): ?>
        <details class="protocol-item" id="protocol-<?= $key ?>" data-saved="0" hidden>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span><?= $label ?></span>
                <button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg>
                </button>
            </summary>
            <div class="protocol-content">
                <p class="empty-state">Configuration unavailable.</p>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <section id="diagnostics" class="diagnostics-page">
        <h2>Device Diagnostics</h2>
        <form method="post">
            <?php icct_nms_token(); ?>
            <input type="hidden" name="action" value="diagnostics" />
            <span class="field-label">Select Diagnostics Methods <span class="field-info" tabindex="0" role="img" aria-label="Information about diagnostic methods" data-tooltip="Choose the diagnostics available for this device. Configure packet count, hop limit and test duration alongside the selected methods."><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span></span>
            <div class="diagnostics-list diagnostics-settings">
              <div class="diagnostic-options">
                <?php
                $selected = icct_backend_diag_tools($diag["tools"] ?? "");
                $labels = icct_backend_diag_available_labels();
                // One shared native parameter per group; keep the existing saved profile fields.
                $groups = [
                    [
                        "Ping (Packets)",
                        ["ping"],
                        "ping_count",
                        "Ping (Packets)",
                        1,
                        10,
                    ],
                    [
                        "Traceroute (Hops)",
                        ["traceroute", "traceroute_icmp", "traceroute_tcp"],
                        "trace_hops",
                        "Traceroute (Hops)",
                        1,
                        30,
                    ],
                    ["MTR", ["mtr_icmp", "mtr_tcp"], "", "", 0, 0],
                    ["ARP", ["arp"], "", "", 0, 0],
                    [
                        "iPerf (Sec)",
                        ["iperf3"],
                        "bandwidth_seconds",
                        "Bandwidth Test (Sec)",
                        1,
                        30,
                    ],
                    ["Pathchar (Sec)", ["pathchar"], "", "", 0, 0],
                    ["Netperf (Sec)", ["netperf"], "", "", 0, 0],
                ];
                foreach (
                    $groups
                    as [$group, $methods, $parameter, $caption, $min, $max]
                ): ?>
                <div class="diagnostic-row">
                    <div class="diagnostic-methods">
                        <?php if ($group === "Traceroute (Hops)"): ?>
                        <label class="check-row" data-tooltip="Select or clear all Traceroute methods. UDP, ICMP and TCP discover the route using different probe types.">
                            <input type="checkbox" data-traceroute-group aria-label="Traceroute (Hops)" />
                            Traceroute (Hops)
                        </label>
                        <?php elseif ($group === "MTR"): ?>
                        <label class="check-row" data-tooltip="Select or clear both MTR modes for repeated path monitoring using ICMP or TCP probes.">
                            <input type="checkbox" data-mtr-group aria-label="MTR" />
                            MTR
                        </label>
                        <?php endif; ?>
                        <?php foreach ($methods as $key):
                            if (!isset($labels[$key])) {
                                continue;
                            } ?>
                        <label data-tooltip="<?= icct_nms_h(
                            [
                                "ping" =>
                                    "Send ICMP probes to test reachability. The packet count controls how many probes are sent.",
                                "traceroute" =>
                                    "One-time path discovery using UDP probes.",
                                "traceroute_icmp" =>
                                    "One-time path discovery using ICMP probes.",
                                "traceroute_tcp" =>
                                    "One-time path discovery using TCP probes.",
                                "mtr_icmp" =>
                                    "Continuous path monitoring using ICMP probes during the diagnostic run.",
                                "mtr_tcp" =>
                                    "Continuous path monitoring using TCP probes during the diagnostic run.",
                                "arp" =>
                                    "Show the assigned collector’s cached IP-to-MAC neighbours.",
                                "iperf3" =>
                                    "Measure bandwidth against an iPerf3 server. Duration uses the Bandwidth Test setting.",
                                "pathchar" =>
                                    "Estimate route characteristics using the available Pathchar tool and configured hop limit.",
                                "netperf" =>
                                    "Measure network performance against netserver. Duration uses the Bandwidth Test setting.",
                            ][$key],
                        ) ?>" class="check-row <?= count($methods) > 1
    ? "diagnostic-child"
    : "" ?>">
                            <input type="checkbox" name="diagnostic_tools[]" value="<?= $key ?>" <?= in_array(
    $key,
    $selected,
    true,
)
    ? "checked"
    : "" ?> />
                            <?= icct_nms_h(
                                [
                                    "ping" => "Ping (Packets)",
                                    "traceroute" => "UDP",
                                    "traceroute_icmp" => "ICMP",
                                    "traceroute_tcp" => "TCP",
                                    "mtr_icmp" => "MTR ICMP",
                                    "mtr_tcp" => "MTR TCP",
                                    "arp" => "ARP",
                                    "iperf3" => "iPerf (Sec)",
                                    "pathchar" => "Pathchar (Sec)",
                                    "netperf" => "Netperf (Sec)",
                                ][$key],
                            ) ?>
                        </label>
                        <?php
                        endforeach; ?>
                    </div>

                </div>
                <?php endforeach;
                ?>
              </div>
              <div class="diagnostic-parameters">
                <?php foreach (
                    $groups
                    as [$group, $methods, $parameter, $caption, $min, $max]
                ): ?>
                    <?php if ($parameter): ?>
                    <div class="diagnostic-parameter" data-diagnostic-parameter="<?= $parameter ?>">
                        <?php icct_nms_input(
                            $caption,
                            $parameter,
                            $diag[$parameter] ??
                                [
                                    "ping_count" => 4,
                                    "trace_hops" => 20,
                                    "bandwidth_seconds" => 10,
                                ][$parameter],
                            "number",
                            'min="' . $min . '" max="' . $max . '"',
                        ); ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="protocol-actions"><button class="button primary">Save</button></div>
        </form>
    </section>
    <?php require __DIR__ . "/graphs.php"; ?>
    <?php require __DIR__ . "/data_queries.php"; ?>
    <footer class="form-footer">
        <a class="button" id="protocol-previous" href="device.php?id=<?= $id ?>">Previous ←</a>
        <a class="button" id="protocol-next" href="#diagnostics">Next →</a>
    </footer>
</div>
<script type="application/json" id="serial-data">
    <?= json_encode(
        $connections,
        JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_THROW_ON_ERROR,
    ) ?>
</script>
