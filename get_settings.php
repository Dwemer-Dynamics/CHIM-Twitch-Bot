<?php
header('Content-Type: application/json');

// Default settings
$defaultSettings = [
    'username' => '',
    'token' => '',
    'channel' => '',
    'cooldown' => 30,
    'modsOnly' => false,
    'subsOnly' => false,
    'whitelistEnabled' => false,
    'rolemasterInstruction' => true,
    'rolemasterSuggestion' => true,
    'rolemasterImpersonation' => true,
    'rolemasterSpawn' => true,
    'rolemasterEncounter' => false,
    'useCommandPrefix' => true,
    'commandPrefix' => 'Rolemaster',
    'helpKeywords' => 'help,ai,Rolemaster,rp',
    'commandNameMap' => [
        'instruction' => 'instruction',
        'suggestion' => 'suggestion',
        'impersonation' => 'impersonation',
        'spawn' => 'spawn',
        'encounter' => 'encounter'
    ]
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
    if (isset($env_vars['TBOT_SUBS_ONLY'])) $settings['subsOnly'] = $env_vars['TBOT_SUBS_ONLY'] === "1";
    if (isset($env_vars['TBOT_WHITELIST_ENABLED'])) $settings['whitelistEnabled'] = $env_vars['TBOT_WHITELIST_ENABLED'] === "1";
    if (isset($env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'])) $settings['rolemasterInstruction'] = $env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'] === "1";
    if (isset($env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'])) $settings['rolemasterSuggestion'] = $env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'] === "1";
    if (isset($env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'])) $settings['rolemasterImpersonation'] = $env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'] === "1";
    if (isset($env_vars['TBOT_ROLEMASTER_SPAWN_ENABLED'])) $settings['rolemasterSpawn'] = $env_vars['TBOT_ROLEMASTER_SPAWN_ENABLED'] === "1";
    if (isset($env_vars['TBOT_ROLEMASTER_ENCOUNTER_ENABLED'])) $settings['rolemasterEncounter'] = $env_vars['TBOT_ROLEMASTER_ENCOUNTER_ENABLED'] === "1";
    if (isset($env_vars['TBOT_USE_COMMAND_PREFIX'])) $settings['useCommandPrefix'] = $env_vars['TBOT_USE_COMMAND_PREFIX'] === "1";
    if (isset($env_vars['TBOT_COMMAND_PREFIX'])) $settings['commandPrefix'] = $env_vars['TBOT_COMMAND_PREFIX'];
    if (isset($env_vars['TBOT_HELP_KEYWORDS'])) $settings['helpKeywords'] = $env_vars['TBOT_HELP_KEYWORDS'];
    if (isset($env_vars['TBOT_COMMAND_NAME_MAP'])) {
        $commandMap = json_decode($env_vars['TBOT_COMMAND_NAME_MAP'], true);
        if (is_array($commandMap)) {
            $settings['commandNameMap'] = $commandMap;
        }
    }
}

echo json_encode([
    'success' => true,
    'settings' => $settings
]);
?> 