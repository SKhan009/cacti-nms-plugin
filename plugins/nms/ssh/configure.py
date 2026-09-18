#!/usr/bin/env python3
"""Install the explicit Linux/systemd adapter. No database configuration is duplicated."""
import argparse,json,os,pathlib,pwd,grp,shutil,subprocess,sys,re

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--cacti-path',required=True);p.add_argument('--origin',required=True)
    p.add_argument('--web-group',required=True);p.add_argument('--poller-id',required=True,type=int)
    p.add_argument('--service-user',default='nms-ssh');p.add_argument('--guacd-port',type=int,default=4822)
    a=p.parse_args()
    if sys.platform!='linux' or os.geteuid()!=0: p.error('Run as root on a Linux system with systemd.')
    base=pathlib.Path(a.cacti_path).resolve();plugin=base/'plugins/nms';php=shutil.which('php')
    if not (base/'include/cli_check.php').is_file() or not (plugin/'ssh/gateway.php').is_file() or not php:p.error('Installed Cacti, NMS SSH files and PHP CLI are required.')
    if not a.origin.startswith('https://') or '/' in a.origin[8:] or not 1024<=a.guacd_port<=65535:p.error('Use an HTTPS origin without path and an unprivileged backend port.')
    if not re.fullmatch(r'[a-z_][a-z0-9_-]*',a.service_user) or not re.fullmatch(r'[a-z_][a-z0-9_-]*',a.web_group):p.error('Invalid service account or web group.')
    for utility in ['systemctl','setfacl','useradd']:
        if not shutil.which(utility):p.error('Required service utility missing: '+utility)
    subprocess.run([php,'-r',"foreach (['openssl','posix','pcntl','sockets'] as $e) {if (!extension_loaded($e)) {fwrite(STDERR, 'Missing PHP extension: '.$e);exit(2);}}"],check=True)
    group=grp.getgrnam(a.web_group)
    code='require $argv[1]."/include/cli_check.php"; if (!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=?",array((int)$argv[2]))) exit(3); echo json_encode(array("url_path"=>$config["url_path"],"log_path"=>read_config_option("path_cactilog")));'
    result=json.loads(subprocess.check_output([php,'-r',code,str(base),str(a.poller_id)],text=True))
    try:user=pwd.getpwnam(a.service_user)
    except KeyError:
        subprocess.run(['useradd','--system','--home-dir','/var/lib/nms-ssh','--shell','/sbin/nologin',a.service_user],check=True);user=pwd.getpwnam(a.service_user)
    if user.pw_uid==0 or user.pw_shell not in ['/sbin/nologin','/usr/sbin/nologin','/bin/false']:p.error('Use a dedicated non-root service account without an interactive shell.')
    for checked in ['/var/lib/nms-ssh','/var/lib/nms-ssh/secrets','/var/lib/nms-ssh/master.key',str(plugin/'ssh.config.local.json')]:
        if pathlib.Path(checked).is_symlink():p.error('Refusing symlink in service configuration: '+checked)
    for suffix in ['', '/secrets']:
        path=pathlib.Path('/var/lib/nms-ssh'+suffix);path.mkdir(exist_ok=True);os.chown(path,user.pw_uid,group.gr_gid);path.chmod(0o700)
    key=pathlib.Path('/var/lib/nms-ssh/master.key')
    if not key.exists():
        fd=os.open(key,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'wb') as stream:stream.write(os.urandom(32))
    os.chown(key,user.pw_uid,group.gr_gid);key.chmod(0o600)
    config={'control_socket':'/run/nms-ssh/control.sock','store_dir':'/var/lib/nms-ssh/secrets','master_key':str(key),'origin':a.origin,'guacd_listen':'127.0.0.1:'+str(a.guacd_port),'poller_id':a.poller_id}
    config_file=plugin/'ssh.config.local.json'
    if config_file.exists() and json.loads(config_file.read_text())!=config:p.error('Existing SSH configuration differs. Review and retain it; installer will not overwrite it.')
    config_file.write_text(json.dumps(config,indent=2)+'\n');os.chown(config_file,0,group.gr_gid);config_file.chmod(0o640)
    unit=(plugin/'ssh/nms-ssh.service.in').read_text()
    for tag,value in {'SERVICE_USER':a.service_user,'WEB_GROUP':a.web_group,'PHP_BINARY':php,'PLUGIN_PATH':str(plugin),'CACTI_PATH':str(base),'CACTI_LOG':result['log_path']}.items():
        if any(c in value for c in '\n\r"\\%'):p.error('Service paths and identities cannot contain control characters or systemd escape characters.')
        unit=unit.replace('@'+tag+'@',value)
    pathlib.Path('/etc/systemd/system/nms-ssh.service').write_text(unit)
    subprocess.run(['setfacl','-m','u:'+a.service_user+':rw',result['log_path']],check=True)
    subprocess.run(['systemctl','daemon-reload'],check=True)
    print(json.dumps({'configuration':str(config_file),'service':'nms-ssh','guacd_listen':config['guacd_listen'],'next':'Install patched guacd and configure HTTPS, then enable/start the service.'},indent=2))

if __name__=='__main__':main()
