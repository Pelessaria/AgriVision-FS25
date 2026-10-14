# Packs FS25_AgriVisionConnector for dedicated-server install (ZIP root = mod files).
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$src = Join-Path $root "FS25_AgriVisionConnector"
$dist = Join-Path $root "dist"
$zip = Join-Path $dist "FS25_AgriVisionConnector.zip"

if (-not (Test-Path (Join-Path $src "modDesc.xml"))) {
  throw "modDesc.xml missing in $src"
}

New-Item -ItemType Directory -Force -Path $dist | Out-Null
if (Test-Path $zip) { Remove-Item $zip -Force }

# Compress contents of the mod folder to the ZIP root (not nested folder).
Push-Location $src
try {
  Compress-Archive -Path * -DestinationPath $zip -Force
} finally {
  Pop-Location
}

# Also ship inside the Laravel tree so in-app updates / FTP deploy find the zip.
$backendPackageDir = Join-Path (Split-Path $root -Parent) "backend\resources\connector"
New-Item -ItemType Directory -Force -Path $backendPackageDir | Out-Null
Copy-Item -Force $zip (Join-Path $backendPackageDir "FS25_AgriVisionConnector.zip")

Write-Host "Packed: $zip"
Write-Host "Copied to backend/resources/connector/ for dashboard deploy"
Get-Item $zip | Format-List FullName, Length, LastWriteTime
