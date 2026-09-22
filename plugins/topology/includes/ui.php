<?php
/** Native Cacti rendering helpers; application navigation and forms use the core theme. */
require_once(__DIR__.'/model.php');
/** Encode all labels and form values using Cacti's normal HTML escaping. */
function tp_h($value) { return html_escape((string)$value); }
/** Build installation-relative native or plugin URLs. */
function tp_url($path) { global $config; return $config['url_path'].$path; }
/** Render a standard Cacti information box. */
function tp_notice($title,$text) {
    html_start_box(tp_h($title),'100%',false,3,'center','');
    print '<tr><td>'.tp_h($text).'</td></tr>'; html_end_box();
}
/** Render native text input metadata. */
function tp_field($label,$value='',$description='',$max=100) {
    return array('method'=>'textbox','friendly_name'=>$label,'description'=>$description,'value'=>$value,'max_length'=>$max,'size'=>50);
}
/** Render native select metadata; the empty choice requires an explicit selection. */
function tp_select($label,$options,$value='',$description='') {
    return array('method'=>'drop_array','friendly_name'=>$label,'description'=>$description,'array'=>$options,'value'=>$value);
}
/** Render a native Cacti form with CSRF protection supplied by Cacti's bootstrap. */
function tp_form($title,$action,$fields,$multipart=false,$button='save') {
    global $tp_flow;
    if(!empty($tp_flow)) foreach(array('step') as $key) $fields['flow_'.$key]=array('method'=>'hidden','value'=>$tp_flow[$key]);
    $page=basename($_SERVER['SCRIPT_NAME']);
    form_start($page,'topology_form',$multipart);
    html_start_box(tp_h($title),'100%',true,3,'center','');
    $fields['tp_action']=array('method'=>'hidden','value'=>$action);
    draw_edit_form(array('config'=>array('no_form_tag'=>true),'fields'=>$fields));
    html_end_box(true,true);
    form_save_button($page,'save','id',false);
}
/** Print a native table with already-escaped cells. */
function tp_table($title,$headers,$rows,$add='',$empty='No records configured.',$paginate=true) {
    static $sequence=0;$sequence++;
    if($paginate)print '<div class="tp-paged-list" id="tp-paged-'.$sequence.'" data-title="'.tp_h($title).'" data-total="'.count($rows).'">';
    if($paginate){
        $total=count($rows);$sizes=array(25,50,100,250);$navs=array();
        foreach($sizes as $size)for($page=1;$page<=max(1,(int)ceil($total/$size));$page++)
            $navs[$size][$page]=html_nav_bar(basename($_SERVER['SCRIPT_NAME']),MAX_DISPLAY_PAGES,$page,$size,$total,count($headers),'Rows','tp_page','main');
        if($total>25)print '<table class="filterTable"><tr><td><label for="tp-rows-'.$sequence.'">Rows</label></td><td><select id="tp-rows-'.$sequence.'" class="tp-page-size" aria-label="'.tp_h($title).' rows"><option>25</option><option>50</option><option>100</option><option>250</option></select></td></tr></table>';
        print '<div class="tp-native-nav" aria-label="'.tp_h($title).' pagination top">'.$navs[25][1].'</div>';
    }
    html_start_box(tp_h($title),'100%',false,3,'center',$add);
    html_header(array_map('tp_h',$headers));
    if (!$rows) print '<tr><td colspan="'.count($headers).'">'.tp_h($empty).'</td></tr>';
    foreach ($rows as $i=>$row) {
        print '<tr class="'.($i%2?'even':'odd').'">';
        foreach ($row as $cell) print '<td>'.tp_list_links($cell).'</td>';
        print '</tr>';
    }
    html_end_box();
    if($paginate){
        print '<div class="tp-native-nav" aria-label="'.tp_h($title).' pagination bottom">'.$navs[25][1].'</div>';
        print '<script type="application/json" class="tp-native-pages" '.CactiSecureHeaders::getNonceAttribute().'>'.json_encode($navs,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script></div>';
    }
}
/** List navigation uses the native text-link style; submitted actions remain buttons. */
function tp_list_links($html){return preg_replace_callback('/<a\b[^>]*>/i',function($m){return preg_replace('/\sclass="[^"]*"/i','',$m[0]);},(string)$html);}
/** Return accessible sites with explicit empty selection, optionally limited to units. */
function tp_site_options() {
    $options=array(''=>'Select a site');
    foreach(tp_sites() as $site) $options[$site['id']]=$site['name'];
    return $options;
}
require_once(__DIR__.'/../templates/submenu.php');
/** Return active device segments without borrowing NMS category IDs. */
function tp_category_options() {
    $options=array(''=>'Select a category');
    foreach(db_fetch_assoc('SELECT id,name FROM plugin_topology_categories WHERE active=1 ORDER BY name') as $row) $options[$row['id']]=$row['name'];
    return $options;
}
/** Keep Cacti's Main Console shortcut rooted correctly when its page is rendered inside a plugin directory. */
function tp_footer() {
    global $tp_flow;
    if(!empty($tp_flow)&&basename($_SERVER['SCRIPT_NAME'])!=='topo_start.php') {
        $flow=json_encode($tp_flow,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        print '<script '.CactiSecureHeaders::getNonceAttribute().'>$(function(){const f='.$flow.';const prefix=new URL(".",location.href).pathname;document.querySelectorAll("#main a[href],#main form").forEach(function(e){if(e.hasAttribute("data-topology-list")||e.id==="tp-list-filter")return;const u=new URL(e.tagName==="FORM"?(e.getAttribute("action")||location.href):e.href,location.href);if(u.origin!==location.origin||!u.pathname.startsWith(prefix)||u.pathname.endsWith("topo_start.php"))return;for(const k of ["step"]){if(e.tagName==="FORM"){if(!e.querySelector("input[name=flow_"+k+"]")){const i=document.createElement("input");i.type="hidden";i.name="flow_"+k;i.value=f[k];e.appendChild(i);}}else if(!u.searchParams.has("flow_"+k))u.searchParams.set("flow_"+k,f[k]);}if(e.tagName!=="FORM")e.href=u.href;});});</script>';
    }

    // Cacti uses a long no-logout sentinel; keep it within the browser timer range.
    $url=json_encode(tp_url('index.php'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    print '<script '.CactiSecureHeaders::getNonceAttribute().'>$(function(){ if(typeof refreshMSeconds!=="undefined" && !refreshIsLogout && refreshMSeconds>2147483647){refreshMSeconds=2147483647;setupPageTimeout();} $("#menu_main_console > a").attr("href",'.$url.'); });</script>';
    print '<script src="'.tp_h(tp_url('plugins/topology/assets/lists.js')).'?v=0112" '.CactiSecureHeaders::getNonceAttribute().'></script>';
    bottom_footer();
}
