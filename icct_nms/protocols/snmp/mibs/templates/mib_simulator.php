<?php
$simulatorUrl = 'protocols/snmp/mibs/controllers/mib_repository.php?tab=simulator';
$simulatorReady = is_dir(ICCT_MIB_SIM_STATE);
$simulatorState = icct_mib_simulator_state();
$simulatorDevices = icct_mib_simulator_devices();
$simulatorDraft = $management ? ($_SESSION['icct_mib_simulator_draft'] ?? []) : [];
?>
<section class="mib-editor" aria-label="MIB Simulator">
    <h2>MIB Simulator</h2>
    <?php if ($error !== ''): ?>
        <p class="site-error" role="alert"><?= icct_nms_h($error) ?></p>
    <?php endif; ?>
    <?php if (!$simulatorReady): ?>
        <p>The simulator has not been provisioned on this collector.</p>
    <?php else: ?>
        <p role="status"><?= $simulatorState ? 'Running' : 'Stopped' ?></p>
        <p><strong>Simulated data</strong> · All readings from this endpoint are generated for testing.</p>
        <?php if ($simulatorDevices): ?>
            <?php foreach ($simulatorDevices as $device): ?>
                <p><a href="inventory/controllers/device.php?id=<?= (int) $device['id'] ?>&amp;view=1#view-graphs"><?= icct_nms_h($device['description']) ?> graphs</a></p>
            <?php endforeach; ?>
        <?php else: ?>
            <p>To graph these readings, add a local Cacti device at <code>127.0.0.1:1161</code> with SNMP v2c community <code>icct-mib-sim</code>.</p>
        <?php endif; ?>
        <?php if ($simulatorState): ?>
            <p><?= icct_nms_h(implode(', ', $simulatorState['files'])) ?> · <?= count($simulatorState['objects']) ?> selected OIDs · <?= (int) $simulatorState['interval'] ?>-second poll interval</p>
            <?php if ($management): ?>
                <form method="post" action="<?= $simulatorUrl ?>">
                    <?php icct_nms_token(); ?>
                    <button class="button" name="action" value="simulator_stop">Stop</button>
                </form>
            <?php endif; ?>
        <?php elseif ($management): ?>
            <form method="post" enctype="multipart/form-data" action="<?= $simulatorUrl ?>">
                <?php icct_nms_token(); ?>
                <label class="field">
                    <span class="field-label">MIB file</span>
                    <input name="simulator_mib[]" type="file" accept=".mib" required>
                </label>
                <button class="button" name="action" value="simulator_upload">Load MIB</button>
            </form>
            <?php if ($simulatorDraft): ?>
                <form method="post" action="<?= $simulatorUrl ?>">
                    <?php icct_nms_token(); ?>
                    <input type="hidden" name="mib_token" value="<?= icct_nms_h($simulatorDraft['token']) ?>">
                    <h3>Select OIDs from <?= icct_nms_h(implode(', ', $simulatorDraft['files'])) ?></h3>
                    <div class="site-table-wrap">
                        <table class="site-table">
                            <thead>
                                <tr><th scope="col">Object</th><th scope="col">OID</th><th scope="col">Syntax</th><th scope="col">Simulated reading</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($simulatorDraft['objects'] as $symbol => $object): ?>
                                    <tr>
                                        <td><label><input type="checkbox" name="objects[]" value="<?= icct_nms_h($symbol) ?>"> <?= icct_nms_h($symbol) ?></label></td>
                                        <td><code><?= icct_nms_h($object['oid']) ?></code>
                                            <?php if ($object['table']): ?>
                                                <label>Instance suffix
                                                    <input type="text" name="instance[<?= icct_nms_h($symbol) ?>]" value="1" maxlength="352"
                                                        aria-label="Instance suffix for <?= icct_nms_h($symbol) ?>">
                                                </label>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= icct_nms_h($object['syntax']) ?></td>
                                        <td><?= isset($object['bounds']) ? 'Varies within ' . implode(' to ', $object['bounds']) :
                                            (isset($object['value']) ? 'Fixed' : 'Increases') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="button primary" name="action" value="simulator_start">Start</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
