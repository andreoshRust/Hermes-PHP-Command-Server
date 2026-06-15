@echo off
title Reset Hermes User Permissions
color 0A

:: Запуск от администратора
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Run as Administrator!
    pause
    exit /b 1
)

echo ============================================================
echo   Resetting Hermes User Permissions
echo ============================================================
echo.

:: 1. Остановить PHP-сервер (если запущен)
echo [1] Stopping PHP server...
taskkill /f /im php.exe 2>nul
echo.

:: 2. Удалить пользователя
echo [2] Removing user HermesAgent...
net user HermesAgent /delete 2>nul
if errorlevel 1 (
    echo [WARN] User did not exist or could not be deleted
) else (
    echo [OK] User deleted
)
echo.

:: 3. Запустить установку заново
echo [3] Running SetupHermesUser...
call SetupHermesUser.bat

echo.
echo ============================================================
echo   Done! Restart PHP server manually.
echo ============================================================
pause