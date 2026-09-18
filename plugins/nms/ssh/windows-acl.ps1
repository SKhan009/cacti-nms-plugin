param([string]$InspectPath)
$ErrorActionPreference='Stop'
try {
 $identity=[Security.Principal.WindowsIdentity]::GetCurrent()
 $principal=[Security.Principal.WindowsPrincipal]::new($identity)
 $result=@{current_sid=$identity.User.Value;administrator=$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)}
 if($InspectPath){
  $item=Get-Item -LiteralPath $InspectPath -Force
  $acl=Get-Acl -LiteralPath $InspectPath
  $result.owner=$acl.GetOwner([Security.Principal.SecurityIdentifier]).Value
  $result.reparse=[bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)
  $result.rules=@($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]) | ForEach-Object {@{sid=$_.IdentityReference.Value;allow=$_.AccessControlType -eq 'Allow';rights=[int64]$_.FileSystemRights}})
 }
 $result | ConvertTo-Json -Depth 5 -Compress
} catch {exit 1}
