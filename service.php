#!/usr/bin/php

<?php

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
$COOLDOWN = intval(getenv("TBOT_COOLDOWN") ?: "30"); // Default to 30 if not set
$MODS_ONLY = (getenv("TBOT_MODS_ONLY") ?: "0") === "1"; // Default to false if not set

// Store last command time and invalid command tracking
$last_command_time = 0;
$invalid_command_count = 0;
$last_invalid_time = 0;

// Store channel moderators
$moderators = [];
$channel_owner = strtolower($CHANNEL);

echo date(DATE_RFC2822) . PHP_EOL;
echo "Using config values:
TWITCH_IRC_SERVER: $TWITCH_IRC_SERVER
USERNAME: $USERNAME
OAUTH_TOKEN: $OAUTH_TOKEN
CHANNEL: $CHANNEL
COOLDOWN: $COOLDOWN seconds
MODS ONLY: " . ($MODS_ONLY ? "Yes" : "No") . "
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
        runBot($TWITCH_IRC_SERVER, $TWITCH_IRC_PORT, $USERNAME, $OAUTH_TOKEN, $CHANNEL);
        exit(0);
    }
}

function runBot($server, $port, $username, $oauth, $channel)
{
    global $moderators;
    echo "🔌 Connecting to Twitch Chat...\n";
    
    $socket = fsockopen($server, $port, $errno, $errstr, 30);
    if (!$socket) {
        echo "🚨 Failed to connect: $errstr ($errno)\n";
        return;
    }
    
    stream_set_blocking($socket, false);
    
    echo "✅ Connected to Twitch Chat\n";
    
    // Authenticate
    fwrite($socket, "PASS $oauth\r\n");
    fwrite($socket, "NICK $username\r\n");
    fwrite($socket, "JOIN #$channel\r\n");
    // Request moderator capabilities
    fwrite($socket, "CAP REQ :twitch.tv/commands twitch.tv/tags\r\n");
    
    echo "📡 Joined #$channel\n";
    
    $last_ping = time();
    
    while (!feof($socket)) {
        $data = fgets($socket, 512);
        
        if ($data) {
            echo "📩 Raw Message: $data";
            
            // Handle PING
            if (strpos($data, "PING") === 0) {
                fwrite($socket, "PONG :tmi.twitch.tv\r\n");
                $last_ping = time();
                continue;
            }
            
            // Check for moderator messages
            if (strpos($data, "USERSTATE") !== false && strpos($data, "mod=1") !== false) {
                preg_match('/login=([^;]+)/', $data, $matches);
                if (isset($matches[1])) {
                    $moderators[] = strtolower($matches[1]);
                }
            }
            
            // Parse chat messages
            if (preg_match('/:([^!]+)!.* PRIVMSG #\w+ :(.*)/', $data, $matches)) {
                $user = strtolower($matches[1]);
                $message = trim($matches[2]);
                
                echo "💬 $user: $message\n";
                
                if (strpos($message, "Rolemaster:") === 0) {
                    if (canUserUseCommands($user)) {
                        parseRolemasterCommand($socket, $channel, $user, $message);
                    }
                }
            }
        }
        
        // Check if we haven't received a PING in a while
        if (time() - $last_ping > 300) {
            echo "⚠️ No PING received for 5 minutes, reconnecting...\n";
            break;
        }
        
        usleep(100000);
    }
    
    fclose($socket);
}

function canUserUseCommands($user) {
    global $MODS_ONLY, $moderators, $channel_owner;
    
    if (!$MODS_ONLY) {
        return true;
    }
    
    $user = strtolower($user);
    return $user === $channel_owner || in_array($user, $moderators);
}

function executeCommand($socket, $channel, $user, $task, $type, $freeText)
{
    global $COOLDOWN, $invalid_command_count;
    echo "📝 Executing $task command ($type) from $user: $freeText\n";

    // Input validation - only allow alphanumeric characters, spaces, and basic punctuation
    if (!preg_match('/^[a-zA-Z0-9\s\.,!?]+$/', $freeText)) {
        handleInvalidCommand($socket, $channel, "❌ Invalid command format. Only letters, numbers, spaces and basic punctuation are allowed.");
        return false;
    }

    // Command length limit
    if (strlen($freeText) > 1024) {
        handleInvalidCommand($socket, $channel, "❌ Command too long. Maximum length is 1024 characters.");
        return false;
    }

    // Sanitize the free text
    $sanitizedFreeText = preg_replace('/[^a-zA-Z0-9\s\.,!?]/', '', $freeText);

    // Use absolute path for the PHP executable
    $phpPath = '/usr/bin/php';
    if (!file_exists($phpPath)) {
        handleInvalidCommand($socket, $channel, "❌ System error: PHP executable not found");
        return false;
    }

    // Use absolute path for the script
    $scriptPath = '/var/www/html/HerikaServer/service/manager.php';
    if (!file_exists($scriptPath)) {
        handleInvalidCommand($socket, $channel, "❌ System error: Manager script not found");
        return false;
    }

    // Execute command with proper escaping and using absolute paths
    $cmd = sprintf('%s %s %s %s %s',
        escapeshellarg($phpPath),
        escapeshellarg($scriptPath),
        escapeshellarg($task),
        escapeshellarg($type),
        escapeshellarg($sanitizedFreeText)
    );

    // Execute with proper error handling
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0) {
        // Reset invalid command count on successful command
        $invalid_command_count = 0;
        sendMessage($socket, $channel, "Command accepted ($COOLDOWN second cooldown until next command)");
        return true;
    } else {
        handleInvalidCommand($socket, $channel, "❌ Error executing command");
        return false;
    }
}

function parseRolemasterCommand($socket, $channel, $user, $message)
{
    global $last_command_time, $COOLDOWN, $invalid_command_count, $last_invalid_time;
    
    // Check cooldown
    $current_time = time();
    $time_since_last = $current_time - $last_command_time;
    
    if ($time_since_last < $COOLDOWN) {
        // Silently ignore during cooldown
        return;
    }

    // Reset invalid command count if more than 10 seconds have passed
    if ($current_time - $last_invalid_time > 10) {
        $invalid_command_count = 0;
    }

    // Parse the message in the format: Rolemaster:type of command:free text
    if (preg_match('/^Rolemaster:([^:]+):(.*)$/', $message, $matches)) {
        $type = trim($matches[1]);
        $freeText = trim($matches[2]);

        // Validate the type
        $validTypes = ['instruction', 'suggestion', 'impersonation'];
        if (!in_array($type, $validTypes)) {
            handleInvalidCommand($socket, $channel, "❌ Invalid command type. Valid types are: " . implode(', ', $validTypes));
            return;
        }

        // Execute the command
        if (executeCommand($socket, $channel, $user, 'rolemaster', $type, $freeText)) {
            // Reset invalid command count on successful command
            $invalid_command_count = 0;
            // Update cooldown time for successful command
            $last_command_time = $current_time;
        }
    } else {
        handleInvalidCommand($socket, $channel, "❌ Invalid command format. Use: Rolemaster:type of command:free text");
    }
}

function handleInvalidCommand($socket, $channel, $errorMessage) {
    global $invalid_command_count, $last_invalid_time, $last_command_time, $COOLDOWN;
    
    $current_time = time();
    $last_invalid_time = $current_time;
    $invalid_command_count++;

    if ($invalid_command_count >= 3) {
        // Trigger cooldown after 3 invalid attempts
        $last_command_time = $current_time;
        $invalid_command_count = 0;
        sendMessage($socket, $channel, "$errorMessage (Cooldown activated due to multiple invalid attempts)");
    } else {
        sendMessage($socket, $channel, $errorMessage);
    }
}

function sendMessage($socket, $channel, $message)
{
    fwrite($socket, "PRIVMSG #$channel :$message\r\n");
}