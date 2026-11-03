<?php
/** One terminal entry point shared by the device view and its SSH settings. */
if (!empty($id) && empty($presetMode) && icct_backend_protocol_enabled($id, 'ssh') && api_user_realm_auth('protocol.php')):
    try {
        require_once __DIR__ . '/../services/console_service.php';
        icct_nms_ssh_console_device($id);
        $terminalUrl = icct_nms_ssh_terminal_url($id);
        ?>
        <a class="button" href="<?= icct_nms_h($terminalUrl) ?>">Open SSH Terminal (HTTPS)</a>
        <?php
    } catch (Throwable $error) {
        ?>
        <span class="empty-state">SSH terminal unavailable: <?= icct_nms_h($error->getMessage()) ?></span>
        <?php
    }
endif;
