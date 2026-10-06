<?php
/** Syslog filters inherit presets and remain independently editable per device. */
$syslog=is_array($syslog ?? null)?$syslog:[];
$syslogFilters=icct_backend_syslog_filters($syslog);
$syslogHelp=static function($label,$help){ ?><span class="field-info" tabindex="0" role="img" aria-label="Information about <?= icct_nms_h($label) ?>" data-tooltip="<?= icct_nms_h($help) ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span><?php };
?>
<?php if(empty($presetMode)): ?>
<input type="hidden" name="source_address" value="<?= icct_nms_h($syslog['source_address'] ?? $host['hostname']) ?>">
<input type="hidden" name="transport" value="<?= icct_nms_h($syslog['transport'] ?? 'both') ?>">
<?php endif; ?>
<div class="protocol-grid cols-2 syslog-settings">
<?php foreach(['severity_codes'=>['Severity',icct_backend_syslog_severity_labels(),'Choose exactly which severity levels to accept. 0 is most urgent; 7 is Debug.'],'facility_codes'=>['Facility',icct_backend_syslog_facility_labels(),'Choose the message categories to accept. All 24 facilities are selected by default.']] as $field=>[$caption,$options,$help]): ?>
<div class="field syslog-selection" data-syslog-selection="<?= $field ?>">
<span class="field-label"><?= $caption ?> <?php $syslogHelp($caption,$help); ?></span>
<input type="hidden" name="<?= $field ?>" value="<?= icct_nms_h(json_encode($syslogFilters[$field])) ?>">
<details class="syslog-select-menu"><summary aria-label="Select <?= strtolower($caption) ?>"><span class="syslog-selected-count"><?= count($syslogFilters[$field]) ?></span><span>Options selected</span><span class="syslog-select-chevron" aria-hidden="true"></span></summary>
<div class="syslog-select-options">
<button type="button" class="syslog-select-all">Select all</button><button type="button" class="syslog-select-none">Clear</button>
<?php foreach($options as $code=>$caption): ?><label class="check-row"><input type="checkbox" value="<?= $code ?>" <?= in_array($code,$syslogFilters[$field],true)?'checked':'' ?> aria-label="<?= icct_nms_h($code.' — '.$caption) ?>"> <?= icct_nms_h($code.' — '.$caption) ?></label><?php endforeach; ?>
</div></details>
</div>
<?php endforeach; ?>
</div>
<div class="field syslog-keywords">
<span class="field-label">Trigger Keywords / Match Strings <?php $syslogHelp('Trigger Keywords / Match Strings','Accept messages containing any selected phrase, ignoring case. Leave empty to accept all messages. Type a phrase and press Enter or comma to add it.'); ?><span class="syslog-keyword-counter"><?= mb_strlen(implode(', ',$syslogFilters['match_strings'])) ?>/148</span></span>
<input type="hidden" name="match_strings" value="<?= icct_nms_h(json_encode($syslogFilters['match_strings'])) ?>">
<div class="syslog-keyword-box"><div class="syslog-keyword-chips"></div><input type="text" class="syslog-keyword-input" aria-label="Add match string" placeholder="Add match string" maxlength="148"></div>
</div>
<div class="protocol-actions split-actions">
<?php if(empty($presetMode)): ?><a class="button" href="protocols/syslog/controllers/syslog.php?host_id=<?= (int)$id ?>">Open Syslog Console</a><?php endif; ?>
<button class="button primary" type="submit">Save</button>
</div>
