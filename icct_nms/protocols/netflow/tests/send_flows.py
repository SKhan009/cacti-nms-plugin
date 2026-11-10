#!/usr/bin/env python3
"""Send deterministic wire-format fixtures to an isolated UDP collector, never device configuration."""
import argparse
import socket
import struct
import time

p = argparse.ArgumentParser()
p.add_argument("--port", type=int, required=True)
p.add_argument("--address", default="127.0.0.1")
p.add_argument("--source", default="127.0.0.77")
p.add_argument("--destination", default="198.51.100.20")
p.add_argument("--version", type=int, choices=[5, 7, 9, 10], action="append")
p.add_argument("--application-id", action="store_true")
a = p.parse_args()
now = int(time.time())
uptime = 100000
src = socket.inet_aton("10.21.1.10")
dst = socket.inet_aton(a.destination)
record = struct.pack(
    "!4s4s4sHHIIIIHHBBBBHHBBH",
    src,
    dst,
    b"\0" * 4,
    1,
    2,
    10,
    12340,
    99000,
    100000,
    41000,
    443,
    0,
    0x12,
    6,
    0,
    0,
    0,
    24,
    24,
    0,
)
assert len(record) == 48
v5 = struct.pack("!HHIIIIBBH", 5, 1, uptime, now, 0, 1, 0, 0, 0) + record
v7 = struct.pack("!HHIIIII", 7, 1, uptime, now, 0, 1, 0) + record + struct.pack("!I", 0)
fields = [
    (8, 4),
    (12, 4),
    (7, 2),
    (11, 2),
    (4, 1),
    (6, 1),
    (2, 4),
    (1, 4),
    (22, 4),
    (21, 4),
    (10, 2),
    (14, 2),
]
values = src + dst + struct.pack("!HHBBIIIIHH", 41000, 443, 6, 0x12, 10, 12340, 99000, 100000, 1, 2)


def flowset(fid, payload):
    payload += b"\0" * ((-len(payload)) % 4)
    return struct.pack("!HH", fid, len(payload) + 4) + payload


if a.application_id:
    fields.append((95, 4))
    values += bytes([3, 0, 0, 80])
template = struct.pack("!HH", 256, len(fields)) + b"".join(struct.pack("!HH", *f) for f in fields)
v9 = struct.pack("!HHIIII", 9, 2, uptime, now, 1, 101) + flowset(0, template) + flowset(256, values)
f10 = [f for f in fields if f[0] not in (22, 21)] + [(152, 8), (153, 8)]
v10values = (
    src
    + dst
    + struct.pack("!HHBBIIHHQQ", 41000, 443, 6, 0x12, 10, 12340, 1, 2, (now - 1) * 1000, now * 1000)
)
if a.application_id:
    v10values = v10values[:-16] + bytes([3, 0, 0, 80]) + v10values[-16:]
t10 = struct.pack("!HH", 256, len(f10)) + b"".join(struct.pack("!HH", *f) for f in f10)
payload = flowset(2, t10) + flowset(256, v10values)
v10 = struct.pack("!HHIII", 10, 16 + len(payload), now, 0, 102) + payload
s = socket.socket(socket.AF_INET6 if ":" in a.source else socket.AF_INET, socket.SOCK_DGRAM)
s.bind((a.source, 0))
for version, data in [(5, v5), (7, v7), (9, v9), (10, v10)]:
    if a.version and version not in a.version:
        continue
    s.sendto(data, (a.address, a.port))
    print("sent", version, len(data))
    time.sleep(0.1)
