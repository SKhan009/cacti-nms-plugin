# Immediate on-demand diagnostics (1.10.63)

The existing Cacti poller hook starts a plugin-owned CLI listener on each collector. The listener stays idle between tests, checks for accepted work once per second, and runs each test in a fresh bounded CLI worker. It requires no new cron entry, service, sudo rule, PHP-FPM change or Cacti core change.

Only one test is accepted per collector at a time. Busy collectors, missing executables and offline runners return an error without submitting another test. Accepted tests must start within 15 seconds; expired work is not replayed. Tool timeouts and current account/device/profile permissions remain enforced. The database stores the request and result so refresh cannot repeat the test.

The listener owns a database lock to prevent duplicate instances, publishes current executable availability, stops when NMS is disabled or its own file changes, and exits if it loses the lock. The existing poller restarts a missing listener. After a reboot or upgrade, it may take until the first normal poller cycle to become ready. Once ready, requests do not wait for the poller schedule. A slow diagnostic still takes its configured duration.

The run page automatically checks progress once per second and reloads the completed result. It restores the Run selected test button, aligns the two form columns, styles status/results consistently, and collapses recent tests beneath the result. Refresh remains a read-only GET.

## Validation

- Admission fixtures passed on PHP 8.2 and 8.5: offline/busy/missing tool refusals do not insert a request; ready runner accepts one; locks release on errors.
- Changed PHP files passed syntax checks on the VM's PHP 8.0.30.
- Deployed to Cacti-RHEL9 as 1.10.63. Backup: /root/nms-before-1.10.63-20260921-001153.
- Live browser request #3: started 1 second after submission, finished 1 second after submission, exit 0, 0% packet loss. Result appeared without manual refresh.
- Starting a second listener exited without creating a duplicate runner. Refresh retained the same result.
- Desktop form/result alignment visually inspected on the live page.
