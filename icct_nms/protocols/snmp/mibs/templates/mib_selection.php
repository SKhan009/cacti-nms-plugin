<?php $mibSelecting=true;$management=true; ?>
<section class="mib-generation mib-tree-step" aria-label="MIB OID selection">
<header class="mib-generation-heading"><h1>Automatic MIB Configuration (<?= icct_nms_h($selectionBundle['name']) ?>)</h1><a class="button" href="protocols/snmp/mibs/controllers/mib_repository.php">Cancel</a></header>
<?php require __DIR__.'/mib_stepper.php'; ?>
<form method="post" id="mibSelectionForm" action="protocols/snmp/mibs/controllers/mib_repository.php">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="select_oids"><input type="hidden" name="bundle_id" value="<?= icct_nms_h($selectionBundle['id']) ?>"><input type="hidden" name="review_payload" id="mibSelectionPayload" value="">
<?php if(isset($selectionReadyToken)): ?><input type="hidden" name="ready_token" value="<?= icct_nms_h($selectionReadyToken) ?>"><?php endif; ?>
<?php require __DIR__.'/mib_browser.php'; ?>
<?php if(isset($selectionDraft)): ?><script type="application/json" id="mibRestoredSelection"><?= json_encode(array_keys($selectionDraft['review']['values']['selected']),JSON_HEX_TAG|JSON_THROW_ON_ERROR) ?></script><?php endif; ?>
<footer class="mib-generation-review-footer"><span id="mibTreeSelectionCount" role="status">0 OIDs selected</span><button class="button primary" type="submit" id="mibSelectionNext" disabled>Next →</button></footer>
</form></section>
