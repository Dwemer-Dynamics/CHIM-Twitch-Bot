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
    // Load existing env vars
    $env_vars = file_exists($env_file) ? json_decode(file_get_contents($env_file), true) : [];

    // Update connection settings if they are being submitted
    if (isset($_POST['tbot_username'], $_POST['tbot_oauth'], $_POST['tbot_channel'])) {
        $env_vars = array_merge($env_vars, [
            "TBOT_USERNAME" => trim($_POST['tbot_username']),
            "TBOT_OAUTH" => trim($_POST['tbot_oauth']),
            "TBOT_CHANNEL" => trim($_POST['tbot_channel'])
        ]);
        
        // Ensure bot control settings have defaults if not set
        if (!isset($env_vars['TBOT_COOLDOWN'])) {
            $env_vars['TBOT_COOLDOWN'] = "30";
        }
        if (!isset($env_vars['TBOT_MODS_ONLY'])) {
            $env_vars['TBOT_MODS_ONLY'] = "0";
        }
        
        file_put_contents($env_file, json_encode($env_vars));
    }

    if ($_POST['action'] === 'start') {
        if (file_exists($pid_file)) {
            $notification = "Bot is already running";
            $notification_type = "warning";
        } else {
            // Set environment variables and start the bot
            $env_command = '';
            foreach ($env_vars as $key => $value) {
                $env_command .= $key . '=' . escapeshellarg($value) . ' ';
            }
            
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
            shell_exec("kill $pid");
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

        function updateControlsState(isRunning) {
            const cooldownInput = document.getElementById('cooldown');
            const modsOnlyInput = document.getElementById('mods_only');
            const saveButton = document.querySelector('.save-controls-btn');
            const settingsGroup = document.querySelector('.settings-group');
            const configureButton = document.querySelector('.settings-button');
            const connectionInputs = document.querySelectorAll('#connection-form input');
            const connectionButtons = document.querySelectorAll('#connection-form button');

            // Disable bot controls
            cooldownInput.disabled = isRunning;
            modsOnlyInput.disabled = isRunning;
            saveButton.disabled = isRunning;
            configureButton.disabled = isRunning;
            
            // Disable connection settings
            connectionInputs.forEach(input => input.disabled = isRunning);
            connectionButtons.forEach(button => button.disabled = isRunning);
            
            if (isRunning) {
                settingsGroup.classList.add('disabled');
                saveButton.innerHTML = '⚠️ Stop bot to apply new settings';
                if (document.getElementById('settingsModal').classList.contains('show')) {
                    hideModal();
                    showToast('Stop the bot before changing connection settings', 'warning');
                }
            } else {
                settingsGroup.classList.remove('disabled');
                saveButton.innerHTML = '💾 Save Bot Controls';
            }
        }

        function saveBotControls() {
            if (document.querySelector('.settings-group').classList.contains('disabled')) {
                showToast('Stop the bot before changing settings', 'warning');
                return;
            }

            const cooldown = Math.max(0, parseInt(document.getElementById('cooldown').value) || 30);
            const modsOnly = document.getElementById('mods_only').checked;

            fetch('update_bot_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cooldown: cooldown,
                    modsOnly: modsOnly
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Bot controls saved successfully', 'success');
                    document.getElementById('cooldown').value = data.settings.cooldown;
                    document.getElementById('mods_only').checked = data.settings.modsOnly;
                } else {
                    showToast('Failed to save bot controls: ' + data.error, 'error');
                    loadSettings();
                }
            })
            .catch(error => {
                showToast('Error saving bot controls: ' + error, 'error');
                loadSettings();
            });
        }

        function loadSettings() {
            fetch('get_settings.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.settings) {
                        // Set default values if not present
                        document.getElementById('cooldown').value = data.settings.cooldown || 30;
                        document.getElementById('mods_only').checked = data.settings.modsOnly || false;
                    } else {
                        // Set default values if no settings found
                        document.getElementById('cooldown').value = 30;
                        document.getElementById('mods_only').checked = false;
                    }
                })
                .catch(error => {
                    showToast('Error loading settings: ' + error, 'error');
                    // Set default values on error
                    document.getElementById('cooldown').value = 30;
                    document.getElementById('mods_only').checked = false;
                });
        }

        // Initialize controls state
        updateControlsState(<?= $is_running ? 'true' : 'false' ?>);
    </script>
</head>
<body>
    <div class="toast-container"></div>
    <h1><img src="images/ClavicusVileMask.png" alt="Clavicus Vile Mask" class="title-icon"> CHIM Twitch Bot Control Panel</h1>

    <div class="page-grid">
        <div class="connection-settings">
            <div class="settings-header">
                <h2>⚙️ Bot Controls</h2>
                <button type="button" class="settings-button" onclick="showModal()" title="Configure Connection Settings" <?= $is_running ? 'disabled' : '' ?>>
                    Configure Connection Settings
                </button>
            </div>

            <form method="post" id="bot-controls-form" onsubmit="return false;">
                <div class="settings-group <?= $is_running ? 'disabled' : '' ?>">
                    <div class="cooldown-container">
                        <input type="number" id="cooldown" name="tbot_cooldown" placeholder="30" min="0" 
                            value="<?= htmlspecialchars($env_vars['TBOT_COOLDOWN'] ?? '30') ?>" required>
                        <label for="cooldown">Command Cooldown (seconds)</label>
                    </div>
                    <div class="toggle-container">
                        <label class="toggle-switch">
                            <input type="checkbox" id="mods_only" name="tbot_mods_only" 
                                <?= ($env_vars['TBOT_MODS_ONLY'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Mods Only Mode</span>
                    </div>
                    <button type="button" onclick="saveBotControls()" class="save-controls-btn" <?= $is_running ? 'disabled' : '' ?>>
                        <?= $is_running ? '⚠️ Stop bot to apply new settings' : '💾 Save Bot Controls' ?>
                    </button>
                </div>
            </form>

            <p class="status">Status: <?= $is_running ? "🟢 Running" : "🔴 Stopped" ?></p>
            <div class="button-group">
                <button type="submit" name="action" value="<?= $is_running ? 'stop' : 'start' ?>" class="<?= $is_running ? 'stop-btn' : 'start-btn' ?>" form="connection-form">
                    <?= $is_running ? '⏹ Stop Bot' : '▶️ Start Bot' ?>
                </button>
            </div>
        </div>

        <!-- Connection Settings Modal -->
        <div class="modal-overlay" id="settingsModal">
            <div class="modal">
                <button class="modal-close" onclick="hideModal()">×</button>
                <h2>⚙️ Connection Settings</h2>
                <form method="post" id="connection-form">
                    <div class="input-group">
                        <div class="input-container">
                            <label for="username">Bot Username</label>
                            <input type="text" id="username" name="tbot_username" placeholder="Username" 
                                value="<?= htmlspecialchars($env_vars['TBOT_USERNAME'] ?? '') ?>" required>
                            <p class="input-description">The Twitch username of your bot account. This should be a separate account from your main Twitch account.</p>
                        </div>

                        <div class="input-container">
                            <label for="oauth">OAuth Token</label>
                            <input type="password" id="oauth" name="tbot_oauth" placeholder="OAUTH TOKEN" 
                                value="<?= htmlspecialchars($env_vars['TBOT_OAUTH'] ?? '') ?>" required>
                            <p class="input-description">
                                The OAuth token for your bot account. This allows the bot to connect to Twitch chat.
                                <a href="https://twitchtokengenerator.com/" target="_blank">Click here to generate a token</a>
                            </p>
                        </div>

                        <div class="input-container">
                            <label for="channel">Channel Name</label>
                            <input type="text" id="channel" name="tbot_channel" placeholder="Channel Name" 
                                value="<?= htmlspecialchars($env_vars['TBOT_CHANNEL'] ?? '') ?>" required>
                            <p class="input-description">Your Twitch channel name where the bot will operate. This should be your main Twitch account name.</p>
                        </div>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="save-btn">💾 Save Connection Settings</button>
                        <button type="button" onclick="hideModal()">Cancel</button>
                    </div>
                </form>
            </div>
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
                <button type="submit" name="action" value="refresh" class="refresh-btn" form="connection-form" onclick="updateLogs(); return false;">🔄 Refresh</button>
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

    <script>
        // Add modal functionality
        function showModal() {
            if (document.querySelector('.settings-button').disabled) {
                showToast('Stop the bot before changing connection settings', 'warning');
                return;
            }
            document.getElementById('settingsModal').classList.add('show');
        }

        function hideModal() {
            document.getElementById('settingsModal').classList.remove('show');
        }

        // Close modal when clicking outside
        document.getElementById('settingsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideModal();
            }
        });
    </script>
</body>
</html>
