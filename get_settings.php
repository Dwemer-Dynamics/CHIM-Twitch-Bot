<?php
header('Content-Type: application/json');

// Default settings
$defaultSettings = [
    'username' => '',
    'token' => '',
    'channel' => '',
    'cooldown' => 30,
    'modsOnly' => false
];

// Load settings from settings.json if it exists
$settings = $defaultSettings;
if (file_exists('settings.json')) {
    $settings = array_merge($settings, json_decode(file_get_contents('settings.json'), true) ?? []);
}

// Load and merge env vars if they exist
$env_file = __DIR__ . "/bot_env.json";
if (file_exists($env_file)) {
    $env_vars = json_decode(file_get_contents($env_file), true) ?? [];
    
    // Map env vars to settings
    if (isset($env_vars['TBOT_USERNAME'])) $settings['username'] = $env_vars['TBOT_USERNAME'];
    if (isset($env_vars['TBOT_OAUTH'])) $settings['token'] = $env_vars['TBOT_OAUTH'];
    if (isset($env_vars['TBOT_CHANNEL'])) $settings['channel'] = $env_vars['TBOT_CHANNEL'];
    if (isset($env_vars['TBOT_COOLDOWN'])) $settings['cooldown'] = intval($env_vars['TBOT_COOLDOWN']);
    if (isset($env_vars['TBOT_MODS_ONLY'])) $settings['modsOnly'] = $env_vars['TBOT_MODS_ONLY'] === "1";
}

echo json_encode([
    'success' => true,
    'settings' => $settings
]);
?> 