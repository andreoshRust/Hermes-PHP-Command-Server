# 🦊 Hermes PHP Command Server — Документация для программиста

## 📌 Назначение системы

Система создана для того, чтобы **AI-агент (Hermes) мог выполнять команды на компьютере с Windows** через веб-интерфейс, без необходимости в сложной настройке WSL, Docker или дополнительных агентов.

### Основные цели:

- Дать агенту возможность **выполнять любые консольные команды**
    
- Обеспечить **безопасность** через ограниченного пользователя Windows
    
- Сделать систему **портативной** (вся в папке `D:\PortableAI`)
    
- Предоставить **прозрачную информацию о правах** агенту
    

---

## 🏗️ Архитектура системы

text

┌────────────┐     HTTP POST      ┌──────────────┐     shell_exec()    ┌────────────────┐
│   AI Agent      │ ─────────────► │   PHP Server    │ ────────────► │  Windows CMD   │
│   (Hermes)      │ ◄───────────── │   (api.php)       │ ◄──────────── │   (HermesAgent) │
└─────────────┘     JSON ответ     └─────────────┘     stdout/stderr  └────────────────┘
        ▲                                                                             │
        │                                                                              ▼
        └────────────────────── GET action=help ────────────────► Проверка прав

### Компоненты:

|Компонент|Роль|Расположение|
|---|---|---|
|**PHP Server**|Принимает запросы, проверяет безопасность, выполняет команды|`D:\PortableAI\PHP\_Run\api.php`|
|**Web Interface**|Визуальный интерфейс для ручного тестирования|`D:\PortableAI\PHP\_Run\index.html`|
|**HermesAgent**|Ограниченный пользователь Windows для выполнения команд|Создаётся скриптом `SetupHermesUser.bat`|
|**Agent**|AI-агент (Hermes), который отправляет запросы|`D:\PortableAI\.hermes`|

---

## ⚙️ Особенности и ограничения

### 🔐 Безопасность

|Механизм|Описание|
|---|---|
|**Ограниченный пользователь**|Команды выполняются от имени `HermesAgent` (не администратор)|
|**Блокировка команд**|Запрещены: `format`, `shutdown`, `taskkill`, `net user` и др.|
|**API ключ**|Все запросы требуют заголовок `Authorization: Bearer your_secret_token_here`|

### 📝 Работа с файлами

|Операция|Команда|Примечание|
|---|---|---|
|Чтение|`cmd: type D:\path`|Работает|
|Запись|`cmd: powershell -Command "Set-Content ..."`|**Только через PowerShell**|
|Добавление|`cmd: powershell -Command "Add-Content ..."`|**Только через PowerShell**|

> ⚠️ **Важно:** Простой `echo >` не работает через `curl`. Для записи файлов обязательно используйте PowerShell `Set-Content`.

### 🌐 Формат запросов

http

POST /api.php HTTP/1.1
Authorization: Bearer your_secret_token_here
Content-Type: application/json
{
    "action": "execute",
    "query": "cmd: dir D:\\PortableAI"
}

### 📤 Формат ответов

json

{
    "status": "success",
    "version": "3.1.0",
    "result": "Hello World\n",
    "timing": {"duration_ms": 46}
}

---

## 🚀 Установка и запуск

### 1. Создание ограниченного пользователя

batch

SetupHermesUser.bat

### 2. Запуск PHP-сервера

batch

cd D:\PortableAI\PHP\_Run
D:\PortableAI\PHP\php.exe -S localhost:1010

### 3. Проверка

bash

curl "http://localhost:1010/api.php?action=help" -H "Authorization: Bearer your_secret_token_here"

---

## 📋 Примеры запросов для агента

bash

# Справка
curl "http://localhost:1010/api.php?action=help" -H "Authorization: Bearer your_secret_token_here"
# Простая команда
curl -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:echo Hello\"}"
# Список файлов
curl -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:dir D:\\PortableAI\"}"
# Создание файла (PowerShell)
curl -X POST "http://localhost:1010/api.php" -H "Authorization: Bearer your_secret_token_here" -H "Content-Type: application/json" -d "{\"action\":\"execute\",\"query\":\"cmd:powershell -Command \\\"Set-Content -Path 'D:/PortableAI/temp/test.txt' -Value 'Hello'\\\"\"}"

---

## 🎯 Заключение

Система предоставляет **безопасный, контролируемый и портативный способ** для AI-агента выполнять команды на Windows. Ключевые преимущества:

- ✅ **Портативность** — вся система в одной папке `D:\PortableAI`
    
- ✅ **Безопасность** — ограниченный пользователь + блокировка команд
    
- ✅ **Простота** — HTTP API с JSON
    
- ✅ **Прозрачность** — агент может запросить свои права
    
- ✅ **Надёжность** — проверено на практике
  
  
  
  ### ⚡ Асинхронный режим (очередь задач)

Для долгих операций (запуск Python-скриптов, обработка больших файлов, команды с длительным выполнением) предусмотрен **двухступенчатый режим**.

#### Как это работает

text

Агент                                              Сервер
│  1. POST action=submit 
│  2. {task_id: "task_xxx"}  ◄────────── 
│  3. GET action=result&task_id ─────► 
│  4. {"status":"pending"}  ◄───── (ещё не готово)
│  ... повторяем шаг 3-4 ...   
│  5. {"status":"completed",   "result":"..."}  ◄────

#### Пример использования

bash

# Шаг 1: Отправить задание
curl -X POST "http://localhost:1010/api.php" \
  -H "Authorization: Bearer your_secret_token_here" \
  -H "Content-Type: application/json" \
  -d "{\"action\":\"submit\",\"query\":\"cmd:python D:\\\\script.py\"}"
#Ответ: {"status":"accepted","task_id":"task_6a2f779e9094e2.45354168"}
# Шаг 2: Получить результат (повторять с паузой 2-5 секунд)
curl "http://localhost:1010/api.php?action=result&task_id=task_6a2f779e9094e2.45354168" \
  -H "Authorization: Bearer your_secret_token_here"

#### Преимущества асинхронного режима

|Характеристика|Синхронный|Асинхронный|
|---|---|---|
|Время ожидания|До 30 сек|Не ограничено|
|Блокировка агента|Да|Нет|
|Подходит для|echo, dir, type|python, node, скачивание файлов|
|Получение результата|Сразу|По task_id|

#### Асинхронный режим в веб-интерфейсе

В `index.html` есть кнопка **"Submit Async"**, которая демонстрирует работу этого режима.


## 📁 Полная документация: BAT-файлы для управления системой

---

### 1. `SetupHermesUser.bat` — Создание ограниченного пользователя

**Назначение:** Создаёт пользователя `HermesAgent` с ограниченными правами, настраивает разрешения для папок и блокирует доступ к системным каталогам.

**Исходный код:**

batch

@echo off
title Hermes Agent - User Setup
color 0A
:: ============================================================
:: НАСТРОЙКИ ОГРАНИЧЕНИЙ (ИЗМЕНЯЙТЕ ЗДЕСЬ)
:: ============================================================
set HERMES_USER=HermesAgent
set HERMES_PASSWORD=123
:: Разрешённые для чтения папки
set ALLOWED_READ_DIRS=D:\PortableAI;D:\Projects
:: Разрешённые для записи папки
set ALLOWED_WRITE_DIRS=D:\PortableAI\_Write;D:\PortableAI\temp
:: Запрещённые команды
set BLOCKED_COMMANDS=format;diskpart;shutdown;taskkill;del /f;rmdir /s
:: ============================================================
:: ПРОВЕРКА ПРАВ АДМИНИСТРАТОРА
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
:: СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ
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
:: НАСТРОЙКА ПАПОК
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
:: ЗАПРЕТ СИСТЕМНЫХ ПАПОК
:: ============================================================
echo.
echo [*] Blocking system folders...
icacls "C:\Windows" /deny "%HERMES_USER%:R" /T 2>nul
icacls "C:\Program Files" /deny "%HERMES_USER%:R" /T 2>nul
icacls "C:\Program Files (x86)" /deny "%HERMES_USER%:R" /T 2>nul
:: ============================================================
:: ВЫВОД ИТОГОВОЙ ИНФОРМАЦИИ
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

---

### 2. `RunPHPServer.bat` — Запуск PHP-сервера

**Назначение:** Запускает встроенный PHP-сервер на порту 1010 для обработки запросов от агента.

**Исходный код:**

batch

@echo off
title Hermes PHP Server
color 0A
:: ============================================================
:: НАСТРОЙКИ
:: ============================================================
set BASE_DIR=D:\PortableAI
set PHP_DIR=%BASE_DIR%\PHP
set WEB_DIR=%BASE_DIR%\PHP\_Run
set PHP_EXE=%PHP_DIR%\php.exe
:: ============================================================
:: ПРОВЕРКА НАЛИЧИЯ PHP
:: ============================================================
if not exist "%PHP_EXE%" (
    echo [ERROR] PHP not found at %PHP_EXE%
    echo Please download PHP from https://windows.php.net/download/
    echo and extract to %PHP_DIR%\
    pause
    exit /b 1
)
:: ============================================================
:: ЗАПУСК СЕРВЕРА
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

---

### 3. `StopPHPServer.bat` — Остановка сервера

**Назначение:** Принудительно завершает все процессы PHP (останавливает сервер).

**Исходный код:**

batch

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

---

### 4. `TestServer.bat` — Тестирование сервера

**Назначение:** Выполняет набор тестов для проверки работоспособности сервера.

**Исходный код:**

batch

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

---

### 5. `StartHermesServer.bat` — Полный запуск (сервер + инициализация)

**Назначение:** Запускает сервер и выполняет инициализацию пользователя (запрос пароля).

**Исходный код:**

batch

@echo off
title Hermes Server - Full Start
color 0A
:: ============================================================
:: ЗАПУСК PHP-СЕРВЕРА В ОТДЕЛЬНОМ ОКНЕ
:: ============================================================
echo Starting PHP server...
start "PHP Server" cmd /k "cd /d D:\PortableAI\PHP\_Run && D:\PortableAI\PHP\php.exe -S localhost:1010"
:: Ожидание запуска
echo Waiting for server to start...
timeout /t 5 /nobreak >nul
:: ============================================================
:: ИНИЦИАЛИЗАЦИЯ И ТЕСТ
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

---

### 6. `RemoveHermesUser.bat` — Удаление пользователя (опционально)

**Назначение:** Удаляет ограниченного пользователя `HermesAgent` (при необходимости).

**Исходный код:**

batch

@echo off
title Remove Hermes User
color 0A
:: ============================================================
:: ПРОВЕРКА ПРАВ АДМИНИСТРАТОРА
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

---

## 📁 Структура файлов

text

D:\PortableAI\
├── PHP\
│   ├── php.exe
│   └── _Run\
│       ├── api.php
│       ├── index.html
│       └── tasks\
│       └── results\
├── SetupHermesUser.bat      ← Запустить от администратора (один раз)
├── RunPHPServer.bat          ← Запуск сервера
├── StopPHPServer.bat         ← Остановка сервера
├── TestServer.bat            ← Тестирование
├── StartHermesServer.bat     ← Полный запуск
└── RemoveHermesUser.bat      ← Удаление пользователя (опционально)

---

## 🚀 Порядок использования

|Шаг|Действие|Команда|
|---|---|---|
|1|Создать пользователя|`SetupHermesUser.bat` (от администратора)|
|2|Запустить сервер|`RunPHPServer.bat` или `StartHermesServer.bat`|
|3|Проверить работу|`TestServer.bat`|
|4|Остановить сервер|`StopPHPServer.bat`|

---

## 🔧 Примечания

1. **Первый запуск** `SetupHermesUser.bat` требует прав администратора
    
2. **При первом выполнении команды** может появиться окно PowerShell для ввода пароля `123`
    
3. **Все BAT-файлы должны быть в кодировке ANSI** (не UTF-8) для корректного отображения кириллицы
    
4. **Пользователь `HermesAgent`** создаётся один раз; при повторном запуске скрипт просто проверит его наличие
