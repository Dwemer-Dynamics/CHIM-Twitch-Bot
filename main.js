// Global variables for test functionality
let testCommandInput;
let testResults;
let testButton;

// Test Command Functionality
function testCommand() {
    const command = testCommandInput.value.trim();
    
    if (!command) {
        showToast('Please enter a command to test', 'warning');
        return;
    }

    // Disable input and button while testing
    testCommandInput.disabled = true;
    testButton.disabled = true;
    
    // Show loading state
    testResults.innerHTML = '<div class="test-message">Testing command...</div>';

    fetch('test_command.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ message: command })
    })
    .then(response => response.json())
    .then(data => {
        let output = '';
        
        // Add command that was tested
        output += `<div class="test-message">Command: ${command}</div>`;
        
        // Add debug output if present
        if (data.debug_output) {
            output += `<div class="test-message test-debug">${data.debug_output}</div>`;
        }
        
        // Add success/failure status
        output += `<div class="test-message ${data.success ? 'test-success' : 'test-error'}">
            ${data.success ? '✅ Command executed successfully' : '❌ Command failed'}
        </div>`;
        
        // Add bot responses
        if (data.messages && data.messages.length > 0) {
            output += '<div class="test-message" style="margin-top: 10px;">Bot responses:</div>';
            data.messages.forEach(message => {
                output += `<div class="test-message">${message}</div>`;
            });
        }

        testResults.innerHTML = output;
    })
    .catch(error => {
        testResults.innerHTML = `<div class="test-message test-error">Error testing command: ${error}</div>`;
        showToast('Error testing command', 'error');
    })
    .finally(() => {
        // Re-enable input and button
        testCommandInput.disabled = false;
        testButton.disabled = false;
        testCommandInput.focus();
    });
}

// Global showToast function
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <span class="toast-icon">${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'}</span>
        <span class="toast-message">${message}</span>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
    `;
    
    document.querySelector('.toast-container').appendChild(toast);
    
    // Trigger reflow
    toast.offsetHeight;
    toast.classList.add('show');

    // Auto remove after 5 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('settingsModal');
    const botControlsForm = document.getElementById('bot-controls-form');
    const connectionForm = document.getElementById('connection-form');
    const openModalBtn = document.getElementById('openSettingsModal');
    const closeModalBtn = document.querySelector('.close-button');
    const saveSettingsBtn = document.getElementById('saveSettings');
    const cooldownInput = document.getElementById('commandCooldown');
    const modsOnlyToggle = document.getElementById('modsOnly');
    const statusElement = document.getElementById('status');
    let isConnected = false;

    // Initialize test command elements
    testCommandInput = document.getElementById('test-command');
    testResults = document.querySelector('.test-results');
    testButton = document.querySelector('.test-button');

    // Add enter key support for test command input
    if (testCommandInput) {
        testCommandInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                testCommand();
            }
        });
    }

    // Clear results when starting to type a new command
    if (testCommandInput && testResults) {
        testCommandInput.addEventListener('input', function() {
            if (this.value.trim() === '') {
                testResults.innerHTML = '';
            }
        });
    }

    // Modal controls
    if (openModalBtn) {
        openModalBtn.onclick = function() {
            showModal();
        };
    }

    if (closeModalBtn) {
        closeModalBtn.onclick = function() {
            hideModal();
        };
    }

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                hideModal();
            }
        });
    }

    // Handle connection settings form submission
    if (connectionForm) {
        connectionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const usernameEl = document.getElementById('username');
            const oauthEl = document.getElementById('oauth');
            const channelEl = document.getElementById('channel');
            const username = usernameEl ? usernameEl.value : '';
            const oauth = oauthEl ? oauthEl.value : '';
            const channel = channelEl ? channelEl.value : '';

            if (!username || !oauth || !channel) {
                showToast('Please fill in all connection fields', 'error');
                return;
            } else {
                fetch('save_connection_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        username: username,
                        token: oauth,
                        channel: channel
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Connection settings saved successfully', 'success');
                        hideModal();
                    } else {
                        showToast('Failed to save connection settings: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    showToast('Error saving connection settings: ' + error, 'error');
                });
            }
        });
    }

    // Load initial settings
    loadSettings();

    function showStatus(message, type = 'info') {
        if (!statusElement) {
            return;
        }
        statusElement.textContent = message;
        statusElement.className = 'status ' + type;
        setTimeout(() => {
            statusElement.textContent = '';
            statusElement.className = 'status';
        }, 5000);
    }

    function showModal() {
        if (modal) {
            modal.classList.add('show');
        }
    }

    function hideModal() {
        if (modal) {
            modal.classList.remove('show');
        }
    }
});

function saveSettings() {
    const cooldown = Math.max(0, parseInt(document.getElementById('cooldown').value) || 30);
    const modsOnly = document.getElementById('mods_only').checked;
    const subsOnly = document.getElementById('subs_only').checked;
    const whitelistEnabled = document.getElementById('whitelist_enabled').checked;
    const rolemasterInstruction = document.getElementById('rolemaster_instruction').checked;
    const rolemasterSuggestion = document.getElementById('rolemaster_suggestion').checked;
    const rolemasterImpersonation = document.getElementById('rolemaster_impersonation').checked;
    const rolemasterSpawn = document.getElementById('rolemaster_spawn').checked;
    const rolemasterCheat = document.getElementById('rolemaster_cheat').checked;
            // Encounter command is disabled
        // const rolemasterEncounter = document.getElementById('rolemaster_encounter').checked;
    const helpKeywords = document.getElementById('help_keywords').value;

    // Command name fields are optional in current UI. Only send when present.
    const cmdInstructionEl = document.getElementById('cmd_instruction');
    const cmdSuggestionEl = document.getElementById('cmd_suggestion');
    const cmdImpersonationEl = document.getElementById('cmd_impersonation');
    const cmdSpawnEl = document.getElementById('cmd_spawn');
    const cmdCheatEl = document.getElementById('cmd_cheat');

    const payload = {
        cooldown: cooldown,
        modsOnly: modsOnly,
        subsOnly: subsOnly,
        whitelistEnabled: whitelistEnabled,
        rolemasterInstruction: rolemasterInstruction,
        rolemasterSuggestion: rolemasterSuggestion,
        rolemasterImpersonation: rolemasterImpersonation,
        rolemasterSpawn: rolemasterSpawn,
        rolemasterCheat: rolemasterCheat,
        // Encounter command is disabled
        // rolemasterEncounter: rolemasterEncounter,
        helpKeywords: helpKeywords
    };

    if (cmdInstructionEl) payload.cmd_instruction = cmdInstructionEl.value.replace(/[^a-zA-Z0-9]/g, '');
    if (cmdSuggestionEl) payload.cmd_suggestion = cmdSuggestionEl.value.replace(/[^a-zA-Z0-9]/g, '');
    if (cmdImpersonationEl) payload.cmd_impersonation = cmdImpersonationEl.value.replace(/[^a-zA-Z0-9]/g, '');
    if (cmdSpawnEl) payload.cmd_spawn = cmdSpawnEl.value.replace(/[^a-zA-Z0-9]/g, '');
    if (cmdCheatEl) payload.cmd_cheat = cmdCheatEl.value.replace(/[^a-zA-Z0-9]/g, '');

    fetch('update_bot_settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Settings saved successfully', 'success');
        } else {
            showToast('Failed to save settings: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showToast('Error saving settings: ' + error, 'error');
    });
}

function loadSettings() {
    fetch('get_settings.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.settings) {
                const cooldownSlider = document.getElementById('cooldown');
                const cooldownValueInput = document.getElementById('cooldown-value-input');
                if (cooldownSlider && cooldownValueInput) {
                    const value = data.settings.cooldown ?? 30;
                    cooldownSlider.value = value;
                    cooldownValueInput.value = value;
                }
                document.getElementById('mods_only').checked = data.settings.modsOnly ?? false;
                document.getElementById('subs_only').checked = data.settings.subsOnly ?? false;
                document.getElementById('whitelist_enabled').checked = data.settings.whitelistEnabled ?? false;
                document.getElementById('rolemaster_instruction').checked = data.settings.rolemasterInstruction ?? true;
                document.getElementById('rolemaster_suggestion').checked = data.settings.rolemasterSuggestion ?? true;
                document.getElementById('rolemaster_impersonation').checked = data.settings.rolemasterImpersonation ?? true;
                document.getElementById('rolemaster_spawn').checked = data.settings.rolemasterSpawn ?? false;
                document.getElementById('rolemaster_cheat').checked = data.settings.rolemasterCheat ?? false;
                // Encounter command is disabled
        // document.getElementById('rolemaster_encounter').checked = data.settings.rolemasterEncounter ?? true;
                document.getElementById('help_keywords').value = data.settings.helpKeywords ?? 'help,ai,Rolemaster,rp';

                // Update command name map inputs (if this UI section is present)
                if (data.settings.commandNameMap) {
                    const cmdInstructionEl = document.getElementById('cmd_instruction');
                    const cmdSuggestionEl = document.getElementById('cmd_suggestion');
                    const cmdImpersonationEl = document.getElementById('cmd_impersonation');
                    const cmdSpawnEl = document.getElementById('cmd_spawn');
                    const cmdCheatEl = document.getElementById('cmd_cheat');
                    if (cmdInstructionEl) cmdInstructionEl.value = data.settings.commandNameMap.instruction ?? 'director';
                    if (cmdSuggestionEl) cmdSuggestionEl.value = data.settings.commandNameMap.suggestion ?? 'suggestion';
                    if (cmdImpersonationEl) cmdImpersonationEl.value = data.settings.commandNameMap.impersonation ?? 'impersonation';
                    if (cmdSpawnEl) cmdSpawnEl.value = data.settings.commandNameMap.spawn ?? 'spawn';
                    if (cmdCheatEl) cmdCheatEl.value = data.settings.commandNameMap.cheat ?? 'cheat';
                    // Encounter command is disabled
        // document.getElementById('cmd_encounter').value = data.settings.commandNameMap.encounter ?? 'encounter';
                }

                // Update test command placeholder after loading settings
                if (typeof updateTestCommandPlaceholder === 'function') {
                    updateTestCommandPlaceholder();
                }
            }
        })
        .catch(error => {
            showToast('Error loading settings: ' + error, 'error');
        });
} 
