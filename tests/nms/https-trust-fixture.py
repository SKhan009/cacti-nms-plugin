"""Run real TLS acceptance with private per-process trust; never modifies system trust."""
import pathlib, selectors, subprocess, sys, tempfile
base = pathlib.Path(__file__).resolve().parent
engine = pathlib.Path(sys.argv[1]).resolve()
php = sys.argv[2] if len(sys.argv) > 2 else 'php'
def run(args):
    subprocess.run(args, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=30)
with tempfile.TemporaryDirectory(prefix='nms-https-trust-') as folder:
    p = pathlib.Path(folder)
    run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=NMS disposable test CA','-keyout',str(p/'ca.key'),'-out',str(p/'ca.pem'),'-addext','basicConstraints=critical,CA:TRUE'])
    run(['openssl','req','-new','-newkey','rsa:2048','-nodes','-subj','/CN=NMS loopback fixture','-keyout',str(p/'server.key'),'-out',str(p/'server.csr')])
    (p/'extensions').write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1\n')
    run(['openssl','x509','-req','-in',str(p/'server.csr'),'-CA',str(p/'ca.pem'),'-CAkey',str(p/'ca.key'),'-CAcreateserial','-days','1','-extfile',str(p/'extensions'),'-out',str(p/'server.pem')])
    server = subprocess.Popen([sys.executable,str(base/'service-fixture.py'),str(p/'server.pem'),str(p/'server.key')],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True)
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(server.stdout,selectors.EVENT_READ)
            if not selector.select(10): raise RuntimeError('TLS fixture startup timed out')
        ports=server.stdout.readline().strip().split()
        if len(ports)!=3: raise RuntimeError('TLS fixture failed to start')
        test=[str(base/'workspace-https-trust.php'),str(engine),ports[1]]
        subprocess.run([php,*test,'untrusted'],check=True,timeout=20)
        subprocess.run([php,'-d','curl.cainfo='+str(p/'ca.pem'),*test,'trusted'],check=True,timeout=20)
    finally:
        server.terminate()
        try: server.wait(timeout=5)
        except subprocess.TimeoutExpired: server.kill();server.wait(timeout=5)
print('Private CA, keys and fixture removed; system trust was unchanged.')
