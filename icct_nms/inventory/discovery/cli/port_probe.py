#!/usr/bin/env python3
"""Local TCP connect scanner. No external services or third-party packages."""
import asyncio
import errno
import ipaddress
import json
import sys


async def scan(config):
    address = str(ipaddress.ip_address(config["address"]))
    timeout = max(0.1, min(10, int(config["timeout_ms"]) / 1000))
    ports = sorted(set(int(p) for p in config["ports"]))
    if not ports or len(ports) > 65535 or min(ports) < 1 or max(ports) > 65535:
        raise ValueError("Ports must be within 1–65535")
    limits = asyncio.Semaphore(64)
    result = {"open": [], "closed": 0, "timed_out": 0, "errors": 0}

    async def probe(port):
        async with limits:
            try:
                _, writer = await asyncio.wait_for(asyncio.open_connection(address, port), timeout)
                result["open"].append(port)
                writer.close()
                await writer.wait_closed()
            except asyncio.TimeoutError:
                result["timed_out"] += 1
            except OSError as error:
                result["closed" if error.errno == errno.ECONNREFUSED else "errors"] += 1

    await asyncio.gather(*(probe(p) for p in ports))
    result["open"].sort()
    return result


if __name__ == "__main__":
    try:
        print(json.dumps(asyncio.run(scan(json.load(sys.stdin)))))
    except Exception as error:
        print(json.dumps({"error": str(error)}))
        sys.exit(1)
