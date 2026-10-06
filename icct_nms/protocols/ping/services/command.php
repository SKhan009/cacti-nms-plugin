<?php
/** Build bounded ICMP Ping arguments for a single device target. */
function icct_backend_ping_command($row, $target)
{
            $args = [
                "-n",
                "-c",
                (string) $row["ping_count"],
                "-W",
                "2",
                "-w",
                (string) (3 * $row["ping_count"] + 2),
                $target,
            ];
            $timeout = 3 * $row["ping_count"] + 5;
    return [$args, $timeout];
}
