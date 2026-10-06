<?php
/** In-process SMI object reader. No executable, network or writable directory required.
 * Naming, imports, types and table instances follow RFC 2578 / RFC 2579.
 */
final class IcctMibOfflineParser {
    private array $modules=[];
    private array $oids=[];
    private array $resolving=[];
    public function __construct(array $texts){foreach($texts as $module=>$text){try{$this->modules[$module]=$this->module($text);}catch(RuntimeException $e){throw new RuntimeException($module.': '.$e->getMessage());}}}
    public static function clean(string $text):string {
        if(str_starts_with($text,"\xEF\xBB\xBF"))$text=substr($text,3);
        // Comments end at a closing -- or the end of the line; quoted text is preserved.
        return preg_replace_callback('/"(?:""|[^\"])*"|--[^\r\n]*?(?:--|(?=\r?$))/m',fn($m)=>str_starts_with($m[0],'--')?' ':$m[0],$text);
    }
    private function module(string $text):array {
        $text=self::clean($text);
        if(!preg_match('/\bEND\s*$/',$text))throw new RuntimeException('Incomplete MIB module: END is missing.');
        preg_match_all('/"(?:""|[^\"])*"|::=|\.\.|[A-Za-z][A-Za-z0-9-]*|-?[0-9]+|[^\s]/s',$text,$matches);
        $t=$matches[0];$imports=[];$defs=[];$count=count($t);
        if($count>250000)throw new RuntimeException('MIB token limit exceeded.');
        for($i=0;$i<$count;$i++){
            if($t[$i]==='IMPORTS'){
                $names=[];for($i++;$i<$count&&$t[$i]!==';';$i++){
                    if($t[$i]==='FROM'){$module=$t[++$i]??'';foreach($names as $name)$imports[$name]=$module;$names=[];}
                    elseif(preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D',$t[$i]))$names[]=$t[$i];
                }continue;
            }
            if(in_array($t[$i],['BEGIN','END','DEFINITIONS'],true)||!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D',$t[$i]))continue;
            $name=$t[$i];$kind=$t[$i+1]??'';
            if($kind==='MACRO') {while($i<$count&&$t[$i]!=='END')$i++;continue;}
            $oidKind=in_array($kind,['OBJECT-TYPE','OBJECT-IDENTITY','MODULE-IDENTITY','NOTIFICATION-TYPE','OBJECT-GROUP','NOTIFICATION-GROUP','MODULE-COMPLIANCE','AGENT-CAPABILITIES','TRAP-TYPE'],true);
            if($kind==='OBJECT'&&($t[$i+2]??'')==='IDENTIFIER')$oidKind=true;
            if($oidKind){
                $j=$i+2;$body=[];while($j<$count&&$t[$j]!=='::=')$body[]=$t[$j++];
                if($j===$count)throw new RuntimeException('Incomplete definition: '.$name.'.');
                if(($t[$j+1]??'')==='{'){
                    $j+=2;$path=[];while($j<$count&&$t[$j]!=='}')$path[]=$t[$j++];
                    if($j===$count)throw new RuntimeException('Incomplete OID: '.$name.'.');
                }elseif($kind==='TRAP-TYPE'){
                    $enterprise=$this->clause($body,'ENTERPRISE');$path=[$enterprise,'0',$t[$j+1]??''];$j++;
                }else throw new RuntimeException('Unsupported OID assignment: '.$name.'.');
                if(isset($defs[$name]))throw new RuntimeException('Duplicate definition: '.$name.'.');
                $defs[$name]=['kind'=>$kind,'body'=>$body,'path'=>$path];$i=$j;continue;
            }
            if($kind==='::='&&($t[$i+2]??'')!=='BEGIN'){
                $j=$i+2;$body=[];$depth=0;
                for(;$j<$count;$j++){
                    $v=$t[$j];
                    if($depth===0&&($v==='END'||($j>$i+2&&!in_array($v,['SYNTAX','STATUS','DESCRIPTION','REFERENCE','DISPLAY-HINT'],true)&&preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D',$v)&&in_array($t[$j+1]??'',['::=','OBJECT-TYPE','OBJECT','MODULE-IDENTITY','NOTIFICATION-TYPE','OBJECT-IDENTITY','TRAP-TYPE'],true))))break;
                    $body[]=$v;if(in_array($v,['{','(','['],true))$depth++;if(in_array($v,['}',')',']'],true))$depth--;
                }
                $defs[$name]=['kind'=>'TYPE','body'=>$body,'path'=>null];$i=$j-1;
            }
        }
        return ['imports'=>$imports,'defs'=>$defs];
    }
    private function clause(array $body,string $key):string {
        $pos=array_search($key,$body,true);if($pos===false)return '';
        $result=[];$depth=0;$stops=['UNITS','MAX-ACCESS','ACCESS','STATUS','DESCRIPTION','REFERENCE','INDEX','AUGMENTS','DEFVAL','DISPLAY-HINT','SYNTAX','OBJECTS','ENTERPRISE','VARIABLES'];
        for($i=$pos+1;$i<count($body);$i++){
            $v=$body[$i];if($depth===0&&in_array($v,$stops,true))break;
            $result[]=$v;if(in_array($v,['{','('],true))$depth++;if(in_array($v,['}',')'],true))$depth--;
            if(str_starts_with($v,'"'))break;
        }
        $s=implode(' ',$result);if(str_starts_with($s,'"'))return str_replace('""','"',substr($s,1,-1));
        return preg_replace(['/\s*\.\.\s*/','/\(\s+/','/\s+\)/','/\s+,\s*/'],['..','(',')',', '],$s);
    }
    private function reference(string $module,string $name):array {
        if(isset($this->modules[$module]['defs'][$name]))return [$module,$name];
        $import=$this->modules[$module]['imports'][$name]??null;
        if($import&&isset($this->modules[$import]['defs'][$name]))return [$import,$name];
        throw new RuntimeException('Unresolved symbol '.$module.'::'.$name.'. Upload its defining module.');
    }
    private function oid(string $module,string $name):string {
        $roots=['iso'=>'1','ccitt'=>'0','itu-t'=>'0','joint-iso-ccitt'=>'2','joint-iso-itu-t'=>'2'];
        if(isset($roots[$name]))return $roots[$name];
        [$module,$name]=$this->reference($module,$name);$key=$module.'::'.$name;
        if(isset($this->oids[$key]))return $this->oids[$key];
        if(isset($this->resolving[$key]))throw new RuntimeException('Circular OID definition: '.$key.'.');
        if(count($this->resolving)>=128)throw new RuntimeException('OID nesting limit exceeded.');
        $this->resolving[$key]=true;$path=$this->modules[$module]['defs'][$name]['path'];
        if(!$path)throw new RuntimeException('Missing OID definition: '.$key.'.');
        $parts=[];
        foreach($path as $i=>$token){
            if(preg_match('/^[0-9]+$/D',$token)){$parts[]=$token;continue;}
            if(in_array($token,['.',',','(',')'],true))continue;
            if(!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D',$token))throw new RuntimeException('Unsupported OID notation: '.$key.'.');
            if(($path[$i+1]??'')==='('){
                $number=$path[$i+2]??'';if(!preg_match('/^[0-9]+$/D',$number)||($path[$i+3]??'')!==')')throw new RuntimeException('Invalid named OID arc: '.$key.'.');
                // Resolved by the numeric token on the following pass; parentheses skipped below.
                continue;
            }
            $prefix=explode('.',$this->oid($module,$token));
            if($parts&&array_slice($prefix,0,count($parts))!==$parts)throw new RuntimeException('Conflicting OID path: '.$key.'.');
            $parts=$prefix;
        }
        if(count($parts)>128||!$parts||!in_array($parts[0],['0','1','2'],true))throw new RuntimeException('Invalid OID path: '.$key.'.');
        foreach($parts as $arc)if(strlen($arc)>10||(float)$arc>4294967295)throw new RuntimeException('OID arc out of range: '.$key.'.');
        unset($this->resolving[$key]);return $this->oids[$key]=implode('.',$parts);
    }
    private function syntax(string $module,string $syntax,array $seen=[]):string {
        preg_match('/^([A-Za-z][A-Za-z0-9-]*)/',$syntax,$m);$name=$m[1]??'';
        $primitive=['INTEGER','Integer32','Unsigned32','Gauge32','Counter32','Counter64','TimeTicks','OCTET','OBJECT','BITS','SEQUENCE','IpAddress','Opaque'];
        $compat=['Counter'=>'Counter32','Gauge'=>'Gauge32'];if(isset($compat[$name]))return preg_replace('/^'.preg_quote($name,'/').'\b/',$compat[$name],$syntax);
        if(in_array($name,$primitive,true))return $syntax;
        [$owner,$alias]=$this->reference($module,$name);$key=$owner.'::'.$alias;
        if(isset($seen[$key]))throw new RuntimeException('Circular syntax: '.$key.'.');$seen[$key]=true;
        $body=$this->modules[$owner]['defs'][$alias]['body'];
        $resolved=in_array('TEXTUAL-CONVENTION',$body,true)?$this->clause($body,'SYNTAX'):implode(' ',$body);
        if(!$resolved)throw new RuntimeException('Unsupported syntax: '.$key.'.');
        return $this->syntax($owner,$resolved,$seen).substr($syntax,strlen($name));
    }
    public function records(array $requested):array {
        $records=[];
        foreach($requested as $module)foreach($this->modules[$module]['defs'] as $name=>$def){
            if(!in_array($def['kind'],['OBJECT-TYPE','NOTIFICATION-TYPE','TRAP-TYPE'],true))continue;
            if(count($records)>=512)throw new RuntimeException('Limit: 512 object definitions per upload. Split larger modules.');
            $body=$def['body'];$oid=$this->oid($module,$name);$syntax=$this->clause($body,'SYNTAX');
            if($syntax)$syntax=$this->syntax($module,$syntax);
            $access=$this->clause($body,'MAX-ACCESS')?:$this->clause($body,'ACCESS');
            $table=false;
            foreach($this->modules as $owner=>$mod)foreach($mod['defs'] as $parent=>$p){
                if($p['kind']!=='OBJECT-TYPE'||(!in_array('INDEX',$p['body'],true)&&!in_array('AUGMENTS',$p['body'],true)))continue;
                if($this->oid($owner,$parent)===preg_replace('/\.[0-9]+$/','',$oid)){$table=true;break 2;}
            }
            $numeric=$def['kind']==='OBJECT-TYPE'&&in_array($access,['read-only','read-write','read-create'],true)&&preg_match('/^(INTEGER|Integer32|Unsigned32|Gauge32|Counter32|Counter64|TimeTicks)\b/',$syntax);
            $enum=str_contains($syntax,'{');$counter=(bool)preg_match('/^Counter(?:32|64)\b/',$syntax);
            $records[]=['symbol'=>$module.'::'.$name,'kind'=>$def['kind'],'base_oid'=>$oid,'oid'=>$table?$oid:$oid.'.0','syntax'=>$syntax,'access'=>$access,'description'=>trim(preg_replace('/\s+/',' ',$this->clause($body,'DESCRIPTION'))),'units'=>$this->clause($body,'UNITS'),'table'=>$table,'numeric'=>(bool)$numeric,'enum'=>$enum,'ds_type'=>$counter?2:1,'label'=>$name]+icct_mib_bounds($syntax)+['reason'=>!$numeric?'Metadata / nonnumeric object':($table?'Table object':($enum?'Numeric enumeration: review state mapping':'Readable numeric scalar'))];
        }
        return $records;
    }
}
