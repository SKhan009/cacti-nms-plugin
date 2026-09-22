# Simulator list and toolbar — 0.6.3

Retry Simulator Activation is now a refresh icon in the upper-right corner of the native Simulator header, rendered through Cacti's header icon API. It has a tooltip, accessible button name, keyboard support and duplicate-click protection. It submits the existing CSRF-protected POST activation request. The lower retry button was removed.

The Imports page adds native Search, Site, Rows, Go and Clear controls with pagination. The Site/Node column uses two lines, record/metric counts share one column, and row actions sit together with consistent spacing. Test SNMP and Open Cacti Device retain their native button styling; provisioning remains available for imports without a created device.

Validation: PHP syntax passed. Browser screenshot confirmed header icon placement and aligned row buttons. Searching Switch A returned one import and excluded Switch B. The retry control submitted through the protected form and Cacti confirmed activation was queued. Existing import/device/data-source records were preserved.

VM backup: `/var/backups/topology-simulator-ui-20260907/topology-before.tar.gz`.
