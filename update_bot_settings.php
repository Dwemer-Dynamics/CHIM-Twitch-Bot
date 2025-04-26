<?php
header('Content-Type: application/json');

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Set default values
$cooldown = isset($data['cooldown']) ? intval($data['cooldown']) : 30;
$modsOnly = isset($data['modsOnly']) ? $data['modsOnly'] : false;

// Ensure cooldown is at least 0
$cooldown = max(0, $cooldown);

// Load existing env vars if any
$env_file = __DIR__ . "/bot_env.json";
$env_vars = file_exists($env_file) ? json_decode(file_get_contents($env_file), true) : [];

// Update env vars with defaults if not set
$env_vars['TBOT_COOLDOWN'] = strval($cooldown);
$env_vars['TBOT_MODS_ONLY'] = $modsOnly ? "1" : "0";

// Save env vars
$success = file_put_contents($env_file, json_encode($env_vars));

if ($success) {
    // Return the updated values
    echo json_encode([
        'success' => true,
        'settings' => [
            'cooldown' => intval($env_vars['TBOT_COOLDOWN']),
            'modsOnly' => $env_vars['TBOT_MODS_ONLY'] === "1"
        ]
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save settings'
    ]);
}
?> 