<?php
/** Native Discovery tabs with one retained configuration form across settings and protocols. */
require(__DIR__.'/../../include/auth.php');
require_once(__DIR__.'/includes/ui.php');
require_once(__DIR__.'/includes/discovery_profiles.php');
$error='';$profileMessage='';$site=tp_scope_id();
$tabs=array('settings','protocols','results','profiles');
$tab=$_POST['discovery_tab']??$_GET['tab']??'settings';
if(!is_string($tab)||!in_array($tab,$tabs,true))$tab='settings';
if(($_GET['edit']??'')==='profile'||($_POST['tp_action']??'')==='profile')$tab='profiles';

try {
    tp_ready();
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $action=$_POST['tp_action']??'';$destination='topo_discovery.php?tab='.$tab;
        if($action==='load_profile') {
            $profileId=tp_id($_POST['saved_profile']??0);
            $loaded=db_fetch_row_prepared('SELECT * FROM plugin_topology_discovery_profiles WHERE id=? AND active=1',array($profileId));
            if(!$loaded)throw new InvalidArgumentException('Select an active saved profile.');
            $_POST['enabled']=$loaded['enabled'];$_POST['interval_seconds']=$loaded['interval_seconds'];$_POST['stale_seconds']=$loaded['stale_seconds'];
            if(!empty($_POST['use_profile_protocol'])){
                $_POST['protocols']=array();foreach(tp_discovery_hosts($site) as $host)$_POST['protocols'][(int)$host['id']]=$loaded['protocol'];
            }
            $profileMessage='Profile loaded. Save to apply these settings.';
        } elseif($action==='save'||$action==='save_run') {
            tp_discovery_config_save($_POST['enabled']??'',$_POST['interval_seconds']??'',$_POST['stale_seconds']??'',$_POST['protocols']??array());
            $message='Discovery settings saved.';
            if($action==='save_run') {
                try{$job=tp_discovery_queue($site);$message.=' Discovery job '.$job.' queued.';$destination='topo_discovery.php?tab=results';}
                catch(Throwable $e){throw new RuntimeException('Settings saved, but discovery could not be queued. '.$e->getMessage());}
            }
        } elseif($action==='run') {
            $job=tp_discovery_queue($site);$message='Discovery job '.$job.' queued using saved settings.';$destination='topo_discovery.php?tab=results';
        } elseif($action==='profile') {
            tp_discovery_profile_save($_POST['id']??'',$_POST['name']??'',$_POST['description']??'',$_POST['protocol']??'',$_POST['profile_enabled']??'',$_POST['profile_interval_seconds']??'',$_POST['profile_stale_seconds']??'',$_POST['active']??'');
            $message='Discovery profile saved.';$destination='topo_discovery.php?tab=profiles';
        }else throw new InvalidArgumentException('Reload Discovery to use the current configuration form.');
        if($action!=='load_profile'){raise_message('topology_discovery',$message,MESSAGE_LEVEL_INFO);header('Location: '.$destination);exit;}
    }
}catch(Throwable $e){$error=$e->getMessage();}
top_header();tp_discovery_tabs($tab);if($error)tp_notice('Discovery error',$error);
try {
    tp_ready();$hosts=tp_discovery_hosts($site);$physical=tp_physical_hosts($site);
    $policy=db_fetch_row_prepared('SELECT * FROM plugin_topology_discovery_sites WHERE unit_id=?',array($site));
    $editingProfile=($_GET['edit']??'')==='profile'||($_POST['tp_action']??'')==='profile';
    $posted=in_array($_POST['tp_action']??'',array('save','save_run','load_profile'),true)?$_POST:array();
    $profiles=db_fetch_assoc('SELECT * FROM plugin_topology_discovery_profiles ORDER BY name');
    form_start('topo_discovery.php','tp-discovery-config');
    print '<input type="hidden" name="tp_action" id="tp-config-action" value="save"><input type="hidden" name="discovery_tab" id="tp-discovery-tab" value="'.tp_h($tab).'">';
    print '<section data-discovery-panel="settings"'.($tab!=='settings'?' hidden':'').'>';
    html_start_box('Discovery Configuration','100%',false,3,'center','');
    print '<tr><td><label for="tp-saved-profile">Saved Profile</label> <select id="tp-saved-profile" name="saved_profile"><option value="">Custom / Current Settings</option>';
    foreach($profiles as $p)if($p['active'])print '<option value="'.(int)$p['id'].'"'.((string)($posted['saved_profile']??'')===(string)$p['id']?' selected':'').'>'.tp_h($p['name']).'</option>';
    print '</select> <label><input id="tp-profile-protocol" name="use_profile_protocol" value="1" type="checkbox"> Use profile protocol for all devices</label> <button id="tp-load-profile" class="ui-button ui-corner-all ui-widget" type="submit" data-tp-action="load_profile">Load Profile</button> <span id="tp-profile-message" role="status">'.tp_h($profileMessage).'</span></td></tr>';html_end_box();
    html_start_box('Collection Settings','100%',false,3,'center','');
    draw_edit_form(array('config'=>array('no_form_tag'=>true),'fields'=>array(
        'enabled'=>tp_select('Schedule',array(''=>'Select schedule',0=>'Manual only',1=>'Scheduled'),$posted['enabled']??$policy['enabled']??''),
        'interval_seconds'=>tp_field('Collection Interval (seconds)',$posted['interval_seconds']??$policy['interval_seconds']??'','60–86400 seconds.',6),
        'stale_seconds'=>tp_field('Stale After (seconds)',$posted['stale_seconds']??$policy['stale_seconds']??'','At least twice the collection interval; maximum 604800 seconds.',6)
    )));html_end_box();
    print '</section><section data-discovery-panel="protocols"'.($tab!=='protocols'?' hidden':'').'>';
    $rows=array();foreach($hosts as $h){
        $id=(int)$h['id'];$value=$posted['protocols'][$id]??$h['protocol'];
        $select='<select class="tp-device-protocol" name="protocols['.$id.']" aria-label="Protocol for '.tp_h($h['description']).'">';
        foreach(tp_protocol_options() as $key=>$label)$select.='<option value="'.$key.'"'.($value===$key?' selected':'').'>'.tp_h($label).'</option>';
        $select.='</select>';
        $rows[]=array(tp_h($h['description']),tp_h($physical[$id]['category']??''),$select);
    }
    tp_table('Device Protocols',array('Device','Device Segment','Neighbor Protocol'),$rows,'','Assign devices in the Setup Guide to configure discovery.');
    print '</section><section id="tp-discovery-save"'.(!in_array($tab,array('settings','protocols'),true)?' hidden':'').'><div class="saveMain"><button type="submit" data-tp-action="save" class="ui-button ui-corner-all ui-widget">Save</button> <button type="submit" data-tp-action="save_run" class="ui-button ui-corner-all ui-widget"'.(!$hosts?' disabled':'').'>Save &amp; Discover Now</button></div></section></form>';
    print '<script '.CactiSecureHeaders::getNonceAttribute().'>$(function(){document.querySelectorAll("#tp-discovery-config button[data-tp-action]").forEach(function(button){button.addEventListener("click",function(){document.getElementById("tp-config-action").value=button.dataset.tpAction;},true);});});</script>';
    print '<section data-discovery-panel="results"'.($tab!=='results'?' hidden':'').'>';form_start('topo_discovery.php?tab=results','tp-discovery-run');print '<input type="hidden" name="tp_action" value="run"><button type="submit" class="ui-button ui-corner-all ui-widget">Discover Now</button> Uses saved settings.</form>';
    $rows=array();foreach(db_fetch_assoc_prepared('SELECT id,state,requested_at,message FROM plugin_topology_jobs WHERE unit_id=? ORDER BY id DESC',array($site)) as $job)$rows[]=array((int)$job['id'],tp_h($job['state']),tp_h($job['requested_at']),tp_h($job['message']));
    $refreshActions=array(array('id'=>'tp-discovery-refresh','class'=>'fa fa-sync','href'=>'topo_discovery.php?tab=results','title'=>'Refresh Discovery Results'));
    tp_table('Recent Discovery Results',array('Job','State','Requested','Result'),$rows,$refreshActions,'No discovery jobs yet.');
    print '</section><section data-discovery-panel="profiles"'.($tab!=='profiles'?' hidden':'').'><div id="profiles"></div>';
    $rows=array();foreach($profiles as $p)$rows[]=array(tp_h($p['name']),tp_h(tp_protocol_options(false)[$p['protocol']]),($p['enabled']?'Scheduled':'Manual only').' / '.(int)$p['interval_seconds'].'s / stale '.(int)$p['stale_seconds'].'s',$p['active']?'Active':'Archived','<a class="ui-button ui-corner-all ui-widget" href="topo_discovery.php?tab=profiles&amp;edit=profile&amp;id='.(int)$p['id'].'#profile-editor">Edit</a>');
    tp_table('Saved Discovery Profiles',array('Profile','Protocol','Timing','State','Action'),$rows,'topo_discovery.php?tab=profiles&edit=profile#profile-editor');
    if($editingProfile){
        $id=tp_id($_POST['id']??$_GET['id']??0,true);$p=$id?db_fetch_row_prepared('SELECT * FROM plugin_topology_discovery_profiles WHERE id=?',array($id)):array();
        if($id&&!$p)throw new InvalidArgumentException('Profile no longer exists.');
        $v=function($k,$d='')use($p){return ($_POST['tp_action']??'')==='profile'?($_POST[in_array($k,array('enabled','interval_seconds','stale_seconds'),true)?'profile_'.$k:$k]??$p[$k]??$d):($p[$k]??$d);};
        print '<div id="profile-editor"></div>';
        tp_form('Discovery Profile','profile',array(
            'id'=>array('method'=>'hidden','value'=>$id),'name'=>tp_field('Profile Name',$v('name')),'description'=>tp_field('Description',$v('description'),'',255),
            'protocol'=>tp_select('Neighbor Protocol',array(''=>'Select protocol')+tp_protocol_options(false),$v('protocol')),
            'profile_enabled'=>tp_select('Schedule',array(''=>'Select schedule',0=>'Manual only',1=>'Scheduled'),$v('enabled')),
            'profile_interval_seconds'=>tp_field('Collection Interval (seconds)',$v('interval_seconds'),'60–86400 seconds.',6),
            'profile_stale_seconds'=>tp_field('Stale After (seconds)',$v('stale_seconds'),'At least twice the interval; maximum 604800 seconds.',6),
            'active'=>tp_select('Profile State',array(1=>'Active',0=>'Archived'),$v('active',1))
        ));
    }
    print '</section>';
}catch(Throwable $e){tp_notice('Configuration required',$e->getMessage());}
print '<script src="'.tp_h(tp_url('plugins/topology/assets/discovery-tabs.js')).'?v=0120" '.CactiSecureHeaders::getNonceAttribute().'></script>';
tp_footer();
