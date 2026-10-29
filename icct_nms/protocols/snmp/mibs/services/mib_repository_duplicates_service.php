<?php
/** Compare active saved entries by content, module identity, filenames and parsed OIDs. */
class IcctMibRepositoryConflict extends InvalidArgumentException {
    public $comparison;
    public function __construct($comparison){$this->comparison=$comparison;parent::__construct($comparison['message']);}
}
function icct_mib_repository_oid_index($records,$module){
    $index=[];
    foreach($records as $record){
        if(explode('::',$record['symbol']??'',2)[0]!==$module)continue;
        $oid=ltrim((string)($record['base_oid']??''),'.');
        if(!preg_match('/^[0-9]+(?:\.[0-9]+)*$/D',$oid))continue;
        $definition=[];foreach(['symbol','kind','syntax','access','description','units','index_description','table','numeric'] as $key)$definition[$key]=$record[$key]??null;
        $index[$oid][]=hash('sha256',json_encode($definition,JSON_THROW_ON_ERROR));
    }
    foreach($index as &$definitions){sort($definitions,SORT_STRING);$definitions=array_values(array_unique($definitions));}unset($definitions);ksort($index,SORT_STRING);return $index;
}
function icct_mib_repository_comparison($preview){
    $incoming=[];
    foreach($preview['files'] as $file){
        $hash=hash('sha256',$file['content']);
        if(!hash_equals($file['sha256'],$hash))throw new RuntimeException('Pending MIB content failed integrity verification. Read the file again.');
        $incoming[]=['module'=>$file['module'],'name'=>$file['name'],'hash'=>$hash,'oids'=>icct_mib_repository_oid_index($preview['records'],$file['module']),'complete'=>empty($preview['parse_error'])];
    }
    $exact=[];$conflicts=[];$warnings=[];$allOidsComparable=empty($preview['parse_error']);
    foreach(icct_mib_list() as $bundle){
        $parsed=null;
        foreach($bundle['files'] as $file){
            foreach($incoming as $candidate){
                $sameModule=$file['module']===$candidate['module'];$sameFilename=strcasecmp(trim($file['name']),trim($candidate['name']))===0;$sameContent=hash_equals($file['sha256'],$candidate['hash']);
                // A complete OID comparison also detects collisions across different modules.
                if($parsed===null){
                    // Use the same current parser for both sides; historical parser output
                    // can differ in expanded syntax and index metadata for identical bytes.
                    $upload=['name'=>[],'stored_content'=>[]];
                    foreach($bundle['files'] as $index=>$stored){$upload['name'][]=$stored['name'];$upload['stored_content'][]=icct_mib_file($bundle,$index);}
                    $parsed=icct_mib_preview($upload,'__mib_unassigned__',true);
                    if(!empty($parsed['parse_error']))$allOidsComparable=false;
                }
                $saved=icct_mib_repository_oid_index($parsed['records'],$file['module']);
                $complete=$candidate['complete']&&empty($parsed['parse_error']);
                $shared=array_intersect_key($candidate['oids'],$saved);$changed=0;
                foreach($shared as $oid=>$definitions)if($definitions!==$saved[$oid])$changed++;
                $match=['repository_id'=>$bundle['id'],'file'=>$file['name'],'module'=>$file['module'],'incoming_module'=>$candidate['module'],'content_hash'=>$file['sha256'],'oid_fingerprint'=>hash('sha256',json_encode($saved,JSON_THROW_ON_ERROR)),'same_module'=>$sameModule,'oid_comparison_complete'=>$complete,
                    'shared_oids'=>$complete?count($shared):null,'added_oids'=>$complete?count(array_diff_key($candidate['oids'],$saved)):null,'removed_oids'=>$complete?count(array_diff_key($saved,$candidate['oids'])):null,'changed_oids'=>$complete?$changed:null];
                if($sameContent){$match['type']='exact';$exact[]=$match;}
                elseif($sameModule){$match['type']='module_exists';$conflicts[]=$match;}
                elseif($sameFilename){$match['type']='filename_exists';$conflicts[]=$match;}
                elseif($shared){$match['type']='oid_overlap';$warnings[]=$match;}
            }
        }
    }
    $sort=function($a,$b){return strcmp($a['repository_id'].'|'.$a['module'].'|'.$a['incoming_module'],$b['repository_id'].'|'.$b['module'].'|'.$b['incoming_module']);};
    usort($exact,$sort);usort($conflicts,$sort);usort($warnings,$sort);
    $identity=array_map(fn($file)=>['module'=>$file['module'],'name'=>$file['name'],'hash'=>$file['hash'],'oids'=>$file['oids']],$incoming);
    $fingerprint=hash('sha256',json_encode([$identity,$exact,$conflicts,$warnings],JSON_THROW_ON_ERROR));
    $message=$exact?'Already have this file content in the repository: '.implode(', ',array_unique(array_column($exact,'file'))).'. Use the existing file.':($conflicts?'Already have this MIB module or filename in the repository: '.implode(', ',array_unique(array_column($conflicts,'file'))).'. Only one entry per module or filename is allowed.':($warnings?'Repository overlap detected. Review the module and OID comparison and acknowledge the warning before saving a separate entry.':($allOidsComparable?'No matching repository content, modules or OID definitions.':'No identical content or matching module found. Some OIDs cannot be compared until missing dependencies are resolved.')));
    return ['oid_comparison_complete'=>$allOidsComparable,'blocked'=>(bool)($exact||$conflicts),'conflicts'=>$conflicts,'warnings'=>$warnings,'exact'=>$exact,'fingerprint'=>$fingerprint,'message'=>$message];
}
function icct_mib_repository_require_acknowledgment($comparison,$acknowledgment){
    if($comparison['blocked'])throw new IcctMibRepositoryConflict($comparison);
    if($comparison['warnings']&&(!is_string($acknowledgment)||!hash_equals($comparison['fingerprint'],$acknowledgment)))throw new IcctMibRepositoryConflict($comparison);
}
