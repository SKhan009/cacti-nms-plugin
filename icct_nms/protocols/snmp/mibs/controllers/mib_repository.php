<?php
require dirname(__DIR__, 4) . '/../../include/auth.php';
require_once dirname(__DIR__, 4) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 4) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 4) . '/presets/services/device_type_service.php';
require_once dirname(__DIR__, 4) . '/protocols/snmp/mibs/services/mib_repository_service.php';
require_once dirname(__DIR__, 4) . '/protocols/snmp/mibs/services/mib_browser_service.php';
require_once dirname(__DIR__, 4) . '/protocols/snmp/mibs/services/mib_generation_service.php';
require_once dirname(__DIR__, 4) . '/shared/services/forms.php';
require_once dirname(__DIR__, 4) . '/protocols/snmp/mibs/services/mib_staging_service.php';
require_once dirname(__DIR__, 4) . '/protocols/snmp/mibs/services/mib_upload_session_service.php';
$uploadStream=$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='upload'&&($_POST['upload_progress']??'')==='1';
$uploadJson=$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='upload'&&($_POST['upload_response']??'')==='json';
$generationJson=$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='generate'&&($_POST['generation_response']??'')==='json';
$uploadSessionJson=$_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['action']??'',['commit_upload','discard_upload','touch_review'],true);
$generationStream=false;
$error='';$notice='';$mode='list';$preview=null;$plan=null;$reviewValues=[];$deletedId='';
// Initialization failures must stop before the shared header calls backend services.
try { icct_nms_backend(); } catch (Throwable $e) { icct_nms_failure($e); }
try{
    $management=is_realm_allowed(3);$types=icct_mib_device_types();$bundles=icct_mib_list();
    if(isset($_GET['repository'])){$legacyBundle=icct_mib_wizard_get($_GET['repository']);icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?view='.$legacyBundle['id']);}
    if(isset($_GET['browse'])){$legacyBundle=icct_mib_wizard_get($_GET['browse']);icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?view='.$legacyBundle['id']);}
    if(isset($_GET['download'])){
        $bundle=null;foreach($bundles as $candidate)if($candidate['id']===($_GET['download']??''))$bundle=$candidate;
        if(!$bundle)throw new InvalidArgumentException('MIB upload not found.');$index=icct_nms_id($_GET['file']??0);$content=icct_mib_file($bundle,$index);
        header('Content-Type: text/plain; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-zA-Z0-9._-]/','_',$bundle['files'][$index]['name']).'"');echo $content;exit;
    }
    $draft=$_SESSION['icct_mib_draft']??null;
    if($draft && ($draft['owner']!==icct_backend_current_user_id()||time()-$draft['created']>3600)){unset($_SESSION['icct_mib_draft'],$_SESSION['icct_mib_plan'],$_SESSION['icct_mib_values']);$draft=null;}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        icct_nms_post();$action=$_POST['action']??'';
        if($action==='touch_review'){
            icct_mib_generation_draft_get($_POST['ready_token']??'',$_POST['bundle_id']??'','ready');
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>true,'csrf'=>csrf_get_tokens()]);exit;
        }elseif($action==='discard_upload'){
            icct_mib_upload_session_discard($_POST['upload_token']??'');
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>true]);exit;
        }elseif($action==='commit_upload'){
            $choice=$_POST['upload_next']??'';if(!in_array($choice,['automatic','repository'],true))throw new InvalidArgumentException('Choose the next step.');
            $stored=icct_mib_upload_session_commit($_POST['upload_token']??'',$choice==='automatic',$_POST['repository_ack']??'');
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
            echo json_encode(['ok'=>true,'redirect'=>'protocols/snmp/mibs/controllers/mib_repository.php?uploaded=1'],JSON_THROW_ON_ERROR);exit;
        }elseif($action==='select_oids'){
            icct_backend_require_management(3);$selectionBundle=icct_mib_wizard_get($_POST['bundle_id']??'');
            $eligible=icct_mib_generation_input($selectionBundle,2)['selected'];$chosen=icct_mib_review_input($_POST)['selected']??[];
            $chosenReview=icct_mib_generation_prepare($eligible,['selected'=>$chosen,'records'=>[]],true);$chosen=$chosenReview['selected'];
            // Step 1 only saves the selection. Step 2 starts with an explicit Generate action.
            $readyToken=!empty($_POST['ready_token'])?icct_mib_staging_selection($selectionBundle['id'],$_POST['ready_token'],$chosenReview):icct_mib_generation_draft_put($selectionBundle['id'],'ready',['values'=>$chosenReview,'object'=>null]);
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?generate='.$selectionBundle['id'].'&stage=2&ready='.$readyToken);
        }elseif($action==='edit_repository'){
            icct_mib_repository_edit($_POST['bundle_id']??'',$_POST['name']??'',$_POST['type_id']??'');
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?updated=1');
        }elseif(in_array($action,['delete','restore'],true)){
            if($action==='delete'&&($_POST['delete_confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm the MIB deletion before continuing.');
            icct_mib_set_deleted($_POST['bundle_id']??'', $action==='delete',$action==='restore');
            if($action==='delete')$_SESSION['icct_mib_deleted_notice']=$_POST['bundle_id'];
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php'.($action==='delete'?'?deleted='.rawurlencode($_POST['bundle_id']):'?restored=1'));
        }elseif($action==='discard_generated'){
            icct_backend_require_management(3);icct_mib_generation_draft_get($_POST['ready_token']??'',$_POST['bundle_id']??'','ready');
            unset($_SESSION['icct_mib_generation_'.$_POST['ready_token']]);
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php');
        }elseif($action==='add_generated'){
            $saveDraft=icct_mib_generation_draft_get($_POST['ready_token']??'',$_POST['bundle_id']??'','ready');
            if(!empty($saveDraft['saved_edit'])){
                $stage=icct_backend_topology_integer($_POST['edit_stage']??'',2,4,'Edit step');
                icct_mib_edit_save($_POST['bundle_id'],$_POST['ready_token'],$stage);
                icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?generate='.$_POST['bundle_id'].'&stage='.$stage.'&review_prepared=1&ready='.$_POST['ready_token'].'&edit_saved='.$stage);
            }
            $stored=icct_mib_staging_commit($_POST['bundle_id']??'',$_POST['ready_token']??'',($_POST['include_device']??'1')!=='0');
            icct_nms_redirect(icct_mib_saved_destination($stored,$_POST['ready_token']??''));
        }elseif($action==='generate'){
            $generationStream=true;
            header('Content-Type: application/x-ndjson; charset=utf-8');header('Cache-Control: no-store');header('X-Accel-Buffering: no');
            while(ob_get_level()>0)ob_end_clean();
            $emit=function($event){echo json_encode($event,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";flush();};
            $stage=icct_backend_topology_integer($_POST['stage']??'',2,4,'Generation stage');
            $id=$_POST['bundle_id']??'';$token=$_POST['ready_token']??'';
            if($stage===2&&isset($_POST['prepared_selection'])&&empty(icct_mib_generation_draft_get($token,$id,'ready')['saved_edit'])){
                $selected=json_decode($_POST['prepared_selection'],true,32,JSON_THROW_ON_ERROR);$bundle=icct_mib_wizard_get($id);
                $values=icct_mib_generation_prepare(icct_mib_generation_input($bundle,2)['selected'],['selected'=>$selected,'records'=>[]],true);
                icct_mib_staging_selection($id,$token,$values);
            }
            if(isset($_POST['prepared_review']))icct_mib_staging_review($id,$token,$stage,['records'=>json_decode($_POST['prepared_records']??'{}',true,32,JSON_THROW_ON_ERROR),'device_graphs'=>$_POST['device_graphs']??'[]','device_name'=>$_POST['device_name']??'','device_class'=>$_POST['device_class']??'','device_queries'=>$_POST['device_queries']??'[]']);
            $editDraft=icct_mib_generation_draft_get($token,$id,'ready');
            if(!empty($editDraft['saved_edit'])){
                icct_mib_edit_save($id,$token,$stage);
                $next=($_POST['next_after_save']??'')==='1'&&$stage<4?$stage+1:$stage;
                session_write_close();$emit(['event'=>'complete','redirect'=>'protocols/snmp/mibs/controllers/mib_repository.php?generate='.$id.'&stage='.$next.'&review_prepared=1&ready='.$token.'&edit_saved='.$stage]);exit;
            }
            icct_mib_staging_generate($id,$token,$stage,$emit);
            if(($_POST['commit_after_save']??'')==='1'){$saved=icct_mib_staging_commit($id,$token);$destination=icct_mib_saved_destination($saved,$token);session_write_close();$emit(['event'=>'complete','redirect'=>$destination]);exit;}
            session_write_close();
            $continue=($_POST['next_after_save']??'')==='1'&&$stage<4;
            $emit(['event'=>'complete','redirect'=>'protocols/snmp/mibs/controllers/mib_repository.php?generate='.$id.'&stage='.($continue?$stage+1:$stage).'&ready='.$token.($continue?'&review_prepared=1&step_saved='.$stage:'')]);exit;
        }elseif($action==='wizard_save'){
            $mode='wizard';$wizardBundle=icct_mib_wizard_get($_POST['bundle_id']??'');
            icct_mib_wizard_save($wizardBundle['id'],icct_mib_review_input($_POST));
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?wizard='.$wizardBundle['id'].'&saved_step=1');
        }elseif($action==='upload'){
            $mode='upload';icct_mib_upload_session_token($_POST['upload_token']??'');icct_mib_validate_upload_selection($_FILES['mibs']??[]);$uploadProgress=null;
            if($uploadStream){
                header('Content-Type: application/x-ndjson; charset=utf-8');header('Cache-Control: no-store');header('X-Accel-Buffering: no');
                while(ob_get_level()>0)ob_end_flush();
                $uploadProgress=function($event){echo json_encode(['type'=>'progress']+$event,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";flush();};
                $uploadProgress(['phase'=>'validate','message'=>'File uploaded. Validating MIB definitions…']);
            }
            $draft=icct_mib_preview($_FILES['mibs']??[], '__mib_unassigned__',true,$uploadProgress);
            $uploadToken=icct_mib_upload_session_token($_POST['upload_token']??'');
            if($uploadProgress)$uploadProgress(['phase'=>'repository','message'=>'Checking repository modules, content and OID definitions…']);
            $repositoryComparison=icct_mib_repository_comparison($draft);
            if($repositoryComparison['blocked'])throw new IcctMibRepositoryConflict($repositoryComparison);
            $uploadSummary=icct_mib_upload_summary($draft);
            $uploadSummary['repository_check']=$repositoryComparison;
            icct_mib_upload_session_store($uploadToken,$draft);
            $uploadResult=['ok'=>true,'object_count'=>count($draft['records']),'summary'=>$uploadSummary,'upload_token'=>$uploadToken];
            if($uploadStream){echo json_encode(['type'=>'complete']+$uploadResult,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";flush();exit;}
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($uploadResult,JSON_THROW_ON_ERROR);exit;
        }elseif($action==='reparse'){
            $bundle=null;foreach($bundles as $candidate)if($candidate['id']===($_POST['bundle_id']??''))$bundle=$candidate;
            if(!$bundle)throw new InvalidArgumentException('MIB upload not found.');
            $upload=['name'=>[],'stored_content'=>[]];
            foreach($bundle['files'] as $i=>$file){$upload['name'][]=$file['name'];$upload['stored_content'][]=icct_mib_file($bundle,$i);}
            $draft=icct_mib_preview($upload,$bundle['type_id'],true);
            if(empty($bundle['rows']))icct_mib_wizard_reparse($bundle['id'],$draft);
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?wizard='.$bundle['id']);
        }elseif($action==='discard'){unset($_SESSION['icct_mib_draft'],$_SESSION['icct_mib_plan'],$_SESSION['icct_mib_values'],$_SESSION['icct_mib_inputs']);icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php');
        }elseif(in_array($action,['review','confirm'],true)){
            $mode=$action==='confirm'?'confirm':'review';$preview=$draft;
            if(!$draft||!hash_equals($draft['id'],(string)($_POST['draft_id']??'')))throw new InvalidArgumentException('Upload review is no longer available. Upload again.');
            if($action==='review'){
                unset($_SESSION['icct_mib_plan']);if(($_POST['review_complete']??'')!=='1')throw new InvalidArgumentException('The review form was truncated. Enable JavaScript and retry so all selected OIDs are included.');$reviewValues=icct_mib_review_input($_POST);$_SESSION['icct_mib_values']=$reviewValues;$plan=icct_mib_plan($draft,$reviewValues);$_SESSION['icct_mib_plan']=$plan;icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?confirm=1');
            }else{
                $plan=$_SESSION['icct_mib_plan']??null;if(!$plan||($_POST['confirmed']??'')!=='1')throw new InvalidArgumentException('Review and confirm the creation options first.');
                icct_mib_save($draft,$plan);unset($_SESSION['icct_mib_draft'],$_SESSION['icct_mib_plan'],$_SESSION['icct_mib_values'],$_SESSION['icct_mib_inputs']);icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?saved=1');
            }
        }else throw new InvalidArgumentException('Unknown repository action.');
    }elseif(isset($_GET['upload'])){icct_backend_require_management(3);$mode='list';$uploadOpen=true;}
    elseif(isset($_GET['review'])||isset($_GET['confirm'])){
        icct_backend_require_management(3);if(!$draft)throw new InvalidArgumentException('Upload a MIB first.');$preview=$draft;$reviewValues=$_SESSION['icct_mib_values']??[];$plan=$_SESSION['icct_mib_plan']??null;$mode=isset($_GET['confirm'])&&$plan?'confirm':'review';
    }elseif(isset($_GET['select'])){
        icct_backend_require_management(3);$mode='selection';$selectionBundle=icct_mib_wizard_get($_GET['select']);$bundles=[$selectionBundle];
        if(isset($_GET['ready'])){$selectionReadyToken=(string)$_GET['ready'];$selectionDraft=icct_mib_generation_draft_get($selectionReadyToken,$selectionBundle['id'],'ready');}
        $initialObject=isset($_GET['object'])?icct_backend_topology_integer($_GET['object'],0,511,'OID'):null;
    }elseif(isset($_GET['generate'])){
        icct_backend_require_management(3);$mode='generation';$generationBundle=icct_mib_wizard_get($_GET['generate']);
        $generationStage=isset($_GET['object'])?2:(isset($_GET['stage'])?icct_backend_topology_integer($_GET['stage'],2,4,'Generation stage'):min(4,(int)($generationBundle['wizard_step']??0)+2));
        if(!isset($_GET['tab'])&&!isset($_GET['ready'])&&$generationStage>(int)($generationBundle['wizard_step']??0)+2)throw new InvalidArgumentException('Complete the previous generation stage first.');
        $generationObject=isset($_GET['object'])?icct_backend_topology_integer($_GET['object'],0,511,'OID'):null;
        // Reopen the persisted configuration even after its preparation session expires.
        if(!isset($_GET['ready'])&&!empty($generationBundle['rows'])){
            $token=icct_mib_edit_open($generationBundle);
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?generate='.$generationBundle['id'].'&stage='.$generationStage.'&review_prepared=1&ready='.$token);
        }
        if(isset($_GET['ready'])){
            try{$generationReadyToken=(string)$_GET['ready'];$readyDraft=icct_mib_generation_draft_get($generationReadyToken,$generationBundle['id'],'ready');}
            catch(Throwable $e){$mode='list';throw $e;}
            if(!empty($readyDraft['saved_readonly'])){$token=icct_mib_edit_open($generationBundle);icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?generate='.$generationBundle['id'].'&stage='.$generationStage.'&review_prepared=1&ready='.$token);}
            $readyDraft['work']=icct_mib_staging_work($readyDraft);
            $generationStage=isset($_GET['stage'])?icct_backend_topology_integer($_GET['stage'],2,4,'Generation stage'):2;
            // Viewing any step is read-only; Generate still enforces preparation order.
            $generationSelected=$readyDraft['review']['values']['selected'];$generationObject=$readyDraft['review']['object'];
        }
        if(isset($_GET['selection'])){
            $selectionKey=(string)$_GET['selection'];if(!preg_match('/^[a-f0-9]{32}$/D',$selectionKey)){$mode='list';throw new InvalidArgumentException('Invalid OID selection.');}
            $savedSelection=$_SESSION['icct_mib_selection_'.$selectionKey]??null;
            if(!$savedSelection||$savedSelection['id']!==$generationBundle['id']||time()-$savedSelection['time']>3600){$mode='list';throw new InvalidArgumentException('OID selection expired. Select the OIDs again.');}
            $generationSelected=$savedSelection['selected'];
        }
        // Old review URLs now lead directly to Generate with validated saved OIDs.
        if(!isset($_GET['tab'])&&$generationStage===2&&!isset($generationReadyToken)&&((int)($generationBundle['wizard_step']??0)<1||isset($generationSelected)||isset($generationObject))){
            $eligible=icct_mib_generation_input($generationBundle,2,$generationObject)['selected'];
            $values=icct_mib_generation_prepare($eligible,['selected'=>$generationSelected??$eligible,'records'=>[]],true);
            $generationReadyToken=icct_mib_generation_draft_put($generationBundle['id'],'ready',['values'=>$values,'object'=>$generationObject]);
            icct_nms_redirect('protocols/snmp/mibs/controllers/mib_repository.php?generate='.$generationBundle['id'].'&stage=2&ready='.$generationReadyToken);
        }

    }elseif(isset($_GET['view'])){
        $viewBundle=icct_mib_wizard_get($_GET['view']);$viewStage=icct_backend_topology_integer($_GET['stage']??1,1,4,'View step');$mode='configuration_view';
    }elseif(isset($_GET['wizard'])){
        icct_backend_require_management(3);$mode='wizard';$wizardBundle=icct_mib_wizard_get($_GET['wizard']);
    }elseif(isset($_GET['inspect'])){
        $inspection=null;foreach($bundles as $candidate)if($candidate['id']===$_GET['inspect'])$inspection=$candidate;
        if(!$inspection)throw new InvalidArgumentException('MIB upload not found.');$objects=icct_mib_objects($inspection);$mode='inspect';
    }elseif(isset($_GET['saved']))$notice='MIB files and selected templates saved.';
    if($mode==='generation'&&isset($_GET['step_saved'],$readyDraft)){
        $savedStage=icct_backend_topology_integer($_GET['step_saved'],2,4,'Saved stage');
        if(isset($readyDraft['work']['plans'][$savedStage]))$notice=[2=>'Data Source',3=>'Graph Template',4=>'Device Template'][$savedStage].' settings saved successfully in this session.';
    }
    if(isset($_GET['uploaded']))$notice='MIB file saved to repository.';
    if(isset($_GET['updated']))$notice='Repository details saved.';
    if(isset($_GET['restored']))$notice='MIB file restored.';
    if(isset($_GET['deleted'])&&is_string($_GET['deleted'])&&preg_match('/^[a-f0-9]{32}$/D',$_GET['deleted'])&&$management&&($_SESSION['icct_mib_deleted_notice']??'')===$_GET['deleted']){$deletedId=$_GET['deleted'];unset($_SESSION['icct_mib_deleted_notice']);}
}catch(Throwable $e){if($generationStream){echo json_encode(['event'=>'error','message'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE)."\n";flush();exit;}if($uploadStream){if(!headers_sent()){http_response_code(400);header('Content-Type: application/x-ndjson; charset=utf-8');header('Cache-Control: no-store');}echo json_encode(['type'=>'error','ok'=>false,'error'=>$e->getMessage()]+($e instanceof IcctMibRepositoryConflict?['repository_check'=>$e->comparison]:[]),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";flush();exit;}if($uploadJson||$generationJson||$uploadSessionJson){http_response_code(400);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>false,'error'=>$e->getMessage()]+($e instanceof IcctMibRepositoryConflict?['repository_check'=>$e->comparison]:[]),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);exit;}$error=$e->getMessage();if(($mode==='wizard'&&!isset($wizardBundle))||($mode==='generation'&&(!isset($generationBundle)||!isset($generationStage))))$mode='list';}
try { if($mode==='wizard'&&isset($wizardBundle)){
    $wizardBundle=icct_mib_wizard_get($wizardBundle['id']);$wizardObjects=icct_mib_objects($wizardBundle);
    $wizardStep=min(4,1+(int)($wizardBundle['wizard_step']??(!empty($wizardBundle['rows'])?3:0)));
    $wizardValues=$_SERVER['REQUEST_METHOD']==='POST'?icct_mib_review_input($_POST):[];
    if($wizardStep===1&&isset($_GET['automatic'])&&$_SERVER['REQUEST_METHOD']!=='POST'){
        $existingSources=icct_mib_existing_source_indexes($wizardObjects);$wizardValues=['selected'=>[]];
        foreach($wizardObjects as $i=>$object)if(!empty($object['numeric'])&&!isset($existingSources[$i]))$wizardValues['selected'][$i]=1;
    }
    if(isset($_GET['saved_step']))$notice='Step saved successfully.';
}
} catch(Throwable $e){$error=$e->getMessage();$mode='list';}
if($mode==='upload'){$mode='list';$uploadOpen=true;}
$title='MIB Repository';$mibRepositoryPage=true;
require dirname(__DIR__, 4) . '/shared/templates/header.php';require dirname(__DIR__, 4) . '/protocols/snmp/mibs/templates/mib_repository.php';require dirname(__DIR__, 4) . '/shared/templates/footer.php';
