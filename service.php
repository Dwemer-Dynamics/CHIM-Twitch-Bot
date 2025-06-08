#!/usr/bin/php

<?php

require_once __DIR__ . '/command.php';

error_reporting(E_ALL);
// Enable async signals (PHP 7.1+)
pcntl_async_signals(true);

// Load environment variables from bot_env.json
$env_file = __DIR__ . "/bot_env.json";
if (file_exists($env_file)) {
    $env_vars = json_decode(file_get_contents($env_file), true);
    if ($env_vars) {
        foreach ($env_vars as $key => $value) {
            putenv("$key=$value");
        }
    }
}

$TWITCH_IRC_SERVER = "irc.chat.twitch.tv";
$TWITCH_IRC_PORT = 6667;
$USERNAME = getenv("TBOT_USERNAME");
$OAUTH_TOKEN = "oauth:" . str_replace("oauth:", "", getenv("TBOT_OAUTH")); // Remove oauth: if already present
$CHANNEL = getenv("TBOT_CHANNEL");
// Ensure cooldown is at least 10, default to 30 if not set or invalid
$COOLDOWN = max(10, intval(getenv('TBOT_COOLDOWN') ?: 30));
$MODS_ONLY = (getenv("TBOT_MODS_ONLY") ?: "0") === "1"; // Default to false if not set
$SUBS_ONLY = (getenv("TBOT_SUBS_ONLY") ?: "0") === "1"; // Default to false if not set
$WHITELIST_ENABLED = (getenv("TBOT_WHITELIST_ENABLED") ?: "0") === "1"; // Default to false if not set
$COMMAND_PREFIX = getenv("TBOT_COMMAND_PREFIX") ?: "Rolemaster"; // Default to "Rolemaster" if not set

// Add these with other environment variables at the top
$ROLEMASTER_INSTRUCTION_ENABLED = (getenv("TBOT_ROLEMASTER_INSTRUCTION_ENABLED") ?: "0") === "1"; // Default to false
$ROLEMASTER_SUGGESTION_ENABLED = (getenv("TBOT_ROLEMASTER_SUGGESTION_ENABLED") ?: "0") === "1"; // Default to false
$ROLEMASTER_IMPERSONATION_ENABLED = (getenv("TBOT_ROLEMASTER_IMPERSONATION_ENABLED") ?: "0") === "1"; // Default to false
$ROLEMASTER_SPAWN_ENABLED = (getenv("TBOT_ROLEMASTER_SPAWN_ENABLED") ?: "0") === "1"; // Default to false
$USE_COMMAND_PREFIX = (getenv("TBOT_USE_COMMAND_PREFIX") ?: "1") === "1"; // Default to true

// Help keywords configuration
$HELP_KEYWORDS = array_filter(explode(',', getenv("TBOT_HELP_KEYWORDS") ?: "help,ai,Rolemaster,rp"));

// Global variables for command timing
$last_command_time = 0;
$invalid_command_count = 0;
$last_invalid_time = 0;

// Store channel moderators
$moderators = array();
$followers = array();
$channel_owner = strtolower($CHANNEL);

// Add these global variables at the top with other globals
$whitelist = array();
$blacklist = array();

// Add this after loading other settings
$lists_file = __DIR__ . "/user_lists.json";
if (file_exists($lists_file)) {
    $lists = json_decode(file_get_contents($lists_file), true);
    if (is_array($lists)) {
        $whitelist = $lists['whitelist'] ?? array();
        $blacklist = $lists['blacklist'] ?? array();
    }
}

// Global variables for list updates
$lists_check_interval = 5; // Check for list updates every 5 seconds
$last_lists_check = time();
$lists_flag_file = __DIR__ . '/lists_updated.flag';

// Global variable for command handler
$commandHandler = null;

// Add this function at the top with other functions
function censorSensitiveInfo($text) {
    // Censor OAuth tokens
    $text = preg_replace('/oauth:[a-z0-9]+/', 'oauth:********', $text);
    return $text;
}

echo date(DATE_RFC2822) . PHP_EOL;
echo "Using config values:
TWITCH_IRC_SERVER: $TWITCH_IRC_SERVER
USERNAME: $USERNAME
OAUTH_TOKEN: " . censorSensitiveInfo($OAUTH_TOKEN) . "
CHANNEL: $CHANNEL
COOLDOWN: $COOLDOWN seconds
MODS ONLY: " . ($MODS_ONLY ? "Yes" : "No") . "
SUBS ONLY: " . ($SUBS_ONLY ? "Yes" : "No") . "
WHITELIST: " . ($WHITELIST_ENABLED ? "Yes" : "No") . "
ROLEMASTER COMMANDS:
  - Instruction: " . ($ROLEMASTER_INSTRUCTION_ENABLED ? "Yes" : "No") . "
  - Suggestion: " . ($ROLEMASTER_SUGGESTION_ENABLED ? "Yes" : "No") . "
  - Impersonation: " . ($ROLEMASTER_IMPERSONATION_ENABLED ? "Yes" : "No") . "
  - Spawn: " . ($ROLEMASTER_SPAWN_ENABLED ? "Yes" : "No") . "
" . PHP_EOL;

$child_pid = null;
$terminate = false;

// Handle termination signals
function shutdownHandler($signal)
{
    global $child_pid, $terminate;
    echo "⚠️ Received termination signal ($signal), stopping bot...\n";
    $terminate = true;
    
    if ($child_pid) {
        echo "🛑 Killing child process (PID: $child_pid)\n";
        posix_kill($child_pid, SIGTERM);
        $wait = 0;
        while ($wait < 10 && posix_kill($child_pid, 0)) {
            sleep(1);
            $wait++;
        }
    }
    echo "✅ Bot stopped cleanly.\n";
    exit(0);
}

// Register signal handlers
pcntl_signal(SIGTERM, "shutdownHandler");
pcntl_signal(SIGINT,  "shutdownHandler");

// Parent Process Loop: Create/restart child processes
while (true) {
    $child_pid = pcntl_fork();
    if ($child_pid == -1) {
        die("❌ Fork failed!\n");
    } elseif ($child_pid) {
        // 👨 Parent process
        echo "🟢 Parent waiting for child (PID: $child_pid)\n";
        
        do {
            $wait = pcntl_waitpid($child_pid, $status, WNOHANG);
            if ($wait === 0) {
                sleep(1);
            }
        } while ($wait === 0 && !$terminate);
        
        if ($terminate) {
            break;
        }
        
        echo "🔄 Child process (PID: $child_pid) died. Restarting...\n";
        sleep(2);
    } else {
        // 👶 Child process: Connect to IRC
        echo "🚸 Child process started (PID: " . getmypid() . ")\n";
        runBot($TWITCH_IRC_SERVER, $TWITCH_IRC_PORT, $USERNAME, $OAUTH_TOKEN, $CHANNEL);
        echo "👋 Child process exiting normally\n";
        exit(0);
    }
}

function runBot($server, $port, $username, $oauth, $channel)
{
    global $moderators, $followers;
    echo "🔌 Connecting to Twitch Chat...\n";
    
    $socket = fsockopen($server, $port, $errno, $errstr, 30);
    if (!$socket) {
        echo "🚨 Failed to connect: $errstr ($errno)\n";
        return;
    }
    
    stream_set_blocking($socket, false);
    echo "✅ Connected to Twitch Chat\n";
    
    // Authenticate
    echo "🔑 Sending authentication...\n";
    fwrite($socket, "PASS $oauth\r\n");
    fwrite($socket, "NICK $username\r\n");
    fwrite($socket, "JOIN #$channel\r\n");
    // Request moderator capabilities
    fwrite($socket, "CAP REQ :twitch.tv/commands twitch.tv/tags\r\n");
    
    echo "📡 Joined #$channel\n";
    
    $last_ping = time();
    $last_data_time = time();
    $connection_check_time = time();
    
    // Initialize command handler after socket is established
    try {
        echo "🔧 Initializing CommandHandler...\n";
        $commandHandler = new CommandHandler($socket, $channel);
        echo "✅ CommandHandler initialized successfully\n";
    } catch (Exception $e) {
        echo "❌ Failed to initialize CommandHandler: " . $e->getMessage() . "\n";
        if ($socket) {
            fclose($socket);
        }
        return;
    }
    
    // Setup lock file
    $lock_file = sys_get_temp_dir() . "/twitch_bot_" . $channel . ".lock";
    if (!file_exists($lock_file)) {
        touch($lock_file);
    }
    
    echo "🔄 Entering main message loop\n";
    
    while (!feof($socket)) {
        $data = fgets($socket, 512);
        $current_time = time();
        
        if ($data) {
            $last_data_time = $current_time;
            if (trim($data) !== "") {
                echo "📩 Raw Message: " . trim($data) . "\n";
            }
            
            // Handle PING
            if (strpos($data, "PING") === 0) {
                fwrite($socket, "PONG :tmi.twitch.tv\r\n");
                $last_ping = $current_time;
                echo "🏓 PING-PONG handled\n";
                continue;
            }
            
            // Parse chat messages
            if (preg_match('/^@([^ ]+) :([^!]+)!.* PRIVMSG #\w+ :(.*)/', $data, $matches)) {
                $tags_str = $matches[1];
                $user = strtolower($matches[2]);
                $message = trim($matches[3]);
                
                // Parse tags into an associative array
                $tags = array();
                $tags_parts = explode(';', $tags_str);
                foreach ($tags_parts as $part) {
                    list($key, $value) = explode('=', $part, 2);
                    $tags[$key] = $value;
                }
                
                // Determine subscriber and moderator status from tags
                $isMod = (isset($tags['badges']) && strpos($tags['badges'], 'moderator/') !== false);
                $isSub = (isset($tags['subscriber']) && $tags['subscriber'] === '1') || 
                         (isset($tags['badges']) && strpos($tags['badges'], 'subscriber/') !== false);
                
                echo "💬 $user: $message (Sub: " . ($isSub ? 'Yes' : 'No') . ", Mod: " . ($isMod ? 'Yes' : 'No') . ")\n";
                
                // Let CommandHandler handle the message
                $commandHandler->parseCommand($user, $message, $isMod, $isSub);
            }
        }
        
        // Connection health checks
        if ($current_time - $connection_check_time >= 30) {
            echo "🔍 Connection health check - Last data: " . ($current_time - $last_data_time) . "s ago\n";
            $connection_check_time = $current_time;
            
            // Test write to socket
            $write_result = @fwrite($socket, "");
            if ($write_result === false) {
                echo "❌ Socket write test failed - connection lost\n";
                break;
            }
        }
        
        // Check for stale connection
        if ($current_time - $last_data_time > 180) {
            echo "⚠️ No data received for 3 minutes, connection may be stale\n";
            break;
        }
        
        // Check for missing PINGs
        if ($current_time - $last_ping > 300) {
            echo "⚠️ No PING received for 5 minutes, reconnecting...\n";
            break;
        }
        
        usleep(100000); // 100ms sleep
    }
    
    echo "🔚 Exiting message loop - " . (feof($socket) ? "EOF reached" : "Connection check failed") . "\n";
    
    if ($socket) {
        fclose($socket);
        echo "🔌 Socket closed\n";
    }
}

function canUserUseCommands($user, $isMod = false, $isSub = false) {
    global $MODS_ONLY, $SUBS_ONLY, $WHITELIST_ENABLED;
    global $channel_owner;
    global $whitelist, $blacklist;
    
    $user = strtolower($user);
    
    // STEP 1: Check blacklist (Always active)
    if (in_array($user, $blacklist) && $user !== $channel_owner) {
        error_log("❌ User $user blocked - User is blacklisted");
        return false;
    }
    
    // STEP 2: Channel owner and mods always have permission (except if blacklisted)
    if ($user === $channel_owner || $isMod) {
        error_log("✅ User $user is channel owner/mod - Command allowed");
        return true;
    }
    
    // STEP 3: Whitelist Logic - If enabled, *only* allow whitelisted users (plus owner/mods checked above)
    if ($WHITELIST_ENABLED) {
        if (in_array($user, $whitelist)) {
            error_log("✅ User $user is whitelisted - Command allowed");
            return true;
        } else {
            error_log("❌ User $user blocked - Allowed Users Only mode is ON and user is not listed");
            return false; // Block everyone else if whitelist is enabled
        }
    }
    
    // STEP 4 & 5: Apply Mods/Subs restrictions only if Whitelist is OFF
    // If Mods Only mode is on, block non-mods (already checked mods above)
    if ($MODS_ONLY) {
        error_log("❌ User $user blocked - Mods Only mode is on");
        return false;
    }
    
    // If Subscribers Only mode is on, check if user is a subscriber
    if ($SUBS_ONLY) {
        if (!$isSub) {
            error_log("❌ User $user blocked - Subs Only mode is on and user is not a subscriber");
            return false;
        }
        error_log("✅ User $user is a subscriber - Proceeding");
    }
    
    // STEP 6: If we got here, Whitelist is OFF, and user wasn't blocked by Mods/Subs Only
    error_log("✅ User $user passed standard permission checks - Command allowed");
    return true;
}


// Example function to demonstrate permission checks
function testPermissions() {
    global $MODS_ONLY, $SUBS_ONLY;
    global $moderators, $subscribers, $channel_owner;
    
    // Test setup
    $channel_owner = "channel_owner";
    $moderators = ["mod1", "mod2"];
    $subscribers = ["sub1", "sub2"];
    
    echo "\n=== Permission Test Cases ===\n";
    
    // Test Case 1: All modes OFF
    $MODS_ONLY = false; $SUBS_ONLY = false;
    echo "\nTest Case 1 - All modes OFF:\n";
    echo "Regular user: " . (canUserUseCommands("regular_user") ? "✅" : "❌") . "\n";
    
    // Test Case 2: Mods Only mode ON
    $MODS_ONLY = true; $SUBS_ONLY = false;
    echo "\nTest Case 2 - Mods Only mode ON:\n";
    echo "Mod: " . (canUserUseCommands("mod1") ? "✅" : "❌") . "\n";
    echo "Regular user: " . (canUserUseCommands("regular_user") ? "✅" : "❌") . "\n";
    
    // Test Case 3: Subs Only mode ON
    $MODS_ONLY = false; $SUBS_ONLY = true;
    echo "\nTest Case 3 - Subs Only mode ON:\n";
    echo "Subscriber: " . (canUserUseCommands("sub1") ? "✅" : "❌") . "\n";
    echo "Non-subscriber: " . (canUserUseCommands("regular_user") ? "✅" : "❌") . "\n";
    
    // Test Case 4: All modes ON (excluding Follower)
    $MODS_ONLY = true; $SUBS_ONLY = true;
    echo "\nTest Case 6 - Mods and Subs Only ON:\n";
    echo "Mod: " . (canUserUseCommands("mod1") ? "✅" : "❌") . "\n";
    echo "Channel owner: " . (canUserUseCommands("channel_owner") ? "✅" : "❌") . "\n";
    
    echo "\n=== End of Test Cases ===\n";
}

// Add this function to demonstrate all permission combinations
function testPermissionsWithLists() {
    global $MODS_ONLY, $SUBS_ONLY;
    global $moderators, $subscribers, $channel_owner;
    global $whitelist, $blacklist;
    
    // Test setup
    $channel_owner = "channel_owner";
    $moderators = ["mod1", "mod2"];
    $subscribers = ["sub1", "sub2"];
    $whitelist = ["whitelisted_user"];
    $blacklist = ["blacklisted_user", "blacklisted_mod"];
    
    echo "\n=== Permission Test Cases with Whitelist/Blacklist ===\n";
    
    // Test Case 1: Blacklist overrides everything except channel owner
    echo "\nTest Case 1 - Blacklist Override:\n";
    echo "Blacklisted regular user: " . (canUserUseCommands("blacklisted_user") ? "✅" : "❌") . "\n";
    echo "Blacklisted mod: " . (canUserUseCommands("blacklisted_mod") ? "✅" : "❌") . "\n";
    echo "Blacklisted channel owner: " . (canUserUseCommands($channel_owner) ? "✅" : "❌") . "\n";
    
    // Test Case 2: Whitelist bypasses restrictions
    $MODS_ONLY = true;
    echo "\nTest Case 2 - Whitelist Bypass:\n";
    echo "Whitelisted user (Mods Only ON): " . (canUserUseCommands("whitelisted_user") ? "✅" : "❌") . "\n";
    
    // Test Case 3: Complex permission scenario
    $MODS_ONLY = false;
    $SUBS_ONLY = true;
    echo "\nTest Case 3 - Complex Scenario (Subs Only):\n";
    echo "Whitelisted non-sub: " . (canUserUseCommands("whitelisted_user") ? "✅" : "❌") . "\n";
    echo "Non-whitelisted sub: " . (canUserUseCommands("sub1") ? "✅" : "❌") . "\n";
    echo "Blacklisted sub: " . (canUserUseCommands("blacklisted_user") ? "✅" : "❌") . "\n";
    
    echo "\n=== End of Test Cases ===\n";
}

// Add this function to reload the user lists
function reloadUserLists() {
    global $whitelist, $blacklist;
    
    $lists_file = __DIR__ . '/user_lists.json';
    if (file_exists($lists_file)) {
        try {
            $lists_data = json_decode(file_get_contents($lists_file), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $whitelist = isset($lists_data['whitelist']) ? $lists_data['whitelist'] : array();
                $blacklist = isset($lists_data['blacklist']) ? $lists_data['blacklist'] : array();
                echo date(DATE_RFC2822) . " - User lists reloaded successfully\n";
            } else {
                echo date(DATE_RFC2822) . " - Error parsing user lists JSON: " . json_last_error_msg() . "\n";
            }
        } catch (Exception $e) {
            echo date(DATE_RFC2822) . " - Error reading user lists file: " . $e->getMessage() . "\n";
        }
    } else {
        // Initialize empty lists if file doesn't exist
        $whitelist = array();
        $blacklist = array();
    }
    
    // Remove the flag file after processing
    if (file_exists($GLOBALS['lists_flag_file'])) {
        unlink($GLOBALS['lists_flag_file']);
    }
}

function checkCommandTiming() {
    global $last_command_time, $COOLDOWN, $invalid_command_count, $last_invalid_time;
    
    $current_time = time();
    
    // Check cooldown
    if ($current_time - $last_command_time < $COOLDOWN) {
        return false;
    }
    
    // Reset invalid command count if more than 5 minutes have passed
    if ($current_time - $last_invalid_time > 300) {
        $invalid_command_count = 0;
    }
    
    // Check rate limiting
    if ($invalid_command_count >= 5) {
        return false;
    }
    
    $last_command_time = $current_time;
    return true;
}

function handleCommand($command, $username, $channel, $isMod, $isSub) {
    global $invalid_command_count, $last_invalid_time;

    // Check if user has permission to use commands
    if (!canUserUseCommands($username, $isMod, $isSub)) {
        return;
    }

    // Check command timing
    if (!checkCommandTiming()) {
        return;
    }

    // Process the command
    switch ($command) {
        case 'help':
            // Handle help command
            break;
        default:
            $invalid_command_count++;
            $last_invalid_time = time();
            break;
    }
}