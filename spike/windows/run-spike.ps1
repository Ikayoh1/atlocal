<#
.SYNOPSIS
  End-to-end Phase 0 run on Windows. Every step stops on first failure.

   1. init        secrets, dev CA/cert, engine config.json
   2. recovery    start engine in recovery mode, discover + provision, stop
   3. normal      start, egress snapshot, re-provision (idempotency + objects created at first
                  normal start), discover, acceptance scenario, egress snapshot, graceful stop
   4. restart     start again, verify persistence, egress snapshot, stop

  Transcript: var\spike\run-<timestamp>.log
#>
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path "$PSScriptRoot\..\..").Path
$php = (Get-Command php).Source
$bin = "$root\spike\php\bin"
$engine = "$PSScriptRoot\engine.ps1"
New-Item -ItemType Directory -Force "$root\var\spike" | Out-Null
Start-Transcript -Path "$root\var\spike\run-$(Get-Date -Format yyyyMMdd-HHmmss).log" | Out-Null

function Step([string]$name, [scriptblock]$body) {
    Write-Host "`n=== $name ===" -ForegroundColor Cyan
    & $body   # PowerShell scripts signal failure by throwing
}

function Php([string]$script, [string[]]$arguments = @()) {
    & $php "$bin\$script" @arguments
    if ($LASTEXITCODE -ne 0) { throw "php $script $arguments failed (exit $LASTEXITCODE)" }
}

try {
    Step 'init' { Php 'init.php' }
    Step 'recovery: start' { & $engine start -Mode recovery }
    Step 'recovery: discover' { Php 'discover.php' @('--recovery') }
    Step 'recovery: provision' { Php 'provision.php' @('--recovery') }
    Step 'recovery: stop' { & $engine stop }

    Step 'normal: start' { & $engine start -Mode normal }
    Step 'normal: egress after start' { & "$PSScriptRoot\egress.ps1" -Label 'after-start' }
    Step 'normal: re-provision' { Php 'provision.php' }
    Step 'normal: discover' { Php 'discover.php' }
    Step 'normal: scenario run' { Php 'scenario.php' @('run') }
    Step 'normal: egress after scenario' { & "$PSScriptRoot\egress.ps1" -Label 'after-scenario' }
    Step 'normal: stop' { & $engine stop }

    Step 'restart: start' { & $engine start -Mode normal }
    Step 'restart: verify persistence' { Php 'scenario.php' @('verify-persistence') }
    Step 'restart: egress' { & "$PSScriptRoot\egress.ps1" -Label 'after-restart' }
    Write-Host "`nPhase 0 run completed. Results in var\spike\" -ForegroundColor Green
}
finally {
    & $engine stop | Out-Host
    Stop-Transcript | Out-Null
}
