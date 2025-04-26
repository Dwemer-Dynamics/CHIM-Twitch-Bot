<?php
header('Content-Type: application/json');

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username']) || !isset($data['token']) || !isset($data['channel'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Load existing settings if any
$settings = [];
if (file_exists('settings.json')) {
    $settings = json_decode(file_get_contents('settings.json'), true);
}

// Update settings.json
$settings['username'] = $data['username'];
$settings['token'] = $data['token'];
$settings['channel'] = $data['channel'];

// Load existing env vars if any
$env_file = __DIR__ . "/bot_env.json";
$env_vars = file_exists($env_file) ? json_decode(file_get_contents($env_file), true) : [];

// Update env vars
$env_vars['TBOT_USERNAME'] = $data['username'];
$env_vars['TBOT_OAUTH'] = $data['token'];
$env_vars['TBOT_CHANNEL'] = $data['channel'];

// Preserve existing env vars if they exist
if (!isset($env_vars['TBOT_COOLDOWN'])) {
    $env_vars['TBOT_COOLDOWN'] = "30";
}
if (!isset($env_vars['TBOT_MODS_ONLY'])) {
    $env_vars['TBOT_MODS_ONLY'] = "0";
}

// Save both files
$success = true;
$error = '';

if (!file_put_contents('settings.json', json_encode($settings))) {
    $success = false;
    $error = 'Failed to save settings.json';
}

if (!file_put_contents($env_file, json_encode($env_vars))) {
    $success = false;
    $error .= ($error ? ' and ' : '') . 'Failed to save bot_env.json';
}

echo json_encode([
    'success' => $success,
    'error' => $error
]);
?> 