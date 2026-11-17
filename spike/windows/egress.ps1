<#
.SYNOPSIS
  Snapshot of the engine process's network endpoints, flagging any connection
  whose remote address is not loopback or the configured bindIp.

  This is supporting evidence only. The authoritative offline test is running the
  whole spike with the WAN uplink physically disconnected (docs/SPIKE-RESULTS.md).
#>
param([string]$Label = 'snapshot')
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path "$PSScriptRoot\..\..").Path
$cfg = Get-Content "$root\spike\spike.config.json" -Raw | ConvertFrom-Json
$pidFile = "$root\var\stalwart\stalwart.pid"
if (-not (Test-Path $pidFile)) { throw 'engine not running' }
$enginePid = [int](Get-Content $pidFile -Raw)

$local = @('127.0.0.1', '::1', '0.0.0.0', '::', $cfg.bindIp)
$tcp = @(Get-NetTCPConnection -OwningProcess $enginePid -ErrorAction SilentlyContinue)
$udp = @(Get-NetUDPEndpoint -OwningProcess $enginePid -ErrorAction SilentlyContinue)

$external = @($tcp | Where-Object { $_.State -ne 'Listen' -and ($local -notcontains $_.RemoteAddress) })
$result = [ordered]@{
    label             = $Label
    at                = (Get-Date).ToUniversalTime().ToString('o')
    pid               = $enginePid
    listening         = @($tcp | Where-Object State -eq 'Listen' | ForEach-Object { "$($_.LocalAddress):$($_.LocalPort)" } | Sort-Object -Unique)
    udpEndpoints      = @($udp | ForEach-Object { "$($_.LocalAddress):$($_.LocalPort)" } | Sort-Object -Unique)
    nonLocalTcp       = @($external | ForEach-Object { "$($_.RemoteAddress):$($_.RemotePort) [$($_.State)]" })
    nonLocalTcpCount  = $external.Count
}
$outDir = "$root\var\spike"
New-Item -ItemType Directory -Force $outDir | Out-Null
$result | ConvertTo-Json -Depth 4 | Out-File -Encoding utf8 "$outDir\egress-$Label.json"
$result | ConvertTo-Json -Depth 4
