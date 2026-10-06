<?php
require __DIR__.'/../../presets/services/rack_preset_service.php';
function db_fetch_assoc($query){return [['id'=>21,'site_id'=>0,'name'=>'Rack 1','profile_id'=>'0123456789abcdef','unit_count'=>12],['id'=>22,'site_id'=>0,'name'=>'Rack 2','profile_id'=>'fedcba9876543210','unit_count'=>24]];}
function db_fetch_cell_prepared($sql,$args){return '';}
function db_fetch_assoc_prepared($sql,$args){return $args[0]===21 && $args[1]!==7 ? [['start_unit'=>3,'unit_height'=>2]] : [];}
$choices=icct_nms_device_rack_choices();
if(array_column($choices,'name')!==['Rack 1','Rack 2'] || array_column($choices,'unit_count')!==[12,24] || $choices[0]['occupied']!==[3,4])throw new RuntimeException('Catalogue choices or occupied units incorrect');
if(icct_nms_device_rack_choices(7)[0]['occupied']!==[])throw new RuntimeException('Own placement cannot be edited');
echo "PASS: one choice per saved rack, preset capacities and occupied-unit exclusion.\n";
