<?php
/** Small accessible SVG inside a native Cacti box; no frontend framework, overlay or external assets. */

/** Draw visible native devices and evidence links with native device drill-down links. */
function tp_draw_connections($hosts,$links) {
    if(!$hosts) return;
    $positions=array();$count=count($hosts);$columns=min(4,$count);$width=$columns*300;$height=(int)ceil($count/$columns)*170;
    foreach(array_values($hosts) as $i=>$host) {
        $x=150+($i%$columns)*300;$y=85+(int)floor($i/$columns)*170;
        $positions[$host['id']]=array($x,$y);
    }
    html_start_box('Discovered Connections','100%',false,3,'center','');
    print '<tr><td><svg xmlns="http://www.w3.org/2000/svg" width="100%" font-size="14" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-label="Node devices and discovered connections"><title>Node connections</title><desc>Solid lines have current neighbor evidence. Dashed lines show historical evidence. Link numbers correspond to the table below; lines are not proof of cable health.</desc>';
    foreach($links as $i=>$link) {
        list($x1,$y1)=$positions[$link['a'][0]];list($x2,$y2)=$positions[$link['b'][0]];
        $dx=$x2-$x1;$dy=$y2-$y1;$factor=max(abs($dx)/100,abs($dy)/32,1);$offset=1/$factor;
        // Offset parallel links so separate ports remain visible and independently numbered.
        $parallel=0;for($j=0;$j<$i;$j++) if($links[$j]['a'][0]===$link['a'][0] && $links[$j]['b'][0]===$link['b'][0]) $parallel++;
        $bend=$parallel*35;
        print '<path d="M '.($x1+$dx*$offset).' '.($y1+$dy*$offset).' Q '.(($x1+$x2)/2).' '.(($y1+$y2)/2-$bend).' '.($x2-$dx*$offset).' '.($y2-$dy*$offset).'" fill="none" stroke="currentColor" stroke-width="2"'.($link['current']?'':' stroke-dasharray="7 5"').'><title>Link '.($i+1).': '.tp_h($link['state']).'</title></path>';
        print '<text x="'.(($x1+$x2)/2).'" y="'.(($y1+$y2)/2-$bend/2-9).'" fill="currentColor" text-anchor="middle">Link '.($i+1).'</text>';
    }
    foreach($hosts as $host) {
        list($x,$y)=$positions[$host['id']];$label=mb_strimwidth($host['description'],0,27,'…');
        print '<a href="'.tp_h(tp_url('host.php?action=edit&id='.(int)$host['id'])).'"><title>'.tp_h($host['description']).'</title><rect x="'.($x-100).'" y="'.($y-32).'" width="200" height="64" rx="3" fill="none" stroke="currentColor"/><text x="'.$x.'" y="'.($y-3).'" fill="currentColor" text-anchor="middle">'.tp_h($label).'</text><text x="'.$x.'" y="'.($y+17).'" fill="currentColor" text-anchor="middle">Cacti device '.(int)$host['id'].'</text></a>';
    }
    print '</svg></td></tr>';html_end_box();
}
/** Display a known interface label while retaining its native numeric index. */
function tp_endpoint_label($endpoint,$hosts,$snapshots) {
    $label='ifIndex '.$endpoint[1];
    foreach($snapshots as $s) if((int)$s['host_id']===$endpoint[0] && isset($s['data']['interfaces'][$endpoint[1]])) {
        $port=$s['data']['interfaces'][$endpoint[1]];
        if($port['name']!=='') {$label=$port['name'].' ('.$label.')';break;}
    }
    return $hosts[$endpoint[0]]['description'].' / '.$label;
}
/** Report interface observations separately from neighbor reciprocity and native device availability. */
function tp_link_interfaces($link,$snapshots,$now,$stale) {
    $out=array();
    foreach(array($link['a'],$link['b']) as $endpoint) {
        $states=array();
        foreach($snapshots as $s) if((int)$s['host_id']===$endpoint[0] && $s['valid'] && $s['status']==='success' && tp_evidence_fresh($s['data']['collected'] ?? 0,$now,$stale)) {
            $oper=$s['data']['interfaces'][$endpoint[1]]['oper'] ?? null;
            if($oper!==null) $states[(string)$oper]=true;
        }
        $names=array('1'=>'Up','2'=>'Down','3'=>'Testing','4'=>'Unknown','5'=>'Dormant','6'=>'Not present','7'=>'Lower layer down');
        $out[]=count($states)===1?($names[(string)array_key_first($states)] ?? 'Unknown'):(count($states)?'Conflicting observations':'Unknown / stale');
    }
    return implode(' / ',$out);
}
