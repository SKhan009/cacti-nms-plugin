<?php
/** Authenticated Syslog event console. */
require dirname(__DIR__, 3) . '/../../include/auth.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 3) . '/inventory/services/inventory.php';

function icct_nms_is_windows_runtime()
{
    return (defined('PHP_OS_FAMILY') && strcasecmp(PHP_OS_FAMILY, 'Windows') === 0)
        || DIRECTORY_SEPARATOR === '\\'
        || stripos(PHP_OS, 'WIN') === 0;
}

function icct_nms_syslog_drop_reason_label($reason)
{
    $labels = [
        'invalid-source-ip' => 'invalid source IP',
        'unmapped-source' => 'source not mapped to an enabled Cacti Syslog device',
        'severity-filtered' => 'filtered by selected severities',
        'facility-filtered' => 'filtered by selected facilities',
        'keyword-filtered' => 'no configured keyword matched',
        'transport-filtered' => 'filtered by device transport setting',
        'empty-message' => 'empty message',
        'policy-drop' => 'policy drop',
    ];
    return $labels[(string) $reason] ?? (string) $reason;
}

$error = '';
$notice = '';
try {
    icct_nms_backend();
    $management = is_realm_allowed(3);
    $windowsTestMode = icct_nms_is_windows_runtime();
    $windowsSpool = dirname(__DIR__, 3) . '/runtime/syslog/remote.ndjson';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        icct_nms_post();
        $action = $_POST['action'] ?? '';
        if ($action === 'import_windows_spool') {
            if (!$windowsTestMode) {
                throw new RuntimeException('The web spool importer is only available for Windows development/testing. RHEL production uses the systemd Syslog worker.');
            }
            $summary = icct_backend_syslog_import_spool_once($windowsSpool, 5000);
            $message = sprintf(
                'Windows Syslog spool imported: %d processed, %d inserted, %d repeated, %d dropped, %d errors.',
                $summary['processed'],
                $summary['inserted'],
                $summary['repeated'],
                $summary['dropped'],
                $summary['errors']
            );
            if (!empty($summary['drop_reasons'])) {
                $parts = [];
                foreach ($summary['drop_reasons'] as $reason => $count) {
                    $parts[] = (int) $count . ' ' . icct_nms_syslog_drop_reason_label($reason);
                }
                $message .= ' Dropped: ' . implode(', ', $parts) . '.';
            }
            if (!empty($summary['error_messages'])) {
                $message .= ' First error: ' . $summary['error_messages'][0];
            }
            $_SESSION['icct_nms_notice'] = $message;
        } elseif ($action === 'reset_windows_spool') {
            if (!$windowsTestMode) {
                throw new RuntimeException('The Windows test-spool cursor can only be reset in Windows development mode.');
            }
            icct_backend_syslog_worker_state_reset($windowsSpool);
            $_SESSION['icct_nms_notice'] = 'Windows Syslog test-spool read position reset. Existing database events were preserved. Click Import Windows Test Spool to read the file again from the beginning.';
        } elseif ($action === 'ack' || $action === 'unack') {
            $eventId = filter_var($_POST['event_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($eventId === false) throw new InvalidArgumentException('Invalid Syslog event ID.');
            if ($action === 'ack') {
                icct_backend_syslog_event_ack($eventId, true, $_POST['comment'] ?? '');
                $_SESSION['icct_nms_notice'] = 'Syslog event acknowledged.';
            } else {
                icct_backend_syslog_event_ack($eventId, false, '');
                $_SESSION['icct_nms_notice'] = 'Syslog acknowledgement cleared.';
            }
        } else {
            throw new InvalidArgumentException('Unsupported Syslog action.');
        }
        icct_nms_redirect('protocols/syslog/controllers/syslog.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
    }

    $devices = icct_nms_inventory();
    $allowedIds = array_map(static fn($row) => (int) $row['id'], $devices);
    $deviceMap = [];
    foreach ($devices as $row) {
        $deviceMap[(int) $row['id']] = $row['description'] . ' (' . $row['hostname'] . ')';
    }

    $hostId = isset($_GET['host_id']) ? (int) $_GET['host_id'] : 0;
    if ($hostId && !in_array($hostId, $allowedIds, true)) $hostId = 0;
    $severity = strtolower(trim((string) ($_GET['severity'] ?? '')));
    if (!in_array($severity, ['', 'critical', 'major', 'warning', 'info', 'debug'], true)) $severity = '';
    $ack = (string) ($_GET['ack'] ?? '');
    if (!in_array($ack, ['', 'yes', 'no'], true)) $ack = '';
    $search = trim((string) ($_GET['search'] ?? ''));
    if (strlen($search) > 200) $search = substr($search, 0, 200);
    $window = (string) ($_GET['window'] ?? '24h');
    $windowChoices = ['1h' => '-1 hour', '24h' => '-24 hours', '7d' => '-7 days', '30d' => '-30 days', 'all' => ''];
    if (!array_key_exists($window, $windowChoices)) $window = '24h';
    $since = $windowChoices[$window] === '' ? '' : date('Y-m-d H:i:s', strtotime($windowChoices[$window]));

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $rows = (int) ($_GET['rows'] ?? 50);
    if (!in_array($rows, [25, 50, 100, 200], true)) $rows = 50;
    $total = 0;
    $filters = [
        'host_id' => $hostId,
        'severity' => $severity,
        'ack' => $ack,
        'search' => $search,
        'since' => $since,
    ];
    $events = icct_backend_syslog_events($allowedIds, $filters, $rows, ($page - 1) * $rows, $total);
    $pages = max(1, (int) ceil($total / $rows));
    if ($page > $pages) {
        $page = $pages;
        $events = icct_backend_syslog_events($allowedIds, $filters, $rows, ($page - 1) * $rows, $total);
    }

    $runtime = icct_backend_syslog_runtime_status();
    $windowsSpoolExists = $windowsTestMode && is_file($windowsSpool);
    $windowsSpoolReadable = $windowsSpoolExists && is_readable($windowsSpool);
    $windowsSpoolSize = $windowsSpoolExists ? (int) @filesize($windowsSpool) : 0;
    $windowsSpoolMtime = $windowsSpoolExists ? @filemtime($windowsSpool) : false;
    $windowsState = [];
    $windowsTestAssignment = [];
    $windowsBindings = [];
    if ($windowsTestMode) {
        [, $windowsState] = icct_backend_syslog_worker_state($windowsSpool);
        $windowsTestAssignment = icct_backend_syslog_match_device('127.0.0.1', '127.0.0.1');
        if ($windowsTestAssignment && !in_array((int) $windowsTestAssignment['host_id'], $allowedIds, true)) {
            $windowsTestAssignment = [];
        }
        foreach ((array) icct_backend_syslog_bindings() as $binding) {
            if (in_array((int) $binding['host_id'], $allowedIds, true)) $windowsBindings[] = $binding;
        }
    }

    $notice = $_SESSION['icct_nms_notice'] ?? '';
    unset($_SESSION['icct_nms_notice']);
    $title = 'Syslog Console';
    require dirname(__DIR__, 3) . '/shared/templates/header.php';
} catch (Throwable $e) {
    icct_nms_failure($e);
}

function icct_nms_syslog_query(array $replace = [])
{
    $current = $_GET;
    unset($current['page']);
    foreach ($replace as $key => $value) {
        if ($value === null || $value === '') unset($current[$key]);
        else $current[$key] = $value;
    }
    return http_build_query($current);
}
?>
<div class="inventory-heading syslog-heading">
    <div>
        <p class="breadcrumb"><a href="inventory/controllers/inventory.php">Inventory</a> / Syslog Console</p>
        <h1>Syslog Console</h1>
        <p class="page-description">Passive device events received by the LNMS Syslog service and mapped to configured Cacti devices.</p>
    </div>
    <?php if ($windowsTestMode): ?>
    <div class="syslog-runtime <?= $windowsSpoolReadable ? 'online' : 'offline' ?>">
        <span class="status-dot"></span>
        <span>
            <strong>Windows Test Mode</strong>
            <small><?= $windowsSpoolReadable ? 'Test spool ready' : 'Test spool unavailable' ?></small>
        </span>
    </div>
    <?php else: ?>
    <div class="syslog-runtime <?= $runtime['online'] ? 'online' : 'offline' ?>">
        <span class="status-dot"></span>
        <span>
            <strong>Ingest Worker <?= $runtime['online'] ? 'Online' : 'Offline' ?></strong>
            <small><?= $runtime['updated_at'] ? 'Heartbeat ' . icct_nms_h($runtime['updated_at']) : 'No heartbeat recorded' ?></small>
            <?php if (!empty($runtime['payload'])): ?>
            <small>Ingested <?= number_format((int) ($runtime['payload']['ingested'] ?? 0)) ?> · Dropped <?= number_format((int) ($runtime['payload']['dropped'] ?? 0)) ?> · Errors <?= number_format((int) ($runtime['payload']['errors'] ?? 0)) ?></small>
            <?php endif; ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<?php if ($windowsTestMode): ?>
<section class="syslog-dev-panel" aria-labelledby="windows-syslog-test-title">
    <div class="syslog-dev-title">
        <div>
            <h2 id="windows-syslog-test-title">Windows Syslog Development Test</h2>
            <p>The PowerShell UDP listener writes NDJSON locally. Import it here through Apache/PHP. Production RHEL continues to use rsyslog and the systemd ingestion worker.</p>
        </div>
        <span class="syslog-dev-badge">DEV ONLY</span>
    </div>
    <div class="syslog-dev-grid">
        <div><span>Listener target</span><strong>127.0.0.1:5514 / UDP</strong></div>
        <div><span>Spool</span><strong><?= $windowsSpoolReadable ? number_format($windowsSpoolSize) . ' bytes' : 'Unavailable' ?></strong></div>
        <div><span>Last spool write</span><strong><?= $windowsSpoolMtime ? icct_nms_h(date('Y-m-d H:i:s', $windowsSpoolMtime)) : '—' ?></strong></div>
        <div><span>Read offset</span><strong><?= number_format((int) ($windowsState['source_offset'] ?? 0)) ?> bytes</strong></div>
    </div>

    <?php if ($windowsTestAssignment): ?>
    <p class="syslog-dev-ok"><strong>127.0.0.1 is mapped:</strong> <?= icct_nms_h($windowsTestAssignment['description']) ?> (<?= icct_nms_h($windowsTestAssignment['hostname']) ?>), <?= icct_nms_h(strtoupper($windowsTestAssignment['transport'])) ?>, severity 0–<?= (int) $windowsTestAssignment['max_severity'] ?>.</p>
    <?php else: ?>
    <p class="syslog-dev-warning"><strong>No enabled Syslog device is mapped to 127.0.0.1.</strong> Before importing, open the intended Cacti device → Protocol Config → Syslog and set Expected Source Address to <code>127.0.0.1</code>, Accepted Transport to <code>UDP + TCP</code> (or UDP), and Store Severity Up To to <code>Informational</code> or Debug for testing. Unmapped messages are intentionally dropped.</p>
    <?php endif; ?>

    <?php if ($windowsBindings): ?>
    <details class="syslog-binding-details">
        <summary>Configured Syslog device bindings (<?= count($windowsBindings) ?>)</summary>
        <div class="table-scroll">
            <table class="syslog-binding-table">
                <thead><tr><th>Device</th><th>Expected source</th><th>Transport</th><th>Max severity</th><th>Enabled</th></tr></thead>
                <tbody>
                <?php foreach ($windowsBindings as $binding): ?>
                    <tr>
                        <td><a href="inventory/controllers/device.php?id=<?= (int) $binding['host_id'] ?>&view=1"><?= icct_nms_h($binding['description']) ?></a></td>
                        <td><?= icct_nms_h($binding['source_address'] ?: $binding['hostname']) ?></td>
                        <td><?= icct_nms_h(strtoupper($binding['transport'])) ?></td>
                        <td><?= (int) $binding['max_severity'] ?></td>
                        <td><?= (int) $binding['enabled'] === 1 ? 'Yes' : 'No' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($management): ?>
    <div class="syslog-dev-actions">
        <form method="post" class="inline-form">
            <?php icct_nms_token(); ?>
            <input type="hidden" name="action" value="import_windows_spool" />
            <button class="button primary" type="submit" <?= $windowsSpoolReadable ? '' : 'disabled' ?>>Import Windows Test Spool</button>
        </form>
        <form method="post" class="inline-form" data-confirm="Reset the Windows test-spool read position? Existing Syslog database events will not be deleted.">
            <?php icct_nms_token(); ?>
            <input type="hidden" name="action" value="reset_windows_spool" />
            <button class="button secondary" type="submit" <?= $windowsSpoolReadable ? '' : 'disabled' ?>>Re-import From Start</button>
        </form>
        <small class="syslog-dev-path"><?= icct_nms_h($windowsSpool) ?></small>
    </div>
    <?php else: ?>
    <p class="syslog-dev-warning">Management permission is required to import the Windows test spool.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<form method="get" class="inventory-filters syslog-filters">
    <label class="field">
        <span class="field-label">Device</span>
        <select name="host_id">
            <option value="0">All permitted devices</option>
            <?php foreach ($deviceMap as $id => $label): ?>
            <option value="<?= (int) $id ?>" <?= $hostId === (int) $id ? 'selected' : '' ?>><?= icct_nms_h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span class="field-label">Severity</span>
        <select name="severity">
            <?php foreach (['' => 'All', 'critical' => 'Critical', 'major' => 'Major', 'warning' => 'Warning', 'info' => 'Info', 'debug' => 'Debug'] as $key => $label): ?>
            <option value="<?= $key ?>" <?= $severity === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span class="field-label">Acknowledgement</span>
        <select name="ack">
            <option value="" <?= $ack === '' ? 'selected' : '' ?>>All</option>
            <option value="no" <?= $ack === 'no' ? 'selected' : '' ?>>Unacknowledged</option>
            <option value="yes" <?= $ack === 'yes' ? 'selected' : '' ?>>Acknowledged</option>
        </select>
    </label>
    <label class="field">
        <span class="field-label">Time</span>
        <select name="window">
            <?php foreach (['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All retained'] as $key => $label): ?>
            <option value="<?= $key ?>" <?= $window === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field inventory-search">
        <span class="sr-only">Search Syslog</span>
        <input type="search" name="search" value="<?= icct_nms_h($search) ?>" placeholder="Message / source / program / device" />
    </label>
    <button class="button" type="submit">Apply</button>
    <a class="button secondary" href="protocols/syslog/controllers/syslog.php">Clear</a>
</form>

<div class="table-scroll syslog-table-scroll" tabindex="0" role="region" aria-label="Syslog events">
<table class="action-table" id="syslog-table">
    <thead>
        <tr>
            <th>Event Time</th>
            <th>Severity</th>
            <th>Device</th>
            <th>Source</th>
            <th>Facility / Program</th>
            <th>Message</th>
            <th>Count</th>
            <th>Ack</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$events): ?>
        <tr><td colspan="8" class="empty-state">No Syslog events match the current filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($events as $event): ?>
        <tr>
            <td><?= icct_nms_h($event['event_time']) ?><small>Last <?= icct_nms_h($event['last_seen']) ?></small></td>
            <td><span class="syslog-severity severity-<?= icct_nms_h($event['nms_severity']) ?>"><?= icct_nms_h(ucfirst($event['nms_severity'])) ?></span><small><?= icct_nms_h($event['severity']) ?> (<?= (int) $event['severity_code'] ?>)</small></td>
            <td><a href="inventory/controllers/device.php?id=<?= (int) $event['host_id'] ?>&view=1"><?= icct_nms_h($event['description']) ?></a><small><?= icct_nms_h($event['hostname']) ?></small></td>
            <td><?= icct_nms_h($event['source_ip']) ?><small><?= icct_nms_h($event['source_host']) ?> · <?= icct_nms_h(strtoupper($event['transport'])) ?></small></td>
            <td><?= icct_nms_h($event['facility']) ?><small><?= icct_nms_h($event['program']) ?></small></td>
            <td class="syslog-message"><details><summary><?= icct_nms_h(mb_strimwidth($event['message'], 0, 120, '…')) ?></summary><pre><?= icct_nms_h($event['message']) ?></pre></details></td>
            <td><?= (int) $event['repeat_count'] ?></td>
            <td>
                <?php if ((int) $event['acknowledged']): ?>
                    <span class="ack-badge">Acknowledged</span>
                    <small><?= icct_nms_h($event['ack_username'] ?: ('User #' . (int) $event['acknowledged_by'])) ?><?= !empty($event['acknowledged_at']) ? ' · ' . icct_nms_h($event['acknowledged_at']) : '' ?></small>
                    <?php if (!empty($event['ack_comment'])): ?><small class="ack-comment"><?= icct_nms_h($event['ack_comment']) ?></small><?php endif; ?>
                    <?php if ($management): ?>
                    <form method="post" class="inline-form">
                        <?php icct_nms_token(); ?>
                        <input type="hidden" name="action" value="unack" />
                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>" />
                        <button class="link-button" type="submit">Clear</button>
                    </form>
                    <?php endif; ?>
                <?php elseif ($management): ?>
                    <details class="ack-form"><summary>Acknowledge</summary>
                        <form method="post">
                            <?php icct_nms_token(); ?>
                            <input type="hidden" name="action" value="ack" />
                            <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>" />
                            <input type="text" name="comment" maxlength="255" placeholder="Optional comment" />
                            <button class="button primary" type="submit">Ack</button>
                        </form>
                    </details>
                <?php else: ?>
                    <span>Unacknowledged</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="inventory-pagination">
    <label>Items per page
        <select onchange="location.href=new URL('protocols/syslog/controllers/syslog.php?<?= icct_nms_h(icct_nms_syslog_query(['rows' => ''])) ?>&rows='+this.value,document.baseURI).href">
            <?php foreach ([25,50,100,200] as $choice): ?><option value="<?= $choice ?>" <?= $rows === $choice ? 'selected' : '' ?>><?= $choice ?></option><?php endforeach; ?>
        </select>
    </label>
    <span><?= $total ? (($page - 1) * $rows + 1) . '–' . min($page * $rows, $total) . ' of ' . $total . ' events' : '0 events' ?></span>
    <div class="page-controls">
        <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : 'protocols/syslog/controllers/syslog.php?' . icct_nms_h(icct_nms_syslog_query(['page' => $page - 1])) ?>">‹</a>
        <span><?= $page ?> of <?= $pages ?></span>
        <a class="page-link <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= $page >= $pages ? '#' : 'protocols/syslog/controllers/syslog.php?' . icct_nms_h(icct_nms_syslog_query(['page' => $page + 1])) ?>">›</a>
    </div>
</div>
<?php require dirname(__DIR__, 3) . '/shared/templates/footer.php'; ?>
