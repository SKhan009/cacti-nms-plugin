#!/usr/bin/env python3
"""Serve the bundled MIB scalar through the Net-SNMP pass_persist protocol."""
import json
from pathlib import Path
import sys
import time

OID = (1, 3, 6, 1, 4, 1, 32473, 20261116, 1, 0)
OID_TEXT = "." + ".".join(map(str, OID))
STATE = Path("/var/lib/icct-nms-mib-simulator/cycle.json")
LOW = 15
HIGH = 90


def level(now):
    try:
        state = json.loads(STATE.read_text())
        interval = state["interval"]
        first_poll = state["first_poll"]
        if type(interval) is not int or interval < 1 or type(first_poll) is not int:
            return LOW
        slot = int(now) // interval - first_poll
        return HIGH if slot >= 0 and slot % 2 == 0 else LOW
    except (OSError, ValueError, KeyError, TypeError):
        return LOW


def respond():
    for line in sys.stdin:
        command = line.strip()
        if command == "PING":
            print("PONG", flush=True)
        elif command in ("get", "getnext"):
            try:
                requested = tuple(map(int, sys.stdin.readline().strip().lstrip(".").split(".")))
            except ValueError:
                print("NONE", flush=True)
                continue
            matches = requested == OID if command == "get" else requested < OID
            print(f"{OID_TEXT}\ninteger\n{level(time.time())}" if matches else "NONE", flush=True)
        elif not command:
            return
        else:
            print("NONE", flush=True)


if __name__ == "__main__":
    respond()
