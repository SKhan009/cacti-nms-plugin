# 0.12.0 — Discovery tabs

Separated Discovery into Collection Settings, Device Protocols, Run & Results and Saved Profiles using the shared native Cacti submenu template. Each tab displays only its controls. SNMP access remains in Cacti core.

Tab switches retain unsaved configuration fields. Save applies collection settings and device protocols together and keeps the selected tab. Save & Discover Now opens Results. The Results tab also offers Discover Now using saved settings. Profile edit links open within Saved Profiles. Existing native pagination is preserved.

Verified all four tabs in the VM browser, direct Results reload, profile editing layout, an unsaved interval retained across tabs, save from Device Protocols, Results page 2, and no JavaScript errors. Discovery job 55 completed with two successful protocol collections and zero failures. Existing interval 300, stale threshold 900, and device protocols LLDP / LLDP / none were retained. PHP and JavaScript syntax checks passed.

Plugin backup: /var/backups/topology-0120/plugin-before.tar.gz. No schema changes in this release.
