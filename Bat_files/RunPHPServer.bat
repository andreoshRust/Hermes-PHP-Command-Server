@echo off
title Hermes PHP Server
color 0A
:: ============================================================
:: Õ¿—“–Œ… »
:: ============================================================
set BASE_DIR=D:\PortableAI
set PHP_DIR=%BASE_DIR%\PHP
set WEB_DIR=%BASE_DIR%\PHP\_Run
set PHP_EXE=%PHP_DIR%\php.exe
:: ============================================================
:: œ–Œ¬≈– ¿ Õ¿À»◊»ﬂ PHP
:: ============================================================
if not exist "%PHP_EXE%" (
    echo [ERROR] PHP not found at %PHP_EXE%
    echo Please download PHP from https://windows.php.net/download/
    echo and extract to %PHP_DIR%\
    pause
    exit /b 1
)
:: ============================================================
:: «¿œ”—  —≈–¬≈–¿
:: ============================================================
echo ============================================================
echo   Hermes PHP Command Server
echo ============================================================
echo   PHP: %PHP_EXE%
echo   Web Dir: %WEB_DIR%
echo   URL: http://localhost:1010
echo ============================================================
echo.
echo Press Ctrl+C to stop the server
echo.
"%PHP_EXE%" -S localhost:1010 -t "%WEB_DIR%"
pause


