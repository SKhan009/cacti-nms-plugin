<?php
/** Shared entry point; each protocol keeps its existing validation and save handler. */
?>
<section class="nms-panel nms-form-panel" aria-label="Device connection">
    <div class="nms-panel-head"><h2>Connection type</h2></div>
    <form method="get" action="devices.php" class="nms-node-form" data-nms-no-tooltip>
        <input type="hidden" name="tab" value="add">
        <label>Connection type
            <select name="connection_type">
                <option value="network"<?php if ($add_connection_type === 'network') print ' selected'; ?>>Network / SNMP</option>
                <option value="serial"<?php if ($add_connection_type === 'serial') print ' selected'; ?>>Serial — RS-232 / RS-485</option>
            </select>
        </label>
        <p><button type="submit" class="nms-node-button">Show settings</button></p>
        <p>Choose the connection type before entering device details. Changing it opens a fresh form.</p>
    </form>
</section>
