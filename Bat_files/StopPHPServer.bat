@echo off
title Stop PHP Server
color 0A
echo ============================================================
echo   Stopping PHP Server
echo ============================================================
echo.
:: Завершение всех процессов PHP
taskkill /f /im php.exe 2>nul
if errorlevel 1 (
    echo [INFO] No PHP processes found
) else (
    echo [OK] PHP processes stopped
)
:: Проверка, что порт 1010 свободен
echo.
echo Checking port 1010...
netstat -an | findstr ":1010" >nul
if errorlevel 1 (
    echo [OK] Port 1010 is free
) else (
    echo [WARN] Port 1010 may still be in use
)
echo.
pause
