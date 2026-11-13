<?php /** Historical records for the already-authorized device; never expose credentials. */
require_once __DIR__ . '/../../configuration/services/configuration_history_display.php';
require_once __DIR__ . '/../../protocols/ssh/services/console_service.php';
require __DIR__ . '/device_record_pager.php'; ?>
<section
    id="view-records"
    data-device-view-panel="records"
    hidden>
    <h2>Logs</h2>
    <?php if (!is_realm_allowed(3)): ?>
        <p>Device management permission is required to read configuration records.</p>
    <?php else:
        $prefix = 'configuration_history_' . (int) $id . '_';
        $changeFilter =
            'LEFT(meta_key,?)=? AND CASE WHEN JSON_VALID(meta_value) THEN ' .
            'JSON_LENGTH(JSON_EXTRACT(meta_value,\'$.changes\'))>0 AND ' .
            'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta_value,\'$.action\')),\'\')<>\'Initial baseline\' ELSE ' .
            '0 END';
        $totalChanges = (int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE ' . $changeFilter,
            [strlen($prefix), $prefix],
        );
        $changesPage = min($recordPage('changes_page'), max(1, (int) ceil($totalChanges / 25)));
        $changeRows = db_fetch_assoc_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE ' .
                $changeFilter .
                ' ORDER BY meta_key DESC LIMIT 25 OFFSET ' .
                ($changesPage - 1) * 25,
            [strlen($prefix), $prefix],
        );
        ?>
        <h3>Configuration changes <span
                class="field-info section-help"
                tabindex="0"
                role="img"
                aria-label="Recorded configuration changes, newest first. Passwords and private keys are excluded. Changes before history recording began are unavailable."
                data-tooltip="Recorded configuration changes, newest first. Passwords and private keys are excluded. Changes before history recording began are unavailable.">ⓘ</span>
        </h3>
        <div class="device-record-list">
            <?php
            foreach ($changeRows as $row):
                $event = json_decode($row['meta_value'], true);
                if (!is_array($event)) {
                    continue;
                }
                $changes = $event['changes'] ?? [];
                ?>
                <details
                    class="device-record-item"
                    name="configuration-records">
                    <summary>
                        <span>
                            <small>Time</small><?= $display($event['time'] ?? '') ?>

                        </span>
                        <span>
                            <small>User</small><?= $display($event['user'] ?? '') ?>

                        </span>
                        <span>
                            <small>Action</small><?= $display($event['action'] ?? '') ?>

                        </span>
                        <span>
                            <small>Changes</small><?= count($changes) ?> changed settings
                        </span>
                    </summary>
                    <div class="device-record-body">
                        <h4>Changed settings</h4>
                        <?php if (
                            $changes
                        ): ?>
                            <div class="site-table-wrap">
                                <table class="site-table">
                                    <thead>
                                        <tr>
                                            <th>Setting</th>
                                            <th>Before</th>
                                            <th>After</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (
                                            $changes as $field => $change
                                        ): ?>
                                            <tr>
                                                <td><?= $display(icct_nms_configuration_label($field)) ?></td>
                                                <td class="configuration-history-value"><?= $display(icct_nms_configuration_value_text($change['before'] ?? null)) ?></td>
                                                <td class="configuration-history-value"><?= $display(icct_nms_configuration_value_text($change['after'] ?? null)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p>No settings changed in this snapshot.</p>
                        <?php endif; ?>
                    </div>
                </details>
            <?php
            endforeach;
if (
    !$changeRows
): ?>
                <p class="device-record-empty">No configuration changes recorded for this device.</p>
            <?php endif;
?>
        </div>
        <?php $recordPager('changes_page', $changesPage, $totalChanges); ?>
        <?php
        $sshPrefix = icct_nms_ssh_audit_prefix($id);
        $sshArgs = [$sshPrefix . '%'];
        $sshTotal = (int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE meta_key LIKE ?', $sshArgs,
        );
        $sshPage = min($recordPage('ssh_changes_page'), max(1, (int) ceil($sshTotal / 25)));
        $sshChanges = db_fetch_assoc_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key LIKE ? ' .
            'ORDER BY updated_at DESC,meta_key DESC LIMIT 25 OFFSET ' . (($sshPage - 1) * 25),
            $sshArgs,
        );
        ?>
        <h3>SSH configuration changes</h3>
        <?php if ($sshChanges): ?>
            <p>Settings reported by operators during authenticated SSH sessions.</p>
            <div class="site-table-wrap">
                <table class="site-table">
                    <thead>
                        <tr>
                            <th scope="col">Recorded at</th>
                            <th scope="col">User</th>
                            <th scope="col">Device ID</th>
                            <th scope="col">Source IP</th>
                            <th scope="col">Setting reported</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sshChanges as $row):
                            $change = json_decode($row['meta_value'], true);
                            if (!is_array($change)) continue;
                            ?>
                            <tr>
                                <td><?= $display($change['time'] ?? '') ?></td>
                                <td><?= $display($change['user'] ?: 'User ' . $change['user_id']) ?></td>
                                <td><?= $display($change['host_id'] ?? '') ?></td>
                                <td><?= $display($change['source_ip'] ?? '') ?></td>
                                <td><?= $display($change['parameter'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $recordPager('ssh_changes_page', $sshPage, $sshTotal); ?>
        <?php else: ?>
            <p class="device-record-empty">No SSH configuration changes reported for this device.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
