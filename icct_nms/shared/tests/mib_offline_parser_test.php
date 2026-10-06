<?php
/** No Cacti bootstrap, executable, network or writable parser directory needed. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_repository_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_offline_parser.php';
function offline_check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$base=<<<'MIB'
QA-BASE DEFINITIONS ::= BEGIN
root OBJECT IDENTIFIER ::= { iso(1) 3 6 1 4 1 999998 }
SmallValue ::= TEXTUAL-CONVENTION
 STATUS current
 DESCRIPTION "Test type"
 SYNTAX INTEGER (-8..9)
END
MIB;
$child=<<<'MIB'
QA-CHILD DEFINITIONS ::= BEGIN
IMPORTS root, SmallValue FROM QA-BASE;
-- fake OBJECT-TYPE SYNTAX INTEGER ::= { missing 7 }
reading OBJECT-TYPE
 SYNTAX SmallValue
 UNITS "degrees"
 ACCESS read-only
 STATUS mandatory
 DESCRIPTION "A -- quoted comment stays. A ""quoted"" word."
 ::= { root 1 }
entry OBJECT-TYPE
 SYNTAX SEQUENCE OF SmallValue
 ACCESS not-accessible
 STATUS mandatory
 INDEX { reading }
 ::= { root 2 }
column OBJECT-TYPE
 SYNTAX Counter
 ACCESS read-only
 STATUS mandatory
 ::= { entry 1 }
notice TRAP-TYPE
 ENTERPRISE root
 VARIABLES { reading }
 DESCRIPTION "Notification"
 ::= 3
END
MIB;
$records=(new IcctMibOfflineParser(['QA-BASE'=>$base,'QA-CHILD'=>$child]))->records(['QA-CHILD']);
offline_check(count($records)===4,'Comments and type declarations do not become objects');
offline_check($records[0]['oid']==='1.3.6.1.4.1.999998.1.0'&&$records[0]['numeric']&&$records[0]['min']==='-8'&&$records[0]['max']==='9','Imported textual convention resolves scalar OID and declared bounds');
offline_check(str_contains($records[0]['description'],'-- quoted')&&str_contains($records[0]['description'],'"quoted"'),'Quoted comments and escaped quotes remain in descriptions');
offline_check($records[2]['table']&&$records[2]['ds_type']===2&&$records[2]['base_oid']==='1.3.6.1.4.1.999998.2.1','SMIv1 counter and INDEX column retain table instance semantics');
offline_check($records[3]['base_oid']==='1.3.6.1.4.1.999998.0.3'&&!$records[3]['numeric'],'SMIv1 trap enterprise and specific number resolve as metadata');
foreach([
 'truncated'=>str_replace("\nEND",'', $child),
 'cycle'=>str_replace('root 1','reading 1',$child),
 'oversized arc'=>str_replace('root 1','root 4294967296',$child),
 'unresolved'=>str_replace('root 1','missing 1',$child),
] as $name=>$invalid){
 try{(new IcctMibOfflineParser(['QA-BASE'=>$base,'QA-CHILD'=>$invalid]))->records(['QA-CHILD']);throw new LogicException('Invalid MIB accepted: '.$name);}catch(RuntimeException $e){offline_check(true,'Rejects '.$name.' definitions before template creation');}
}
$texts=[];
foreach(glob(__DIR__.'/../../shared/assets/mibs/*.txt') as $file){$text=file_get_contents($file);if(preg_match('/\b([A-Za-z][A-Za-z0-9-]*)\s+DEFINITIONS\s*::=\s*BEGIN/',$text,$m))$texts[$m[1]]=$text;}
$parser=new IcctMibOfflineParser($texts);
foreach(['IF-MIB','IP-MIB','BRIDGE-MIB','CISCO-ENTITY-SENSOR-MIB','ENTITY-MIB','SNMPv2-MIB'] as $module){
 $objects=$parser->records([$module]);offline_check(count($objects)>0,$module.' resolves bundled imports without an external parser');
 offline_check(!array_filter($objects,fn($object)=>!preg_match('/^[012](?:\.[0-9]+)+$/D',$object['base_oid'])),$module.' object OIDs are numeric');
}
echo "Offline MIB parser passed.\n";
