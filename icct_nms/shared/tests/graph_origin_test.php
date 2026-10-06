<?php
require_once __DIR__ . '/../../graphs/services/graph_origin.php';
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$old = icct_nms_graph_xml('<hash_000103aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa><name>Stock Graph</name><graph><lower_limit>0</lower_limit><upper_limit>100</upper_limit></graph><items><hash_100103bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb><sequence>1</sequence><task_item_id>hash_080103cccccccccccccccccccccccccccccccc</task_item_id><color_id>0000FF</color_id></hash_100103bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb></items><inputs/></hash_000103aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa>');
$baseline = icct_nms_graph_xml_fields($old);
$new = icct_nms_graph_xml(str_replace('0103', '0104', $old->asXML()));
verify(icct_nms_graph_matches_stock(icct_nms_graph_xml_fields($new), $baseline), 'Export version must not mark a template edited.');
$new->graph->upper_limit = '200';
verify(!icct_nms_graph_matches_stock(icct_nms_graph_xml_fields($new), $baseline), 'Changed maximum must mark template edited.');
$new->graph->upper_limit = '100';
$new->graph->addChild('new_cacti_field', '');
verify(icct_nms_graph_matches_stock(icct_nms_graph_xml_fields($new), $baseline), 'New release fields must not mark original templates edited.');
$new->items->addChild('hash_100104dddddddddddddddddddddddddddddddd')->addChild('sequence', '2');
verify(!icct_nms_graph_matches_stock(icct_nms_graph_xml_fields($new), $baseline), 'Added graph items must mark template edited.');
unset($new->items->children()[1]);
$new->name = 'Stock Graph renamed';
verify(!icct_nms_graph_matches_stock(icct_nms_graph_xml_fields($new), $baseline), 'Renamed stock template must remain stock and be marked edited.');
verify(icct_nms_graph_xml('<broken>') === false, 'Malformed package must fail safely.');
echo "Graph origin comparisons passed.\n";
