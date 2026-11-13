<!-- Graph filters preserve the selected device and time range across form submissions. -->
<div
    class="device-graph-controls"
    data-device-id="<?= (int) $id ?>">
    <div class="device-graph-filter-row">
        <label class="field graph-search">
            <span class="field-label">Search graphs</span>
            <input
                type="search"
                id="device-graph-search"
                placeholder="Graph title">
        </label>
        <label class="field">
            <span
                class="field-label"
                data-field-help="Maximum graphs displayed on each page. All displays every matching graph.">Graphs per page</span>
            <select id="device-graph-limit">
                <?php foreach (
                    [10, 25, 50, 100] as $size
                ): ?>
                    <option value="<?= $size ?>"><?= $size ?></option>
                <?php endforeach; ?>
                <option value="0">All</option>
            </select>
        </label>
        <label class="field">
            <span
                class="field-label"
                data-field-help="Number of graph columns in this device view.">Columns</span>
            <select id="device-graph-columns">
                <?php foreach (
                    range(1, 6) as $col
                ): ?>
                    <option
                        value="<?= $col ?>"
                        <?= $col === 2 ? 'selected' : '' ?>><?= $col ?> columns</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="graph-check">
            <input
                type="checkbox"
                id="device-graph-thumbnails"> Thumbnails
        </label>
        <button
            type="button"
            class="button secondary"
            id="device-graph-save">Save filters</button>
        <button
            type="button"
            class="button secondary"
            id="device-graph-clear">Clear</button>
    </div>
    <div class="device-graph-filter-row">
        <label class="field">
            <span
                class="field-label"
                data-field-help="Time range used for the displayed graphs. Choose Custom to enter From and To.">Time preset</span>
            <select id="device-graph-preset">
                <option value="3600">Last hour</option>
                <option value="21600">Last 6 hours</option>
                <option
                    value="86400"
                    selected>Last day</option>
                <option value="604800">Last week</option>
                <option value="2592000">Last month</option>
                <option value="custom">Custom</option>
            </select>
        </label>
        <label class="field">
            <span
                class="field-label"
                data-field-help="Start of the graph time range in your browser’s local time. Must be earlier than To.">From</span>
            <input
                type="datetime-local"
                id="device-graph-from">
        </label>
        <label class="field">
            <span
                class="field-label"
                data-field-help="End of the graph time range in your browser’s local time. Must be later than From.">To</span>
            <input
                type="datetime-local"
                id="device-graph-to">
        </label>
        <button
            type="button"
            class="button secondary"
            id="device-graph-earlier"
            aria-label="Previous time range">←</button>
        <button
            type="button"
            class="button secondary"
            id="device-graph-later"
            aria-label="Next time range">→</button>
        <button
            type="button"
            class="button"
            id="device-graph-refresh">Refresh</button>
    </div>
    <p
        id="device-graph-filter-status"
        role="status"
        aria-live="polite"></p>
</div>
