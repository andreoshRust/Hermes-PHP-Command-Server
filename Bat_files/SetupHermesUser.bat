
@echo off
title Hermes Agent - User Setup
color 0A
:: ============================================================
:: Õ¿—“–Œ… » Œ√–¿Õ»◊≈Õ»… (»«Ã≈Õﬂ…“≈ «ƒ≈—‹)
:: ============================================================
set HERMES_USER=HermesAgent
set HERMES_PASSWORD=123
:: –‡ÁÂ¯∏ÌÌ˚Â ‰Îˇ ˜ÚÂÌËˇ Ô‡ÔÍË
set ALLOWED_READ_DIRS=D:\PortableAI;D:\Projects
:: –‡ÁÂ¯∏ÌÌ˚Â ‰Îˇ Á‡ÔËÒË Ô‡ÔÍË
set ALLOWED_WRITE_DIRS=D:\PortableAI\_Write;D:\PortableAI\temp
:: «‡ÔÂ˘∏ÌÌ˚Â ÍÓÏ‡Ì‰˚
set BLOCKED_COMMANDS=format;diskpart;shutdown;taskkill;del /f;rmdir /s
:: ============================================================
:: œ–Œ¬≈– ¿ œ–¿¬ ¿ƒÃ»Õ»—“–¿“Œ–¿
:: ============================================================
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
:: ============================================================
:: —Œ«ƒ¿Õ»≈ œŒÀ‹«Œ¬¿“≈Àﬂ
:: ============================================================
net user %HERMES_USER% >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] User %HERMES_USER% already exists
) else (
    echo [*] Creating user %HERMES_USER% with password %HERMES_PASSWORD%...
    net user %HERMES_USER% %HERMES_PASSWORD% /add /expires:never
    net localgroup Users %HERMES_USER% /delete
    net localgroup Guests %HERMES_USER% /add
    echo [OK] User created
)
:: ============================================================
:: Õ¿—“–Œ… ¿ œ¿œŒ 
:: ============================================================
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
:: ============================================================
:: «¿œ–≈“ —»—“≈ÃÕ€’ œ¿œŒ 
:: ============================================================
echo.
echo [*] Blocking system folders...
icacls "C:\Windows" /deny "%HERMES_USER%:R" /T 2>nul
icacls "C:\Program Files" /deny "%HERMES_USER%:R" /T 2>nul
icacls "C:\Program Files (x86)" /deny "%HERMES_USER%:R" /T 2>nul
:: ============================================================
:: ¬€¬Œƒ »“Œ√Œ¬Œ… »Õ‘Œ–Ã¿÷»»
:: ============================================================
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
echo Next steps:
echo 1. Run RunPHPServer.bat to start the server
echo.
pause
