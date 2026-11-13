<!-- Only measured terminal output reaches the display; credentials remain in the broker's private store. -->
<link
    rel="stylesheet"
    href="protocols/ssh/css/terminal.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../css/terminal.css'), 0, 12) ?>">
<section
    id="ssh-terminal"
    data-host-id="<?= (int) $id ?>"
    data-api-url="protocols/ssh/controllers/ssh_console.php">
    <div class="titlebar">
        <div class="ssh-terminal-heading">
            <h1>SSH Terminal · <?= icct_nms_h($device['description']) ?></h1>
            <span
                class="field-info"
                tabindex="0"
                role="img"
                aria-label="SSH terminal help"
                data-tooltip="Click the terminal to type. Sessions use the saved credential and approved host key. Closing this terminal ends its authorization.">ⓘ</span>
        </div>
        <a
            class="button"
            href="inventory/controllers/device.php?id=<?= (int) $id ?>&amp;view=1">Back to device</a>
    </div>
    <div class="ssh-terminal-content">
        <div class="ssh-terminal-toolbar">
            <div class="ssh-terminal-details">
                <p><?= icct_nms_h($device['endpoint']) ?> · <?= icct_nms_h($device['username']) ?> · <?= icct_nms_h($device['auth_method']) ?> authentication</p>
                <p>Host key: <?= icct_nms_h(icct_backend_ssh_fingerprint($device['host_key'])) ?> · Monitoring <?= !empty($device['monitoring']) ? 'on' : 'off' ?></p>
                <p
                    id="ssh-terminal-status"
                    role="status"
                    aria-live="polite">Disconnected</p>
            </div>
            <div class="ssh-terminal-actions">
                <button
                    type="button"
                    class="button"
                    id="ssh-test">Test saved credential</button>
                <button
                    type="button"
                    class="button primary"
                    id="ssh-connect">Connect</button>
                <button
                    type="button"
                    class="button"
                    id="ssh-disconnect"
                    disabled>Disconnect</button>
            </div>
        </div>
        <details
            id="ssh-change-audit"
            hidden>
            <summary>Record a configuration change</summary>
            <form id="ssh-change-form">
                <label class="field">
                    <span>Setting changed</span>
                    <input
                        name="parameter"
                        required
                        maxlength="120"
                        placeholder="Example: GigabitEthernet0/1 description">
                </label>
                <p>Enter the setting name only. Keep values and credentials out of the log.</p>
                <button
                    type="submit"
                    class="button">Record change</button>
                <p
                    id="ssh-change-status"
                    role="status"></p>
            </form>
        </details>
        <div
            id="ssh-terminal-display"
            class="ssh-terminal-display"
            tabindex="0"
            role="application"
            aria-label="SSH terminal keyboard and display"></div>
    </div>
    <form
        id="ssh-terminal-token"
        hidden>
        <?php icct_nms_token(); ?>
    </form>
</section>
<script src="protocols/ssh/assets/guacamole-1.6.0.js"></script>
<script
    src="protocols/ssh/js/terminal.js?v=<?= substr(hash_file('sha256', __DIR__ . '/../js/terminal.js'), 0, 12) ?>"
    defer></script>
