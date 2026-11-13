<?php
$passiveRules = $id ? icct_nms_passive_fault_rules($id) : [];
$trapChoices = [
    '1.3.6.1.6.3.1.1.5.1' => 'coldStart',
    '1.3.6.1.6.3.1.1.5.2' => 'warmStart',
    '1.3.6.1.6.3.1.1.5.3' => 'linkDown',
    '1.3.6.1.6.3.1.1.5.4' => 'linkUp',
    '1.3.6.1.6.3.1.1.5.5' => 'authenticationFailure',
    '1.3.6.1.6.3.1.1.5.6' => 'egpNeighborLoss',
];
$repositoryTraps = [];
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_repository_service.php';
foreach (icct_mib_list() as $bundle) {
    if (empty($bundle['object_parts'])) {
        continue;
    }
    foreach (icct_mib_objects($bundle) as $object) {
        if (in_array($object['kind'], ['NOTIFICATION-TYPE', 'TRAP-TYPE'], true) && $object['base_oid'] !== '') {
            $oid = ltrim($object['base_oid'], '.');
            if (!isset($trapChoices[$oid])) {
                $trapChoices[$oid] = $object['symbol'];
                $repositoryTraps[$oid] = true;
            }
        }
    }
}
$trapPayloadChoices = [];
if ($id) {
    $receivedTraps = db_fetch_assoc_prepared(
        "SELECT DISTINCT program,JSON_KEYS(raw_message) AS payload_oids
        FROM plugin_icct_nms_syslog_events WHERE host_id=? AND event_origin='trap' AND JSON_VALID(raw_message)",
        [(int) $id],
    );
    foreach ($receivedTraps ?: [] as $trap) {
        $oid = $trap['program'];
        $trapChoices[$oid] ??= 'Received notification';
        foreach (json_decode($trap['payload_oids'] ?? '[]', true) ?: [] as $fieldOid) {
            $trapPayloadChoices[$oid][$fieldOid] = $fieldOid;
        }
    }
}
foreach ($passiveRules as $rule) {
    if ($rule['source'] === 'trap') {
        $trapChoices[$rule['oid']] ??= 'Saved rule';
        if (!empty($rule['varbind'])) {
            $trapPayloadChoices[$rule['oid']][$rule['varbind']] = $rule['varbind'];
        }
    }
}
natcasesort($trapChoices);
$trapGroups = [
    'SNMP OIDs' => array_diff_key($trapChoices, $repositoryTraps),
    'MIB Repository' => array_intersect_key($trapChoices, $repositoryTraps),
];
$passiveHelp = static function (string $headingHelpText): void {
    require __DIR__ . '/../../shared/templates/heading_help.php';
};
?>
<form id="passive-fault-form" method="post" hidden>
    <?php icct_nms_token(); ?>
    <input type="hidden" name="action" value="passive_faults">
    <input
        type="hidden"
        name="passive_rules">
    <script type="application/json" id="saved-passive-rules"><?= json_encode(
        $passiveRules,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
    <script type="application/json" id="passive-trap-payload-oids"><?= json_encode(
        array_map('array_values', $trapPayloadChoices),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
        <section
            data-passive-panel="trap"
            class="fault-workspace"
            hidden>
                <aside
                    class="fault-alarm-sidebar"
                    aria-label="Notification OIDs">
                    <div
                        class="fault-snmp-sections"
                        role="radiogroup"
                        aria-label="Notification OID">
                        <?php foreach ($trapGroups as $group => $choices): ?>
                            <section class="fault-snmp-list">
                                <h3><?= icct_nms_h($group) ?></h3>
                                <div class="fault-snmp-oid-list">
                                    <?php if (!$choices): ?>
                                        <p class="fault-oid-empty">No trap OIDs in this list.</p>
                                    <?php endif; ?>
                                    <?php foreach ($choices as $oid => $choice): ?>
                                        <label class="fault-oid-link fault-notification-choice">
                                            <input
                                                type="radio"
                                                name="trap_oid_view"
                                                value="<?= icct_nms_h($oid) ?>"
                                                data-trap-choice>
                                            <span class="fault-oid-heading">
                                                <span data-trap-name><?= icct_nms_h($choice) ?></span>
                                                <span
                                                    class="fault-oid-rule-count"
                                                    data-trap-rule-count></span>
                                            </span>
                                            <small><?= icct_nms_h($oid) ?></small>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </aside>
            <div class="fault-alarm-editor">
                <section
                    class="fault-saved-alarms"
                    aria-label="Alarm rules">
                    <h3>Alarm rules</h3>
                    <ul data-passive-alarm-list></ul>
                </section>
                <div data-passive-rules></div>
                <p
                    class="fault-alarm-empty"
                    data-passive-empty>Select a notification or use Add Rule for the selected OID.</p>
                <template>
                    <div
                        class="passive-rule"
                        role="group"
                        aria-label="SNMP notification rule">
                        <label class="field">
                            <span class="field-label">Alarm Name <?php $passiveHelp('Enter the title operators should see when the alarm is raised.'); ?></span>
                            <input
                                data-field="name"
                                aria-label="Alarm name"
                                maxlength="160"
                                required>
                        </label>
                        <label class="field">
                            <span class="field-label">Trap OID <?php $passiveHelp('Identifies the notification that triggers this rule. This value comes from the selected item in the sidebar. Select another item to work with a different OID.'); ?></span>
                            <input
                                data-field="oid"
                                aria-label="Trap OID"
                                readonly>
                        </label>
                        <label class="field">
                            <span class="field-label">Payload field OID <?php $passiveHelp('Choose a field from a trap already received from this device, such as the interface index. Choose Any payload to match every notification with the selected Trap OID.'); ?></span>
                            <span class="select-wrap"><select data-field="varbind" aria-label="Payload field OID"><option value="">Any payload</option></select></span>
                        </label>
                        <label class="field">
                            <span class="field-label">Expected field value <?php $passiveHelp('Copy the exact reported value for the selected field from SNMP Trap History. For example, enter 7 if the interface index is reported as 7. This field is unavailable when Any payload is selected.'); ?></span>
                            <input
                                data-field="value"
                                aria-label="Expected field value"
                                maxlength="160">
                        </label>
                        <label class="field">
                            <span class="field-label">Incident Group (optional) <?php $passiveHelp('Leave blank for an independent rule. To group rules for the same incident, enter a shared label such as port-7-down and give them the same alarm name and severity. Use different labels for different ports.'); ?></span>
                            <input
                                data-field="group"
                                aria-label="Incident Group (optional)"
                                maxlength="64">
                        </label>
                        <label class="field">
                            <span class="field-label">Severity <?php $passiveHelp('Choose how urgent this alarm should appear in NMS. For example, choose Major for a serious equipment fault or Information for a routine notice.'); ?></span>
                            <span class="select-wrap"><select data-field="severity" aria-label="Severity" required>
                                <option value="">Select severity</option>
                                <?php foreach (['Critical', 'Major', 'Minor', 'Warning', 'Information'] as $severity): ?>
                                    <option><?= $severity ?></option>
                                <?php endforeach; ?>
                            </select></span>
                        </label>
                        <div class="fault-rule-actions">
                            <label class="field fault-enabled-field">
                                <span class="field-label">Enabled <?php $passiveHelp('Check this and save to let the rule raise alarms for future matching events. Leave it unchecked to save the rule as disabled.'); ?></span>
                                <input
                                    type="checkbox"
                                    data-field="enabled"
                                    aria-label="Enabled">
                            </label>
                            <button
                                class="button"
                                type="button"
                                data-passive-remove>Remove</button>
                        </div>
                    </div>
                </template>
            </div>
        </section>
</form>
