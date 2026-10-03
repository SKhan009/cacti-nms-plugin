<?php
require __DIR__.'/../../include/auth.php';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/device_type_service.php';
require_once __DIR__.'/includes/mib_repository_service.php';
require_once __DIR__.'/includes/forms.php';
$error='';$notice='';$mode='list';$preview=null;$plan=null;$reviewValues=[];
try{
    icct_nms_backend();$management=is_realm_allowed(3);$types=icct_nms_device_types();$bundles=icct_mib_list();
    if(isset($_GET['download'])){
        $bundle=null;foreach($bundles as $candidate)if($candidate['id']===($_GET['download']??''))$bundle=$candidate;
        if(!$bundle)throw new InvalidArgumentException('MIB upload not found.');$index=icct_nms_id($_GET['file']??0);$content=icct_mib_file($bundle,$index);
        header('Content-Type: text/plain; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-zA-Z0-9._-]/','_',$bundle['files'][$index]['name']).'"');echo $content;exit;
    }
    $draft=$_SESSION['icct_mib_draft']??null;
    if($draft && ($draft['owner']!==icct_backend_current_user_id()||time()-$draft['created']>3600)){unset($_SESSION['icct_mib_draft'],$_SESSION['icct_mib_plan'],$_SESSION['icct_mib_values']);$draft=null;}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        icct_nms_post();$action=$_POST['action']??'';
        if($action==='upload'){
            $mode='upload';$draft=icct_mib_preview($_FILES['mibs']??[], $_POST['type_id']??'');
            $_SESSION['icct_mib_draft']=$draft;unset($_SESSION['icct_mib_plan'],$_SESSION['icct_mib_values']);icct_nms_redirect('mib_repository.php?review=1');
        }elseif($action==='discard'){unset($_SESSION['icct_mib_draft'],$_SESSION['icct_mib_plan'],$_SESSION['icct_mib_values']);icct_nms_redirect('mib_repository.php');
        }elseif(in_array($action,['review','confirm'],true)){
            $mode=$action==='confirm'?'confirm':'review';$preview=$draft;
            if(!$draft||!hash_equals($draft['id'],(string)($_POST['draft_id']??'')))throw new InvalidArgumentException('Upload review is no longer available. Upload again.');
            if($action==='review'){
                $reviewValues=$_POST;$_SESSION['icct_mib_values']=$_POST;$plan=icct_mib_plan($draft,$_POST);$_SESSION['icct_mib_plan']=$plan;icct_nms_redirect('mib_repository.php?confirm=1');
            }else{
                $plan=$_SESSION['icct_mib_plan']??null;if(!$plan||($_POST['confirmed']??'')!=='1')throw new InvalidArgumentException('Review and confirm the creation options first.');
                icct_mib_save($draft,$plan);unset($_SESSION['icct_mib_draft'],$_SESSION['icct_mib_plan'],$_SESSION['icct_mib_values']);icct_nms_redirect('mib_repository.php?saved=1');
            }
        }else throw new InvalidArgumentException('Unknown repository action.');
    }elseif(isset($_GET['upload'])){icct_backend_require_management(3);$mode='upload';}
    elseif(isset($_GET['review'])||isset($_GET['confirm'])){
        icct_backend_require_management(3);if(!$draft)throw new InvalidArgumentException('Upload a MIB first.');$preview=$draft;$reviewValues=$_SESSION['icct_mib_values']??[];$plan=$_SESSION['icct_mib_plan']??null;$mode=isset($_GET['confirm'])&&$plan?'confirm':'review';
    }elseif(isset($_GET['inspect'])){
        $inspection=null;foreach($bundles as $candidate)if($candidate['id']===$_GET['inspect'])$inspection=$candidate;
        if(!$inspection)throw new InvalidArgumentException('MIB upload not found.');$objects=icct_mib_objects($inspection);$mode='inspect';
    }elseif(isset($_GET['saved']))$notice='MIB files and selected templates saved.';
}catch(Throwable $e){$error=$e->getMessage();}
$title='MIB Repository';$mibRepositoryPage=true;
require __DIR__.'/templates/header.php';require __DIR__.'/templates/mib_repository.php';require __DIR__.'/templates/footer.php';
