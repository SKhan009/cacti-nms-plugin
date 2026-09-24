# Immediate on-demand diagnostics

For systemd oneshot pollers, use the [persistent listener service](deployment/README.md). Phase 6 found that normal service cleanup kills a background listener; the QA VM now runs it as apache in its own unit. The following describes the original poller-dispatch implementation.

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

## TCP and ICMP checks (1.10.98)

Previously only Ping used ICMP, and Traceroute used its default UDP mode. Profiles now offer Traceroute ICMP (`-I`) and TCP (`-T -p 443`), MTR ICMP/TCP reports, Nping ICMP/TCP SYN probes and hping3 ICMP/TCP SYN probes. Existing Ping, UDP Traceroute, ARP and bandwidth settings are preserved. TCP probe checks currently use fixed destination port **443**; this does not change iPerf3 or Netperf ports.

Upgrade NMS using Cacti Plugin Management to widen the saved tool list. In Diagnostics → Profiles, enable the desired protocol-specific tools, save, and run them for a device assigned to that profile. New tools are opt-in. The existing poller restarts the changed listener; readiness refreshes installed executables at most once per minute.

Install matching offline Linux packages providing `traceroute`, `mtr` (including its packet helper), `nping`, or `hping3` on each execution collector. Availability means the binary is executable, not that raw-socket permissions or network replies are guaranteed. Tools run as the existing collector account, without adding sudo or changing OS capabilities. Permission errors remain in results. TCP/ICMP Traceroute never falls back to UDP tracepath when traceroute is missing. hping3 supports IPv4 only; IPv6 literals are rejected and IPv6-only DNS names require another tool. Nping, MTR and explicit Traceroute modes pass `-6` for IPv6 literals.

The profile probe count (1–10) controls Nping/hping3 packets and MTR report cycles; its hop limit (1–30) controls Traceroute and MTR. Probes are spaced one second apart for Nping/hping3; MTR uses a one-second interval. Every process has a hard timeout (MTR: 60 seconds), with partial output retained. Raw SYN replies do not prove an HTTPS connection succeeds: a reset is still a reply. MTR/probe results ask users to review the technical output rather than infer endpoint success from exit code zero.

Argument references: [MTR manual](https://github.com/traviscross/mtr/blob/master/man/mtr.8.in), [Nping reference](https://nmap.org/book/nping-man.html), and [hping manual](https://github.com/antirez/hping/blob/master/docs/hping3.8).

Local validation covers protocol arguments, limits, executable resolution, queue admission, result summaries, and PHP syntax. These additions still need execution testing on a Linux Cacti collector with the corresponding packages and permissions; local fixtures do not certify deployment or raw-socket access.

### VM deployment verification — 24 September 2026

Deployed 1.10.98 to Cacti-RHEL9, upgraded the profile tool column, and enabled NMS through Cacti's plugin lifecycle. NMS was disabled (status 4), which prevented its diagnostic runner from starting. The existing apache poller now launches the listener successfully. Enabled the eight new choices in the existing “All protocol checks” profile through the web form.

Installed repository packages `nmap` (provides Nping) and `hping3`; MTR and Traceroute were already installed. All owned plugin PHP files passed target-VM syntax checks, and all five diagnostic fixture suites passed there. Under the apache collector account, ICMP Traceroute and both MTR modes returned loopback replies. TCP Traceroute, Nping and hping3 currently fail because the account lacks the required raw-packet privileges. No additional file capabilities, sudo rules or SELinux changes were applied. “Installed on collector” reports executable presence, not permission to send probes.

Backup on VM: `/root/nms-before-1.10.98-20260921-083216` (plugin plus database). The VM clock reads 21 September, so its backup and test timestamps differ from the host date.

### Nping execution correction

The VM's Nping 0.7.92 rejects `-n` as an ambiguous option. Removed that argument and added `--privileged`, allowing Nping to use administrator-provisioned Linux capabilities without requiring UID 0. The flag itself grants no privileges. On this VM, `/usr/bin/nping` now has only `cap_net_raw=ep`; the apache collector stays unprivileged and SELinux stays enforcing. File capabilities may need reprovisioning after an OS package replaces the binary. Prior capability metadata and plugin source are saved under `/root/nms-nping-permissions-before`.

Both corrected Nping ICMP and TCP SYN commands returned replies on loopback under apache (2/2 replies, no loss). This supersedes the earlier Nping permission limitation; TCP Traceroute and hping3 have not received additional capabilities.

### Full protocol verification

The subsequent [14-check VM verification](PROTOCOL_VERIFICATION_1.10.98.md) supersedes the earlier remaining TCP Traceroute/hping3 limitations and blanket review summaries. All fourteen collector checks completed successfully on loopback.

### Separate diagnostic and bandwidth sections

The run page now has independent Device diagnostics and Bandwidth tests sections, each with its own device selector, permitted-test list, compact help cards and run button. Bandwidth groups iPerf3, Netperf and the Pathchar capacity estimate. Results and saved history remain shared. Each selector filters only its own group's tools against the assigned profile; a group with no permitted tools disables its run button. VM verification submitted ARP and a local iPerf3 self-test through the two forms.
