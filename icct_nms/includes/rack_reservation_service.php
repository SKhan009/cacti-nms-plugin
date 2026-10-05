<?php
/** Reservations occupy real rack units without creating Cacti hosts. */
function icct_nms_rack_reservations($rack) {
    $json=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_reserved_'.$rack]);
    return $json ? json_decode($json,true,512,JSON_THROW_ON_ERROR) : [];
}
function icct_nms_rack_reservation_revision($rack) {return hash('sha256',json_encode(icct_nms_rack_reservations($rack)));}
function icct_nms_rack_reserved_units($rack) {
    $units=[];foreach(icct_nms_rack_reservations($rack) as $item)for($u=$item['start'];$u<$item['start']+$item['height'];$u++)$units[]=$u;return $units;
}
