<?php
$collectionIds = array_flip(array_intersect_key($collectionNames['native_ids'], $collectionChoices));
?>
<label class="field">
    <span class="field-label">Collection Method</span>
    <span class="select-wrap">
        <select name="<?= icct_nms_h($prefix . '[collection_method]') ?>">
            <?php if (!isset($collectionChoices[$selectedCollection])): ?>
                <option value="" selected disabled>Choose an available method</option>
            <?php endif; ?>
            <?php foreach ($collectionNames['catalog'] as $methodId => $methodName):
                $methodValue = $collectionIds[$methodId] ?? null;
                ?>
                <option value="<?= icct_nms_h($methodValue ?? ('native-' . $methodId)) ?>"
                    <?= $methodValue === null ? 'disabled' : ($methodValue === $selectedCollection ? 'selected' : '') ?>><?= icct_nms_h($methodName) ?></option>
            <?php endforeach; ?>
        </select>
    </span>
    <small>Indexed SNMP requires a table column. Other methods are configured in Cacti.</small>
</label>
