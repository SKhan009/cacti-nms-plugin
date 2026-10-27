<?php $mibSelecting=true;$management=true; ?>
<section class="mib-generation mib-tree-step" aria-label="MIB OID selection">
<header class="mib-generation-heading"><h1>Automatic MIB Configuration (<?= icct_nms_h($selectionBundle['name']) ?>)</h1><a class="button" href="protocols/snmp/mibs/controllers/mib_repository.php">Cancel</a></header>
<nav class="mib-generation-stepper" aria-label="MIB generation steps"><?php foreach([1=>'MIB Tree / OID Selection',2=>'Data Source Generation',3=>'Graph Template Generation',4=>'Device Template Generation'] as $number=>$label): ?><div class="<?= $number===1?'active':'' ?>" <?= $number===1?'aria-current="step"':'' ?>><span class="mib-generation-step-icon" aria-hidden="true"></span><strong><?= $label ?></strong><small><?= $number ?>/4</small></div><?php endforeach; ?></nav>
<form method="post" id="mibSelectionForm" action="protocols/snmp/mibs/controllers/mib_repository.php">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="select_oids"><input type="hidden" name="bundle_id" value="<?= icct_nms_h($selectionBundle['id']) ?>"><input type="hidden" name="review_payload" id="mibSelectionPayload" value="">
<?php require __DIR__.'/mib_browser.php'; ?>
<footer class="mib-generation-review-footer"><span id="mibTreeSelectionCount" role="status">0 OIDs selected</span><button class="button primary" type="submit" id="mibSelectionNext" disabled>Next →</button></footer>
</form></section>
