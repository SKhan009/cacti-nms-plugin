"""Disposable local HTTP/TLS acceptance fixture. No external addresses or credentials."""
import http.server, ssl, threading, time, sys, socket, struct
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self):
        if self.path == '/slow': time.sleep(2)
        status=302 if self.path=='/redirect' else 403 if self.path=='/denied' else 200
        self.send_response(status)
        if status==302:self.send_header('Location','http://unreachable.invalid/')
        self.end_headers()
        try:self.wfile.write(b'x'*70000 if self.path=='/large' else b'service ready')
        except (BrokenPipeError,ConnectionResetError):pass
plain=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
https=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(sys.argv[1],sys.argv[2]);https.socket=ctx.wrap_socket(https.socket,server_side=True)
threading.Thread(target=plain.serve_forever,daemon=True).start()
threading.Thread(target=https.serve_forever,daemon=True).start()
dns=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);dns.bind(('127.0.0.1',0))
def answer_dns():
    while True:
        packet,peer=dns.recvfrom(2048)
        if len(packet)<17:continue
        end=12;labels=[]
        while end<len(packet) and packet[end]:
            length=packet[end];labels.append(packet[end+1:end+1+length]);end+=length+1
        end+=1
        if end+4>len(packet):continue
        kind=struct.unpack('!H',packet[end:end+2])[0]
        missing=b'.'.join(labels)==b'missing.test'
        data=socket.inet_pton(socket.AF_INET6,'2001:db8::5') if kind==28 else socket.inet_aton('192.0.2.5')
        header=packet[:2]+struct.pack('!HHHHH',0x8183 if missing else 0x8180,1,0 if missing else 1,0,0)
        response=header+packet[12:end+4]
        if not missing:response+=b'\xc0\x0c'+struct.pack('!HHIH',kind,1,60,len(data))+data
        dns.sendto(response,peer)
threading.Thread(target=answer_dns,daemon=True).start()
print(plain.server_port,https.server_port,dns.getsockname()[1],flush=True)
try:
    while True:time.sleep(1)
except KeyboardInterrupt:
    plain.shutdown();https.shutdown()
