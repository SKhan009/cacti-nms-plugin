<?php

/** Read and write Cacti's native sites, shared with device site selections. */
function icct_nms_site_fields()
{
    return [
        'name' => ['Name', 100],
        'address1' => ['Address1', 100],
        'address2' => ['Address2', 100],
        'city' => ['City', 30],
        'state' => ['State', 20],
        'postal_code' => ['Postal/Zip Code', 20],
        'country' => ['Country', 30],
        'timezone' => ['Timezone', 40],
        'latitude' => ['Latitude', 30],
        'longitude' => ['Longitude', 30],
        'zoom' => ['Zoom', 4],
        'notes' => ['Notes', 255],
        'alternate_id' => ['Alternate Name', 30],
    ];
}
/** Use the same timezone catalog as Cacti sites.php. */
function icct_nms_site_timezones()
{
    $rows = db_fetch_assoc('SELECT Name AS name FROM mysql.time_zone_name ORDER BY Name');
    if (!is_array($rows)) {
        throw new RuntimeException('Unable to read the Cacti timezone catalog.');
    }
    return array_column($rows, 'name');
}
function icct_nms_site_values($input)
{
    $save = [];
    foreach (icct_nms_site_fields() as $field => [$label, $max]) {
        if (!is_string($input[$field] ?? '')) {
            throw new InvalidArgumentException('Enter a valid ' . $label . '.');
        }
        $value = trim($input[$field] ?? '');
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException(
                $label . ' must be ' . $max . ' characters or fewer.',
            );
        }
        $save[$field] = $value;
    }
    if ($save['name'] === '') {
        throw new InvalidArgumentException('Enter a site name.');
    }
    foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
        if ($save[$field] === '') {
            $save[$field] = '0';
        }
        if (
            !preg_match('/^-?\d+(?:\.\d{1,10})?$/D', $save[$field]) ||
            abs((float) $save[$field]) > $limit
        ) {
            throw new InvalidArgumentException(
                ucfirst($field) .
                    ' must be between -' .
                    $limit .
                    ' and ' .
                    $limit .
                    ' (up to 10 decimal places).',
            );
        }
    }
    if ($save['zoom'] === '') {
        $save['zoom'] = '12';
    }
    if (!ctype_digit($save['zoom']) || (int) $save['zoom'] > 23) {
        throw new InvalidArgumentException('Map zoom must be between 0 and 23.');
    }
    if ($save['timezone'] !== '' && !in_array($save['timezone'], icct_nms_site_timezones(), true)) {
        throw new InvalidArgumentException('Select a valid timezone.');
    }
    return $save;
}
function icct_nms_save_site($input)
{
    $siteId = $input['site_id'] ?? 0;
    $id = icct_backend_topology_integer($siteId === '' ? 0 : $siteId, 0, 4294967295, 'Site ID');
    if ($id && !db_fetch_cell_prepared('SELECT id FROM sites WHERE id=?', [$id])) {
        throw new InvalidArgumentException('Choose a saved site.');
    }
    $save = icct_nms_site_values($input);
    $save['id'] = $id;
    if (!sql_save($save, 'sites')) {
        throw new RuntimeException('The site could not be saved. Please retry.');
    }
    set_config_option('time_last_change_site', time());
    set_config_option('time_last_change_site_device', time());
    return $id ? 'Site updated.' : 'Site added.';
}
