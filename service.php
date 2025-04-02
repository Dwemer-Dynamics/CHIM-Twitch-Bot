#!/usr/bin/php

<?php

error_reporting(E_ALL);
// Enable async signals (PHP 7.1+)
pcntl_async_signals(true);

$TWITCH_IRC_SERVER = "irc.chat.twitch.tv";
$TWITCH_IRC_PORT = 6667;
$USERNAME = getenv("TBOT_USERNAME");
$OAUTH_TOKEN = "oauth:" . getenv("TBOT_OAUTH");
$CHANNEL = getenv("TBOT_CHANNEL");

echo date(DATE_RFC2822) . PHP_EOL;
echo "Using config values:
TWITCH_IRC_SERVER: $TWITCH_IRC_SERVER
USERNAME: $USERNAME
OAUTH_TOKEN: $OAUTH_TOKEN
CHANNEL: $CHANNEL
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
    
    echo "📡 Joined #$channel\n";
    
    $last_ping = time();
    
    while (!feof($socket)) {
        // Check for incoming messages
        $data = fgets($socket, 512);
        
        if ($data) {
            echo "📩 Raw Message: $data";
            
            // Respond to PING to avoid disconnection
            if (strpos($data, "PING") === 0) {
                fwrite($socket, "PONG :tmi.twitch.tv\r\n");
                $last_ping = time();
                continue;
            }
            
            // Parse chat messages
            if (preg_match('/:([^!]+)!.* PRIVMSG #\w+ :(.*)/', $data, $matches)) {
                $user = $matches[1];
                $message = trim($matches[2]);
                
                echo "💬 $user: $message\n";
                
                if (strpos($message, "Rolemaster:") === 0) {
                    $command = trim(substr($message, strlen("Rolemaster:")));
                    echo "🧙‍♂️ Command Detected: $command\n";
                    executeRolemasterCommand($socket, $channel, $user, $command);
                }
            }
        }
        
        // Check if we haven't received a PING in a while
        if (time() - $last_ping > 300) { // 5 minutes
            echo "⚠️ No PING received for 5 minutes, reconnecting...\n";
            break;
        }
        
        usleep(100000); // Sleep for 100ms to prevent CPU hogging
    }
    
    fclose($socket);
}

function executeRolemasterCommand($socket, $channel, $user, $command)
{
    echo "📝 Executing command from $user: $command\n";
    $escapedCommand = escapeshellarg($command);
    exec("php /var/www/html/HerikaServer/service/manager.php $escapedCommand", $output, $returnCode);
    $response = $returnCode === 0 ? "Command accepted" : "❌ Error executing command";
    sendMessage($socket, $channel, $response);
}

function sendMessage($socket, $channel, $message)
{
    fwrite($socket, "PRIVMSG #$channel :$message\r\n");
}