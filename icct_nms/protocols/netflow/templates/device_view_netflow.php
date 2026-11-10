<!-- Device flow summaries show collector observations for the selected device and time window. -->
<section
    id="view-netflow"
    data-device-view-panel="netflow"
    hidden>
    <div class="device-history-heading">
        <h2 data-field-help="Traffic summaries and records sent to the collector by this device. Each tab keeps its own filters.">NetFlow / IPFIX</h2>
        <div>
            <span
                id="netflow-health"
                role="status">Loading readings…</span>
            <button
                class="icon-button"
                type="button"
                id="netflow-refresh"
                aria-label="Refresh flow readings">↻</button>
        </div>
    </div>
    <p id="netflow-configuration"></p>
    <p
        id="netflow-feedback"
        role="alert"></p>
    <?php $netflowViews = [
        'overview' => 'Overview',
        'breakdown' => 'Traffic Breakdown',
        'records' => 'Traffic Records',
        'anomalies' => 'Anomalies',
    ]; ?>
    <div class="fcaps-tabs" role="tablist" aria-label="NetFlow views">
        <?php foreach ($netflowViews as $key => $label): ?>
            <button type="button" role="tab" id="netflow-tab-<?= $key ?>"
                aria-controls="netflow-panel-<?= $key ?>" aria-selected="<?= $key === 'overview' ? 'true' : 'false' ?>"
                tabindex="<?= $key === 'overview' ? '0' : '-1' ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($netflowViews as $key => $label): ?>
        <section id="netflow-panel-<?= $key ?>" role="tabpanel" aria-labelledby="netflow-tab-<?= $key ?>"
            <?= $key === 'overview' ? '' : 'hidden' ?>>
            <?php if ($key === 'overview'): ?>
                <section aria-labelledby="netflow-live-title">
                    <h3 id="netflow-live-title" data-field-help="Calculated from stored flows with observation times in the last five minutes. The time and traffic filters below apply to the historical totals and chart.">Latest Traffic Readings</h3>
                    <p id="netflow-live-status" role="status">Loading the last five minutes of traffic…</p>
                    <div class="netflow-summary netflow-live-summary">
                        <?php foreach (icct_nms_netflow_rule_metrics() as $metric => [$metricLabel, $unit]): ?>
                            <div>
                                <span><?= icct_nms_h($metric === 'distributed_syn_rate' ? 'Peak distributed TCP SYN rate' : $metricLabel) ?></span>
                                <strong data-netflow-live="<?= icct_nms_h($metric) ?>" data-unit="<?= icct_nms_h($unit) ?>">—</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <form class="device-history-filters netflow-filters" data-netflow-view="<?= $key ?>"
                data-device-id="<?= (int) $id ?>" aria-label="<?= $label ?> filters">
                <label class="field">
                    <span data-field-help="Choose a recent time window or enter both dates and times for a custom range.">Time range</span>
                    <span class="select-wrap">
                        <select name="hours">
                            <option value="1">Last hour</option>
                            <option value="24" selected>Last 24 hours</option>
                            <option value="168">Last 7 days</option>
                            <option value="720">Last 30 days</option>
                            <option value="custom">Custom range</option>
                        </select>
                    </span>
                </label>
                <label class="field" data-netflow-custom-date hidden>
                    <span data-field-help="The beginning of the custom time range in India Standard Time.">Start (IST)</span>
                    <input type="datetime-local" name="start" disabled>
                </label>
                <label class="field" data-netflow-custom-date hidden>
                    <span data-field-help="The end of the custom time range in India Standard Time.">End (IST)</span>
                    <input type="datetime-local" name="end" disabled>
                </label>
                <?php if ($key === 'anomalies'): ?>
                    <label class="field">
                        <span data-field-help="Filter findings by the severity assigned by the anomaly rule.">Severity</span>
                        <span class="select-wrap">
                            <select name="event_severity">
                                <option value="">All severities</option>
                                <?php foreach (['Critical', 'Major', 'Minor', 'Warning', 'Information'] as $severity): ?>
                                    <option><?= $severity ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </label>
                    <label class="field">
                        <span data-field-help="Active findings are ongoing. Resolved findings were cleared by later observations.">Status</span>
                        <span class="select-wrap">
                            <select name="event_state">
                                <option value="">All statuses</option>
                                <option>Active</option>
                                <option>Resolved</option>
                            </select>
                        </span>
                    </label>
                <?php else: ?>
                    <label class="field">
                        <span data-field-help="Filter by the configured LAN or WAN classification. Unclassified traffic matched neither.">Traffic</span>
                        <span class="select-wrap">
                            <select name="traffic_scope">
                                <option value="">All traffic</option>
                                <option>LAN</option>
                                <option>WAN</option>
                                <option value="Unknown">Unclassified</option>
                            </select>
                        </span>
                    </label>
                    <?php if ($key !== 'overview'): ?>
                        <label class="field">
                            <span data-field-help="Filter by the network protocol recorded in each flow, such as TCP or UDP.">Protocol</span>
                            <span class="select-wrap">
                                <select name="protocol">
                                    <option value="">All protocols</option>
                                    <option value="6">TCP</option>
                                    <option value="17">UDP</option>
                                    <option value="1">ICMP</option>
                                    <option value="58">ICMPv6</option>
                                </select>
                            </span>
                        </label>
                        <label class="field">
                            <span data-field-help="Search application names or identifiers available in the stored flow records.">Application</span>
                            <input name="application" maxlength="120" placeholder="Name or application ID">
                        </label>
                    <?php endif; ?>
                    <?php if ($key === 'records'): ?>
                        <label class="field">
                            <span data-field-help="Enter the exact IPv4 or IPv6 address that sent the traffic.">Source IP</span>
                            <input name="src_addr" placeholder="Exact IP address">
                        </label>
                        <label class="field">
                            <span data-field-help="Enter the exact IPv4 or IPv6 address that received the traffic.">Destination IP</span>
                            <input name="dst_addr" placeholder="Exact IP address">
                        </label>
                        <label class="field">
                            <span data-field-help="Enter the exact destination port, from 0 to 65535.">Destination port</span>
                            <input name="dst_port" type="number" min="0" max="65535">
                        </label>
                        <label class="field">
                            <span data-field-help="Choose the format the device used to send these records to the collector.">Export version</span>
                            <span class="select-wrap">
                                <select name="version">
                                    <option value="">All versions</option>
                                    <option value="5">v5</option>
                                    <option value="7">v7</option>
                                    <option value="9">v9</option>
                                    <option value="10">IPFIX</option>
                                </select>
                            </span>
                        </label>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="device-history-actions">
                    <button class="button" type="reset">Clear All</button>
                    <?php if ($key === 'breakdown'): ?>
                        <a class="button" id="netflow-breakdown-export"
                            href="protocols/netflow/controllers/netflow.php?id=<?= (int) $id ?>&amp;export=breakdown">Export Breakdown CSV</a>
                    <?php endif; ?>
                    <?php if ($key === 'records'): ?>
                        <a class="button" id="netflow-export" data-tooltip="Download all traffic records matching the filters on this tab."
                            href="protocols/netflow/controllers/netflow.php?id=<?= (int) $id ?>&amp;export=1">Export CSV</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($key === 'overview'): ?>
                <div
                    class="netflow-summary"
                    id="netflow-totals"></div>
                <div class="netflow-chart-panel">
                    <h3 data-field-help="Average traffic rate calculated from exported flows in each time interval. Empty intervals have no reported readings.">LAN / WAN Bandwidth Trends</h3>
                    <p id="netflow-chart-interval">Average throughput from exported flow records.</p>
                    <svg
                        id="netflow-chart"
                        viewBox="0 0 900 220"
                        role="img"
                        aria-label="LAN and WAN bandwidth history"></svg>
                    <p class="netflow-legend">
                        <span>● LAN</span>
                        <span>● WAN</span>
                        <span>● Unclassified</span>
                    </p>
                </div>
            <?php elseif ($key === 'breakdown'): ?>
                <h3 data-field-help="Ranks the top entries in each group by bytes for the current filters.">Traffic breakdown</h3>
                <div
                    class="netflow-rankings"
                    id="netflow-rankings"></div>
            <?php elseif ($key === 'records'): ?>
                <h3 data-field-help="Each row shows one stored flow. The Application column indicates how the application was identified when that information is available.">Traffic Records</h3>
                <div class="site-table-wrap">
                    <table class="site-table netflow-table">
                        <thead>
                            <tr>
                                <th>First / Last Seen (IST)</th>
                                <th>Version</th>
                                <th>Source</th>
                                <th>Destination</th>
                                <th>Protocol</th>
                                <th>Bytes / Packets</th>
                                <th>Application</th>
                                <th>LAN / WAN</th>
                            </tr>
                        </thead>
                        <tbody id="netflow-records"></tbody>
                    </table>
                </div>
                <footer class="inventory-pagination">
                    <label>Items per page:
                        <span class="select-wrap">
                            <select id="netflow-size">
                                <option>10</option>
                                <option selected>25</option>
                                <option>50</option>
                                <option>100</option>
                            </select>
                        </span>
                    </label>
                    <span id="netflow-range"></span>
                    <div class="page-controls">
                        <span class="select-wrap">
                            <select
                                id="netflow-page"
                                aria-label="NetFlow page"></select>
                        </span>
                        <span id="netflow-pages"></span>
                        <button
                            class="pagination-control"
                            type="button"
                            id="netflow-prev"
                            aria-label="Previous NetFlow page">
                            <svg
                                class="pagination-arrow"
                                viewBox="0 0 24 24"
                                aria-hidden="true">
                                <path d="M15 5 7 12 15 19Z" />
                            </svg>
                        </button>
                        <button
                            class="pagination-control"
                            type="button"
                            id="netflow-next"
                            aria-label="Next NetFlow page">
                            <svg
                                class="pagination-arrow"
                                viewBox="0 0 24 24"
                                aria-hidden="true">
                                <path d="M9 5 17 12 9 19Z" />
                            </svg>
                        </button>
                    </div>
                </footer>
            <?php else: ?>
                <h3 data-field-help="Rules flag unusual traffic patterns such as scans or high bandwidth use. Each finding describes the pattern that triggered it.">Traffic Anomalies</h3>
                <p id="netflow-linked-finding" hidden>Showing the finding linked to this alarm. Clear All restores the time range filter.</p>
                <div class="site-table-wrap">
                    <table class="site-table">
                        <thead>
                            <tr>
                                <th>Detected / Updated (IST)</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Finding</th>
                                <th>Alarm</th>
                            </tr>
                        </thead>
                        <tbody id="netflow-events"></tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</section>
