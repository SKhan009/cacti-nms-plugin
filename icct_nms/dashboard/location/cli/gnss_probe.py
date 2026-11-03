#!/usr/bin/env python3
"""Read GNSS NMEA or gpsd locally using only the Python standard library."""
import datetime
import ipaddress
import json
import math
import os
import select
import socket
import sys
import termios
import time


def coordinate(value, hemisphere):
    if hemisphere not in ("N", "S", "E", "W"):
        raise ValueError("Invalid coordinate hemisphere")
    raw = float(value)
    degrees = int(raw // 100)
    minutes = raw - degrees * 100
    if raw < 0 or not 0 <= minutes < 60:
        raise ValueError("Invalid coordinate minutes")
    result = degrees + minutes / 60
    return -result if hemisphere in ("S", "W") else result


def timestamp(day, clock):
    if len(day) != 6 or len(clock) < 6:
        raise ValueError("GNSS timestamp missing")
    year = int(day[4:6])
    year += 2000 if year < 80 else 1900
    seconds = float(clock[4:])
    return datetime.datetime(
        year,
        int(day[2:4]),
        int(day[:2]),
        int(clock[:2]),
        int(clock[2:4]),
        int(seconds),
        int((seconds % 1) * 1000000),
        tzinfo=datetime.timezone.utc,
    ).timestamp()


def validate(lat, lon, observed, now, max_age):
    if (
        not all(math.isfinite(v) for v in (lat, lon, observed))
        or not -90 <= lat <= 90
        or not -180 <= lon <= 180
    ):
        raise ValueError("GNSS coordinates out of range")
    if observed > now + 30 or now - observed > max_age:
        raise ValueError("GNSS fix is stale or has a future timestamp")
    return {"latitude": lat, "longitude": lon, "source_time": observed}


def parse_line(line, now, max_age):
    line = line.strip()
    if line.startswith("{"):
        value = json.loads(line)
        if value.get("class") != "TPV":
            return None
        if value.get("mode", 0) < 2:
            raise ValueError("GNSS receiver has no position fix")
        observed = datetime.datetime.fromisoformat(value["time"].replace("Z", "+00:00")).timestamp()
        return validate(float(value["lat"]), float(value["lon"]), observed, now, max_age)
    if not line.startswith("$") or "*" not in line:
        return None
    body, checksum = line[1:].split("*", 1)
    actual = 0
    for character in body:
        actual ^= ord(character)
    if len(checksum) != 2 or actual != int(checksum, 16):
        raise ValueError("GNSS sentence checksum failed")
    fields = body.split(",")
    kind = fields[0][-3:]
    if kind == "RMC":
        if len(fields) < 10 or fields[2] != "A":
            raise ValueError("GNSS receiver reports an invalid fix")
        return validate(
            coordinate(fields[3], fields[4]),
            coordinate(fields[5], fields[6]),
            timestamp(fields[9], fields[1]),
            now,
            max_age,
        )
    if kind == "GGA":
        if len(fields) < 7 or int(fields[6] or 0) == 0:
            raise ValueError("GNSS receiver reports no fix")
        # GGA has UTC time but no date; resolve to the nearest day around the receiver time.
        today = datetime.datetime.fromtimestamp(now, datetime.timezone.utc)
        candidates = []
        for offset in (-1, 0, 1):
            date = today + datetime.timedelta(days=offset)
            candidates.append(timestamp(date.strftime("%d%m%y"), fields[1]))
        observed = min(candidates, key=lambda value: abs(now - value))
        return validate(
            coordinate(fields[2], fields[3]),
            coordinate(fields[4], fields[5]),
            observed,
            now,
            max_age,
        )
    return None


def read_source(config):
    timeout = int(config.get("timeout", 3))
    source = config["transport"]
    deadline = time.monotonic() + timeout
    last_error = "No valid GNSS fix received"
    handle = None
    buffer = b""
    try:
        if source in ("tcp", "gpsd"):
            handle = socket.create_connection((config["address"], int(config["port"])), timeout)
            if source == "gpsd":
                handle.sendall(b'?WATCH={"enable":true,"json":true};\n')
        elif source == "udp":
            handle = socket.socket(
                socket.AF_INET6 if ":" in config.get("bind", "") else socket.AF_INET,
                socket.SOCK_DGRAM,
            )
            handle.bind((config.get("bind", "0.0.0.0"), int(config["port"])))
        elif source in ("serial", "file"):
            handle = os.open(config["path"], os.O_RDONLY | os.O_NONBLOCK | os.O_NOCTTY)
            if source == "serial":
                settings = termios.tcgetattr(handle)
                settings[0] = settings[1] = settings[3] = 0
                settings[2] = termios.CS8 | termios.CLOCAL | termios.CREAD
                baud = getattr(termios, "B" + str(config["baud"]))
                settings[4] = settings[5] = baud
                settings[6][termios.VMIN] = settings[6][termios.VTIME] = 0
                termios.tcsetattr(handle, termios.TCSANOW, settings)
        else:
            raise ValueError("Unsupported GNSS transport")
        while time.monotonic() < deadline:
            if not select.select([handle], [], [], max(0, deadline - time.monotonic()))[0]:
                break
            if source == "udp":
                chunk, peer = handle.recvfrom(8192)
                if config.get("address") and ipaddress.ip_address(peer[0]) != ipaddress.ip_address(
                    config["address"]
                ):
                    continue
            else:
                chunk = os.read(handle, 8192) if isinstance(handle, int) else handle.recv(8192)
            if not chunk:
                break
            buffer += chunk
            if len(buffer) > 65536:
                raise ValueError("GNSS input exceeds supported sentence length")
            lines = buffer.split(b"\n")
            buffer = lines.pop()
            if source in ("file", "udp") and buffer:
                lines.append(buffer)
                buffer = b""
            for raw in lines:
                try:
                    fix = parse_line(raw.decode("ascii"), time.time(), int(config["max_age"]))
                    if fix:
                        return fix
                except (ValueError, KeyError, TypeError, UnicodeError) as error:
                    last_error = str(error)
        raise ValueError(last_error)
    finally:
        if handle is not None:
            os.close(handle) if isinstance(handle, int) else handle.close()


if __name__ == "__main__":
    try:
        print(json.dumps(read_source(json.load(sys.stdin))))
    except Exception as error:
        print(json.dumps({"error": str(error)}))
        sys.exit(1)
