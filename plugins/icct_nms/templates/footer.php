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
    <script src="assets/js/inventory.js?v=<?= substr(
        hash_file("sha256", __DIR__ . "/../assets/js/inventory.js"),
        0,
        12,
    ) ?>" defer></script>
</body>
</html>
