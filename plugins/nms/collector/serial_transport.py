#!/usr/bin/env python3
"""Bounded Modbus RTU transport. JSON stdin/stdout; no shell or vendor register maps.

The caller must authorise the operation and validate a model-specific field map.
Addresses are zero-based protocol offsets. Only FC03/04 reads and FC06 writes
are implemented. A write is sent once, then verified with FC03 while holding
one endpoint lock. This module never marks a write verified from its echo alone.
"""
import contextlib
import fcntl
import hashlib
import ipaddress
import json
import os
import re
import socket
import stat
import struct
import sys
import time
import termios


class ProtocolError(Exception):
    pass


class DeviceException(ProtocolError):
    pass


def integer(value, low, high, name):
    if type(value) is not int or not low <= value <= high:
        raise ValueError('%s must be an integer from %d to %d' % (name, low, high))
    return value


def crc(data):
    value = 0xFFFF
    for byte in data:
        value ^= byte
        for _ in range(8):
            value = (value >> 1) ^ (0xA001 if value & 1 else 0)
    return struct.pack('<H', value)


def frame(unit, function, offset, value):
    body = struct.pack('>BBHH', unit, function, offset, value)
    return body + crc(body)


def decode(response, unit, function, count=1, request=None):
    if len(response) < 5 or crc(response[:-2]) != response[-2:]:
        raise ProtocolError('Invalid or incomplete RTU checksum')
    if response[0] != unit:
        raise ProtocolError('Reply came from a different device address')
    if response[1] == (function | 0x80):
        if len(response) != 5:
            raise ProtocolError('Invalid exception response length')
        raise DeviceException('Modbus exception %d' % response[2])
    if response[1] != function:
        raise ProtocolError('Unexpected reply function')
    if function == 6:
        if response != request:
            raise ProtocolError('Write acknowledgement does not match request')
        return None
    if len(response) != 5 + count * 2 or response[2] != count * 2:
        raise ProtocolError('Unexpected register count')
    return list(struct.unpack('>' + 'H' * count, response[3:-2]))


def validate(job):
    connection = job['connection']
    settings = connection['settings']
    if settings.get('protocol') != 'modbus_rtu':
        raise ValueError('Unsupported serial protocol')
    if settings.get('interface', 'unspecified') not in ('unspecified', 'rs232', 'rs485'):
        raise ValueError('Unsupported serial interface')
    if settings.get('interface') == 'rs485' and settings['flow_control'] != 'none':
        raise ValueError('RS-485 requires automatic direction control and flow control None')
    integer(settings['baud_rate'], 50, 4000000, 'Baud rate')
    integer(settings['data_bits'], 8, 8, 'Data bits')
    integer(settings['stop_bits'], 1, 2, 'Stop bits')
    integer(settings['timeout_ms'], 100, 10000, 'Timeout')
    integer(settings['retries'], 0, 3, 'Read retries')
    if settings['parity'] not in ('none', 'even', 'odd', 'mark', 'space') or settings['flow_control'] not in ('none', 'rtscts'):
        raise ValueError('Unsupported serial settings')
    integer(job['unit'], 1, 247, 'Device address')
    integer(job['offset'], 0, 65535, 'Register offset')
    operation = job.get('operation')
    if operation == 'read':
        integer(job.get('function', 3), 3, 4, 'Read function')
        count = integer(job.get('count', 1), 1, 125, 'Register count')
        if job['offset'] + count > 65536:
            raise ValueError('Register range exceeds protocol bounds')
    elif operation == 'write':
        integer(job['value'], 0, 65535, 'Register value')
        integer(job['expected'], 0, 65535, 'Preview value')
    else:
        raise ValueError('Unsupported operation')
    endpoint = connection['endpoint']
    if not isinstance(endpoint, str):
        raise ValueError('Invalid endpoint')
    if connection['transport'] == 'direct':
        if not re.fullmatch(r'/dev/(serial/by-id/[A-Za-z0-9_.:+-]+|tty(USB|ACM|S)[0-9]+)', endpoint) or '..' in endpoint:
            raise ValueError('Invalid direct serial path')
        resolved = os.path.realpath(endpoint)
        info = os.stat(resolved)
        if not stat.S_ISCHR(info.st_mode):
            raise ValueError('Serial endpoint must be a character device')
        # Device number, not path: two symlinks to one port share one lock.
        key = 'direct:%d' % info.st_rdev
    elif connection['transport'] == 'rtu_tcp':
        match = re.fullmatch(r'\[([^\]]+)\]:([0-9]+)', endpoint)
        if not match:
            raise ValueError('Expected [gateway-IP]:port')
        address = str(ipaddress.ip_address(match[1]))
        port = integer(int(match[2]), 1, 65535, 'Gateway port')
        key = 'rtu_tcp:[%s]:%d' % (address, port)
    else:
        raise ValueError('Unsupported transport')
    return key


@contextlib.contextmanager
def endpoint_lock(directory, identity, timeout=2):
    # The deployment creates this directory for the collector service account.
    path = os.path.join(directory, hashlib.sha256(identity.encode()).hexdigest() + '.lock')
    fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise TimeoutError('Serial connection is busy')
                time.sleep(0.02)
        yield
    finally:
        os.close(fd)


class Channel:
    def __init__(self, connection):
        self.connection = connection
        self.stream = None
        self.network = connection['transport'] == 'rtu_tcp'
        self.timeout = connection['settings']['timeout_ms'] / 1000

    def __enter__(self):
        endpoint = self.connection['endpoint']
        settings = self.connection['settings']
        if self.network:
            match = re.fullmatch(r'\[([^\]]+)\]:([0-9]+)', endpoint)
            self.stream = socket.create_connection((match[1], int(match[2])), self.timeout)
        else:
            import serial
            try:
                self.stream = serial.Serial(endpoint, baudrate=settings['baud_rate'], bytesize=8,
                    parity={'none':'N', 'even':'E', 'odd':'O', 'mark':'M', 'space':'S'}[settings['parity']],
                    stopbits=settings['stop_bits'], rtscts=settings['flow_control'] == 'rtscts',
                    timeout=self.timeout, write_timeout=self.timeout, exclusive=True)
            except termios.error as error:
                raise OSError('Serial port rejected the communication settings') from error
            self.stream.reset_input_buffer()
        return self

    def __exit__(self, *args):
        if self.stream:
            self.stream.close()

    def exchange(self, request):
        settings = self.connection['settings']
        # Inter-frame idle period; fixed 1.75ms above 19200 baud per serial guide.
        time.sleep(0.00175 if settings['baud_rate'] > 19200 else 3.5 * 11 / settings['baud_rate'])
        deadline = time.monotonic() + self.timeout
        if self.network:
            self.stream.settimeout(self.timeout)
            self.stream.sendall(request)
        else:
            sent = self.stream.write(request)
            if sent != len(request):
                raise TimeoutError('Incomplete serial write')
        received = bytearray()
        size = 3
        while len(received) < size:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError('Device reply timed out')
            if self.network:
                self.stream.settimeout(remaining)
                part = self.stream.recv(size - len(received))
            else:
                self.stream.timeout = remaining
                part = self.stream.read(size - len(received))
            if not part:
                raise TimeoutError('Device disconnected or did not reply')
            received.extend(part)
            if len(received) == 3:
                size = 5 if received[1] & 0x80 else (8 if received[1] == 6 else 5 + received[2])
                if size > 255:
                    raise ProtocolError('Oversized RTU reply')
        return bytes(received)


def read_registers(connection, unit, offset, count=1, function=3):
    request = frame(unit, function, offset, count)
    last = None
    for _ in range(connection['settings']['retries'] + 1):
        try:
            with Channel(connection) as channel:
                return decode(channel.exchange(request), unit, function, count)
        except DeviceException:
            raise
        except (OSError, TimeoutError, ProtocolError) as error:
            last = TimeoutError('Device reply timed out') if isinstance(error, socket.timeout) else error
    raise last


def execute(job, lock_directory):
    identity = validate(job)
    connection, unit, offset = job['connection'], job['unit'], job['offset']
    with endpoint_lock(lock_directory, identity):
        if job['operation'] == 'read':
            values = read_registers(connection, unit, offset, job.get('count', 1), job.get('function', 3))
            return {'status':'read', 'values':values, 'device_responded':True}
        before = read_registers(connection, unit, offset)[0]
        if before != job['expected']:
            return {'status':'failed', 'before':before, 'error':'Value changed since preview; no write sent'}
        request = frame(unit, 6, offset, job['value'])
        # No retry around writes. An acknowledgement error does not prove no change.
        acknowledged = False
        try:
            with Channel(connection) as channel:
                decode(channel.exchange(request), unit, 6, request=request)
                acknowledged = True
        except DeviceException as error:
            return {'status':'failed', 'before':before, 'error':str(error)}
        except (OSError, TimeoutError, ProtocolError):
            pass  # Read back after an ambiguous acknowledgement; never repeat the write.
        try:
            observed = read_registers(connection, unit, offset)[0]
        except (OSError, TimeoutError, ProtocolError) as error:
            return {'status':'unverified', 'before':before, 'acknowledged':acknowledged, 'error':str(error)}
        return {'status':'verified' if observed == job['value'] else 'unverified',
                'before':before, 'observed':observed, 'acknowledged':acknowledged}


def main():
    try:
        if len(sys.argv) != 2:
            raise ValueError('Collector lock directory argument required')
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536:
            raise ValueError('Request too large')
        result = execute(json.loads(raw), sys.argv[1])
    except (ValueError, KeyError, TypeError, OSError, ProtocolError, ImportError) as error:
        result = {'status':'failed', 'error':str(error), 'device_responded':False}
    print(json.dumps(result, allow_nan=False))


if __name__ == '__main__':
    main()
