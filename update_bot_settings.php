<?php
header('Content-Type: application/json');

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Set default values
$cooldown = isset($data['cooldown']) ? intval($data['cooldown']) : 30;
$modsOnly = isset($data['modsOnly']) ? $data['modsOnly'] : false;
$subsOnly = isset($data['subsOnly']) ? $data['subsOnly'] : false;
$followerOnly = isset($data['followerOnly']) ? $data['followerOnly'] : false;
$whitelistEnabled = isset($data['whitelistEnabled']) ? $data['whitelistEnabled'] : false;
$blacklistEnabled = isset($data['blacklistEnabled']) ? $data['blacklistEnabled'] : false;
$rolemasterInstruction = isset($data['rolemasterInstruction']) ? $data['rolemasterInstruction'] : true;
$rolemasterSuggestion = isset($data['rolemasterSuggestion']) ? $data['rolemasterSuggestion'] : true;
$rolemasterImpersonation = isset($data['rolemasterImpersonation']) ? $data['rolemasterImpersonation'] : true;

// Ensure cooldown is at least 0
$cooldown = max(0, $cooldown);

// Load existing env vars if any
$env_file = __DIR__ . "/bot_env.json";
$env_vars = file_exists($env_file) ? json_decode(file_get_contents($env_file), true) : [];

// Update env vars with defaults if not set
$env_vars['TBOT_COOLDOWN'] = strval($cooldown);
$env_vars['TBOT_MODS_ONLY'] = $modsOnly ? "1" : "0";
$env_vars['TBOT_SUBS_ONLY'] = $subsOnly ? "1" : "0";
$env_vars['TBOT_FOLLOWER_ONLY'] = $followerOnly ? "1" : "0";
$env_vars['TBOT_WHITELIST_ENABLED'] = $whitelistEnabled ? "1" : "0";
$env_vars['TBOT_BLACKLIST_ENABLED'] = $blacklistEnabled ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'] = $rolemasterInstruction ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'] = $rolemasterSuggestion ? "1" : "0";
$env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'] = $rolemasterImpersonation ? "1" : "0";

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
            'followerOnly' => $env_vars['TBOT_FOLLOWER_ONLY'] === "1",
            'whitelistEnabled' => $env_vars['TBOT_WHITELIST_ENABLED'] === "1",
            'blacklistEnabled' => $env_vars['TBOT_BLACKLIST_ENABLED'] === "1",
            'rolemasterInstruction' => $env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'] === "1",
            'rolemasterSuggestion' => $env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'] === "1",
            'rolemasterImpersonation' => $env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'] === "1"
        ]
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save settings'
    ]);
}
?> 