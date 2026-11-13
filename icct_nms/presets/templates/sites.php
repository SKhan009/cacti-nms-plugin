<!-- Site forms retain submitted values after validation; writes are handled by the preset controller. -->
<?php if ($management): ?>
    <form
        method="post"
        id="site-editor"
        class="site-editor"
        <?= $siteEditing ? '' : 'hidden' ?>>
        <?php icct_nms_token(); ?>
        <input
            type="hidden"
            name="action"
            value="save_site">
        <input
            type="hidden"
            name="site_id"
            value="<?= icct_nms_h($siteValues['site_id'] ?? '0') ?>">
        <div class="type-editor-heading">
            <h2 id="site-editor-title"><?= empty($siteValues['site_id']) ? 'Add' : 'Edit' ?> Site</h2>
            <div>
                <button
                    type="button"
                    id="cancel-site"
                    class="button">Cancel</button>
                <button
                    type="submit"
                    class="button primary">Save</button>
            </div>
        </div>
        <?php foreach (
            [
                'Site Information' => ['name'],
                'Address Information' => ['address1', 'address2', 'city', 'state', 'postal_code', 'country', 'timezone'],
                'Geolocation Information' => ['latitude', 'longitude', 'zoom'],
                'Additional Information' => ['notes', 'alternate_id']
            ] as $heading => $fields
        ): ?>
            <section class="site-section">
                <h3><?= $heading ?></h3>
                <div class="site-fields">
                    <?php foreach ($fields as $field):
                        [$label, $max] = icct_nms_site_fields()[$field];
                        $value = $siteValues[$field] ?? ($field === 'zoom' ? '12' : ''); ?>
                        <label class="field <?= $field === 'notes' ? 'site-notes' : '' ?>">
                            <span class="field-label"><?= $label ?><?= $field === 'name' ? ' *' : '' ?></span>
                            <?php if ($field === 'timezone'): ?>
                                <span class="select-wrap">
                                <select
                                    name="timezone"
                                    data-searchable-template="Timezone">
                                    <option value="">Select timezone</option>
                                    <?php foreach (icct_nms_site_timezones() as $zone): ?>
                                        <option
                                            <?= $value === $zone ? 'selected' : '' ?>

                                            value="<?= icct_nms_h($zone) ?>"><?= icct_nms_h($zone) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                </span>
                            <?php elseif ($field === 'notes'): ?>
                                <textarea
                                    name="notes"
                                    maxlength="<?= $max ?>"
                                    rows="3"
                                    placeholder="Enter site notes"><?= icct_nms_h($value) ?></textarea>
                            <?php else: ?>
                                <input
                                    name="<?= $field ?>"
                                    value="<?= icct_nms_h($value) ?>"
                                    <?= $field === 'name' ? 'required' : '' ?>

                                    maxlength="<?= $max ?>"
                                    placeholder="Enter <?= strtolower($label) ?>"
                                    <?php if (in_array($field, ['latitude', 'longitude', 'zoom'], true)): ?>

                                    type="number"
                                    min="<?= $field === 'latitude' ? '-90' : ($field === 'longitude' ? '-180' : '0') ?>"
                                    max="<?= $field === 'latitude' ? '90' : ($field === 'longitude' ? '180' : '23') ?>"
                                    step="<?= $field === 'zoom' ? '1' : 'any' ?>"
                                    <?php endif; ?>>
                            <?php endif; ?>
                            <?php if ($field === 'latitude' || $field === 'longitude' || $field === 'zoom'): ?>
                                <small><?= $field === 'latitude' ? '−90 to 90°' : ($field === 'longitude' ? '−180 to 180°' : '0–23') ?></small>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </form>
<?php endif; ?>
<section
    id="site-list"
    aria-label="Sites">
    <label class="field site-search">
        <span class="field-label">Search sites</span>
        <input
            type="search"
            id="site-search"
            placeholder="Search by site name, city or country">
    </label>
    <div class="site-table-wrap">
        <table class="action-table site-table">
            <thead>
                <tr>
                    <th>Site Name</th>
                    <th>City</th>
                    <th>State</th>
                    <th>Country</th>
                    <th>Timezone</th>
                    <th>Devices</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $site): ?>
                    <tr
                        data-site-row
                        data-site-search="<?= icct_nms_h(mb_strtolower(implode(' ', [$site['name'], $site['city'], $site['country']]))) ?>">
                        <td><?= icct_nms_h($site['name']) ?></td>
                        <?php foreach (['city', 'state', 'country', 'timezone', 'devices'] as $field): ?>
                            <td><?= icct_nms_h($site[$field] !== '' && $site[$field] !== null ? $site[$field] : '—') ?></td>
                        <?php endforeach; ?>
                        <td>
                            <?php if ($management): ?>
                                <button
                                    type="button"
                                    class="icon-button"
                                    data-edit-site="<?= icct_nms_h(json_encode($site, JSON_THROW_ON_ERROR)) ?>"
                                    aria-label="Edit <?= icct_nms_h($site['name']) ?>">
                                    <svg
                                        class="icct-edit-icon"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true">
                                        <path d="m16 3 5 5L8 21l-5 1 1-5ZM14 5l5 5" />
                                    </svg>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p
        id="site-empty"
        class="empty-state"
        <?= $sites ? 'hidden' : '' ?>>No sites found.</p>
</section>
<!-- Collector locations are saved explicitly; GNSS configuration belongs to a selected poller. -->
<section class="lnms-site-settings">
    <h2>LNMS / Remote Poller Locations <span
            class="field-info section-help"
            tabindex="0"
            role="img"
            aria-label="Assign each Cacti poller to its physical site. Map View and Birds Eye View use these coordinates; IP addresses do not provide physical location."
            data-tooltip="Assign each Cacti poller to its physical site. Map View and Birds Eye View use these coordinates; IP addresses do not provide physical location.">ⓘ</span>
    </h2>
    <?php $lnmsSites = db_fetch_assoc('SELECT id,name FROM sites ORDER BY name,id');
$lnmsAssignments = icct_nms_lnms_site_assignments(); ?>
    <form method="post">
        <?php icct_nms_token(); ?>
        <input
            type="hidden"
            name="action"
            value="save_lnms_sites">
        <div class="site-table-wrap">
            <table class="site-table">
                <thead>
                    <tr>
                        <th>LNMS / Poller</th>
                        <th>Hostname / IP Address</th>
                        <th>Site</th>
                        <th>GNSS / Collector</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (db_fetch_assoc('SELECT id,name,hostname FROM poller ORDER BY id') as $lnmsPoller): ?>
                        <tr>
                            <td><?= icct_nms_h($lnmsPoller['name']) ?></td>
                            <td><?= icct_nms_h($lnmsPoller['hostname']) ?></td>
                            <td>
                                <select
                                    name="poller_sites[<?= (int) $lnmsPoller['id'] ?>]"
                                    aria-label="Site for <?= icct_nms_h($lnmsPoller['name']) ?>"
                                    <?= $management ? '' : 'disabled' ?>>
                                    <option value="0">Unassigned</option>
                                    <?php foreach ($lnmsSites as $lnmsSite): ?>
                                        <option
                                            value="<?= (int) $lnmsSite['id'] ?>"
                                            <?= (int) ($lnmsAssignments[$lnmsPoller['id']] ?? 0) === (int) $lnmsSite['id'] ? 'selected' : '' ?>><?= icct_nms_h($lnmsSite['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($management): ?>
                                    <a
                                        class="button"
                                        href="presets/controllers/presets.php?tab=site&amp;gnss=<?= (int) $lnmsPoller['id'] ?>">Configure GNSS</a>
                                    <a
                                        class="button"
                                        href="<?= icct_nms_h($config['url_path']) ?>pollers.php?action=edit&amp;id=<?= (int) $lnmsPoller['id'] ?>">Collector Settings</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($management): ?>
            <div class="type-editor-actions">
                <button
                    class="button primary"
                    type="submit">Save LNMS Locations</button>
            </div>
        <?php endif; ?>
    </form>
</section>
<?php if ($management && isset($_GET['gnss'])):
    $gnssPoller = (int)$_GET['gnss'];
    $gnssSource = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_gnss_sources WHERE poller_id=?', [$gnssPoller]);
    if (db_fetch_cell_prepared('SELECT id FROM poller WHERE id=?', [$gnssPoller])):
        $v = array_replace(
            json_decode($gnssSource['config_json'] ?? '{}', true) ?: [],
            ['enabled' => !empty($gnssSource['enabled'])]
        );
        if ($error && ($_POST['action'] ?? '') === 'save_gnss') {
            $v = array_replace($v, $_POST);
        }
        $hosts = icct_nms_inventory();
        ?>
        <h2>GNSS Position Source</h2>
        <form
            method="post"
            id="gnss-config">
            <?php icct_nms_token(); ?>
            <input
                type="hidden"
                name="action"
                value="save_gnss">
            <input
                type="hidden"
                name="poller_id"
                value="<?= (int) $_GET['gnss'] ?>">
            <label class="check-row">
                <input
                    type="checkbox"
                    name="enabled"
                    value="1"
                    <?= !empty($v['enabled']) ? 'checked' : '' ?>>Enable GNSS location
            </label>
            <div class="form-grid">
                <label class="field">
                    <span class="field-label">Transport</span>
                    <select
                        name="transport"
                        id="gnssTransport">
                        <?php foreach (icct_nms_gnss_transports() as $key => $label): ?>
                            <option
                                value="<?= $key ?>"
                                <?= ($v['transport'] ?? 'snmp') === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php foreach (
                    [
                        'interval' => ['Collection Interval (sec)', 30, 5, 3600],
                        'max_age' => ['Fix Maximum Age (sec)', 120, 10, 86400],
                        'timeout' => ['Read Timeout (sec)', 3, 1, 10],
                    ] as $key => [$label, $default, $min, $max]
                ) {
                    icct_nms_input(
                        $label,
                        $key,
                        $v[$key] ?? $default,
                        'number',
                        'required min="' . $min . '" max="' . $max . '"',
                    );
                } ?>
            </div>
            <div
                class="form-grid"
                data-gnss-transports="tcp udp gpsd">
                <?php icct_nms_input('Receiver Address / UDP Sender Filter', 'address', $v['address'] ?? '');
        icct_nms_input(
            'TCP / UDP / gpsd Port',
            'port',
            $v['port'] ?? 10110,
            'number',
            'min="1" max="65535"',
        );
        icct_nms_input('UDP Local Bind IP', 'bind', $v['bind'] ?? '0.0.0.0'); ?>
            </div>
            <div
                class="form-grid"
                data-gnss-transports="serial file">
                <?php icct_nms_input('Serial Device / NMEA File Path', 'path', $v['path'] ?? '');
        icct_nms_input('Serial Baud Rate', 'baud', $v['baud'] ?? 9600, 'number'); ?>
            </div>
            <div
                class="form-grid"
                data-gnss-transports="snmp">
                <label class="field">
                    <span class="field-label">GNSS Device — assigned to this collector</span>
                    <select name="host_id">
                        <option value="0">Select device</option>
                        <?php foreach ($hosts as $host):
                            if ((int)$host['poller_id'] !== (int)$_GET['gnss']) {
                                continue;
                            } ?>
                            <option
                                value="<?= (int) $host['id'] ?>"
                                <?= (int) ($v['host_id'] ?? 0) === (int) $host['id'] ? 'selected' : '' ?>><?= icct_nms_h($host['description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php foreach (
                    [
                        'latitude_oid' => 'Latitude OID',
                        'longitude_oid' => 'Longitude OID',
                        'fix_oid' => 'Fix Status OID',
                        'scale' => 'Coordinate Divisor',
                        'fix_values' => 'Valid Fix Values',
                    ] as $key => $label
                ) {
                    icct_nms_input(
                        $label,
                        $key,
                        is_array($v[$key] ?? null)
                            ? implode(',', $v[$key])
                            : $v[$key] ?? (['scale' => 1, 'fix_values' => '1'][$key] ?? ''),
                    );
                } ?>
            </div>
            <span
                class="field-info section-help"
                tabindex="0"
                role="img"
                aria-label="SNMP uses the selected device credentials. Map vendor coordinate and valid-fix OIDs; divisor 1000000 converts microdegrees. NMEA accepts checksum-verified RMC/GGA; gpsd uses valid TPV fixes. Files must be under /var/lib/icct-nms/gnss/. Stale or invalid fixes are marked and never treated as a current position."
                data-tooltip="SNMP uses the selected device credentials. Map vendor coordinate and valid-fix OIDs; divisor 1000000 converts microdegrees. NMEA accepts checksum-verified RMC/GGA; gpsd uses valid TPV fixes. Files must be under /var/lib/icct-nms/gnss/. Stale or invalid fixes are marked and never treated as a current position.">ⓘ</span>
            <div class="protocol-actions">
                <button class="button primary">Save GNSS Source</button>
            </div>
        </form>
        <script
            src="inventory/discovery/js/gnss-settings.js?v=<?= substr(hash_file('sha256', __DIR__ . '/../js/gnss-settings.js'), 0, 12) ?>"
            defer></script>
<?php endif; endif; ?>
