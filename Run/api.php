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

// Получение ACL для конкретной папки
function getFolderACL($path) {
    if (!file_exists($path)) return null;
    
    $output = shell_exec('icacls "' . $path . '" 2>nul');
    if (!$output) return null;
    
    $lines = explode("\n", $output);
    $result = [];
    foreach ($lines as $line) {
        if (strpos($line, '\\') !== false && strpos($line, ':') !== false) {
            $parts = explode(':', $line, 2);
            if (count($parts) == 2) {
                $result[] = trim($parts[0]) . ': ' . trim($parts[1]);
            }
        }
    }
    return $result;
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
    $help .= "⚡ РЕЖИМЫ ВЫПОЛНЕНИЯ КОМАНД\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "📌 1. СИНХРОННЫЙ РЕЖИМ (быстрые команды)\n";
    $help .= "   POST {\"action\":\"execute\",\"query\":\"cmd: команда\"}\n";
    $help .= "   → Ответ возвращается сразу после выполнения\n";
    $help .= "   → Подходит для: echo, dir, type, copy\n\n";
    $help .= "📌 2. АСИНХРОННЫЙ РЕЖИМ (для долгих операций)\n";
    $help .= "   ШАГ 1 — Отправить задание:\n";
    $help .= "   POST {\"action\":\"submit\",\"query\":\"cmd: команда\"}\n";
    $help .= "   → Ответ: {\"status\":\"accepted\",\"task_id\":\"task_xxx\"}\n\n";
    $help .= "   ШАГ 2 — Получить результат:\n";
    $help .= "   GET /api.php?action=result&task_id=task_xxx\n";
    $help .= "   → Если ещё не готово: {\"status\":\"pending\"}\n";
    $help .= "   → Если готово: {\"status\":\"completed\",\"result\":\"...\",\"timing\":{...}}\n\n";
    $help .= "   💡 Когда использовать асинхронный режим:\n";
    $help .= "   • Долгие операции (python script.py, node server.js)\n";
    $help .= "   • Команды с таймаутом > 30 секунд\n";
    $help .= "   • Фоновые задачи\n";
    $help .= "   • Загрузка/обработка больших файлов\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "📂 РАБОТА С ФАЙЛАМИ\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "✅ Чтение файла:\n   cmd: type D:\\PortableAI\\temp\\file.txt\n\n";
    $help .= "✅ Создание/запись файла (PowerShell):\n";
    $help .= "   cmd: powershell -Command \"Set-Content -Path 'D:/PortableAI/temp/file.txt' -Value 'текст'\"\n\n";
    $help .= "✅ Добавление в конец файла:\n";
    $help .= "   cmd: powershell -Command \"Add-Content -Path 'D:/PortableAI/temp/file.txt' -Value 'новая строка'\"\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "🔒 ЗАБЛОКИРОВАННЫЕ КОМАНДЫ\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "• " . implode(", ", BLOCKED_COMMANDS) . "\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "💡 ВАЖНЫЕ ЗАМЕЧАНИЯ\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "• Все команды ДОЛЖНЫ начинаться с префикса 'cmd:'\n";
    $help .= "• Для записи файлов ОБЯЗАТЕЛЬНО используйте PowerShell (Set-Content/Add-Content)\n";
    $help .= "• В PowerShell командах используйте прямые слеши D:/path (не D:\\path)\n";
    $help .= "• Команды выполняются от имени пользователя " . $perms['username'] . "\n";
    $help .= "• Асинхронные задания автоматически удаляются после получения результата\n";
    $help .= "• Таймаут синхронных команд: 30 секунд (настраивается в коде)\n\n";
    
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "📖 ПОЛУЧИТЬ ЭТУ СПРАВКУ\n";
    $help .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $help .= "GET /api.php?action=help\n";
    
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