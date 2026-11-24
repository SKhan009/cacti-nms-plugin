<?php

/** Non-secret starter catalogue, applied only during plugin install/upgrade. */
require_once __DIR__ . '/device_type_service.php';

function icct_nms_inventory_starters(): array
{
    $data = json_decode(file_get_contents(__DIR__ . '/../data/inventory_defaults.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($data['format'] ?? null) !== 1 || !is_array($data['segments'] ?? null) || !is_array($data['device_types'] ?? null)) {
        throw new RuntimeException('Invalid inventory starter catalogue.');
    }
    foreach ($data['device_types'] as $type) {
        if (!isset(icct_nms_type_icons()[$type['icon']]) ||
            !isset(icct_nms_type_shapes()[$type['shape']]) ||
            ($type['segment'] !== null && !isset($data['segments'][$type['segment']])) ||
            icct_nms_type_image_asset('defaults/' . $type['icon'] . '.svg') === '') {
            throw new RuntimeException('A device starter has missing appearance assets or segment.');
        }
    }
    return $data;
}

function icct_nms_seed_inventory_presets(): void
{
    // Share the editor's lock so upgrades never replace a concurrent catalogue edit.
    $lock = icct_nms_type_catalogue_lock();
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,5)', [$lock]) !== 1) {
        throw new RuntimeException('Device type defaults are busy. Retry the upgrade.');
    }
    $execute = static function ($sql, $args = []) {
        if (!db_execute_prepared($sql, $args)) {
            throw new RuntimeException('Could not save inventory starter presets.');
        }
    };
    $transaction = false;
    try {
        $marker = 'inventory_starter_defaults_v1';
        if (db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [$marker])) {
            return;
        }
        $starters = icct_nms_inventory_starters();
        $execute('START TRANSACTION');
        $transaction = true;
        $segments = db_fetch_assoc('SELECT id,name,seed_key FROM plugin_icct_nms_categories ORDER BY id FOR UPDATE');
        $segmentIds = [];
        foreach ($starters['segments'] as $key => $name) {
            $seed = 'inventory:' . $key;
            $matches = array_values(array_filter($segments, static fn($row) => ($row['seed_key'] ?? '') === $seed));
            if (!$matches) {
                $matches = array_values(array_filter($segments, static fn($row) => strcasecmp($row['name'], $name) === 0));
            }
            if (count($matches) > 1) {
                throw new RuntimeException('Resolve duplicate segment names before adding defaults: ' . $name);
            }
            if ($matches) {
                $segmentIds[$key] = (int) $matches[0]['id'];
                if (empty($matches[0]['seed_key'])) {
                    $execute('UPDATE plugin_icct_nms_categories SET seed_key=? WHERE id=? AND seed_key IS NULL', [$seed, $segmentIds[$key]]);
                }
            } else {
                $execute("INSERT INTO plugin_icct_nms_categories(name,description,seed_key,sort_order,updated_by,updated_at) VALUES(?,'',?,0,0,NOW())", [$name, $seed]);
                $segmentIds[$key] = (int) db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_categories WHERE seed_key=?', [$seed]);
                if (!$segmentIds[$key]) {
                    throw new RuntimeException('New starter segment could not be resolved.');
                }
            }
        }
        $types = icct_nms_device_types();
        foreach ($starters['device_types'] as $key => $starter) {
            $category = $starter['segment'] === null ? 0 : $segmentIds[$starter['segment']];
            $seed = 'inventory:' . $key;
            $matches = array_keys(array_filter($types, static fn($type) => ($type['seed_key'] ?? '') === $seed));
            if (!$matches) {
                $matches = array_keys(array_filter($types, static fn($type) =>
                    (int) $type['category_id'] === $category && strcasecmp($type['name'], $starter['name']) === 0));
            }
            if (count($matches) > 1) {
                throw new RuntimeException('Resolve duplicate device types before adding defaults: ' . $starter['name']);
            }
            $id = $matches[0] ?? substr(hash('sha256', $seed), 0, 16);
            if (!$matches && isset($types[$id])) {
                throw new RuntimeException('Starter device type key conflicts with a saved profile.');
            }
            $existing = $types[$id] ?? [];
            $base = ['name' => $starter['name'], 'category_id' => $category, 'icon' => $starter['icon'],
                'shape' => $starter['shape'], 'physical_ports' => null,
                'image' => 'defaults/' . $starter['icon'] . '.svg',
                'display_modes' => ['network' => 'icon', 'rack' => 'icon', 'map' => 'icon']];
            $types[$id] = array_replace($base, $existing);
            $types[$id]['display_modes'] = array_replace($base['display_modes'], $existing['display_modes'] ?? []);
            // This one-time migration supplies missing images; future explicit removals stay removed.
            if (empty($existing['image'])) {
                $icon = $types[$id]['icon'];
                $image = 'defaults/' . $icon . '.svg';
                $types[$id]['image'] = icct_nms_type_image_asset($image) !== '' ? $image : $base['image'];
            }
            $types[$id]['seed_key'] = $seed;
        }
        $json = json_encode($types, JSON_THROW_ON_ERROR);
        if (strlen($json) > 60000) {
            throw new RuntimeException('The device type catalogue has no room for starter presets.');
        }
        foreach (['device_type_profiles' => $json, $marker => '1'] as $key => $value) {
            $execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()', [$key, $value]);
        }
        $execute('COMMIT');
        $transaction = false;
    } catch (Throwable $error) {
        if ($transaction) {
            $execute('ROLLBACK');
        }
        throw $error;
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
