<?php
/**
 * Saved inventory table, filters, tree view, pager and native discovery queue form.
 */
?>
<div class="inventory-heading">
    <div>
        <p class="breadcrumb">
            <a href="topology.php">Dashboard</a>
            /
            <a href="inventory.php">Inventory</a>
            / Table View
        </p>
        <h1>Inventory</h1>
    </div>
    <div class="inventory-tools">
        <div class="view-toggle">
            <button class="active" type="button" data-view="table" aria-pressed="true">
                Table View
            </button>
            <a href="inventory.php?view=tree">Tree View</a>
        </div>
        <a class="export-button" href="export.php" aria-label="Export inventory CSV">↥</a>
        <?php if ($management): ?>
        <button class="button" type="button" id="device-discovery">Device Discovery</button>
        <a class="button primary" href="device.php">Add Device</a>
        <?php endif; ?>
    </div>
</div>
<div class="inventory-filters">
    <label class="field">
        <span class="field-label">Segment</span>
        <select id="filter-segment">
            <option value="">All</option>
        </select>
    </label>
    <label class="field">
        <span class="field-label">Device Status</span>
        <select id="filter-status">
            <option value="">All</option>
        </select>
    </label>
    <label class="field">
        <span class="field-label">Rack Name</span>
        <select id="filter-rack">
            <option value="">All</option>
        </select>
    </label>
    <button class="button" type="button" id="clear-filters" disabled>Clear All</button>
    <label class="field inventory-search">
        <span class="sr-only">Search inventory</span>
        <input
            id="inventory-search"
            type="search"
            placeholder="Search With Device Name or IP Address"
        />
    </label>
</div>
<div class="table-scroll" tabindex="0" role="region" aria-label="Inventory table">
    <table id="inventory-table">
        <thead>
            <tr>
                <th scope="col" aria-sort="ascending">
                    <button type="button" id="sort-name">
                        Device Name &amp;
                        <br />
                        Short Name
                        <span>↑</span>
                    </button>
                </th>
                <th scope="col">
                    Segment &amp;
                    <br />
                    Device Type
                </th>
                <th scope="col">
                    Device
                    <br />
                    Status
                </th>
                <th scope="col">
                    Serial Number &amp;
                    <br />
                    MAC Address
                </th>
                <th scope="col">IP Address</th>
                <th scope="col">
                    Rack Placement &amp;
                    <br />
                    Rack Name
                </th>
                <th scope="col">
                    Uptime &amp; Polling
                    <br />
                    Interval (Sec)
                </th>
                <th scope="col">
                    Availability
                    <br />
                    (%)
                </th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $d):
                $id = (int) $d["id"]; ?>
            <tr
                data-name="<?= icct_nms_h($d["description"]) ?>"
                data-search="<?= icct_nms_h(
                    $d["description"] .
                        " " .
                        $d["short_name"] .
                        " " .
                        $d["hostname"],
                ) ?>"
                data-segment="<?= icct_nms_h($d["segment"]) ?>"
                data-status="<?= icct_nms_h($d["status_label"]) ?>"
                data-rack="<?= icct_nms_h($d["rack_name"]) ?>"
                data-id="<?= $id ?>"
            >
                <td>
                    <?= icct_nms_h($d["description"]) ?>
                    <small><?= icct_nms_h($d["short_name"]) ?></small>
                </td>
                <td>
                    <?= icct_nms_h($d["segment"]) ?>
                    <small><?= icct_nms_h($d["device_type"]) ?></small>
                </td>
                <td><span class="device-status <?= icct_nms_status_class($d["status_label"]) ?>"><?= icct_nms_h($d["status_label"]) ?></span></td>
                <td>
                    <?= icct_nms_h($d["manual_serial_number"]) ?>
                    <small><?= icct_nms_h($d["mac_address"]) ?></small>
                </td>
                <td><?= icct_nms_h($d["hostname"]) ?></td>
                <td>
                    <?= $d["start_unit"]
                        ? icct_nms_h(
                            $d["start_unit"] .
                                "U" .
                                ((int) $d["unit_height"] > 1
                                    ? "–" .
                                        ($d["start_unit"] +
                                            $d["unit_height"] -
                                            1) .
                                        "U"
                                    : ""),
                        )
                        : "" ?>
                    <small><?= icct_nms_h($d["rack_name"]) ?></small>
                </td>
                <td>
                    <?= icct_nms_h(
                        icct_nms_uptime($d["snmp_sysUpTimeInstance"]),
                    ) ?>
                    <small><?= icct_nms_h($d["polling_interval"]) ?></small>
                </td>
                <td><?= $d["availability"] !== null
                    ? number_format((float) $d["availability"], 2, ".", "") . "%"
                    : "" ?></td>
                <td>
                    <div class="row-actions">
                        <a
                            href="device.php?id=<?= $id ?>&amp;view=1"
                            aria-label="View <?= icct_nms_h(
                                $d["description"],
                            ) ?>"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </a>
                        <a
                            href="<?= icct_nms_h(
                                $config["url_path"],
                            ) ?>graph_view.php?action=preview&amp;host_id=<?= $id ?>"
                            aria-label="Graphs for <?= icct_nms_h(
                                $d["description"],
                            ) ?>"
                        >
                            ∿
                        </a>
                        <?php if ($management): ?>
                        <a href="device.php?id=<?= $id ?>" aria-label="Edit <?= icct_nms_h(
    $d["description"],
) ?>">
                            ✎
                        </a>
                        <details class="row-menu">
                            <summary aria-label="More actions for <?= icct_nms_h(
                                $d["description"],
                            ) ?>">⋮</summary>
                            <div class="row-menu-panel">
                                <?php $deviceDiagnostics=icct_backend_diag_selected_labels($id);foreach($deviceDiagnostics as $tool=>$label): ?>
                                <a href="diagnostics.php?host_id=<?= $id ?>&amp;tool=<?= icct_nms_h($tool) ?>#diagnostic-run" data-host-id="<?= $id ?>" data-tool="<?= icct_nms_h($tool) ?>" data-device-name="<?= icct_nms_h($d['description']) ?>"><?= icct_nms_h($label) ?></a>
                                <?php endforeach; ?>
                                <?php if(!$deviceDiagnostics): ?><span>No diagnostics selected</span><?php endif; ?>
                                <?php $launch = icct_nms_meta(
                                    "icct_cross_launch_" . $id,
                                ); ?>
                                <?php if (
                                    $launch !== "" &&
                                    filter_var($launch, FILTER_VALIDATE_URL) &&
                                    in_array(
                                        strtolower(
                                            parse_url($launch, PHP_URL_SCHEME),
                                        ),
                                        ["http", "https"],
                                        true,
                                    )
                                ): ?>
                                <a href="<?= icct_nms_h(
                                    $launch,
                                ) ?>" target="_blank" rel="noopener noreferrer">Cross Launch URL</a>
                                <?php else: ?>
                                <button type="button" disabled title="No Cross Launch URL is saved for this device.">Cross Launch URL</button>
                                <?php endif; ?>
                                <button type="button" disabled title="Alarm suppression unavailable.">Alarm Suppression</button>
                                <form method="post" action="protocol.php?id=<?= $id ?>">
                                    <?php icct_nms_token(); ?>
                                    <input type="hidden" name="action" value="reindex">
                                    <button type="submit">Re-Index Device</button>
                                </form>
                                <a href="device.php?id=<?= $id ?>">Modify Device</a>
                                <a href="device.php?clone_id=<?= $id ?>" data-clone-device data-device-name="<?= icct_nms_h(
    $d["description"],
) ?>">Clone Device</a>
                                <?php foreach (
                                    [
                                        ($d["disabled"] ?? "") === "on"
                                            ? 2
                                            : 3 =>
                                            ($d["disabled"] ?? "") === "on"
                                                ? "Enable Device"
                                                : "Disable Device",
                                        1 => "Delete Device",
                                    ]
                                    as $action => $label
                                ): ?>
                                <form method="post" action="<?= icct_nms_h(
                                    $config["url_path"],
                                ) ?>host.php" <?= $action === 1
    ? 'class="danger-action"'
    : "" ?>>
                                    <?php icct_nms_token(); ?>
                                    <input type="hidden" name="action" value="actions">
                                    <input type="hidden" name="drp_action" value="<?= $action ?>">
                                    <input type="hidden" name="chk_<?= $id ?>" value="on">
                                    <button type="submit"><?= $label ?></button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php
            endforeach; ?>
        </tbody>
    </table>
    <p id="empty-inventory" class="empty-state" <?= $devices ? "hidden" : "" ?>>
        No saved devices match these filters.
    </p>
</div>
<div id="inventory-tree" hidden></div>
<footer class="inventory-pagination">
    <label>
        Items per page:
        <select id="page-size">
            <option>10</option>
            <option>25</option>
            <option>50</option>
            <option>100</option>
        </select>
    </label>
    <span id="result-count" aria-live="polite"></span>
    <div class="page-controls">
        <label>
            <span class="sr-only">Page</span>
            <select id="page-number"></select>
        </label>
        <span id="page-count"></span>
        <button type="button" id="page-previous" aria-label="Previous page">‹</button>
        <button type="button" id="page-next" aria-label="Next page">›</button>
    </div>
</footer>
<?php if ($management): ?>
<dialog id="discovery-dialog">
    <div class="dialog-heading">
        <h2>Device Discovery</h2>
        <button type="button" data-close-dialog aria-label="Close">×</button>
    </div>
    <form method="post" action="protocol.php">
        <?php icct_nms_token(); ?>
        <input type="hidden" name="action" value="discover" />
        <button class="button primary">Queue Discovery</button>
    </form>
</dialog>
<?php endif; ?>
