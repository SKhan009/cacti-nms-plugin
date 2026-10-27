#!/usr/bin/env python3
"""Exercise real multipart uploads and image cleanup in an isolated PHP/GD server.

Usage: python3 device_type_image_upload_test.py [php-binary]
No database, production files, or internet connection are used.
"""
import http.client
import json
from pathlib import Path
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import time
import zlib

PLUGIN = Path(__file__).resolve().parents[2]
ROUTER = r'''<?php
require __DIR__.'/presets/services/device_type_service.php';
set_error_handler(function($severity,$message){throw new ErrorException($message,0,$severity);});
$old=$_POST['existing_image']??'';
$catalogue=['fixture'=>['name'=>'Fixture','category_id'=>0,'icon'=>'device','image'=>$old,'display_modes'=>['network'=>'icon','rack'=>'icon','map'=>'icon']]];
function db_fetch_cell($sql){return 'isolated_upload_test';}
function db_fetch_assoc($sql){return [];}
function db_fetch_cell_prepared($sql,$args){
    if(str_contains($sql,'meta_value'))return json_encode($GLOBALS['catalogue']);
    if(str_contains($sql,'COUNT(*)'))return 0;
    return 1;
}
function icct_backend_classification_text($text,$max){return trim($text);}
function icct_backend_topology_integer($value,$min,$max,$label){return (int)$value;}
function icct_backend_category_execute($sql,$args=[]){
    if(str_starts_with($sql,'INSERT INTO plugin_icct_nms_meta')){
        if(!empty($_POST['fail_save']))throw new RuntimeException('Fixture save failed');
        $GLOBALS['catalogue']=json_decode($args[1],true);
    }
}
header('Content-Type: application/json');
try{
    $input=['action'=>$_POST['action']??'save_type','type_id'=>'fixture','type_name'=>'Fixture','icon'=>'device'];
    icct_nms_save_device_type($input,$_FILES['device_image']??null);
    $image=$GLOBALS['catalogue']['fixture']['image']??'';
    $path=__DIR__.'/shared/assets/images/device-types/'.$image;
    $info=$image?getimagesize($path):null;
    echo json_encode(['image'=>$image,'size'=>$info?[$info[0],$info[1]]:null,
        'asset'=>icct_nms_type_asset(['image'=>$image,'display_modes'=>['network'=>'image']]),
        'old_exists'=>$old&&is_file(__DIR__.'/shared/assets/images/device-types/'.$old)]);
}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);}
'''


def png(width, height):
    def chunk(kind, data):
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data))
    raw = (b'\0' + b'\x25\x80\xab\x80' * width) * height
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', width, height, 8, 6, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))


def request(port, fields, image=None):
    boundary = 'icct-device-image-regression'
    body = bytearray()
    for key, value in fields.items():
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    if image is not None:
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="device_image"; filename="device.png"\r\nContent-Type: image/png\r\n\r\n'.encode())
        body.extend(image + b'\r\n')
    body.extend(f'--{boundary}--\r\n'.encode())
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    connection.request('POST', '/', body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
    response = connection.getresponse()
    result = json.loads(response.read())
    status = response.status
    connection.close()
    return status, result


def main():
    php = sys.argv[1] if len(sys.argv) > 1 else 'php'
    with tempfile.TemporaryDirectory(prefix='icct-image-upload-') as directory:
        root = Path(directory)
        service = root / 'presets/services/device_type_service.php'
        service.parent.mkdir(parents=True)
        shutil.copy2(PLUGIN / 'presets/services/device_type_service.php', service)
        icons = root / 'shared/assets/images/icons'
        icons.mkdir(parents=True)
        (icons / 'device.svg').write_text('<svg xmlns="http://www.w3.org/2000/svg"/>')
        uploads = root / 'shared/assets/images/device-types/uploads'
        uploads.mkdir(parents=True)
        (root / 'router.php').write_text(ROUTER)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        with tempfile.TemporaryFile() as log:
            server = subprocess.Popen([php, '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', str(root / 'router.php')], cwd=root, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        if server.poll() is not None:
                            raise RuntimeError('PHP server exited')
                        time.sleep(.05)
                status, first = request(port, {}, png(600, 300))
                assert status == 200, first
                assert first['size'] == [512, 256], first
                assert (uploads.parent / first['image']).is_file(), first
                assert first['asset'] == 'shared/assets/images/device-types/' + first['image'], first
                print('PASS: real multipart image upload saves and resolves under device-types/uploads')
                status, second = request(port, {'existing_image': first['image']}, png(16, 16))
                assert status == 200 and not second['old_exists'], second
                assert len(list(uploads.glob('*.png'))) == 1
                print('PASS: replacing a device image removes the previous file')
                before = set(uploads.iterdir())
                status, failed = request(port, {'existing_image': second['image'], 'fail_save': '1'}, png(16, 16))
                assert status == 400 and failed['error'] == 'Fixture save failed', failed
                assert set(uploads.iterdir()) == before
                print('PASS: failed preset save cleans up the new upload and preserves the old image')
                status, invalid = request(port, {}, b'not an image')
                assert status == 400 and 'PNG, JPEG or WebP' in invalid['error'], invalid
                assert set(uploads.iterdir()) == before
                print('PASS: invalid image is rejected without changing saved files')
                status, deleted = request(port, {'existing_image': second['image'], 'action': 'delete_type'})
                assert status == 200 and not deleted['old_exists'], deleted
                assert not list(uploads.iterdir())
                print('PASS: deleting an unused device type removes its uploaded image')
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == '__main__':
    main()
