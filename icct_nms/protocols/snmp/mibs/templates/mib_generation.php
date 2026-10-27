<?php
$generationCompleted=isset($generationObject)?0:(int)($generationBundle['wizard_step']??0);$generationDone=$generationCompleted>=$generationStage-1;
$generationLabels=[2=>'Data Source',3=>'Graph Template',4=>'Device Template'];$generationLabel=$generationLabels[$generationStage];
$generationResults=icct_mib_generation_results($generationBundle,$generationStage);$generationError='';
if(!$generationDone)try{icct_mib_generation_input($generationBundle,$generationStage,$generationObject??null);}catch(Throwable $e){$generationError=$e->getMessage();}
?>
<section class="mib-generation" aria-label="Automatic MIB configuration">
<header class="mib-generation-heading"><h1>Automatic MIB Configuration (<?= icct_nms_h($generationBundle['name']) ?>)</h1><div><a class="button" href="protocols/snmp/mibs/controllers/mib_repository.php">Cancel</a><?php if($generationCompleted===3): ?><a class="button primary" href="protocols/snmp/mibs/controllers/mib_repository.php">Add</a><?php else: ?><button class="button primary" type="button" disabled>Add</button><?php endif; ?></div></header>
<nav class="mib-generation-stepper" aria-label="MIB generation steps"><?php foreach([1=>"Upload MIBs",2=>'Data Source Generation',3=>'Graph Template Generation',4=>'Device Template Generation'] as $number=>$label): $complete=$number===1||$generationCompleted>=$number-1; ?><div class="<?= $complete?'complete':($number===$generationStage?'active':'') ?>" <?= $number===$generationStage?'aria-current="step"':'' ?>><span class="mib-generation-step-icon" aria-hidden="true"><?= $complete?'✓':'' ?></span><strong><?= $label ?></strong><small><?= $number ?>/4</small></div><?php endforeach; ?></nav>
<form method="post" id="mibGenerationForm" action="protocols/snmp/mibs/controllers/mib_repository.php">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="generate"><input type="hidden" name="bundle_id" value="<?= icct_nms_h($generationBundle['id']) ?>"><input type="hidden" name="stage" value="<?= $generationStage ?>"><?php if(isset($generationObject)): ?><input type="hidden" name="object" value="<?= $generationObject ?>"><?php endif; ?>
<?php if($generationStage===2&&!$generationDone&&!$generationError): require __DIR__.'/mib_generation_review.php'; else: ?>
<button type="submit" id="mibGenerate" <?= $generationDone||$generationError?'disabled':'' ?>>Generate</button><?php endif; ?>
</form>
<p id="mibGenerationError" class="notice error" role="alert" <?= $generationError?'':'hidden' ?>><?= icct_nms_h($generationError) ?></p>
<section id="mibGenerationProgress" class="<?= $generationDone?'complete':'' ?>" <?= $generationDone?'':'hidden' ?> aria-label="Generation results">
<p class="mib-generation-status" role="status" aria-live="polite"><span class="mib-generation-spinner" hidden></span><span id="mibGenerationStatus"><?= $generationDone?icct_nms_h($generationLabel).' generation completed.':'' ?></span></p>
<p>Generating “<?= $generationLabel ?>” for “<?= icct_nms_h($generationBundle['name']) ?>” <span id="mibGenerationState"><?= $generationDone?'Completed':'' ?></span></p>
<progress id="mibGenerationBar" max="100" <?= $generationDone?'value="100"':'' ?> aria-label="Template generation progress"></progress>
<p class="mib-generation-caption" id="mibGenerationCaption"><?= $generationDone?'Generation Completed Successfully':'' ?></p>
<ul class="mib-generation-results"><?php foreach($generationResults as $result): ?><li><span class="mib-generation-check" aria-hidden="true">✓</span><?= icct_nms_h($result['kind']) ?> Created “<?= icct_nms_h($result['name'].($result['oid']?'('.$result['oid'].')':'')) ?>”</li><?php endforeach; ?></ul>
<?php if($generationDone&&$generationStage<4): ?><a class="button primary mib-generation-next" href="protocols/snmp/mibs/controllers/mib_repository.php?generate=<?= icct_nms_h($generationBundle['id']) ?>&amp;stage=<?= $generationStage+1 ?>">Next →</a><?php endif; ?>
<?php if($generationCompleted===3): ?><a class="mib-generation-review" href="protocols/snmp/mibs/controllers/mib_repository.php?wizard=<?= icct_nms_h($generationBundle['id']) ?>">View Templates →</a><?php endif; ?>
</section></section>
