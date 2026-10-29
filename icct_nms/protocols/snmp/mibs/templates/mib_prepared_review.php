<?php
if($generationStage===4){require __DIR__.'/mib_prepared_device.php';return;}
if($generationStage===3){require __DIR__.'/mib_prepared_graphs.php';return;}
$preparedPlan=$generationWork['plans'][$generationStage]??$generationWork['plans'][$generationStage-1]??null;
$preparedRecords=$preparedPlan['records']??[];
$preparedDefaultPosition=array_key_first($preparedRecords);
$preparedObjects=icct_mib_objects($generationBundle);$preparedIndexes=array_flip(array_column($preparedObjects,'symbol'));
$preparedFaultInfo=[];foreach($preparedRecords as $position=>$record){$recordSettings=array_replace($record,$readyDraft['review']['values']['records'][$preparedIndexes[$record['symbol']]]??[]);$preparedFaultInfo[$position]=icct_mib_review_fault_presets($record,$generationBundle,$recordSettings);}
$preparedFields=$generationStage===2?['data_name'=>'Data Source Name','label'=>'Parameter Label','units'=>'Units','min'=>'RRD Minimum','max'=>'RRD Maximum']:($generationStage===3?['graph_name'=>'Graph Template Name','legend'=>'Graph Legend','color'=>'Graph Color (hex)']:[]);
?>
<input type="hidden" name="prepared_review" value="1">
<div class="mib-prepared-review" data-prepared-review>
<aside class="mib-prepared-sidebar mib-browser-sidebar" aria-label="Prepared OIDs">
<h2><?= icct_nms_h($generationDisplayName) ?> (<?= count($preparedRecords) ?> OIDs)</h2>
<label class="field"><span class="sr-only">Search prepared OIDs</span><input type="search" data-prepared-search placeholder="Search With Display Name or OID"></label>
<div class="mib-prepared-items">
<details class="mib-browser-root" open><summary>MIB Tree</summary>
<?php $preparedModules=[];foreach($preparedRecords as $position=>$record)$preparedModules[explode('::',$record['symbol'])[0]][$position]=$record; ?>
<?php foreach($preparedModules as $module=>$moduleRecords): ?><details class="mib-browser-branch" open><summary><svg class="mib-browser-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h7l2 3h9v11H3z"/></svg><span><?= icct_nms_h($module) ?></span></summary><div class="mib-browser-children">
<?php foreach($moduleRecords as $position=>$record): $preparedIndex=$preparedIndexes[$record['symbol']]; ?>
<div class="mib-tree-select-row" data-prepared-row="<?= $position ?>">
<label class="mib-tree-checkbox"><input type="checkbox" data-prepared-select="<?= $preparedIndex ?>" data-prepared-choice="<?= $position ?>" aria-label="Include <?= icct_nms_h($record['label']) ?>" checked <?= $generationStage===2&&empty($readyDraft['saved_edit'])?'':'disabled' ?>></label>
<button type="button" class="mib-browser-leaf<?= $position===$preparedDefaultPosition?' selected':'' ?>" data-prepared-open="<?= $position ?>" aria-pressed="<?= $position===$preparedDefaultPosition?'true':'false' ?>" title="<?= icct_nms_h($record['symbol'].' · '.$record['oid']) ?>"><svg class="mib-browser-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 15h6"/></svg><span><?= icct_nms_h($record['label']) ?></span><span class="mib-oid-fault-indicator" data-prepared-fault-indicator role="img" aria-label="<?= count($preparedFaultInfo[$position]['rules']) ?> fault presets for <?= icct_nms_h($record['label']) ?>" data-tooltip="<?= count($preparedFaultInfo[$position]['rules']) ?> fault presets available" <?= $preparedFaultInfo[$position]['rules']?'':'hidden' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3zM12 9v5m0 3v1"/></svg></span></button>
</div><?php endforeach; ?></div></details><?php endforeach; ?></details>
<p data-prepared-empty hidden>No matching OIDs.</p>
</div></aside>
<div class="mib-prepared-details">
<p data-prepared-placeholder <?= $preparedDefaultPosition!==null?'hidden':'' ?>>Select an OID from the left side to view/modify the details</p>
<?php foreach($preparedRecords as $position=>$record): $index=$preparedIndexes[$record['symbol']];$record+=['graph_name'=>$preparedPlan['name'].' - '.$record['label'],'legend'=>$record['label'],'color'=>'00CF00'];$settings=array_replace($record,$readyDraft['review']['values']['records'][$index]??[]); ?>
<section data-prepared-panel="<?= $position ?>" data-prepared-search-text="<?= icct_nms_h(strtolower($record['data_name'].' '.$record['symbol'].' '.$record['oid'])) ?>" <?= $position===$preparedDefaultPosition?'':'hidden' ?>>
<h2><?= icct_nms_h($record['symbol']) ?></h2>
<dl class="mib-prepared-metadata"><?php foreach(['oid'=>'OID','syntax'=>'Syntax','access'=>'Access','units'=>'Units','kind'=>'Object Type'] as $key=>$label): ?><div><dt><?= $label ?></dt><dd><?= icct_nms_h($record[$key]?:'—') ?></dd></div><?php endforeach; ?></dl>
<?php if($generationStage===2): require __DIR__.'/mib_prepared_data_source.php'; elseif($preparedFields): ?><div class="protocol-grid cols-2"><?php foreach($preparedFields as $key=>$label): ?>
<label class="field"><span class="field-label"><?= $label ?></span><input name="records[<?= $index ?>][<?= $key ?>]" value="<?= icct_nms_h($settings[$key]??'') ?>" maxlength="<?= in_array($key,['data_name','graph_name'],true)?190:150 ?>" <?= $key==='color'?'pattern="[a-fA-F0-9]{6}"':'' ?>></label>
<?php endforeach; ?></div><p class="mib-prepared-help">Changes stay in this session. Choose <?= $generationDone?'Regenerate':'Generate' ?> to validate and prepare this step before continuing.</p><?php else: ?><p>Device template: <?= icct_nms_h($preparedPlan['name']) ?></p><?php endif; ?>
</section><?php endforeach; ?>
</div></div>
