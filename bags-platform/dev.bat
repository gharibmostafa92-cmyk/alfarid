@echo off
title Alfarid Dev Servers
color 0A

echo ============================================
echo   Alfarid - Starting All Dev Servers
echo ============================================
echo.
echo [1] Backend    ^(Laravel^)   : http://localhost:8000
echo [2] Storefront ^(Next.js^)   : http://localhost:3001
echo [3] Backoffice ^(Vite^)      : http://localhost:5173
echo.
echo Starting servers in separate windows...
echo.

:: Backend - Laravel
start "Backend (Laravel :8000)" cmd /k "cd /d %~dp0backend && php artisan serve"

:: Wait a moment before next
timeout /t 2 /nobreak >nul

:: Storefront - Next.js (port 3001)
start "Storefront (Next.js :3001)" cmd /k "cd /d %~dp0frontend && pnpm dev:storefront"

:: Wait a moment before next
timeout /t 2 /nobreak >nul

:: Backoffice - Vite (port 5173) - run directly (bypasses turbo crash on Windows)
start "Backoffice (Vite :5173)" cmd /k "cd /d %~dp0frontend\apps\backoffice && pnpm vite --port 5173"

echo.
echo All 3 servers started in separate windows!
echo.
echo To stop: close each window or press Ctrl+C inside it.
pause
