$ErrorActionPreference = "Stop"

$backendDir = Split-Path -Parent $PSScriptRoot
$repoRoot   = Split-Path -Parent $backendDir

# ── 1. SSH tunnel (MySQL poort 3307) ─────────────────────────────────────────
$tunnelRunning = (netstat -an | Select-String "127.0.0.1:3307.*LISTEN").Count -gt 0
if ($tunnelRunning) {
    Write-Host "[SSH]       tunnel al actief op poort 3307"
} else {
    Write-Host "[SSH]       tunnel starten (poort 3307)..."
    Start-Process -FilePath "ssh" `
        -ArgumentList "-N", "-L", "3307:176.62.168.235:3306", "lowieknaepsnxtmediatecheu@ssh085.webhosting.be" `
        -WindowStyle Hidden
    Start-Sleep -Seconds 2
    Write-Host "[SSH]       tunnel gestart"
}

# ── 2. Docker AI-service ──────────────────────────────────────────────────────
$aiRunning = (docker ps --filter "name=velopred-ai" --format "{{.Names}}" 2>$null) -ne ""
if ($aiRunning) {
    Write-Host "[Docker]    AI-service al actief op poort 8002"
} else {
    Write-Host "[Docker]    AI-service starten..."
    Start-Process -FilePath "docker" `
        -ArgumentList "compose", "-f", "$repoRoot\docker-compose.ai.yml", "up", "-d", "--build" `
        -NoNewWindow -Wait
    Write-Host "[Docker]    AI-service gestart op poort 8002"
}

# ── 3. Laravel server ─────────────────────────────────────────────────────────
Write-Host "[Laravel]   server starten op http://127.0.0.1:8080 ..."
Start-Process -FilePath "powershell.exe" `
    -ArgumentList "-NoExit", "-Command",
        "Write-Host 'Laravel  http://127.0.0.1:8080'; Set-Location '$backendDir\public'; php -S 127.0.0.1:8080 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"

# ── 4. Scheduler ──────────────────────────────────────────────────────────────
Write-Host "[Scheduler] starten..."
Start-Process -FilePath "powershell.exe" `
    -ArgumentList "-NoExit", "-Command",
        "Write-Host 'Scheduler'; Set-Location '$backendDir'; php artisan schedule:work"

Write-Host ""
Write-Host "Klaar. Open http://127.0.0.1:8080 in je browser."
Write-Host "Laat de twee vensters open terwijl je werkt."
