<?php
$graphPlan=$generationWork['plans'][3]??$generationWork['plans'][2]??null;
$objects=icct_mib_objects($generationBundle);$objectIndexes=array_flip(array_column($objects,'symbol'));$graphSources=[];
foreach($graphPlan['records']??[] as $record){$index=$objectIndexes[$record['symbol']];$settings=array_replace($record,$readyDraft['review']['values']['records'][$index]??[]);$settings+=['graph_name'=>$graphPlan['name'].' - '.$record['label'],'legend'=>$record['label'],'color'=>'00CF00','graph_type'=>4,'cf'=>1,'statistics'=>'1','graph_options'=>json_encode(icct_mib_core_graph_defaults($settings['graph_name']??$graphPlan['name'].' - '.$record['label'],$record['units']))];$settings['graph_deleted_items']=json_encode(icct_mib_deleted_graph_items($settings['graph_deleted_items']??[]));$settings['graph_items']=json_encode(icct_mib_graph_items($settings['graph_items']??[]));if(is_array($settings['graph_options']))$settings['graph_options']=json_encode($settings['graph_options']);$graphSources[]=['index'=>$index,'record'=>$record,'settings'=>$settings];}
$nativeGraphItems=db_fetch_assoc("SELECT gi.*,c.hex AS color_hex,gp.gprint_text,cd.name AS cdef_name,vd.name AS vdef_name FROM graph_templates_item gi JOIN graph_templates gt ON gt.id=gi.graph_template_id LEFT JOIN colors c ON c.id=gi.color_id LEFT JOIN graph_templates_gprint gp ON gp.id=gi.gprint_id LEFT JOIN cdef cd ON cd.id=gi.cdef_id LEFT JOIN vdef vd ON vd.id=gi.vdef_id WHERE gt.name='SNMP - Generic OID Template' AND gi.local_graph_id=0 ORDER BY gi.sequence,gi.id")?:[];
$nativeGraphFlags=db_fetch_row("SELECT multiple,test_source FROM graph_templates WHERE name='SNMP - Generic OID Template'")?:[];
$plotTypes=array_intersect_key($graph_item_types,array_flip([4,5,6,7]));$cfTypes=array_intersect_key($consolidation_functions,array_flip([1,2,3,4]));
?>
<input type="hidden" name="prepared_review" value="1">
<div class="mib-prepared-review mib-graph-review" data-prepared-review data-graph-review>
<aside class="mib-prepared-sidebar" aria-label="Graph templates"><h2>Graph Templates (<span data-graph-count></span>)</h2>
<label class="field"><span class="sr-only">Search graph templates</span><input type="search" data-graph-search placeholder="Search With Template Name"></label>
<div data-graph-list></div><p data-graph-empty hidden>No matching templates.</p></aside>
<div class="mib-graph-details">
<div class="mib-graph-toolbar" data-graph-edit-only hidden><button type="button" class="button" data-graphs-separate>Separate Graphs</button><button type="button" class="button" data-graphs-combine>Combine All Sources</button><button type="button" class="button" data-graph-add>Add Graph Template</button></div>
<p class="mib-prepared-help" data-graph-edit-only hidden><?= !empty($readyDraft['saved_edit'])?'Existing source groups are retained. Create a new template to split or combine sources.':'Choose the data sources for each graph. Combined indexed sources must share a table entry and polling profile.' ?></p>
<div data-graph-editor></div>
</div>
<div hidden data-graph-values><?php foreach($graphSources as $source): foreach(['graph_name','legend','color','graph_type','cf','statistics','graph_options','graph_items','graph_deleted_items'] as $field): ?><input name="records[<?= $source['index'] ?>][<?= $field ?>]" data-source="<?= $source['index'] ?>" data-graph-field="<?= $field ?>" value="<?= icct_nms_h($source['settings'][$field]) ?>"><?php endforeach; endforeach; ?></div>
<script type="application/json" data-graph-model><?= json_encode(['sources'=>$graphSources,'graphFields'=>icct_mib_core_graph_fields(),'flags'=>$nativeGraphFlags,'items'=>$nativeGraphItems,'plotTypes'=>$plotTypes,'cfTypes'=>$cfTypes,'itemTypes'=>$graph_item_types,'itemFields'=>icct_mib_core_graph_item_fields()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_THROW_ON_ERROR) ?></script>
</div>
