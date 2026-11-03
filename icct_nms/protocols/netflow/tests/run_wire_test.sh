#!/bin/bash
# Isolated real UDP packets -> nfcapd files -> native temporary database -> reporting assertions.
set -euo pipefail
netflow_dir=$(cd "$(dirname "$0")/.." && pwd)
test_root=$(mktemp -d /tmp/icct-netflow-test.XXXXXX)
test_port=${NF_TEST_PORT:-22065}
collector_pid=''
cleanup() {
    if [[ -n "$collector_pid" ]]; then
        kill "$collector_pid" 2> /dev/null || true
        wait "$collector_pid" 2> /dev/null || true
    fi
    rm -rf -- "$test_root"
}
trap cleanup EXIT
mkdir "$test_root/capture"
timeout 8 /usr/bin/nfcapd -4 -b 127.0.0.1 -p "$test_port" -t 5 -n "qa,127.0.0.77,$test_root/capture" > "$test_root/collector.log" 2>&1 &
collector_pid=$!
sleep 1
kill -0 "$collector_pid" || {
    cat "$test_root/collector.log"
    exit 1
}
python3 "$netflow_dir/tests/send_flows.py" --port "$test_port"
wait "$collector_pid" || test $? -eq 124
collector_pid=''
php "$netflow_dir/tests/integration.php" --captures="$test_root/capture"
