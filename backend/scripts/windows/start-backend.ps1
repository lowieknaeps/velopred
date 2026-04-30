$ErrorActionPreference = "Stop"

# Starts the Laravel dev server + scheduler in separate terminal windows.
# Run from PowerShell:  powershell -ExecutionPolicy Bypass -File .\scripts\windows\start-backend.ps1

$backendDir = Split-Path -Parent $PSScriptRoot
Set-Location $backendDir

Write-Host "Starting Laravel server on http://127.0.0.1:8080 ..."
Start-Process -FilePath "powershell.exe" -ArgumentList "-NoExit", "-Command", "cd `"$backendDir`"; php artisan serve --host=127.0.0.1 --port=8080"

Write-Host "Starting scheduler (php artisan schedule:work) ..."
Start-Process -FilePath "powershell.exe" -ArgumentList "-NoExit", "-Command", "cd `"$backendDir`"; php artisan schedule:work"

Write-Host "Done. Keep these windows open while testing."

