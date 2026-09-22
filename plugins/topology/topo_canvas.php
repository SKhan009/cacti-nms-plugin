<?php
/** Single-node canvas within Cacti's native Console, with protected JSON mutations. */
require(__DIR__.'/../../include/auth.php');
require_once(__DIR__.'/includes/ui.php');
require_once(__DIR__.'/includes/browse.php');
require_once(__DIR__.'/includes/flow.php');
require_once(__DIR__.'/includes/physical.php');
$error='';$unitId=tp_scope_id();$category=tp_id($_POST['category_id']??$_GET['category_id']??0,true);
try {
    tp_ready();
    $unitId=tp_scope_id();
    if($_SERVER['REQUEST_METHOD']==='POST') {
        switch($_POST['tp_action']??'') {
            case 'connect': tp_cable_add($unitId,$_POST['port_a']??'',$_POST['port_b']??'');break;
            case 'disconnect': tp_cable_delete($unitId,$_POST['cable_id']??'');break;
            case 'layout': tp_layout_save($unitId,$_POST['revision']??'',json_decode($_POST['positions']??'',true,16,JSON_THROW_ON_ERROR));break;
            default: throw new InvalidArgumentException('Unknown topology action.');
        }
        if(($_POST['format']??'')==='json') {header('Content-Type: application/json');print json_encode(array('ok'=>true,'data'=>tp_canvas_data($unitId,$category)),JSON_THROW_ON_ERROR);exit;}
        raise_message('topology_canvas_saved','Topology saved.',MESSAGE_LEVEL_INFO);
        header('Location: topo_canvas.php?unit_id='.$unitId);exit;
    }
} catch(Throwable $e) {
    if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['format']??'')==='json') {http_response_code(400);header('Content-Type: application/json');print json_encode(array('ok'=>false,'error'=>$e instanceof JsonException?'Invalid layout data.':$e->getMessage()));exit;}
    $error=$e->getMessage();
}
top_header();
if($error) tp_notice('Topology error',$error);
try {
    tp_ready();

    $data=tp_canvas_data($unitId,$category);
        html_start_box('Filter Devices','100%',false,3,'center','');
        print '<tr><td><form method="get" id="tp-canvas-filter"><label for="tp-canvas-category">Device Segment</label> <select name="category_id" id="tp-canvas-category"><option value="0">All Segments</option>';
        foreach(tp_category_options() as $id=>$name)if($id)print '<option value="'.(int)$id.'"'.((int)$id===$category?' selected':'').'>'.tp_h($name).'</option>';
        print '</select> <button type="submit" class="ui-button ui-corner-all ui-widget">Go</button></form></td></tr>';html_end_box();
        print '<script '.CactiSecureHeaders::getNonceAttribute().'>$(function(){$("#tp-canvas-category").on("selectmenuchange",function(){document.getElementById("tp-canvas-filter").requestSubmit();});});</script>';
            print '<link rel="stylesheet" href="'.tp_h(tp_url('plugins/topology/assets/canvas.css')).'?v=090">';
            html_start_box('Topology View','100%',false,3,'center','');
            print '<tr><td><div id="tp-canvas-app"><div class="tp-toolbar"><button type="button" id="tp-save-layout" class="ui-button ui-corner-all ui-widget" disabled>Save Layout</button> <button type="button" id="tp-arrange" class="ui-button ui-corner-all ui-widget">Arrange Devices</button> <button type="button" id="tp-disconnect" class="ui-button ui-corner-all ui-widget" disabled>Disconnect</button><div class="tp-zoom-controls" aria-label="Canvas zoom"><button type="button" id="tp-zoom-out" class="ui-button ui-corner-all ui-widget" aria-label="Zoom out" title="Zoom out">−</button><output id="tp-zoom-level" aria-live="polite">100%</output><button type="button" id="tp-zoom-in" class="ui-button ui-corner-all ui-widget" aria-label="Zoom in" title="Zoom in">+</button><button type="button" id="tp-fit" class="ui-button ui-corner-all ui-widget">Fit to Screen</button></div><span id="tp-canvas-message" role="status" aria-live="polite">Drag device headers to arrange. Drag a port onto another device’s port to connect.</span></div><div class="tp-viewport" id="tp-viewport"><div id="tp-stage"><div id="tp-canvas"><svg id="tp-wires" width="2400" height="1400" aria-hidden="true"></svg></div></div></div><div class="tp-legend" aria-label="Topology color legend"><span class="tp-legend-up">● Up</span><span class="tp-legend-down">● Down / Error</span><span class="tp-legend-recovering">● Recovering</span><span class="tp-legend-unknown">● Unknown / Disabled</span><span class="tp-legend-cable">━ Configured cable</span><span>○ Available port</span></div><div id="tp-category-legend" class="tp-legend" aria-label="Device segment colors"></div><p class="tp-caption">Device status: latest Cacti result. Cable color represents configuration.</p></div></td></tr>';
            html_end_box();
            // Cacti injects and validates its standard CSRF token in this native POST form.
            print '<form id="tp-canvas-token" method="post" action="topo_canvas.php"><input type="hidden" name="unit_id" value="'.$unitId.'"><input type="hidden" name="category_id" value="'.$category.'"></form>';
            print '<script type="application/json" id="tp-canvas-data">'.json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';
            print '<script src="'.tp_h(tp_url('plugins/topology/assets/canvas.js')).'?v=090" '.CactiSecureHeaders::getNonceAttribute().'></script>';
} catch(Throwable $e) {tp_notice('Configuration required',$e->getMessage());}
tp_footer();
