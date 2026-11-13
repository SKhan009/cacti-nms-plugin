#!/bin/bash
# Capture this Linux host's interface traffic with installed nfdump tools.
# Runtime-only files; no packet payloads and no historical replay after restart.
set -eu
capture_dir=/run/icct-nms-local-exporter
/usr/bin/nfpcapd -i enp0s8 -w "$capture_dir" -t 15 -e 30,10 -s 128 -B 65536 -b 4 &
capture_pid=$!
trap 'kill "$capture_pid" 2>/dev/null || true; wait "$capture_pid" 2>/dev/null || true' EXIT
trap 'exit 0' TERM INT
while kill -0 "$capture_pid" 2>/dev/null; do
    for capture_file in "$capture_dir"/nfcapd.*; do
        capture_name=${capture_file##*/}
        [[ "$capture_name" =~ ^nfcapd\.[0-9]{12,14}$ ]] || continue
        /usr/bin/nfreplay -4 -H 127.0.0.1 -p 22066 -S 127.0.0.77 -v 9 -r "$capture_file"
        rm -- "$capture_file"
    done
    sleep 3 &
    wait $! || true
done
exit 1
