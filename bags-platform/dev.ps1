# Alfarid - Start All Dev Servers
# Usage: .\dev.ps1
# Runs: Backend (Laravel:8000), Storefront (Next.js:3001), Backoffice (Vite:5173)

$root = $PSScriptRoot

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  Alfarid - Starting All Dev Servers" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "[1] Backend    (Laravel)  : " -NoNewline; Write-Host "http://localhost:8000" -ForegroundColor Green
Write-Host "[2] Storefront (Next.js)  : " -NoNewline; Write-Host "http://localhost:3001" -ForegroundColor Green
Write-Host "[3] Backoffice (Vite)     : " -NoNewline; Write-Host "http://localhost:5173" -ForegroundColor Green
Write-Host ""
Write-Host "Starting servers in separate windows..." -ForegroundColor Yellow
Write-Host ""

# Backend - Laravel
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$root\backend'; Write-Host 'Backend (Laravel :8000)' -ForegroundColor Cyan; php artisan serve" -WindowStyle Normal

Start-Sleep -Seconds 2

# Storefront - Next.js (port 3001)
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$root\frontend'; Write-Host 'Storefront (Next.js :3001)' -ForegroundColor Green; pnpm dev:storefront" -WindowStyle Normal

Start-Sleep -Seconds 1

# Backoffice - Vite (port 5173) - run directly (bypasses turbo crash on Windows)
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$root\frontend\apps\backoffice'; Write-Host 'Backoffice (Vite :5173)' -ForegroundColor Magenta; pnpm vite --port 5173" -WindowStyle Normal

Write-Host "All 3 servers launched!" -ForegroundColor Green
Write-Host ""
Write-Host "Press any key to exit this window (servers keep running)..." -ForegroundColor Gray
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
