<?php
/** Authenticated diagnostics: immediate Ping/Trace Route and saved-profile tools. */
require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/shared/services/bootstrap.php";
require_once __DIR__ . "/inventory/services/inventory.php";
require_once __DIR__ . "/shared/services/forms.php";
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "instant"
) {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    try {
        icct_nms_backend();
        icct_nms_post();
        $id = icct_nms_id($_POST["host_id"] ?? 0);
        $tool = (string) ($_POST["tool"] ?? "");
        $result = icct_backend_diag_run_instant($id, $tool);
        echo json_encode(
            ["ok" => true, "result" => $result],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    } catch (Throwable $exception) {
        http_response_code(400);
        echo json_encode(
            ["ok" => false, "error" => $exception->getMessage()],
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    exit();
}
if (($_GET['action']??'')==='options') {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    try {
        icct_nms_backend();icct_backend_require_management(3);
        $id=icct_nms_id($_GET['host_id']??0);icct_backend_require_device_access($id);
        $host=icct_nms_device($id);$labels=icct_backend_diag_selected_labels($id);
        $tool=(string)($_GET['tool']??array_key_first($labels)??'');
        if($tool!==''&&!isset($labels[$tool]))throw new InvalidArgumentException('This diagnostic is not selected for the device.');
        echo json_encode(['ok'=>true,'name'=>$host['description'],'tools'=>$labels,'tool'=>$tool],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    }catch(Throwable $exception){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$exception->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);}
    exit;
}
if (($_GET['action']??'')==='status' || ($_POST['action']??'')==='queue') {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    try {
        icct_nms_backend();icct_backend_require_management(3);
        $id=icct_nms_id($_GET['host_id']??($_POST['host_id']??0));icct_backend_require_device_access($id);
        if(($_POST['action']??'')==='queue'){
            icct_nms_post();$jobId=icct_backend_diag_run($id,(string)($_POST['tool']??''));
        } else {$jobId=icct_nms_id($_GET['job_id']??0);}
        $job=icct_backend_diag_job($jobId);
        if((int)$job['host_id']!==$id)throw new RuntimeException('The result belongs to another device.');
        $result=$job['result_json']!==''?json_decode($job['result_json'],true):null;
        echo json_encode(['ok'=>true,'job_id'=>(int)$job['id'],'status'=>$job['status'],'output'=>$result['output']??''],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    } catch(Throwable $exception){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$exception->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);}
    exit;
}
$error = "";
$notice = "";
$result = null;
$job = null;
try {
    icct_nms_backend();
    $id = icct_nms_id($_GET["host_id"] ?? 0);
    $host = icct_nms_device($id);
    icct_backend_require_management(3);
    $diagnosticLabels=icct_backend_diag_selected_labels($id);
    $tool = (string) ($_POST["tool"] ?? ($_GET["tool"] ?? array_key_first($diagnosticLabels) ?? ""));
    if ($tool!=="" && !isset($diagnosticLabels[$tool])) {
        throw new InvalidArgumentException("This diagnostic is not selected for the device. Select it in Device Diagnostics first.");
    }
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        icct_nms_post();
        try {
            $jobId = icct_backend_diag_run($id, $tool);
            icct_nms_redirect(
                "diagnostics.php?host_id=" .
                    $id .
                    "&tool=" .
                    rawurlencode($tool) .
                    "&job_id=" .
                    $jobId,
            );
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
    if (isset($_GET["job_id"])) {
        $job = icct_backend_diag_job(icct_nms_id($_GET["job_id"]));
        if ((int) $job["host_id"] !== $id) {
            throw new RuntimeException("The result belongs to another device.");
        }
        if ($job["result_json"] !== "") {
            $result = json_decode(
                $job["result_json"],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }
    }
    $title = "Device Diagnostics";
    require __DIR__ . "/shared/templates/header.php";
    require __DIR__ . "/inventory/diagnostics/templates/diagnostics.php";
    require __DIR__ . "/shared/templates/footer.php";
} catch (Throwable $exception) {
    icct_nms_failure($exception);
}
