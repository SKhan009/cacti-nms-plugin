<?php

require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 2) . '/graphs/services/graph_service.php';
try {
    icct_nms_backend();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        icct_nms_post();
        icct_nms_alarm_action($_POST);
        $message = [
            'ack' => 'Alarm acknowledged successfully.',
            'escalate' => 'Ticket opened successfully.',
            'ticket_progress' => 'Ticket moved to in progress successfully.',
            'ticket_resolve' => 'Ticket resolved successfully.',
            'ticket_reopen' => 'Ticket reopened successfully.',
            'ticket_close' => 'Ticket closed successfully.',
        ][$_POST['alarm_action'] ?? ''] ?? 'Alarm updated successfully.';
        if (in_array($_POST['alarm_action'] ?? '', ['ack', 'escalate', 'ticket_progress', 'ticket_resolve', 'ticket_reopen', 'ticket_close'], true)) {
            $_SESSION['icct_nms_alarm_action_success'] = $message;
        }
        echo json_encode(['ok' => true, 'message' => $message]);
        exit();
    }
    if (isset($_GET['history'])) {
        $key = $_GET['history'];
        $row =
            is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key)
                ? db_fetch_row_prepared(
                    'SELECT host_id,graph_id FROM plugin_icct_nms_alarms WHERE alarm_key=?',
                    [$key],
                )
                : [];
        if (!$row) {
            throw new RuntimeException('Alarm unavailable.');
        }
        icct_nms_alarm_require_access($row);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(
            db_fetch_assoc_prepared(
                'SELECT event,actor_id,note,created_at,UNIX_TIMESTAMP(created_at) AS created_epoch FROM ' .
                    'plugin_icct_nms_alarm_history WHERE alarm_key=? ORDER BY id DESC',
                [$key],
            ),
            JSON_THROW_ON_ERROR,
        );
        exit();
    }
    if (isset($_GET['ticket'], $_GET['ticket_action'])) {
        icct_backend_require_management(3);
        $ticketAction = $_GET['ticket_action'];
        if (!is_string($ticketAction) || !in_array($ticketAction, ['ticket_progress', 'ticket_resolve', 'ticket_reopen', 'ticket_close'], true)) {
            throw new InvalidArgumentException('Choose a valid ticket action.');
        }
        $ticketDetail = icct_nms_alarm_detail($_GET['ticket']);
        $alarmTicketPage = true;
    } elseif (isset($_GET['ack'])) {
        icct_backend_require_management(3);
        $ackDetail = icct_nms_alarm_detail($_GET['ack']);
        $alarmAckPage = true;
    } elseif (isset($_GET['escalate'])) {
        icct_backend_require_management(3);
        $escalateDetail = icct_nms_alarm_detail($_GET['escalate']);
        $alarmEscalatePage = true;
    } elseif (isset($_GET['view'])) {
        $alarmDetail = icct_nms_alarm_detail($_GET['view']);
        if (isset($_GET['readings'])) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode($alarmDetail, JSON_THROW_ON_ERROR);
            exit();
        }
    } else {
        $alarmRows = icct_nms_alarm_rows();
    }
    if (isset($_GET['readings'])) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($alarmRows, JSON_THROW_ON_ERROR);
        exit();
    }
} catch (Throwable $e) {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' ||
        isset($_GET['history']) ||
        isset($_GET['readings'])
    ) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
    icct_nms_failure($e);
}
$title = isset($ticketDetail)
    ? ['ticket_progress' => 'In Progress Ticket', 'ticket_resolve' => 'Resolve Ticket', 'ticket_reopen' => 'Reopen Ticket', 'ticket_close' => 'Close Ticket'][$ticketAction]
    : (isset($ackDetail)
    ? 'Acknowledging Alarm'
    : (isset($escalateDetail) ? 'Escalate Alarm' : 'Alarms'));
$alarmsPage = true;
$management = is_realm_allowed(3);
require dirname(__DIR__, 2) . '/shared/templates/header.php';
if (isset($ticketDetail)) {
    require dirname(__DIR__) . '/templates/ticket_action.php';
} elseif (isset($ackDetail)) {
    require dirname(__DIR__) . '/templates/ack.php';
} elseif (isset($escalateDetail)) {
    require dirname(__DIR__) . '/templates/escalate.php';
} elseif (isset($alarmDetail)) {
    require dirname(__DIR__) . '/templates/detail.php';
} else {
    require dirname(__DIR__) . '/templates/list.php';
}
require dirname(__DIR__, 2) . '/shared/templates/footer.php';
