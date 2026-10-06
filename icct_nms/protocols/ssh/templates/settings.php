        <details class="protocol-item" id="protocol-ssh" data-saved="<?= $ssh
            ? "1"
            : "0" ?>" <?= $ssh || in_array("ssh", $protocolDraft, true) ? "" : "hidden" ?> <?= $failedProtocol === "ssh" ? "open" : "" ?>>
            <summary>
                <span class="accordion-chevron" aria-hidden="true"></span>
                <span>SSH <?php $protocolPresetHelp('ssh', 'SSH'); ?></span>
                <button type="button" class="delete-protocol" aria-label="Remove protocol"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" /></svg>
                </button>
            </summary>
            <div class="protocol-content">
                <form method="post" enctype="multipart/form-data">
                    <?php icct_nms_token(); ?>
                    <input type="hidden" name="action" value="<?= $presetMode ? 'save_protocol_defaults' : 'ssh' ?>" /><?php if ($presetMode): ?><input type="hidden" name="preset_protocol" value="<?= 'ssh' ?>" /><?php endif; ?>
                    <div class="protocol-grid cols-5">
                        <?php
                        icct_nms_input(
                            "Port Number",
                            "port",
                            $ssh["port"] ?? "",
                            "number",
                            'required min="1" max="65535"',
                        );
                        icct_nms_input(
                            "Timeout (Sec)",
                            "connect_timeout",
                            $ssh["connect_timeout"] ?? "",
                            "number",
                            'required min="1" max="120"',
                        );
                        icct_nms_select(
                            "Authentication Method",
                            "auth_method",
                            [
                                "password" => "Username & Password",
                                "key" => "Private Key",
                            ],
                            $ssh["auth_method"] ?? "password",
                        );
                        icct_nms_input(
                            "Username",
                            "username",
                            $ssh["username"] ?? "",
                            "text",
                            "required",
                        );
                        icct_nms_input(
                            "Password",
                            "secret",
                            "",
                            "password",
                            'autocomplete="new-password" placeholder="Blank retains saved credential"',
                        );
                        ?>
                    </div>
                    <div class="protocol-grid cols-3 ssh-common-settings">
                        <?php
                        icct_nms_input(
                            "Command Timeout (Sec)",
                            "command_timeout",
                            $ssh["command_timeout"] ?? "",
                            "number",
                            'required min="1" max="300"',
                        );
                        icct_nms_input(
                            "Retries",
                            "retries",
                            $ssh["retries"] ?? "",
                            "number",
                            'required min="0" max="2"',
                        );
                        icct_nms_input(
                            "Keepalive (Sec)",
                            "keepalive",
                            $ssh["keepalive"] ?? "",
                            "number",
                            'required min="0" max="300"',
                        );
                        ?>
                    </div>
                    <div class="ssh-private-key">
                        <h3>Upload files</h3>
                        <p>
                            Maximum 64 KB. Supported
                            formats: .ppk, PEM, OpenSSH.
                        </p>
                        <label class="upload-zone">
                            Upload +
                            <input
                                name="key_file"
                                type="file"
                                accept=".ppk,.pem,.key"
                                aria-label="Upload private key"
                            />
                        </label>
                        <span class="selected-file" aria-live="polite"></span>
                        <?php icct_nms_input(
                            "Key Passphrase",
                            "passphrase",
                            "",
                            "password",
                            'autocomplete="new-password"',
                        ); ?>
                    </div>
                    <label class="check-row">
                        <input name="monitoring" type="checkbox" <?= !empty(
                            $ssh["monitoring"]
                        )
                            ? "checked"
                            : "" ?> />
                        Enable SSH monitoring
                    </label>
                    <div class="protocol-actions"><button class="button primary">Save</button></div>
                </form>
            </div>
        </details>
