@echo off
title Remove Hermes User
color 0A
:: ============================================================
:: ÏÐÎÂÅÐÊÀ ÏÐÀÂ ÀÄÌÈÍÈÑÒÐÀÒÎÐÀ
:: ============================================================
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Run as Administrator!
    pause
    exit /b 1
)
echo ============================================================
echo   Removing Hermes User
echo ============================================================
echo.
net user HermesAgent >nul 2>&1
if errorlevel 1 (
    echo [INFO] User HermesAgent does not exist
) else (
    net user HermesAgent /delete
    echo [OK] User HermesAgent deleted
)
echo.
pause
