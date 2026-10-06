<?php
require_once __DIR__ . "/../../../../presets/services/rack_reservation_service.php";
/** ICCT-owned topology config services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: topology config apply. */
function icct_backend_topology_config_apply($action, $site_id, $input)
{
    $user = icct_backend_current_user_id();
    $rack_id = icct_backend_topology_integer($input['rack_id'], 1, PHP_INT_MAX, 'Rack ID');
    $rack = db_fetch_row_prepared(
        'SELECT r.* FROM plugin_icct_nms_racks r WHERE r.id = ? AND (r.site_id = 0 OR r.site_id = ?)',
        [$rack_id, $site_id]
    );
    if (!$rack) {
        throw new InvalidArgumentException('Select a rack at this site.');
    }
    if ($action === 'save_rack') {
        $name = icct_backend_classification_text($input['name'], 150);
        $units = icct_backend_topology_integer($input['unit_count'], 1, 100, 'Rack units');
        if ($name === '') {
            throw new InvalidArgumentException('Enter a rack name.');
        }
        if (
            (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM plugin_icct_nms_rack_devices WHERE rack_id = ? AND start_unit + unit_height - 1 > ?',
                [$rack_id, $units]
            )
        ) {
            throw new InvalidArgumentException(
                'The smaller rack would exclude installed devices. Move them first.'
            );
        }
        foreach (icct_nms_rack_reserved_units($rack_id) as $reserved_unit) {
            if ($reserved_unit > $units) throw new InvalidArgumentException('The smaller rack would exclude reserved units. Clear them first.');
        }
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_racks SET name = ?, unit_count = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$name, $units, $user, $rack_id]
        );
    } elseif ($action === 'place_device' || $action === 'unplace_device') {
        $host_id = icct_backend_topology_integer($input['host_id'], 1, PHP_INT_MAX, 'Device ID');
        icct_backend_require_device_access($host_id);
        if ($action === 'unplace_device') {
            icct_backend_category_execute(
                'DELETE FROM plugin_icct_nms_rack_devices WHERE host_id = ? AND rack_id = ?',
                [$host_id, $rack_id]
            );
        } else {
            if (
                !(int) db_fetch_cell_prepared(
                    "SELECT COUNT(*) FROM host WHERE id = ? AND site_id = ? AND deleted = ''",
                    [$host_id, $site_id]
                )
            ) {
                throw new InvalidArgumentException('Select a Cacti device at this site.');
            }
            $start = icct_backend_topology_integer($input['start_unit'], 1, 100, 'Start unit');
            $height = icct_backend_topology_integer($input['unit_height'], 1, 100, 'Device height');
            if ($start + $height - 1 > (int) $rack['unit_count']) {
                throw new InvalidArgumentException('The device extends beyond the rack capacity.');
            }
            if (
                (int) db_fetch_cell_prepared(
                    'SELECT COUNT(*) FROM plugin_icct_nms_rack_devices WHERE rack_id = ? AND host_id != ? AND start_unit <= ? AND start_unit + unit_height - 1 >= ?',
                    [$rack_id, $host_id, $start + $height - 1, $start]
                )
            ) {
                throw new InvalidArgumentException('These rack units are already occupied.');
            }
            foreach(icct_nms_rack_reserved_units($rack_id) as $unit) if($unit>=$start && $unit<$start+$height) throw new InvalidArgumentException('These rack units are reserved.');
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_rack_devices (host_id, rack_id, start_unit, unit_height, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE rack_id = VALUES(rack_id), start_unit = VALUES(start_unit), unit_height = VALUES(unit_height), updated_by = VALUES(updated_by), updated_at = NOW()',
                [$host_id, $rack_id, $start, $height, $user]
            );
        }
    } else {
        throw new InvalidArgumentException('Unsupported rack action.');
    }
    return (int) $rack['site_id'];
}

/** Reused Inventory service: topology config write. */
function icct_backend_topology_config_write($action, $site_id, $input)
{
    $lock =
        'icct_backend_racks_' .
        substr(hash('sha256', (string) db_fetch_cell('SELECT DATABASE()')), 0, 32);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
        throw new RuntimeException('Rack configuration is busy. Please retry.');
    }
    icct_backend_category_execute('START TRANSACTION');
    try {
        $id = icct_backend_topology_config_apply($action, $site_id, $input);
        icct_backend_category_execute('COMMIT');
        return $id;
    } catch (Throwable $error) {
        db_execute('ROLLBACK');
        throw $error;
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Reused Inventory service: topology integer. */
function icct_backend_topology_integer($value, $min, $max, $label)
{
    if (
        !is_scalar($value) ||
        !preg_match('/^[0-9]+$/D', (string) $value) ||
        (float) $value < $min ||
        (float) $value > $max
    ) {
        throw new InvalidArgumentException(
            $label . ' must be a whole number from ' . $min . ' to ' . $max . '.'
        );
    }
    return (int) $value;
}
