<?php
/* Optional simulator setup, independent of PHP-FPM environment variables.
 * Copy this file to config.local.php beside setup.php, then enter real values.
 * Keep config.local.php administrator-owned, readable but NOT writable by PHP.
 * On POSIX, neither this file nor its parent may be group/world writable.
 * Missing data folders are created on upload below an existing writable parent.
 * Only the record-data parent/directory needs write access for PHP; existing
 * ownership and permissions are never changed. The responder needs read access.
 * SELinux installations also need a suitable policy for that data directory.
 * Do not put database credentials here. This file returns no HTTP output.
 * This example is manual mode. In manual mode, an edited record takes effect only
 * after the administrator restarts the running responder. For automatic RHEL/Linux operation use
 * snmpsim/configure.py --help and its root-only --install option. It generates
 * activation=systemd with auto_start=true and enables the responder/upload timer.
 * Point Cacti's nms_snmpsim_config to the installed JSON configuration instead
 * of using this manual example. The installed responder executable is required.
 */
return [
	"activation" => "manual",
	// Absolute local data directory, e.g. /var/lib/nms-sim/data or C:/NMS/data.
	"data_dir" => "",
	// Reachable simulator IP from the configured Cacti collector.
	"client_address" => "",
	"port" => 1161,
	// Existing enabled Cacti collector ID; no collector is assumed.
	"poller_id" => 0,
];
