@echo off
title Hermes Server - Full Start
color 0A
:: ============================================================
:: «¿œ”—  PHP-—≈–¬≈–¿ ¬ Œ“ƒ≈À‹ÕŒÃ Œ Õ≈
:: ============================================================
echo Starting PHP server...
start "PHP Server" cmd /k "cd /d D:\PortableAI\PHP\_Run && D:\PortableAI\PHP\php.exe -S localhost:1010"
:: ŒÊË‰‡ÌËÂ Á‡ÔÛÒÍ‡
echo Waiting for server to start...
timeout /t 5 /nobreak >nul
:: ============================================================
:: »Õ»÷»¿À»«¿÷»ﬂ » “≈—“
:: ============================================================
echo.
echo ============================================================
echo   Initializing HermesAgent...
echo   A PowerShell window may appear for password entry
echo   Enter password: 123
echo ============================================================
echo.
curl -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:echo Server ready\"}"
echo.
echo ============================================================
echo   Server is running and ready!
echo ============================================================
echo.
echo Open browser: http://localhost:1010
echo.
pause

