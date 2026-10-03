<?php
/**
 * Shared Inventory shell with the actual authenticated account and assigned collector.
 */

$user = db_fetch_row_prepared('SELECT username,full_name FROM user_auth WHERE id=?', [
    icct_backend_current_user_id()
]);
$collector = db_fetch_cell_prepared('SELECT name FROM poller WHERE id=?', [
    (int) $config['poller_id']
]);
?>

<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <title><?= icct_nms_h($title) ?> · ICCT NMS</title>
        <!-- Content version keeps deployed spacing changes fresh in cached browsers. -->
        <link rel="stylesheet" href="assets/css/inventory.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../assets/css/inventory.css'), 0, 12) ?>" />
        <?php if (!empty($mapPage)): ?>
        <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
        <link rel="stylesheet" href="assets/css/map.css?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/css/map.css'),0,12) ?>">
        <?php endif; ?>
    </head>
    <body class="icct-inventory">
        <header class="topbar">
            <div class="header-menu">
                <button class="icon-button" type="button" aria-label="Navigation menu" aria-expanded="false">☰</button>
                <nav class="header-menu-panel" aria-label="Main navigation">
                    <a href="topology.php">Dashboard</a>
                    <a href="inventory.php">Inventory</a>
                    <a href="node_configuration.php">Node Configuration</a>
                    <a href="presets.php#segment">Presets</a>
                </nav>
            </div>
            <div class="brand">
                <img class="brand-logo brand-logo-drdo" src="assets/images/drdo-logo.svg" alt="DRDO" width="34" height="34">
                <img class="brand-logo brand-logo-bel" src="assets/images/bharat-electronics-logo.svg" alt="Bharat Electronics" width="128" height="34">
            </div>
            <div class="station">
                <strong>ICCT NMS</strong>
                <span><?= icct_nms_h($collector) ?></span>
            </div>
            <div class="system-metrics"><span>Poller: <?= icct_nms_h(read_config_option('poller_interval')) ?> sec</span></div>
            <a class="profile" href="<?= icct_nms_h($config['url_path']) ?>auth_profile.php">
                <span class="user-avatar" aria-hidden="true">◉</span>
                <span>
                    <strong><?= icct_nms_h($user['username']) ?></strong>
                    <small><?= icct_nms_h($user['full_name']) ?></small>
                </span>
            </a>
        </header>
        <main class="workspace">
            <section class="device-panel">
                <?php if (!empty($error)): ?>
                <p class="feedback error" role="alert"><?= icct_nms_h($error) ?></p>
                <?php endif; ?>
                <?php if (!empty($notice)): ?>
                <p class="feedback" role="status"><?= icct_nms_h($notice) ?></p>
                <?php endif; ?>
