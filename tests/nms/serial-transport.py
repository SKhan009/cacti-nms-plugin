"""Local protocol simulator tests. No real device addresses or vendor register map."""
import importlib.util
import pathlib
import os
import select
import socket
import sys
import tempfile
import threading
import time
import unittest
from unittest.mock import patch, Mock

path = pathlib.Path(__file__).resolve().parents[2] / 'plugins/nms/collector/serial_transport.py'
spec = importlib.util.spec_from_file_location('transport', path)
t = importlib.util.module_from_spec(spec)
spec.loader.exec_module(t)


class Simulator:
    def __init__(self, value=17, mode='normal', port=0):
        self.value, self.mode, self.writes = value, mode, 0
        self.requests = 0
        self.socket = socket.socket()
        self.socket.bind(('127.0.0.1', port))
        self.socket.listen()
        self.socket.settimeout(0.05)
        self.port = self.socket.getsockname()[1]
        self.stop = threading.Event()
        self.thread = threading.Thread(target=self.run, daemon=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *args):
        self.stop.set()
        self.thread.join(2)
        self.socket.close()

    def run(self):
        while not self.stop.is_set():
            try:
                peer, _ = self.socket.accept()
            except socket.timeout:
                continue
            with peer:
                peer.settimeout(0.5)
                request = b''
                try:
                    while len(request) < 8:
                        chunk = peer.recv(8-len(request))
                        if not chunk:
                            break
                        request += chunk
                    if len(request) != 8:
                        continue
                    self.requests += 1
                    if self.mode == 'silent':
                        time.sleep(0.15)
                        continue
                    unit, function, offset, value = t.struct.unpack('>BBHH', request[:6])
                    if function == 6:
                        self.writes += 1
                        if self.mode != 'ignore_write':
                            self.value = value
                        if self.mode == 'lost_ack':
                            continue
                        response = request
                    else:
                        body = bytes([unit, function, 2]) + t.struct.pack('>H', self.value)
                        if self.mode == 'exception':
                            body = bytes([unit, function | 0x80, 2])
                        if self.mode == 'wrong_unit':
                            body = bytes([unit+1]) + body[1:]
                        response = body + t.crc(body)
                        if self.mode == 'bad_crc' or (self.mode == 'first_bad_crc' and self.requests == 1):
                            response = response[:-1] + bytes([response[-1] ^ 1])
                    # Every response is fragmented to exercise stream framing.
                    for byte in response:
                        peer.sendall(bytes([byte]))
                except (OSError, TimeoutError):
                    pass


def job(sim, operation='read'):
    return {'connection':{'transport':'rtu_tcp', 'endpoint':'[127.0.0.1]:%d' % sim.port,
        'settings':{'protocol':'modbus_rtu','baud_rate':9600,'data_bits':8,'stop_bits':1,
        'parity':'even','flow_control':'none','timeout_ms':100,'retries':1}},
        'operation':operation, 'unit':1, 'offset':0, 'value':23, 'expected':17}


class TransportTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)

    def execute(self, request):
        return t.execute(request, self.directory.name)

    def test_serial_option_mapping(self):
        for parity, code in [('none', 'N'), ('even', 'E'), ('odd', 'O'), ('mark', 'M'), ('space', 'S')]:
            for flow in ('none', 'rtscts'):
                with self.subTest(parity=parity, flow=flow), Simulator() as sim:
                    request = job(sim)
                    request['connection']['settings'].update(parity=parity, flow_control=flow, baud_rate=50)
                    t.validate(request)
                    connection = dict(request['connection'], transport='direct', endpoint='/dev/ttyUSB97')
                    serial = Mock()
                    with patch.dict(sys.modules, {'serial': serial}):
                        with t.Channel(connection):
                            pass
                    options = serial.Serial.call_args.kwargs
                    self.assertEqual(options['parity'], code)
                    self.assertEqual(options['baudrate'], 50)
                    self.assertEqual(options['bytesize'], 8)
                    self.assertEqual(options['rtscts'], flow == 'rtscts')
                    serial.Serial.return_value.close.assert_called_once()

    def test_unsupported_flow_is_rejected(self):
        with Simulator() as sim:
            for flow in ('xonxoff', 'dsrdtr'):
                request = job(sim)
                request['connection']['settings']['flow_control'] = flow
                with self.assertRaises(ValueError):
                    self.execute(request)
            self.assertEqual(sim.requests, 0)

    def test_known_crc(self):
        self.assertEqual(t.frame(1,3,0,1).hex(), '010300000001840a')

    def test_fragmented_read(self):
        with Simulator() as sim:
            self.assertEqual(self.execute(job(sim))['values'], [17])

    def test_write_readback(self):
        with Simulator() as sim:
            result = self.execute(job(sim,'write'))
            self.assertEqual(result['status'], 'verified')
            self.assertEqual(result['observed'], 23)
            self.assertEqual(sim.writes, 1)

    def test_lost_ack_no_write_retry(self):
        with Simulator(mode='lost_ack') as sim:
            result = self.execute(job(sim,'write'))
            self.assertEqual(result['status'], 'verified')
            self.assertFalse(result['acknowledged'])
            self.assertEqual(sim.writes, 1)

    def test_echo_is_not_verification(self):
        with Simulator(mode='ignore_write') as sim:
            self.assertEqual(self.execute(job(sim,'write'))['status'], 'unverified')
            self.assertEqual(sim.writes, 1)

    def test_stale_preview_no_write(self):
        with Simulator(value=18) as sim:
            self.assertEqual(self.execute(job(sim,'write'))['status'], 'failed')
            self.assertEqual(sim.writes, 0)

    def test_invalid_responses(self):
        for mode in ('bad_crc','wrong_unit','exception'):
            with self.subTest(mode=mode), Simulator(mode=mode) as sim:
                with self.assertRaises(t.ProtocolError):
                    self.execute(job(sim))

    def test_bounded_timeout(self):
        with Simulator(mode='silent') as sim:
            start = time.monotonic()
            with self.assertRaises(TimeoutError):
                self.execute(job(sim))
            self.assertLess(time.monotonic()-start, 1)

    def test_transient_read_retries_and_recovers(self):
        with Simulator(mode='first_bad_crc') as sim:
            self.assertEqual(self.execute(job(sim))['values'], [17])
            self.assertEqual(sim.requests, 2)
            self.assertEqual(sim.writes, 0)

    def test_read_retry_budget(self):
        for retries in (0, 1, 3):
            with self.subTest(retries=retries), Simulator(mode='bad_crc') as sim:
                request = job(sim)
                request['connection']['settings']['retries'] = retries
                with self.assertRaises(t.ProtocolError):
                    self.execute(request)
                self.assertEqual(sim.requests, retries + 1)
                self.assertEqual(sim.writes, 0)

    def test_device_exception_is_not_retried(self):
        with Simulator(mode='exception') as sim:
            request = job(sim)
            request['connection']['settings']['retries'] = 3
            with self.assertRaises(t.DeviceException):
                self.execute(request)
            self.assertEqual(sim.requests, 1)

    def test_endpoint_lock(self):
        with t.endpoint_lock(self.directory.name, 'test'):
            with self.assertRaises(TimeoutError):
                with t.endpoint_lock(self.directory.name, 'test', timeout=0.03):
                    self.fail('Concurrent access granted')
        with t.endpoint_lock(self.directory.name, 'test', timeout=0.03):
            pass

    def test_broadcast_and_register_bounds(self):
        with Simulator() as sim:
            for change in ({'unit':0}, {'unit':248}, {'unit':True}, {'offset':65535,'count':2}, {'count':126}):
                with self.subTest(change=change), self.assertRaises(ValueError):
                    self.execute(dict(job(sim), **change))

    def test_mismatched_function_and_count(self):
        for body in (bytes([1,4,2,0,1]), bytes([1,3,4,0,1,0,2])):
            with self.assertRaises(t.ProtocolError):
                t.decode(body+t.crc(body),1,3)

    def test_direct_pseudoterminal(self):
        try:
            import serial
        except ImportError:
            self.skipTest('pySerial not installed on this test host')
        master, slave = os.openpty()
        self.addCleanup(os.close, master)
        self.addCleanup(os.close, slave)
        errors = []
        def instrument():
            try:
                request = b''
                deadline = time.monotonic() + 2
                while len(request) < 8:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0 or not select.select([master], [], [], remaining)[0]:
                        raise TimeoutError('PTY simulator received no request')
                    request += os.read(master, 8-len(request))
                self.assertEqual(request, t.frame(1,3,0,1))
                body = bytes([1,3,2,0,42])
                os.write(master, body+t.crc(body))
            except Exception as error:
                errors.append(error)
        with Simulator() as sim:
            connection = job(sim)['connection']
            connection.update(transport='direct', endpoint=os.ttyname(slave))
            connection['settings']['parity']='none'  # PTYs do not emulate physical parity hardware.
            thread = threading.Thread(target=instrument)
            thread.start()
            self.assertEqual(t.read_registers(connection, 1, 0), [42])
            thread.join(3)
            self.assertFalse(thread.is_alive())
            self.assertFalse(errors, str(errors))

    def test_direct_aliases_same_lock(self):
        fake = type('Stat', (), {'st_mode':t.stat.S_IFCHR, 'st_rdev':321})()
        with Simulator() as sim, patch.object(t.os,'stat',return_value=fake):
            request=job(sim)
            request['connection']['transport']='direct'
            request['connection']['endpoint']='/dev/ttyUSB0'
            first=t.validate(request)
            request['connection']['endpoint']='/dev/serial/by-id/fixture'
            self.assertEqual(first,t.validate(request))


if __name__ == '__main__':
    if sys.argv[1:] == ['--serve']:
        with Simulator() as simulator:
            print(simulator.port, flush=True)
            sys.stdin.read()
    else:
        unittest.main(verbosity=2)
