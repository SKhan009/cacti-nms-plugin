# SSH terminal installation

PHP client and browser assets are bundled. The native terminal needs a strict-auth patched Guacamole server, HTTPS and a dedicated account; an ordinary guacd package alone is insufficient.

1. Obtain Guacamole Server 1.6.0 from Apache's official distribution. Verify the signature and SHA-256 in `guacamole/manifest.json`.
2. Build/package for the target OS and CPU with `guacamole/strict-auth.patch` applied, following upstream build instructions and preserving licences/NOTICE. No cross-architecture executable is supplied.
3. Configure HTTPS and identify the native poller ID. Run the bundled adapter with your actual Cacti path, HTTPS origin, web group and poller ID:

```sh
sudo python3 protocols/ssh/configure.py --cacti-path /path/to/cacti --origin https://nms.example.org --web-group apache --poller-id 1
```

Use the real web group (often `www-data` on Debian/Ubuntu). The adapter creates its private account/key and socket configuration, refuses to overwrite a different existing configuration, and leaves device credentials and host-key approvals unchanged.

4. Review the generated service and `guacamole/nms-guacd.service` for the approved binary location and matching local port. Start them after settings match; verify Installation Checks and a real SSH device session.

The pinned native source and strict-auth patch are now bundled. Build on the matching Linux OS/CPU as a regular user:

```sh
python3 installation/cli/native.py build --component guacamole-strict --bundle /tmp/guacamole-native
sudo python3 installation/cli/native.py install --bundle /tmp/guacamole-native --web-group apache
```

The verified native binary is installed at `/opt/icct-nms/native/guacamole-1.6.0/sbin/guacd`. It can also be included in a complete offline release through `package.py --native-bundle /tmp/guacamole-native`; the release installer installs it automatically. Build prerequisites are listed in `installation/README.md`. Native dependency installation does not replace a running custom service. Point the dedicated Guacamole service to the verified binary when configuring the actual HTTPS origin, then test a real SSH session.
