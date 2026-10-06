<section id="device-ports" class="device-ports" hidden>
<p id="port-error" role="status" hidden></p>
<?php $portSettings=$id?icct_nms_port_monitoring($id):['enabled'=>true,'ports'=>[]]; ?>
<form method="post" id="port-monitor-form">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="ports">
<input type="hidden" name="port_settings" value="<?= icct_nms_h(json_encode($portSettings,JSON_THROW_ON_ERROR)) ?>">
<div class="field port-monitor-switch"><span class="field-label">Port Monitoring <span class="field-info" tabindex="0" data-tooltip="Enable the saved link-status alarms for this device." aria-label="About Port Monitoring">ⓘ</span></span>
<label class="switch-label"><input class="switch-input" type="checkbox" id="port-monitor-enabled" <?= $portSettings['enabled']?'checked':'' ?> aria-label="Port Monitoring"><span class="switch-track"></span><span id="port-monitor-label"><?= $portSettings['enabled']?'Yes':'No' ?></span></label></div>
<div id="port-rows" class="port-config-rows"></div>
</form>
<script type="application/json" id="port-observations"><?= json_encode($id?icct_backend_ports_view($old):['ports'=>[],'fresh'=>false,'collected'=>0,'source'=>'','message'=>''],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</section>
