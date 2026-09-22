<?php
/** Regression cases for imports that would otherwise execute plugins, corrupt OIDs or duplicate data. */
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/records.php');
function check($ok,$label) { if(!$ok) throw new RuntimeException($label); }
function reject($content,$label) { try { tp_records($content); } catch(InvalidArgumentException $e) { return; } throw new RuntimeException('Accepted '.$label); }
$content=file_get_contents(__DIR__.'/fixtures/protocols/switch-a.snmprec');
$r=tp_records($content);
check(count($r)>30,'fixture size');
check(count(array_filter($r,function($v){return $v['metric'];}))>0,'numeric metrics');
foreach($r as $oid=>$record) if(strpos($oid,'1.0.8802.')===0) check(!$record['metric'],'neighbor identifiers are not graph metrics');
check(tp_record_content(tp_records(tp_record_content($r)))===tp_record_content($r),'canonical round trip');
reject($content."\n1.3.6.1.2.1.1.3.0|67|100\n",'duplicate OID');
reject(str_replace('|67|8640000','|67:subprocess|anything',$content),'variation module');
reject(str_replace('|67|8640000','|67|-1',$content),'negative ticks');
reject(str_replace('|67|8640000','|70|18446744073709551616',$content),'overflow');
reject(str_replace('1.3.6.1.2.1.1.5.0','1.3.6.1.2.1.1.9.0',$content),'missing identity');
reject(str_replace('1.3.6.1.2.1.1.5.0','1.40.6.1.2.1.1.5.0',$content),'bad second arc');
reject(str_replace('1.3.6.1.2.1.1.5.0','1.3.06.1.2.1.1.5.0',$content),'noncanonical arc');
reject(str_repeat('x',2097153),'oversize');
check(tp_unsigned('18446744073709551615','18446744073709551615'),'max counter64');
foreach(array('../x','abc.php','a b','x:module') as $community) {try{tp_community($community);throw new RuntimeException('unsafe community');}catch(InvalidArgumentException $e){}}
echo "PASS: strict records, ranges, neighbor/metric separation, identity, canonicalization and filename validation\n";
