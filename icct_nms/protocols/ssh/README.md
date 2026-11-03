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

The patched native build and HTTPS setup are not automatically installed by the common package installer yet. The Install action displays this guide without claiming installation has completed.
