<div
    class="installation-packages"
    data-package-page
    data-endpoint="<?= $installationUrl ?>?view=package-job">
    <form
        hidden
        data-package-token>
        <?php icct_nms_token(); ?>
    </form>
    <div class="installation-package-toolbar">
        <p
            data-package-availability
            data-state="<?= $status['available'] ? 'ready' : 'unavailable' ?>"
            role="status"><?= icct_nms_h($status['message']) ?></p>
        <div class="installation-package-actions">
            <button
                type="button"
                class="button"
                data-package-last
                <?= empty($status['job']) ? 'hidden' : '' ?>>Last operation</button>
            <button
                type="button"
                class="button"
                data-package-action="verify"
                data-target="media"
                aria-label="Verify package media"
                <?= !$status['available'] ? 'disabled' : '' ?>>Verify media</button>
        </div>
    </div>
    <div class="installation-package-help" data-package-help <?= empty($status['next_step']) ? 'hidden' : '' ?>>
        <p data-package-next-step><?= icct_nms_h($status['next_step'] ?? '') ?></p>
        <pre data-package-command <?= empty($status['command']) ? 'hidden' : '' ?>><code><?= icct_nms_h($status['command'] ?? '') ?></code></pre>
    </div>
    <p data-package-platform><?php if (!empty($status['target'])): ?>
        <?= icct_nms_h('Collector platform · ' . strtoupper($status['target']['id']) . ' ' .
            $status['target']['version'] . ' · ' . $status['target']['arch']) ?>
    <?php else: ?>Collector platform will appear when the package runner reports its status.<?php endif; ?></p>
    <p>Install adds the missing dependencies for the selected feature from matching offline RPMs. LLDP and CDP use SNMP access to devices. Configure protocol credentials and receivers after installing their dependencies.</p>
    <div class="installation-table-wrap">
        <table class="action-table site-table installation-table">
            <thead>
                <tr><th>Package profile</th><th>Installed</th><th>Actions</th></tr>
            </thead>
            <?php $previousCategory = null; ?>
            <?php foreach ($packages as $id => $profile): ?>
                <?php if ($previousCategory !== $profile['category']): ?>
                    <tbody>
                        <tr class="installation-package-category">
                            <th scope="colgroup" colspan="3"><?= icct_nms_h($profile['category']) ?></th>
                        </tr>
                    </tbody>
                    <?php $previousCategory = $profile['category']; ?>
                <?php endif; ?>
                <?php
                $names = $profile['packages']['rhel'];
                $installed = array_intersect($names, array_keys(($status['installed'] ?? []) + ($status['external'] ?? [])));
                $versionProfile = $profile['version_profile'] ?? $id;
                $versions = $status['versions'][$versionProfile] ?? [];
                $missing = array_diff($names, $installed);
                $removable = array_intersect($names, $status['removable'] ?? []);
                $canInstall = $missing && !array_filter($missing, static fn ($name) => empty($versions[$name]['bundled']));
                ?>
                <tbody data-package-profile="<?= icct_nms_h($id) ?>" data-package-version-profile="<?= icct_nms_h($versionProfile) ?>">
                    <tr class="installation-profile-row">
                        <td>
                            <button
                                type="button"
                                class="installation-profile-toggle"
                                data-profile-toggle
                                aria-expanded="false"
                                aria-controls="package-details-<?= icct_nms_h($id) ?>">
                                <strong><?= icct_nms_h($profile['label']) ?></strong>
                                <?php if (isset($profile['detail'])): ?>
                                    <span class="installation-version-note"><?= icct_nms_h($profile['detail']) ?></span>
                                <?php endif; ?>
                            </button>
                        </td>
                        <td data-profile-count><?= !empty($status['inventory_available']) ? count($installed) . ' / ' . count($names) : 'Not checked' ?></td>
                        <td>
                            <div class="installation-package-actions">
                                <button
                                    type="button"
                                    class="button primary"
                                    data-package-action="install"
                                    data-target="<?= icct_nms_h($id) ?>"
                                    aria-label="Install <?= icct_nms_h($profile['label']) ?>"
                                    <?= !$status['available'] || !$canInstall ? 'disabled' : '' ?>><?= !$missing && !empty($status['inventory_available']) ? 'Installed' : 'Install' ?></button>
                                <button
                                    type="button"
                                    class="button"
                                    data-package-action="uninstall"
                                    data-target="<?= icct_nms_h($id) ?>"
                                    aria-label="Uninstall <?= icct_nms_h($profile['label']) ?>"
                                    <?= !$status['available'] || !$removable ? 'disabled' : '' ?>>Uninstall</button>
                            </div>
                            <small data-package-action-note></small>
                        </td>
                    </tr>
                    <tr
                        class="installation-profile-details"
                        id="package-details-<?= icct_nms_h($id) ?>"
                        hidden>
                        <td colspan="3">
                            <table
                                class="installation-rpms"
                                aria-label="<?= icct_nms_h($profile['label']) ?> packages">
                                <thead><tr>
                                    <th scope="col">Package</th>
                                    <th scope="col">Installed version</th>
                                    <th scope="col">Offline bundle version</th>
                                    <th scope="col">Version check</th>
                                    <th scope="col">Actions</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($names as $name): ?>
                                        <?php
                                        $present = in_array($name, $installed, true);
                                        $version = $status['available'] ? ($versions[$name] ?? []) : [];
                                        $external = !empty($status['inventory_available']) ? ($status['external'][$name] ?? '') : '';
                                        ?>
                                        <tr data-rpm="<?= icct_nms_h($name) ?>">
                                            <th scope="row"><?= icct_nms_h($name) ?></th>
                                            <td data-rpm-installed><?= icct_nms_h($external ?: (implode(', ', $version['installed'] ?? []) ?: (!empty($status['inventory_available']) ? ($present ? 'Installed' : 'Missing') : '—'))) ?></td>
                                            <td data-rpm-bundled><?= icct_nms_h(implode(', ', $version['bundled'] ?? []) ?: '—') ?></td>
                                            <td data-rpm-status><?= icct_nms_h($external ? 'Managed outside RPM' : ($version['status'] ?? 'Not checked')) ?></td>
                                            <td>
                                                <div class="installation-package-actions">
                                                    <button
                                                        type="button"
                                                        class="button primary"
                                                        data-package-action="install"
                                                        data-target="rpm:<?= icct_nms_h($name) ?>"
                                                        aria-label="Install <?= icct_nms_h($name) ?>"
                                                        <?= !$status['available'] || $present || empty($version['bundled']) ? 'disabled' : '' ?>><?= $present && !empty($status['inventory_available']) ? 'Installed' : 'Install' ?></button>
                                                    <button
                                                        type="button"
                                                        class="button"
                                                        data-package-action="uninstall"
                                                        data-target="rpm:<?= icct_nms_h($name) ?>"
                                                        aria-label="Uninstall <?= icct_nms_h($name) ?>"
                                                        <?= !$status['available'] || !in_array($name, $status['removable'] ?? [], true) ? 'disabled' : '' ?>>Uninstall</button>
                                                </div>
                                                <small data-package-action-note></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </tbody>
            <?php endforeach; ?>
        </table>
    </div>
    <?php require __DIR__ . '/runner_dialog.php'; ?>
</div>
<script src="installation/js/installation.js?v=<?= substr(hash_file('sha256', dirname(__DIR__) . '/js/installation.js'), 0, 12) ?>" defer></script>
