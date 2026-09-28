<?php
/** Decode and re-encode uploaded pictures; never retain active SVG or source bytes. */
function nms_appearance_image_upload($file)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new InvalidArgumentException('Image upload failed. Select the image again.');
    if (filesize($file['tmp_name']) > 2097152) throw new InvalidArgumentException('Use an image smaller than 2 MB.');
    return nms_appearance_image_encode(file_get_contents($file['tmp_name']));
}
function nms_appearance_image_encode($bytes)
{
    if (!is_string($bytes) || strlen($bytes) > 2097152) throw new InvalidArgumentException('Use an image smaller than 2 MB.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 16000000) throw new InvalidArgumentException('Use a PNG, JPEG or WebP image up to 16 megapixels.');
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) throw new RuntimeException('Image uploads require PHP GD with WebP support.');
    $source = @imagecreatefromstring($bytes);
    if (!$source) throw new InvalidArgumentException('The image could not be decoded.');
    try {
        $scale = min(1, 512 / max($info[0], $info[1]));
        $target = imagecreatetruecolor(max(1,(int)($info[0]*$scale)), max(1,(int)($info[1]*$scale)));
        imagealphablending($target, false); imagesavealpha($target, true);
        imagecopyresampled($target,$source,0,0,0,0,imagesx($target),imagesy($target),$info[0],$info[1]);
        ob_start(); imagewebp($target,null,80); $encoded=ob_get_clean(); imagedestroy($target);
        $uri='data:image/webp;base64,'.base64_encode($encoded);
        if (!$encoded || strlen($uri)>60000) throw new InvalidArgumentException('Image is too detailed. Use a smaller or simpler image.');
        return $uri;
    } finally { imagedestroy($source); }
}
function nms_appearance_image($profile)
{
    $key=$profile['image_key'] ?? '';
    if (!preg_match('/^appearance_image_[a-f0-9]{16}$/D',$key)) return '';
    static $cache=[];
    if (!isset($cache[$key])) {
        $value=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?',[$key]);
        $cache[$key]=preg_match('~^data:image/webp;base64,[A-Za-z0-9+/=]+$~D',$value) ? $value : '';
    }
    return $cache[$key];
}

/** Existing uploads retain their prior display until the operator chooses otherwise. */
function nms_appearance_display_mode($profile, $view)
{
    $mode=$profile['display_modes'][$view] ?? (empty($profile['image_key']) ? 'icon' : 'image');
    return in_array($mode, ['none', 'icon', 'image'], true) ? $mode : 'icon';
}
function nms_appearance_display_modes($input, $profile, $has_image)
{
    $modes=[];
    foreach (['network','rack','map'] as $view) {
        $mode=$input['display_'.$view] ?? nms_appearance_display_mode($profile,$view);
        if (!is_string($mode) || !in_array($mode,['none','icon','image'],true)) throw new InvalidArgumentException('Choose None, Icon or Image for each topology view.');
        if ($mode==='image' && !$has_image) throw new InvalidArgumentException('Upload an image or choose None or Icon for each view.');
        $modes[$view]=$mode;
    }
    return $modes;
}
