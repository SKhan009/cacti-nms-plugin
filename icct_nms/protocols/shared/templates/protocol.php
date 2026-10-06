<?php
/**
 * Separate native POST forms for discovery, SNMP, SSH, serial assignments and diagnostics.
 */
$presetMode = $presetMode ?? false;
$protocolDraft = $protocolDraft ?? [];
$failedProtocol = $failedProtocol ?? '';
$protocolPresetHelp = static function (string $key, string $label) use ($presetMode): void {
    if (!$presetMode) return;
    $help = [
        'cdp' => 'Preset collection, stale and refresh intervals. On the device, enable CDP and configure SNMP access; saved device settings stay independent.',
        'lldp' => 'Preset collection, stale and refresh intervals. On the device, enable LLDP and configure SNMP access; saved device settings stay independent.',
        'snmp' => 'Preset version, port, timeouts, retries and security options. When adding a device, enter its community or SNMPv3 credentials; changes apply only to that device.',
        'ssh' => 'Preset port, authentication method, username and connection settings. When adding a device, confirm its username and enter its password or private key; changes apply only to that device.',
        'serial' => 'Preset interface, line settings, polling and serial protocol. When adding a device, select its serial port and bus address; changes apply only to that device.',
        'syslog' => 'Receive device messages over UDP or TCP. Match the sender address and choose the least urgent severity to store; more urgent messages are also included.',
        'netflow' => 'Planned presets: version, collector, timeouts and sampling. Device setup will configure its flow exporter to send to the collector. Configuration is unavailable until integration is ready.',
        'ntp' => 'Planned presets: server, version, timeout, polling and offset limit. Device setup will confirm its NTP server. Configuration is unavailable until integration is ready.',
        'tacacs' => 'Planned presets: server, port, timeout, retries and authentication method. Device setup will require its shared secret and credentials. Configuration is unavailable until integration is ready.',
    ];
    ?>
    <span class="field-info" tabindex="0" role="img" aria-label="Configuration help for <?= icct_nms_h($label) ?>" data-tooltip="<?= icct_nms_h($help[$key]) ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span>
    <?php
};
?>
<?php if (empty($wizard) && !$presetMode): ?>
<div class="titlebar">
    <h1><?= icct_nms_h($host["description"]) ?></h1>
    <a class="button" href="inventory/controllers/inventory.php">Done</a>
</div>
<nav class="steps">
    <ol>
        <li>
            <a href="inventory/controllers/device.php?id=<?= $id ?>">
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
        <li data-protocol-step="3"><a href="protocols/shared/controllers/protocol.php?id=<?= $id ?>#graphs">Graphs<small>4/7</small></a></li>
        <li data-protocol-step="4"><a href="#data-query">Data Query<small>5/7</small></a></li>
        <li><a href="inventory/controllers/device.php?id=<?= $id ?>#ports">Port Config<small>6/7</small></a></li>
        <li><a href="inventory/controllers/device.php?id=<?= $id ?>#fcaps">FCAPS<small>7/7</small></a></li>
    </ol>
</nav>
<?php endif; ?>
<?php
$protocolStates = [];
foreach (["cdp", "lldp", "snmp", "ssh", "serial", "syslog"] as $key) {
    $protocolStates[$key] = icct_backend_protocol_enabled($id, $key);
}
?>
<?php if (!$presetMode): ?><script type="application/json" id="protocol-states"><?= json_encode(
    $protocolStates,
    JSON_HEX_TAG | JSON_HEX_AMP,
) ?></script><?php endif; ?>
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
    : "0" ?>" <?= $isSaved || in_array($protocolKey, $protocolDraft, true) ? "" : "hidden" ?> <?= $failedProtocol === $protocolKey ? "open" : "" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span><?= $protocolLabel ?> <?php $protocolPresetHelp($protocolKey, $protocolLabel); ?></span>
                <button type="button" class="delete-protocol" aria-label="Remove <?= strtoupper(
                    $protocolKey,
                ) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg></button>
            </summary>
            <div class="protocol-content">
                <form method="post">
                    <?php icct_nms_token(); ?>
                    <input type="hidden" name="action" value="<?= $presetMode ? 'save_protocol_defaults' : 'discovery' ?>" /><?php if ($presetMode): ?><input type="hidden" name="preset_protocol" value="<?= $protocolKey ?>" /><?php endif; ?>
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
                                ($presetMode ? ($protocolPresets[$protocolKey][$key] ?? null) : ($discovery[$key] ?? null)) ?? "",
                                "number",
                                'required min="'.(['interval_seconds'=>300,'stale_seconds'=>600,'refresh_seconds'=>10][$key]).'" max="'.(['interval_seconds'=>86400,'stale_seconds'=>604800,'refresh_seconds'=>300][$key]).'"',
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
    || in_array('snmp', $protocolDraft, true) ? "open" : "hidden" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span>SNMP (Simple Network Management Protocol) <?php $protocolPresetHelp('snmp', 'SNMP'); ?></span>
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
                    <input type="hidden" name="action" value="<?= $presetMode ? 'save_protocol_defaults' : 'snmp' ?>" /><?php if ($presetMode): ?><input type="hidden" name="preset_protocol" value="<?= 'snmp' ?>" /><?php endif; ?>
                    <?php require __DIR__ . "/../../snmp/templates/snmp.php"; ?>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form>
                <?php if(!$presetMode): ?><form method="post" id="snmp-discovery-policy">
                    <?php icct_nms_token(); ?><input type="hidden" name="action" value="discovery"><input type="hidden" name="discovery_policy" value="1">
                    <h3>Network Discovery</h3><div class="protocol-grid cols-3">
                    <?php foreach(['interval_seconds'=>'Collection Interval (Sec)','stale_seconds'=>'Stale (Seconds)','refresh_seconds'=>'Display Refresh (Seconds)'] as $key=>$label) icct_nms_input($label,$key,$discovery[$key]??(['interval_seconds'=>300,'stale_seconds'=>900,'refresh_seconds'=>30][$key]),'number','required min="'.(['interval_seconds'=>300,'stale_seconds'=>600,'refresh_seconds'=>10][$key]).'" max="'.(['interval_seconds'=>86400,'stale_seconds'=>604800,'refresh_seconds'=>300][$key]).'"'); ?>
                    </div><?php require __DIR__ . '/discovery_observations.php'; ?>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form><?php endif; ?>

            </div>
        </details>
        <?php require __DIR__.'/../../ssh/templates/settings.php'; ?>
        <details class="protocol-item" id="protocol-serial" data-saved="<?= $serial
            ? "1"
            : "0" ?>" <?= $serial || in_array("serial", $protocolDraft, true) ? "" : "hidden" ?> <?= $failedProtocol === "serial" ? "open" : "" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span>Serial Communication <?php $protocolPresetHelp('serial', 'Serial Communication'); ?></span>
                <button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg>
                </button>
            </summary>
            <div class="protocol-content">
                <form method="post">
                    <?php icct_nms_token(); ?>
                    <input type="hidden" name="action" value="<?= $presetMode ? 'save_protocol_defaults' : 'serial' ?>" /><?php if ($presetMode): ?><input type="hidden" name="preset_protocol" value="<?= 'serial' ?>" /><?php endif; ?>
                    <input type="hidden" name="assignment_revision" value="<?= (int) ($serial[
                        "revision"
                    ] ?? 0) ?>" />
                    <?php require __DIR__ . "/../../serial/templates/serial.php"; ?>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form>
            </div>
        </details>
        <?php
        $syslog = $presetMode ? ($protocolPresets['syslog'] ?? []) : icct_backend_syslog_assignment($id);
        $syslogSaved = !empty($syslog);
        if (!$presetMode) {
            $syslogDefaults = icct_nms_protocol_presets()['syslog'] ?? [];
            foreach (['severity_codes', 'facility_codes', 'match_strings'] as $syslogField) {
                if (!isset($syslog[$syslogField]) && isset($syslogDefaults[$syslogField])) {
                    $syslog[$syslogField] = $syslogDefaults[$syslogField];
                }
            }
        }
        ?>
        <details class="protocol-item" id="protocol-syslog" data-saved="<?= $syslogSaved ? '1' : '0' ?>" <?= $syslogSaved || in_array('syslog', $protocolDraft, true) ? '' : 'hidden' ?>>
            <summary><span class="accordion-chevron" aria-hidden="true"></span><span>Syslog <?php $protocolPresetHelp('syslog', 'Syslog'); ?></span><button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7"/></svg></button></summary>
            <div class="protocol-content"><form method="post">
                <?php icct_nms_token(); ?>
                <input type="hidden" name="action" value="<?= $presetMode ? 'save_protocol_defaults' : 'syslog' ?>" />
                <?php if ($presetMode): ?><input type="hidden" name="preset_protocol" value="syslog" /><?php endif; ?>
                <?php require __DIR__ . '/../../syslog/templates/syslog.php'; ?>
            </form></div>
        </details>
        <?php foreach (
            [
                "netflow" => "Netflow",
                "ntp" => "NTP",
                "tacacs" => "TACACS+",
            ]
            as $key => $label
        ): ?>
        <details class="protocol-item" id="protocol-<?= $key ?>" data-saved="0" <?= in_array($key, $protocolDraft, true) ? "" : "hidden" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span><?= $label ?> <?php $protocolPresetHelp($key, $label); ?></span>
                <button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg>
                </button>
            </summary>
            <div class="protocol-content">
                <?php require __DIR__ . "/protocol_pending.php"; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <?php if (!$presetMode): ?>
    <section id="diagnostics" class="diagnostics-page">
        <h2>Device Diagnostics</h2>
        <form method="post">
            <?php icct_nms_token(); ?>
            <input type="hidden" name="action" value="diagnostics" />
            <span class="field-label">Select Diagnostics Methods <span class="field-info" tabindex="0" role="img" aria-label="Information about diagnostic methods" data-tooltip="Choose the diagnostics available for this device. Configure packet count, hop limit and test duration alongside the selected methods."><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span></span>
            <div class="diagnostics-list diagnostics-settings">
                <?php
                $selected = icct_backend_diag_tools($diag['tools'] ?? '');
                $groups = [
                    ['Ping (Packets)', ['ping'=>'Ping (Packets)'], 'ping_count', 'Ping (Packets)', 1, 10],
                    ['Traceroute (Hops)', ['traceroute'=>'UDP','traceroute_icmp'=>'ICMP','traceroute_tcp'=>'TCP'], 'trace_hops', 'Traceroute (Hops)', 1, 30],
                    ['MTR', ['mtr_icmp'=>'MTR (ICMP)','mtr_tcp'=>'MTR (TCP)'], 'mtr_cycles', 'MTR Readings', 1, 30],
                    ['ARP', ['arp'=>'ARP'], '', '', 0, 0],
                    ['iPerf (Sec)', ['iperf3'=>'iPerf (Sec)'], 'bandwidth_seconds', 'Bandwidth Test (Sec)', 1, 30],
                    ['Pathchar', ['pathchar'=>'Pathchar'], '', '', 0, 0],
                    ['Netperf (Sec)', ['netperf'=>'Netperf (Sec)'], 'bandwidth_seconds', 'Bandwidth Test (Sec)', 1, 30],
                ];
                foreach ($groups as [$group,$methods,$parameter,$caption,$min,$max]):
                    $isGroup=count($methods)>1;
                    $key=array_key_first($methods);
                ?>
                <div class="diagnostic-row <?= $group==='MTR'?'diagnostic-row-mtr':'' ?>">
                    <label class="check-row diagnostic-primary">
                        <?php if ($isGroup): ?>
                        <input type="checkbox" <?= $group==='MTR'?'data-mtr-group':'data-traceroute-group' ?> aria-label="<?= icct_nms_h($group) ?>" />
                        <?php else: ?>
                        <input type="checkbox" name="diagnostic_tools[]" value="<?= $key ?>" <?= in_array($key,$selected,true)?'checked':'' ?> />
                        <?php endif; ?>
                        <?= icct_nms_h($group) ?>
                    </label>
                    <?php if ($parameter): ?>
                    <div class="diagnostic-parameter" data-diagnostic-parameter="<?= $parameter ?>">
                        <?php icct_nms_input($caption,$parameter,$diag[$parameter]??['ping_count'=>4,'trace_hops'=>20,'bandwidth_seconds'=>10,'mtr_cycles'=>(int)($diag['ping_count']??4)][$parameter],'number','min="'.$min.'" max="'.$max.'"'); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($isGroup): ?>
                    <div class="diagnostic-methods">
                        <?php foreach ($methods as $key=>$label): ?>
                        <label class="check-row diagnostic-child">
                            <input type="checkbox" name="diagnostic_tools[]" value="<?= $key ?>" <?= in_array($key,$selected,true)?'checked':'' ?> />
                            <?= icct_nms_h($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($group==='MTR'): ?>
                    <div class="diagnostic-monitor">
                        <label class="check-row"><input type="checkbox" name="mtr_background" value="1" <?= !empty($diag['mtr_background'])?'checked':'' ?> /> Automatic background monitoring</label>
                        <?php icct_nms_input('Monitoring Interval (Sec)','mtr_interval',$diag['mtr_interval']??300,'number','min="60" max="3600"'); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="protocol-actions"><button class="button primary">Save</button></div>
        </form>
        <?php
        require_once __DIR__.'/../../../dashboard/topology/services/topology_mtr_service.php';
        $mtrReports=$id ? icct_nms_link_mtr($id) : [];
        foreach ($mtrReports as $report): ?>
        <details class="diagnostic-monitor-report">
            <summary><?= icct_nms_h($report['method'].' · '.$report['collected'].' · '.$report['status']) ?></summary>
            <pre><?= icct_nms_h($report['output']) ?></pre>
        </details>
        <?php endforeach; ?>
    </section>
    <?php require __DIR__ . "/../../../graphs/templates/graphs.php"; ?>
    <?php require __DIR__ . "/../../../graphs/templates/data_queries.php"; ?>
    <?php endif; ?>
    <?php if (empty($wizard) && !$presetMode): ?>
    <footer class="form-footer">
        <a class="button" id="protocol-previous" href="inventory/controllers/device.php?id=<?= $id ?>">Previous ←</a>
        <a class="button" id="protocol-next" href="#diagnostics">Next →</a>
    </footer>
    <?php endif; ?>
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
