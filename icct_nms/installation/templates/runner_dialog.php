    <dialog
        class="installation-runner-dialog"
        aria-labelledby="installation-runner-title">
        <header class="installation-runner-header">
            <h2 id="installation-runner-title">Package operation</h2>
            <button
                type="button"
                data-package-close
                aria-label="Close package operation">×</button>
        </header>
        <div class="installation-runner-body">
            <p
                data-package-summary
                role="status"></p>
            <progress
                aria-label="Completed package checks"
                max="4"
                value="0"
                hidden></progress>
            <div
                data-package-review
                hidden></div>
            <details
                class="installation-runner-log"
                hidden>
                <summary>Operation details</summary>
                <ul
                    data-package-steps
                    aria-label="Package runner output"></ul>
            </details>
        </div>
        <div class="message-actions">
            <button
                type="button"
                class="button"
                data-package-close
                autofocus>Close</button>
            <button
                type="button"
                class="button primary"
                data-package-confirm
                hidden>Continue</button>
        </div>
    </dialog>
