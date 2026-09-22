# 0.10.0 — Discovery on one page

Removed SNMP Access and the Discovery step tabs. Collection settings and per-device protocol selectors now share one native form with Save and Save & Discover Now. Recent results and optional saved profiles appear below it. Profile loading and editing stay on this page. Legacy Discovery tab URLs open the consolidated page.

Configuration saves validate the complete visible assignment set and commit policy/protocol changes atomically. Queueing happens after commit and reports a queue failure separately. Native Cacti device and SNMP fields are untouched. Button actions use a hidden action field compatible with Cacti submission handling.

The plugin footer bounds Cacti’s non-logout refresh sentinel to the browser timer limit, preventing immediate refresh loops without altering genuine session logout timers.

Validation: PHP syntax checks; isolated database tests for invalid protocol/timing, stale device lists, rollback, native host preservation and read-only permissions; browser profile load from a changed interval; successful combined save/discovery on the existing two-device LLDP simulator pair. No physical-switch validation is claimed.
