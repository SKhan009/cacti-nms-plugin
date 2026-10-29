<?php
$prefix='records['.$index.']';
$faultInfo=$preparedFaultInfo[$position];$settings['fault_presets']=$faultInfo['rules'];$faultSuggestions=$faultInfo['suggested'];
$profileChoices=array_column(icct_mib_profiles(),'name','id');
$collectionChoices=icct_mib_collection_options($record,icct_mib_collection_names());
$selectedCollection=($settings['collection_method']??'auto')==='auto'?$record['resolved_method']:$settings['collection_method'];
if(!empty($readyDraft['saved_edit'])){
    $collectionChoices=array_intersect_key($collectionChoices,[$selectedCollection=>true]);
    $savedRow=current(array_filter($generationBundle['rows'],fn($row)=>($row['object_index']??null)===$index));
    if($savedRow&&db_fetch_cell_prepared('SELECT id FROM data_local WHERE data_template_id=? LIMIT 1',[$savedRow['data_template_id']]))$profileChoices=array_intersect_key($profileChoices,[(int)$settings['profile_id']=>true]);
}

global $data_source_types;
$sourceTypes=array_intersect_key($data_source_types,[1=>true,2=>true,3=>true,4=>true]);
?>
<h3>Data Source Template</h3>
<div class="mib-editor-tabs" role="tablist" aria-label="<?= icct_nms_h($record['label']) ?> configuration">
<button type="button" role="tab" id="mib-source-tab-<?= $index ?>" aria-selected="true" aria-controls="mib-source-pane-<?= $index ?>" data-editor-tab="source">Data Source (Cacti)</button>
<button type="button" role="tab" id="mib-fault-tab-<?= $index ?>" aria-selected="false" aria-controls="mib-fault-pane-<?= $index ?>" data-editor-tab="fault">Fault Configuration</button>
</div>
<div role="tabpanel" id="mib-source-pane-<?= $index ?>" aria-labelledby="mib-source-tab-<?= $index ?>" data-editor-pane="source">
<div class="mib-data-source-grid">
<?php icct_nms_input('Data Source - Template Name',$prefix.'[data_name]',$settings['data_name'],'text','maxlength="190"');
icct_nms_select('Collection Method',$prefix.'[collection_method]',$collectionChoices,$selectedCollection);
icct_nms_select('Polling Interval/Cacti Profile',$prefix.'[profile_id]',$profileChoices,$settings['profile_id']); ?>
<label class="field"><span class="field-label">Data Source Active</span><span class="mib-source-toggle"><input type="checkbox" role="switch" data-source-active aria-label="Data Source Active" <?= ($settings['active']??'1')==='0'?'':'checked' ?>><input type="hidden" name="<?= $prefix ?>[active]" value="<?= ($settings['active']??'1')==='0'?'0':'1' ?>"><span><?= ($settings['active']??'1')==='0'?'Disabled':'Enabled' ?></span></span></label>
<?php icct_nms_input('Polling OID',$prefix.'[oid]',$settings['oid'],'text','readonly aria-readonly="true" class="mib-readonly-oid"');
icct_nms_select('Data Source Type',$prefix.'[ds_type]',$sourceTypes,$settings['ds_type']);
icct_nms_input('Units',$prefix.'[units]',$settings['units'],'text','maxlength="20"');
icct_nms_input('RRD Minimum (U=Unknown)',$prefix.'[min]',$settings['min']);
icct_nms_input('RRD Maximum (U=Unknown)',$prefix.'[max]',$settings['max']); ?>
</div>
<?php if(!empty($record['table'])): ?><div class="mib-data-source-grid mib-instance-row"><?php icct_nms_input('Instance Index (Specific GET)',$prefix.'[instance_index]',$settings['instance_index']??'','text','maxlength="128" placeholder="For a specific GET instance"'); ?></div><?php endif; ?>
</div>
<div role="tabpanel" id="mib-fault-pane-<?= $index ?>" aria-labelledby="mib-fault-tab-<?= $index ?>" data-editor-pane="fault" data-prepared-faults hidden>
<input type="hidden" name="<?= $prefix ?>[fault_presets_reviewed]" data-fault-reviewed value="<?= !empty($settings['fault_presets_reviewed'])?'1':'0' ?>">
<input type="hidden" name="<?= $prefix ?>[fault_presets]" data-prepared-fault-payload value="<?= icct_nms_h(json_encode($settings['fault_presets']??[],JSON_THROW_ON_ERROR)) ?>">
<div class="mib-prepared-fault-toolbar"><button type="button" class="button" data-prepared-fault-add>Add Fault</button></div>
<p class="mib-fault-empty" data-prepared-fault-empty>No fault presets configured for this OID.</p><?php if($faultSuggestions): ?><p class="mib-fault-origin">Auto-filled from the MIB definition. Review thresholds and severity before enabling; rules are disabled.</p><?php endif; ?><div data-prepared-fault-list></div>
<template data-prepared-fault-template><article class="mib-fault-card">
<header><div><button type="button" class="mib-fault-title" data-rule-title data-tooltip="Edit fault rule name">New Fault</button><input class="mib-fault-name" data-rule="name" aria-label="Fault rule name" placeholder="Fault rule name" maxlength="120" hidden><small><?= icct_nms_h($record['symbol']) ?></small></div><span class="mib-fault-enabled" data-rule-status>Disabled</span><button type="button" class="mib-fault-save" data-rule-save hidden>Save</button><button type="button" data-rule-edit aria-label="Edit fault preset" title="Edit fault preset"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16-1 5 5-1L21 7l-4-4L4 16zm10-10 4 4"/></svg></button><button type="button" class="icon-button" data-prepared-fault-remove aria-label="Delete fault rule" data-tooltip="Delete fault rule"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/></svg></button></header>
<div class="mib-fault-summary <?= trim($settings['units'])===''?'mib-fault-no-units':'' ?>" data-rule-summary>
<div><span>Comparator</span><strong data-rule-preview="condition">—</strong></div><div><span>Threshold</span><strong data-rule-preview="threshold">Not configured</strong></div><?php if(trim($settings['units'])!==''): ?><div><span>Units</span><strong><?= icct_nms_h($settings['units']) ?></strong></div><?php endif; ?><div><span>Severity</span><strong class="mib-fault-severity" data-rule-preview="severity">—</strong></div><div class="mib-fault-description"><span>Description</span><p data-rule-preview="description">—</p></div><div class="mib-fault-description"><span>Fix</span><p data-rule-preview="corrective_action">—</p></div></div>
<p class="notice error" role="alert" data-rule-error hidden></p>
<div class="mib-fault-fields" data-rule-fields hidden>
<label class="field mib-fault-toggle"><span class="field-label">Enable Alarm</span><span class="mib-fault-switch"><input type="checkbox" role="switch" aria-label="Enable Alarm" data-rule="enabled"><span data-rule-enabled-label>Disabled</span></span></label>
<label class="field"><span class="field-label">Comparator</span><select data-rule="condition"><?php foreach(['above'=>'> Above','below'=>'< Below','at_or_above'=>'≥ At or above','at_or_below'=>'≤ At or below','equals'=>'= Equals','not_equals'=>'≠ Not equal'] as $value=>$text): ?><option value="<?= $value ?>"><?= $text ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Threshold</span><input type="number" step="any" data-rule="threshold"></label>
<label class="field"><span class="field-label">Severity</span><select data-rule="severity"><?php foreach(['Information','Minor','Warning','Major','Critical'] as $severity): ?><option value="<?= $severity ?>"><?= ['Information'=>'Info','Warning'=>'Warn'][$severity]??$severity ?></option><?php endforeach; ?></select></label>

<label class="field mib-fault-text"><span class="field-label">Notes/Description <small data-rule-counter="description">0/1000</small></span><textarea aria-label="Description" maxlength="1000" data-rule="description"></textarea></label>
<label class="field mib-fault-text"><span class="field-label">Fix <small data-rule-counter="corrective_action">0/1000</small></span><textarea aria-label="Fix" maxlength="1000" data-rule="corrective_action"></textarea></label>
</div></article></template>
</div>
