# VM protocol verification — 24 September 2026

All 14 enabled checks were submitted sequentially through `nms_diag_run` for device 2 (Cacti RHEL 9 Local, 127.0.0.1), with the existing account and profile checks. The existing apache listener and worker executed each job. All returned exit code 0 and complete status; summaries were checked against saved output. Older failed jobs remain unchanged in history.

| Job | Diagnostic | Execution |
| --- | --- | --- |
| 21 | ping | Passed, exit 0 |
| 22 | traceroute | Passed, exit 0 |
| 23 | traceroute_icmp | Passed, exit 0 |
| 24 | traceroute_tcp | Passed, exit 0 |
| 25 | mtr_icmp | Passed, exit 0 |
| 26 | mtr_tcp | Passed, exit 0 |
| 27 | nping_icmp | Passed, exit 0 |
| 28 | nping_tcp | Passed, exit 0 |
| 29 | hping3_icmp | Passed, exit 0 |
| 30 | hping3_tcp | Passed, exit 0 |
| 31 | arp | Passed, exit 0 |
| 32 | iperf3 | Passed, exit 0 |
| 33 | netperf | Passed, exit 0 |
| 34 | pathchar | Passed, exit 0 |

## Repairs and validation

- Added `cap_net_raw=ep` to the VM's traceroute and hping3 executables, matching the raw-socket requirement. Nping already has this capability from its earlier correction. The collector remains apache; SELinux remains enforcing. No sudo execution path was added to the plugin.
- Removed unconditional MTR/Nping/hping3 summary warnings. Summaries now parse packet loss, counts and timing, require matching ICMP echo/TCP SYN-ACK replies or a zero-loss MTR destination hop, and retain warnings for TCP resets, loss, missing/unrecognized output or an unconfirmed endpoint. Nonzero exits remain failures.
- All five diagnostic regression suites passed on the target VM. Additional summary fixtures cover replies, TCP resets, loss, destination mismatch, truncation and failed execution. Changed PHP passed target syntax checks. The live hping3 TCP result shows four SYN-ACK replies, zero loss and Test completed.

Ping, both MTR modes, both Nping modes and both hping3 modes received 4/4 replies. All three Traceroute modes reported one responding hop. ARP returned two cached neighbours without unresolved entries. iPerf3 and Netperf completed bounded three-second local server self-tests. Pathchar completed its local self-test with 33/33 replies. These results verify the collector locally, not remote-device reachability or physical link capacity. TCP raw probes do not verify application-layer service health.

Results are jobs 21–34 in the VM's Protocol checks history. The VM clock reports 21 September, while the host date is 24 September. Pre-change capabilities and summary source are recorded in `/root/nms-all-protocols-before`. OS package upgrades can remove file capabilities; recheck permissions after replacing those binaries.
