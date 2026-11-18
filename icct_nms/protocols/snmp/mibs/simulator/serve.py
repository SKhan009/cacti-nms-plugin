#!/usr/bin/env python3
"""Loopback SNMPv2c agent for selected MIB instances and generated values."""
from bisect import bisect_right
from hashlib import sha256
import json
import math
from pathlib import Path
import socket
import time

STATE = Path("/var/lib/icct-nms-mib-simulator/cycle.json")
COMMUNITY = b"icct-mib-sim"
UPTIME = (1, 3, 6, 1, 2, 1, 1, 3, 0)
MAX_RESPONSE = 8192
VALUE_TAGS = {
    "integer": 0x02,
    "string": 0x04,
    "oid": 0x06,
    "ip": 0x40,
    "counter32": 0x41,
    "gauge": 0x42,
    "ticks": 0x43,
    "counter64": 0x46,
}


def tlv(tag, payload):
    size = len(payload)
    if size < 128:
        length = bytes([size])
    else:
        width = (size.bit_length() + 7) // 8
        length = bytes([128 + width]) + size.to_bytes(width, "big")
    return bytes([tag]) + length + payload


def number(value, tag=0x02):
    width = max(1, ((-1 - value if value < 0 else value).bit_length() + 8) // 8)
    return tlv(tag, value.to_bytes(width, "big", signed=True))


def oid_parts(text):
    return tuple(map(int, text.split(".")))


def encode_oid(parts):
    payload = bytearray()
    for arc in (40 * parts[0] + parts[1], *parts[2:]):
        tail = [arc & 127]
        while arc >= 128:
            arc >>= 7
            tail.append(128 | (arc & 127))
        payload.extend(reversed(tail))
    return tlv(0x06, payload)


def decode_oid(payload):
    if not payload or len(payload) > 640:
        raise ValueError("Invalid OID")
    parts = []
    arc = 0
    continued = False
    for octet in payload:
        arc = (arc << 7) | (octet & 127)
        if arc > 2**32 + 79:
            raise ValueError("OID subidentifier too large")
        continued = bool(octet & 128)
        if not continued:
            parts.append(arc)
            arc = 0
    if continued or len(parts) > 127:
        raise ValueError("Invalid OID")
    first = min(parts[0] // 40, 2)
    return (first, parts[0] - 40 * first, *parts[1:])


class BerReader:
    def __init__(self, data):
        self.data = data
        self.offset = 0

    def read(self, expected=None):
        start = self.offset
        if start + 2 > len(self.data):
            raise ValueError("Truncated BER header")
        tag, length = self.data[start:start + 2]
        start += 2
        if length & 128:
            width = length & 127
            if width not in (1, 2) or start + width > len(self.data):
                raise ValueError("Invalid BER length")
            length = int.from_bytes(self.data[start:start + width], "big")
            start += width
        end = start + length
        if end > len(self.data) or (expected is not None and tag != expected):
            raise ValueError("Invalid BER value")
        self.offset = end
        return tag, self.data[start:end]

    def sequence(self):
        return BerReader(self.read(0x30)[1])

    def integer(self):
        payload = self.read(0x02)[1]
        if not 1 <= len(payload) <= 4:
            raise ValueError("Invalid Integer32")
        return int.from_bytes(payload, "big", signed=True)

    def done(self):
        return self.offset == len(self.data)

    def finish(self):
        if not self.done():
            raise ValueError("Unexpected trailing BER data")


def reading(obj, state, now):
    type_name = obj["type"]
    started = state["started_at"]
    interval = state["interval"]
    slot = max(0, int(now) // interval - started // interval)
    seed = int.from_bytes(
        sha256(f'{state["seed"]}/{obj["oid"]}'.encode()).digest()[:8], "big"
    )
    if type_name in ("counter32", "counter64"):
        width = 32 if type_name == "counter32" else 64
        rate = 5 + seed % 96
        return number((1000 + seed % 9000 + slot * interval * rate) % (1 << width),
                      VALUE_TAGS[type_name])
    if type_name == "ticks":
        return number(int(max(0, now - started) * 100) % (1 << 32), VALUE_TAGS[type_name])
    if "bounds" in obj:
        low, high = obj["bounds"]
        # Derive each sample from its poll slot, so repeated reads and restarts agree.
        period = 15 + seed % 8
        phase = (seed % 360) * math.pi / 180
        position = 0.5 + 0.27 * math.sin(phase + slot * 2 * math.pi / period)
        position += 0.04 * math.sin(phase + slot * 1.1)
        value = min(high, max(low, round(low + (high - low) * position)))
    else:
        value = obj["value"]
    if type_name == "string":
        return tlv(0x04, value.encode())
    if type_name == "oid":
        return encode_oid(oid_parts(value))
    if type_name == "ip":
        return tlv(0x40, socket.inet_aton(value))
    return number(value, VALUE_TAGS[type_name])


def respond(packet, state, now):
    reader = BerReader(packet)
    message = reader.sequence()
    reader.finish()
    if message.integer() != 1 or message.read(0x04)[1] != COMMUNITY:
        return None
    operation, payload = message.read()
    message.finish()
    if operation not in (0xA0, 0xA1, 0xA3, 0xA5):
        return None
    pdu = BerReader(payload)
    request_id = pdu.integer()
    non_repeaters = pdu.integer()
    repetitions = pdu.integer()
    query = pdu.sequence()
    pdu.finish()
    requested = []
    original = []
    while not query.done():
        _, binding = query.read(0x30)
        varbind = BerReader(binding)
        requested.append(decode_oid(varbind.read(0x06)[1]))
        varbind.read()
        varbind.finish()
        original.append(tlv(0x30, binding))
        if len(requested) > 128:
            raise ValueError("Too many bindings")
    objects = {oid_parts(obj["oid"]): obj for obj in state["objects"]}
    objects.setdefault(UPTIME, {"oid": "1.3.6.1.2.1.1.3.0", "type": "ticks"})
    ordered = sorted(objects)

    def binding(oid, value):
        return tlv(0x30, encode_oid(oid) + value)

    def next_value(oid):
        index = bisect_right(ordered, oid)
        if index == len(ordered):
            return oid, binding(oid, tlv(0x82, b""))
        next_oid = ordered[index]
        return next_oid, binding(next_oid, reading(objects[next_oid], state, now))

    status = index = 0
    if operation == 0xA3:
        status, index = (17, 1) if requested else (0, 0)  # notWritable
        values = original
    elif operation == 0xA0:
        values = [binding(oid, reading(objects[oid], state, now) if oid in objects
                          else tlv(0x80, b"")) for oid in requested]
    else:
        count = len(requested) if operation == 0xA1 else min(len(requested), max(0, non_repeaters))
        values = [next_value(oid)[1] for oid in requested[:count]]
        cursors = requested[count:]
        for _ in range(min(128, max(0, repetitions)) if cursors else 0):
            row = [next_value(oid) for oid in cursors]
            values.extend(data for _, data in row)
            advanced = [oid for oid, _ in row]
            if len(values) >= 128 or advanced == cursors:
                break
            cursors = advanced
        values = values[:128]

    def reply(code, error_index, bindings):
        response = number(request_id) + number(code) + number(error_index)
        response += tlv(0x30, b"".join(bindings))
        return tlv(0x30, number(1) + tlv(0x04, COMMUNITY) + tlv(0xA2, response))

    packet = reply(status, index, values)
    return packet if len(packet) <= MAX_RESPONSE else reply(1, 0, [])


def load_state():
    try:
        state = json.loads(STATE.read_text())
        if (state["version"] == 1 and 0 < state["interval"] <= 86400
                and state["objects"] and len(state["objects"]) <= 128):
            return state
    except (OSError, ValueError, TypeError, KeyError):
        pass
    return None


def serve():
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as agent:
        agent.bind(("127.0.0.1", 1161))
        while True:
            packet, address = agent.recvfrom(MAX_RESPONSE + 1)
            if len(packet) > MAX_RESPONSE:
                continue
            state = load_state()
            if state is None:
                continue
            try:
                response = respond(packet, state, time.time())
                if response and STATE.is_file():
                    agent.sendto(response, address)
            except (ValueError, TypeError, KeyError, OverflowError, IndexError, OSError):
                continue


if __name__ == "__main__":
    serve()
