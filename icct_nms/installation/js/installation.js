/* Only display progress measured by the local runner. */
(function () {
    'use strict';

    const portSave = document.querySelector('[data-syslog-save-job]');
    if (portSave) {
        const receiverPort = document.querySelector('#syslog-receiver-port');
        const initialPort = receiverPort.value;
        const deadline = Date.now() + 90000;
        const pollPort = async () => {
            try {
                const response = await fetch('installation/controllers/installation.php?view=package-job');
                if (!response.ok) throw new Error();
                const state = await response.json();
                const job = state.job;
                if (job?.id === portSave.dataset.syslogSaveJob && job.status !== 'running') {
                    if (job.status === 'complete' && receiverPort.value === initialPort) {
                        receiverPort.value = state.syslog_port;
                    }
                    portSave.textContent = job.status === 'complete'
                        ? 'Receiver port updated to ' + state.syslog_port + '.'
                        : (job.steps.at(-1) || 'The receiver port change failed.');
                    return;
                }
            } catch {
                // Retry briefly while the local runner starts.
            }
            if (Date.now() < deadline) window.setTimeout(pollPort, 1500);
            else portSave.textContent = 'The port change could not be confirmed. Check Installation → Offline Packages.';
        };
        pollPort();
    }

    const page = document.querySelector('[data-package-page]');
    if (!page) return;

    const endpoint = page.dataset.endpoint;
    const token = page.querySelector('[name="__csrf_magic"]');
    const dialog = page.querySelector('dialog');
    const title = dialog.querySelector('h2');
    const summary = dialog.querySelector('[data-package-summary]');
    const progress = dialog.querySelector('progress');
    const steps = dialog.querySelector('[data-package-steps]');
    const log = dialog.querySelector('.installation-runner-log');
    const review = dialog.querySelector('[data-package-review]');
    const confirm = dialog.querySelector('[data-package-confirm]');
    const close = dialog.querySelector('.message-actions [data-package-close]');
    const last = page.querySelector('[data-package-last]');
    let selection;
    let watchedJob;
    let latestJob;
    let submitting = false;
    let startedAt = 0;
    let previousSteps = 0;
    let previewId = '';

    async function request(options) {
        const response = await fetch(endpoint, options);
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'The package runner request failed.');
        return data;
    }

    function updatePackages(state) {
        latestJob = state.job;
        if (last) last.hidden = !latestJob;
        const installed = { ...state.installed, ...state.external };
        const busy = submitting || Boolean(watchedJob) || state.job?.status === 'running';
        const availability = page.querySelector('[data-package-availability]');
        const verify = page.querySelector('[data-package-action="verify"]');
        if (availability) availability.textContent = state.message;
        if (verify) verify.disabled = busy || !state.available;
        page.querySelectorAll('[data-package-profile]').forEach((profile) => {
            const rows = Array.from(profile.querySelectorAll('[data-rpm]'));
            const names = rows.map((row) => row.dataset.rpm);
            const versions = state.available ? (state.versions?.[profile.dataset.packageVersionProfile] || {}) : {};
            const hasPackage = (name) => Object.prototype.hasOwnProperty.call(installed, name);
            const count = names.filter(hasPackage).length;
            profile.querySelector('[data-profile-count]').textContent =
                state.available ? count + ' / ' + names.length : 'Not checked';
            rows.forEach((row) => {
                const name = row.dataset.rpm;
                const version = versions[name];
                const external = state.available ? state.external?.[name] : null;
                row.querySelector('[data-rpm-installed]').textContent =
                    external || version?.installed.join(', ') || (state.available && !hasPackage(name) ? 'Missing' : '—');
                row.querySelector('[data-rpm-bundled]').textContent = version?.bundled.join(', ') || '—';
                row.querySelector('[data-rpm-status]').textContent =
                    external ? 'Managed outside RPM' : (version?.status || 'Not checked');
            });
            profile.querySelectorAll('[data-package-action]').forEach((button) => {
                const target = button.dataset.target;
                const selected = target.startsWith('rpm:') ? [target.slice(4)] : names;
                const install = button.dataset.packageAction === 'install';
                const missing = selected.filter((name) => !hasPackage(name));
                button.hidden = install ? missing.length === 0 : !selected.some(hasPackage);
                button.disabled = !state.available || busy ||
                    (install && missing.some((name) => !versions[name]?.bundled.length));
            });
        });
    }

    function resetDialog() {
        previousSteps = 0;
        steps.replaceChildren();
        review.replaceChildren();
        review.hidden = log.hidden = progress.hidden = true;
        log.open = false;
        confirm.hidden = true;
        confirm.removeAttribute('data-destructive');
        summary.removeAttribute('data-status');
        close.textContent = 'Close';
        previewId = '';
    }

    function reviewList(heading, entries) {
        if (!entries.length) return;
        const title = document.createElement('h3');
        title.textContent = heading;
        const list = document.createElement('ul');
        for (const entry of entries) {
            const item = document.createElement('li');
            item.textContent = entry;
            list.append(item);
        }
        review.append(title, list);
    }

    function removalReason(message) {
        const service = message.match(/^(.+)\.service is (.+)\.$/);
        if (service) {
            return service[2] === 'active'
                ? 'The ' + service[1] + ' service is running.'
                : 'The ' + service[1] + ' service could be in use (status: ' + service[2] + ').';
        }
        const program = message.match(/^\/.*\/([^/]+) is running\.$/);
        if (program) return 'The ' + program[1] + ' program is running.';
        return message.replace(
            'No eligible installed RPMs in this selection.',
            'There are no installed packages in this selection that Offline Packages can remove.',
        );
    }

    function keptPackageReason(reason) {
        switch (reason) {
            case 'Managed outside this package runner':
            case 'Installed outside RPM. Remove it through the original installer.':
                return 'Installed outside Offline Packages. Use the original installation method to manage it.';
            case 'Protected collector dependency':
                return 'Required by this monitoring server.';
            case 'Changed since this runner installed it':
                return 'The installed version changed after Offline Packages installed it.';
            default:
                return reason;
        }
    }

    function showJob(job, allowConfirm = false) {
        summary.dataset.status = job.status;
        if (job.action === 'preview-uninstall' && job.status === 'running') {
            summary.textContent = 'Checking whether these packages can be safely removed…';
            return;
        }
        if (job.removal) {
            const plan = job.removal;
            const blocked = plan.blockers.length > 0;
            const kept = Object.entries(plan.skipped);
            summary.textContent = blocked ? 'Uninstall isn’t available for this selection.' :
                'Review the packages and features affected, then confirm to uninstall.';
            summary.dataset.status = blocked ? 'failed' : 'review';
            review.hidden = false;
            review.replaceChildren();
            reviewList('Why uninstall is unavailable', plan.blockers
                .filter((reason) => !kept.length || reason !== 'No eligible installed RPMs in this selection.')
                .map(removalReason));
            reviewList(blocked ? 'Packages checked' : 'Packages to remove and features affected',
                plan.packages.map((name) => name + ' — ' + plan.impacts[name]));
            reviewList('Packages that will stay installed',
                kept.map(([name, reason]) => name + ' — ' + keptPackageReason(reason)));
            if (allowConfirm && !blocked) {
                previewId = job.id;
                confirm.hidden = false;
                confirm.textContent = 'Confirm uninstall';
                confirm.setAttribute('data-destructive', '');
                close.textContent = 'Cancel';
            }
            if (job.id === watchedJob) watchedJob = null;
            return;
        }
        progress.hidden = false;
        progress.max = job.total;
        progress.value = job.completed;
        progress.dataset.status = job.status;
        for (const message of job.steps.slice(previousSteps)) {
            const item = document.createElement('li');
            item.textContent = message;
            steps.append(item);
        }
        previousSteps = job.steps.length;
        log.hidden = !previousSteps;
        if (job.status === 'running') {
            summary.textContent = job.action === 'uninstall' ? 'Uninstalling and checking the result…' :
                job.completed + ' / ' + job.total + ' steps complete';
        } else {
            summary.textContent = job.status === 'complete'
                ? (job.action === 'syslog-port' ? 'Syslog port updated.' :
                    job.action === 'uninstall' ? 'Uninstall complete.' : 'Package operation complete.') :
                (job.steps[job.steps.length - 1] || 'Package operation stopped.');
            if (job.id === watchedJob) watchedJob = null;
        }
    }

    async function refresh() {
        try {
            const state = await request();
            if (watchedJob && state.job?.id === watchedJob) {
                showJob(state.job, state.job.action === 'preview-uninstall' &&
                    dialog.open && selection?.dataset.target === state.job.target);
            } else if (watchedJob && Date.now() - startedAt > 40000) {
                watchedJob = null;
                summary.textContent = 'The runner did not start this request. Check its status and try again.';
            }
            updatePackages(state);
        } catch {
            const availability = page.querySelector('[data-package-availability]');
            if (availability) availability.textContent = 'Could not read the offline runner status.';
        }
        window.setTimeout(refresh, watchedJob ? 1200 : 5000);
    }

    page.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-profile-toggle]');
        if (toggle) {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            const open = page.querySelector('[data-profile-toggle][aria-expanded="true"]');
            if (open) {
                open.setAttribute('aria-expanded', 'false');
                document.getElementById(open.getAttribute('aria-controls')).hidden = true;
            }
            if (!expanded) {
                toggle.setAttribute('aria-expanded', 'true');
                document.getElementById(toggle.getAttribute('aria-controls')).hidden = false;
            }
            return;
        }
        const button = event.target.closest('[data-package-action]');
        if (button && !submitting && !watchedJob) {
            const action = button.dataset.packageAction;
            selection = button;
            title.textContent = button.getAttribute('aria-label');
            resetDialog();
            const descriptions = {
                install: 'Install the missing packages from the verified offline bundle on this collector.',
                uninstall: 'Checking whether these packages can be safely removed…',
                verify: 'Check the local RPM and native bundles, file hashes, OS release, and CPU architecture.',
            };
            summary.textContent = descriptions[action];
            confirm.hidden = action === 'uninstall';
            confirm.textContent = button.textContent.trim();
            dialog.showModal();
            if (action === 'uninstall') submit('preview-uninstall');
        } else if (event.target.closest('[data-package-last]') && latestJob) {
            resetDialog();
            selection = null;
            title.textContent = 'Last package operation';
            if (latestJob.status === 'running') watchedJob = latestJob.id;
            showJob(latestJob);
            dialog.showModal();
        } else if (event.target.closest('[data-package-close]')) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', () => {
        if (!dialog.open) selection = null;
    });

    async function submit(action) {
        if (submitting || watchedJob) return;
        submitting = true;
        confirm.hidden = true;
        if (action !== 'preview-uninstall') {
            review.hidden = true;
            close.textContent = 'Close';
            summary.textContent = 'Submitting package request…';
        }
        summary.removeAttribute('data-status');
        const body = new URLSearchParams({
            __csrf_magic: token.value,
            action,
            target: selection.dataset.target,
            preview: previewId,
        });
        try {
            const data = await request({ method: 'POST', body });
            watchedJob = data.id;
            startedAt = Date.now();
            if (action !== 'preview-uninstall') summary.textContent = 'Waiting for the local package runner…';
        } catch (error) {
            summary.textContent = error.message;
            summary.dataset.status = 'failed';
        } finally {
            submitting = false;
        }
    }
    confirm.addEventListener('click', () => submit(selection.dataset.packageAction));

    refresh();
}());
