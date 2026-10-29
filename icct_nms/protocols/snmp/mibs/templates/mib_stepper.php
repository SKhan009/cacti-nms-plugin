<?php
$stepperStage=$generationStage??1;
$stepperId=$generationBundle['id']??$selectionBundle['id'];
$stepperToken=$generationReadyToken??$selectionReadyToken??null;
$stepperCompleted=isset($generationCompleted)?$generationCompleted:((isset($selectionDraft)?icct_mib_staging_work($selectionDraft)['stage']:1)-1);
?>
<nav class="mib-generation-stepper" aria-label="MIB generation steps">
<?php foreach([1=>'MIB Tree / OID Selection',2=>'Data Source Generation',3=>'Graph Template Generation',4=>'Device Template Generation'] as $number=>$label):
$stepperUrl='protocols/snmp/mibs/controllers/mib_repository.php?'.($number===1?'select=':'generate=').$stepperId.($number===1?'':'&stage='.$number.'&tab=1').($stepperToken?'&ready='.$stepperToken:'');
$stepperComplete=($number===1&&$stepperToken)||($number>1&&$stepperCompleted>=$number-1);
?>
<div class="<?= $number===$stepperStage?'active':($stepperComplete?'complete':'') ?>">
<a href="<?= icct_nms_h($stepperUrl) ?>" <?= $number===$stepperStage?'aria-current="step"':'' ?>><span class="mib-generation-step-icon" aria-hidden="true"></span><strong><?= $label ?></strong><small><?= $number ?>/4</small></a>
</div><?php endforeach; ?></nav>
