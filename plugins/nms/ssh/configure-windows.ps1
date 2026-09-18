# Run in elevated Windows PowerShell 5.1. Uses an existing unprivileged service account.
param(
 [Parameter(Mandatory=$true)][string]$CactiPath,
 [Parameter(Mandatory=$true)][string]$PhpPath,
 [Parameter(Mandatory=$true)][string]$Origin,
 [Parameter(Mandatory=$true)][string]$WebAccount,
 [Parameter(Mandatory=$true)][PSCredential]$ServiceCredential,
 [Parameter(Mandatory=$true)][int]$PollerId,
 [int]$GuacdPort=4822,[int]$ControlPort=8766
)
$ErrorActionPreference='Stop'
[Console]::OutputEncoding=[Text.UTF8Encoding]::new($false)
$principal=[Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
if(!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'Run elevated Windows PowerShell.'}
$CactiPath=(Resolve-Path -LiteralPath $CactiPath).Path
$PhpPath=(Resolve-Path -LiteralPath $PhpPath).Path
$plugin=Join-Path $CactiPath 'plugins\nms'
if(!(Test-Path -LiteralPath (Join-Path $CactiPath 'include\cli_check.php'))){throw 'Cacti bootstrap missing.'}
if($Origin -notmatch '^https://[a-zA-Z0-9.\-]+(:[0-9]+)?$' -or $GuacdPort -lt 1024 -or $GuacdPort -gt 65535 -or $ControlPort -lt 1024 -or $ControlPort -gt 65535 -or $GuacdPort -eq $ControlPort){throw 'Invalid HTTPS origin or distinct loopback ports.'}
$serviceSid=([Security.Principal.NTAccount]::new($ServiceCredential.UserName)).Translate([Security.Principal.SecurityIdentifier]).Value
$webSid=([Security.Principal.NTAccount]::new($WebAccount)).Translate([Security.Principal.SecurityIdentifier]).Value
if($serviceSid -eq $webSid -or $serviceSid -in @('S-1-5-18','S-1-5-19','S-1-5-20')){throw 'Use a dedicated service identity distinct from PHP and system accounts.'}
# A temporary PHP script avoids Windows PowerShell native -r quote stripping.
$probe=[IO.Path]::GetTempFileName()
$probeCode=@'
<?php
try {
 foreach(['openssl','sockets'] as $e)if(!extension_loaded($e))throw new RuntimeException('Missing extension');
 require $argv[1].'/include/cli_check.php';
 if(!db_fetch_cell_prepared('SELECT id FROM poller WHERE id=? AND disabled=?',[(int)$argv[2],'']))throw new RuntimeException('Collector missing');
 echo json_encode(['url'=>$config['url_path'],'log'=>read_config_option('path_cactilog')]);
} catch(Throwable $e){fwrite(STDERR,"Cacti, collector or PHP dependency validation failed.\n");exit(2);}
'@
try {
 [IO.File]::WriteAllText($probe,$probeCode,[Text.UTF8Encoding]::new($false))
 $native=& $PhpPath $probe $CactiPath $PollerId
 if($LASTEXITCODE){throw 'Cacti collector/bootstrap validation failed.'}
} finally {Remove-Item -LiteralPath $probe -Force}
$native=$native | ConvertFrom-Json
$home=Join-Path $env:ProgramData 'CactiNMS'
$store=Join-Path $home 'ssh-private';$rpc=Join-Path $home 'ssh-rpc';$bin=Join-Path $home 'ssh-service'
$configPath=Join-Path $plugin 'ssh.config.local.json'
$cfg=[ordered]@{store_dir=(Join-Path $store 'secrets');master_key=(Join-Path $store 'master.key');rpc_key=(Join-Path $rpc 'transport.key');control_listen="127.0.0.1:$ControlPort";service_sid=$serviceSid;web_sid=$webSid;origin=$Origin;guacd_listen="127.0.0.1:$GuacdPort";poller_id=$PollerId}
if((Test-Path -LiteralPath $configPath) -or (Get-Service nms-ssh -ErrorAction SilentlyContinue)){throw 'Existing SSH configuration or service found. Preserve it and review an upgrade; this installer does not overwrite it.'}
function Set-NmsAcl([string]$Path,[hashtable]$Access){
 $item=Get-Item -LiteralPath $Path -Force
 if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw "Reparse point rejected: $Path"}
 if($item.PSIsContainer){$acl=[Security.AccessControl.DirectorySecurity]::new()}else{$acl=[Security.AccessControl.FileSecurity]::new()}
 $acl.SetAccessRuleProtection($true,$false)
 $acl.SetOwner([Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'))
 foreach($sid in $Access.Keys){
  $inherit=if($item.PSIsContainer){[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'}else{[Security.AccessControl.InheritanceFlags]::None}
  $rule=[Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new($sid),[Security.AccessControl.FileSystemRights]$Access[$sid],$inherit,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)
  $acl.AddAccessRule($rule)
 }
 Set-Acl -LiteralPath $Path -AclObject $acl
}
$private=@{'S-1-5-18'='FullControl';'S-1-5-32-544'='FullControl'};$private[$serviceSid]='Modify'
$shared=@{'S-1-5-18'='FullControl';'S-1-5-32-544'='FullControl'};$shared[$serviceSid]='ReadAndExecute';$shared[$webSid]='ReadAndExecute'
# Source and configuration must not be writable by the web or service identity.
$codeItems=@(Get-Item -LiteralPath $plugin)+@(Get-ChildItem -LiteralPath $plugin -Recurse -Force)
foreach($item in $codeItems){if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Linked plugin content rejected; review code installation first.'}}
foreach($item in $codeItems){Set-NmsAcl $item.FullName $shared}
foreach($path in @($home,$store,$rpc,$bin,$cfg.store_dir)){
 if(Test-Path -LiteralPath $path){if((Get-Item -LiteralPath $path).Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Reparse point rejected.'}}else{New-Item -ItemType Directory -Path $path | Out-Null}
}
Set-NmsAcl $home $shared;Set-NmsAcl $store $private;Set-NmsAcl $cfg.store_dir $private;Set-NmsAcl $rpc $shared;Set-NmsAcl $bin $shared
foreach($path in @($cfg.master_key,$cfg.rpc_key)){
 if(Test-Path -LiteralPath $path){throw 'Existing key found; never overwrite it.'}
 $bytes=New-Object byte[] 32;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();$rng.GetBytes($bytes);$rng.Dispose();[IO.File]::WriteAllBytes($path,$bytes)
}
Set-NmsAcl $cfg.master_key $private;Set-NmsAcl $cfg.rpc_key $shared
[IO.File]::WriteAllText($configPath,($cfg | ConvertTo-Json),[Text.UTF8Encoding]::new($false))
Set-NmsAcl $configPath $shared
$xml=[xml]'<service><php/><script/><cwd/></service>'
$xml.service.php=$PhpPath;$xml.service.script=(Join-Path $plugin 'ssh\gateway.php');$xml.service.cwd=$CactiPath
$xml.Save((Join-Path $bin 'service.xml'))
$exe=Join-Path $bin 'nms-ssh-service.exe'
Add-Type -TypeDefinition (Get-Content -LiteralPath (Join-Path $plugin 'ssh\windows-service.cs') -Raw) -ReferencedAssemblies 'System.dll','System.ServiceProcess.dll','System.Xml.dll' -OutputAssembly $exe -OutputType WindowsApplication
# Preserve native log permissions and add only the service's write access.
$acl=Get-Acl -LiteralPath $native.log
$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new($serviceSid),'Write','Allow'))
Set-Acl -LiteralPath $native.log -AclObject $acl
New-Service -Name nms-ssh -BinaryPathName ('"'+$exe+'"') -DisplayName 'Cacti NMS SSH backend' -StartupType Automatic -Credential $ServiceCredential | Out-Null
& sc.exe failure nms-ssh reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null
& sc.exe failureflag nms-ssh 1 | Out-Null
Write-Output 'Configured. Grant this dedicated account Log on as a service in Local Security Policy; configure same-origin HTTPS/WSS proxy; then Start-Service nms-ssh. Do not expose loopback ports through the firewall.'
