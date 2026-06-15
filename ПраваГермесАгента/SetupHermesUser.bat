@echo off
title Hermes Agent - User Setup
color 0A

set HERMES_USER=HermesAgent
set HERMES_PASSWORD=123

set ALLOWED_READ_DIRS=D:\PortableAI\DostupHermes
set ALLOWED_WRITE_DIRS=D:\PortableAI\DostupHermes
set BLOCKED_COMMANDS=format;diskpart;shutdown;taskkill;del /f;rmdir /s

:: Проверка прав администратора
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Run as Administrator!
    pause
    exit /b 1
)

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

:: Настройка папок (правильный синтаксис для русской Windows)
echo.
echo [*] Setting folder permissions...

for %%d in (%ALLOWED_READ_DIRS%) do (
    if exist %%d (
        icacls "%%d" /grant "%HERMES_USER%:R" /T
    ) else (
        mkdir "%%d" 2>nul
        icacls "%%d" /grant "%HERMES_USER%:R" /T
    )
    echo [OK] Read: %%d
)

for %%d in (%ALLOWED_WRITE_DIRS%) do (
    if exist %%d (
        icacls "%%d" /grant "%HERMES_USER%:RW" /T
    ) else (
        mkdir "%%d" 2>nul
        icacls "%%d" /grant "%HERMES_USER%:RW" /T
    )
    echo [OK] Write: %%d
)

:: Запрет системных папок
echo.
echo [*] Blocking system folders...
icacls "C:\Windows" /deny "%HERMES_USER%:R" /T 2>nul
icacls "C:\Program Files" /deny "%HERMES_USER%:R" /T 2>nul
icacls "C:\Program Files (x86)" /deny "%HERMES_USER%:R" /T 2>nul

:: Создание конфига
set CONFIG_FILE=D:\PortableAI\PHP\_Run\user_config.php

echo ^<?php > %CONFIG_FILE%
echo define('RUNAS_USER', '%HERMES_USER%'); >> %CONFIG_FILE%
echo define('RUNAS_PASSWORD', '%HERMES_PASSWORD%'); >> %CONFIG_FILE%
echo define('ALLOWED_READ_DIRS', ['%ALLOWED_READ_DIRS:;=', '%']); >> %CONFIG_FILE%
echo define('ALLOWED_WRITE_DIRS', ['%ALLOWED_WRITE_DIRS:;=', '%']); >> %CONFIG_FILE%
echo define('BLOCKED_COMMANDS', ['%BLOCKED_COMMANDS:;=', '%']); >> %CONFIG_FILE%
echo ?^> >> %CONFIG_FILE%

echo.
echo ============================================================
echo   SETUP COMPLETE
echo ============================================================
echo.
echo User: %HERMES_USER%
echo Password: %HERMES_PASSWORD%
echo.
echo Read:  %ALLOWED_READ_DIRS%
echo Write: %ALLOWED_WRITE_DIRS%
echo.
echo Blocked: %BLOCKED_COMMANDS%
echo.
pause
