<!-- Upload dialog hooks are shared by client validation and server response handling. -->
<dialog
    id="mibUploadDialog"
    class="mib-upload-dialog"
    data-auto-open="<?= !empty($uploadOpen) ? 'true' : 'false' ?>"
    aria-labelledby="mibUploadTitle">
    <header class="mib-upload-heading">
        <h2 id="mibUploadTitle">Upload MIB</h2>
        <button
            type="button"
            data-mib-upload-close
            aria-label="Close MIB upload">×</button>
    </header>
    <div class="mib-upload-body">
        <p
            id="mibUploadError"
            class="notice error"
            role="alert"
            hidden></p>
        <section
            id="mibUploadRepositoryWarning"
            class="mib-upload-repository-warning"
            role="status"
            hidden>
            <strong>Repository warning</strong>
            <ul id="mibUploadRepositoryMatches"></ul>
            <span
                class="field-info section-help"
                tabindex="0"
                role="img"
                aria-label="Saving keeps a separate entry. Existing files and Cacti templates will not be replaced."
                data-tooltip="Saving keeps a separate entry. Existing files and Cacti templates will not be replaced.">ⓘ</span>
        </section>
        <div
            class="mib-upload-notice"
            id="mibUploadIntro">
            <svg
                viewBox="0 0 20 20"
                aria-hidden="true">
                <circle
                    cx="10"
                    cy="10"
                    r="8" />
                <path d="M10 9v5M10 5.5v.5" />
            </svg>
            <span
                class="field-info section-help"
                tabindex="0"
                role="img"
                aria-label="The application scans the MIB to identify OIDs, check Data Source eligibility, and flag existing Cacti configurations."
                data-tooltip="The application scans the MIB to identify OIDs, check Data Source eligibility, and flag existing Cacti configurations.">ⓘ</span>
        </div>
        <?php if (!empty($uploadChooser)): ?>
            <form
                method="post"
                action="protocols/snmp/mibs/controllers/mib_repository.php"
                enctype="multipart/form-data"
                data-mib-upload-form
                id="mibUploadChooser">
                <?php icct_nms_token(); ?>
                <input
                    type="hidden"
                    name="action"
                    value="upload">
                <label
                    class="field mib-upload-file-field"
                    for="mibUploadFile">
                    <span class="field-label">MIB File *</span>
                    <input
                        id="mibUploadFile"
                        type="file"
                        name="mibs[]"
                        accept=".mib,.my,.txt"
                        aria-describedby="mibUploadFileHelp"
                        required>
                </label>
                <span
                    class="field-info section-help mib-upload-file-help"
                    id="mibUploadFileHelp"
                    tabindex="0"
                    role="img"
                    aria-label=".mib, .my or .txt · Maximum 1 MiB"
                    data-tooltip=".mib, .my or .txt · Maximum 1 MiB">ⓘ</span>
                <div class="mib-upload-actions">
                    <button
                        class="button"
                        type="button"
                        data-mib-upload-close>Cancel</button>
                    <button
                        class="button primary"
                        type="submit">Upload</button>
                </div>
            </form>
        <?php endif; ?>
        <section
            id="mibUploadProgress"
            hidden
            aria-label="MIB upload progress">
            <p id="mibUploadProgressText">Please wait while the MIB file uploads and is read.</p>
            <p id="mibUploadFilename"></p>
            <progress
                id="mibUploadBar"
                max="100"
                aria-label="Upload and scan progress"></progress>
            <p
                id="mibUploadCount"
                role="status"
                aria-live="polite"></p>
            <div class="mib-upload-actions">
                <button
                    class="button"
                    type="button"
                    data-mib-upload-close>Close</button>
                <button
                    class="button primary"
                    type="button"
                    id="mibUploadReadNext"
                    hidden>Next →</button>
            </div>
        </section>
        <section
            id="mibUploadNext"
            hidden
            aria-label="Choose next step">
            <table
                class="mib-upload-summary"
                aria-label="Uploaded MIB summary">
                <thead>
                    <tr>
                        <th>MIB Name</th>
                        <th>No. of OIDs</th>
                        <th>Supports Data Source</th>
                        <th>Existing Data Source</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td data-upload-summary="name"></td>
                        <td data-upload-summary="object_count"></td>
                        <td data-upload-summary="supported_count"></td>
                        <td data-upload-summary="existing_count"></td>
                    </tr>
                </tbody>
            </table>
            <p id="mibUploadReadyText">How would you like to proceed?</p>
            <form id="mibUploadNextForm">
                <label class="mib-upload-choice">
                    <input
                        type="radio"
                        name="upload_next"
                        value="automatic"
                        checked>
                    <span>
                        <strong>Configure Automatically</strong>
                        <small>Prepare data sources, graphs and a device template from the MIB
                            definitions.</small>
                    </span>
                    <div class="mib-upload-notice mib-upload-duplicate-note">
                        <svg
                            viewBox="0 0 20 20"
                            aria-hidden="true">
                            <circle
                                cx="10"
                                cy="10"
                                r="8" />
                            <path d="M10 9v5M10 5.5v.5" />
                        </svg>
                        <span
                            class="field-info section-help"
                            tabindex="0"
                            role="img"
                            aria-label="Existing Data Sources will not be duplicated. You can modify their settings after adding them to a device."
                            data-tooltip="Existing Data Sources will not be duplicated. You can modify their settings after adding them to a device.">ⓘ</span>
                    </div>
                </label>
                <label class="mib-upload-choice">
                    <input
                        type="radio"
                        name="upload_next"
                        value="repository">
                    <span>
                        <strong>Repository Only</strong>
                        <small>Save the file to the repository and configure monitoring later.
                        </small>
                    </span>
                </label>
                <label
                    class="mib-upload-acknowledgment"
                    id="mibUploadRepositoryConsent"
                    hidden>
                    <input
                        type="checkbox"
                        id="mibUploadRepositoryAcknowledged"> I reviewed the repository overlap and want to save a separate entry.
                </label>
                <div class="mib-upload-actions">
                    <button
                        type="submit"
                        class="button primary"
                        id="mibUploadProceed">Proceed</button>
                </div>
            </form>
        </section>
    </div>
</dialog>
