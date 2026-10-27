<?php
$generationObjects=icct_mib_objects($generationBundle);
$generationSelection=icct_mib_generation_input($generationBundle,2,$generationObject??null)['selected'];
$generationProfiles=array_column(icct_mib_profiles(),'name','id');$collectionNames=icct_mib_collection_names();
?>
<input type="hidden" name="generation_review" value="1"><div class="mib-generation-review">
<aside class="mib-generation-objects" aria-label="Data source OIDs">
<h2><?= icct_nms_h(implode(', ',array_column($generationBundle['files'],'module'))) ?> (<?= count($generationSelection) ?> OIDs)</h2>
<label class="mib-generation-search"><span aria-hidden="true">⌕</span><input type="search" id="mibGenerationSearch" aria-label="Search data source OIDs" placeholder="Search With Display Name or OID"></label>
<?php foreach($generationSelection as $i=>$selected): $object=$generationObjects[$i]; ?>
<div class="mib-generation-oid" data-generation-row="<?= $i ?>" data-search="<?= icct_nms_h(strtolower($object['symbol'].' '.$object['base_oid'])) ?>"><input type="checkbox" name="selected[<?= $i ?>]" value="1" checked aria-label="Include <?= icct_nms_h($object['label']) ?>"><button type="button" data-generation-open="<?= $i ?>" aria-expanded="false"><span class="mib-generation-chevron" aria-hidden="true">▸</span><span><?= icct_nms_h($object['label']) ?></span></button></div>
<?php endforeach; ?>
<p id="mibGenerationNoMatches" hidden>No matching OIDs.</p>
</aside>
<div class="mib-generation-editor">
<p id="mibGenerationEmpty">Select an OID from the left side to view/modify the details</p>
<?php foreach($generationSelection as $i=>$selected): $object=$generationObjects[$i];$object+=icct_mib_bounds($object['syntax']??'');$prefix='records['.$i.']'; ?>
<section data-generation-panel="<?= $i ?>" hidden><h2><?= icct_nms_h($object['symbol']) ?></h2>
<dl class="mib-oid-metadata"><?php foreach(['base_oid'=>'OID','syntax'=>'Syntax','access'=>'Access','units'=>'Units'] as $key=>$label): ?><dt><?= $label ?></dt><dd><?= icct_nms_h($object[$key]?:'Not specified') ?></dd><?php endforeach; ?></dl><p><?= icct_nms_h($object['description']) ?></p>
<h3>Data Source Template</h3><div class="protocol-grid cols-2">
<?php
icct_nms_select('Collection Method',$prefix.'[collection_method]',$object['table']?['auto'=>'Auto (Recommended) — '.$collectionNames['indexed'],'indexed'=>$collectionNames['indexed'],'get'=>$collectionNames['get'].' — Specific Instance']:['auto'=>'Auto (Recommended) — '.$collectionNames['get'],'get'=>$collectionNames['get']],'auto');
icct_nms_select('Polling Interval / Cacti Profile',$prefix.'[profile_id]',$generationProfiles,array_key_first($generationProfiles));
icct_nms_input('Data Source Template Name *',$prefix.'[data_name]',$generationBundle['name'].' - '.$object['label'],'text','required maxlength="190"');
icct_nms_input('Parameter Label *',$prefix.'[label]',$object['label'],'text','required maxlength="150"');
icct_nms_input('Polling OID *',$prefix.'[oid]',$object['oid'],'text','required maxlength="255"');
if($object['table'])icct_nms_input('Instance Index (Specific GET)',$prefix.'[instance_index]','','text','maxlength="128" placeholder="Required for a specific table instance"');
icct_nms_select('Data Source Type',$prefix.'[ds_type]',[1=>'Gauge',2=>'Counter (rate)',3=>'Derive',4=>'Absolute'],$object['ds_type']);
icct_nms_input('Units',$prefix.'[units]',$object['units'],'text','maxlength="20"');
icct_nms_input('RRD Minimum (U = unknown)',$prefix.'[min]',$object['min']);
icct_nms_input('RRD Maximum (U = unknown)',$prefix.'[max]',$object['max']);
?></div></section><?php endforeach; ?>
</div></div>
<footer class="mib-generation-review-footer"><p id="mibGenerationSelected" aria-live="polite"><?= count($generationSelection) ?> OIDs selected</p><button class="button" type="button" disabled>Previous ←</button><button class="button primary" type="submit" id="mibGenerate">Next →</button></footer>
