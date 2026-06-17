<?php
// ============================================================
// FUNC.PHP - ВСЕ ФУНКЦИИ ДЛЯ HERMES COMMAND SERVER v3.2.1
// ============================================================
// ============================================================
// ФУНКЦИЯ: ОБРАБОТКА PHP-КОМАНД ОТ АГЕНТА
// ============================================================

function handlePHPCommand($query) {
    global $logFile;
    $logFile = getLogFile();
    // Проверяем, начинается ли команда с php:
    if (stripos($query, 'php:') !== 0) {
        return null;
    }
    
    // Извлекаем PHP-код
    $code = substr($query, 4);
    $code = ltrim($code);
    
    file_put_contents($logFile, "  [PHP] ПОЛУЧЕН КОД: $code\n", FILE_APPEND);
    
    return executeSafePHP($code);
}

// ============================================================
// ФУНКЦИЯ: ВЫПОЛНЕНИЕ PHP-СКРИПТА (БЕЗОПАСНЫЙ РЕЖИМ)
// ============================================================

function executeSafePHP($code) {
    global $logFile;
    $logFile = getLogFile();
    // Проверяем код на опасные функции
    $forbidden = [
        'shell_exec', 'exec', 'system', 'passthru', 'popen', 'proc_open',
        'eval', 'assert', 'include', 'require', 'dl', 'putenv', 'ini_set',
        'chmod', 'chown', 'chgrp', 'umask', 'curl_', 'ftp_', 'socket_',
        'base64_decode', 'gz', 'bz', 'zip', 'preg_replace',
        '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SESSION', '$_FILES',
        '$_SERVER', '$_ENV', '$GLOBALS'
    ];
    
    foreach ($forbidden as $pattern) {
        if (stripos($code, $pattern) !== false) {
            file_put_contents($logFile, "  [PHP] ОБНАРУЖЕНА ЗАПРЕЩЕННАЯ ФУНКЦИЯ: $pattern\n", FILE_APPEND);
            return "⛔ Ошибка безопасности: обнаружена запрещенная функция '$pattern'";
        }
    }
    
    // Проверяем, что код работает только с разрешенными путями
    preg_match_all('/[A-Za-z]:[\/\\\\][^\s"\'&|<>]+/', $code, $matches);
    foreach ($matches[0] as $path) {
        if (!isPathAllowedForUser($path)) {
            file_put_contents($logFile, "  [PHP] ДОСТУП ЗАПРЕЩЕН К ПУТИ: $path\n", FILE_APPEND);
            return "⛔ Ошибка безопасности: доступ к '$path' запрещен";
        }
    }
    
    // Создаем временный файл для выполнения
    $tempFile = TEMP_DIR . '\\php_' . uniqid() . '.php';
    file_put_contents($tempFile, '<?php ' . $code . ' ?>');
    
    file_put_contents($logFile, "  [PHP] ВЫПОЛНЕНИЕ: $tempFile\n", FILE_APPEND);
    
    // Выполняем через PHP CLI от имени HermesAgent
    $runAsPath = getRunAsPath();
    $user = RUNAS_USER;
    $password = RUNAS_PASSWORD;
    
    $fullCommand = sprintf(
        '"%s" %s %s "%s" -f "%s" . 8 2>&1',
        $runAsPath,
        $user,
        $password,
        PHP_PATH,
        $tempFile
    );
    
    $output = shell_exec($fullCommand);
    $output = convertEncoding($output);
    $output = cleanOutput($output);
    
    // Удаляем временный файл
    @unlink($tempFile);
    
    return $output ?: "✅ PHP-скрипт выполнен (пустой вывод)\n";
}


// ============================================================
// ФУНКЦИЯ: ЗАГРУЗКА КОНФИГУРАЦИИ ИЗ INI ФАЙЛА
// ============================================================

function loadConfig() {
    $configFile = __DIR__ . '/config.ini';
    
    if (!file_exists($configFile)) {
        // Если файла нет, создаем с настройками по умолчанию
        createDefaultConfig($configFile);
    }
    
    $config = parse_ini_file($configFile, true, INI_SCANNER_TYPED);
    
    if ($config === false) {
        die("❌ Ошибка: не удалось прочитать config.ini");
    }
    
    return $config;
}

function createDefaultConfig($path) {
    $default = '; ============================================================
; HERMES PHP COMMAND SERVER - КОНФИГУРАЦИЯ
; ============================================================

[paths]
php_path = php.exe
runas_path = D:\PortableAI\PHP\_Run\RunAsCPP.exe
temp_dir = D:\PortableAI\temp
log_file = D:\PortableAI\PHP\_Run\server.log

[user]
username = HermesAgent
password = 123

[allowed_dirs]
dirs = D:\PortableAI\DostupHermes, D:\PortableAI\temp, D:\PortableAI\PHP\_Run, D:\PortableAI\PHP

[server]
version = 3.2.1
date = 2026-06-16

[blocked_commands]
commands = format, diskpart, shutdown, taskkill, del /f, rmdir /s, rd /s, takeown, cacls, icacls, reg, sc, bcdedit, net user, net localgroup, whoami, attrib, cipher, compact, convert, fsutil, wmic, systeminfo, netsh, start, open, explorer, wsl, bash
';
    file_put_contents($path, $default);
}

// ============================================================
// ГЛОБАЛЬНАЯ ПЕРЕМЕННАЯ ДЛЯ КОНФИГА
// ============================================================

$CONFIG = loadConfig();

// ============================================================
// ОПРЕДЕЛЕНИЕ КОНСТАНТ ИЗ КОНФИГА
// ============================================================

// Пути
define('PHP_PATH', $CONFIG['paths']['php_path'] ?? 'php.exe');
define('RUNAS_PATH', $CONFIG['paths']['runas_path'] ?? 'RunAsCPP.exe');
define('TEMP_DIR', rtrim($CONFIG['paths']['temp_dir'] ?? 'D:\\PortableAI\\temp', '\\'));
define('LOG_FILE', $CONFIG['paths']['log_file'] ?? __DIR__ . '/server.log');

// Пользователь
define('RUNAS_USER', $CONFIG['user']['username'] ?? 'HermesAgent');
define('RUNAS_PASSWORD', $CONFIG['user']['password'] ?? '123');

// Разрешенные каталоги
$allowedDirs = array_map('trim', explode(',', $CONFIG['allowed_dirs']['dirs'] ?? ''));
define('ALLOWED_DIRS', $allowedDirs);

// Версия
define('SERVER_VERSION', $CONFIG['server']['version'] ?? '3.2.1');
define('SERVER_DATE', $CONFIG['server']['date'] ?? date('Y-m-d'));

// Заблокированные команды
$blockedCommands = array_map('trim', explode(',', $CONFIG['blocked_commands']['commands'] ?? ''));
define('BLOCKED_COMMANDS', $blockedCommands);

// ============================================================
// ДОПОЛНИТЕЛЬНЫЕ КОНСТАНТЫ
// ============================================================

define('FORBIDDEN_PATTERNS', [
    '..\\', '../', '..\\\\',
    'C:\\Windows', 'C:\\Program Files',
    'C:\\Users', 'C:\\System32',
    'D:\\System Volume Information',
    'D:\\$Recycle.Bin'
]);

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ РАБОТЫ С КОНФИГОМ
// ============================================================

function getLogFile() {
    return LOG_FILE;
}

function getPhpPath() {
    return PHP_PATH;
}

function getRunAsPath() {
    return RUNAS_PATH;
}

function getTempDir() {
    $dir = TEMP_DIR;
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

// ============================================================
// ОСТАЛЬНЫЕ ФУНКЦИИ (ваши существующие)
// ============================================================

// ... здесь весь ваш код (getDirectoriesFromDoc, checkDirectoryPermissions, 
// getUserPermissions, convertEncoding, isBlockedCommand, 
// extractPathsFromCommand, isPathAllowedForUser, cleanPath, 
// cleanOutput, fixAgentPaths, parseCommand, parseSingleCommand, 
// rebuildCommand, smartFixPaths, analyzePathEscaping, 
// smartErrorResponse, sendError, sendEscapingAdvice, 
// safeDeleteFile, safeExecute, executePowerShellDirect, 
// handlePHPCommand, executeSafePHP, handleScriptCommand, 
// decodeText, generateHelp ...)



// ============================================================
// ФУНКЦИЯ: ДЕКОДИРОВАНИЕ ТЕКСТА ОТ АГЕНТА
// ============================================================

function decodeAgentText($text) {
    // 1. Проверяем base64 (если текст в формате <b64>...</b64>)
    if (preg_match('/^<b64>(.*)<\/b64>$/s', trim($text), $matches)) {
        $decoded = base64_decode($matches[1]);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
    }
    
    // 2. Заменяем _charXX_ на символ с кодом XX
    $text = preg_replace_callback('/_char(\d+)_/', function($matches) {
        $code = intval($matches[1]);
        return chr($code);
    }, $text);
    
    // 3. Восстанавливаем пробелы (если использовались)
    $text = str_replace('_space_', ' ', $text);
    
    // 4. Восстанавливаем перевод строки
    $text = str_replace('_newline_', "\n", $text);
    
    // 5. Восстанавливаем табуляцию
    $text = str_replace('_tab_', "\t", $text);
    
    return $text;
}


// ============================================================
// ФУНКЦИЯ: ПАРСИНГ И ВЫПОЛНЕНИЕ SCRIPTS
// ============================================================

function handleScriptCommand($query) {
    global $logFile;
    $logFile = getLogFile();
    // Проверяем, начинается ли команда с script:
    if (stripos($query, 'script:') !== 0) {
        return null;
    }
    
    // Убираем префикс
    $content = substr($query, 7);
    $content = ltrim($content);
    
    file_put_contents($logFile, "  [SCRIPT] Получен: $content\n", FILE_APPEND);
    
    // ============================================================
    // КОМАНДА: writeAdd - запись или добавление в файл
    // ============================================================
if (preg_match('/<writeAdd>\s*(.+?)\s*<\/writeAdd>\s*<Text>(.*?)<\/Text>/is', $content, $matches)) {
    $filename = trim($matches[1]);
    $text = trim($matches[2]);
    
file_put_contents($logFile, "  [SCRIPT] ДО ДЕКОДИРОВАНИЯ: $text\n", FILE_APPEND);
$text = decodeAgentText($text);
file_put_contents($logFile, "  [SCRIPT] ПОСЛЕ ДЕКОДИРОВАНИЯ: $text\n", FILE_APPEND);
    
    // Очищаем путь
    $filename = cleanPath($filename);
    
    // Проверяем, что путь разрешен
    if (!isPathAllowedForUser($filename)) {
        return "⛔ ACCESS DENIED: Path '$filename' is not allowed.\n";
    }
    
    // Определяем режим: write или add
    if (stripos($content, '<writeAdd>') !== false && stripos($content, 'add') !== false) {
        $mode = 'add';
    } else {
        $mode = 'write';
    }
    
    file_put_contents($logFile, "  [SCRIPT] writeAdd: $filename, mode=$mode, text=$text\n", FILE_APPEND);
        // Создаем папку, если не существует
        $dir = dirname($filename);
        if (!file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }
        
        // Записываем файл
        $flags = ($mode === 'add') ? FILE_APPEND : 0;
		// ✅ Убираем BOM и правильно кодируем
		$textToWrite = $text . "\n";
		$textToWrite = preg_replace('/^\xEF\xBB\xBF/', '', $textToWrite); // Убираем BOM
		
        $result = @file_put_contents($filename, $text . "\n", $flags);
        
        if ($result !== false) {
            return "✅ Файл успешно записан: $filename\nТекст: $text\n";
        } else {
            return "❌ Ошибка записи файла: $filename\n";
        }
    }
    
    // ============================================================
    // КОМАНДА: read - чтение файла
    // ============================================================
    if (preg_match('/<read>\s*(.+?)\s*<\/read>/is', $content, $matches)) {
        $filename = trim($matches[1]);
        $filename = cleanPath($filename);
        
        if (!isPathAllowedForUser($filename)) {
            return "⛔ ACCESS DENIED: Path '$filename' is not allowed.\n";
        }
        
        if (!file_exists($filename)) {
            return "❌ Файл не найден: $filename\n";
        }
        
        $text = @file_get_contents($filename);
        if ($text !== false) {
            return "📄 Содержимое файла: $filename\n\n" . $text;
        } else {
            return "❌ Ошибка чтения файла: $filename\n";
        }
    }
    
    // ============================================================
    // КОМАНДА: delete - удаление файла
    // ============================================================
    if (preg_match('/<delete>\s*(.+?)\s*<\/delete>/is', $content, $matches)) {
        $filename = trim($matches[1]);
        $filename = cleanPath($filename);
        
        if (!isPathAllowedForUser($filename)) {
            return "⛔ ACCESS DENIED: Path '$filename' is not allowed.\n";
        }
        
        if (!file_exists($filename)) {
            return "❌ Файл не найден: $filename\n";
        }
        
        if (@unlink($filename)) {
            return "✅ Файл удален: $filename\n";
        } else {
            return "❌ Ошибка удаления файла: $filename\n";
        }
    }
    
    // ============================================================
    // КОМАНДА: list - список файлов в папке
    // ============================================================
    if (preg_match('/<list>\s*(.+?)\s*<\/list>/is', $content, $matches)) {
        $dirname = trim($matches[1]);
        $dirname = cleanPath($dirname);
        
        if (!isPathAllowedForUser($dirname)) {
            return "⛔ ACCESS DENIED: Path '$dirname' is not allowed.\n";
        }
        
        if (!file_exists($dirname) || !is_dir($dirname)) {
            return "❌ Папка не найдена: $dirname\n";
        }
        
        $files = scandir($dirname);
        $result = "📂 Содержимое папки: $dirname\n\n";
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $fullPath = $dirname . '\\' . $file;
                $type = is_dir($fullPath) ? '<DIR>' : 'FILE';
                $size = is_file($fullPath) ? filesize($fullPath) : '-';
                $result .= "  $type   $file   ($size bytes)\n";
            }
        }
        return $result;
    }
    
    // ============================================================
    // Неизвестная script-команда
    // ============================================================
    return "❌ Неизвестная script-команда.\n\nДоступные команды:\n" .
           "  <writeAdd>путь</writeAdd> <Text>текст</Text>  - записать текст в файл (создает/перезаписывает)\n" .
           "  <writeAdd add>путь</writeAdd> <Text>текст</Text> - добавить текст в конец файла\n" .
           "  <read>путь</read>  - прочитать содержимое файла\n" .
           "  <delete>путь</delete> - удалить файл\n" .
           "  <list>путь</list> - показать содержимое папки\n";
}


// ============================================================
// ФУНКЦИЯ: ПОЛУЧЕНИЕ КАТАЛОГОВ ИЗ DOC.MD
// ============================================================

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
        if ($inSection && strpos($line, '#') === 0 && strpos($line, 'Каталоги') === false) {
            break;
        }
    }
    return $directories;
}

// ============================================================
// ФУНКЦИЯ: ПРОВЕРКА ПРАВ ДОСТУПА К КАТАЛОГАМ
// ============================================================

function checkDirectoryPermissions($dir) {
    if (!file_exists($dir)) return '❌ Не существует';
    $readTest = @file_get_contents($dir . '\\test.txt');
    $canRead = ($readTest !== false);
    $testFile = $dir . '\\_perm_test_' . uniqid() . '.txt';
    $writeTest = @file_put_contents($testFile, 'test');
    $canWrite = ($writeTest !== false);
    if ($canWrite) @unlink($testFile);
    if ($canRead && $canWrite) return '✅ Чтение и запись';
    if ($canRead) return '✅ Только чтение';
    return '❌ Нет доступа';
}

// ============================================================
// ФУНКЦИЯ: ПОЛУЧЕНИЕ ПРАВ ПОЛЬЗОВАТЕЛЯ
// ============================================================

function getFolderPermissions($path, $user) {
    if (!file_exists($path)) return '❌ Папка не существует';
    $output = shell_exec('icacls "' . $path . '" 2>nul | findstr /i "' . $user . '"');
    if (empty($output)) return '❌ Нет доступа';
    if (strpos($output, ':(R)') !== false || strpos($output, ':R') !== false) return '✅ Только чтение';
    if (strpos($output, ':(RW)') !== false || strpos($output, ':RW') !== false) return '✅ Чтение и запись';
    if (strpos($output, ':(F)') !== false) return '✅ Полный доступ';
    return '⚠️ Частичный доступ';
}

function getUserPermissions() {
    $user = RUNAS_USER;
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
    $tempFile = TEMP_DIR . '\\_perm_test_' . uniqid() . '.txt';
    $testWrite = @file_put_contents($tempFile, 'test');
    $permissions['can_write_temp'] = ($testWrite !== false);
    if ($testWrite !== false) @unlink($tempFile);
    $permissions['folder_rights'] = [];
    foreach (ALLOWED_DIRS as $dir) {
        $permissions['folder_rights'][$dir] = getFolderPermissions($dir, $user);
    }
    return $permissions;
}  

// ============================================================
// ФУНКЦИЯ: КОНВЕРТАЦИЯ КОДИРОВКИ
// ============================================================

function convertEncoding($output) {
    if (empty($output)) return '';
    $output = preg_replace('/^\xEF\xBB\xBF/', '', $output);
    $converted = iconv('CP866', 'UTF-8//IGNORE//TRANSLIT', $output);
    if ($converted !== false && $converted !== $output) return $converted;
    $converted = iconv('Windows-1251', 'UTF-8//IGNORE//TRANSLIT', $output);
    if ($converted !== false && $converted !== $output) return $converted;
    return $output;
}

// ============================================================
// ФУНКЦИЯ: ПРОВЕРКА ЗАБЛОКИРОВАННЫХ КОМАНД
// ============================================================

function isBlockedCommand($command) {
    foreach (BLOCKED_COMMANDS as $blocked) {
        if (stripos($command, $blocked) !== false) return true;
    }
    return false;
}

// ============================================================
// ФУНКЦИЯ: ИСПРАВЛЕНИЕ WINDOWS ПУТЕЙ
// ============================================================

function fixWindowsPaths($command) {
    // Заменяем двойные обратные слеши на одинарные для cmd
    $command = str_replace('\\\\', '\\', $command);
    
    // Заменяем прямые слеши на обратные
    $command = str_replace('/', '\\', $command);
    
    return $command;
}

// ============================================================
// ФУНКЦИЯ: ПРОВЕРКА КОМАНДЫ DIR
// ============================================================

function isDirCommand($command) {
    $command = ltrim($command);
    return preg_match('/^(dir|ls|tree|cd)\s/i', $command) === 1;
}

// ============================================================
// ФУНКЦИЯ: ИЗВЛЕЧЕНИЕ ПУТЕЙ ИЗ КОМАНДЫ
// ============================================================

function extractPathsFromCommand($command) {
    $paths = [];
    preg_match_all('/[A-Za-z]:[\/\\\\][^\s"\'&|<>]+/', $command, $matches);
    if (!empty($matches[0])) {
        foreach ($matches[0] as $path) {
            $paths[] = $path;
        }
    }
    return $paths;
}

// ============================================================
// ФУНКЦИЯ: ПРОВЕРКА РАЗРЕШЕННОГО ПУТИ
// ============================================================

function isPathAllowedForUser($path) {
    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }
    
    foreach (ALLOWED_DIRS as $allowed) {
        $allowedReal = realpath($allowed);
        if ($allowedReal !== false && stripos($realPath, $allowedReal) === 0) {
            return true;
        }
    }
    
    return false;
}

// ============================================================
// ФУНКЦИЯ: ОЧИСТКА ПУТИ
// ============================================================

function cleanPath($path) {
    $path = trim($path, '"\'');
    $path = preg_replace('/\\\\\\\\+/', '\\', $path);
    $path = str_replace('/', '\\', $path);
    return $path;
}

// ============================================================
// ФУНКЦИЯ: ОЧИСТКА ВЫВОДА ОТ БИТЫХ СИМВОЛОВ
// ============================================================

function cleanOutput($output) {
    if (empty($output)) return $output;
    $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output);
    $output = preg_replace('/[^\x20-\x7E\x0A\x0D\x80-\xFF]/', ' ', $output);
    $output = preg_replace('/\s+/', ' ', $output);
    
    $replaceMap = [
        '╨' => 'Р', '╡' => 'е', '╛' => 'ь', '╤' => 'Т',
        '╒' => 'Г', '╚' => 'Л', '╩' => 'И', '╧' => 'П',
        '╫' => 'У', '╬' => 'Ф', '╭' => 'а', '╮' => 'б',
        '╯' => 'в', '╰' => 'г', '╱' => 'д', '╲' => 'е',
        '╳' => 'ж', '╴' => 'з', '╵' => 'и', '╶' => 'й',
        '╷' => 'к', '╸' => 'л', '╹' => 'м', '╺' => 'н',
        '╻' => 'о', '╼' => 'п', '╽' => 'р', '╾' => 'с',
        '╿' => 'т', '─' => '—', '█' => '█', '║' => '│',
    ];
    $output = str_replace(array_keys($replaceMap), array_values($replaceMap), $output);
    
    return trim($output);
}

// ============================================================
// ФУНКЦИЯ: ИСПРАВЛЕНИЕ ПУТЕЙ ОТ АГЕНТА
// ============================================================

function fixAgentPaths($query) {
    global $logFile;
    $logFile = getLogFile();
    $command = $query;
    if (stripos($command, 'cmd:') === 0) {
        $command = substr($command, 4);
        $command = ltrim($command);
    }
    
    $hasQuotes = (strpos($command, '"') !== false);
    
    if (preg_match('/[A-Za-z]:\\\\\\\\[^\s"&|<>]+/', $command)) {
        file_put_contents($logFile, "  ✅ ПУТЬ УЖЕ ПРАВИЛЬНЫЙ: $command\n", FILE_APPEND);
        return 'cmd: ' . $command;
    }
    
    if (preg_match('/[A-Za-z]:\\\\([^\s"&|<>]+)/', $command)) {
        $command = preg_replace_callback(
            '/([A-Za-z]):\\\\([^\s"&|<>]+)/',
            function($matches) {
                $path = $matches[2];
                $path = str_replace('\\', '\\\\', $path);
                return $matches[1] . ':\\\\' . $path;
            },
            $command
        );
    }
    
    if (preg_match('/[A-Za-z]:\/([^\s"&|<>]+)/', $command)) {
        $command = preg_replace_callback(
            '/([A-Za-z]):\/([^\s"&|<>]+)/',
            function($matches) {
                $path = str_replace('/', '\\\\', $matches[2]);
                return $matches[1] . ':\\\\' . $path;
            },
            $command
        );
    }
    
    if ($hasQuotes && strpos($command, '"') === false) {
        if (strpos($command, ' ') !== false) {
            $command = preg_replace_callback(
                '/([A-Za-z]:\\\\[^\s&|<>]+)/',
                function($matches) {
                    return '"' . $matches[1] . '"';
                },
                $command
            );
        }
    }
    
    file_put_contents($logFile, "  🔧 ИСПРАВЛЕНО: $command\n", FILE_APPEND);
    return 'cmd: ' . $command;
}

// ============================================================
// ФУНКЦИЯ: УМНЫЙ ПАРСЕР КОМАНД
// ============================================================

function parseCommand($command) {
    $result = [
        'success' => false,
        'command' => '',
        'path' => '',
        'file' => '',
        'args' => [],
        'full_command' => '',
        'error' => ''
    ];
    
    if (stripos($command, 'cmd:') === 0) {
        $command = substr($command, 4);
        $command = ltrim($command);
    }
    
    $operators = ['&&', '||', '|', '>', '>>', '<'];
    $hasOperators = false;
    $operatorUsed = '';
    
    foreach ($operators as $op) {
        if (strpos($command, $op) !== false) {
            $hasOperators = true;
            $operatorUsed = $op;
            break;
        }
    }
    
    if ($hasOperators) {
        $parts = explode($operatorUsed, $command);
        $result['command'] = trim($parts[0]);
        if (isset($parts[1])) {
            $result['args'] = array_merge($result['args'], array_map('trim', array_slice($parts, 1)));
        }
        $firstPart = trim($parts[0]);
        $parsed = parseSingleCommand($firstPart);
        if ($parsed['success']) {
            $result['path'] = $parsed['path'];
            $result['file'] = $parsed['file'];
            $result['command'] = $parsed['command'];
            $result['success'] = true;
        }
        $result['full_command'] = rebuildCommand($result);
        return $result;
    }
    
    $parsed = parseSingleCommand($command);
    if ($parsed['success']) {
        $result['success'] = true;
        $result['command'] = $parsed['command'];
        $result['path'] = $parsed['path'];
        $result['file'] = $parsed['file'];
        $result['full_command'] = rebuildCommand($result);
    } else {
        $result['error'] = 'Не удалось распарсить команду';
    }
    
    return $result;
}

// ============================================================
// ФУНКЦИЯ: ПАРСИНГ ОДИНОЧНОЙ КОМАНДЫ
// ============================================================

function parseSingleCommand($command) {
    $result = [
        'success' => false,
        'command' => '',
        'path' => '',
        'file' => '',
        'args' => []
    ];
    
    $pathCommands = ['dir', 'ls', 'cd', 'type', 'del', 'erase', 'rmdir', 'rd', 'mkdir', 'md', 'copy', 'move', 'ren', 'rename'];
    $fileCommands = ['type', 'del', 'erase', 'copy', 'move', 'ren', 'rename'];
    
    foreach ($pathCommands as $cmd) {
        if (preg_match('/^' . $cmd . '\s+/i', $command)) {
            $result['command'] = $cmd;
            $rest = substr($command, strlen($cmd));
            $rest = ltrim($rest);
            break;
        }
    }
    
    if (empty($result['command'])) {
        return $result;
    }
    
    preg_match('/[A-Za-z]:[\/\\\\][^\s"\'&|<>]+/', $rest, $matches);
    
    if (!empty($matches)) {
        $rawPath = $matches[0];
        $result['path'] = cleanPath($rawPath);
        
        if (in_array(strtolower($result['command']), $fileCommands)) {
            $fileName = basename($rawPath);
            if ($fileName && strpos($fileName, '.') !== false) {
                $result['file'] = $fileName;
            }
        }
        
        $restWithoutPath = str_replace($rawPath, '', $rest);
        $restWithoutPath = ltrim($restWithoutPath);
        if (!empty($restWithoutPath)) {
            $result['args'] = explode(' ', $restWithoutPath);
        }
        
        $result['success'] = true;
    } else {
        if (in_array($result['command'], ['echo', 'set', 'systeminfo', 'ipconfig'])) {
            $result['success'] = true;
            $result['args'] = explode(' ', $rest);
        }
    }
    
    return $result;
}

// ============================================================
// ФУНКЦИЯ: СБОРКА КОМАНДЫ
// ============================================================

function rebuildCommand($parsed) {
    if (!$parsed['success']) return '';
    
    $cmd = $parsed['command'];
    $path = $parsed['path'];
    $args = $parsed['args'];
    
    if (!empty($path) && strpos($path, ' ') !== false) {
        $path = '"' . $path . '"';
    }
    
    $fullCommand = $cmd;
    if (!empty($path)) $fullCommand .= ' ' . $path;
    if (!empty($args)) $fullCommand .= ' ' . implode(' ', $args);
    
    return $fullCommand;
}

// ============================================================
// ФУНКЦИЯ: УМНОЕ ИСПРАВЛЕНИЕ ПУТЕЙ
// ============================================================

function smartFixPaths($query) {
    global $logFile;
    $logFile = getLogFile();
    file_put_contents($logFile, "  🧠 УМНЫЙ ПАРСЕР: $query\n", FILE_APPEND);
    
    if (stripos($query, 'cmd:') === 0) {
        $query = substr($query, 4);
        $query = ltrim($query);
    }
    
    $parsed = parseCommand($query);
    
    if ($parsed['success']) {
        $fixed = 'cmd: ' . $parsed['full_command'];
        file_put_contents($logFile, "  🧠 ПАРСЕР УСПЕШЕН: $fixed\n", FILE_APPEND);
        return $fixed;
    }
    
    file_put_contents($logFile, "  🧠 ПАРСЕР НЕ УДАЛСЯ, используем стандартный\n", FILE_APPEND);
    return fixAgentPaths($query);
}

// ============================================================
// ФУНКЦИЯ: АНАЛИЗ ЭКРАНИРОВАНИЯ
// ============================================================

function analyzePathEscaping($query) {
    $analysis = [
        'status' => 'ok',
        'warnings' => [],
        'suggestions' => [],
        'fixed_query' => $query
    ];
    
    preg_match_all('/[A-Za-z]:[\/\\\\][^\s"\'&|<>]+/', $query, $matches);
    
    if (empty($matches[0])) return $analysis;
    
    foreach ($matches[0] as $path) {
        if (preg_match('/\\\\\\\\+/', $path)) {
            $analysis['warnings'][] = "⚠️ Обнаружено ИЗБЫТОЧНОЕ экранирование в пути: $path";
            $analysis['suggestions'][] = "📌 Уберите лишние обратные слеши. Вместо D:\\\\\\\\folder используйте D:\\folder";
            $analysis['status'] = 'too_many_slashes';
        }
        
        if (preg_match('/[^\\\\]\\\[^\\\\]/', $path)) {
            $analysis['warnings'][] = "⚠️ Обнаружено НЕДОСТАТОЧНОЕ экранирование в пути: $path";
            $analysis['suggestions'][] = "📌 Экранируйте обратные слеши: D:\\folder\\file → D:\\\\folder\\\\file";
            $analysis['status'] = 'too_few_slashes';
        }
        
        if (strpos($path, '/') !== false && strpos($path, '\\') !== false) {
            $analysis['warnings'][] = "⚠️ Обнаружены СМЕШАННЫЕ слеши в пути: $path";
            $analysis['suggestions'][] = "📌 Используйте только обратные слеши: D:\\folder\\file";
            $analysis['status'] = 'mixed_slashes';
        }
        
        if (strpos($path, ' ') !== false && strpos($path, '"') === false && strpos($path, "'") === false) {
            $analysis['warnings'][] = "⚠️ Обнаружены ПРОБЕЛЫ без кавычек: $path";
            $analysis['suggestions'][] = "📌 Заключите путь в кавычки: \"D:\\Program Files\\file.txt\"";
            $analysis['status'] = 'space_no_quotes';
        }
    }
    
    if (!empty($analysis['warnings'])) {
        $fixed = $analysis['fixed_query'];
        $fixed = preg_replace('/\\\\\\\\+/', '\\\\', $fixed);
        $fixed = preg_replace_callback(
            '/([A-Za-z]):\\\\([^\s"\'&|<>]+)/',
            function($matches) {
                $path = $matches[2];
                $path = str_replace('\\', '\\\\', $path);
                return $matches[1] . ':\\\\' . $path;
            },
            $fixed
        );
        $analysis['fixed_query'] = $fixed;
    }
    
    return $analysis;
}

// ============================================================
// ФУНКЦИЯ: УМНЫЙ ОТВЕТ ПРИ ОШИБКЕ
// ============================================================

function smartErrorResponse($command, $error, $suggestions = []) {
    global $logFile;
    $logFile = getLogFile();
    $response = [
        'status' => 'error',
        'message' => $error,
        'command' => $command,
        'suggestions' => $suggestions,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (strpos($error, 'кодировк') !== false || 
        strpos($error, 'CP866') !== false || 
        strpos($error, 'UTF') !== false ||
        preg_match('/[А-Яа-яЁё]/u', $command)) {
        
        $response['suggestions'][] = "🔧 ПРОБЛЕМА С РУССКИМ ТЕКСТОМ ИЛИ КОДИРОВКОЙ";
        $response['suggestions'][] = "📌 Попробуйте использовать PowerShell для записи:";
        $response['suggestions'][] = 'cmd: powershell -Command "Set-Content -Path \'D:\\PortableAI\\DostupHermes\\file.txt\' -Value \'Текст\' -Encoding UTF8"';
        $response['suggestions'][] = "📌 Или для добавления в конец:";
        $response['suggestions'][] = 'cmd: powershell -Command "Add-Content -Path \'D:\\PortableAI\\DostupHermes\\file.txt\' -Value \'Текст\' -Encoding UTF8"';
        $response['suggestions'][] = "📌 Для чтения файла с русским текстом:";
        $response['suggestions'][] = 'cmd: powershell -Command "Get-Content -Path \'D:\\PortableAI\\DostupHermes\\file.txt\' -Encoding UTF8"';
    }
    
    if (strpos($error, 'путь') !== false || strpos($error, 'path') !== false) {
        $response['suggestions'][] = "📌 Проверьте, что путь существует и доступен:";
        $response['suggestions'][] = 'cmd: dir D:\\PortableAI\\DostupHermes';
    }
    
    if (strpos($error, 'кавычк') !== false || strpos($error, 'quote') !== false) {
        $response['suggestions'][] = "📌 Используйте двойные кавычки для путей с пробелами:";
        $response['suggestions'][] = 'cmd: dir "D:\\Program Files"';
    }
    
    file_put_contents($logFile, "  💡 УМНЫЙ ОТВЕТ: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    
    return $response;
}

// ============================================================
// ФУНКЦИЯ: ОТПРАВКА ОШИБКИ
// ============================================================

function sendError($message, $suggestions = []) {
    $response = [
        'status' => 'error',
        'message' => $message,
        'suggestions' => $suggestions,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    global $logFile;
	$logFile = getLogFile();
    file_put_contents($logFile, '  ERROR: ' . $message . "\n", FILE_APPEND);
    if (!empty($suggestions)) {
        file_put_contents($logFile, '  SUGGESTIONS: ' . implode('; ', $suggestions) . "\n", FILE_APPEND);
    }
    http_response_code(400);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// ФУНКЦИЯ: ОТПРАВКА СОВЕТОВ ПО ЭКРАНИРОВАНИЮ
// ============================================================

function sendEscapingAdvice($analysis, $originalQuery) {
    $response = [
        'status' => 'error',
        'message' => 'Ошибка экранирования путей в запросе',
        'original_query' => $originalQuery,
        'fixed_query' => $analysis['fixed_query'],
        'warnings' => $analysis['warnings'],
        'suggestions' => $analysis['suggestions'],
        'instruction' => implode("\n", [
            "📖 ПРАВИЛА ЭКРАНИРОВАНИЯ ДЛЯ АГЕНТА:",
            "1. В JSON всегда экранируйте обратные слеши: D:\\\\folder\\\\file",
            "2. Не используйте больше 2 обратных слешей подряд",
            "3. Пути с пробелами заключайте в кавычки: \"D:\\\\Program Files\\\\file.txt\"",
            "4. Используйте только обратные слеши (\\), не смешивайте с прямыми (/)",
            "",
            "✅ ПРАВИЛЬНО: {\"query\":\"cmd: dir D:\\\\PortableAI\\\\DostupHermes\"}",
            "❌ НЕПРАВИЛЬНО: {\"query\":\"cmd: dir D:\\\\\\\\PortableAI\\\\\\\\DostupHermes\"}",
            "",
            "Попробуйте отправить исправленный запрос:",
            $analysis['fixed_query']
        ]),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    return $response;
}

// ============================================================
// ФУНКЦИЯ: БЕЗОПАСНОЕ УДАЛЕНИЕ ФАЙЛА
// ============================================================

function safeDeleteFile($command) {
    if (preg_match('/^(del|erase)\s+["\']?(.+?)["\']?$/i', $command, $matches)) {
        $filePath = trim($matches[2]);
        $filePath = trim($filePath, '"\'');
        $filePath = str_replace('\\\\', '\\', $filePath);
        
        if (!file_exists($filePath)) {
            return "ERROR: Файл не найден: $filePath\n";
        }
        
        if (is_dir($filePath)) {
            return "ERROR: Это папка, а не файл. Используйте rmdir для удаления папок.\n";
        }
        
        if (@unlink($filePath)) {
            return "✅ Файл успешно удален: $filePath\n";
        } else {
            $cmd = 'del /f /q "' . $filePath . '" 2>&1';
            $output = shell_exec($cmd);
            $output = convertEncoding($output);
            return $output ?: "Ошибка при удалении файла\n";
        }
    }
    return null;
}

// ============================================================
// ФУНКЦИЯ: БЕЗОПАСНОЕ ВЫПОЛНЕНИЕ КОМАНД
// ============================================================

function safeExecute($command) {
    global $logFile;
    $logFile = getLogFile();
    if (isBlockedCommand($command)) {
        return "⛔ ERROR: Command blocked for security reasons.\n";
    }
    
    $deleteResult = safeDeleteFile($command);
    if ($deleteResult !== null) {
        return $deleteResult;
    }
    
    $paths = extractPathsFromCommand($command);
    foreach ($paths as $path) {
        if (!isPathAllowedForUser($path)) {
            return "⛔ ACCESS DENIED: Path '$path' is not allowed.\n";
        }
    }
    
    $result = null;
    $errors = [];
    
    // Способ 1: RunAsCPP
try {
    $runAsPath = getRunAsPath();
    $user = RUNAS_USER;
    $password = RUNAS_PASSWORD;
    
    // ✅ ИСПРАВЛЕНО: Заменяем \\ на \ для cmd
    $commandForCmd = str_replace('\\\\', '\\', $command);
    $escapedCommand = str_replace('"', '\\"', $commandForCmd);
    
    if (file_exists($runAsPath)) {
        $fullCommand = sprintf(
            '"%s" %s %s "cmd.exe /c %s" . 8 2>&1',
            $runAsPath,
            $user,
            $password,
            $escapedCommand
        );
        
        file_put_contents($logFile, "  [TRY 1] RunAsCPP: $fullCommand\n", FILE_APPEND);
        $output = shell_exec($fullCommand);
        
        if ($output !== null && trim($output) !== '') {
            $result = cleanOutput(convertEncoding($output));
            file_put_contents($logFile, "  [OK] RunAsCPP успешно\n", FILE_APPEND);
            return $result;
        } else {
            $errors[] = "RunAsCPP вернул пустой вывод";
        }
    } else {
        $errors[] = "RunAsCPP.exe не найден";
    }
} catch (Exception $e) {
    $errors[] = "RunAsCPP exception: " . $e->getMessage();
}

    
    // Способ 2: PowerShell
    try {
        $escapedCommand = str_replace('"', '`"', $command);
        $psCommand = sprintf(
            'powershell -Command "Start-Process cmd -ArgumentList \'/c %s\' -Wait -RedirectStandardOutput ' . TEMP_DIR . '\\ps_output.txt -RedirectStandardError ' . TEMP_DIR . '\\ps_error.txt; Get-Content ' . TEMP_DIR . '\\ps_output.txt; if (Test-Path ' . TEMP_DIR . '\\ps_error.txt) { Get-Content ' . TEMP_DIR . '\\ps_error.txt }"',
            $escapedCommand
        );
        
        file_put_contents($logFile, "  [TRY 2] PowerShell: $psCommand\n", FILE_APPEND);
        $output = shell_exec($psCommand);
        
        if ($output !== null && trim($output) !== '') {
            $result = cleanOutput(convertEncoding($output));
            @unlink(TEMP_DIR . '\\ps_output.txt');
            @unlink(TEMP_DIR . '\\ps_error.txt');
            file_put_contents($logFile, "  [OK] PowerShell успешно\n", FILE_APPEND);
            return $result;
        } else {
            $errors[] = "PowerShell вернул пустой вывод";
        }
    } catch (Exception $e) {
        $errors[] = "PowerShell exception: " . $e->getMessage();
    }
    
    // ВСЕ СПОСОБЫ НЕ УДАЛИСЬ
    $errorMsg = "❌ ВСЕ СПОСОБЫ ВЫПОЛНЕНИЯ НЕ УДАЛИСЬ:\n";
    foreach ($errors as $i => $error) {
        $errorMsg .= "  " . ($i + 1) . ". $error\n";
    }
    $errorMsg .= "\nКоманда: $command";
    
    $suggestions = [
        "📌 Проверьте синтаксис команды",
        "📌 Убедитесь, что путь существует",
        "📌 Для русских текстов используйте PowerShell"
    ];
    $errorResponse = smartErrorResponse($command, $errorMsg, $suggestions);
    
    return json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
}

// ============================================================
// ФУНКЦИЯ: ГЕНЕРАЦИЯ HELP
// ============================================================

function generateHelp() {
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
        foreach ($directories as $dir) {
            $rights = checkDirectoryPermissions($dir);
            $help .= "• $dir: $rights\n";
        }
    }
    $help .= "\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "📁 РАЗРЕШЕННЫЕ КАТАЛОГИ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════\n";
    foreach (ALLOWED_DIRS as $dir) {
        $help .= "• $dir\n";
    }
    $help .= "\n";
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
	$help .= "📝 ЗАПИСЬ И ЧТЕНИЕ ФАЙЛОВ (ПРОТОКОЛ script:)\n";
	$help .= "═══════════════════════════════════════════════════════════════════════════════════════════════════════════\n";
	$help .= "📌 1. ЗАПИСЬ ТЕКСТА В ФАЙЛ (латиница):\n";
	$help .= '   POST {"action":"execute","query":"script: <writeAdd>D:\\PortableAI\\DostupHermes\\file.txt</writeAdd> <Text>Hello World!</Text>"}' . "\n\n";
	$help .= "📌 2. ЗАПИСЬ РУССКОГО ТЕКСТА (через Base64):\n";
	$help .= '   POST {"action":"execute","query":"script: <writeAdd>D:\\PortableAI\\DostupHermes\\file.txt</writeAdd> <Text><b64>0J/RgNC40LLQtdGCINC+0YIg0JPQtdGA0LzQtdGB0LAh</b64></Text>"}' . "\n\n";
	$help .= "📌 3. ДОБАВЛЕНИЕ В КОНЕЦ ФАЙЛА:\n";
	$help .= '   POST {"action":"execute","query":"script: <writeAdd add>D:\\PortableAI\\DostupHermes\\file.txt</writeAdd> <Text>Новая строка!</Text>"}' . "\n\n";
	$help .= "📌 4. ЧТЕНИЕ ФАЙЛА:\n";
	$help .= '   POST {"action":"execute","query":"script: <read>D:\\PortableAI\\DostupHermes\\file.txt</read>"}' . "\n\n";
	$help .= "📌 5. УДАЛЕНИЕ ФАЙЛА:\n";
	$help .= '   POST {"action":"execute","query":"script: <delete>D:\\PortableAI\\DostupHermes\\file.txt</delete>"}' . "\n\n";
	$help .= "📌 6. СПИСОК ФАЙЛОВ В ПАПКЕ:\n";
	$help .= '   POST {"action":"execute","query":"script: <list>D:\\PortableAI\\DostupHermes</list>"}' . "\n\n";
	$help .= "⚠️ ВАЖНО: Для русского текста ОБЯЗАТЕЛЬНО используйте Base64!\n";
	$help .= "   • Кодируйте текст в Base64: 'Привет' → '0J/RgNC40LLQtdGC'\n";
	$help .= "   • Вставляйте в теги: <b64>0J/RgNC40LLQtdGC</b64>\n";
	$help .= "   • Сервер автоматически декодирует и запишет правильный текст\n\n";
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
    $help .= "═══════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "📖 ПОЛУЧИТЬ ЭТУ СПРАВКУ\n";
    $help .= "═══════════════════════════════════════════════════════════════════════════════════\n";
    $help .= "GET /api.php?action=help\n";
    return $help;
}

?>


