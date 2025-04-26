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

    // Modal controls
    openModalBtn.onclick = function() {
        showModal();
    }

    closeModalBtn.onclick = function() {
        hideModal();
    }

    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            hideModal();
        }
    });

    // Handle bot controls form submission
    botControlsForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const cooldown = document.getElementById('cooldown').value;
        const modsOnly = document.querySelector('input[name="tbot_mods_only"]').checked;

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
            } else {
                showToast('Failed to save bot controls: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showToast('Error saving bot controls: ' + error, 'error');
        });
    });

    // Handle connection settings form submission
    connectionForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const username = document.getElementById('username').value;
        const oauth = document.getElementById('oauth').value;
        const channel = document.getElementById('channel').value;

        if (!username || !oauth || !channel) {
            showToast('Please fill in all connection fields', 'error');
            return;
        }

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
    });

    // Load initial settings
    fetch('get_settings.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.settings) {
                document.getElementById('username').value = data.settings.username || '';
                document.getElementById('oauth').value = data.settings.token || '';
                document.getElementById('channel').value = data.settings.channel || '';
                document.getElementById('cooldown').value = data.settings.cooldown || 30;
                document.querySelector('input[name="tbot_mods_only"]').checked = data.settings.modsOnly || false;
            }
        })
        .catch(error => {
            showToast('Error loading settings: ' + error, 'error');
        });

    // Function to show toast notifications
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

    function showStatus(message, type = 'info') {
        statusElement.textContent = message;
        statusElement.className = 'status ' + type;
        setTimeout(() => {
            statusElement.textContent = '';
            statusElement.className = 'status';
        }, 5000);
    }

    function showModal() {
        modal.classList.add('show');
    }

    function hideModal() {
        modal.classList.remove('show');
    }
}); 