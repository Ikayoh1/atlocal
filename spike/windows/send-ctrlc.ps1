<#
  Sends CTRL_C_EVENT to the console of a target process. Must run in its own
  powershell.exe process (it detaches from its own console). Exit 0 = event sent.
#>
param([Parameter(Mandatory)][int]$TargetPid)
Add-Type -Namespace AtLocalSpike -Name Kernel32 -MemberDefinition @'
[DllImport("kernel32.dll", SetLastError = true)] public static extern bool FreeConsole();
[DllImport("kernel32.dll", SetLastError = true)] public static extern bool AttachConsole(uint dwProcessId);
[DllImport("kernel32.dll", SetLastError = true)] public static extern bool SetConsoleCtrlHandler(System.IntPtr handler, bool add);
[DllImport("kernel32.dll", SetLastError = true)] public static extern bool GenerateConsoleCtrlEvent(uint dwCtrlEvent, uint dwProcessGroupId);
'@
[AtLocalSpike.Kernel32]::FreeConsole() | Out-Null
if (-not [AtLocalSpike.Kernel32]::AttachConsole([uint32]$TargetPid)) { exit 2 }
[AtLocalSpike.Kernel32]::SetConsoleCtrlHandler([IntPtr]::Zero, $true) | Out-Null   # do not kill ourselves
$sent = [AtLocalSpike.Kernel32]::GenerateConsoleCtrlEvent(0, 0)
Start-Sleep -Milliseconds 500
[AtLocalSpike.Kernel32]::FreeConsole() | Out-Null
if ($sent) { exit 0 } else { exit 3 }
