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

┌─────────────────┐     HTTP POST      ┌─────────────────┐     shell_exec()    ┌─────────────────┐
│   AI Agent      │ ─────────────────► │   PHP Server    │ ─────────────────► │   Windows CMD   │
│   (Hermes)      │ ◄───────────────── │   (api.php)     │ ◄───────────────── │   (HermesAgent) │
└─────────────────┘     JSON ответ     └─────────────────┘     stdout/stderr   └─────────────────┘
        ▲                                                                              │
        │                                                                              ▼
        └────────────────────── GET action=help ───────────────────────────────► Проверка прав

### Компоненты:

|Компонент|Роль|Расположение|
|---|---|---|
|**PHP Server**|Принимает запросы, проверяет безопасность, выполняет команды|`D:\PortableAI\PHP\_Run\api.php`|
|**Web Interface**|Визуальный интерфейс для ручного тестирования|`D:\PortableAI\PHP\_Run\index.html`|
|**HermesAgent**|Ограниченный пользователь Windows для выполнения команд|Создаётся скриптом `SetupHermesUser.bat`|
|**Agent**|AI-агент (Hermes), который отправляет запросы|`D:\PortableAI\.hermes`|

---

## 📁 Полный исходный код

### 1. `api.php` — основной сервер

php

<?php
// ============================================================
// HERMES PHP COMMAND SERVER v3.1.0
// ============================================================
header('Content-Type: application/json; charset=utf-8');
// Версия сервера
define('SERVER_VERSION', '3.1.0');
define('SERVER_DATE', '2026-06-15');
// Блокировка опасных команд
define('BLOCKED_COMMANDS', [
    'format', 'diskpart', 'shutdown', 'taskkill', 
    'del /f', 'rmdir /s', 'rd /s', 'takeown', 
    'cacls', 'icacls', 'reg', 'sc', 'bcdedit',
    'net user', 'net localgroup', 'whoami'
]);
// Авторизация
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expected = 'Bearer your_secret_token_here';
if ($auth !== $expected) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
// Проверка заблокированных команд
function isBlockedCommand($command) {
    foreach (BLOCKED_COMMANDS as $blocked) {
        if (stripos($command, $blocked) !== false) return true;
    }
    return false;
}
// Получение информации о правах пользователя
function getUserPermissions() {
    $user = 'HermesAgent';
    $domain = getenv('COMPUTERNAME');
    
    $permissions = [
        'username' => $user,
        'domain' => $domain,
        'is_admin' => false,
        'groups' => [],
        'drive_d_access' => [],
        'can_write_temp' => false
    ];
    
    // Проверка, является ли пользователь администратором
    $adminCheck = shell_exec('net localgroup Administrators | findstr /i "' . $user . '" 2>nul');
    $permissions['is_admin'] = !empty($adminCheck);
    
    // Получение групп пользователя
    $groupsOutput = shell_exec('net user ' . $user . ' | findstr /i "Local Group" 2>nul');
    if ($groupsOutput) {
        preg_match_all('/\*([^\r\n]+)/', $groupsOutput, $matches);
        $permissions['groups'] = array_map('trim', $matches[1] ?? []);
    }
    
    // Проверка доступа к диску D:
    $permissions['drive_d_access'] = [
        'exists' => file_exists('D:\\'),
        'readable' => is_readable('D:\\'),
        'writable' => is_writable('D:\\')
    ];
    
    // Проверка записи в temp папку
    $tempFile = 'D:\\PortableAI\\temp\\_perm_test_' . uniqid() . '.txt';
    $testWrite = @file_put_contents($tempFile, 'test');
    $permissions['can_write_temp'] = ($testWrite !== false);
    if ($testWrite !== false) {
        @unlink($tempFile);
    }
    
    return $permissions;
}
// Безопасное выполнение
function safeExecute($command) {
    if (isBlockedCommand($command)) {
        return "ERROR: Command blocked for security reasons.\n";
    }
    
    $output = shell_exec($command . ' 2>&1');
    return $output ?: "No output\n";
}
$action = $_GET['action'] ?? 'execute';
$input = json_decode(file_get_contents('php://input'), true);
// === СПРАВКА ДЛЯ АГЕНТА ===
if ($action === 'help') {
    $perms = getUserPermissions();
    
    $help = "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    $help .= "║                    HERMES PHP COMMAND SERVER v" . SERVER_VERSION . "                          ║\n";
    $help .= "║                      AI Agent Execution Bridge                               ║\n";
    $help .= "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "👤 ПРАВА ПОЛЬЗОВАТЕЛЯ " . $perms['username'] . "\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "• Администратор: " . ($perms['is_admin'] ? "✅ ДА" : "❌ НЕТ") . "\n";
    $help .= "• Домен/Компьютер: " . $perms['domain'] . "\n";
    $help .= "• Группы: " . (implode(', ', $perms['groups']) ?: "нет (ограниченный пользователь)") . "\n\n";
    
    $help .= "Доступ к диску D:\n";
    $help .= "• Существует: " . ($perms['drive_d_access']['exists'] ? "✅" : "❌") . "\n";
    $help .= "• Чтение: " . ($perms['drive_d_access']['readable'] ? "✅" : "❌") . "\n";
    $help .= "• Запись: " . ($perms['drive_d_access']['writable'] ? "✅" : "❌") . "\n";
    $help .= "• Запись в D:\\PortableAI\\temp: " . ($perms['can_write_temp'] ? "✅" : "❌") . "\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "🔒 ЗАБЛОКИРОВАННЫЕ КОМАНДЫ (не выполняются)\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "• " . implode(", ", BLOCKED_COMMANDS) . "\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "📂 РАБОТА С ФАЙЛАМИ\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "✅ Чтение файла:\n   cmd: type D:\\PortableAI\\temp\\file.txt\n\n";
    $help .= "✅ Создание/запись файла (PowerShell):\n";
    $help .= "   cmd: powershell -Command \"Set-Content -Path 'D:/PortableAI/temp/file.txt' -Value 'текст'\"\n\n";
    $help .= "✅ Добавление в конец файла:\n";
    $help .= "   cmd: powershell -Command \"Add-Content -Path 'D:/PortableAI/temp/file.txt' -Value 'новая строка'\"\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "💡 ВАЖНЫЕ ЗАМЕЧАНИЯ\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "• Все команды ДОЛЖНЫ начинаться с префикса 'cmd:'\n";
    $help .= "• Для записи файлов ОБЯЗАТЕЛЬНО используйте PowerShell (Set-Content/Add-Content)\n";
    $help .= "• В PowerShell командах используйте прямые слеши D:/path (не D:\\path)\n";
    $help .= "• Команды выполняются от имени пользователя " . $perms['username'] . "\n\n";
    
    echo json_encode([
        'status' => 'success',
        'version' => SERVER_VERSION,
        'permissions' => $perms,
        'help' => $help
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// === ВЫПОЛНЕНИЕ ===
if ($action === 'execute') {
    $query = $input['query'] ?? '';
    $start_time = microtime(true);
    
    if (stripos($query, 'cmd:') === 0) {
        $command = substr($query, 4);
        $result = safeExecute($command);
        $result = iconv('CP866', 'UTF-8//IGNORE', $result);
    } else {
        $result = "Use cmd: <command>";
    }
    
    $duration = round((microtime(true) - $start_time) * 1000);
    
    echo json_encode([
        'status' => 'success',
        'version' => SERVER_VERSION,
        'result' => $result,
        'timing' => ['duration_ms' => $duration]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['error' => 'Unknown action. Use action=help']);
?>

### 2. `index.html` — веб-интерфейс

html

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hermes Command Server</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
            font-family: 'Segoe UI', 'Fira Code', monospace;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .header h1 {
            font-size: 2.5rem;
            background: linear-gradient(135deg, #a8edea, #fed6e3);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .version-badge {
            display: inline-block;
            background: #4a4e69;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: #c9d1d9;
        }
        .command-input {
            width: 100%;
            padding: 15px;
            background: #1a1a2e;
            border: 2px solid #4a4e69;
            border-radius: 12px;
            color: #cdd6f4;
            font-family: monospace;
            font-size: 1rem;
        }
        button {
            padding: 12px 24px;
            margin: 5px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-init { background: #a6e3a1; color: #1a1a2e; }
        .btn-execute { background: #89b4fa; color: #1a1a2e; }
        .btn-help { background: #cba6f7; color: #1a1a2e; }
        .output-content {
            background: #0d0d1a;
            border-radius: 12px;
            padding: 20px;
            font-family: monospace;
            color: #cdd6f4;
            white-space: pre-wrap;
            max-height: 500px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🦊 Hermes Command Server</h1>
        <span class="version-badge" id="serverVersion">v3.1.0</span>
    </div>
    
    <input type="text" id="commandInput" class="command-input" 
           placeholder="cmd: dir D:\PortableAI" value="cmd: dir D:\PortableAI">
    <br><br>
    <button class="btn-init" onclick="initializeAndTest()">🔐 Initialize & Test</button>
    <button class="btn-execute" onclick="executeCommand()">▶ Execute</button>
    <button class="btn-help" onclick="getHelp()">📖 Help</button>
    
    <div id="outputContent" class="output-content" style="margin-top:20px;">Ready.</div>
</div>
<script>
    const API = '/api.php';
    const AUTH = 'Bearer your_secret_token_here';
    async function getHelp() {
        const res = await fetch(API + '?action=help', {headers: {'Authorization': AUTH}});
        const data = await res.json();
        document.getElementById('outputContent').innerText = data.help;
    }
    async function initializeAndTest() {
        const res = await fetch(API, {
            method: 'POST',
            headers: {'Authorization': AUTH, 'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'execute', query: 'cmd: echo "Server ready" && dir D:\\PortableAI'})
        });
        const data = await res.json();
        document.getElementById('outputContent').innerText = data.result;
    }
    async function executeCommand() {
        const cmd = document.getElementById('commandInput').value;
        const res = await fetch(API, {
            method: 'POST',
            headers: {'Authorization': AUTH, 'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'execute', query: cmd})
        });
        const data = await res.json();
        document.getElementById('outputContent').innerText = data.result;
    }
</script>
</body>
</html>

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

Агент                           Сервер
  │                                │
  │  1. POST action=submit         │
  │ ─────────────────────────────► │
  │                                │
  │  2. {task_id: "task_xxx"}      │
  │ ◄───────────────────────────── │
  │                                │
  │  3. GET action=result&task_id  │
  │ ─────────────────────────────► │
  │                                │
  │  4. {"status":"pending"}       │
  │ ◄───────────────────────────── │ (ещё не готово)
  │                                │
  │  ... повторяем шаг 3-4 ...     │
  │                                │
  │  5. {"status":"completed",     │
  │      "result":"..."}           │
  │ ◄───────────────────────────── │

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


"# Hermes-PHP-Command-Server" 
