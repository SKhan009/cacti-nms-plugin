"""Read-only RTU-over-TCP exchange against a bounded loopback fixture."""
import importlib.util
from pathlib import Path
import socket
import tempfile
import threading

spec = importlib.util.spec_from_file_location('transport', Path(__file__).resolve().parents[2] / 'protocols/serial/collector/serial_transport.py')
transport = importlib.util.module_from_spec(spec)
spec.loader.exec_module(transport)
settings = dict(protocol='modbus_rtu', baud_rate=9600, data_bits=8, parity='even', stop_bits=1, flow_control='none', timeout_ms=1000, retries=0)
for function in (3, 4):
    with socket.socket() as server, tempfile.TemporaryDirectory() as locks:
        server.bind(('127.0.0.1', 0))
        server.listen(1)
        server.settimeout(3)
        errors=[]
        def respond():
            try:
                with server.accept()[0] as client:
                    client.settimeout(3)
                    request=b''
                    while len(request)<8:
                        chunk=client.recv(8-len(request))
                        if not chunk: raise AssertionError('Incomplete request')
                        request+=chunk
                    assert request == transport.frame(1, function, 0, 1)
                    body=bytes([1,function,2,0,42])
                    client.sendall(body+transport.crc(body))
            except Exception as error: errors.append(error)
        worker=threading.Thread(target=respond)
        worker.start()
        result=transport.execute(dict(connection=dict(transport='rtu_tcp',endpoint=f'[127.0.0.1]:{server.getsockname()[1]}',settings=settings),unit=1,offset=0,count=1,function=function,operation='read'),locks)
        worker.join(4)
        assert not errors and not worker.is_alive(), errors
        assert result['status']=='read' and result['values']==[42] and result['device_responded']
print('Live FC03/FC04 read-only RTU TCP framing, checksum, bus address and response parsing passed.')
