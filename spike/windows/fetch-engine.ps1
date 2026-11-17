<#
.SYNOPSIS
  Downloads the pinned Stalwart Windows build and verifies its SHA-256 against
  spike/spike.config.json. Run once on a connected machine; for air-gapped hosts copy
  var\engine\ across on removable media and run with -OfflineArchive <path>.

  Provenance: the hash is also the digest recorded in the release's Sigstore bundle.
  Full Sigstore signature verification (cosign/sigstore-go) is a Phase 1 supply-chain task.
#>
param([string]$OfflineArchive)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path "$PSScriptRoot\..\..").Path
$cfg = Get-Content "$root\spike\spike.config.json" -Raw | ConvertFrom-Json
$ver = $cfg.engine.version
$dir = "$root\var\engine\stalwart-$ver"
$zip = "$dir\download\$($cfg.engine.windowsArchive)"
New-Item -ItemType Directory -Force "$dir\download" | Out-Null

if ($OfflineArchive) {
    Copy-Item $OfflineArchive $zip -Force
} elseif (-not (Test-Path $zip)) {
    $url = "https://github.com/stalwartlabs/stalwart/releases/download/$ver/$($cfg.engine.windowsArchive)"
    & curl.exe -sSL --fail -o $zip $url
    if ($LASTEXITCODE -ne 0) { throw "download failed: $url" }
}
$hash = (Get-FileHash $zip -Algorithm SHA256).Hash
if ($hash -ne $cfg.engine.windowsArchiveSha256) {
    Remove-Item $zip -Force
    throw "SHA-256 mismatch for $zip (got $hash). Archive deleted."
}
Expand-Archive $zip -DestinationPath "$dir\bin" -Force
"verified $($cfg.engine.windowsArchive) sha256=$hash -> $dir\bin\stalwart.exe"
