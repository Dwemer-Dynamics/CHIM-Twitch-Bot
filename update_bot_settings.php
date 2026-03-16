<?php
header('Content-Type: application/json');

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Set default values
$cooldown = isset($data['cooldown']) ? intval($data['cooldown']) : 30;
$modsOnly = isset($data['modsOnly']) ? $data['modsOnly'] : false;
$subsOnly = isset($data['subsOnly']) ? $data['subsOnly'] : false;
$whitelistEnabled = isset($data['whitelistEnabled']) ? $data['whitelistEnabled'] : false;
$rolemasterInstruction = isset($data['rolemasterInstruction']) ? $data['rolemasterInstruction'] : true;
$rolemasterSuggestion = isset($data['rolemasterSuggestion']) ? $data['rolemasterSuggestion'] : true;
$rolemasterImpersonation = isset($data['rolemasterImpersonation']) ? $data['rolemasterImpersonation'] : true;
$rolemasterSpawn = isset($data['rolemasterSpawn']) ? $data['rolemasterSpawn'] : false;
$rolemasterCheat = isset($data['rolemasterCheat']) ? $data['rolemasterCheat'] : false;
$rolemasterEncounter = isset($data['rolemasterEncounter']) ? $data['rolemasterEncounter'] : false;
$helpKeywords = isset($data['helpKeywords']) ? $data['helpKeywords'] : 'help,ai,Rolemaster,rp';

// Load existing env vars if any
$env_file = __DIR__ . "/bot_env.json";
$env_vars = file_exists($env_file) ? json_decode(file_get_contents($env_file), true) : [];
if (!is_array($env_vars)) {
    $env_vars = [];
}

// Build command name mapping (merge posted values over existing map)
$defaultCommandMap = [
    'instruction' => 'director',
    'suggestion' => 'suggestion',
    'impersonation' => 'impersonation',
    'spawn' => 'spawn',
    'cheat' => 'cheat',
    'encounter' => 'encounter'
];
$existingCommandMap = $defaultCommandMap;
if (isset($env_vars['TBOT_COMMAND_NAME_MAP'])) {
    $decoded = json_decode($env_vars['TBOT_COMMAND_NAME_MAP'], true);
    if (is_array($decoded)) {
        $existingCommandMap = array_merge($existingCommandMap, $decoded);
    }
}

$mapKeyByPostField = [
    'cmd_instruction' => 'instruction',
    'cmd_suggestion' => 'suggestion',
    'cmd_impersonation' => 'impersonation',
    'cmd_spawn' => 'spawn',
    'cmd_cheat' => 'cheat',
    'cmd_encounter' => 'encounter'
];

$commandNameMap = $existingCommandMap;
foreach ($mapKeyByPostField as $postField => $mapKey) {
    if (isset($data[$postField])) {
        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '', (string)$data[$postField]);
        if ($sanitized !== '') {
            $commandNameMap[$mapKey] = $sanitized;
        }
    }
}

// Update env vars with new values
$env_vars['TBOT_COOLDOWN'] = strval($cooldown);
$env_vars['TBOT_MODS_ONLY'] = $modsOnly ? "1" : "0";
$env_vars['TBOT_SUBS_ONLY'] = $subsOnly ? "1" : "0";
$env_vars['TBOT_WHITELIST_ENABLED'] = $whitelistEnabled ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'] = $rolemasterInstruction ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'] = $rolemasterSuggestion ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'] = $rolemasterImpersonation ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_SPAWN_ENABLED'] = $rolemasterSpawn ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_CHEAT_ENABLED'] = $rolemasterCheat ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_ENCOUNTER_ENABLED'] = $rolemasterEncounter ? "1" : "0";
$env_vars['TBOT_HELP_KEYWORDS'] = $helpKeywords;
$env_vars['TBOT_COMMAND_NAME_MAP'] = json_encode($commandNameMap);
unset($env_vars['TBOT_ROLEMASTER_CHEAT_MODS_ONLY']);

// Save env vars
$success = file_put_contents($env_file, json_encode($env_vars));

if ($success) {
    // Return the updated values
    echo json_encode([
        'success' => true,
        'settings' => [
            'cooldown' => intval($env_vars['TBOT_COOLDOWN']),
            'modsOnly' => $env_vars['TBOT_MODS_ONLY'] === "1",
            'subsOnly' => $env_vars['TBOT_SUBS_ONLY'] === "1",
            'whitelistEnabled' => $env_vars['TBOT_WHITELIST_ENABLED'] === "1",
            'rolemasterInstruction' => $env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'] === "1",
            'rolemasterSuggestion' => $env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'] === "1",
            'rolemasterImpersonation' => $env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'] === "1",
            'rolemasterSpawn' => $env_vars['TBOT_ROLEMASTER_SPAWN_ENABLED'] === "1",
            'rolemasterCheat' => $env_vars['TBOT_ROLEMASTER_CHEAT_ENABLED'] === "1",
            'rolemasterEncounter' => $env_vars['TBOT_ROLEMASTER_ENCOUNTER_ENABLED'] === "1",
            'helpKeywords' => $env_vars['TBOT_HELP_KEYWORDS'],
            'commandNameMap' => $commandNameMap
        ]
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save settings'
    ]);
}
?> 
