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

// === ЧТЕНИЕ КАТАЛОГОВ ИЗ Doc.md ДЛЯ ОТОБРАЖЕНИЯ В СПРАВКЕ ===
function getDirectoriesFromDoc() {
    $docFile = __DIR__ . '/Doc.md';
    if (!file_exists($docFile)) return [];
    
    $content = file_get_contents($docFile);
    $lines = explode("\n", $content);
    $directories = [];
    $inSection = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '# Каталоги') !== false) {
            $inSection = true;
            continue;
        }
        if ($inSection && preg_match('/^[A-Z]:\\\\/', $line)) {
            $directories[] = $line;
        }
        // Останавливаемся при следующем заголовке
        if ($inSection && strpos($line, '#') === 0 && strpos($line, 'Каталоги') === false) {
            break;
        }
    }
    return $directories;
}

// Получение прав для каталогов
function checkDirectoryPermissions($dir) {
    $user = 'HermesAgent';
    if (!file_exists($dir)) return '❌ Не существует';
    
    // Проверка чтения
    $readTest = @file_get_contents($dir . '\\test.txt');
    $canRead = ($readTest !== false);
    
    // Проверка записи
    $testFile = $dir . '\\_perm_test_' . uniqid() . '.txt';
    $writeTest = @file_put_contents($testFile, 'test');
    $canWrite = ($writeTest !== false);
    if ($canWrite) @unlink($testFile);
    
    if ($canRead && $canWrite) return '✅ Чтение и запись';
    if ($canRead) return '✅ Только чтение';
    return '❌ Нет доступа';
}


// Упрощённое преобразование кодировки
function convertEncoding($output) {
    if (empty($output)) return '';
    $output = preg_replace('/^\xEF\xBB\xBF/', '', $output);
    $converted = iconv('CP866', 'UTF-8//IGNORE//TRANSLIT', $output);
    if ($converted !== false && $converted !== $output) return $converted;
    $converted = iconv('Windows-1251', 'UTF-8//IGNORE//TRANSLIT', $output);
    if ($converted !== false && $converted !== $output) return $converted;
    return $output;
}

// Проверка заблокированных команд
function isBlockedCommand($command) {
    foreach (BLOCKED_COMMANDS as $blocked) {
        if (stripos($command, $blocked) !== false) return true;
    }
    return false;
}

// Функция для получения реальных прав через icacls
function getFolderPermissions($path, $user) {
    if (!file_exists($path)) return '❌ Папка не существует';
    $output = shell_exec('icacls "' . $path . '" 2>nul | findstr /i "' . $user . '"');
    if (empty($output)) return '❌ Нет доступа';
    if (strpos($output, ':(R)') !== false || strpos($output, ':R') !== false) return '✅ Только чтение';
    if (strpos($output, ':(RW)') !== false || strpos($output, ':RW') !== false) return '✅ Чтение и запись';
    if (strpos($output, ':(F)') !== false) return '✅ Полный доступ';
    return '⚠️ Частичный доступ';
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
        'can_write_temp' => false,
        'folder_rights' => []
    ];

    $adminCheck = shell_exec('net localgroup Administrators | findstr /i "' . $user . '" 2>nul');
    $permissions['is_admin'] = !empty($adminCheck);
    
    $groupsOutput = shell_exec('net user ' . $user . ' | findstr /i "Local Group" 2>nul');
    if ($groupsOutput) {
        preg_match_all('/\*([^\r\n]+)/', $groupsOutput, $matches);
        $permissions['groups'] = array_map('trim', $matches[1] ?? []);
    }
    
    $permissions['drive_d_access'] = [
        'exists' => file_exists('D:\\'),
        'readable' => is_readable('D:\\'),
        'writable' => is_writable('D:\\')
    ];
    
    $tempFile = 'D:\\PortableAI\\temp\\_perm_test_' . uniqid() . '.txt';
    $testWrite = @file_put_contents($tempFile, 'test');
    $permissions['can_write_temp'] = ($testWrite !== false);
    if ($testWrite !== false) @unlink($tempFile);
    
    $permissions['folder_rights'] = [
        'D:\\PortableAI' => getFolderPermissions('D:\\PortableAI', $user),
        'D:\\PortableAI\\DostupHermes' => getFolderPermissions('D:\\PortableAI\\DostupHermes', $user),
        'D:\\PortableAI\\temp' => getFolderPermissions('D:\\PortableAI\\temp', $user),
        'C:\\Windows' => getFolderPermissions('C:\\Windows', $user)
    ];
    
    return $permissions;
}

// Безопасное выполнение
function safeExecute($command) {
    if (isBlockedCommand($command)) return "ERROR: Command blocked for security reasons.\n";
    $output = shell_exec($command . ' 2>&1');
    $output = convertEncoding($output);
    return $output ?: "No output\n";
}

$action = $_GET['action'] ?? 'execute';
$input = json_decode(file_get_contents('php://input'), true);

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

// === СПРАВКА ДЛЯ АГЕНТА ===
if ($action === 'help') {
    $perms = getUserPermissions();
    
    $help = "╔══════════════════════════════════════════════════════════════════════════════════════════════════════════╗\n";
    $help .= "║                         HERMES PHP COMMAND SERVER v" . SERVER_VERSION . " - AI AGENT EXECUTION BRIDGE                      ║\n";
    $help .= "╚══════════════════════════════════════════════════════════════════════════════════════════════════════════╝\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "👤 ПРАВА ПОЛЬЗОВАТЕЛЯ " . $perms['username'] . "\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "• Администратор: " . ($perms['is_admin'] ? "✅ ДА" : "❌ НЕТ") . "\n";
    $help .= "• Домен/Компьютер: " . $perms['domain'] . "\n";
    $help .= "• Группы: " . (implode(', ', $perms['groups']) ?: "нет (ограниченный пользователь)") . "\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "📂 ПРОВЕРКА ПРАВ ДОСТУПА К КАТАЛОГАМ (из Doc.md)\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════\n";

	$directories = getDirectoriesFromDoc();
	if (empty($directories)) {
	    $help .= "Нет каталогов для проверки. Добавьте их в Doc.md в разделе 'Каталоги для проверки прав'\n\n";
	} else {
	//    foreach ($directories as $dir) {
	//        $rights = checkDirectoryPermissions($dir);
	//        $help .= "• $dir: $rights\n";
	//    }
	foreach ($directories as $dir) {
    	$rights = checkDirectoryPermissions($dir);
    	// Показываем только каталоги с доступом (не "Нет доступа" и не "Не существует")
    		if (strpos($rights, '✅') !== false) {
        		$help .= "• $dir: $rights\n";
        		$hasAccessible = true;
   	 	}
	}
     }
    $help .= "\n";

    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "📌 ОСНОВНЫЕ ПРАВИЛА\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "1. Все команды ДОЛЖНЫ начинаться с префикса 'cmd:'\n";
    $help .= "2. Пример: cmd: dir D:\\PortableAI\n";
    $help .= "3. Без префикса 'cmd:' сервер вернёт ошибку 'Use cmd: <command>'\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "🔒 ЗАБЛОКИРОВАННЫЕ КОМАНДЫ (не выполняются)\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "• format, diskpart, shutdown, taskkill, del /f, rmdir /s\n";
    $help .= "• takeown, cacls, icacls, reg, sc, bcdedit\n";
    $help .= "• net user, net localgroup, whoami\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "🔧 ОБЩИЕ КОМАНДЫ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "• cmd: echo Hello World\n";
    $help .= "• cmd: systeminfo | findstr 'OS'\n";
    $help .= "• cmd: ipconfig\n\n";

    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "⚡ РЕЖИМЫ ВЫПОЛНЕНИЯ КОМАНД\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
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
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "📂 РАБОТА С ФАЙЛАМИ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "✅ Чтение файла (простой способ):\n";
    $help .= "   cmd: type D:\\PortableAI\\temp\\file.txt\n\n";
    $help .= "✅ Чтение файла с правильной кодировкой UTF-8:\n";
    $help .= "   cmd: powershell -Command \"Get-Content -Path 'D:/PortableAI/temp/file.txt' -Encoding UTF8\"\n\n";
    $help .= "✅ Создание/запись файла (через PowerShell):\n";
    $help .= "   cmd: powershell -Command \"Set-Content -Path 'D:/PortableAI/temp/file.txt' -Value 'текст'\"\n\n";
    $help .= "✅ Добавление в конец файла:\n";
    $help .= "   cmd: powershell -Command \"Add-Content -Path 'D:/PortableAI/temp/file.txt' -Value 'новая строка'\"\n\n";
    $help .= "✅ Список файлов в папке:\n";
    $help .= "   cmd: dir D:\\PortableAI\n\n";
    $help .= "✅ Список файлов через PowerShell (подробный):\n";
    $help .= "   cmd: powershell -Command \"Get-ChildItem D:/PortableAI/temp\"\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "🔧 ОБЩИЕ КОМАНДЫ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "• cmd: echo Hello World\n";
    $help .= "• cmd: systeminfo | findstr 'OS'\n";
    $help .= "• cmd: ipconfig\n";
    $help .= "• cmd: curl -s 'https://api.duckduckgo.com/?q=hello&format=json'\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "🐍 ЗАПУСК ПРОГРАММ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "• cmd: python D:\\PortableAI\\script.py\n";
    $help .= "• cmd: node D:\\server.js\n";
    $help .= "• cmd: notepad.exe D:\\file.txt\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "💡 ВАЖНЫЕ ЗАМЕЧАНИЯ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "• Все команды ДОЛЖНЫ начинаться с префикса 'cmd:'\n";
    $help .= "• Для чтения файлов с русским текстом используйте PowerShell Get-Content -Encoding UTF8\n";
    $help .= "• Для записи файлов ОБЯЗАТЕЛЬНО используйте PowerShell (Set-Content/Add-Content)\n";
    $help .= "• В PowerShell командах используйте прямые слеши D:/path (не D:\\path)\n";
    $help .= "• Команды выполняются от имени пользователя " . $perms['username'] . "\n";
    $help .= "• Асинхронные задания автоматически удаляются после получения результата\n";
    $help .= "• Таймаут синхронных команд: 30 секунд (настраивается в коде)\n\n";
    
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "🌐 ПОИСК В ИНТЕРНЕТЕ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "⚠️ ВНИМАНИЕ: Обычный веб-поиск (web_search, browser_*) НЕ РАБОТАЕТ!\n";
    $help .= "⚠️ Агент НЕ МОЖЕТ использовать web_search или browser_* для поиска в интернете.\n";
    $help .= "⚠️ Для получения информации из интернета используйте следующие методы:\n\n";
    $help .= "⚠️ Для поиска информации используйте curl или PowerShell:\n\n";
    $help .= "• DuckDuckGo: cmd: curl -s 'https://api.duckduckgo.com/?q=AI&format=json' | findstr AbstractText\n";
    $help .= "• Wikipedia: cmd: curl -s 'https://ru.wikipedia.org/api/rest_v1/page/summary/Искусственный_интеллект' | findstr extract\n";
    $help .= "• Погода: cmd: curl -s 'https://wttr.in/Moscow?format=3'\n";
    $help .= "• Время: cmd: curl -s 'https://worldtimeapi.org/api/timezone/Europe/Moscow'\n\n";

    $help .= "📌 1. Через curl (прямые HTTP запросы):\n";
    $help .= "   cmd: curl -s 'https://api.duckduckgo.com/?q=запрос&format=json'\n";
    $help .= "   cmd: curl -s 'https://ru.wikipedia.org/api/rest_v1/page/summary/Заголовок'\n";
    $help .= "   cmd: curl -s 'https://www.google.com/search?q=запрос'\n\n";

    $help .= "📌 2. Через PowerShell (Invoke-WebRequest):\n";
    $help .= "   cmd: powershell -Command \"(Invoke-WebRequest -Uri 'https://api.duckduckgo.com/?q=AI&format=json').Content\"\n\n";

    $help .= "📌 3. Поиск через Wikipedia API (работает на русском):\n";
    $help .= "   cmd: curl -s 'https://ru.wikipedia.org/api/rest_v1/page/summary/Искусственный_интеллект' | findstr extract\n\n";

    $help .= "📌 4. Поиск через DuckDuckGo API:\n";
    $help .= "   cmd: curl -s 'https://api.duckduckgo.com/?q=what+is+AI&format=json&no_html=1' | findstr AbstractText\n\n";

    $help .= "📌 5. Если нужно открыть страницу в браузере (только для просмотра человеком):\n";
    $help .= "   cmd: start https://www.google.com\n\n";

    $help .= "💡 ПРИМЕРЫ ПОИСКОВЫХ ЗАПРОСОВ:\n";
    $help .= "• Новости: curl -s 'https://newsapi.org/v2/top-headlines?country=ru&apiKey=YOUR_KEY'\n";
    $help .= "• Погода: curl -s 'https://wttr.in/Moscow?format=3'\n";
    $help .= "• Википедия: curl -s 'https://ru.wikipedia.org/api/rest_v1/page/summary/Москва' | findstr extract\n";
    $help .= "• Текущее время: cmd: curl -s 'https://worldtimeapi.org/api/timezone/Europe/Moscow'\n\n";


    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "📖 ПОЛУЧИТЬ ЭТУ СПРАВКУ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "GET /api.php?action=help\n";
    
    echo json_encode([
        'status' => 'success',
        'version' => SERVER_VERSION,
        'permissions' => $perms,
        'help' => $help
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Unknown action. Use action=help']);
?>
