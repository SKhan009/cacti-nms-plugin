#!/usr/bin/env python3
"""Configure the native macOS launchd adapter; requires an existing dedicated service account."""
import argparse,grp,json,os,pathlib,plistlib,pwd,subprocess,sys

def main():
 p=argparse.ArgumentParser(description=__doc__)
 for arg in ['cacti-path','php','origin','service-user','web-group']:p.add_argument('--'+arg,required=True)
 p.add_argument('--poller-id',required=True,type=int);p.add_argument('--guacd-port',type=int,default=4822)
 a=p.parse_args()
 if sys.platform!='darwin' or os.geteuid()!=0:p.error('Run with sudo on macOS.')
 base=pathlib.Path(a.cacti_path).resolve();plugin=base/'plugins/nms';php=pathlib.Path(a.php).resolve()
 user=pwd.getpwnam(a.service_user);group=grp.getgrnam(a.web_group)
 if user.pw_uid==0 or user.pw_shell not in ['/usr/bin/false','/bin/false','/usr/sbin/nologin']:p.error('Use an existing dedicated unprivileged account with a non-login shell.')
 if not php.is_file() or not (base/'include/cli_check.php').is_file():p.error('Cacti bootstrap and an explicit PHP executable are required.')
 if not a.origin.startswith('https://') or '/' in a.origin[8:] or not 1024<=a.guacd_port<=65535:p.error('Invalid HTTPS origin or gateway port.')
 subprocess.run([str(php),'-r',"foreach(['openssl','posix','pcntl','sockets'] as $e)if(!extension_loaded($e))exit(2);"],check=True)
 code='require $argv[1]."/include/cli_check.php";if(!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=?",[(int)$argv[2],""]))exit(3);echo json_encode(["url"=>$config["url_path"],"log"=>read_config_option("path_cactilog")]);'
 native=json.loads(subprocess.check_output([str(php),'-r',code,str(base),str(a.poller_id)],text=True))
 home=pathlib.Path('/Library/Application Support/CactiNMS')
 run=home/'run';store=home/'ssh';key=store/'master.key';config=plugin/'ssh.config.local.json'
 cfg={'control_socket':str(run/'control.sock'),'store_dir':str(store/'secrets'),'master_key':str(key),'origin':a.origin,'guacd_listen':'127.0.0.1:'+str(a.guacd_port),'poller_id':a.poller_id}
 if config.exists() and json.loads(config.read_text())!=cfg:p.error('Existing configuration differs; it was not overwritten.')
 for path in [home,run,store,store/'secrets',key,config]:
  if path.is_symlink():p.error('Refusing symlink: '+str(path))
 home.mkdir(exist_ok=True);os.chown(home,0,0);home.chmod(0o755)
 for path,mode in [(run,0o750),(store,0o700),(store/'secrets',0o700)]:
  path.mkdir(exist_ok=True);os.chown(path,user.pw_uid,group.gr_gid);path.chmod(mode)
 if not key.exists():
  fd=os.open(key,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
  with os.fdopen(fd,'wb') as f:f.write(os.urandom(32))
 os.chown(key,user.pw_uid,group.gr_gid);key.chmod(0o600)
 config.write_text(json.dumps(cfg,indent=2)+'\n');os.chown(config,0,group.gr_gid);config.chmod(0o640)
 subprocess.run(['/bin/chmod','+a',f'user {a.service_user} allow write,append',native['log']],check=True)
 unit={'Label':'net.cacti.nms.ssh','ProgramArguments':[str(php),str(plugin/'ssh/gateway.php')],'UserName':a.service_user,'GroupName':a.web_group,'WorkingDirectory':str(base),'RunAtLoad':True,'KeepAlive':True,'ThrottleInterval':10,'Umask':7,'StandardOutPath':str(run/'service.log'),'StandardErrorPath':str(run/'service.log')}
 target=pathlib.Path('/Library/LaunchDaemons/net.cacti.nms.ssh.plist')
 if target.is_symlink():p.error('Refusing linked launchd definition.')
 if target.exists() and plistlib.loads(target.read_bytes())!=unit:p.error('Existing launchd definition differs; review it before replacement.')
 target.write_bytes(plistlib.dumps(unit));os.chown(target,0,0);target.chmod(0o644)
 subprocess.run(['/usr/bin/plutil','-lint',str(target)],check=True)
 print('Configured. Set HTTPS/WSS proxy and client trust, then run: sudo launchctl bootstrap system '+str(target))
if __name__=='__main__':main()
