<?php
$bot_script =   __DIR__."/service.php";      // Bot script path
$pid_file =     __DIR__."/bot_pid.txt";         // File to store PID
$log_file =     __DIR__."/bot_output.log";      // Log file for bot output

// Store environment variables
$env_file = __DIR__ . "/bot_env.json";
$env_vars = file_exists($env_file) ? json_decode(file_get_contents($env_file), true) : [];

if (!file_exists( __DIR__."/vendor/autoload.php")) {
    error_log("Addon not installed.... installing");
    $install_command = "cd ". __DIR__." && HOME=".__DIR__."  composer install";
    error_log("$install_command");

    shell_exec("$install_command");
}

// Add new endpoint for fetching logs
if (isset($_GET['fetch_logs'])) {
    header('Content-Type: application/json');
    $log_content = file_exists($log_file) ? array_slice(file($log_file), -25) : [];
    $log_content = array_reverse($log_content);
    echo json_encode(['logs' => $log_content]);
    exit;
}

$notification = '';
$notification_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['tbot_username'], $_POST['tbot_oauth'], $_POST['tbot_channel'])) {
        $env_vars = [
            "TBOT_USERNAME" => trim($_POST['tbot_username']),
            "TBOT_OAUTH" => trim($_POST['tbot_oauth']),
            "TBOT_CHANNEL" => trim($_POST['tbot_channel']),
            "TBOT_COOLDOWN" => trim($_POST['tbot_cooldown']),
            "TBOT_MODS_ONLY" => isset($_POST['tbot_mods_only']) ? "1" : "0",
        ];
        file_put_contents($env_file, json_encode($env_vars));
    }

    if ($_POST['action'] === 'start') {
        if (file_exists($pid_file)) {
            $notification = "⚠️ Bot is already running!";
            $notification_type = "warning";
        } else {
            // Set environment variables and start the bot
            $env_command = "TBOT_USERNAME='{$env_vars["TBOT_USERNAME"]}' "
                        . "TBOT_OAUTH='{$env_vars["TBOT_OAUTH"]}' "
                        . "TBOT_CHANNEL='{$env_vars["TBOT_CHANNEL"]}' "
                        . "TBOT_COOLDOWN='{$env_vars["TBOT_COOLDOWN"]}' "
                        . "TBOT_MODS_ONLY='{$env_vars["TBOT_MODS_ONLY"]}'";
            $command = "$env_command php $bot_script > $log_file 2>&1 & echo $!";
            error_log($command);
            $pid = shell_exec($command);

            file_put_contents($pid_file, $pid);
            $notification = "Bot started successfully";
            $notification_type = "success";
        }
    } elseif ($_POST['action'] === 'stop') {
        if (file_exists($pid_file)) {
            $pid = file_get_contents($pid_file);
            shell_exec("kill $pid"); // Stop the bot
            unlink($pid_file);
            $notification = "Bot stopped";
            $notification_type = "error";
        } else {
            $notification = "Bot is not running";
            $notification_type = "warning";
        }
    } elseif ($_POST['action'] === 'refresh') {
        header("Location: ".$_SERVER['PHP_SELF']); // Refresh the page
        exit;
    }
}

// Function to check if the bot is running by verifying both
// the existence of the pid file and if a process with that pid exists.
function is_bot_running($pid_file) {
    if (file_exists($pid_file)) {
        $pid = (int) trim(file_get_contents($pid_file));
        // Check if the process exists using posix_getpgid.
        if (function_exists('posix_getpgid')) {
            if (@posix_getpgid($pid) !== false) {
                return true;
            } else {
                // PID file exists but no process found, remove the stale PID file.
                unlink($pid_file);
                return false;
            }
        }
        // If posix_getpgid() isn't available, a fallback to just file existence.
        return true;
    }
    return false;
}

// Check if bot is running
$is_running = is_bot_running($pid_file);
$log_content = file_exists($log_file) ? array_slice(file($log_file), -25) : []; // Get last 15 lines
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>CHIM Twitch Bot Control</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="main.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        let lastLogContent = '';
        
        function updateLogs() {
            fetch('index.php?fetch_logs')
                .then(response => response.json())
                .then(data => {
                    const newContent = data.logs.join('<br>');
                    if (newContent !== lastLogContent) {
                        document.querySelector('.log-box').innerHTML = newContent || 'No logs yet.';
                        lastLogContent = newContent;
                    }
                })
                .catch(error => console.error('Error fetching logs:', error));
        }

        function showToast(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <span class="toast-icon">${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'}</span>
                <span class="toast-message">${message}</span>
                <button class="toast-close" onclick="this.parentElement.remove()">×</button>
            `;
            toastContainer.appendChild(toast);
            
            // Trigger reflow to enable animation
            toast.offsetHeight;
            toast.classList.add('show');

            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Update logs every 2 seconds
        setInterval(updateLogs, 2000);

        // Initial update
        document.addEventListener('DOMContentLoaded', () => {
            updateLogs();
            <?php if ($notification): ?>
            showToast(<?= json_encode($notification) ?>, <?= json_encode($notification_type) ?>);
            <?php endif; ?>
        });
    </script>
</head>
<body>
    <div class="toast-container"></div>
    <h1><img src="images/ClavicusVileMask.png" alt="Clavicus Vile Mask" class="title-icon"> CHIM Twitch Bot Control Panel</h1>

    <div class="page-grid">
        <div class="connection-settings">
            <form method="post" id="bot-form">
                <h2>⚙️ Twitch Connection Settings</h2>
                <div class="input-group">
                    <div class="input-container">
                        <label for="username">Bot Username</label>
                        <input type="text" id="username" name="tbot_username" placeholder="Username" value="<?= htmlspecialchars($env_vars['TBOT_USERNAME'] ?? '') ?>" required>
                    </div>
                    <div class="input-container">
                        <label for="oauth">OAuth Token</label>
                        <input type="password" id="oauth" name="tbot_oauth" placeholder="OAUTH TOKEN" value="<?= htmlspecialchars($env_vars['TBOT_OAUTH'] ?? '') ?>" required>
                        <a href="https://twitchtokengenerator.com/" target="_blank">Obtain OAuth Token</a>
                    </div>
                    <div class="input-container">
                        <label for="channel">Channel Name</label>
                        <input type="text" id="channel" name="tbot_channel" placeholder="Channel Name" value="<?= htmlspecialchars($env_vars['TBOT_CHANNEL'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div class="settings-group">
                    <div class="cooldown-container">
                        <input type="number" id="cooldown" name="tbot_cooldown" placeholder="30" min="0" value="<?= htmlspecialchars($env_vars['TBOT_COOLDOWN'] ?? '30') ?>" required>
                        <label for="cooldown">Command Cooldown (seconds)</label>
                    </div>
                    <div class="toggle-container">
                        <label class="toggle-switch">
                            <input type="checkbox" name="tbot_mods_only" <?= ($env_vars['TBOT_MODS_ONLY'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Mods Only Mode</span>
                    </div>
                </div>

                <p class="status">Status: <?= $is_running ? "🟢 Running" : "🔴 Stopped" ?></p>
                <div class="button-group">
                    <button type="submit" name="action" value="<?= $is_running ? 'stop' : 'start' ?>" class="<?= $is_running ? 'stop-btn' : 'start-btn' ?>">
                        <?= $is_running ? '⏹ Stop Bot' : '▶️ Start Bot' ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="commands-section">
            <h2>🎯 Available Commands</h2>
            <div class="command-card">
                <h3>Rolemaster:instruction:</h3>
                <p class="command-description"><i>Will <b>[immediately]</b> prompt AI NPC's in the vicinity to follow your commands to the best of their ability.</i></p>
                <p class="command-example">E.G. Rolemaster:instruction: Make Mikael tell a story.</p>
            </div>
            <div class="command-card">
                <h3>Rolemaster:suggestion:</h3>
                <p class="command-description"><i>Will <b>[eventually during the next pause in AI conversation] </b> prompt AI NPC's in the vicinity to follow your commands to the best of their ability.</i></p>
                <p class="command-example">E.G. Rolemaster:suggestion: Make Mikael tell a story.</p>
            </div>
            <div class="command-card">
                <h3>Rolemaster:impersonation:</h3>
                <p class="command-description"><i>The player character will repeat what is entered.</i></p>
                <p class="command-example">Rolemaster:impersonation: Why did the chicken cross the road?</p>
            </div>
        </div>

        <div class="log-section">
            <h2>
                📜 Bot Output
                <button type="submit" name="action" value="refresh" class="refresh-btn" form="bot-form" onclick="updateLogs(); return false;">🔄 Refresh</button>
            </h2>
            <div class="log-box">
                <?php
                if (!empty($log_content)) {
                    $log_content = array_reverse($log_content);
                    echo implode("<br>", $log_content);
                } else {
                    echo "No logs yet.";
                }
                ?>
            </div>
        </div>
    </div>
</body>
</html>
