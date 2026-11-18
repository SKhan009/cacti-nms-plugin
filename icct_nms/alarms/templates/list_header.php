<link rel="stylesheet" href="alarms/css/syslog-console.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../css/syslog-console.css'), 0, 12) ?>">
    <header class="alarm-page-heading">
        <div>
            <p>
                <a href="dashboard/controllers/topology.php">Dashboard</a> / Alarms
            </p>
            <h1>Alarms</h1>
        </div>
        <div class="alarm-page-heading-actions">
            <nav class="alarm-view-switch" aria-label="Event view">
                <a href="alarms/controllers/alarms.php" <?= empty($alarmSyslog) ? 'aria-current="page"' : '' ?>>Alarms (Faults &amp; Traps)</a>
                <a href="alarms/controllers/alarms.php?tab=syslog" <?= !empty($alarmSyslog) ? 'aria-current="page"' : '' ?>>Syslog</a>
            </nav>
            <span class="alarm-heading-divider" aria-hidden="true"></span>
            <?php $exportLabel = !empty($alarmSyslog) ? 'Export current Syslog page (PDF)' : 'Export alarm report (PDF)'; ?>
            <button
                type="button"
                class="icon-button"
                id="alarm-export"
                aria-label="<?= icct_nms_h($exportLabel) ?>"
                title="<?= icct_nms_h($exportLabel) ?>">
                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true">
                    <path d="M12 16V3m-4 4 4-4 4 4M5 10H3v11h18V10h-2" />
                </svg>
            </button>
        </div>
    </header>
