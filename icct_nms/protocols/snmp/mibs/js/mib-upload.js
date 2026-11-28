/** Read into a temporary session draft; only explicit Save/Proceed persists files. */
(() => {
    'use strict';
    const dialog = document.querySelector('#mibUploadDialog');
    if (!dialog) return;
    const title = document.querySelector('#mibUploadTitle'),
        chooser = document.querySelector('#mibUploadChooser'),
        progress = document.querySelector('#mibUploadProgress'),
        bar = document.querySelector('#mibUploadBar'),
        count = document.querySelector('#mibUploadCount'),
        filename = document.querySelector('#mibUploadFilename'),
        error = document.querySelector('#mibUploadError');
    const fileInput = chooser.querySelector('[type=file]'),
        nextStep = document.querySelector('#mibUploadNext'),
        nextForm = document.querySelector('#mibUploadNextForm'),
        proceed = document.querySelector('#mibUploadProceed'),
        readNext = document.querySelector('#mibUploadReadNext'),
        readyText = document.querySelector('#mibUploadReadyText'),
        progressText = document.querySelector('#mibUploadProgressText');
    const warning = document.querySelector('#mibUploadRepositoryWarning'),
        matches = document.querySelector('#mibUploadRepositoryMatches'),
        consent = document.querySelector('#mibUploadRepositoryConsent'),
        ack = document.querySelector('#mibUploadRepositoryAcknowledged');
    const closeButtons = [...dialog.querySelectorAll('[data-mib-upload-close]')];
    let busy = false,
        saving = false,
        returnFocus = null,
        completed = null,
        activeToken = null,
        activeRequest = null;
    const endpoint = new URL(chooser.getAttribute('action'), document.baseURI);
    function working(value) {
        busy = value;
        closeButtons.forEach((button) => (button.disabled = value && saving));
        dialog.setAttribute('aria-busy', String(value));
    }
    function controls(action, token) {
        const body = new FormData(chooser);
        body.delete('mibs[]');
        body.set('action', action);
        body.set('upload_token', token);
        return body;
    }
    function showError(message) {
        error.textContent = message;
        error.hidden = false;
    }
    function repositoryWarning(comparison) {
        matches.replaceChildren();
        ack.checked = false;
        warning.hidden = !comparison?.warnings?.length;
        consent.hidden = warning.hidden;
        ack.required = !warning.hidden;
        for (const match of comparison?.warnings || []) {
            const row = document.createElement('li');
            row.textContent =
                (match.same_module
                    ? 'Same module with different content: '
                    : 'Overlapping OIDs with module: ') +
                match.module +
                ' — ' +
                match.file +
                '. ' +
                (match.oid_comparison_complete
                    ? 'OID counts — shared: ' +
                      match.shared_oids +
                      ', added: ' +
                      match.added_oids +
                      ', removed: ' +
                      match.removed_oids +
                      ', changed: ' +
                      match.changed_oids +
                      '.'
                    : 'OID comparison unavailable because some MIB definitions are unresolved.');
            matches.append(row);
        }
    }
    function validation(summary) {
        const section = document.querySelector('#mibUploadValidation'),
            rows = document.querySelector('#mibUploadValidationRows');
        rows.replaceChildren();
        for (const file of summary.validation || []) {
            const row = document.createElement('tr');
            for (const value of [file.name, file.module, file.message]) {
                const cell = document.createElement('td');
                cell.textContent = String(value ?? '');
                row.append(cell);
            }
            rows.append(row);
        }
        document.querySelector('#mibUploadDependencies').textContent =
            summary.dependencies?.length ? 'Dependencies resolved locally: ' + summary.dependencies.join(', ') : 'All dependencies are included in the selected files.';
        section.hidden = false;
    }
    function validComparison(value) {
        return (
            value &&
            typeof value.blocked === 'boolean' &&
            Array.isArray(value.warnings) &&
            Array.isArray(value.exact) &&
            /^[a-f0-9]{64}$/.test(value.fingerprint)
        );
    }
    function discardDraft(token) {
        return fetch(endpoint.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            body: controls('discard_upload', token),
            keepalive: true,
        }).catch(() => {});
    }
    function discard() {
        if (saving) return;
        const token = activeToken;
        activeToken = null;
        completed = null;
        if (activeRequest) {
            activeRequest.abort();
            activeRequest = null;
        }
        working(false);
        chooser.reset();
        nextForm.reset();
        progress.hidden = true;
        nextStep.hidden = true;
        error.hidden = true;
        readNext.hidden = true;
        proceed.disabled = false;
        repositoryWarning(null);
        document.querySelector('#mibUploadValidation').hidden = true;
        dialog.classList.remove('mib-upload-next-step');
        title.textContent = 'Upload MIB Files';
        if (token) discardDraft(token);
        if (returnFocus?.isConnected) returnFocus.focus();
    }
    closeButtons.forEach((button) =>
        button.addEventListener('click', () => {
            if (!saving) dialog.close();
        }),
    );
    dialog.addEventListener('cancel', (event) => {
        if (saving) event.preventDefault();
    });
    dialog.addEventListener('close', discard);
    document.querySelectorAll('[data-mib-upload-open]').forEach((button) =>
        button.addEventListener('click', () => {
            if (busy) return;
            returnFocus = button;
            fileInput.click();
        }),
    );
    if (dialog.dataset.autoOpen === 'true') document.querySelector('[data-mib-upload-open]')?.focus();
    readNext.addEventListener('click', () => {
        if (!completed || busy) return;
        progress.hidden = true;
        nextStep.hidden = false;
        dialog.classList.add('mib-upload-next-step');
        title.textContent = 'MIB Upload Successful – Choose Next Step';
        proceed.focus();
    });
    function choice() {
        proceed.textContent =
            nextForm.querySelector('[name=upload_next]:checked').value === 'repository'
                ? 'Save'
                : 'Proceed';
        proceed.disabled =
            busy || !!completed?.repository_check?.blocked || (!consent.hidden && !ack.checked);
    }
    nextForm.addEventListener('change', choice);
    nextForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!completed || busy) return;
        if (completed.repository_check?.blocked) return;
        if (!consent.hidden && !ack.checked) {
            showError('Review and acknowledge the repository warning before saving.');
            ack.focus();
            return;
        }
        const token = activeToken,
            body = controls('commit_upload', token);
        body.set('repository_ack', ack.checked ? completed.repository_check.fingerprint : '');
        body.set('upload_next', nextForm.querySelector('[name=upload_next]:checked').value);
        saving = true;
        working(true);
        proceed.disabled = true;
        error.hidden = true;
        title.textContent = 'Saving MIB Files…';
        try {
            const response = await fetch(endpoint.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body,
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw Error('Your session expired. Reopen the repository.');
            const result = await response.json();
            if (!response.ok || !result.ok) {
                if (validComparison(result.repository_check)) {
                    completed.repository_check = result.repository_check;
                    repositoryWarning(result.repository_check);
                }
                throw Error(result.error || 'Could not save the MIB files.');
            }
            if (typeof result.redirect !== 'string')
                throw Error(
                    'The save response was incomplete. Retry Save to check the same upload.',
                );
            const next = new URL(result.redirect, document.baseURI);
            if (next.origin !== location.origin || next.pathname !== endpoint.pathname)
                throw Error('Invalid repository destination.');
            activeToken = null;
            completed = null;
            location.assign(next.href);
        } catch (failure) {
            saving = false;
            working(false);
            proceed.disabled = false;
            title.textContent = 'MIB Upload Successful – Choose Next Step';
            showError(failure.message);
            choice();
        }
    });
    fileInput.addEventListener('change', () => {
        if (busy) return;
        const files = [...fileInput.files];
        if (!files.length) return;
        if (!dialog.open) dialog.showModal();
        if (activeToken) discardDraft(activeToken);
        activeToken = null;
        completed = null;
        progress.hidden = true;
        nextStep.hidden = true;
        readNext.hidden = true;
        document.querySelector('#mibUploadValidation').hidden = true;
        const fileError =
            files.length > 20 ? 'Select up to 20 MIB files, including dependencies.' :
            files.some(file => !/\.(mib|my|txt)$/i.test(file.name)) ? 'Select .mib, .my or .txt MIB files.' :
            files.some(file => file.size < 1 || file.size > 1048576) ? 'Each MIB file must be nonempty and no larger than 1 MiB.' :
            files.reduce((total, file) => total + file.size, 0) > 8388608 ? 'The selected files exceed the 8 MiB batch limit.' : '';
        if (fileError) {
            showError(fileError);
            return;
        }
        let token;
        try {
            token = icctRandomToken();
        } catch (failure) {
            showError(failure.message);
            return;
        }
        activeToken = token;
        completed = null;
        repositoryWarning(null);
        document.querySelector('#mibUploadValidation').hidden = true;
        readNext.hidden = true;
        working(true);
        title.textContent = 'Validating MIB Files';
        error.hidden = true;
        progress.hidden = false;
        bar.max = 100;
        bar.value = 0;
        progressText.textContent =
            'Validating all files and dependencies. Nothing is saved until you choose Save or Proceed.';
        filename.textContent = 'Uploading “' + files.map(file => file.name).join(', ') + '”';
        count.textContent = 'Uploading ' + files.length + ' files…';
        const body = new FormData(chooser);
        body.set('upload_file_count', String(files.length));
        body.set('upload_response', 'json');
        body.set('upload_progress', '1');
        body.set('upload_token', token);
        const xhr = new XMLHttpRequest();
        activeRequest = xhr;
        xhr.open('POST', endpoint.href, true);
        xhr.responseType = 'text';
        xhr.timeout = 180000;
        xhr.setRequestHeader('Accept', 'application/x-ndjson');
        function fail(message) {
            if (activeToken !== token) return;
            activeRequest = null;
            working(false);
            title.textContent = 'Upload MIB Files';
            progress.hidden = true;
            showError(message);
        }
        let received = 0,
            pending = '',
            streamResult = null;
        function readUpdates() {
            if (activeToken !== token) return;
            const text = xhr.responseText || '';
            pending += text.slice(received);
            received = text.length;
            let newline;
            while ((newline = pending.indexOf('\n')) >= 0) {
                const line = pending.slice(0, newline).trim();
                pending = pending.slice(newline + 1);
                if (!line) continue;
                let update;
                try {
                    update = JSON.parse(line);
                } catch (_) {
                    continue;
                }
                if (update.type === 'complete' || update.type === 'error') {
                    streamResult = update;
                    continue;
                }
                if (update.type !== 'progress') continue;
                if (
                    update.phase === 'scan' &&
                    Number.isInteger(update.processed) &&
                    Number.isInteger(update.total) &&
                    update.total >= 0 &&
                    update.processed >= 0 &&
                    update.processed <= update.total
                ) {
                    bar.max = Math.max(1, update.total);
                    bar.value = update.processed;
                    count.textContent =
                        update.processed + '/' + update.total + ' OIDs Read & Scanned';
                    filename.textContent =
                        'Scanning “' +
                        files.map(file => file.name).join(', ') +
                        '”' +
                        (update.symbol ? ' — ' + update.symbol : '');
                } else if (typeof update.message === 'string') count.textContent = update.message;
            }
        }
        xhr.addEventListener('progress', readUpdates);
        xhr.upload.addEventListener('progress', (event) => {
            if (activeToken === token && event.lengthComputable) {
                bar.max = 100;
                bar.value = (event.loaded / event.total) * 100;
                count.textContent = 'Uploading files: ' + Math.round(bar.value) + '%';
            }
        });
        xhr.upload.addEventListener('load', () => {
            if (activeToken === token) {
                bar.removeAttribute('value');
                count.textContent = 'Files uploaded temporarily. Validating imports and OIDs…';
            }
        });
        xhr.addEventListener('load', () => {
            if (activeToken !== token) return;
            readUpdates();
            let result = streamResult;
            try {
                if (!result)
                    result =
                        typeof xhr.response === 'object'
                            ? xhr.response
                            : JSON.parse(xhr.responseText || xhr.response || 'null');
            } catch (_) {}
            if (xhr.status < 200 || xhr.status >= 300 || !result?.ok) {
                fail(result?.error || 'The files could not be validated. Check the files and retry.');
                return;
            }
            const summary = result.summary;
            if (!validComparison(summary?.repository_check)) {
                fail(
                    'The server did not return a complete repository duplicate check. Read the file again.',
                );
                return;
            }
            if (summary.repository_check.blocked) {
                fail(summary.repository_check.message);
                return;
            }
            if (
                !Array.isArray(summary?.validation) || summary.validation.length !== files.length ||
                !summary.validation.every(file => file.status === 'valid' && typeof file.module === 'string') ||
                result.upload_token !== token ||
                !summary ||
                typeof summary.name !== 'string' ||
                typeof summary.can_configure !== 'boolean' ||
                !['object_count', 'supported_count', 'existing_count'].every(
                    (key) => Number.isInteger(summary[key]) && summary[key] >= 0,
                ) ||
                summary.existing_count > summary.supported_count ||
                summary.supported_count > summary.object_count ||
                result.object_count !== summary.object_count
            ) {
                fail(
                    'The server returned an incomplete MIB summary. Close the popup and read the file again.',
                );
                return;
            }
            validation(summary);
            completed = summary;
            repositoryWarning(summary.repository_check);
            activeRequest = null;
            working(false);
            readNext.hidden = false;
            title.textContent = summary.parse_error
                ? 'MIB Read — Action Required'
                : 'MIB Read & Scanned';
            bar.max = 100;
            bar.value = 100;
            filename.textContent = 'Files: ' + files.map(file => file.name).join(', ');
            count.textContent =
                summary.object_count + '/' + summary.object_count + ' OIDs Read & Scanned';
            progressText.textContent =
                'All files validated. Nothing has been saved to the repository.';
            readyText.textContent = summary.parse_error
                ? 'Resolve these dependencies to configure monitoring, or save the file to the repository only.'
                : 'Choose Save or Proceed to keep these files. Closing discards the batch.';
            nextStep
                .querySelectorAll('[data-upload-summary]')
                .forEach(
                    (cell) =>
                        (cell.textContent = String(summary[cell.dataset.uploadSummary] ?? '—')),
                );
            const automatic = nextForm.querySelector('[value=automatic]'),
                repositoryChoice = nextForm.querySelector('[value=repository]');
            automatic.disabled = !summary.can_configure;
            automatic.checked = !!summary.can_configure;
            repositoryChoice.checked = !summary.can_configure;
            choice();
            if (summary.parse_error) showError(summary.parse_error);
            readNext.focus();
        });
        xhr.addEventListener('error', () =>
            fail(
                'The connection was interrupted. No file was saved to the repository. Retry reading the file.',
            ),
        );
        xhr.addEventListener('timeout', () =>
            fail(
                'Reading timed out. No file was saved to the repository. Close the popup and retry.',
            ),
        );
        xhr.send(body);
    });
})();
