# Build script for WordPress plugin ZIP
$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $scriptDir

$zipPath = Join-Path $scriptDir "eme-event-attendee-export.zip"
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

$tempDir = Join-Path ([System.IO.Path]::GetTempPath()) "eme-plugin-build-$(Get-Random)"
$stageDir = Join-Path $tempDir "eme-event-attendee-export"
New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

Write-Host "Staging files..."
Copy-Item -Path "admin" -Destination $stageDir -Recurse
Copy-Item -Path "assets" -Destination $stageDir -Recurse
Copy-Item -Path "includes" -Destination $stageDir -Recurse
Copy-Item -Path "eme-event-attendee-export.php" -Destination $stageDir
Copy-Item -Path "readme.txt" -Destination $stageDir
Copy-Item -Path "uninstall.php" -Destination $stageDir
Copy-Item -Path "LICENSE" -Destination $stageDir

Write-Host "Compressing to $zipPath..."
Compress-Archive -Path $stageDir -DestinationPath $zipPath -Force
Remove-Item -Path $tempDir -Recurse -Force

Write-Host "Success! Created production WordPress plugin ZIP:" -ForegroundColor Green
Write-Host $zipPath -ForegroundColor Cyan
