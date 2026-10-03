<?php
// Parameter previews only. No form/action exists until a real backend adapter is implemented.
$pendingFields = [
    'netflow' => [
        ['Version','version','select',['5'=>'NetFlow v5','9'=>'NetFlow v9','10'=>'IPFIX']],
        ['Collector Address','collector','text','Collector hostname or IP address'],
        ['Collector Port','port','number','1–65535'],
        ['Active Timeout (Sec)','active_timeout','number','Exporter active-flow timeout'],
        ['Inactive Timeout (Sec)','inactive_timeout','number','Exporter inactive-flow timeout'],
        ['Sampling Rate','sampling_rate','number','Packets per sample'],
    ],
    'ntp' => [
        ['NTP Server','server','text','Server hostname or IP address'],
        ['Port','port','number','1–65535'],
        ['Version','version','select',['3'=>'NTP v3','4'=>'NTP v4']],
        ['Timeout (Sec)','timeout','number','Response timeout'],
        ['Poll Interval (Sec)','poll_interval','number','Time between checks'],
        ['Maximum Offset (ms)','maximum_offset','number','Offset threshold'],
    ],
    'tacacs' => [
        ['Server Address','server','text','Server hostname or IP address'],
        ['Port','port','number','1–65535'],
        ['Timeout (Sec)','timeout','number','Response timeout'],
        ['Retries','retries','number','Retry count'],
        ['Authentication Method','authentication','select',['ascii'=>'ASCII','pap'=>'PAP','chap'=>'CHAP']],
    ],
];
$pendingHelp = static function (string $text): void { ?>
<span class="field-info" tabindex="0" role="img" aria-label="Information about <?= icct_nms_h($text) ?>" data-tooltip="<?= icct_nms_h($text) ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span>
<?php }; ?>
<p class="pending-protocol-status" role="status">Not available yet. Configuration and Save will be enabled when the required package and backend integration are ready.</p>
<?php if ($key === 'syslog'): ?>
<div class="protocol-grid cols-2">
<?php foreach (['Severity'=>['0 — Emergency','1 — Alert','2 — Critical','3 — Error','4 — Warning','5 — Notice','6 — Informational','7 — Debug'], 'Facility'=>['0 — Kernel','1 — User','2 — Mail','3 — System daemon','4 — Security/authentication','5 — Syslog','6 — Line printer','7 — Network news','8 — UUCP','9 — Clock daemon','10 — Security/authentication','11 — FTP','12 — NTP','13 — Log audit','14 — Log alert','15 — Clock daemon','16 — Local 0','17 — Local 1','18 — Local 2','19 — Local 3','20 — Local 4','21 — Local 5','22 — Local 6','23 — Local 7']] as $caption=>$options): ?>
<div class="field"><span class="field-label"><?= $caption ?> <?php $pendingHelp($caption === 'Severity' ? 'Syslog severity levels: 0–7.' : 'Syslog facility codes: 0–23.'); ?></span>
<details class="pending-protocol-options"><summary>Select <?= strtolower($caption) ?></summary><div><?php foreach ($options as $option): ?><label><input type="checkbox" disabled> <?= icct_nms_h($option) ?></label><?php endforeach; ?></div></details></div>
<?php endforeach; ?>
</div>
<label class="field"><span class="field-label">Trigger Keywords / Match Strings <?php $pendingHelp('Text to match in incoming syslog messages.'); ?></span><textarea disabled rows="2" placeholder="Enter keywords or match strings"></textarea></label>
<?php else: ?>
<div class="protocol-grid cols-4">
<?php foreach ($pendingFields[$key] as [$caption,$name,$type,$options]): ?>
<label class="field"><span class="field-label"><?= $caption ?> <?php $pendingHelp(is_array($options) ? 'Choose '.strtolower($caption).' for '.$label.'.' : $options.'.'); ?></span>
<?php if ($type === 'select'): ?><select disabled aria-label="<?= icct_nms_h($caption) ?>"><option value="">Select <?= strtolower($caption) ?></option><?php foreach ($options as $value=>$option): ?><option value="<?= icct_nms_h($value) ?>"><?= icct_nms_h($option) ?></option><?php endforeach; ?></select>
<?php else: ?><input disabled type="<?= $type ?>" placeholder="<?= icct_nms_h($options) ?>" aria-label="<?= icct_nms_h($caption) ?>"><?php endif; ?></label>
<?php endforeach; ?>
</div>
<?php if ($key === 'tacacs'): ?><p class="pending-protocol-status">Shared secret and authentication credentials will be configured per device.</p><?php endif; ?>
<?php endif; ?>
<div class="protocol-actions"><button type="button" class="button primary" disabled>Save</button></div>
