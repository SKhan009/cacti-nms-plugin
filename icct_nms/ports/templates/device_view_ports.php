<?php
/** Device port tabs reuse the shared port model and its monitoring permissions. */
$serialView = icct_backend_serial_assignment($id);
if ($serialView && (int) $old['snmp_version'] === 0):
    $serialState = icct_backend_config_connection_status($id);
    $serialTarget = null;
    $serialError = '';
    try {
        $serialTarget = icct_backend_config_target($id, false);
    } catch (Throwable $e) {
        $serialError = $e->getMessage();
    }
    ?>
    <section
        id="view-ports"
        data-device-view-panel="ports"
        hidden>
        <h2>Serial Connection</h2>
        <dl class="device-detail-grid">
            <?php foreach (
                [
                    'Connection' => $serialView['connection_name'],
                    'Endpoint' => $serialView['endpoint'],
                    'Device Bus Address' => $serialView['device_address'],
                    'Status' => $serialState['status'] ?? 'Unavailable',
                ] as $label => $value
            ): ?>
                <div>
                    <dt><?= icct_nms_h($label) ?></dt>
                    <dd><?= icct_nms_h($value) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <?php if ($serialError): ?>
            <p role="status"><?= icct_nms_h($serialError) ?></p>
        <?php endif; ?>
        <?php if (
            $serialTarget
        ): ?>
            <h3>Register Readings</h3>
            <div class="site-table-wrap">
                <table class="site-table">
                    <thead>
                        <tr>
                            <th>Parameter</th>
                            <th>Register offset</th>
                            <th>Read function</th>
                            <th>Value</th>
                            <th>Units</th>
                            <th>Status</th>
                            <th>Collected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($serialTarget['fields'] as $key => $field):
                            $reading = icct_backend_config_reading($id, $key); ?>
                            <tr>
                                <td><?= icct_nms_h($field['label']) ?></td>
                                <td><?= (int) $field['offset'] ?></td>
                                <td><?= (int) $field['function'] ?></td>
                                <td><?= icct_nms_h($reading['value'] ?? '—') ?></td>
                                <td><?= icct_nms_h($field['unit']) ?></td>
                                <td><?= icct_nms_h(ucfirst($reading['status'])) ?></td>
                                <td><?= empty($reading['observed_at']) ? 'Awaiting collection' : icct_nms_time_cell($reading['observed_at']) ?></td>
                            </tr>
                        <?php
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (
            is_realm_allowed(3)
        ): ?>
            <a
                class="button primary"
                href="inventory/controllers/device.php?id=<?= (int) $id ?>#protocol">Configure Serial Registers</a>
        <?php endif; ?>
    </section>
<?php return;
endif;
$portView = icct_nms_config_ports_view($old);
$states = [
    1 => 'UP',
    2 => 'DOWN',
    3 => 'TESTING',
    4 => 'UNKNOWN',
    5 => 'DORMANT',
    6 => 'NOT PRESENT',
    7 => 'LOWER LAYER DOWN',
];
if (!isset($profile)) {
    $classification = db_fetch_row_prepared(
        'SELECT category_id,device_type FROM plugin_icct_nms_device_classification WHERE host_id=?',
        [$id],
    );
    foreach (icct_nms_device_types() as $type) {
        if ((int) $type['category_id'] === (int) ($classification['category_id'] ?? 0) &&
            $type['name'] === ($classification['device_type'] ?? '')
        ) {
            $profile = $type;
            break;
        }
    }
}
$slots = icct_nms_port_slots($portView['ports'], $profile['physical_ports'] ?? null);
$physicalPorts = array_values(
    array_filter($slots['chassis'], static fn ($port) => empty($port['placeholder'])),
);
$visualHardware = icct_nms_visual_hardware_ports($portView['ports']);
$usbPanel = icct_nms_usb_panel_ports($portView['usb_hosts'] ?? [], $visualHardware);
$physicalPorts = array_merge($physicalPorts, $visualHardware);
$softwarePorts = array_values(array_filter(
    $slots['ports'],
    static fn ($port) => empty($port['hardware']) && icct_nms_port_kind($port) === 'Software',
));
$allPorts = array_merge($physicalPorts, $softwarePorts);
$portTab = ($_GET['ports_tab'] ?? 'interfaces') === 'history' ? 'history' : 'interfaces';
$portHistoryActive = $portTab === 'history';
$portView['ports'] = $portHistoryActive ? [] : $allPorts;
$isPhysicalTab = false;
$portConnections = $portHistoryActive || !$portView['fresh']
    ? [] : icct_nms_port_connections($id, $portView['ports'], true);
$portBanks = $portHistoryActive ? [] : array_merge(
    array_chunk($slots['chassis'], 16),
    array_chunk($usbPanel, 16),
);
$portTabs = ['interfaces' => 'Interface/Port', 'history' => 'Port alarm history'];
$portAvailability = static function ($port) use ($portView) {
    if (!empty($port['placeholder']) || !($port['fresh'] ?? $portView['fresh'])) {
        return 'Unknown';
    }
    if (!empty($port['hardware'])) {
        return match ($port['status'] ?? '') {
            'connected' => 'In use',
            'disconnected', 'Not present' => 'Not in use',
            'Enumerated' => 'Present',
            default => 'Unknown',
        };
    }
    return match ($port['status'] ?? '') {
        'In use', 'connected' => 'In use',
        'Available (link down)', 'Disabled', 'disconnected', 'Not present', 'Dormant', 'Lower layer down' => 'Not in use',
        default => 'Unknown',
    };
};
$portTone = static function ($port) use ($portView) {
    if (!empty($port['placeholder']) || !($port['fresh'] ?? $portView['fresh'])) {
        return 'unknown';
    }
    return match ($port['oper'] ?? null) {
        1 => 'up',
        2, 7 => 'down',
        default => 'unknown',
    };
};
?>
<section
    id="view-ports"
    data-device-view-panel="ports"
    hidden>
    <h2 class="device-help-heading">Interfaces/Ports <?php
    $headingHelpText = 'Groups use the device-reported connector and interface type. Network connection details come from LLDP/CDP or learned MAC and IP observations. Power inputs appear when the monitored Linux device reports their online state.';
    require __DIR__ . '/../../shared/templates/heading_help.php';
    ?>
 </h2>
    <?php
    $portTabUrl = static fn ($tab) => 'inventory/controllers/device.php?' .
        http_build_query(['id' => (int) $id, 'view' => 1, 'ports_tab' => $tab]) . '#view-ports';
    ?>
    <nav
        class="device-view-tabs device-port-tabs"
        aria-label="Interface sections">
        <?php foreach ($portTabs as $key => $label): ?>
            <a
                href="<?= icct_nms_h($portTabUrl($key)) ?>"
                class="<?= $key === $portTab ? 'current' : '' ?>"
                <?= $key === $portTab ? 'aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($portBanks): ?>
        <div
            class="device-port-chassis"
            role="group"
            aria-label="Network, USB and power ports">
            <?php foreach ($portBanks as $bank): ?>
                <div class="device-port-bank">
                    <?php foreach (
                        array_chunk($bank, 2) as $pair
                    ): ?>
                        <div class="device-port-pair <?= count($pair) === 1 && count($bank) === 1 ? 'single' : '' ?>">
                            <?php
                            foreach ($pair as $port):
                                $label = !empty($port['placeholder'])
                                        ? (!empty($port['usb_slot']) ? 'USB port ' : 'Slot ') . $port['panel_port']
                                        : $port['name'];
                                $shortLabel = !empty($port['hardware']) || !empty($port['usb_slot'])
                                    ? $port['panel_port'] : $label; ?>
                                <button
                                    type="button"
                                    class="device-port-jack <?= $portTone($port) ?>"
                                    data-port-index="<?= (int) $port['index'] ?>"
                                    <?= !empty($port['placeholder']) ? 'disabled' : '' ?>

                                    aria-label="<?= icct_nms_h(
                                        $label . ' · ' . $portAvailability($port) . ' · ' . (!empty($port['placeholder']) ? 'Not discovered' : (!($port['fresh'] ?? $portView['fresh']) ? 'Unknown (stale reading)' : (!empty($port['hardware']) ? $port['status'] : ($states[$port['oper'] ?? 0] ?? 'UNKNOWN')))),
                                    ) ?>"
                                    title="<?= icct_nms_h(
                                        $label . ' · ' . $portAvailability($port) . ' · ' . (!empty($port['placeholder']) ? 'Not discovered' : (!($port['fresh'] ?? $portView['fresh']) ? 'Unknown (stale reading)' : (!empty($port['hardware']) ? $port['status'] : ($states[$port['oper'] ?? 0] ?? 'UNKNOWN')))),
                                    ) ?>">
                                    <span><?= icct_nms_h($shortLabel) ?></span>
                                    <svg
                                        viewBox="0 0 40 30"
                                        aria-hidden="true">
                                        <path d="M3 27V9h7V5h7V2h6v3h7v4h7v18Z" />
                                    </svg>
                                </button>
                            <?php
                            endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($portHistoryActive): ?>
        <?php require __DIR__ . '/device_port_history.php'; ?>
    <?php else: ?>
        <div class="device-port-toolbar">
            <div class="field">
                <input
                    id="device-port-search"
                    type="search"
                    aria-label="Search interfaces"
                    placeholder="Search by port name, IP or MAC address"
                    aria-controls="device-port-rows">
            </div>
            <button
                type="button"
                class="device-port-export"
                id="device-port-export"
                aria-label="Export interfaces CSV"
                title="Export interfaces CSV">
                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true">
                    <path d="M12 15V3m-4 4 4-4 4 4M5 12v8h14v-8" />
                </svg>
            </button>
        </div>
        <div class="device-port-table-wrap">
            <table
                class="device-port-table"
                id="device-port-table">
                <thead>
                    <tr>
                        <?php foreach (
                            [
                                'ifIndex',
                                'ifName',
                                'Port No',
                                'Availability',
                                'Status',
                                'Connected Device',
                                'Connected IP',
                                'VLAN ID',
                                'Admin Status',
                                'ifSpeed (Gb/s)',
                                'Last change Time',
                            ] as $heading
                        ): ?>
                            <th><?= icct_nms_h($heading) ?></th>
                        <?php endforeach; ?>
                        <?php if ($isPhysicalTab): ?>
                            <th>Learned endpoints</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="device-port-rows">
                    <?php foreach ($portView['ports'] as $port):
                        $fresh = ($port['fresh'] ?? $portView['fresh']) && empty($port['placeholder']);
                        [$device, $address, $vlan] = icct_nms_port_connection_cells(
                            $port,
                            $portConnections[$port['index']] ?? [],
                            $fresh,
                        );
                        $speed = ($port['high_speed_mbps'] ?? 0) * 1000000 ?: $port['speed_bps'] ?? 0;
                        $lastChange = 'Not reported';
                        $ticks = $port['last_change_ticks'] ?? null;
                        $uptime = $portView['uptime_ticks'];
                        if ($fresh && $ticks !== null) {
                            if ($ticks === 0) {
                                $lastChange = 'Before last agent restart';
                            } elseif ($uptime !== null && $uptime >= $ticks) {
                                $lastChange = icct_nms_display_time(
                                    (int) ($portView['collected'] - ($uptime - $ticks) / 100),
                                );
                            }
                        }
                        $availability = $portAvailability($port);
                        $cells = [
                            !empty($port['hardware']) ? '—' : (empty($port['placeholder']) ? (int) $port['index'] : 'Not reported'),
                            $port['name'],
                            $port['panel_port'] ?? 'Not reported',
                            $availability,
                            $fresh ? (!empty($port['hardware']) ? $port['status'] : ($states[$port['oper'] ?? 0] ?? 'UNKNOWN')) : 'UNKNOWN',
                            $device,
                            $address,
                            $vlan,
                            !empty($port['hardware']) ? '—' : ($fresh ? $states[$port['admin'] ?? 0] ?? 'UNKNOWN' : 'UNKNOWN'),
                            !empty($port['hardware']) ? '—' : ($fresh && $speed > 0
                                        ? rtrim(rtrim(number_format($speed / 1e9, 6, '.', ''), '0'), '.')
                                        : 'Not reported'),
                            !empty($port['hardware']) ? '—' : $lastChange,
                        ];
                        ?>
                        <tr data-port-row="<?= (int) $port['index'] ?>" data-interface-type="<?= icct_nms_h(icct_nms_port_type_label($port)) ?>" class="<?= !empty($port['hardware']) ? 'device-port-hardware-row' : '' ?>">
                            <?php foreach (
                                $cells as $column => $cell
                            ): ?>
                                <td<?php if (in_array($column, [4, 8], true)): ?>

                                    class="port-state <?= $fresh
                                        ? (in_array($cell, ['UP', 'connected'], true)
                                            ? 'up'
                                            : (in_array($cell, ['DOWN', 'LOWER LAYER DOWN', 'disconnected', 'Not present'], true)
                                                ? 'down'
                                                : 'unknown'))
                                        : 'unknown' ?>"
                                    <?php endif; ?>><?= $column === 10 && $cell !== '—' && $cell !== 'Not reported' && $cell !== 'Before last agent restart' ? icct_nms_time_cell($cell) : icct_nms_h($cell) ?></td>
                            <?php endforeach; ?>
                            <?php if ($isPhysicalTab): ?>
                                <?php $learned = $fresh ? ($portConnections[$port['index']]['endpoints'] ?? []) : []; ?>
                                <td class="device-port-endpoints">
                                    <?php if ($learned): ?>
                                        <details>
                                            <summary><?= count($learned) ?> learned</summary>
                                            <ul>
                                                <?php foreach ($learned as $endpoint): ?>
                                                    <li>
                                                        <a
                                                            href="dashboard/topology/controllers/topology_configuration.php?tab=discovered&amp;mac=<?= rawurlencode($endpoint['mac']) ?>"
                                                            title="Find this MAC in Discovered Devices"><?= icct_nms_h($endpoint['mac']) ?></a>
                                                        <?php if ($endpoint['ips']): ?>
                                                            <div>IP seen on this interface: <?= icct_nms_h(implode(', ', $endpoint['ips'])) ?></div>
                                                        <?php else: ?>
                                                            <div>No IP matched on this interface</div>
                                                        <?php endif; ?>
                                                        <?php if ($endpoint['vlans']): ?>
                                                            <div>VLANs reported by the switch: <?= icct_nms_h(implode(', ', $endpoint['vlans'])) ?></div>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php else: ?>
                                        <?= !empty($port['hardware']) ? '—' : 'None reported' ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php
                    endforeach; ?>
                </tbody>
            </table>
        </div>
        <p
            id="device-port-empty"
            <?= $portView['ports'] ? 'hidden' : '' ?>>No interfaces reported for the current configuration.</p>
        <p
            id="device-port-count"
            role="status"
            aria-live="polite"></p>
    <?php endif; ?>
</section>
