<?php
require_once __DIR__ . '/command.php';

// Special local testing username with illegal Twitch character
define('LOCAL_TEST_USER', '@local_tester');

// Load environment variables from bot_env.json if it exists
$env_file = __DIR__ . "/bot_env.json";
if (file_exists($env_file)) {
    $env_vars = json_decode(file_get_contents($env_file), true);
    if ($env_vars) {
        foreach ($env_vars as $key => $value) {
            putenv("$key=$value");
        }
    } else {
    }
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No message provided']);
    exit;
}

$message = trim($input['message']);

// Start output buffering to capture command execution output
ob_start();

// Create command handler with message callback
$messages = [];
$commandHandler = new CommandHandler(null, null, function($msg) use (&$messages) {
    $messages[] = $msg;
});

// Execute command with local test user (always mod for testing)
$isMod = true;
$isSub = true;
$result = $commandHandler->parseCommand(LOCAL_TEST_USER, $message, $isMod, $isSub);

// Get the command execution output
$commandOutput = ob_get_clean();

echo json_encode([
    'success' => $result,
    'messages' => $messages,
    'user' => LOCAL_TEST_USER,
    'debug_output' => $commandOutput // Include the captured output in the response
]); 