<?php /** Protocol presets are rendered through shared fields to keep device editors consistent. */
$presetMode = true;
$wizard = true;
$id = 0;
$protocolPresets = icct_nms_protocol_presets();
$failedProtocol = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {
    $key = $_POST['preset_protocol'] ?? '';
    if (
        is_string($key) &&
        in_array($key, ['cdp', 'lldp', 'snmp', 'ssh', 'serial', 'syslog', 'netflow'], true)
    ) {
        $protocolPresets[$key] = array_intersect_key(
            array_filter($_POST, 'is_scalar'),
            array_flip(icct_nms_protocol_preset_fields($key)),
        );
        $failedProtocol = $key;
    }
}
$protocolDraft = ['cdp', 'lldp', 'snmp', 'ssh', 'serial', 'syslog', 'netflow', 'ntp', 'tacacs'];
$host = array_replace(icct_nms_defaults(), $protocolPresets['snmp'] ?? []);
$host['snmp_community'] = '';
$host['description'] = 'Protocol defaults';
$discovery = [];
$ssh = $protocolPresets['ssh'] ?? [];
$serial = [];
$connections = []; ?>
<section
    id="protocol-defaults"
    class="protocol-defaults">
    <?php require __DIR__ . '/protocol.php'; ?>
</section>
<script
    type="application/json"
    id="protocol-preset-profiles"><?= json_encode(
        [
            'saved' => icct_nms_protocol_presets(),
            'profiles' => icct_nms_protocol_profiles(),
            'variants' => icct_nms_protocol_variants(),
            'fields' => array_combine(
                ['snmp', 'ssh', 'serial', 'syslog', 'cdp', 'lldp'],
                array_map('icct_nms_protocol_preset_fields', [
                    'snmp',
                    'ssh',
                    'serial',
                    'syslog',
                    'cdp',
                    'lldp',
                ]),
            ),
        ],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
<?php $wizard = false; ?>
