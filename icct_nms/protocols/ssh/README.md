# SSH terminal installation

The PHP SSH client, browser terminal assets and strict-auth Guacamole source patch are bundled. Runtime uses the verified local build, a dedicated broker account, a separate Guacamole account and the administrator's explicit HTTPS origin. Password and key authentication never switch methods; host-key approval remains required.

Build the pinned native bundle on matching RHEL release and CPU architecture, using approved offline build prerequisites:

```sh
python3 installation/cli/native.py build --component guacamole-strict --bundle /media/guacamole-native
```

Include this directory in the offline release with `installation/cli/package.py --native-bundle /media/guacamole-native`, together with the matching OS package bundle. On the target, the release bootstrap verifies all files and configures SSH in one command:

```sh
sudo python3 install.py --cacti-root /var/www/html/cacti --ssh-origin https://nms.example.org
```

Use the exact browser origin, including a nonstandard HTTPS port. Provision the site's certificate and HTTPS virtual host first; setup does not create a certificate or bypass certificate verification. No Internet access is required by this command when the release carries its verified package and native bundles.

For a provisioned Cacti installation with the native bundle already installed:

```sh
sudo python3 protocols/ssh/configure.py --cacti-path /var/www/html/cacti --origin https://nms.example.org --web-user apache --web-group apache --poller-id 1 --native-bundle /media/guacamole-native
```

The adapter verifies installed native hashes against the pinned bundle, provisions `icct-nms-ssh.service` and `icct-nms-guacd.service`, retains SELinux enforcement, installs the scoped broker-socket policy, starts both services and checks access from the PHP account. Guacamole listens only on loopback port 4823 by preset default; `--guacd-port` explicitly selects another unprivileged port. The installer bootstrap uses `--ssh-guacd-port` for the same setting.

Existing encrypted credentials are retained. For an explicitly selected existing private store, use `--service-user` and `--state-directory` with its current noninteractive account and `/var/lib/<name>` directory. The bootstrap equivalents are `--ssh-service-user` and `--ssh-state-directory`. Setup rejects another account's directory, changed private keys, symlinks and a different existing configuration. It does not copy credentials, create devices, assign presets or enable monitoring.

Installation Checks verifies the strict native file hashes and the broker socket; it also reports both services independently. Device credentials and pinned host keys are validated separately when opening a terminal. Optional packages alone do not establish SSH readiness.

## Plugin terminal

Open **Open SSH Terminal (HTTPS)** from the existing device view or its SSH settings. **Test saved credential** performs actual host-key verification and authentication through the broker. **Connect** starts the bundled Guacamole display; **Disconnect** revokes its browser-bound authorization. No password or private key is returned to the browser. Terminal operations retain the device's monitoring switch and never create devices or replace host-key approvals.

Each console requires the native Inventory/protocol and device permissions plus management access. HTTPS, native CSRF tokens, one-use connection tickets, browser-session binding, live permission/revision checks and bounded authorization leases protect every operation. Browser certificate warnings must be reviewed by the user; the plugin does not bypass them or substitute an HTTP terminal.
