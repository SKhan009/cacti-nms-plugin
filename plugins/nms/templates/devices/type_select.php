<?php
/** Use the same saved segment/type catalogue as Device appearance. */
$type_catalog=[];
foreach(nms_appearance_read()['types'] ?? [] as $type_profile) {
    $type_catalog[(string)(int)$type_profile['category_id']][]=$type_profile['name'];
}
foreach($type_catalog as &$type_names) { $type_names=array_values(array_unique($type_names)); natcasesort($type_names); $type_names=array_values($type_names); } unset($type_names);
$type_templates=[];
foreach(db_fetch_assoc('SELECT host_template_id,category_id FROM plugin_nms_category_templates') as $type_row) $type_templates[(string)$type_row['host_template_id']]=(string)$type_row['category_id'];
$type_options=$type_catalog[(string)$type_segment] ?? [];
if($type_keep_saved && $type_value!=='' && !in_array($type_value,$type_options,true)) $type_options[]=$type_value;
?>
<label><span>Device type</span><select name="device_type" data-nms-type-select><option value="">Select a device type</option><?php foreach($type_options as $type_option){ ?><option value="<?php print nms_h($type_option); ?>" <?php print $type_option===$type_value?'selected':''; ?>><?php print nms_h($type_option); ?></option><?php } ?></select><small data-nms-type-help>Types follow the selected device segment.</small></label>
<script>
(function(){
    var catalog=<?php print json_encode($type_catalog,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    var templates=<?php print json_encode($type_templates,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    var saved=<?php print json_encode($type_keep_saved?$type_value:'',JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    var savedSegment=<?php print json_encode((string)$type_segment); ?>;
    function init(){
        var select=document.querySelector('[data-nms-type-select]'); if(!select)return;
        var form=select.form, segment=form.elements.equipment_category_id, template=form.elements.host_template_id;
        function update(initial){
            var key=segment.value==='template'?templates[template.value]:segment.value;
            var options=(catalog[key] || []).slice(), current=select.value;
            if(initial && saved && key===savedSegment && options.indexOf(saved)<0)options.push(saved);
            select.replaceChildren(new Option(options.length?'Select a device type':'No preset types for this segment',''));
            options.forEach(function(name){select.add(new Option(name,name));});
            select.value=options.indexOf(current)>=0?current:'';
            select.closest('label').querySelector('[data-nms-type-help]').textContent=options.length?'Types from the selected segment.':'Define types for this segment in Topology → Device appearance.';
        }
        segment.addEventListener('change',function(){update(false);});
        if(template)template.addEventListener('change',function(){if(segment.value==='template')update(false);});
        update(true);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>
