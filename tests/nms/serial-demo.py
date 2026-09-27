#!/usr/bin/env python3
"""Explicit local QA demo; not a manufacturer register map or physical serial test."""
import argparse
import importlib.util
import pathlib
import threading

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, required=True)
args = parser.parse_args()
if not 1024 <= args.port <= 65535:
    parser.error('Choose an unprivileged local TCP port')
path = pathlib.Path(__file__).with_name('serial-transport.py')
spec = importlib.util.spec_from_file_location('nms_serial_demo_fixture', path)
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)
with fixture.Simulator(value=17, port=args.port) as simulator:
    print('SIMULATOR ONLY: RTU/TCP on 127.0.0.1:%d; demo register starts at 17' % simulator.port, flush=True)
    threading.Event().wait()
