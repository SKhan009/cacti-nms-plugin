<?php
require $argv[1] ?? __DIR__.'/../../plugins/nms/includes/topology/appearance_images.php';
$source=imagecreatetruecolor(1024,256);
ob_start(); imagepng($source); $png=ob_get_clean(); imagedestroy($source);
$uri=nms_appearance_image_encode($png);
$decoded=base64_decode(substr($uri,strpos($uri,',')+1));
$size=getimagesizefromstring($decoded);
if ($size[0]!==512 || $size[1]!==128 || $size[2]!==IMAGETYPE_WEBP) throw new Exception('Resize/encoding failed');
foreach (['<svg onload="alert(1)"></svg>', 'not an image',str_repeat('x',2097153)] as $bad) {
    try { nms_appearance_image_encode($bad); throw new Exception('Invalid upload accepted'); }
    catch (InvalidArgumentException $expected) {}
}
if (nms_appearance_image_upload(null)!==null) throw new Exception('Empty upload must preserve image');
try { nms_appearance_image_upload(['error'=>UPLOAD_ERR_OK,'tmp_name'=>__FILE__]); throw new Exception('Non-upload accepted'); }
catch (InvalidArgumentException $expected) {}
echo "PASS: resize, WebP encoding, invalid/SVG/oversize rejection, empty upload, upload origin validation\n";
$modes=nms_appearance_display_modes(['display_network'=>'icon','display_rack'=>'image','display_map'=>'icon'],[],true);
if ($modes!==['network'=>'icon','rack'=>'image','map'=>'icon']) throw new Exception('Independent display choices failed');
if (nms_appearance_display_mode(['image_key'=>'existing'],'map')!=='image') throw new Exception('Legacy image changed');
foreach ([['display_network'=>'image'],['display_map'=>'invalid']] as $invalid) {
    try { nms_appearance_display_modes($invalid,[],false); throw new Exception('Invalid display choice accepted'); }
    catch (InvalidArgumentException $expected) {}
}
echo "PASS: independent display modes, legacy fallback, missing-image and invalid-mode rejection\n";

foreach (['network','rack','map'] as $view) {
    $modes=nms_appearance_display_modes(['display_'.$view=>'none'],[],false);
    $saved=json_decode(json_encode(['display_modes'=>$modes]),true);
    if (nms_appearance_display_mode($saved,$view)!=='none') throw new Exception('None did not survive save/reload');
}
$modes=nms_appearance_display_modes(['display_network'=>'none','display_rack'=>'none','display_map'=>'none'],['image_key'=>'existing'],false);
if (array_unique(array_values($modes))!==['none']) throw new Exception('Removing an image with None selected failed');
echo "PASS: None in every view, save/reload and image removal without forced icon fallback\n";
