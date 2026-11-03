#!/usr/bin/env python3
"""Real local transports, checksums and GNSS fix freshness. No Internet required."""
import datetime
import importlib.util
import json
import os
from pathlib import Path
import pty
import socket
import tempfile
import threading
import time

path = Path(__file__).resolve().parents[1] / "cli" / "gnss_probe.py"
spec = importlib.util.spec_from_file_location("gnss", path)
gnss = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gnss)


def sentence(status="A", now=None):
    date = datetime.datetime.fromtimestamp(now or time.time(), datetime.timezone.utc)
    body = (
        "GPRMC,"
        + date.strftime("%H%M%S")
        + ","
        + status
        + ",1258.2960,N,07735.6760,E,0,0,"
        + date.strftime("%d%m%y")
        + ",,,A"
    )
    checksum = 0
    for c in body:
        checksum ^= ord(c)
    return "$" + body + "*" + format(checksum, "02X") + "\r\n"


def check(fix):
    assert abs(fix["latitude"] - 12.9716) < 0.000001, fix
    assert abs(fix["longitude"] - 77.5946) < 0.000001, fix


check(gnss.parse_line(sentence(), time.time(), 120))
for value in (sentence("V"), sentence(now=time.time() - 1000), sentence()[:-4] + "00\r\n"):
    try:
        gnss.parse_line(value, time.time(), 120)
    except ValueError:
        pass
    else:
        raise AssertionError("Invalid/stale/checksum failure accepted")

for transport in ("tcp", "gpsd"):
    listener = socket.socket()
    listener.bind(("127.0.0.1", 0))
    listener.listen(1)

    def serve(listener=listener, transport=transport):
        connection, _ = listener.accept()
        if transport == "gpsd":
            assert b"?WATCH=" in connection.recv(512)
            value = {
                "class": "TPV",
                "mode": 3,
                "lat": 12.9716,
                "lon": 77.5946,
                "time": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            }
            connection.sendall((json.dumps(value) + "\n").encode())
        else:
            connection.sendall(sentence().encode())
        connection.close()
        listener.close()

    port = listener.getsockname()[1]
    thread = threading.Thread(target=serve)
    thread.start()
    check(
        gnss.read_source(
            {
                "transport": transport,
                "address": "127.0.0.1",
                "port": port,
                "max_age": 120,
                "timeout": 2,
            }
        )
    )
    thread.join()

reserve = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
reserve.bind(("127.0.0.1", 0))
port = reserve.getsockname()[1]
reserve.close()


def send_udp():
    time.sleep(0.1)
    sender = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    for _ in range(5):
        sender.sendto(sentence().encode(), ("127.0.0.1", port))
        time.sleep(0.03)
    sender.close()


thread = threading.Thread(target=send_udp)
thread.start()
check(
    gnss.read_source(
        {
            "transport": "udp",
            "bind": "127.0.0.1",
            "address": "127.0.0.1",
            "port": port,
            "max_age": 120,
            "timeout": 2,
        }
    )
)
thread.join()

with tempfile.NamedTemporaryFile() as source:
    source.write(sentence().encode())
    source.flush()
    check(
        gnss.read_source({"transport": "file", "path": source.name, "max_age": 120, "timeout": 2})
    )

master, slave = pty.openpty()


def send_serial():
    time.sleep(0.1)
    for _ in range(5):
        os.write(master, sentence().encode())
        time.sleep(0.03)


thread = threading.Thread(target=send_serial)
thread.start()
check(
    gnss.read_source(
        {
            "transport": "serial",
            "path": os.ttyname(slave),
            "baud": 9600,
            "max_age": 120,
            "timeout": 2,
        }
    )
)
thread.join()
os.close(master)
os.close(slave)
print(
    "PASS: NMEA TCP/UDP/serial/file, gpsd JSON, checksums, invalid fixes and timestamps using real loopback transports."
)
