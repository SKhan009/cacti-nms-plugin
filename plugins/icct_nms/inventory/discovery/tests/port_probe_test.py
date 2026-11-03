#!/usr/bin/env python3
import asyncio
import importlib.util
from pathlib import Path
import socket

path = Path(__file__).resolve().parents[1] / "cli" / "port_probe.py"
spec = importlib.util.spec_from_file_location("probe", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


async def main():
    async def accept(reader, writer):
        writer.close()
        await writer.wait_closed()

    server = await asyncio.start_server(accept, "127.0.0.1", 0)
    port = server.sockets[0].getsockname()[1]
    closed = socket.socket()
    closed.bind(("127.0.0.1", 0))
    closed_port = closed.getsockname()[1]
    result = await module.scan(
        {"address": "127.0.0.1", "ports": [port, closed_port], "timeout_ms": 100}
    )
    assert result["open"] == [port] and result["closed"] == 1, result
    assert result["errors"] == result["timed_out"] == 0, result
    closed.close()
    server.close()
    await server.wait_closed()
    for bad in [[0], [65536]]:
        try:
            await module.scan({"address": "127.0.0.1", "ports": bad, "timeout_ms": 100})
        except ValueError:
            pass
        else:
            raise AssertionError("Invalid port accepted")
    print("PASS: real loopback open/closed TCP ports and input bounds; no Internet used.")


asyncio.run(main())
