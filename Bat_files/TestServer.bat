@echo off
title Test Hermes Server
color 0A
echo ========================================
echo   Testing Hermes PHP Command Server
echo ========================================
echo.
:: Проверка, запущен ли сервер
echo [1] Checking server status...
curl -s --connect-timeout 2 "http://localhost:1010/api.php?action=help" -H "Authorization: Bearer your_secret_token_here" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Server not running!
    echo Please start server first: RunPHPServer.bat
    pause
    exit /b 1
)
echo [OK] Server is running
echo.
:: Тест 1: Справка
echo [2] Testing help...
curl -s "http://localhost:1010/api.php?action=help" -H "Authorization: Bearer your_secret_token_here" | findstr "version"
echo.
:: Тест 2: Echo
echo [3] Testing echo...
curl -s -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:echo OK\"}" | findstr "OK"
echo.
:: Тест 3: Dir
echo [4] Testing directory listing...
curl -s -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:dir D:\\PortableAI\"}" | findstr "PortableAI"
echo.
:: Тест 4: Создание файла
echo [5] Testing file creation...
curl -s -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:powershell -Command \\\"Set-Content -Path 'D:/PortableAI/temp/test123.txt' -Value 'Hello'\\\"\"}" >nul
if exist "D:\PortableAI\temp\test123.txt" (echo [OK] File created) else (echo [FAIL] File not created)
echo.
:: Тест 5: Блокировка команды
echo [6] Testing blocked command...
curl -s -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:shutdown\"}" | findstr "blocked"
echo.
:: Тест 6: Асинхронный режим
echo [7] Testing async mode...
set TASK_ID=
for /f "tokens=6 delims=:" %%a in ('curl -s -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"submit\",\"query\":\"cmd:echo AsyncTest\"}"') do set TASK_ID=%%a
echo [OK] Task submitted: %TASK_ID%
echo.
echo ========================================
echo   Tests completed
echo ========================================
pause

