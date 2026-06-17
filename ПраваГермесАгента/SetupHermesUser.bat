@echo off
:: Проверка прав администратора
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo ============================================================
    echo   [ERROR] This script must be run as Administrator!
    echo ============================================================
    echo.
    echo Right-click on this file and select "Run as administrator"
    echo.
    pause
    exit /b 1
)

title Hermes Agent - User Setup
color 0A

set HERMES_USER=HermesAgent
set HERMES_PASSWORD=123

echo ============================================================
echo   Hermes Agent - User Setup
echo ============================================================
echo.

:: Создание пользователя
net user %HERMES_USER% >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] User %HERMES_USER% already exists
) else (
    echo [*] Creating user %HERMES_USER%...
    net user %HERMES_USER% %HERMES_PASSWORD% /add /expires:never
    net localgroup Users %HERMES_USER% /delete
    net localgroup Guests %HERMES_USER% /add
    echo [OK] User created
)

:: Настройка прав
echo.
echo [*] Setting folder permissions...

takeown /F D:\PortableAI\DostupHermes /R /D Y
icacls D:\PortableAI\DostupHermes /inheritance:r /T
icacls D:\PortableAI\DostupHermes /reset /T
icacls D:\PortableAI\DostupHermes /grant %HERMES_USER%:(OI)(CI)F /T
icacls D:\PortableAI\DostupHermes /remove Everyone /T 2>nul
icacls D:\PortableAI\DostupHermes /remove "NT AUTHORITY\Authenticated Users" /T 2>nul

echo [OK] Permissions set!
echo.
icacls D:\PortableAI\DostupHermes

echo.
echo ============================================================
echo   SETUP COMPLETE
echo ============================================================
echo.
echo User: %HERMES_USER%
echo Password: %HERMES_PASSWORD%
echo.
pause
