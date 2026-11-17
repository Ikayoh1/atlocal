<#
.SYNOPSIS
  Start/stop/status for the Phase 0 Stalwart engine on Windows (native binary; no Docker).

.DESCRIPTION
  start -Mode recovery : management API only on http://127.0.0.1:<recoveryHttp> (no mail services).
                         NOTE: Stalwart binds the recovery listener on ALL interfaces (documented);
                         if Windows Firewall prompts, choose Cancel/deny.
  start -Mode normal   : mail services on the listeners provisioned by provision.php (bindIp only).
  stop                 : graceful Ctrl-C (the only stop signal Stalwart honours); after a timeout
                         falls back to a forced kill and SAYS SO.

  Spike-only conveniences, NOT a production pattern:
   - STALWART_RECOVERY_ADMIN is set on every start so the scripts can provision.
   - HTTP(S)_PROXY points at a dead local port as a best-effort guard against HTTP egress
     (does not block DNS or raw TCP; see egress.ps1 and docs/SPIKE-RESULTS.md).
#>
param(
    [Parameter(Mandatory)][ValidateSet('start', 'stop', 'status')][string]$Action,
    [ValidateSet('normal', 'recovery')][string]$Mode = 'normal',
    [int]$TimeoutSeconds = 60
)
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path "$PSScriptRoot\..\..").Path
$cfg = Get-Content "$root\spike\spike.config.json" -Raw | ConvertFrom-Json
$exe = "$root\var\engine\stalwart-$($cfg.engine.version)\bin\stalwart.exe"
$configJson = "$root\var\stalwart\etc\config.json"
$logDir = "$root\var\stalwart\logs"
$pidFile = "$root\var\stalwart\stalwart.pid"
$caFile = "$root\var\certs\atlocal-dev-ca.pem"

function Get-EngineProcess {
    if (-not (Test-Path $pidFile)) { return $null }
    $enginePid = [int](Get-Content $pidFile -Raw)
    $p = Get-Process -Id $enginePid -ErrorAction SilentlyContinue
    if ($p -and $p.Path -eq $exe) { return $p }
    return $null
}

function Test-Ready([string]$mode) {
    if ($mode -eq 'recovery') {
        $code = & curl.exe -s -o NUL -w '%{http_code}' --noproxy '*' "http://127.0.0.1:$($cfg.ports.recoveryHttp)/healthz/live"
    } else {
        $code = & curl.exe -s -o NUL -w '%{http_code}' --noproxy '*' --cacert $caFile `
            --resolve "$($cfg.hostname):$($cfg.ports.https):$($cfg.bindIp)" "https://$($cfg.hostname):$($cfg.ports.https)/healthz/live"
    }
    return ($code -eq '200')
}

switch ($Action) {
    'status' {
        $p = Get-EngineProcess
        if ($p) { "running pid=$($p.Id) started=$($p.StartTime)" } else { 'stopped' }
    }

    'start' {
        if (Get-EngineProcess) { throw 'Engine already running; stop it first.' }
        if (-not (Test-Path $exe)) { throw "Engine binary missing: $exe (run spike\windows\fetch-engine.ps1)" }
        if (-not (Test-Path $configJson)) { throw "Missing $configJson (run php spike\php\bin\init.php)" }
        $secrets = Get-Content "$root\var\secrets\spike-secrets.json" -Raw | ConvertFrom-Json

        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = "$env:SystemRoot\System32\cmd.exe"
        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
        $out = "$logDir\engine-$Mode-$stamp.out.log"
        $err = "$logDir\engine-$Mode-$stamp.err.log"
        # cmd /c gives the engine its own hidden console (needed for a graceful Ctrl-C) and file redirection.
        $psi.Arguments = "/d /c `"`"$exe`" --config `"$configJson`" 1>`"$out`" 2>`"$err`"`""
        $psi.UseShellExecute = $false
        $psi.CreateNoWindow = $true
        $psi.WorkingDirectory = "$root\var\stalwart"
        $envs = $psi.EnvironmentVariables
        $envs['STALWART_RECOVERY_ADMIN'] = "$($secrets.recoveryAdmin.user):$($secrets.recoveryAdmin.password)"
        $envs['STALWART_RECOVERY_MODE_PORT'] = [string]$cfg.ports.recoveryHttp
        $envs['STALWART_PUBLIC_URL'] = "https://$($cfg.hostname):$($cfg.ports.https)"
        $envs['STALWART_HOSTNAME'] = $cfg.hostname
        $envs['HTTP_PROXY'] = 'http://127.0.0.1:9'
        $envs['HTTPS_PROXY'] = 'http://127.0.0.1:9'
        $envs['NO_PROXY'] = '127.0.0.1,localhost,::1'
        if ($Mode -eq 'recovery') { $envs['STALWART_RECOVERY_MODE'] = '1' } else { $envs.Remove('STALWART_RECOVERY_MODE') }

        $shell = [System.Diagnostics.Process]::Start($psi)
        $engine = $null
        $deadline = (Get-Date).AddSeconds(15)
        while (-not $engine -and (Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 300
            $engine = Get-CimInstance Win32_Process -Filter "ParentProcessId=$($shell.Id) AND Name='stalwart.exe'" | Select-Object -First 1
        }
        if (-not $engine) { throw "stalwart.exe did not start; see $err" }
        Set-Content -Path $pidFile -Value $engine.ProcessId -Encoding ascii

        $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
        while ((Get-Date) -lt $deadline) {
            if (-not (Get-EngineProcess)) {
                Write-Host '--- engine exited during startup; stderr tail ---'
                Get-Content $err -Tail 40 -ErrorAction SilentlyContinue
                throw 'Engine exited during startup'
            }
            if (Test-Ready $Mode) {
                "started mode=$Mode pid=$($engine.ProcessId) logs=$out | $err"
                return
            }
            Start-Sleep -Seconds 1
        }
        Write-Host '--- engine not ready; stderr tail ---'
        Get-Content $err -Tail 40 -ErrorAction SilentlyContinue
        throw "Engine not ready after $TimeoutSeconds s (mode=$Mode)"
    }

    'stop' {
        $p = Get-EngineProcess
        if (-not $p) { 'already stopped'; return }
        $helper = Start-Process powershell.exe -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSScriptRoot\send-ctrlc.ps1`"", '-TargetPid', $p.Id) `
            -WindowStyle Hidden -Wait -PassThru
        $graceful = $false
        if ($helper.ExitCode -eq 0) { $graceful = $p.WaitForExit($TimeoutSeconds * 1000) }
        if ($graceful) {
            "stopped gracefully (Ctrl-C) pid=$($p.Id)"
        } else {
            Stop-Process -Id $p.Id -Force
            "WARNING: graceful stop failed (helper exit $($helper.ExitCode)); FORCE-killed pid=$($p.Id)"
        }
        Remove-Item $pidFile -ErrorAction SilentlyContinue
    }
}
