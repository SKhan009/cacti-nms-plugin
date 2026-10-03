<?php
require __DIR__.'/../../include/auth.php';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/node_service.php';
require_once __DIR__.'/includes/node_configuration_service.php';
$error='';
try {
    icct_nms_backend();
    $management=is_realm_allowed(3);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        try {
            icct_nms_post();
            if (isset($_POST['device_ids'])) icct_nms_assign_node_devices($_POST);
            else icct_nms_assign_node_device($_POST);
            $_SESSION['icct_nms_notice']='Device node assignments saved.';
            icct_nms_redirect('node_configuration.php');
        } catch (Throwable $failure) { $error=$failure->getMessage(); }
    }
    $devices=icct_nms_inventory(); $nodes=icct_nms_nodes();
    $memberships=[]; $rackNodes=[];
    foreach (db_fetch_assoc("SELECT meta_key,meta_value FROM plugin_icct_nms_meta WHERE meta_key LIKE 'device_node_id_%'") as $row) $memberships[(int)substr($row['meta_key'],15)]=(int)$row['meta_value'];
    foreach (db_fetch_assoc('SELECT id,node_id FROM plugin_icct_nms_racks') as $rack) $rackNodes[(int)$rack['id']]=(int)$rack['node_id'];
    $peripheralIds=[];
    foreach (db_fetch_assoc("SELECT meta_key,meta_value FROM plugin_icct_nms_meta WHERE meta_key LIKE 'rack_peripheral_%'") as $row) { $id=(int)substr($row['meta_key'],16); $memberships[$id]=(int)($rackNodes[(int)$row['meta_value']] ?? 0); $peripheralIds[$id]=true; }
    $grouped=icct_nms_node_configuration_groups($nodes,$devices,$memberships,$rackNodes);
} catch (Throwable $failure) { icct_nms_failure($failure); }
$nodeConfigurationPage=true; $title='Node Configuration'; $notice=$_SESSION['icct_nms_notice'] ?? ''; unset($_SESSION['icct_nms_notice']);
require __DIR__.'/templates/header.php';
require __DIR__.'/templates/node_configuration.php';
require __DIR__.'/templates/footer.php';
