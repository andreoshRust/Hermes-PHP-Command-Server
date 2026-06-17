<?php

// ============================================================
// API.PHP - ТОЧКА ВХОДА
// ============================================================

header('Content-Type: application/json; charset=utf-8');

// Подключаем все функции
require_once __DIR__ . '/func.php';


$logFile = getLogFile();

// ============================================================
// ВХОДНЫЕ ДАННЫЕ
// ============================================================

$logEntry = "\n" . str_repeat('=', 60) . "\n";
$logEntry .= date('Y-m-d H:i:s') . "\n";
$logEntry .= 'METHOD: ' . $_SERVER['REQUEST_METHOD'] . "\n";
$logEntry .= 'URI: ' . $_SERVER['REQUEST_URI'] . "\n";
$logEntry .= 'CONTENT_TYPE: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not set') . "\n";
$logEntry .= 'RAW INPUT: ' . file_get_contents('php://input') . "\n";
$logEntry .= 'GET: ' . print_r($_GET, true) . "\n";
$logEntry .= 'POST: ' . print_r($_POST, true) . "\n";
$logEntry .= 'SERVER: ' . print_r($_SERVER, true) . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    sendError('Unsupported HTTP method: ' . $method, ['Use GET for help or POST for commands']);
}

$rawInput = file_get_contents('php://input');

if ($method === 'POST') {
    if (empty($rawInput)) {
        sendError('Empty request body', [
            'Send JSON with {"action":"execute","query":"cmd:your_command"}',
            'Example: {"action":"execute","query":"cmd:dir D:\\"}',
            'For help use: GET /api.php?action=help'
        ]);
    }
    $input = json_decode($rawInput, true);
    if ($input === null) {
        sendError('Invalid JSON format', [
            'Check your JSON syntax',
            'Make sure to escape backslashes: D:\\\\folder',
            'Content-Type must be: application/json',
            'Example: {"action":"execute","query":"cmd:echo Hello"}'
        ]);
    }
} else {
    $input = [];
}

$action = $_GET['action'] ?? $input['action'] ?? 'help';

// ============================================================
// HELP
// ============================================================
if ($action === 'help') {
    $help = generateHelp();
    echo json_encode([
        'status' => 'success',
        'version' => SERVER_VERSION,
        'permissions' => getUserPermissions(),
        'help' => $help
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// EXECUTE
// ============================================================
if ($action === 'execute') {
    if (!isset($input['query']) || empty($input['query'])) {
        sendError('Missing or empty "query" field', [
            'Add "query" field with your command',
            'Example: {"action":"execute","query":"cmd:dir D:\\"}',
            'Commands must start with "cmd:" or "php:" prefix'
        ]);
    }
    
    $query = $input['query'];
    $start_time = microtime(true);
    
    file_put_contents($logFile, "  📥 ЗАПРОС: $query\n", FILE_APPEND);
    
	// ============================================================
	// ОБРАБОТКА SCRIPT-КОМАНД
	// ============================================================
	$scriptResult = handleScriptCommand($query);
	if ($scriptResult !== null) {
		$duration = round((microtime(true) - $start_time) * 1000);
		echo json_encode([
			'status' => 'success',
			'version' => SERVER_VERSION,
			'result' => $scriptResult,
			'timing' => ['duration_ms' => $duration]
		], JSON_UNESCAPED_UNICODE);
		exit;
	}
	
	
    // ============================================================
    // ОБРАБОТКА PHP-КОМАНД
    // ============================================================
    $phpResult = handlePHPCommand($query);
    if ($phpResult !== null) {
        $duration = round((microtime(true) - $start_time) * 1000);
        echo json_encode([
            'status' => 'success',
            'version' => SERVER_VERSION,
            'result' => $phpResult,
            'timing' => ['duration_ms' => $duration]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
	
    // ============================================================
    // ОБРАБОТКА ОБЫЧНЫХ КОМАНД
    // ============================================================
    $analysis = analyzePathEscaping($query);
    
    if ($analysis['status'] === 'too_many_slashes') {
        $response = sendEscapingAdvice($analysis, $query);
        file_put_contents($logFile, "  ⚠️ ИЗБЫТОЧНОЕ ЭКРАНИРОВАНИЕ\n", FILE_APPEND);
        http_response_code(400);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($analysis['status'] === 'too_few_slashes' || $analysis['status'] === 'mixed_slashes') {
        file_put_contents($logFile, "  🔧 ДО ИСПРАВЛЕНИЯ: $query\n", FILE_APPEND);
        $query = fixAgentPaths($query);
        file_put_contents($logFile, "  🔧 ПОСЛЕ ИСПРАВЛЕНИЯ: $query\n", FILE_APPEND);
    }
    
    if ($analysis['status'] === 'ok') {
        file_put_contents($logFile, "  ✅ ПУТЬ ПРАВИЛЬНЫЙ: $query\n", FILE_APPEND);
    }
    
    if (stripos($query, 'cmd:') !== 0) {
        sendError('Command must start with "cmd:"', [
            'Example: {"action":"execute","query":"cmd:dir D:\\"}',
            'Current query: ' . substr($query, 0, 50) . '...'
        ]);
    }
    
    $command = substr($query, 4);
    $command = ltrim($command);
    
    if (isBlockedCommand($command)) {
        sendError('Command blocked for security reasons', [
            'The command contains blocked keywords',
            'Blocked commands: ' . implode(', ', BLOCKED_COMMANDS),
            'Try a different command'
        ]);
    }
    
    file_put_contents($logFile, "  COMMAND: $command\n", FILE_APPEND);
    
    $result = safeExecute($command);
    
    file_put_contents($logFile, "  RESULT: " . substr($result, 0, 200) . "...\n", FILE_APPEND);
    
    $duration = round((microtime(true) - $start_time) * 1000);
    
    echo json_encode([
        'status' => 'success',
        'version' => SERVER_VERSION,
        'result' => $result,
        'timing' => ['duration_ms' => $duration]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// UNKNOWN ACTION
// ============================================================
sendError(
    'Unknown action: ' . $action,
    [
        'Supported actions: execute, help',
        'Use "action": "execute" for commands',
        'Use "action": "help" for documentation'
    ]
);
?>
