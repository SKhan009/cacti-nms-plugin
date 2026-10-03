<?php
require __DIR__ . '/../../include/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/segment_service.php';
$error = ''; $editing = false; $segmentId = 0; $segmentName = '';
try {
    icct_nms_backend();
    $management = is_realm_allowed(3);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $segmentId = icct_nms_id($_POST['segment_id'] ?? 0);
        $segmentName = is_string($_POST['segment_name'] ?? '') ? $_POST['segment_name'] : '';
        $editing = ($_POST['action'] ?? '') !== 'delete_segment';
        try {
            icct_nms_post();
            if (!in_array($_POST['action'] ?? '', ['save_segment','delete_segment'],true)) throw new InvalidArgumentException('Choose a valid segment action.');
            $_SESSION['icct_nms_notice'] = icct_nms_save_segment($_POST);
            icct_nms_redirect('presets.php#segment');
        } catch (Throwable $failure) { $error = $failure->getMessage(); }
    }
    $segments = db_fetch_assoc('SELECT id,name FROM plugin_icct_nms_categories ORDER BY sort_order,name,id');
} catch (Throwable $failure) { icct_nms_failure($failure); }
$title = 'Presets';
$notice = $_SESSION['icct_nms_notice'] ?? ''; unset($_SESSION['icct_nms_notice']);
require __DIR__.'/templates/header.php';
require __DIR__.'/templates/presets.php';
$presetsPage = true;
require __DIR__.'/templates/footer.php';
