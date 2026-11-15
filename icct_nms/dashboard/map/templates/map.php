<?php /** Same local Leaflet / GeoServer map stack as NMS; native Cacti locations. */ ?>
<section class="icct-map-page" data-dashboard-config="<?= !empty($dashboardConfiguration) ? '1' : '0' ?>">
    <?php if (!empty($dashboardConfiguration)): ?>
        <header class="dashboard-config-title">
            <div>
                <nav class="dashboard-config-breadcrumb" aria-label="Breadcrumb">
                    <a href="dashboard/controllers/topology.php">Dashboard</a>
                    <span aria-hidden="true">/</span>
                    <span>Configuration</span>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page">Widget Configuration</span>
                </nav>
                <h1>Widget Configuration</h1>
            </div>
        </header>
        <section id="dashboardSavedList" class="dashboard-saved-list" aria-labelledby="dashboardSavedTitle">
            <div class="dashboard-saved-heading">
                <div>
                    <h2 id="dashboardSavedTitle">Saved Dashboards</h2>
                    <span id="dashboardSavedCount"></span>
                </div>
                <button type="button" class="button primary" id="dashboardConfigAdd">Add Dashboard (Max 5)</button>
            </div>
            <p id="dashboardSavedMessage" role="status" aria-live="polite" hidden></p>
            <div class="dashboard-saved-scroll">
                <table class="dashboard-saved-table">
                    <thead><tr><th scope="col">Dashboard</th><th scope="col">Views</th><th scope="col">Widgets</th><th scope="col">Actions</th></tr></thead>
                    <tbody id="dashboardSavedRows"></tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    <div class="dashboard-toolbar" <?= !empty($dashboardConfiguration) ? 'hidden' : '' ?>>
        <label
            class="sr-only"
            for="dashboardSelect">Dashboard</label>
        <select id="dashboardSelect"></select>
        <button
            type="button"
            class="dashboard-add-widget"
            id="dashboardAddWidget"
            aria-controls="dashboardWidgetEditor">+ Add Widget</button>
        <p
            id="dashboardSaveStatus"
            role="status"
            hidden></p>
        <div class="dashboard-add-slot" <?= !empty($dashboardConfiguration) ? 'hidden' : '' ?>>
            <button
                type="button"
                class="button primary dashboard-add"
                id="dashboardAdd">Add Dashboard (Max 5)</button>
        </div>
    </div>
    <section
        id="dashboardWidgetEditor"
        hidden
        aria-labelledby="dashboardWidgetTitle">
        <div class="dashboard-widget-dialog-header">
            <h2 id="dashboardWidgetTitle">Select Widgets</h2>
            <div class="dashboard-widget-dialog-actions">
                <button type="button" class="button" id="dashboardWidgetCancel">Cancel</button>
                <button type="button" class="button primary" id="dashboardWidgetEdit" hidden>Edit</button>
                <button type="button" class="button primary" id="dashboardWidgetSave">Save Widgets</button>
            </div>
        </div>
        <div class="dashboard-widget-name" id="dashboardWidgetNameRow" hidden>
            <label for="dashboardWidgetName">Name of Dashboard <span class="dashboard-widget-name-help" title="Give this dashboard a name">ⓘ</span></label>
            <input id="dashboardWidgetName" maxlength="60" autocomplete="off" placeholder="Enter Name" required>
        </div>
        <p class="dashboard-widget-dialog-help" id="dashboardWidgetHelp">Choose the widgets to show on this dashboard.</p>
        <p id="dashboardWidgetError" class="dashboard-widget-error" role="alert" hidden></p>
        <div id="dashboardWidgetChoices" class="dashboard-widget-choices" aria-label="Available dashboard widgets"></div>
    </section>
    <div class="dashboard-grid" <?= !empty($dashboardConfiguration) ? 'hidden' : '' ?>>
        <section
            class="icct-map-panel"
            data-widget="topology">
            <div class="icct-map-toolbar">
                <div
                    class="icct-view-tabs"
                    role="tablist"
                    aria-label="Dashboard views">
                    <?php foreach (
                        [
                            'topology' => 'Topology',
                            'image' => 'Image View',
                            'rack' => 'Rack View',
                            'map' => 'Map View'
                        ] as $key => $label
                    ): ?>
                        <button
                            type="button"
                            role="tab"
                            id="icct-view-<?= $key ?>"
                            aria-controls="icct-panel-<?= $key ?>"
                            aria-selected="<?= $key === $dashboardView ? 'true' : 'false' ?>"
                            tabindex="<?= $key === $dashboardView ? '0' : '-1' ?>"
                            data-view="<?= $key ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                </div>
                <div
                    id="dashboardStatusCounts"
                    class="icct-map-counts"
                    aria-label="Device status totals">
                    <span>Total: <?= $mapData['counts']['total'] ?></span>
                    <span class="online">Online: <?= $mapData['counts']['online'] ?></span>
                    <span class="offline">Offline: <?= $mapData['counts']['offline'] ?></span>
                    <span class="disabled">Disabled: <?= $mapData['counts']['disabled'] ?></span>
                </div>
                <button
                    type="button"
                    class="dashboard-topology-refresh"
                    id="dashboardRefresh"
                    aria-label="Refresh dashboard"
                    title="Refresh dashboard">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M20 11a8 8 0 1 0 1 4" />
                        <path d="M20 4v7h-7" />
                    </svg>
                </button>
            </div>
            <p
                id="icctMapStatus"
                role="status"
                hidden></p>
            <div
                class="icct-view-panel"
                id="icct-panel-map"
                role="tabpanel"
                aria-labelledby="icct-view-map"
                <?= $dashboardView === 'map' ? '' : 'hidden' ?>>
                <div
                    id="icctSiteMap"
                    aria-label="Map of configured devices"></div>
                <div
                    id="lnmsLocationNotice"
                    class="lnms-location-notice"
                    role="status"
                    hidden></div>
                <div
                    class="icct-map-controls"
                    aria-label="Map controls">
                    <button
                        id="icctMapZoomIn"
                        type="button"
                        aria-label="Zoom in"
                        title="Zoom in">+</button>
                    <button
                        id="icctMapZoomOut"
                        type="button"
                        aria-label="Zoom out"
                        title="Zoom out">−</button>
                    <button
                        id="icctMapFit"
                        type="button"
                        aria-label="Reset map view"
                        title="Reset map view">▣</button>
                    <button
                        id="icctMapFullscreen"
                        type="button"
                        aria-label="Toggle map fullscreen"
                        title="Fullscreen">⛶</button>
                </div>
            </div>
            <?php foreach (['topology', 'rack', 'image'] as $view): ?>
                <div
                    class="icct-view-panel icct-device-view"
                    id="icct-panel-<?= $view ?>"
                    role="tabpanel"
                    aria-labelledby="icct-view-<?= $view ?>"
                    <?= $view === $dashboardView ? '' : 'hidden' ?>>
                    <?php if ($view === 'rack'): ?>
                        <?php require __DIR__ . '/../../rack-view/templates/rack_view.php'; ?>
                    <?php elseif ($view === 'topology'):
                        require __DIR__ . '/../../topology/templates/topology_view.php'; ?>
                    <?php else:
                        ?>
                        <!-- The shared topology surface moves here when Image View is selected. -->
                    <?php
                    endif; ?>
                </div>
            <?php endforeach; ?>
            <details
                class="icct-map-credits"
                hidden>
                <summary
                    title="Map credits"
                    aria-label="Map credits">ⓘ</summary>
                <span>Leaflet. State boundaries: geoBoundaries / DataMeet (CC BY 2.5 IN).</span>
            </details>
        </section>
        <aside
            class="dashboard-widgets"
            id="dashboardWidgets">
            <?php foreach (
                [
                    'birds' => 'Birds Eye View',
                    'alarms' => 'Alarm Overview',
                    'ack' => 'Ack Overview',
                    'escalation' => 'Escalation Overview',
                    'frequent' => 'Top 10 Frequent Alarms by Count',
                    'recent' => 'Recent Alarms (25)',
                    'ports' => 'Device Interface (Ports) Overview',
                    'problematic' => 'Problematic Devices by Active Alarms Count',
                    'ntp' => 'NTP Overview',
                    'netflow_talkers' => 'NetFlow Top Talkers',
                ] as $key => $label
            ): ?>
                <section
                    class="dashboard-card"
                    data-widget="<?= $key ?>">
                    <header>
                        <button
                            type="button"
                            class="widget-grip"
                            draggable="true"
                            aria-label="Move <?= $label ?> widget"
                            title="Drag to reorder; use arrow keys to move">⠿</button>
                        <h2><?= $label ?></h2>
                        <?php
                        if (
                            $key === 'problematic'
                        ): ?>
                            <button
                                type="button"
                                class="widget-icon"
                                id="problematicExpand"
                                aria-label="Expand Problematic Devices"
                                aria-pressed="false"
                                title="Expand">
                                <svg
                                    viewBox="0 0 24 24"
                                    aria-hidden="true">
                                    <path d="M8 3H3v5M3 3l7 7M16 21h5v-5M21 21l-7-7" />
                                </svg>
                            </button>
                        <?php endif;
                if (
                    $key === 'ports'
                ): ?>
                            <button
                                type="button"
                                class="widget-icon"
                                id="portsExpand"
                                aria-label="Expand Ports Overview"
                                aria-pressed="false"
                                title="Expand">
                                <svg
                                    viewBox="0 0 24 24"
                                    aria-hidden="true">
                                    <path d="M8 3H3v5M3 3l7 7M16 21h5v-5M21 21l-7-7" />
                                </svg>
                            </button>
                        <?php endif;
                if (
                    $key === 'recent'
                ): ?>
                            <button
                                type="button"
                                class="widget-icon"
                                id="recentExpand"
                                aria-label="Expand Recent Alarms"
                                aria-pressed="false"
                                title="Expand">
                                <svg
                                    viewBox="0 0 24 24"
                                    aria-hidden="true">
                                    <path d="M8 3H3v5M3 3l7 7M16 21h5v-5M21 21l-7-7" />
                                </svg>
                            </button>
                        <?php endif;
                if (
                    $key === 'birds'
                ): ?>
                            <button
                                type="button"
                                class="widget-icon"
                                id="birdsRefresh"
                                aria-label="Refresh Birds Eye View"
                                title="Refresh">↻</button>
                        <?php endif;
                ?>
                        <button
                            type="button"
                            class="widget-icon"
                            data-remove-widget="<?= $key ?>"
                            aria-label="Remove <?= $label ?> widget"
                            title="Remove widget">
                            <svg
                                viewBox="0 0 24 24"
                                aria-hidden="true">
                                <path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 9v9M14 9v9" />
                            </svg>
                        </button>
                    </header>
                    <?php if (
                        $key === 'birds'
                    ): ?>
                        <div class="birds-content">
                            <p
                                id="birdsLnmsStatus"
                                class="lnms-radar-status"
                                role="status"></p>
                            <svg
                                id="birdsRadar"
                                viewBox="0 0 320 320"
                                role="group"
                                aria-label="Birds Eye View centered on the Cacti server"></svg>
                        </div>
                    <?php elseif (
                        $key === 'problematic'
                    ): ?>
                        <div class="problematic-content">
                            <fieldset
                                class="problematic-filters"
                                aria-label="Problematic device severities">
                                <?php foreach (
                                    [
                                        'Critical' => 'Critical',
                                        'Major' => 'Major',
                                        'Minor' => 'Minor',
                                        'Warning' => 'Warn',
                                        'Information' => 'Info',
                                    ] as $severity => $text
                                ): ?>
                                    <label>
                                        <input
                                            type="checkbox"
                                            data-problematic-severity="<?= $severity ?>"
                                            <?= in_array($severity, ['Critical', 'Major'], true) ? 'checked' : '' ?>><?= $text ?>

                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <div class="problematic-total">
                                <span>Total Problematic Devices</span>
                                <p>
                                    <strong id="problematicCount">0</strong>
                                    <span id="problematicDeviceTotal">/0</span>
                                </p>
                            </div>
                            <h3>Devices by Active Alarm Count</h3>
                            <table
                                class="problematic-table"
                                aria-label="Devices by active alarm count">
                                <thead class="sr-only">
                                    <tr>
                                        <th>Device</th>
                                        <th>Active alarms</th>
                                    </tr>
                                </thead>
                                <tbody id="problematicDevices"></tbody>
                            </table>
                        </div>
                    <?php elseif ($key === 'ports'): ?>
                        <div
                            class="ports-overview-content"
                            id="portsOverview"></div>
                    <?php elseif ($key === 'recent'): ?>
                        <div
                            class="recent-content"
                            id="recentAlarms"></div>
                    <?php elseif ($key === 'ntp'): ?>
                        <div class="recent-content" id="ntpOverview"></div>
                    <?php elseif ($key === 'netflow_talkers'): ?>
                        <div class="recent-content" id="netflowTalkers"></div>
                    <?php elseif (
                        $key === 'frequent'
                    ): ?>
                        <div class="frequent-content">
                            <table
                                class="frequent-table"
                                aria-label="Top ten alarms by active count">
                                <thead class="sr-only">
                                    <tr>
                                        <th>Alarm</th>
                                        <th>Severity</th>
                                        <th>Count</th>
                                    </tr>
                                </thead>
                                <tbody id="frequentAlarms"></tbody>
                            </table>
                        </div>
                    <?php elseif (
                        $key === 'ack' ||
                        $key === 'escalation'
                    ): ?>
                        <div class="alarm-content workflow-content">
                            <div class="alarm-chart">
                                <svg
                                    id="<?= $key ?>Donut"
                                    viewBox="0 0 180 180"
                                    role="img"
                                    aria-label="<?= $label ?>"></svg>
                                <ul id="<?= $key ?>Legend"></ul>
                            </div>
                            <p id="<?= $key ?>Note"></p>
                        </div>
                    <?php else: ?>
                        <div class="alarm-content">
                            <div
                                class="alarm-mode"
                                role="group"
                                aria-label="Alarm grouping">
                                <button
                                    type="button"
                                    data-alarm-mode="severity"
                                    aria-pressed="true">Severity</button>
                                <button
                                    type="button"
                                    data-alarm-mode="segments"
                                    aria-pressed="false">Segment</button>
                            </div>
                            <div class="alarm-chart">
                                <svg
                                    id="alarmDonut"
                                    viewBox="0 0 180 180"
                                    role="img"
                                    aria-label="Active alarms"></svg>
                                <ul id="alarmLegend"></ul>
                            </div>
                            <p id="alarmNote"></p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </aside>
        <div
            class="dashboard-extra-widgets"
            id="dashboardExtraWidgets"></div>
    </div>
    <div
        id="dashboardPortTooltip"
        class="dashboard-port-tooltip"
        role="tooltip"
        hidden></div>
    <form
        id="dashboardToken"
        hidden>
        <?php icct_nms_token(); ?>
    </form>
    <script
        type="application/json"
        id="dashboardData"><?= json_encode(
            [
                'preferences' => $dashboardPreferences,
                'readings' => $dashboardReadings,
                'site_id' => $dashboardSiteId,
                'poller_id' => $dashboardPollerId ?? 0,
                'user' => icct_backend_current_user_id(),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
    <dialog
        id="lnmsNodeDialog"
        class="topology-device-dialog map-site-dialog"
        aria-labelledby="lnmsNodeTitle">
        <header class="topology-summary-heading">
            <div>
                <h2 id="lnmsNodeTitle"></h2>
                <p id="lnmsNodeAddress"></p>
            </div>
            <span
                id="lnmsNodeStatus"
                class="device-status"></span>
            <form method="dialog">
                <button
                    class="button"
                    aria-label="Close device summary">×</button>
            </form>
        </header>
        <dl
            id="lnmsNodeDetails"
            class="topology-capacity map-site-counts"></dl>
        <div
            id="lnmsNodeAlarms"
            class="topology-summary-alarms"></div>
        <nav class="topology-summary-links map-site-links">
            <a id="lnmsTopologyLink">View Topology <span aria-hidden="true">→</span></a>
            <button
                type="button"
                disabled
                title="Chat is not configured">Chat <span aria-hidden="true">→</span></button>
        </nav>
    </dialog>
    <script
        type="application/json"
        id="icctMapData"
        data-summary-url="dashboard/controllers/topology.php"><?= json_encode(
            [
                'counts' => $dashboardReadings['device_counts'],
                'lnms' => $dashboardReadings['lnms'] ?? ['nodes' => [], 'counts' => []],
                'states' => 'shared/assets/maps/india-states.json',
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
</section>
