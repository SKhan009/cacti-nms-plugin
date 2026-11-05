<?php

/** Topology settings and validation feedback are rendered from the controller view model. */
$connectionFieldHelp = static function ($label, $text) { ?>
    <span
        class="field-info"
        tabindex="0"
        role="img"
        aria-label="Information about <?= icct_nms_h($label) ?>"
        data-tooltip="<?= icct_nms_h($text) ?>">
        <svg
            viewBox="0 0 20 20"
            aria-hidden="true">
            <circle
                cx="10"
                cy="10"
                r="8" />
            <path d="M10 9v5M10 6v.5" />
        </svg>
    </span>
<?php };
$rowIcon = static function ($name) {
    $paths = [
        'edit' => '<path d="m16 3 5 5L8 21l-5 1 1-5ZM14 5l5 5"/>',
        'remove' => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
        'configure' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8h10M7 16h10M10 5v6M14 13v6"/>',
        'view' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'add' => '<path d="M12 5v14M5 12h14"/>',
        'run' => '<path d="m8 4 12 8-12 8Z"/>',
        'cancel' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'retry' => '<path d="M3 10a9 9 0 1 1 1 7M3 4v6h6"/>',
    ];
    echo '<svg viewBox="0 0 24 24" aria-hidden="true">' . $paths[$name] . '</svg>';
};
$connectionDeviceActions = static function ($id) use ($management, $rowIcon) {
    if (!$management || $id < 1) {
        return;
    }
    ?>
    <a
        class="icon-button"
        href="inventory/controllers/device.php?id=<?= (int) $id ?>#protocol"
        aria-label="Device Configuration"
        title="Device Configuration">
        <?php $rowIcon('configure'); ?>
    </a>
<?php
};
$scanResultAction = static function ($d) use ($management, $rowIcon) {
    if (!$management) {
        return;
    }
    if (!empty($d['ambiguous'])) { ?>
        <a
            class="icon-button"
            href="inventory/controllers/inventory.php"
            aria-label="Review matches"
            title="Review matches">
            <?php $rowIcon('view'); ?>
        </a>
    <?php } elseif ($d['host_id']) { ?>
        <a
            class="icon-button"
            href="inventory/controllers/device.php?id=<?= (int) $d['host_id'] ?>"
            aria-label="Configure"
            title="Configure">
            <?php $rowIcon('edit'); ?>
        </a>
    <?php } elseif ($d['reachable']) { ?>
        <a
            class="icon-button"
            href="inventory/controllers/device.php?discovery_result=<?= (int) $d['id'] ?>"
            aria-label="Review / Add"
            title="Review / Add">
            <?php $rowIcon('add'); ?>
        </a>
<?php }
    };
?>
<section class="topology-configuration">
    <div class="titlebar">
        <h1>Topology Configuration</h1>
        <a
            class="button"
            href="dashboard/controllers/topology.php">View Topology</a>
    </div>
    <nav
        class="presets-tabs"
        aria-label="Topology configuration sections">
        <?php foreach (
            [
                'connections' => 'Device Connections',
                'networks' => 'Networks',
                'discovered' => 'Discovered Devices'
            ] as $key => $label
        ): ?>
            <a
                href="<?= icct_nms_h(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>?tab=<?= $key ?>"
                <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($tab === 'connections'): ?>
        <h2>Manual device connections</h2>
        <?php if ($management): ?>
            <form
                method="post"
                class="topology-config-form">
                <?php icct_nms_token(); ?>
                <input
                    type="hidden"
                    name="action"
                    value="save_link">
                <input
                    type="hidden"
                    name="link_id"
                    value="<?= icct_nms_h($edit['link_id'] ?? ($_GET['edit'] ?? '')) ?>">
                <div class="form-grid">
                    <?php foreach (['source' => 'Source Device', 'target' => 'Target Device'] as $key => $label): ?>
                        <div class="field">
                            <label for="<?= $key ?>"><?= $label ?> * <span
                                    class="field-info"
                                    tabindex="0"
                                    role="img"
                                    aria-label="<?= $key === 'source'
                                        ? 'Choose the device where the connection starts. Search by device name or address.'
                                        : 'Choose the connected device. It must be different from the source device. Search by name or address.' ?>"
                                    data-tooltip="<?= $key === 'source'
                                        ? 'Choose the device where the connection starts. Search by device name or address.'
                                        : 'Choose the connected device. It must be different from the source device. Search by name or address.' ?>">
                                    <svg
                                        viewBox="0 0 20 20"
                                        aria-hidden="true">
                                        <circle
                                            cx="10"
                                            cy="10"
                                            r="8" />
                                        <path d="M10 9v5M10 6v.5" />
                                    </svg>
                                </span>
                            </label>
                            <select
                                class="connection-device-select"
                                id="<?= $key ?>"
                                name="<?= $key ?>"
                                required>
                                <option value="">Select device</option>
                                <?php foreach ($devices as $d): ?>
                                    <option
                                        value="<?= (int) $d['id'] ?>"
                                        data-address="<?= icct_nms_h($d['hostname'] ?? '') ?>"
                                        <?= (int) ($edit[$key] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= icct_nms_h($d['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <div class="field">
                        <label for="profile">Network Connection * <?php $connectionFieldHelp(
                            'Network Connection',
                            'Choose a preset from Presets → Network Connections, such as Ethernet or Fiber. The ' .
                                'preset controls how this connection is drawn in topology.',
                        ); ?></label>
                        <select
                            id="profile"
                            name="profile"
                            required>
                            <option value="">Select connection type</option>
                            <?php foreach ($profiles as $key => $p): ?>
                                <option
                                    value="<?= icct_nms_h($key) ?>"
                                    <?= ($edit['profile'] ?? '') === $key ? 'selected' : '' ?>><?= icct_nms_h($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php foreach (['source_port' => 'Source Port', 'target_port' => 'Target Port'] as $key => $label): ?>
                        <div class="field">
                            <label for="<?= $key ?>"><?= $label ?> <?php $connectionFieldHelp(
                                $label,
                                'Optional. Enter the interface on the ' .
                                    ($key === 'source_port' ? 'Source' : 'Target') .
                                    ' Device, for example Gi1/0/1, Ethernet1 or port number 1. Leave blank if unknown. Maximum 100 characters.',
                            ); ?></label>
                            <input
                                id="<?= $key ?>"
                                name="<?= $key ?>"
                                maxlength="100"
                                value="<?= icct_nms_h($edit[$key] ?? '') ?>"
                                placeholder="Interface name or port number">
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="type-editor-actions">
                    <a
                        class="button"
                        href="<?= icct_nms_h(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>?tab=connections">Cancel</a>
                    <button class="button primary">Save Connection</button>
                </div>
            </form>
        <?php endif; ?>
        <h2>Manual connections and automatically detected devices</h2>
        <div class="topology-discovery-filters connection-filters">
            <div class="field">
                <label for="connectionSearch">Search connections</label>
                <input
                    type="search"
                    id="connectionSearch"
                    placeholder="Device, address, port or method">
            </div>
            <div class="field">
                <label for="connectionOrigin">Connection source</label>
                <select id="connectionOrigin">
                    <option value="">All connections</option>
                    <option>Manual</option>
                    <option>Automatic</option>
                </select>
            </div>
        </div>
        <div class="topology-table-scroll">
            <table
                class="action-table site-table"
                id="connectionResults">
                <thead>
                    <tr>
                        <th>Source / Port</th>
                        <th>Target / Port</th>
                        <th>Connection / Method</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Last Detected</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $key => $link): ?>
                        <tr data-origin="Manual">
                            <td><?= icct_nms_h(
                                $byId[$link['source']]['description'] . ' / ' . ($link['source_port'] ?: 'Not specified'),
                            ) ?></td>
                            <td><?= icct_nms_h(
                                $byId[$link['target']]['description'] . ' / ' . ($link['target_port'] ?: 'Not specified'),
                            ) ?></td>
                            <td><?= icct_nms_h($profiles[$link['profile']]['name'] ?? 'Custom') ?></td>
                            <td>Manual</td>
                            <td>
                                <span class="device-status other">Configured</span>
                            </td>
                            <td>—</td>
                            <td>
                                <div class="topology-row-actions">
                                    <?php if ($management): ?>
                                        <a
                                            class="icon-button"
                                            href="<?= icct_nms_h(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>?tab=connections&amp;edit=<?= icct_nms_h($key) ?>"
                                            aria-label="Edit"
                                            title="Edit">
                                            <?php $rowIcon('edit'); ?>
                                        </a>
                                        <form
                                            method="post"
                                            class="topology-inline-form">
                                            <?php icct_nms_token(); ?>
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete_link">
                                            <input
                                                type="hidden"
                                                name="link_id"
                                                value="<?= icct_nms_h($key) ?>">
                                            <button
                                                class="icon-button"
                                                aria-label="Remove connection"
                                                title="Remove connection">
                                                <?php $rowIcon('remove'); ?>
                                            </button>
                                        </form>
                                    <?php $connectionDeviceActions((int)$link['source']); endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($observations as $d): ?>
                        <tr
                            data-origin="Automatic"
                            data-reporter-id="<?= (int) $d['reporter_id'] ?>">
                            <td><?= icct_nms_h($d['reporter'] . ' / ' . ($d['port'] ?: 'Not specified')) ?></td>
                            <td><?= icct_nms_h($d['name'] . ' / ' . ($d['remote_port'] ?: 'Not specified')) ?><?php if ($d['address']): ?><div><?= icct_nms_h($d['address']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= icct_nms_h($d['protocol']) ?><div><?= icct_nms_h($d['detail']) ?></div>
                            </td>
                            <td>Automatic</td>
                            <td>
                                <span class="device-status <?= $d['status'] === 'Current' ? 'online' : 'other' ?>"><?= icct_nms_h($d['status']) ?></span>
                            </td>
                            <td><?= icct_nms_h($d['last_check']) ?></td>
                            <td>
                                <div class="topology-row-actions">
                                    <?php $connectionDeviceActions((int) $d['reporter_id']); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($connectionScanResults as $d): ?>
                        <tr data-origin="Automatic">
                            <td><?= icct_nms_h($d['name'] . ' / Not reported') ?><?php if ($d['name'] !== $d['address']): ?><div><?= icct_nms_h($d['address']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>Not identified</td>
                            <td><?= icct_nms_h($d['method']) ?><div><?= icct_nms_h($d['detail']) ?></div>
                            </td>
                            <td>Automatic</td>
                            <td>
                                <span class="device-status <?= $d['reachable'] ? 'online' : 'other' ?>"><?= $d['reachable'] ? 'Detected' : 'No response' ?></span>
                            </td>
                            <td><?= icct_nms_h($d['observed_at']) ?></td>
                            <td>
                                <div class="topology-row-actions">
                                    <?php $scanResultAction($d); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr
                        id="connectionEmpty"
                        <?= $links || $observations || $connectionScanResults ? 'hidden' : '' ?>>
                        <td colspan="7">No matching manual connections or detected devices.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav
            class="inventory-pagination"
            aria-label="Connection results pages">
            <label>Items per page: <select id="connectionSize">
                    <option>10</option>
                    <option selected>25</option>
                    <option>50</option>
                </select>
            </label>
            <span
                id="connectionRange"
                aria-live="polite"></span>
            <div class="page-controls">
                <label>
                    <span class="sr-only">Page</span>
                    <select id="connectionPageNumber"></select>
                </label>
                <span id="connectionPageCount"></span>
                <button
                    type="button"
                    id="connectionPrevious"
                    aria-label="Previous page"
                    class="pagination-control">
                    <svg
                        class="pagination-arrow"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                        focusable="false">
                        <path d="M15 5 7 12 15 19Z" />
                    </svg>
                </button>
                <button
                    type="button"
                    id="connectionNext"
                    aria-label="Next page"
                    class="pagination-control">
                    <svg
                        class="pagination-arrow"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                        focusable="false">
                        <path d="M9 5 17 12 9 19Z" />
                    </svg>
                </button>
            </div>
        </nav>
    <?php elseif ($tab === 'networks'): ?>
        <?php if (!$automation): ?>
            <p>Your account does not have Cacti Automation permission.</p>
        <?php else: ?>
            <?php if ($networkEdit !== null):
                require __DIR__ . '/../../../configuration/templates/network_configuration.php';
            else: ?>
                <div class="titlebar">
                    <h2>Discovery networks</h2>
                    <a
                        class="button primary"
                        href="<?= icct_nms_h(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>?tab=networks&amp;network_edit=0">Add Network</a>
                </div>
                <table
                    class="action-table site-table"
                    <?= $nativeRunning ||
            array_filter($scanJobs, fn ($job) => in_array($job['state'], ['queued', 'running'], true))
            ? 'data-live-discovery'
            : '' ?>>
                    <thead>
                        <tr>
                            <th>Network</th>
                            <th>Subnet Range</th>
                            <th>Enabled</th>
                            <th>Addresses / Up / SNMP</th>
                            <th>Last Started</th>
                            <th>Result</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($networks as $network): ?>
                            <tr>
                                <td><?= icct_nms_h($network['name']) ?></td>
                                <td><?= icct_nms_h($network['subnet_range']) ?></td>
                                <td><?= $network['enabled'] === 'on' ? 'Enabled' : 'Disabled' ?></td>
                                <td><?= (int) $network['total_ips'] ?> / <?= (int) $network['up_hosts'] ?> / <?= (int) $network['snmp_hosts'] ?></td>
                                <td><?= icct_nms_h($network['last_started']) ?></td>
                                <td>
                                    <?php $job = $scanJobs[$network['id']] ?? null;
                            $nativeBusy = in_array($network['id'], $nativeRunning); ?>
                                    <?php if ($nativeBusy): ?> Native discovery running ·
                                    <?php endif; ?><?= icct_nms_h(
                                        $job
                                            ? ucfirst($job['state']) . ' · ' . $job['completed'] . ' / ' . $job['total']
                                            : $network['last_status'],
                                    ) ?><?php if ($job && !empty($job['last_error'])): ?><div><?= icct_nms_h($job['last_error']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="topology-row-actions">
                                        <a
                                            class="icon-button"
                                            href="<?= icct_nms_h(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>?tab=networks&amp;network_edit=<?= (int) $network['id'] ?>"
                                            aria-label="Configure"
                                            title="Configure">
                                            <?php $rowIcon('edit'); ?>
                                        </a>
                                        <a
                                            class="icon-button"
                                            href="<?= icct_nms_h(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>?tab=discovered&amp;network=<?= (int) $network['id'] ?>"
                                            aria-label="Discovered Devices"
                                            title="Discovered Devices">
                                            <?php $rowIcon('view'); ?>
                                        </a>
                                        <form
                                            method="post"
                                            class="topology-inline-form">
                                            <?php icct_nms_token(); ?>
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="run_network">
                                            <input
                                                type="hidden"
                                                name="network_id"
                                                value="<?= (int) $network['id'] ?>">
                                            <button
                                                class="icon-button"
                                                aria-label="Discover Now"
                                                title="Discover Now"
                                                <?= $network['enabled'] !== 'on' ||
                                                $nativeBusy ||
                                                ($job && in_array($job['state'], ['queued', 'running'], true))
                                                    ? 'disabled'
                                                    : '' ?>>
                                                <?php $rowIcon('run'); ?>
                                            </button>
                                        </form>
                                        <?php if (
                                            $job && in_array($job['state'], ['queued', 'running', 'partial', 'failed', 'cancelled'], true)
                                        ): ?>
                                            <form
                                                method="post"
                                                class="topology-inline-form">
                                                <?php icct_nms_token(); ?>
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="<?= in_array($job['state'], ['queued', 'running'], true) ? 'cancel_scan' : 'retry_scan' ?>">
                                                <input
                                                    type="hidden"
                                                    name="job_id"
                                                    value="<?= (int) $job['id'] ?>">
                                                <button
                                                    class="icon-button"
                                                    aria-label="<?= in_array($job['state'], ['queued', 'running'], true) ? 'Cancel discovery' : 'Retry discovery' ?>"
                                                    title="<?= in_array($job['state'], ['queued', 'running'], true) ? 'Cancel discovery' : 'Retry discovery' ?>">
                                                    <?php $rowIcon(in_array($job['state'], ['queued', 'running'], true) ? 'cancel' : 'retry'); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$networks): ?>
                            <tr>
                                <td colspan="7">No discovery networks configured.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
        <?php endif; endif; ?>
    <?php else: ?>
        <div class="titlebar">
            <h2>Discovered devices and neighbours</h2>
            <div class="topology-heading-actions">
                <?php if ($automation): ?>
                    <a
                        class="button"
                        href="<?= icct_nms_h($config['url_path']) ?>automation_devices.php">Manage / Add to Cacti</a>
                <?php endif; ?>
                <a
                    class="button"
                    href="<?= icct_nms_h(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>?tab=discovered">Refresh</a>
            </div>
        </div>
        <div class="topology-discovery-filters">
            <div class="field">
                <label for="discoveredSearch">Search</label>
                <input
                    type="search"
                    id="discoveredSearch"
                    placeholder="Device, address, reporter or port">
            </div>
            <div class="field">
                <label for="discoveredProtocol">Method</label>
                <select id="discoveredProtocol">
                    <option value="">All methods</option>
                    <?php foreach (['Cacti Network', 'IP scan', 'LLDP', 'CDP', 'ARP', 'FDB'] as $method): ?>
                        <option><?= $method ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="discoveredNetwork">Network</label>
                <select id="discoveredNetwork">
                    <option value="">All networks</option>
                    <?php foreach ($networks as $network): ?>
                        <option
                            value="<?= (int) $network['id'] ?>"
                            <?= (string) ($_GET['network'] ?? '') === (string) $network['id'] ? 'selected' : '' ?>><?= icct_nms_h($network['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p
            id="discoveredCount"
            aria-live="polite"></p>
        <div class="topology-table-scroll">
            <table
                class="site-table"
                id="discoveredTable">
                <thead>
                    <tr>
                        <th>Device / Identity</th>
                        <th>Address</th>
                        <th>Network / Reporting Device</th>
                        <th>Method</th>
                        <th>Local / Remote Port</th>
                        <th>Status</th>
                        <th>Last Check</th>
                        <th>Evidence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scanResults as $d): ?>
                        <tr
                            data-method="IP scan"
                            data-network="<?= (int) $d['network_id'] ?>">
                            <td><?= icct_nms_h($d['name']) ?></td>
                            <td><?= icct_nms_h($d['address']) ?></td>
                            <td><?= icct_nms_h($d['scan_name'] . ' / ' . $d['collector']) ?></td>
                            <td><?= icct_nms_h($d['method']) ?></td>
                            <td>—</td>
                            <td>
                                <span class="device-status <?= $d['reachable'] ? 'online' : 'other' ?>"><?= $d['reachable'] ? 'Detected' : 'No response' ?></span>
                            </td>
                            <td><?= icct_nms_h($d['observed_at']) ?></td>
                            <td><?= icct_nms_h($d['detail']) ?><div class="topology-row-actions">
                                    <?php $scanResultAction($d); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($coreDevices as $d):
                        $status = $d['up'] ? 'Up' : 'Down'; ?>
                        <tr
                            data-method="Cacti Network"
                            data-network="<?= (int) $d['network_id'] ?>">
                            <td><?= icct_nms_h($d['sysName'] ?: ($d['hostname'] ?: 'Not detected')) ?></td>
                            <td><?= icct_nms_h($d['ip']) ?></td>
                            <td><?= icct_nms_h($d['network']) ?></td>
                            <td>Cacti Network</td>
                            <td>—</td>
                            <td>
                                <span class="device-status <?= icct_nms_status_class($status) ?>"><?= $status ?></span>
                            </td>
                            <td><?= icct_nms_h($d['last_check']) ?></td>
                            <td><?= $d['known'] ? 'Known to Cacti' : 'Not added' ?> · SNMP <?= $d['snmp'] ? 'Up' : 'Down' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($observations as $d): ?>
                        <tr
                            data-method="<?= icct_nms_h($d['protocol']) ?>"
                            data-network="">
                            <td><?= icct_nms_h($d['name']) ?></td>
                            <td><?= icct_nms_h($d['address']) ?></td>
                            <td><?= icct_nms_h($d['reporter']) ?></td>
                            <td><?= icct_nms_h($d['protocol']) ?></td>
                            <td><?= icct_nms_h($d['port'] . ' / ' . $d['remote_port']) ?></td>
                            <td>
                                <span class="device-status <?= $d['status'] === 'Current' ? 'online' : 'other' ?>"><?= $d['status'] ?></span>
                            </td>
                            <td><?= icct_nms_h($d['last_check']) ?></td>
                            <td><?= icct_nms_h($d['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr
                        id="discoveredEmpty"
                        hidden>
                        <td colspan="8">No matching discovery results.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav
            class="inventory-pagination discovery-pagination"
            aria-label="Discovery results pages">
            <label>Items per page: <select id="discoveredSize">
                    <option>10</option>
                    <option selected>25</option>
                    <option>50</option>
                </select>
            </label>
            <span
                id="discoveredRange"
                aria-live="polite"></span>
            <div class="page-controls">
                <label>
                    <span class="sr-only">Page</span>
                    <select id="discoveredPageNumber"></select>
                </label>
                <span id="discoveredPage"></span>
                <button
                    type="button"
                    id="discoveredPrevious"
                    aria-label="Previous page"
                    class="pagination-control">
                    <svg
                        class="pagination-arrow"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                        focusable="false">
                        <path d="M15 5 7 12 15 19Z" />
                    </svg>
                </button>
                <button
                    type="button"
                    id="discoveredNext"
                    aria-label="Next page"
                    class="pagination-control">
                    <svg
                        class="pagination-arrow"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                        focusable="false">
                        <path d="M9 5 17 12 9 19Z" />
                    </svg>
                </button>
            </div>
        </nav>
        <span
            class="field-info section-help"
            tabindex="0"
            role="img"
            aria-label="Current LLDP/CDP neighbours that uniquely match an accessible Cacti device appear as dashboard connections. ARP/FDB entries remain observations. Add unknown devices to Cacti before including them in the device topology."
            data-tooltip="Current LLDP/CDP neighbours that uniquely match an accessible Cacti device appear as dashboard connections. ARP/FDB entries remain observations. Add unknown devices to Cacti before including them in the device topology.">ⓘ</span>
    <?php endif; ?>
</section>
