<?php
/**
 * Close the Inventory shell and load local progressive UI behavior.
 */
?>
        </section>
    </main>
    <dialog id="message-dialog" aria-labelledby="message-title" aria-describedby="message-text">
        <div class="message-heading">
            <h2 id="message-title"></h2>
            <button type="button" id="message-close" aria-label="Close message">×</button>
        </div>
        <p id="message-text"></p>
        <div class="message-actions">
            <button class="button" type="button" id="message-cancel">Cancel</button>
            <button class="button primary" type="button" id="message-confirm">OK</button>
        </div>
    </dialog>
    <dialog id="shared-diagnostic-dialog" aria-labelledby="shared-diagnostic-title">
        <div class="message-heading"><h2 id="shared-diagnostic-title">Device Diagnostics</h2><button type="button" data-shared-diagnostic-close aria-label="Close diagnostics">×</button></div>
        <form id="shared-diagnostic-form"><?php icct_nms_token(); ?><input type="hidden" name="host_id"><input type="hidden" name="action" value="queue"><div class="field"><label for="shared-diagnostic-tool">Diagnostic method</label><select id="shared-diagnostic-tool" name="tool"></select></div><div class="protocol-actions"><button class="button primary" type="submit">Run Diagnostic</button></div></form>
        <p id="shared-diagnostic-status" role="status" aria-live="polite"></p><pre id="shared-diagnostic-output" hidden></pre>
        <div class="message-actions"><button class="button" type="button" data-shared-diagnostic-close>Close</button></div>
    </dialog>
    <script src="assets/js/diagnostic-popup.js?v=<?= substr(hash_file('sha256',ICCT_NMS_ROOT.'/assets/js/diagnostic-popup.js'),0,12) ?>" defer></script>
    <script src="assets/js/inventory.js?v=<?= substr(
        hash_file("sha256", __DIR__ . "/../assets/js/inventory.js"),
        0,
        12,
    ) ?>" defer></script>
<?php if (!empty($wizard)): ?>
    <dialog id="unsaved-dialog" aria-labelledby="unsaved-title">
        <div class="message-heading"><h2 id="unsaved-title">Unsaved changes</h2><button type="button" data-draft-choice="cancel" aria-label="Close unsaved changes">×</button></div>
        <p>Save your changes before leaving this device?</p>
        <div class="message-actions"><button type="button" class="button" data-draft-choice="cancel">Keep editing</button><button type="button" class="button" data-draft-choice="discard">Discard</button><button type="button" class="button primary" data-draft-choice="save">Save</button></div>
    </dialog>
    <script src="assets/js/ports.js?v=<?= substr(hash_file('sha256', __DIR__.'/../assets/js/ports.js'),0,12) ?>" defer></script>
    <script src="assets/js/fcaps.js?v=<?= substr(hash_file('sha256', __DIR__.'/../assets/js/fcaps.js'),0,12) ?>" defer></script>
    <script src="assets/js/wizard.js?v=<?= substr(hash_file('sha256', __DIR__.'/../assets/js/wizard.js'),0,12) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($presetsPage)): ?>
    <script src="assets/js/presets.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/presets.js'),0,12) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($nodeConfigurationPage)): ?>
    <script src="assets/js/node-configuration.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/node-configuration.js'),0,12) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($mapPage)): ?>
    <script src="assets/js/dashboard.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/dashboard.js'),0,12) ?>" defer></script>
    <script src="assets/vendor/leaflet/leaflet.js" defer></script>
    <script src="assets/js/rack-view.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/rack-view.js'),0,12) ?>" defer></script>
    <script src="assets/js/topology-view.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/topology-view.js'),0,12) ?>" defer></script>
    <script src="assets/js/map.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/map.js'),0,12) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($topologyConfigurationPage)): ?>
<script src="assets/js/topology-configuration.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/topology-configuration.js'),0,12) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($mibRepositoryPage)): ?>
<script src="assets/js/mib-repository.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/mib-repository.js'),0,12) ?>" defer></script>
<?php endif; ?>
</body>
</html>
