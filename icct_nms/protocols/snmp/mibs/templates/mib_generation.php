<?php
$generationReview=isset($_GET['review_prepared'])||isset($_GET['tab']);
$generationStaged=isset($generationReadyToken);$generationWork=isset($readyDraft)?icct_mib_staging_work($readyDraft):['stage'=>1,'results'=>[]];
$generationCompleted=(isset($generationObject)||isset($generationSelected))?0:(int)($generationBundle['wizard_step']??0);$generationDone=$generationCompleted>=$generationStage-1;
if($generationStaged){$generationCompleted=$generationWork['stage']-1;$generationDone=$generationWork['stage']>=$generationStage;}
if($generationStage===4&&$generationDone&&!array_key_exists('queries',$generationWork['plans'][4]['device_template']??[]))$generationDone=false;
if($generationStage===4&&$generationDone)$generationReview=true;
$generationDisplayName=implode(', ',array_unique(array_filter(array_column($generationBundle['files']??[],'module'))))?:$generationBundle['name'];
$generationActionLabel=($generationReview||$generationWork['stage']>=2||!empty($generationBundle['rows']))?'Save':'Add';
$generationLabels=[2=>'Data Source',3=>'Graph Template',4=>'Device Template'];$generationLabel=$generationLabels[$generationStage];
$generationResults=icct_mib_generation_results($generationBundle,$generationStage);$generationError='';
if($generationStaged)$generationResults=$generationWork['results'][$generationStage]??[];
if($generationStaged&&$generationStage>$generationWork['stage']+1)$generationError='Generate the previous stage before preparing this step.';
if(!$generationStaged)$generationError='Select OIDs in step 1 and choose Next before generating.';
if(!$generationDone&&!$generationError)try{icct_mib_generation_input($generationBundle,$generationStage,$generationObject??null);}catch(Throwable $e){$generationError=$e->getMessage();}
?>
<section class="mib-generation" aria-label="Automatic MIB configuration">
<header class="mib-generation-heading"><h1>Automatic MIB Configuration (<?= icct_nms_h($generationDisplayName) ?>)</h1><div>
<?php if($generationStaged): ?><form method="post" id="mibAddForm" action="protocols/snmp/mibs/controllers/mib_repository.php"><?php icct_nms_token(); ?><input type="hidden" name="bundle_id" value="<?= icct_nms_h($generationBundle['id']) ?>"><input type="hidden" name="ready_token" value="<?= icct_nms_h($generationReadyToken) ?>"><input type="hidden" name="edit_stage" value="<?= $generationStage ?>"><button class="button" type="submit" name="action" value="discard_generated">Cancel</button><button class="button primary" type="submit" name="action" value="add_generated" data-default-label="<?= $generationActionLabel ?>" <?= $generationWork['stage']>=3&&($generationStage!==4||$generationDone)?'':'disabled' ?>><?= $generationActionLabel ?></button></form>
<?php else: ?><a class="button" href="protocols/snmp/mibs/controllers/mib_repository.php">Back to Repository</a><?php endif; ?></div></header>
<?php require __DIR__.'/mib_stepper.php'; ?>
<?php if(!empty($readyDraft['saved_edit'])&&isset($_GET['edit_saved'])): ?><div class="notice success" role="status">Template changes saved successfully.</div><?php endif; ?>
<?php if($generationStage===4&&(!empty($readyDraft['committed'])||(!empty($readyDraft['saved_edit'])&&isset($_GET['edit_saved'])))&&icct_mib_can_finish($generationBundle)): ?><div class="notice success" role="status">Templates saved successfully.</div><?php endif; ?>
<form method="post" id="mibGenerationForm" <?= !empty($readyDraft['saved_edit'])?'data-saved-edit="1"':'' ?> action="protocols/snmp/mibs/controllers/mib_repository.php">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="generate"><input type="hidden" name="bundle_id" value="<?= icct_nms_h($generationBundle['id']) ?>"><input type="hidden" name="stage" value="<?= $generationStage ?>"><?php if(isset($generationObject)): ?><input type="hidden" name="object" value="<?= $generationObject ?>"><?php endif; ?>
<?php if(isset($generationReadyToken)): ?><input type="hidden" name="ready_token" value="<?= icct_nms_h($generationReadyToken) ?>"><?php endif; ?>
<button type="submit" id="mibGenerate" data-completed="<?= $generationDone?'1':'0' ?>" <?= $generationDone?'hidden':'' ?> <?= $generationError||!$generationStaged?'disabled':'' ?>><?= ($generationDone||($generationStage===2&&!empty($readyDraft['selection_changed'])))&&$generationStaged?'Regenerate':'Generate' ?></button>
<?php if(isset($generationReadyToken)&&!$generationDone): ?><div class="mib-generation-ready-actions"><span><?= $generationStage===4?count($readyDraft['device_template']['graphs']??array_column(isset($generationWork['plans'][3])?icct_mib_graph_groups($generationWork['plans'][3]['records']):[],'name')).' graph templates selected':count($generationSelected??[]).' OIDs selected' ?></span></div><?php endif; ?>
<?php if($generationReview&&$generationStaged&&($generationDone||$generationStage===3)): require __DIR__.'/mib_prepared_review.php'; endif; ?>
</form>
<p id="mibGenerationError" class="notice error" role="alert" <?= $generationError?'':'hidden' ?>><?= icct_nms_h($generationError) ?></p>
<section id="mibGenerationProgress" class="<?= $generationDone?'complete':'' ?>" <?= $generationDone&&!$generationReview?'':'hidden' ?> aria-label="Generation results" aria-busy="false">
<p class="mib-generation-status" role="status" aria-live="polite"><span class="mib-generation-spinner" hidden></span><span id="mibGenerationStatus"><?= $generationDone?icct_nms_h($generationLabel).' preparation completed.':'' ?></span></p>
<p class="mib-generation-description"><span>Preparing “<?= $generationLabel ?>” for “<?= icct_nms_h($generationDisplayName) ?>” <span id="mibGenerationState"><?= $generationDone?'Completed':'' ?></span></span><?php if($generationDone): ?><span class="mib-generation-check" aria-hidden="true">✓</span><?php endif; ?></p>
<progress id="mibGenerationBar" max="100" <?= $generationDone?'value="100"':'' ?> aria-label="Template generation progress"></progress>
<p id="mibGenerationCount" role="status" aria-live="polite"><?= $generationDone?count($generationResults).' / '.count($generationResults).' prepared':'' ?></p>
<p class="mib-generation-caption" id="mibGenerationCaption"><?= $generationDone?'Preparation completed — session only. Add becomes available after graph template preparation to save to Cacti.':'' ?></p>
<ul class="mib-generation-results" id="mibGenerationResults"><?php foreach($generationResults as $result): ?><li><span class="mib-generation-check" aria-hidden="true">✓</span><?= icct_nms_h($result['kind']) ?> <?= $generationStaged?'Prepared':'Created' ?> “<?= icct_nms_h($result['name'].($result['oid']?'('.$result['oid'].')':'')) ?>”</li><?php endforeach; ?></ul>
<?php if(!$generationStaged&&$generationCompleted===3): ?><a class="mib-generation-review" href="protocols/snmp/mibs/controllers/mib_repository.php?wizard=<?= icct_nms_h($generationBundle['id']) ?>">View Templates →</a><?php endif; ?>
</section>
<footer class="mib-generation-navigation" aria-label="Configuration step navigation">
<div class="mib-session-save" data-session-save hidden><label><input type="checkbox" data-session-save-ack> <?= !empty($readyDraft['saved_edit'])?'Save changes to the existing templates.':'Save changes for the selected OIDs in this session.' ?></label></div>
<?php if($generationStaged): ?>
<a class="button mib-generation-previous" href="protocols/snmp/mibs/controllers/mib_repository.php?<?= $generationStage===2?'select=':'generate=' ?><?= icct_nms_h($generationBundle['id']) ?><?= $generationStage===2?'':'&amp;stage='.($generationStage-1).'&amp;review_prepared=1' ?>&amp;ready=<?= icct_nms_h($generationReadyToken) ?>"><span>Previous</span><span aria-hidden="true">←</span></a>
<?php else: ?><button type="button" class="button mib-generation-previous" disabled><span>Previous</span><span aria-hidden="true">←</span></button><?php endif; ?>
<?php if($generationStage===4&&(!empty($readyDraft['committed'])||(!empty($readyDraft['saved_edit'])&&isset($_GET['edit_saved'])))&&icct_mib_can_finish($generationBundle)): ?><a class="button mib-generation-next" href="protocols/snmp/mibs/controllers/mib_repository.php?saved=1" data-mib-finish><span>Finish</span><span aria-hidden="true">→</span></a><?php elseif($generationDone&&(!$generationReview||$generationStage<4)): ?><a class="button primary mib-generation-next" href="protocols/snmp/mibs/controllers/mib_repository.php?generate=<?= icct_nms_h($generationBundle['id']) ?>&amp;stage=<?= $generationReview?$generationStage+1:$generationStage ?>&amp;review_prepared=1<?= $generationStaged?'&amp;ready='.icct_nms_h($generationReadyToken):'' ?><?= $generationReview?'&amp;step_saved='.$generationStage:'' ?>"><span>Next</span><span aria-hidden="true">→</span></a><?php else: ?><button type="button" class="button primary mib-generation-next" disabled><span><?= $generationStage===4?'Finish':'Next' ?></span><span aria-hidden="true">→</span></button><?php endif; ?>
</footer>
</section>
