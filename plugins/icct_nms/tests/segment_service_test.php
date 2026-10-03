<?php
require_once __DIR__.'/../includes/services/categories.php';
require_once __DIR__.'/../includes/segment_service.php';
function icct_nms_id($value) { return (int)$value; }
function icct_backend_current_user_id() { return 7; }
$exists = true; $duplicate = false; $references = 0; $writes = [];
function db_fetch_cell_prepared($sql,$params) {
    global $exists,$duplicate,$references;
    if (strpos($sql,'LOWER(name)') !== false) return $duplicate ? 9 : 0;
    if (strpos($sql,'plugin_icct_nms_categories') !== false) return $exists ? 1 : 0;
    return $references;
}
function db_execute_prepared($sql,$params) { global $writes; $writes[]=[$sql,$params]; return true; }
function rejected($input,$expected) { global $writes; $before=count($writes); try { icct_nms_save_segment($input); throw new RuntimeException('Expected validation error'); } catch (InvalidArgumentException $e) { if (strpos($e->getMessage(),$expected) === false || count($writes)!==$before) throw new RuntimeException('Invalid request wrote data or gave incorrect feedback'); } }
rejected(['segment_name'=>'   '],'Enter a segment name');
$duplicate=true; rejected(['segment_name'=>'Network'],'already exists'); $duplicate=false;
$references=1; rejected(['action'=>'delete_segment','segment_id'=>2],'in use'); $references=0;
$exists=false; rejected(['segment_id'=>2,'segment_name'=>'Network'],'no longer exists'); $exists=true;
icct_nms_save_segment(['segment_name'=>'New Segment']);
icct_nms_save_segment(['segment_id'=>2,'segment_name'=>'Renamed Segment']);
if (count($writes)!==2 || $writes[1][1]!==['Renamed Segment',7,2]) throw new RuntimeException('Save failed to retain identity or audit user');
echo "Segment validation and persistence tests passed.\n";
