<!-- FCAPS panels retain separate measurement settings while sharing the parent form submission. -->
<section
    id="device-fcaps"
    class="device-fcaps"
    hidden>
    <div
        class="fcaps-tabs"
        role="tablist"
        aria-label="FCAPS">
        <?php foreach (
            [
                'fault' => 'Fault',
                'configuration' => 'Configuration',
                'accounting' => 'Accounting',
                'performance' => 'Performance',
                'security' => 'Security',
            ] as $key => $label
        ): ?>
            <button
                type="button"
                role="tab"
                id="fcaps-tab-<?= $key ?>"
                aria-controls="fcaps-<?= $key ?>"
                aria-selected="<?= $key === 'fault' ? 'true' : 'false' ?>"
                tabindex="<?= $key === 'fault' ? '0' : '-1' ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </div>
    <section
        id="fcaps-fault"
        role="tabpanel"
        aria-labelledby="fcaps-tab-fault">
        <fieldset class="fault-source-options">
            <legend>Fault Source <span
                    class="field-info"
                    tabindex="0"
                    role="img"
                    aria-label="Information about Fault Source"
                    data-tooltip="Choose a measurement threshold or an alarm rule for received device events.">
                    <svg
                        viewBox="0 0 20 20"
                        aria-hidden="true">
                        <circle
                            cx="10"
                            cy="10"
                            r="8" />
                        <path d="M10 9v5M10 6v.5" />
                    </svg>
                </span>
            </legend>
            <label>
                <input
                    type="radio"
                    name="alarm_source_view"
                    value="rrd"
                    checked> Cacti Data Source
            </label>
            <label>
                <input
                    type="radio"
                    name="alarm_source_view"
                    value="snmp"> SNMP
            </label>
            <label>
                <input
                    type="radio"
                    name="alarm_source_view"
                    value="ntp"> NTP
            </label>
            <label>
                <input type="radio" name="alarm_source_view" value="syslog" data-passive-source> Syslog
            </label>
            <label>
                <input type="radio" name="alarm_source_view" value="netflow"> NetFlow / IPFIX
            </label>
        </fieldset>
        <fieldset id="snmp-fault-rule-type" class="fault-rule-type-options" hidden>
            <legend>SNMP rule type</legend>
            <label class="check-row">
                <input type="radio" name="snmp_fault_view" value="snmp" checked> OID polling thresholds
            </label>
            <label class="check-row">
                <input type="radio" name="snmp_fault_view" value="trap"> Trap and inform rules
            </label>
        </fieldset>
        <div class="fcaps-form-actions">
            <button type="button" class="button" form="fault-form" id="add-fault-rule">+ Add Threshold</button>
            <button type="submit" class="button primary" form="fault-form">Save</button>
            <button type="button" class="button" form="passive-fault-form" data-passive-add>+ Add Rule</button>
            <button type="submit" class="button primary" form="passive-fault-form">Save</button>
            <button type="button" class="button" form="netflow-fault-form" data-netflow-action id="add-netflow-fault" hidden>+ Add Rule</button>
            <button type="submit" class="button primary" form="netflow-fault-form" data-netflow-action hidden>Save</button>
        </div>
        <form
            id="fault-form"
            method="post">
            <?php icct_nms_token(); ?>
            <input
                type="hidden"
                name="action"
                value="faults">
            <input
                type="hidden"
                name="fault_rules"
                id="fault-rules-value">
            <div class="fault-workspace">
                <aside
                    class="fault-alarm-sidebar"
                    aria-label="Measurements">
                    <section id="fault-graph-section">
                        <h3>Graph Templates</h3>
                        <div id="fault-alarm-navigation"></div>
                    </section>
                    <section
                        id="fault-oid-section"
                        hidden>
                        <section class="fault-snmp-list">
                            <h3>SNMP OIDs</h3>
                            <div id="fault-oid-navigation" class="fault-snmp-oid-list"></div>
                        </section>
                        <section class="fault-snmp-list">
                            <h3>MIB Repository</h3>
                            <div id="fault-mib-navigation" class="fault-snmp-oid-list"></div>
                        </section>
                    </section>
                    <section
                        id="fault-ntp-section"
                        hidden>
                        <h3>NTP Parameters</h3>
                        <div id="fault-ntp-navigation"></div>
                    </section>
                </aside>
                <div class="fault-alarm-editor">
                    <section class="fault-saved-alarms" aria-label="Alarm rules">
                        <div class="fault-alarm-list-heading">
                            <h3>Alarm rules</h3>
                            <a id="fault-mib-repository-edit" class="button" target="_blank" rel="noopener" hidden>Edit in MIB Repository</a>
                        </div>
                        <ul id="fault-saved-alarm-list"></ul>
                    </section>
                    <section class="fault-saved-alarms" id="fault-mib-preset-section" hidden>
                        <h3>MIB Fault Presets <?php $ntpHelpText = 'Select saved MIB fault presets associated with this device. Each preset retains its native Cacti data source. Clear a selection to disable its rule or use Edit thresholds to change it.';
                        require __DIR__ . '/../../protocols/ntp/templates/help.php'; ?></h3>
                        <div id="fault-mib-presets" aria-label="Select MIB fault presets"></div>
                    </section>
                    <p
                        id="fault-alarm-empty"
                        hidden>Select a data source, OID or NTP parameter to configure thresholds.</p>
                    <div id="fault-rule-list"></div>
                </div>
            </div>
        </form>
        <?php require __DIR__ . '/passive_faults.php'; ?>
        <?php $netflowFaultConfig = icct_nms_netflow_assignment($id); ?>
        <section id="fault-netflow-summary" hidden aria-label="NetFlow fault rules">
            <p><?php if (!$netflowFaultConfig): ?>
                    Add a NetFlow exporter in Protocol Config to evaluate these rules for this device.
                <?php elseif (!icct_backend_protocol_enabled($id, 'netflow')): ?>
                    NetFlow collection is disabled for this device. Enable it in Protocol Config to evaluate these rules.
                <?php else: ?>
                    The collector evaluates five-minute traffic windows. Matching findings appear in the Alarms console.
                <?php endif; ?></p>
            <?php require __DIR__ . '/../../protocols/netflow/templates/fault_rules.php'; ?>
        </section>
        <?php $faultCatalogue = icct_nms_fault_catalogue();
        $faultRules = icct_nms_fault_rules($id);
        $faultObservations = $id ? icct_nms_fault_observations($old) : []; ?>
        <script
            type="application/json"
            id="fault-current-readings"><?= json_encode(
                $faultObservations,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
            ) ?></script>
    </section>
    <section
        id="fcaps-configuration"
        role="tabpanel"
        aria-labelledby="fcaps-tab-configuration"
        hidden>
        <h3>Configuration summary <span
                class="field-info section-help"
                tabindex="0"
                role="img"
                aria-label="Read-only summary of the current form values, including unsaved changes. Edit settings in Basic Information and Protocol Config. Changes are saved for this device only."
                data-tooltip="Read-only summary of the current form values, including unsaved changes. Edit settings in Basic Information and Protocol Config. Changes are saved for this device only.">ⓘ</span>
        </h3>
        <dl
            id="configuration-device-summary"
            class="configuration-summary"></dl>
        <h3>Selected protocols</h3>
        <div id="configuration-protocol-summary"></div>
        <h3>NMS settings backups and change history <span
                class="field-info section-help"
                tabindex="0"
                role="img"
                aria-label="Backups contain saved device settings, protocol parameters, graph associations and fault rules. Credentials are excluded. These are NMS settings snapshots, not configurations retrieved from the device."
                data-tooltip="Backups contain saved device settings, protocol parameters, graph associations and fault rules. Credentials are excluded. These are NMS settings snapshots, not configurations retrieved from the device.">ⓘ</span>
        </h3>
        <span
            class="field-info section-help"
            tabindex="0"
            role="img"
            aria-label="Backup history information"
            data-tooltip="<?= icct_nms_h($id
                ? 'A snapshot is recorded automatically after settings are saved. You can also create a manual backup of saved settings.'
                : 'Backup and change history are enabled. Save this device to create its first snapshot.') ?>">ⓘ</span>
        <button
            type="button"
            class="button primary"
            id="configuration-backup"
            <?= $id ? '' : 'disabled' ?>>Back Up Saved Settings</button>
        <p
            id="configuration-backup-result"
            role="status"></p>
        <?php $configurationHistory = icct_nms_configuration_history($id); ?>
        <div class="site-table-wrap">
            <table class="site-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Changes / Backup</th>
                    </tr>
                </thead>
                <tbody id="configuration-history-rows">
                    <?php
                            foreach ($configurationHistory as $event): ?>
                        <tr>
                            <td><?= icct_nms_h($event['time']) ?></td>
                            <td><?= icct_nms_h($event['user'] ?: 'User ' . $event['user_id']) ?></td>
                            <td><?= icct_nms_h($event['action']) ?></td>
                            <td>
                                <button
                                    type="button"
                                    class="button configuration-download"
                                    data-backup-id="<?= icct_nms_h($event['id']) ?>">Download Backup</button>
                                <details>
                                    <summary><?= count($event['changes']) ?> changed settings</summary>
                                    <table class="site-table">
                                        <thead>
                                            <tr>
                                                <th>Setting</th>
                                                <th>Before</th>
                                                <th>After</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (
                                                $event['changes'] as $field => $change
                                            ): ?>
                                                <tr>
                                                    <td><?= icct_nms_h($field) ?></td>
                                                    <td><?= icct_nms_h($change['before'] ?? 'Not set') ?></td>
                                                    <td><?= icct_nms_h($change['after'] ?? 'Not set') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach;
        if (!$configurationHistory): ?>
                        <tr>
                            <td colspan="4"><?= $id
                                ? 'No snapshots yet. Create a backup or save changed settings to start history.'
                                : 'History appears after this device is saved.' ?></td>
                        </tr>
                    <?php endif;
        ?>
                </tbody>
            </table>
        </div>
        <span
            class="field-info section-help"
            tabindex="0"
            role="img"
            aria-label="Showing the latest 100 snapshots. Earlier snapshots remain stored. Changes to excluded credentials are not included in this history."
            data-tooltip="Showing the latest 100 snapshots. Earlier snapshots remain stored. Changes to excluded credentials are not included in this history.">ⓘ</span>
        <script
            type="application/json"
            id="configuration-backups"><?= json_encode(
                $configurationHistory,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
            ) ?></script>
    </section>
    <section
        id="fcaps-accounting"
        role="tabpanel"
        aria-labelledby="fcaps-tab-accounting"
        hidden>
        <h3>Accounting</h3>
        <p>Accounting policies and usage reports are not configured for this device yet.</p>
    </section>
    <section
        id="fcaps-performance"
        role="tabpanel"
        aria-labelledby="fcaps-tab-performance"
        hidden>
        <h3>Performance <span
                class="field-info section-help"
                tabindex="0"
                role="img"
                aria-label="Selected graph templates provide performance measurements. View graph definitions in Graphs and interface status in Port Config."
                data-tooltip="Selected graph templates provide performance measurements. View graph definitions in Graphs and interface status in Port Config.">ⓘ</span>
        </h3>
        <ul>
            <?php foreach (
                $graphAssociations as $template
            ): ?>
                <li><?= icct_nms_h($template['name']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section
        id="fcaps-security"
        role="tabpanel"
        aria-labelledby="fcaps-tab-security"
        hidden>
        <h3>Security</h3>
        <p>Security monitoring policies are not configured yet. Manage device credentials and
            enabled protocols in Protocol Config.</p>
    </section>
    <script
        type="application/json"
        id="fault-catalogue"><?= json_encode(
            $faultCatalogue,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?></script>
    <?php $ntpFaultCurrent = icct_nms_ntp_current($id);
    $ntpFaultAssignment = icct_nms_ntp_assignment($id); ?>
    <script
        type="application/json"
        id="fault-ntp-parameters"><?= json_encode(
            icct_nms_ntp_fault_parameters(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
    <script
        type="application/json"
        id="fault-ntp-readings"><?= json_encode(
            [
                'values' => icct_nms_ntp_fault_values($ntpFaultCurrent),
                'attempted_epoch' => $ntpFaultCurrent['attempted_epoch'],
                'stale_seconds' => $ntpFaultAssignment['stale_seconds'] ?? null,
                'status' => $ntpFaultCurrent['status'],
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
    <script
        type="application/json"
        id="fault-mib-preset-values"><?= json_encode(
            icct_nms_fault_presets($id),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
    <script
        type="application/json"
        id="saved-fault-rules"><?= json_encode($faultRules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
<script
    type="application/json"
    id="fault-parameters"><?= json_encode(
        icct_nms_fault_parameters($id),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
    ) ?></script>
