<?php
$reading = icct_nms_diagnostic_reading($run['tool'], (string) ($result['output'] ?? ''));
$udp = $run['tool'] === 'iperf3_udp';
$receiver = $reading['totals']['receiver'] ?? null;
$sender = $reading['totals']['sender'] ?? null;
$status = $labels[$run['tool']] ?? $run['tool'];
$ip = $reading['destination_ip'] ?? '';
$ipVersion = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6'
    : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'IPv4' : '');
$runDuration = '';
if ($run['tool'] === 'pathchar' && !empty($run['started_at']) && !empty($run['finished_at'])) {
    $start = strtotime($run['started_at']);
    $finish = strtotime($run['finished_at']);
    if ($start !== false && $finish !== false && $finish >= $start) {
        $runDuration = ($finish - $start) . ' s';
    }
}
$protocol = isset($reading['intervals']) ? ($udp ? 'UDP' : 'TCP') : '';
if ($protocol !== '' && $ipVersion !== '') {
    $protocol .= ' / ' . $ipVersion;
}
?>
<div class="diagnostic-summary-heading">
    <strong><?= $display($status . ' · ' . ucfirst($run['status'])) ?></strong>
    <?php if (!empty($result['self_test'])): ?>
        <p>This test ran within the collector. Use a remote device to measure speed across the network.</p>
    <?php elseif ($receiver && $run['status'] === 'complete'): ?>
        <p>The receiving device got data at <?= $display($receiver['speed']) ?> during this test.</p>
    <?php elseif (!empty($reading['hops'])): ?>
        <p>Each row shows a step along the route. Response times are in milliseconds. Loss shows how many probes went unanswered at that step.</p>
    <?php elseif (empty($reading['metrics']) && empty($reading['table_rows'])): ?>
        <p>Open Raw data for the full diagnostic output.</p>
    <?php endif; ?>
</div>
<?php $metrics = $reading['metrics'] ?? [];
if ($receiver && $run['status'] === 'complete') {
    $metrics = [
        ['Received speed', $receiver['speed'], 'How fast data arrived'],
        ['Data received', $receiver['transfer'], 'How much data arrived'],
        ['Measured time', $receiver['seconds'] . ' s', 'Time measured by the receiver'],
    ];
    if ($udp) {
        $metrics[] = ['Jitter', $receiver['jitter'] ?? '', 'How much packet timing varied'];
        $metrics[] = ['Packet loss', $receiver['loss'] ?? '', 'Packets that did not arrive'];
    } else {
        $metrics[] = ['Retransmissions', $sender['retries'] ?? '', 'TCP segments sent again'];
    }
}
if ($metrics): ?>
    <dl class="diagnostic-summary-grid diagnostic-summary-metrics">
        <?php foreach ($metrics as [$label, $value, $help]): ?>
            <div><dt><?= $display($label) ?></dt><dd><?= $display($value) ?></dd><small><?= $display($help) ?></small></div>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>
<section class="diagnostic-summary-section">
    <h5>Test details</h5>
    <dl class="diagnostic-summary-grid">
        <?php foreach ([
            'Started' => $run['started_at'],
            'Finished' => $run['finished_at'],
            'Collector' => $result['execution_host'] ?? '',
            'Target' => $reading['endpoint'] ?? $result['target'] ?? '',
            'Source IP' => $reading['source_ip'] ?? '',
            'Destination IP' => $ip,
            'Protocol' => $protocol,
            'Port' => $reading['port'] ?? '',
            'Target rate' => $reading['target_rate'] ?? '',
            'Run duration' => $runDuration,
        ] + ($reading['details'] ?? []) as $label => $value):
            if ($value === '' || $value === null) continue; ?>
            <div><dt><?= $display($label) ?></dt><dd><?= $display($value) ?></dd></div>
        <?php endforeach; ?>
    </dl>
</section>
<?php if (!empty($reading['intervals'])):
    $peak = max(1e-9, max(array_column($reading['intervals'], 'mbps'))); ?>
    <section class="diagnostic-summary-section">
        <h5>Speed over time</h5>
        <div class="diagnostic-summary-table">
            <table>
                <thead><tr><th>Elapsed time</th><th>Data sent</th><th>Sending speed</th>
                    <?php if ($udp): ?><th>Datagrams sent</th>
                    <?php else: ?><th>Sent again</th><th>TCP congestion window</th><?php endif; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($reading['intervals'] as $row): ?>
                        <tr>
                            <td><?= $display($row['interval']) ?></td>
                            <td><?= $display($row['transfer']) ?></td>
                            <td><?= $display($row['speed']) ?>
                                <meter aria-label="Sending speed relative to the peak interval" min="0" max="<?= icct_nms_h((string) $peak) ?>" value="<?= icct_nms_h((string) $row['mbps']) ?>"></meter>
                            </td>
                            <?php if ($udp): ?><td><?= $display($row['datagrams'] ?? '') ?></td>
                            <?php else: ?><td><?= $display($row['retries'] ?? '') ?></td><td><?= $display($row['window'] ?? '') ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php if (!empty($reading['totals'])): ?>
    <section class="diagnostic-summary-section">
        <h5>Sender and receiver totals</h5>
        <div class="diagnostic-summary-table">
            <table>
                <thead><tr><th>Endpoint role</th><th>Elapsed time</th><th>Data transferred</th><th>Average speed</th>
                    <?php if ($udp): ?><th>Jitter</th><th>Packet loss</th>
                    <?php else: ?><th>Retransmissions</th><?php endif; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($reading['totals'] as $role => $row): ?>
                        <tr>
                            <th scope="row"><?= $display(ucfirst($role)) ?></th>
                            <td><?= $display($row['interval']) ?></td>
                            <td><?= $display($row['transfer']) ?></td>
                            <td><?= $display($row['speed']) ?></td>
                            <?php if ($udp): ?><td><?= $display($row['jitter'] ?? '') ?></td><td><?= $display($row['loss'] ?? '') ?></td>
                            <?php else: ?><td><?= $display($row['retries'] ?? '') ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php if (!empty($reading['hops'])): ?>
    <section class="diagnostic-summary-section">
        <h5>Route measurements</h5>
        <div class="diagnostic-summary-table">
            <table>
                <thead><tr><th>Hop</th><th>Address</th><th>Loss</th><th>Probes sent</th><th>Latest (ms)</th>
                    <th>Average (ms)</th><th>Fastest (ms)</th><th>Slowest (ms)</th><th>Variation (ms)</th></tr></thead>
                <tbody>
                    <?php foreach ($reading['hops'] as $hop): ?>
                        <tr><?php foreach ($hop as $value): ?><td><?= $display($value) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php
$tables = $reading['tables'] ?? [];
if (!empty($reading['table_rows'])) {
    $tables[] = [$reading['table_title'], $reading['table_headers'], $reading['table_rows']];
}
foreach ($tables as [$tableTitle, $tableHeaders, $tableRows]):
    if (!$tableRows) continue;
?>
    <section class="diagnostic-summary-section">
        <h5><?= $display($tableTitle) ?></h5>
        <div class="diagnostic-summary-table">
            <table>
                <thead><tr><?php foreach ($tableHeaders as $heading): ?>
                    <th scope="col"><?= $display($heading) ?></th>
                <?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($tableRows as $row): ?>
                    <tr><?php foreach ($row as $value): ?><td><?= $display($value) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
