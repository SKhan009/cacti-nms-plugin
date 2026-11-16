<?php
$simulatorUrl = 'protocols/snmp/mibs/controllers/mib_repository.php?tab=simulator';
$simulatorReady = is_dir(ICCT_MIB_SIM_STATE);
$simulatorRunning = icct_mib_simulator_running();
$simulatorDevices = icct_mib_simulator_devices();
?>
<section class="mib-editor" aria-label="MIB Simulator">
    <h2>MIB Simulator</h2>
    <?php if (!$simulatorReady): ?>
        <p>The simulator has not been provisioned on this collector.</p>
    <?php else: ?>
        <p role="status"><?= $simulatorRunning ? 'Running' : 'Stopped' ?></p>
        <?php if ($simulatorDevices): ?>
            <p>The value alternates between 15 and 90 at the Cacti poller interval.</p>
            <?php foreach ($simulatorDevices as $device): ?>
                <p><a href="inventory/controllers/device.php?id=<?= (int) $device['id'] ?>&amp;view=1#view-graphs"><?= icct_nms_h($device['description']) ?></a></p>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Add a Cacti device at <code>127.0.0.1:1161</code> with SNMP v2c community <code>icct-mib-sim</code> and the <code>icctSimulatedLevel</code> graph.</p>
        <?php endif; ?>
        <?php if ($management): ?>
            <form method="post" action="<?= $simulatorUrl ?>">
                <?php icct_nms_token(); ?>
                <?php if ($simulatorRunning): ?>
                    <button class="button" name="action" value="simulator_stop" <?= $simulatorDevices ? '' : 'disabled' ?>>Stop</button>
                <?php else: ?>
                    <button class="button primary" name="action" value="simulator_start" <?= $simulatorDevices ? '' : 'disabled' ?>>Start</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
