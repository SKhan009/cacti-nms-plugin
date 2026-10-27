<?php
/** Automatic generation delegates each committed stage to the native Cacti wizard service. */
function icct_mib_generation_input($bundle,$stage,$object=null){
    if(!in_array($stage,[2,3,4],true))throw new InvalidArgumentException('Choose a valid generation stage.');
    $input=['step'=>$stage-1,'type_id'=>$bundle['type_id'],'template_name'=>$bundle['name'],'selected'=>[],'records'=>[]];
    if($stage===2){
        $objects=icct_mib_objects($bundle);$existing=icct_mib_existing_source_indexes($objects);
        if($object!==null&&(!is_int($object)||!isset($objects[$object])||empty($objects[$object]['numeric'])))throw new InvalidArgumentException('Choose a readable numeric OID to create a data source.');
        foreach($objects as $i=>$record)if(($object===null||$object===$i)&&!empty($record['numeric'])&&!isset($existing[$i]))$input['selected'][$i]=1;
        if(!$input['selected'])throw new InvalidArgumentException('No new supported OIDs remain. Existing Cacti data sources will not be duplicated.');
    }
    return $input;
}
function icct_mib_generation_save($id,$stage,$object=null,$review=[]){
    icct_backend_require_management(3);$bundle=icct_mib_wizard_get($id);
    $input=icct_mib_generation_input($bundle,$stage,$object);
    if($stage===2&&(array_key_exists('selected',$review)||!empty($review['generation_review']))){
        if(!is_array($review['selected']??[])||!is_array($review['records']??[]))throw new InvalidArgumentException('Invalid OID review.');
        $input['selected']=array_intersect_key($review['selected']??[],$input['selected']);
        if(!$input['selected'])throw new InvalidArgumentException('Select at least one supported OID.');
        $input['records']=array_intersect_key($review['records']??[],$input['selected']);
    }
    // A later Create starts a new batch while preserving earlier template associations.
    if($stage===2&&!empty($bundle['rows'])){
        $upload=['name'=>[],'stored_content'=>[]];
        foreach($bundle['files'] as $i=>$file){$upload['name'][]=$file['name'];$upload['stored_content'][]=icct_mib_file($bundle,$i);}
        $preview=icct_mib_preview($upload,$bundle['type_id'],true);
        $objects=icct_mib_objects($bundle);$label=$object!==null?$objects[$object]['label']:'Additional OIDs';
        $bundle=icct_mib_save($preview,icct_mib_plan($preview,['type_id'=>$bundle['type_id'],'template_name'=>$bundle['name'].' - '.$label]));
        $input['template_name']=$bundle['name'];$id=$bundle['id'];
    }
    return icct_mib_wizard_save($id,$input);
}
function icct_mib_generation_results($bundle,$stage){
    $labels=[2=>'Data Source',3=>'Graph Template',4=>'Device Template'];$label=$labels[$stage];$results=[];
    if((int)($bundle['wizard_step']??0)<$stage-1)return $results;
    if($stage===4){$results[]=['name'=>$bundle['name'],'oid'=>'','id'=>(int)$bundle['host_template_id'],'kind'=>$label];}
    else foreach($bundle['rows'] as $row)$results[]=['name'=>$row['name'],'oid'=>$row['oid'],'id'=>(int)$row[$stage===2?'data_template_id':'graph_template_id'],'kind'=>$label];
    return $results;
}
