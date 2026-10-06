<?php
/**
 * Native SNMP and availability controls. Blank V3 secrets retain saved credentials on submit.
 */
?>
<fieldset class="radio-group">
    <legend>Select SNMP Version</legend>
    <?php foreach ([1 => 'SNMP V1', 2 => 'SNMP V2', 3 => 'SNMP V3'] as $version => $label): ?>
    <label><input type="radio" name="snmp_version" value="<?= $version ?>" <?= max(1, (int) $values['snmp_version']) === $version ? 'checked' : '' ?> required /><?= $label ?></label>
    <?php endforeach; ?>
</fieldset>
<div class="snmp-community">
<div class="protocol-grid cols-4">
    <?php
    icct_nms_input(
        'SNMP Community String',
        'snmp_community',
        !empty($readonly) ? '' : $values['snmp_community'],
        'text',
        'autocomplete="off" spellcheck="false"'
    );
    icct_nms_input(
        'SNMP Port',
        'snmp_port',
        $values['snmp_port'],
        'number',
        icct_nms_native_range_attributes('snmp_port')
    );
    icct_nms_input(
        'SNMP Timeout (ms)',
        'snmp_timeout',
        $values['snmp_timeout'],
        'number',
        icct_nms_native_range_attributes('snmp_timeout')
    );
    icct_nms_core_select('Maximum OIDs Per Get Request', 'max_oids', $values['max_oids']);
    ?>
</div>
<hr />
<div class="protocol-grid cols-4">
    <?php
    icct_nms_core_select(
        'Downed Device Detection',
        'availability_method',
        $values['availability_method']
    );
    icct_nms_core_select('Ping Method', 'ping_method', $values['ping_method']);
    icct_nms_input(
        'Ping Timeout (ms)',
        'ping_timeout',
        $values['ping_timeout'],
        'number',
        icct_nms_native_range_attributes('ping_timeout')
    );
    icct_nms_input(
        'Ping Retry Count',
        'ping_retries',
        $values['ping_retries'],
        'number',
        icct_nms_native_range_attributes('ping_retries')
    );
    ?>
</div>
</div>
<div class="snmp-v3-fields">
    <fieldset class="radio-group">
        <legend>SNMP Security Level</legend>
        <?php
        $level = empty($values['snmp_auth_protocol']) || $values['snmp_auth_protocol'] === '[None]' ? 'noAuthNoPriv' : (empty($values['snmp_priv_protocol']) || $values['snmp_priv_protocol'] === '[None]' ? 'authNoPriv' : 'authPriv');
        foreach (['noAuthNoPriv', 'authNoPriv', 'authPriv'] as $security): ?>
        <label><input type="radio" name="snmp_security_level" value="<?= $security ?>" <?= $level === $security ? 'checked' : '' ?> /><?= $security ?></label>
        <?php endforeach; ?>
    </fieldset>
    <div class="protocol-grid cols-4">
    <?php
    icct_nms_core_select(
        'SNMP Auth Protocol (V3)',
        'snmp_auth_protocol',
        $values['snmp_auth_protocol']
    );
    icct_nms_input('SNMP Username (V3)', 'snmp_username', $values['snmp_username']);
    icct_nms_input(
        'SNMP Password (V3)',
        'snmp_password',
        '',
        'password',
        'autocomplete="new-password" placeholder="Leave blank to retain saved credential"'
    );
    icct_nms_input('Re-enter SNMP Password (V3)', 'snmp_password_confirm', '', 'password', 'autocomplete="new-password"');
    icct_nms_core_select(
        'SNMP Privacy Protocol (V3)',
        'snmp_priv_protocol',
        $values['snmp_priv_protocol']
    );
    icct_nms_input(
        'SNMP Privacy Passphrase (V3)',
        'snmp_priv_passphrase',
        '',
        'password',
        'autocomplete="new-password" placeholder="Leave blank to retain saved credential"'
    );
    icct_nms_input('Re-enter SNMP Privacy Passphrase (V3)', 'snmp_priv_confirm', '', 'password', 'autocomplete="new-password"');
    icct_nms_input('SNMP Context (V3)', 'snmp_context', $values['snmp_context']);
    ?>
</div>

</div>
