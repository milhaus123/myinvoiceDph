# MyInvoice.cz — Docker upgrade watcher (Windows / PowerShell verze).
#
# Sleduje storage/upgrade-requested.json a když ho UI vytvoří (POST
# /api/admin/update/trigger), spustí docker-update.ps1 a výsledek zapíše
# do storage/upgrade-result.json. UI to pak v Settings → Aktualizace
# zobrazí jako „aplikováno / selhalo".
#
# Provoz:
#   - Pust jako Scheduled Task (Trigger: At startup, Action: powershell.exe
#     -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myinvoice\cmd\docker-update-watcher.ps1)
#     s "Run whether user is logged in or not" + "Run with highest privileges".
#   - Nebo z user shellu manuálně, dokud je session aktivní.
#
# Idempotent — flag se zpracovává jednou (rename před spuštěním).
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

$flag     = Join-Path $ProjectRoot 'storage\upgrade-requested.json'
$result   = Join-Path $ProjectRoot 'storage\upgrade-result.json'
$inflight = Join-Path $ProjectRoot 'storage\upgrade-inflight.json'
$intervalS = if ($env:MYINVOICE_WATCHER_INTERVAL) { [int]$env:MYINVOICE_WATCHER_INTERVAL } else { 30 }

if (-not (Test-Path 'storage')) { New-Item -ItemType Directory -Path 'storage' -Force | Out-Null }
Write-Host "[watcher] start, polling $flag every $intervalS s (cwd: $ProjectRoot)"

function Write-Result {
    param(
        [string]$Status,
        [string]$Target,
        [string]$Message
    )
    $payload = @{
        status         = $Status
        target_version = $Target
        applied_at     = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        message        = $Message
    }
    ($payload | ConvertTo-Json -Depth 4) | Set-Content -Path $result -Encoding UTF8
}

while ($true) {
    if (Test-Path $flag) {
        Move-Item -Path $flag -Destination $inflight -Force

        $target = 'latest'
        try {
            $payload = Get-Content -Path $inflight -Raw | ConvertFrom-Json
            if ($payload.target_version) { $target = [string]$payload.target_version }
        } catch {
            Write-Warning "Nelze parsnout $inflight: $_"
        }

        $ts = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
        $log = Join-Path 'storage' ("upgrade-$ts.log")
        Write-Host "[watcher] $((Get-Date).ToUniversalTime().ToString('s'))Z upgrade requested → $target"

        try {
            & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ProjectRoot 'cmd\docker-update.ps1') *>&1 | Tee-Object -FilePath $log
            if ($LASTEXITCODE -eq 0) {
                Write-Host "[watcher] OK"
                Write-Result -Status 'applied' -Target $target -Message "Upgrade dokoncen. Log: $log"
            } else {
                Write-Host "[watcher] FAILED (rc=$LASTEXITCODE). Viz $log"
                Write-Result -Status 'failed' -Target $target -Message "Upgrade selhal (rc=$LASTEXITCODE). Log: $log"
            }
        } catch {
            Write-Host "[watcher] EXCEPTION: $_"
            Write-Result -Status 'failed' -Target $target -Message "Watcher exception: $_. Log: $log"
        } finally {
            if (Test-Path $inflight) { Remove-Item $inflight -Force }
        }
    }
    Start-Sleep -Seconds $intervalS
}
