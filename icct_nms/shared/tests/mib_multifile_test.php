<?php
if (PHP_SAPI !== 'cli') exit(1);
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
function db_fetch_assoc($sql){return [];}
function icct_backend_current_user_id(){return 1;}
require __DIR__.'/../../protocols/snmp/mibs/services/mib_repository_service.php';
function checkMib($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejectedMib($fn,$message){try{$fn();}catch(Throwable $e){checkMib(str_contains($e->getMessage(),$message),$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$message);}
function parseMibFiles($files){return icct_mib_parse_upload(['name'=>array_keys($files),'stored_content'=>array_values($files)]);}
icct_mib_validate_upload_selection(['name'=>['vendor.txt','dependency.mib']]);
foreach ([[],array_fill(0,9,'mib.txt')] as $names) rejectedMib(fn()=>icct_mib_validate_upload_selection(['name'=>$names]),'1–8');
rejectedMib(fn()=>icct_mib_validate_upload_selection(['name'=>['ok.txt','bad.html']]),'.txt');
$legacy=<<<'MIB'
TEST-UPS DEFINITIONS ::= BEGIN
IMPORTS OBJECT-TYPE FROM RFC-1212 TRAP-TYPE FROM RFC-1215
        enterprises, Gauge FROM RFC1155-SMI DisplayString FROM RFC1213-MIB;
ups OBJECT IDENTIFIER ::= { enterprises 55555 }
load OBJECT-TYPE SYNTAX Gauge ACCESS read-only STATUS mandatory ::= { ups 1 }
alarm TRAP-TYPE ENTERPRISE ups DESCRIPTION "alarm" ::= 2
END
MIB;
$p=parseMibFiles(['vendor UPS.txt'=>$legacy]);
checkMib(count($p['records'])===2,'Legacy dependencies must resolve offline without adding their objects');
checkMib($p['records'][0]['base_oid']==='1.3.6.1.4.1.55555.1','Exact legacy OID');
$dep='DEP DEFINITIONS ::= BEGIN IMPORTS enterprises FROM RFC1155-SMI; root OBJECT IDENTIFIER ::= { enterprises 55556 } other OBJECT-TYPE SYNTAX INTEGER ACCESS read-only STATUS mandatory ::= { root 2 } END';
$main='MAIN DEFINITIONS ::= BEGIN IMPORTS root FROM DEP; reading OBJECT-TYPE SYNTAX INTEGER ACCESS read-only STATUS mandatory ::= { root 1 } END';
foreach ([['vendor.txt'=>$main,'anything.txt'=>$dep],['anything.txt'=>$dep,'vendor.txt'=>$main]] as $files){
 $p=parseMibFiles($files);checkMib(count($p['files'])===2 && count($p['records'])===1,'Resolve dependencies independent of filename/order without monitoring dependency objects');
 checkMib($p['records'][0]['base_oid']==='1.3.6.1.4.1.55556.1','Custom dependency OID');
}
rejectedMib(fn()=>parseMibFiles(['vendor.txt'=>$main]),'Missing MIB dependencies: DEP');
rejectedMib(fn()=>parseMibFiles(['RFC 1215.txt'=>'TRAP-TYPE MACRO ::= BEGIN END']),'RFC 1215.txt: not a complete MIB module');
rejectedMib(fn()=>parseMibFiles(['first.txt'=>$main,'second.txt'=>$main]),'Duplicate MIB module MAIN');
rejectedMib(fn()=>parseMibFiles(['cut.txt'=>str_replace('END','',$main),'dep.txt'=>$dep]),'END is missing');
rejectedMib(fn()=>parseMibFiles(['too-big.txt'=>str_repeat('a',1048577)]),'1 MiB');
echo "PASS: Offline legacy imports, multi-file order, dependency-only objects, exact OIDs and invalid files.\n";
