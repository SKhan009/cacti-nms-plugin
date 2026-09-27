# Serial demo on the QA collector

This is a retained, user-requested demonstration, not a real serial instrument.
Open `devices.php?tab=configuration&id=64` in NMS.

| Item | Configured value |
| --- | --- |
| Device | Serial Demo - Modbus RTU Simulator (64) |
| Site | Topology Lab Location 01 |
| Collector | Cacti collector 1 |
| Serial preset | Serial Demo - 9600 8E1 (3) |
| Settings | 9600 baud, 8 data bits, Even parity, 1 stop bit, no flow control |
| Timeout / retries | 1000 ms / 1 read retry |
| Connection | Serial Demo - Local RTU TCP (10) |
| Endpoint | 127.0.0.1:15020 on the QA VM only |
| Unit address | 1 |
| Equipment profile | Serial Demo - Register settings (3) |
| Field | demo_value, holding register offset 0, uint16, range 0–100 |
| Collection interval | 60 seconds |

The existing collector automatically read 17 (job 6). The normal browser
Preview → Apply workflow requested 23; job 7 became Verified with
before/requested/observed values 17 / 23 / 23. A subsequent scheduled read
showed current value 23. Native numeric graph creation also succeeded; graph
samples require Cacti's next scheduled poll.

To try it: click Read current, refresh for the completed result, enter a value
from 0 through 100, select Preview change, review it and Apply change. Refresh
to see Verified and the read-back value. Reads used for a write must be refreshed
before another write.

Only the TCP/RTU exchange and configuration workflow are demonstrated. Baud,
parity, stop bits and physical handshake behavior are not tested by a TCP
simulator. The loopback address is the actual simulator endpoint, not a fallback
for real devices. The synthetic register map must not be assigned to hardware.

The simulator runs as apache in transient `nms-serial-demo.service`, bound only
to loopback. It is separate test equipment, not a new production collector.
It remains available until stopped or the QA VM reboots. A restart resets its
in-memory value to 17. On the QA VM:

```sh
sudo systemctl status nms-serial-demo.service --no-pager
sudo systemctl stop nms-serial-demo.service
# Start it again after a reboot (the transient unit is then absent):
sudo systemd-run --unit=nms-serial-demo \
  --property=User=apache --property=Restart=on-failure \
  --property=NoNewPrivileges=yes \
  /usr/bin/python3 -u \
  /var/lib/cacti/nms-serial-demo/tests/nms/serial-demo.py --port 15020
```

The fixture sources are `tests/nms/serial-demo.py` and `serial-transport.py`,
with the transport module staged under `/var/lib/cacti/nms-serial-demo`.
The demonstration is intentionally retained for the user. No Git publication.
