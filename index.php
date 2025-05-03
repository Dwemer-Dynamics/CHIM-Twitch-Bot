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
            // Get all relevant controls
            const cooldownInput = document.getElementById('cooldown');
            const modsOnlyToggle = document.getElementById('mods_only');
            const subsOnlyToggle = document.getElementById('subs_only');
            const whitelistEnabledToggle = document.getElementById('whitelist_enabled');
            // Blacklist toggle removed, no need to select
            const rolemasterInstructionToggle = document.getElementById('rolemaster_instruction');
            const rolemasterSuggestionToggle = document.getElementById('rolemaster_suggestion');
            const rolemasterImpersonationToggle = document.getElementById('rolemaster_impersonation');
            const saveButton = document.querySelector('.save-controls-btn');
            const settingsGroup = document.querySelector('.settings-group');
            const configureButton = document.querySelector('.settings-button');
            const whitelistBtn = document.getElementById('whitelist_btn');
            // Blacklist button is always enabled, no need to select it here

            // List of controls to disable when bot is running
            const controlsToDisable = [
                cooldownInput,
                modsOnlyToggle,
                subsOnlyToggle,
                whitelistEnabledToggle,
                rolemasterInstructionToggle,
                rolemasterSuggestionToggle,
                rolemasterImpersonationToggle,
                saveButton,
                configureButton
            ];

            // Disable/enable based on running state
            controlsToDisable.forEach(control => {
                if (control) { // Check if element exists
                    control.disabled = isRunning;
                }
            });
            
            // Update button text and settings group class
            if (isRunning) {
                settingsGroup.classList.add('disabled'); // Add class for potential styling
                saveButton.innerHTML = '⚠️ Stop bot to save new settings';
            } else {
                settingsGroup.classList.remove('disabled'); // Remove class
                saveButton.innerHTML = '💾 Save Bot Controls';
            }

            // Whitelist button state should *always* be enabled, regardless of bot state or toggle state
            if (whitelistBtn) {
                 whitelistBtn.disabled = false; // Keep the button enabled
            }
        }

        function saveBotControls() {
            if (document.querySelector('.settings-group').classList.contains('disabled')) {
                showToast('Stop the bot before changing settings', 'warning');
                return;
            }

            const cooldown = Math.max(0, parseInt(document.getElementById('cooldown').value) || 30);
            const modsOnly = document.getElementById('mods_only').checked;
            const subsOnly = document.getElementById('subs_only').checked;
            const whitelistEnabled = document.getElementById('whitelist_enabled').checked;
            const rolemasterInstruction = document.getElementById('rolemaster_instruction').checked;
            const rolemasterSuggestion = document.getElementById('rolemaster_suggestion').checked;
            const rolemasterImpersonation = document.getElementById('rolemaster_impersonation').checked;

            fetch('update_bot_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cooldown: cooldown,
                    modsOnly: modsOnly,
                    subsOnly: subsOnly,
                    whitelistEnabled: whitelistEnabled,
                    rolemasterInstruction: rolemasterInstruction,
                    rolemasterSuggestion: rolemasterSuggestion,
                    rolemasterImpersonation: rolemasterImpersonation
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Bot controls saved successfully', 'success');
                    document.getElementById('cooldown').value = data.settings.cooldown;
                    document.getElementById('mods_only').checked = data.settings.modsOnly;
                    document.getElementById('subs_only').checked = data.settings.subsOnly;
                    document.getElementById('whitelist_enabled').checked = data.settings.whitelistEnabled;
                    document.getElementById('rolemaster_instruction').checked = data.settings.rolemasterInstruction;
                    document.getElementById('rolemaster_suggestion').checked = data.settings.rolemasterSuggestion;
                    document.getElementById('rolemaster_impersonation').checked = data.settings.rolemasterImpersonation;
                    
                    // Update button states
                    document.getElementById('whitelist_btn').disabled = !data.settings.whitelistEnabled;
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
                        document.getElementById('cooldown').value = data.settings.cooldown || 30;
                        document.getElementById('mods_only').checked = data.settings.modsOnly || false;
                        document.getElementById('subs_only').checked = data.settings.subsOnly || false;
                        document.getElementById('whitelist_enabled').checked = data.settings.whitelistEnabled || false;
                        document.getElementById('rolemaster_instruction').checked = data.settings.rolemasterInstruction || true;
                        document.getElementById('rolemaster_suggestion').checked = data.settings.rolemasterSuggestion || true;
                        document.getElementById('rolemaster_impersonation').checked = data.settings.rolemasterImpersonation || true;
                        
                        // Update button states
                        document.getElementById('whitelist_btn').disabled = !data.settings.whitelistEnabled;
                    } else {
                        // Set default values if no settings found
                        document.getElementById('cooldown').value = 30;
                        document.getElementById('mods_only').checked = false;
                        document.getElementById('subs_only').checked = false;
                        document.getElementById('whitelist_enabled').checked = false;
                        document.getElementById('rolemaster_instruction').checked = true;
                        document.getElementById('rolemaster_suggestion').checked = true;
                        document.getElementById('rolemaster_impersonation').checked = true;
                    }
                })
                .catch(error => {
                    showToast('Error loading settings: ' + error, 'error');
                    // Set default values on error
                    document.getElementById('cooldown').value = 30;
                    document.getElementById('mods_only').checked = false;
                    document.getElementById('subs_only').checked = false;
                    document.getElementById('whitelist_enabled').checked = false;
                    document.getElementById('rolemaster_instruction').checked = true;
                    document.getElementById('rolemaster_suggestion').checked = true;
                    document.getElementById('rolemaster_impersonation').checked = true;
                });
        }

        // Initialize controls state on page load
        document.addEventListener('DOMContentLoaded', () => {
            updateControlsState(<?= $is_running ? 'true' : 'false' ?>);
            loadSettings(); // Also load settings from backend

            // Add click prevention listeners to toggle containers
            const toggleContainers = document.querySelectorAll('.permissions-section .toggle-container');
            toggleContainers.forEach(container => {
                // Skip the blacklist container as it only has a button
                if (container.classList.contains('blacklist-container')) return;

                container.addEventListener('click', function(event) {
                    const input = this.querySelector('input[type="checkbox"]');
                    // If the input exists and is disabled...
                    if (input && input.disabled) {
                        // ...and the click was on the switch itself or its label/slider
                        const switchElement = this.querySelector('.toggle-switch');
                        if (switchElement && switchElement.contains(event.target)) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                    }
                }, true); // Use capture phase to intercept early
            });

            // Add similar prevention for cooldown container
            const cooldownContainer = document.querySelector('.cooldown-container');
            if (cooldownContainer) {
                cooldownContainer.addEventListener('click', function(event) {
                    const input = this.querySelector('input[type="number"]');
                    if (input && input.disabled) {
                         // Allow clicks on the label, but maybe block the input itself if needed?
                         // For now, let's just log, as blocking clicks here might be annoying
                         console.log("Cooldown container clicked while disabled");
                         // If you wanted to block changing the number via clicks:
                         // if (event.target === input) { 
                         //    event.preventDefault(); 
                         //    event.stopPropagation();
                         // }
                    }
                }, true);
            }
        });
    </script>
    <style>
        .rolemaster-commands-section {
            margin-top: 20px;
            padding: 15px;
            background-color: rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }

        .rolemaster-commands-section h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #fff;
            font-size: 1.2em;
        }

        .rolemaster-commands-section .toggle-container {
            margin-bottom: 10px;
        }

        .rolemaster-commands-section .toggle-label {
            color: #fff;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="toast-container"></div>
    <h1><img src="images/ClavicusVileMask.png" alt="Clavicus Vile Mask" class="title-icon"> CHIM Twitch Bot Control Panel</h1>

    <div class="page-grid">
        <div class="connection-settings">
            <div class="settings-header">
                <h2>⚙️ Bot Controls</h2>
                <div class="header-buttons">
                    <button type="button" class="settings-button" onclick="showModal()" title="Configure Connection Settings" <?= $is_running ? 'disabled' : '' ?>>
                        Configure Connection Settings
                    </button>
                    <button type="button" class="help-button" onclick="showCommandsModal()" title="View Available Commands">
                        📖 Available Commands
                    </button>
                </div>
            </div>

            <form method="post" id="bot-controls-form" onsubmit="return false;">
                <div class="settings-group <?= $is_running ? 'disabled' : '' ?>">
                    <div class="cooldown-container">
                        <input type="number" id="cooldown" name="tbot_cooldown" placeholder="30" min="0" 
                            value="<?= htmlspecialchars($env_vars['TBOT_COOLDOWN'] ?? '30') ?>" required>
                        <label for="cooldown">Command Cooldown (seconds)</label>
                    </div>
                    
                    <div class="permissions-section">
                        <h3>Permission Settings</h3>
                        <div class="toggle-container">
                            <label class="toggle-switch">
                                <input type="checkbox" id="mods_only" name="tbot_mods_only" 
                                    <?= ($env_vars['TBOT_MODS_ONLY'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <span class="toggle-label">Mods Only</span>
                        </div>

                        <div class="toggle-container">
                            <label class="toggle-switch">
                                <input type="checkbox" id="subs_only" name="tbot_subs_only" 
                                    <?= ($env_vars['TBOT_SUBS_ONLY'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <span class="toggle-label">Subscribers Only</span>
                        </div>

                        <div class="toggle-container">
                            <label class="toggle-switch">
                                <input type="checkbox" id="whitelist_enabled" name="tbot_whitelist_enabled" 
                                    <?= ($env_vars['TBOT_WHITELIST_ENABLED'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <span class="toggle-label">Enable Allowed Users Only Mode</span>
                            <button type="button" class="list-action-btn" onclick="showListModal('whitelist')" 
                                    id="whitelist_btn" <?= ($env_vars['TBOT_WHITELIST_ENABLED'] ?? '0') === '0' ? 'disabled' : '' ?>>
                                ✅ Manage Allowed Users
                            </button>
                        </div>

                        <div class="toggle-container blacklist-container">
                            <button type="button" class="list-action-btn danger" onclick="showListModal('blacklist')" 
                                    id="blacklist_btn">
                                ❌ Manage Blocked Users
                            </button>
                        </div>

                        <!-- Add Rolemaster Command Settings -->
                        <div class="rolemaster-commands-section">
                            <h3>Rolemaster Commands</h3>
                            <div class="toggle-container">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="rolemaster_instruction" name="tbot_rolemaster_instruction_enabled" 
                                        <?= ($env_vars['TBOT_ROLEMASTER_INSTRUCTION_ENABLED'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="toggle-label">🎬 Instruction Command</span>
                            </div>

                            <div class="toggle-container">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="rolemaster_suggestion" name="tbot_rolemaster_suggestion_enabled" 
                                        <?= ($env_vars['TBOT_ROLEMASTER_SUGGESTION_ENABLED'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="toggle-label">🕒 Suggestion Command</span>
                            </div>

                            <div class="toggle-container">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="rolemaster_impersonation" name="tbot_rolemaster_impersonation_enabled" 
                                        <?= ($env_vars['TBOT_ROLEMASTER_IMPERSONATION_ENABLED'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="toggle-label">🗣️ Impersonation Command</span>
                            </div>
                        </div>
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

    <!-- Commands Modal -->
    <div class="modal-overlay" id="commandsModal">
        <div class="modal">
            <button class="modal-close" onclick="hideCommandsModal()">×</button>
            <h2>📖 Available Commands</h2>
            <div class="commands-list">
                <h3 class="commands-section-title">Rolemaster Commands</h3>
                <div class="command-card">
                    <h3>🎬 Rolemaster:instruction:</h3>
                    <p class="command-description"><i>Will <b>immediately</b> prompt an AI NPC in the vicinity to follow your commands to the best of their ability.</i></p>
                    <p class="command-example">E.G. Rolemaster:instruction: Make Mikael tell a story.</p>
                </div>
                <div class="command-card">
                    <h3>🕒 Rolemaster:suggestion:</h3>
                    <p class="command-description"><i>Will <b>queue</b> a prompt for an AI NPC in the vicinity to follow your commands to the best of their ability once the current scene playing has ended.</i></p>
                    <p class="command-example">E.G. Rolemaster:suggestion: Make Mikael tell a story.</p>
                </div>
                <div class="command-card">
                    <h3>🗣️ Rolemaster:impersonation:</h3>
                    <p class="command-description"><i>The player character will repeat whatever is entered by chat. You may want to be careful with this one...</i></p>
                    <p class="command-example">Rolemaster:impersonation: Why did the chicken cross the road?</p>
                </div>

                <h3 class="commands-section-title">Moderation Commands</h3>
                <div class="command-card moderator-command">
                    <h3>Moderation:help:</h3>
                    <p class="command-description"><i>Lists all available commands in the Twitch chat. <b>[Moderators & Channel Owner Only]</b></i></p>
                    <p class="command-example">Moderation:help:</p>
                </div>
                <div class="command-card moderator-command">
                    <h3>Moderation:permissions:</h3>
                    <p class="command-description"><i>Shows current permission settings including cooldown, mods-only, subs-only, and list modes. <b>[Moderators & Channel Owner Only]</b></i></p>
                    <p class="command-example">Moderation:permissions:</p>
                </div>
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

        // Add these functions for the commands modal
        function showCommandsModal() {
            document.getElementById('commandsModal').classList.add('show');
        }

        function hideCommandsModal() {
            document.getElementById('commandsModal').classList.remove('show');
        }

        // Close commands modal when clicking outside
        document.getElementById('commandsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideCommandsModal();
            }
        });
    </script>

    <!-- User List Management Modal -->
    <div class="modal-overlay" id="listModal">
        <div class="modal">
            <button class="modal-close" onclick="hideListModal()">×</button>
            <h2 id="listModalTitle">Manage List</h2>
            
            <div class="list-management">
                <div class="bulk-input-container">
                    <label for="bulkUserInput">Add Users (one per line or comma-separated)</label>
                    <textarea id="bulkUserInput" placeholder="user1&#10;user2&#10;user3&#10;or: user1, user2, user3"></textarea>
                    <button type="button" onclick="addUsers()" class="bulk-action-btn">Add Users</button>
                </div>
                
                <div class="current-list-container">
                    <h4>Current Users in List</h4>
                    <div class="search-container">
                        <input type="text" id="userSearch" placeholder="Search users..." onkeyup="filterUsers()">
                        <button type="button" onclick="removeSelected()" class="remove-btn">Remove Selected</button>
                    </div>
                    <div class="user-list" id="currentUsers">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" onclick="saveList()" class="save-btn">Save Changes</button>
                <button type="button" onclick="hideListModal()" class="cancel-btn">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Add this before the closing </body> tag -->
    <script>
    let currentListType = null;
    let currentListData = {
        whitelist: [],
        blacklist: []
    };

    // Load initial list data
    document.addEventListener('DOMContentLoaded', () => {
        loadListData();
    });

    function loadListData() {
        fetch('get_user_lists.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentListData = data.lists;
                }
            })
            .catch(error => showToast('Error loading user lists: ' + error, 'error'));
    }

    function showListModal(listType) {
        currentListType = listType;
        const modal = document.getElementById('listModal');
        const title = document.getElementById('listModalTitle');
        
        title.textContent = listType === 'whitelist' ? 'Manage Allowed Users' : 'Manage Blocked Users';
        modal.classList.add('show');
        
        // Clear and populate the current users list
        const currentUsers = document.getElementById('currentUsers');
        currentUsers.innerHTML = '';
        
        currentListData[listType].forEach(user => {
            const userElement = document.createElement('div');
            userElement.className = 'user-item';
            userElement.innerHTML = `
                <input type="checkbox" id="user_${user}" value="${user}">
                <label for="user_${user}">${user}</label>
            `;
            currentUsers.appendChild(userElement);
        });
    }

    function hideListModal() {
        document.getElementById('listModal').classList.remove('show');
        document.getElementById('bulkUserInput').value = '';
        currentListType = null;
    }

    function addUsers() {
        const input = document.getElementById('bulkUserInput').value;
        const users = input.split(/[\n,]/).map(user => user.trim().toLowerCase()).filter(user => user);
        
        // Add new users to the current list
        users.forEach(user => {
            if (!currentListData[currentListType].includes(user)) {
                currentListData[currentListType].push(user);
            }
        });
        
        // Refresh the display
        showListModal(currentListType);
        document.getElementById('bulkUserInput').value = '';
    }

    function filterUsers() {
        const searchTerm = document.getElementById('userSearch').value.toLowerCase();
        const userElements = document.querySelectorAll('.user-item');
        
        userElements.forEach(element => {
            const username = element.querySelector('label').textContent.toLowerCase();
            element.style.display = username.includes(searchTerm) ? '' : 'none';
        });
    }

    function removeSelected() {
        const selectedUsers = Array.from(document.querySelectorAll('.user-item input:checked'))
            .map(checkbox => checkbox.value);
        
        currentListData[currentListType] = currentListData[currentListType]
            .filter(user => !selectedUsers.includes(user));
        
        showListModal(currentListType);
    }

    function saveList() {
        fetch('update_user_lists.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                listType: currentListType,
                users: currentListData[currentListType]
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(`${currentListType.charAt(0).toUpperCase() + currentListType.slice(1)} updated successfully`, 'success');
                hideListModal();
            } else {
                showToast('Failed to update list: ' + data.error, 'error');
            }
        })
        .catch(error => showToast('Error saving list: ' + error, 'error'));
    }

    // Add these to your existing event listeners
    document.getElementById('whitelist_enabled').addEventListener('change', function() {
        document.getElementById('whitelist_btn').disabled = !this.checked;
        if (!this.checked) {
            showToast('Allowed Users List disabled', 'info');
        }
    });
    </script>
</body>
</html>
