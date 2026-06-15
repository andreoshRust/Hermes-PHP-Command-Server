@echo off
cd /d C:\andreosh\GitHab_my\Hermes-PHP-Command-Server

echo ========================================
echo    Hermes-PHP-Command-Server Repository Update
echo ========================================
echo.

git add .

echo Files to be committed:
git status --short
echo.

for /f "tokens=1-3 delims=.: " %%a in ('echo %time%') do set "mytime=%%a:%%b:%%c"
git commit -m "Update from %date% %mytime%"

git push origin main

echo.
echo ========================================
echo    Done!
echo ========================================
pause
