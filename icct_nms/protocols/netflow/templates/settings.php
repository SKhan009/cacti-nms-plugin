<?php /** NetFlow settings expose the local collector configuration and its validation errors. */
$nfFields = [
    'version' => [
        'Version',
        'select',
        [5 => 'NetFlow v5', 7 => 'NetFlow v7', 9 => 'NetFlow v9', 10 => 'IPFIX'],
    ],
    'collector' => ['Collector Address', 'text', 'Receiver hostname or IP address'],
    'port' => ['Collector Port', 'number', '1–65535'],
    'active_timeout' => ['Active Timeout (Sec)', 'number', '1–86400'],
    'inactive_timeout' => ['Inactive Timeout (Sec)', 'number', '1–86400'],
    'sampling_rate' => ['Sampling Rate', 'number', 'Packets per sample'],
    'lan_cidrs' => ['LAN Prefixes', 'text', 'Comma-separated CIDRs'],
    'wan_interfaces' => ['WAN Interface ifIndex Values', 'text', 'Optional: 2,3'],
    'bandwidth_limit_mbps' => ['Bandwidth Alert Limit (Mbps)', 'number', '5-minute average'],
    'scan_threshold' => [
        'Scan Alert Destinations / Ports',
        'number',
        'Distinct targets per 5 minutes',
    ],
    'syn_pps_threshold' => ['SYN Alert Packets / Sec', 'number', '5-minute average'],
];
$nfHelp = [
    'version' => 'Flow format sent by the device: v5, v7, v9 or IPFIX. Match the router setting.',
    'collector' =>
        'Receiver IP or hostname, not the router address. Example: 10.0.2.15. Maximum 253 characters; no URL or port.',
    'port' =>
        'Receiver UDP port: 1–65535. Common: NetFlow 2055, IPFIX 4739. Match the router destination port.',
    'active_timeout' =>
        'Ongoing flow update interval: 1–86400 seconds. Example: 60 = every minute. Set on the router.',
    'inactive_timeout' =>
        'Idle time before exporting a flow: 1–86400 seconds. Example: 15. Set on the router.',
    'sampling_rate' =>
        'Packets per sample: 1–1000000. 1 = every packet; 100 = one in 100. Used when exporter sampling is absent.',
    'lan_cidrs' =>
        'Internal network CIDRs, comma-separated. Example: 192.168.1.0/24. IPv4 prefix: 0–32; IPv6: 0–128. Maximum 1000 characters.',
    'wan_interfaces' =>
        'Optional WAN ifIndex IDs, such as 2,3. Overrides LAN classification. Maximum 512 characters.',
    'bandwidth_limit_mbps' =>
        'High-usage alert threshold: 1–1000000 Mbps, averaged over five minutes. Does not limit speed.',
    'scan_threshold' =>
        'Scan alert threshold: 2–1000000 distinct destination IPs or ports per source within five minutes.',
    'syn_pps_threshold' =>
        'SYN-flood alert threshold: 1–100000000 SYN-without-ACK packets/sec, averaged over five minutes.',
    'exporter_address' =>
        'Device source IP seen by the collector. One IPv4/IPv6 address; no hostname or port. Must uniquely identify this device.',
];
$nfRanges = [
    'port' => [1, 65535],
    'active_timeout' => [1, 86400],
    'inactive_timeout' => [1, 86400],
    'sampling_rate' => [1, 1000000],
    'bandwidth_limit_mbps' => [1, 1000000],
    'scan_threshold' => [2, 1000000],
    'syn_pps_threshold' => [1, 100000000],
]; ?>
<div class="protocol-grid cols-4">
    <?php foreach (
        $nfFields as $name => [$label, $type, $help]
    ): ?>
        <label class="field">
            <span class="field-label"><?= $label ?> <span
                    class="field-info"
                    tabindex="0"
                    role="img"
                    aria-label="Information about <?= icct_nms_h($label) ?>"
                    data-tooltip="<?= icct_nms_h($nfHelp[$name]) ?>">
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
            </span>
            <?php if ($type === 'select'): ?>
                <select name="<?= $name ?>">
                    <?php foreach (
                        $help as $v => $caption
                    ): ?>
                        <option
                            value="<?= $v ?>"
                            <?= (int) $netflow[$name] === $v ? 'selected' : '' ?>><?= $caption ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input
                    name="<?= $name ?>"
                    type="<?= $type ?>"
                    value="<?= icct_nms_h($netflow[$name]) ?>"
                    placeholder="<?= icct_nms_h($help) ?>"
                    <?= isset(['collector' => 253, 'lan_cidrs' => 1000, 'wan_interfaces' => 512][$name])
                        ? 'maxlength="' .
                            ['collector' => 253, 'lan_cidrs' => 1000, 'wan_interfaces' => 512][$name] .
                            '"'
                        : '' ?>
                    <?= in_array($name, ['wan_interfaces', 'lan_cidrs'], true) ? '' : 'required' ?>
                    <?= $type === 'number'
                        ? 'min="' . $nfRanges[$name][0] . '" max="' . $nfRanges[$name][1] . '"'
                        : '' ?>>
            <?php endif; ?>
        </label>
    <?php endforeach; ?>
    <?php if (
        !$presetMode
    ): ?>
        <label class="field">
            <span class="field-label">Exporter Source IP <span
                    class="field-info"
                    tabindex="0"
                    role="img"
                    aria-label="Information about Exporter Source IP"
                    data-tooltip="<?= icct_nms_h($nfHelp['exporter_address']) ?>">
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
            </span>
            <input
                name="exporter_address"
                required
                value="<?= icct_nms_h($netflow['exporter_address'] ?? ($host['hostname'] ?? '')) ?>"
                placeholder="Source IP observed by the collector">
        </label>
    <?php endif; ?>
</div>
<div class="protocol-actions">
    <button
        class="button primary"
        type="submit">Save</button>
</div>
