<?php
$netflowRules = icct_nms_netflow_rules($id);
$netflowMetrics = icct_nms_netflow_rule_metrics();
$netflowRuleHelp = static function (string $headingHelpText): void {
    require __DIR__ . '/../../../shared/templates/heading_help.php';
};
?>
<form id="netflow-fault-form" method="post">
    <?php icct_nms_token(); ?>
    <input type="hidden" name="action" value="netflow_faults">
    <input type="hidden" name="netflow_revision" value="<?= icct_nms_h(icct_nms_netflow_rule_revision($netflowRules)) ?>">
    <input type="hidden" name="netflow_rules" value="<?= icct_nms_h(json_encode($netflowRules, JSON_THROW_ON_ERROR)) ?>">
    <div class="fault-workspace">
        <aside class="fault-alarm-sidebar" aria-label="Traffic metrics">
            <section class="fault-snmp-list">
                <h3>Traffic Metrics</h3>
                <?php foreach ($netflowMetrics as $key => [$label, $unit]): ?>
                    <button type="button" class="fault-oid-link" data-netflow-metric="<?= icct_nms_h($key) ?>"
                        data-metric-label="<?= icct_nms_h("$label ($unit)") ?>"
                        aria-pressed="false">
                        <span><?= icct_nms_h($label) ?></span>
                        <small data-netflow-rule-count data-unit="<?= icct_nms_h($unit) ?>"><?= icct_nms_h($unit) ?></small>
                    </button>
                <?php endforeach; ?>
            </section>
        </aside>
        <div class="fault-alarm-editor">
            <section class="fault-saved-alarms" aria-label="Custom NetFlow rules">
                <h3>Custom NetFlow rules</h3>
                <ul data-netflow-rule-list hidden></ul>
                <p class="fault-oid-empty" data-netflow-rule-empty>Select a metric and choose Add Rule. Each rule evaluates the last five minutes of traffic.</p>
            </section>
            <div data-netflow-rule-editors></div>
            <section class="fault-saved-alarms" aria-labelledby="netflow-built-in-title">
                <h3 id="netflow-built-in-title">Built-in NetFlow anomaly rules</h3>
                <p class="fault-oid-empty">These rules have built-in detection methods and severities. Set their scan, SYN rate, and bandwidth thresholds in Protocol Config.</p>
                <div class="site-table-wrap">
                    <table class="site-table netflow-built-in-table">
                        <thead>
                            <tr>
                                <th scope="col">Rule</th>
                                <th scope="col">Severity</th>
                                <th scope="col">Trigger</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $scan = $netflowFaultConfig ? (int) $netflowFaultConfig['scan_threshold'] : 'the configured number of';
                            $syn = $netflowFaultConfig ? (int) $netflowFaultConfig['syn_pps_threshold'] . ' packets/s' : 'the configured rate';
                            $bandwidth = $netflowFaultConfig ? (int) $netflowFaultConfig['bandwidth_limit_mbps'] . ' Mbps' : 'the bandwidth limit';
                            foreach ([
                                ['Network scan', 'Major', "One source reaches at least $scan distinct destination IPs or ports."],
                                ['Possible worm-like traffic', 'Major', "One source reaches at least $scan distinct destination IPs on SMB or RPC ports."],
                                ['Possible SYN flood / DoS', 'Critical', "One source sends TCP SYN packets without ACK at or above $syn."],
                                ['Possible distributed SYN flood', 'Critical', "Multiple sources send TCP SYN packets without ACK to one destination. Together they reach $syn, while each stays below it for that destination."],
                                ['Bandwidth overuse', 'Major', "Traffic reaches $bandwidth or exceeds three times the recent baseline when sufficient traffic history exists."],
                            ] as [$rule, $severity, $trigger]): ?>
                                <tr>
                                    <th scope="row"><?= icct_nms_h($rule) ?></th>
                                    <td><?= icct_nms_severity_badge($severity) ?></td>
                                    <td><?= icct_nms_h($trigger) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
    <template data-netflow-rule-template>
        <div class="passive-rule" role="group" aria-label="NetFlow rule">
            <label class="field">
                <span class="field-label">Alarm Name <?php $netflowRuleHelp('Enter the title operators will see when this rule raises an alarm.'); ?></span>
                <input type="text" data-field="name" aria-label="Alarm name" maxlength="100" placeholder="Example: High traffic from a device">
            </label>
            <label class="field">
                <span class="field-label">Traffic Metric <?php $netflowRuleHelp('This metric comes from the sidebar. Select a metric there to open its rules or add a new rule.'); ?></span>
                <input type="text" data-netflow-metric-display aria-label="Traffic metric" readonly>
            </label>
            <label class="field">
                <span class="field-label">Threshold Value <?php $netflowRuleHelp('The alarm triggers when the five-minute reading reaches or exceeds this value. Use the unit shown next to the traffic metric.'); ?></span>
                <input type="text" inputmode="decimal" data-field="threshold" aria-label="Threshold value" placeholder="Example: 100">
            </label>
            <label class="field">
                <span class="field-label">Alarm Severity <?php $netflowRuleHelp('Choose how urgent the alarm should appear in the Alarms console.'); ?></span>
                <span class="select-wrap">
                    <select data-field="severity" aria-label="Alarm severity">
                        <?php foreach (['Critical', 'Major', 'Minor', 'Warning', 'Information'] as $severity): ?>
                            <option><?= icct_nms_h($severity) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </label>
            <label class="field">
                <span class="field-label">Source IP (optional)</span>
                <input type="text" data-field="src_addr" maxlength="45" placeholder="Any source">
            </label>
            <label class="field">
                <span class="field-label" data-netflow-destination-label>Destination IP (optional)</span>
                <input type="text" data-field="dst_addr" maxlength="45" placeholder="Any destination">
            </label>
            <label class="field">
                <span class="field-label">Destination Port (optional)</span>
                <input type="text" inputmode="numeric" data-field="dst_port" maxlength="5" placeholder="Any port">
            </label>
            <p class="fault-rule-actions" data-netflow-distributed-help hidden>Enter the destination IP to monitor. The rule totals SYN packets from two or more source IPs.</p>
            <div class="fault-rule-actions">
                <label class="field fault-enabled-field">
                    <span class="field-label">Enabled <?php $netflowRuleHelp('Save with this checked to let the rule raise alarms. Clear it to pause the rule.'); ?></span>
                    <input type="checkbox" data-field="enabled" aria-label="Enabled">
                </label>
                <button type="button" class="button" data-netflow-rule-remove>Remove</button>
            </div>
        </div>
    </template>
</form>
