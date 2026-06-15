<?php
// ============================================================
// HERMES PHP COMMAND SERVER v3.2.0
// ============================================================
header('Content-Type: application/json; charset=utf-8');

// Версия сервера
define('SERVER_VERSION', '3.2.0');
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

// Упрощённое преобразование кодировки (без mbstring)
function convertEncoding($output) {
    if (empty($output)) return '';
    
    // Удаляем BOM если есть
    $output = preg_replace('/^\xEF\xBB\xBF/', '', $output);
    
    // Проверяем наличие русских символов в неправильной кодировке
    // Пробуем преобразовать из CP866 (консоль Windows)
    $converted = iconv('CP866', 'UTF-8//IGNORE//TRANSLIT', $output);
    if ($converted !== false && $converted !== $output) {
        return $converted;
    }
    
    // Пробуем из Windows-1251
    $converted = iconv('Windows-1251', 'UTF-8//IGNORE//TRANSLIT', $output);
    if ($converted !== false && $converted !== $output) {
        return $converted;
    }
    
    return $output;
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
        'drive_d_access' => [],
        'can_write_temp' => false
    ];
    
    $adminCheck = shell_exec('net localgroup Administrators | findstr /i "' . $user . '" 2>nul');
    $permissions['is_admin'] = !empty($adminCheck);
    
    $permissions['drive_d_access'] = [
        'exists' => file_exists('D:\\'),
        'readable' => is_readable('D:\\'),
        'writable' => is_writable('D:\\')
    ];
    
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
    
    // Выполняем команду
    $output = shell_exec($command . ' 2>&1');
    
    // Преобразуем кодировку
    $output = convertEncoding($output);
    
    return $output ?: "No output\n";
}

$action = $_GET['action'] ?? 'execute';
$input = json_decode(file_get_contents('php://input'), true);

// === СПРАВКА ===
if ($action === 'help') {
    $perms = getUserPermissions();
    
    $help = "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    $help .= "║                    HERMES PHP COMMAND SERVER v" . SERVER_VERSION . "                          ║\n";
    $help .= "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
    
    $help .= "👤 ПРАВА ПОЛЬЗОВАТЕЛЯ " . $perms['username'] . "\n";
    $help .= "• Администратор: " . ($perms['is_admin'] ? "✅ ДА" : "❌ НЕТ") . "\n\n";
    
    $help .= "Доступ к диску D:\n";
    $help .= "• Чтение: " . ($perms['drive_d_access']['readable'] ? "✅" : "❌") . "\n";
    $help .= "• Запись: " . ($perms['drive_d_access']['writable'] ? "✅" : "❌") . "\n\n";
    
    $help .= "РЕЖИМЫ ВЫПОЛНЕНИЯ:\n";
    $help .= "• Синхронный: POST {\"action\":\"execute\",\"query\":\"cmd: команда\"}\n";
    $help .= "• Асинхронный: POST action=submit, GET action=result&task_id=ID\n\n";
    
    $help .= "РАБОТА С ФАЙЛАМИ:\n";
    $help .= "• Чтение: cmd: type D:\\path\\file.txt\n";
    $help .= "• Запись: cmd: powershell -Command \"Set-Content -Path 'D:/path/file.txt' -Value 'текст'\"\n";
    
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

// === АСИНХРОННЫЙ РЕЖИМ ===
if ($action === 'submit') {
    $query = $input['query'] ?? '';
    $task_id = uniqid('task_', true);
    $tasks_dir = __DIR__ . '/tasks';
    $results_dir = __DIR__ . '/results';
    
    if (!file_exists($tasks_dir)) mkdir($tasks_dir, 0777, true);
    if (!file_exists($results_dir)) mkdir($results_dir, 0777, true);
    
    file_put_contents($tasks_dir . '/' . $task_id . '.json', json_encode([
        'id' => $task_id,
        'query' => $query,
        'created_at' => time()
    ]));
    
    echo json_encode([
        'status' => 'accepted',
        'task_id' => $task_id,
        'message' => 'Task accepted'
    ]);
    exit;
}

if ($action === 'result') {
    $task_id = $_GET['task_id'] ?? '';
    $results_dir = __DIR__ . '/results';
    $result_file = $results_dir . '/' . $task_id . '.json';
    
    if (file_exists($result_file)) {
        $data = json_decode(file_get_contents($result_file), true);
        unlink($result_file);
        echo json_encode([
            'status' => 'completed',
            'result' => $data['result'],
            'timing' => $data['timing']
        ]);
    } else {
        echo json_encode([
            'status' => 'pending',
            'task_id' => $task_id
        ]);
    }
    exit;
}

// === ВОРКЕР ===
if (php_sapi_name() === 'cli' && $action === 'worker') {
    $tasks_dir = __DIR__ . '/tasks';
    $results_dir = __DIR__ . '/results';
    
    while (true) {
        $task_files = glob($tasks_dir . '/*.json');
        foreach ($task_files as $task_file) {
            $task = json_decode(file_get_contents($task_file), true);
            $task_id = $task['id'];
            $query = $task['query'];
            $start_time = microtime(true);
            
            if (stripos($query, 'cmd:') === 0) {
                $command = substr($query, 4);
                $result = safeExecute($command);
            } else {
                $result = "Use cmd: <command>";
            }
            
            $duration = round((microtime(true) - $start_time) * 1000);
            
            file_put_contents($results_dir . '/' . $task_id . '.json', json_encode([
                'result' => $result,
                'timing' => ['duration_ms' => $duration]
            ]));
            unlink($task_file);
        }
        sleep(2);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action. Use action=help']);
?>
