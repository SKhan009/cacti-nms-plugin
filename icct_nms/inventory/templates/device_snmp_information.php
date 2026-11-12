<?php
[$snmpInformation, $snmpInformationMessage] = icct_nms_device_snmp_information(
    $old,
    $status,
    $snmpInformationEnabled,
    $scanResult['evidence'] ?? [],
);
?>
<section class="device-snmp-information" aria-labelledby="device-snmp-information-title">
    <h2 id="device-snmp-information-title">SNMP Information</h2>
    <p><?= icct_nms_h($snmpInformationMessage) ?></p>
    <dl class="device-detail-grid">
        <?php foreach ($snmpInformation as $label => $value): ?>
            <div>
                <dt><?= icct_nms_h($label) ?></dt>
                <dd><?= icct_nms_h($value === '' ? '—' : $value) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
</section>
