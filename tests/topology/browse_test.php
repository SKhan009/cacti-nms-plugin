<?php
/** Read-only default-list, filtering, escaping and visibility checks. */
if(PHP_SAPI!=='cli')exit(2);
$o=getopt('',array('cacti-root:'));if(empty($o['cacti-root']))exit(2);
require($o['cacti-root'].'/include/cli_check.php');require_once(dirname(__DIR__, 2).'/plugins/topology/includes/browse.php');
try {
    $_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));$_GET=array();$_POST=array();
    $_SERVER['SCRIPT_NAME']='/cacti/plugins/topology/topo_discovery.php';$_SERVER['REQUEST_METHOD']='GET';
    function bcheck($v,$s){if(!$v)throw new RuntimeException($s);print "PASS: $s\n";}
    $f=tp_browse_options();$devices=tp_browse_data('inventory',$f);
    $expected=0;foreach(tp_units() as $u)$expected+=count(tp_physical_hosts($u['id']));
    bcheck(count($devices)===$expected,'default inventory contains all assigned accessible devices');
    if(!$devices)throw new RuntimeException('Configured lab required.');
    $f['search']=$devices[0]['host']['description'];$filtered=tp_browse_data('inventory',$f);
    bcheck(count($filtered)>0,'device search finds matching records');
    $f['search']='no-match-topology-qa-826902';bcheck(count(tp_browse_data('discovery',$f))===0,'no matches returns an empty list');
    $f['search']='';$f['list_site']=(int)$devices[0]['host']['site_id'];foreach(tp_browse_data('inventory',$f) as $row)bcheck((int)$row['host']['site_id']===$f['list_site'],'Site filter uses native device Site');
    $_GET=array('search'=>'<script>alert(1)</script>');ob_start();tp_browse_page('discovery');$html=ob_get_clean();
    bcheck(!str_contains($html,'<script>alert(1)</script>')&&str_contains($html,'&lt;script&gt;'),'search input is escaped');
    $_GET=array('page'=>'999999');ob_start();tp_browse_page('discovery');$html=ob_get_clean();bcheck(str_contains($html,'Showing 1–'),'out-of-range pagination returns the last available page');
    foreach(array('inventory','ports') as $kind){$_GET=array();ob_start();tp_browse_page($kind);$html=ob_get_clean();bcheck(str_contains($html,'All Sites')&&str_contains($html,'tp-list-filter')&&!str_contains($html,'Select a node'),'native list and filters render for '.$kind);}
    $_SESSION=array('sess_user_id'=>3);$_GET=array();$f=tp_browse_options();foreach(tp_browse_data('inventory',$f) as $row)bcheck(is_device_allowed($row['host']['id']),'restricted list contains only permitted devices');
    print "BROWSE CHECKS COMPLETE\n";
}catch(Throwable $e){if(ob_get_level())ob_end_clean();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
