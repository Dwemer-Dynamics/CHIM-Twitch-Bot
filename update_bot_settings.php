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
$rolemasterSpawn = isset($data['rolemasterSpawn']) ? $data['rolemasterSpawn'] : true;
$rolemasterEncounter = isset($data['rolemasterEncounter']) ? $data['rolemasterEncounter'] : false;
$useCommandPrefix = isset($data['useCommandPrefix']) ? $data['useCommandPrefix'] : true;
$commandPrefix = isset($data['commandPrefix']) ? preg_replace('/[^a-zA-Z0-9]/', '', $data['commandPrefix']) : 'Rolemaster';
$helpKeywords = isset($data['helpKeywords']) ? $data['helpKeywords'] : 'help,ai,Rolemaster,rp';

// Build command name mapping
$commandNameMap = [
    'instruction' => isset($data['cmd_instruction']) && !empty($data['cmd_instruction']) ? 
        preg_replace('/[^a-zA-Z0-9]/', '', $data['cmd_instruction']) : 'instruction',
    'suggestion' => isset($data['cmd_suggestion']) && !empty($data['cmd_suggestion']) ? 
        preg_replace('/[^a-zA-Z0-9]/', '', $data['cmd_suggestion']) : 'suggestion',
    'impersonation' => isset($data['cmd_impersonation']) && !empty($data['cmd_impersonation']) ? 
        preg_replace('/[^a-zA-Z0-9]/', '', $data['cmd_impersonation']) : 'impersonation',
    'spawn' => isset($data['cmd_spawn']) && !empty($data['cmd_spawn']) ? 
        preg_replace('/[^a-zA-Z0-9]/', '', $data['cmd_spawn']) : 'spawn',
    'encounter' => isset($data['cmd_encounter']) && !empty($data['cmd_encounter']) ? 
        preg_replace('/[^a-zA-Z0-9]/', '', $data['cmd_encounter']) : 'encounter'
];

// Load existing env vars if any
$env_file = __DIR__ . "/bot_env.json";
$env_vars = file_exists($env_file) ? json_decode(file_get_contents($env_file), true) : [];

// Update env vars with new values
$env_vars['TBOT_COOLDOWN'] = strval($cooldown);
$env_vars['TBOT_MODS_ONLY'] = $modsOnly ? "1" : "0";
$env_vars['TBOT_SUBS_ONLY'] = $subsOnly ? "1" : "0";
$env_vars['TBOT_WHITELIST_ENABLED'] = $whitelistEnabled ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'] = $rolemasterInstruction ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'] = $rolemasterSuggestion ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'] = $rolemasterImpersonation ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_SPAWN_ENABLED'] = $rolemasterSpawn ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_ENCOUNTER_ENABLED'] = $rolemasterEncounter ? "1" : "0";
$env_vars['TBOT_USE_COMMAND_PREFIX'] = $useCommandPrefix ? "1" : "0";
$env_vars['TBOT_COMMAND_PREFIX'] = $commandPrefix;
$env_vars['TBOT_HELP_KEYWORDS'] = $helpKeywords;
$env_vars['TBOT_COMMAND_NAME_MAP'] = json_encode($commandNameMap);

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
            'rolemasterEncounter' => $env_vars['TBOT_ROLEMASTER_ENCOUNTER_ENABLED'] === "1",
            'useCommandPrefix' => $env_vars['TBOT_USE_COMMAND_PREFIX'] === "1",
            'commandPrefix' => $env_vars['TBOT_COMMAND_PREFIX'],
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