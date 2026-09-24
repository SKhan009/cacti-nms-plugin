# RHEL systemd diagnostic listener

The VM runs its Cacti poller as a systemd oneshot service. The default control-group cleanup terminates background children when polling finishes. On that deployment, run the existing NMS listener in its own service. Do not weaken the poller's KillMode setting.

Review `nms-diagnostic-runner.service`: the supplied paths and `apache` account match this QA VM. Adapt them to the installation and existing collector account on another host. No sudo invocation is added to diagnostic commands and no raw-socket permissions are changed by this unit.

```sh
sudo install -m 644 nms-diagnostic-runner.service /etc/systemd/system/nms-diagnostic-runner.service
sudo systemctl daemon-reload
sudo systemctl enable --now nms-diagnostic-runner.service
sudo systemctl status nms-diagnostic-runner.service --no-pager
```

Enable NMS through Cacti Plugin Management as well. The listener's database lock prevents duplicate instances; plugin-disabled or incompatible-schema states do not run diagnostics. Restart=always retries exited listeners, including after plugin re-enable or listener updates. The service runs as the collector account, not root.

Verify Protocol checks reports tools available while idle, run a bounded local Ping test, let a poller cycle finish, then verify the service remains active. A busy worker may make the current five-second readiness indicator temporarily unavailable until its test completes. Do not enqueue work repeatedly while a test runs.

After deploying listener code, restart the service. To remove this optional deployment integration:

```sh
sudo systemctl disable --now nms-diagnostic-runner.service
sudo rm /etc/systemd/system/nms-diagnostic-runner.service
sudo systemctl daemon-reload
```

Removing the service does not remove Cacti devices or saved results. On a oneshot-poller deployment, diagnostics will need a suitable persistent listener again.
