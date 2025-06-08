<?php

class CommandHandler {
    private $command_prefix;
    private $use_command_prefix;
    private $mods_only;
    private $subs_only;
    private $whitelist_enabled;
    private $command_types_enabled;
    private $command_name_map;
    private $command_name_reverse_map;
    private $invalid_command_count = 0;
    private $last_invalid_time = 0;
    private $socket = null;
    private $channel = null;
    private $message_callback = null;
    private $php_path;
    private $manager_script;
    private $help_keywords = [];
    
    // List management
    private $whitelist = array();
    private $blacklist = array();
    private $lists_check_interval = 5;
    private $last_lists_check = 0;
    private $lists_flag_file;
    private $lists_file;
    
    // Channel info
    private $channel_owner;
    private $moderators = array();
    
    // Legal encounter NPCs for validation
    private $legal_encounter_npcs = [
        "bandit", "bear", "wolf", "draugr", "skeleton", "spider", "troll", 
        "sabrecat", "vampire", "assassin", "mudcrab", "hagraven", "forsworn", 
        "flame_atronach", "dremora", "cultist", "necromancer", "falmer"
    ];

    /**
     * Initializes a new CommandHandler instance with IRC connection details, environment-based configuration, command mappings, and user access lists.
     *
     * Loads command prefix, permission modes, help keywords, command type enablement, and user whitelist/blacklist from environment variables and files. Sets up paths for command execution scripts.
     */
    public function __construct($socket = null, $channel = null, $message_callback = null) {
        // Store IRC connection details if provided
        $this->socket = $socket;
        $this->channel = $channel;
        $this->message_callback = $message_callback;
        $this->channel_owner = strtolower(getenv("TBOT_CHANNEL"));

        // Load settings from environment
        $prefix_env = getenv("TBOT_USE_COMMAND_PREFIX");
        $this->command_prefix = getenv("TBOT_COMMAND_PREFIX") ?: "Rolemaster";
        $this->use_command_prefix = $prefix_env === false ? true : $prefix_env === "1";
        
        $this->mods_only = (getenv("TBOT_MODS_ONLY") ?: "0") === "1";
        $this->subs_only = (getenv("TBOT_SUBS_ONLY") ?: "0") === "1";
        $this->whitelist_enabled = (getenv("TBOT_WHITELIST_ENABLED") ?: "0") === "1";
        
        // Load help keywords
        $this->help_keywords = array_filter(explode(',', getenv("TBOT_HELP_KEYWORDS") ?: "help,ai,Rolemaster,rp"));
        
        // Load command name mapping
        $this->command_name_map = json_decode(getenv("TBOT_COMMAND_NAME_MAP") ?: '{}', true) ?: [
            'instruction' => 'instruction',
            'suggestion' => 'suggestion',
            'impersonation' => 'impersonation',
            'spawn' => 'spawn',
            'encounter' => 'encounter'
        ];
        
        // Create reverse mapping for looking up dev commands from user commands
        $this->command_name_reverse_map = array_flip($this->command_name_map);
        
        // Command type settings
        $this->command_types_enabled = [
            'instruction' => (getenv("TBOT_ROLEMASTER_INSTRUCTION_ENABLED") ?: "0") === "1",
            'suggestion' => (getenv("TBOT_ROLEMASTER_SUGGESTION_ENABLED") ?: "0") === "1",
            'impersonation' => (getenv("TBOT_ROLEMASTER_IMPERSONATION_ENABLED") ?: "0") === "1",
            'spawn' => (getenv("TBOT_ROLEMASTER_SPAWN_ENABLED") ?: "0") === "1",
            'encounter' => (getenv("TBOT_ROLEMASTER_ENCOUNTER_ENABLED") ?: "0") === "1"
        ];

        // Initialize list management
        $this->lists_file = __DIR__ . "/user_lists.json";
        $this->lists_flag_file = __DIR__ . '/lists_updated.flag';
        $this->loadUserLists();
        
        // Path configuration
        $this->php_path = getenv("TBOT_PHP_PATH") ?: '/usr/bin/php';
        $this->manager_script = getenv("TBOT_MANAGER_SCRIPT") ?: '/var/www/html/HerikaServer/service/manager.php';
    }

    /**
     * Sends a message to the chat channel via IRC socket or a callback function.
     *
     * If an IRC connection is available, the message is sent directly to the channel. Otherwise, the message is delivered using a provided callback.
     *
     * @param string $message The message to send.
     */
    public function sendMessage($message) {
        if ($this->socket && $this->channel) {
            // If we have an IRC connection, send through IRC
            fwrite($this->socket, "PRIVMSG #{$this->channel} :$message\r\n");
        } elseif ($this->message_callback) {
            // Otherwise use the callback if provided
            call_user_func($this->message_callback, $message);
        }
    }

    /**
     * Handles invalid command attempts by notifying the user and suggesting the help command.
     *
     * Tracks consecutive invalid command attempts and, after three, sends a message indicating multiple invalid attempts along with help guidance.
     *
     * @param string $errorMessage The error message to display to the user.
     */
    public function handleInvalidCommand($errorMessage) {
        $current_time = time();
        $this->last_invalid_time = $current_time;
        $this->invalid_command_count++;

        // Get the default help keyword (either "help" or first available keyword)
        $helpKeyword = in_array("help", $this->help_keywords) ? "help" : $this->help_keywords[0];

        if ($this->invalid_command_count >= 3) {
            $this->invalid_command_count = 0;
            $this->sendMessage("$errorMessage (Multiple invalid attempts). Type !$helpKeyword to see available commands.");
        } else {
            $this->sendMessage("$errorMessage Type !$helpKeyword to see available commands.");
        }
    }

    /**
     * Determines whether a user is permitted to execute commands based on blacklist, whitelist, and channel permission settings.
     *
     * Evaluates user access by checking blacklist status, channel ownership, moderator status, whitelist enforcement, and channel modes such as mods-only and subs-only.
     *
     * @param string $user The username to check for command permissions.
     * @param bool $isMod Whether the user is a moderator.
     * @param bool $isSub Whether the user is a subscriber.
     * @return bool True if the user is allowed to use commands; false otherwise.
     */
    public function canUserUseCommands($user, $isMod = false, $isSub = false) {
        $user = strtolower($user);
        
        // STEP 1: Check blacklist (Always active)
        if (in_array($user, $this->blacklist) && $user !== $this->channel_owner) {
            error_log("❌ User $user blocked - User is blacklisted");
            return false;
        }
        
        // STEP 2: Channel owner and mods always have permission (except if blacklisted)
        if ($user === $this->channel_owner || $isMod) {
            error_log("✅ User $user is channel owner/mod - Command allowed");
            return true;
        }
        
        // STEP 3: Whitelist Logic
        if ($this->whitelist_enabled) {
            if (in_array($user, $this->whitelist)) {
                error_log("✅ User $user is whitelisted - Command allowed");
                return true;
            } else {
                error_log("❌ User $user blocked - Allowed Users Only mode is ON and user is not listed");
                return false;
            }
        }
        
        // STEP 4: Mods Only mode
        if ($this->mods_only) {
            error_log("❌ User $user blocked - Mods Only mode is on");
            return false;
        }
        
        // STEP 5: Subscribers Only mode
        if ($this->subs_only && !$isSub) {
            error_log("❌ User $user blocked - Subs Only mode is on and user is not a subscriber");
            return false;
        }
        
        error_log("✅ User $user passed standard permission checks - Command allowed");
        return true;
    }

    /**
     * Loads the whitelist and blacklist arrays from a JSON file if it exists.
     *
     * Updates the in-memory user lists based on the contents of the configured lists file.
     */
    private function loadUserLists() {
        if (file_exists($this->lists_file)) {
            $lists = json_decode(file_get_contents($this->lists_file), true);
            if (is_array($lists)) {
                $this->whitelist = $lists['whitelist'] ?? array();
                $this->blacklist = $lists['blacklist'] ?? array();
            }
        }
    }

    /**
     * Checks if the user whitelist or blacklist needs to be reloaded and reloads them if necessary.
     *
     * Reloads the user lists from file if a flag file exists, then removes the flag file. This check occurs at a fixed interval.
     */
    public function checkAndReloadLists() {
        $current_time = time();
        if ($current_time - $this->last_lists_check >= $this->lists_check_interval) {
            if (file_exists($this->lists_flag_file)) {
                $this->loadUserLists();
                unlink($this->lists_flag_file);
            }
            $this->last_lists_check = $current_time;
        }
    }

    /**
     * Returns the user-facing command name corresponding to a developer command name.
     *
     * If no mapping exists, returns the original developer command name.
     *
     * @param string $devCommand The internal developer command name.
     * @return string The user-facing command name.
     */
    private function getUserCommandName($devCommand) {
        return $this->command_name_map[$devCommand] ?? $devCommand;
    }

    /**
     * Retrieves the developer-facing command name corresponding to a user-facing command name.
     *
     * If no mapping exists, returns the original user command name.
     *
     * @param string $userCommand The user-facing command name.
     * @return string The developer-facing command name.
     */
    private function getDevCommandName($userCommand) {
        return $this->command_name_reverse_map[$userCommand] ?? $userCommand;
    }

    /**
     * Parses and processes a chat message as a potential command.
     *
     * Determines if the message is a help request, a moderation command, or a user command. Validates command format, checks user permissions, and dispatches the command for execution or help handling. Returns true if the message was handled as a command or help request, false otherwise.
     *
     * @param string $user The username of the message sender.
     * @param string $message The chat message to parse.
     * @param bool $isMod Whether the user has moderator privileges.
     * @param bool $isSub Whether the user is a subscriber.
     * @return bool True if the message was handled as a command or help request, false if ignored or not permitted.
     */
    public function parseCommand($user, $message, $isMod = false, $isSub = false) {
        // First check if this is a help command
        $helpType = $this->isHelpCommand($message);
        if ($helpType !== false) {
            if ($helpType === 'general') {
                $this->handleHelpCommand($user);
            } elseif ($helpType === 'specific') {
                // Extract the command name from the message
                $keyword = strtolower(substr($message, 1));
                $this->handleSpecificCommandHelp($keyword);
            }
            return true;
        }

        // Check if this is a valid command format and normalize it
        $normalizedCommand = $this->normalizeCommand($message);
        
        if ($normalizedCommand === false) {
            // Not a command, ignore it (regular chat message)
            return false;
        }

        // First check permissions
        if (!$this->canUserUseCommands($user, $isMod, $isSub)) {
            return false;
        }

        // Reset invalid command count if more than 10 seconds have passed
        $current_time = time();
        if ($current_time - $this->last_invalid_time > 10) {
            $this->invalid_command_count = 0;
        }

        // Check and reload lists if needed
        $this->checkAndReloadLists();

        // Handle moderation commands
        if (strpos($normalizedCommand, 'moderation:') === 0) {
            if ($isMod || $user === $this->channel_owner) {
                if (preg_match('/^moderation:([^:]+):?(.*)$/i', $normalizedCommand, $matches)) {
                    $type = strtolower(trim($matches[1]));
                    $freeText = isset($matches[2]) ? trim($matches[2]) : '';
                    $this->handleModerationCommand($user, $type, $freeText);
                    return true;
                }
            }
            return false;
        }

        // Parse the normalized command (now always in type:text format)
        if (preg_match('/^([^:]+):(.*)$/', $normalizedCommand, $matches)) {
            $userType = strtolower(trim($matches[1]));
            $type = $this->getDevCommandName($userType);
            $freeText = trim($matches[2]);
            
            return $this->processCommand($user, $userType, $type, $freeText);
        }

        // If we get here, the command format was invalid
        $this->handleInvalidCommand($this->getCommandFormatHelp());
        return false;
    }

    /**
     * Normalizes a chat command message by removing the command prefix if required and validating its format.
     *
     * Returns the normalized command string if valid, or false if the message does not conform to the expected command format or prefix requirements. Moderation commands are returned unchanged.
     *
     * @param string $message The raw chat message to normalize.
     * @return string|false The normalized command string, or false if invalid.
     */
    private function normalizeCommand($message) {
        $lowercaseMessage = strtolower($message);
        
        // Check for moderation commands first
        if (strpos($lowercaseMessage, 'moderation:') === 0) {
            return $message; // Keep original case for moderation commands
        }

        // Check if message starts with prefix
        $lowercasePrefix = strtolower($this->command_prefix);
        $hasPrefix = strpos($lowercaseMessage, $lowercasePrefix . ':') === 0;

        if ($this->use_command_prefix) {
            // If prefix is required, it must be present
            if (!$hasPrefix) {
                return false;
            }
            return substr($message, strlen($this->command_prefix) + 1);
        } else {
            // Without required prefix, accept both formats
            if ($hasPrefix) {
                // If prefix exists, remove it
                $message = substr($message, strlen($this->command_prefix) + 1);
            }
            
            // Check if it has the basic command format (type:text)
            if (preg_match('/^[a-zA-Z]+:/', strtolower($message))) {
                return $message;
            }
        }

        return false;
    }

    /**
     * Returns a message describing the correct command format based on whether a command prefix is required.
     *
     * @return string Help message indicating the expected command syntax.
     */
    private function getCommandFormatHelp() {
        if ($this->use_command_prefix) {
            return "❌ Invalid command format. Use: {$this->command_prefix}:type:text or Moderation:type:";
        } else {
            return "❌ Invalid command format. Use: type:text or Moderation:type:";
        }
    }

    /**
     * Determines if a message is a help command and identifies its type.
     *
     * Returns 'general' for general help commands, 'specific' for help requests targeting a specific command, or false if the message is not a help command.
     *
     * @param string $message The chat message to evaluate.
     * @return string|false The type of help command ('general', 'specific'), or false if not a help command.
     */
    private function isHelpCommand($message) {
        // Check if message starts with ! and matches any help keyword (case insensitive)
        if (strlen($message) > 1 && $message[0] === '!') {
            $keyword = strtolower(substr($message, 1));
            
            // Check for general help keywords
            if (in_array($keyword, array_map('strtolower', $this->help_keywords))) {
                return 'general';
            }
            
            // Check for specific command help
            if ($this->isSpecificCommandHelp($keyword)) {
                return 'specific';
            }
        }
        return false;
    }
    
    /**
     * Determines if a keyword corresponds to an enabled command type, matching either user-facing or developer command names.
     *
     * @param string $keyword The command keyword to check.
     * @return bool True if the keyword matches an enabled command type; otherwise, false.
     */
    private function isSpecificCommandHelp($keyword) {
        // Check if the keyword matches any enabled command (user name or dev name)
        $validTypes = array_keys($this->command_types_enabled);
        $validUserTypes = array_map([$this, 'getUserCommandName'], $validTypes);
        
        // Check against user command names
        foreach ($validUserTypes as $userType) {
            if (strtolower($userType) === $keyword && $this->command_types_enabled[$this->getDevCommandName($userType)]) {
                return true;
            }
        }
        
        // Check against dev command names
        foreach ($validTypes as $devType) {
            if (strtolower($devType) === $keyword && $this->command_types_enabled[$devType]) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Sends a detailed help message for a specific command type based on the provided keyword.
     *
     * Determines the command type from the keyword, constructs a usage example, and sends a description of the command's purpose and usage to the user.
     *
     * @param string $keyword The command keyword for which help is requested.
     */
    private function handleSpecificCommandHelp($keyword) {
        // Find the command type (try user name first, then dev name)
        $commandType = null;
        $displayName = null;
        
        // Check user command names first
        $validTypes = array_keys($this->command_types_enabled);
        $validUserTypes = array_map([$this, 'getUserCommandName'], $validTypes);
        
        foreach ($validUserTypes as $userType) {
            if (strtolower($userType) === $keyword) {
                $devType = $this->getDevCommandName($userType);
                if ($this->command_types_enabled[$devType]) {
                    $commandType = $devType;
                    $displayName = $userType;
                    break;
                }
            }
        }
        
        // If not found, check dev command names
        if (!$commandType) {
            foreach ($validTypes as $devType) {
                if (strtolower($devType) === $keyword && $this->command_types_enabled[$devType]) {
                    $commandType = $devType;
                    $displayName = $this->getUserCommandName($devType);
                    break;
                }
            }
        }
        
        if (!$commandType) {
            return; // Should not happen if isSpecificCommandHelp returned true
        }
        
        // Generate specific help message
        $format = $this->use_command_prefix ? "{$this->command_prefix}:$displayName:" : "$displayName:";
        
        switch ($commandType) {
            case 'instruction':
                $helpMessage = "🎬 $format - Orchestrates a scene involving multiple NPCs (up to 2-3). Use this to create complex interactions between characters or set up dramatic scenarios. Example: $format" . "Have Lydia and Serana discuss the ancient ruins while exploring together";
                break;
            case 'suggestion':
                $helpMessage = "🕒 $format - Makes a single NPC perform an action with dialogue. Use this for simple, immediate NPC interactions or reactions. Example: $format" . "Make Lydia comment on the weather and adjust her armor";
                break;
            case 'impersonation':
                $helpMessage = "🗣️ $format - Speak on behalf of the player character. Use this to have your character say or do something in the scene. Example: $format" . "I draw my sword and cautiously approach the mysterious door";
                break;
            case 'spawn':
                $helpMessage = "👥 $format - Create a bio for a new custom AI NPC. Describe the character you want to add to the scene. Example: $format" . "A wise old merchant who sells rare magical artifacts and speaks in riddles";
                break;
            case 'encounter':
                $helpMessage = "⚔️ $format - Spawn 1-3 NPCs of a specific type for encounters. Must include one NPC type: " . implode(', ', $this->legal_encounter_npcs) . ". Example: $format" . "Bandits ambush the party on the road";
                break;
            default:
                $helpMessage = "📖 $format - No specific help available for this command.";
                break;
        }
        
        $this->sendMessage($helpMessage);
    }

    /**
     * Processes a user command by validating its type and executing it if enabled.
     *
     * Validates the user-facing command type, checks if the corresponding developer command type is enabled, and executes the command if permitted. Sends appropriate feedback messages based on the outcome.
     *
     * @param string $user The username issuing the command.
     * @param string $userType The user-facing command type as provided by the user.
     * @param string $type The internal developer command type.
     * @param string $freeText The command's free text or arguments.
     * @return bool True if the command was successfully executed; false otherwise.
     */
    private function processCommand($user, $userType, $type, $freeText) {
        // Validate the type
        $validTypes = array_keys($this->command_types_enabled);
        $validUserTypes = array_map([$this, 'getUserCommandName'], $validTypes);
        
        // First check if the user's command type is valid
        if (!in_array($userType, $validUserTypes)) {
            $this->handleInvalidCommand("❌ Invalid command type. Valid types are: " . implode(', ', $validUserTypes));
            return false;
        }

        // Then check if the mapped dev command is enabled
        if (!isset($this->command_types_enabled[$type]) || !$this->command_types_enabled[$type]) {
            // Log the ignored disabled command attempt
            return false;
        }

        // Execute the command with original case preserved freeText
        if ($this->executeCommand($user, 'rolemaster', $type, $freeText)) {
            // Reset invalid command count on successful command
            $this->invalid_command_count = 0;
            $this->sendMessage("✅ Command accepted!");
            return true;
        }

        return false;
    }

    /**
     * Sends a help message to the user listing all enabled AI commands with their descriptions and usage formats.
     */
    private function handleHelpCommand($user) {
        // Build help message showing available commands and their descriptions
        $helpMessage = "🤖 AI Commands Available:";
        
        if ($this->command_types_enabled['instruction']) {
            $userCommand = $this->getUserCommandName('instruction');
            $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
            $helpMessage .= " 🎬 $format Orchestrates a scene involving multiple NPCs (up to 2-3)";
        }
        
        if ($this->command_types_enabled['suggestion']) {
            $userCommand = $this->getUserCommandName('suggestion');
            $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
            $helpMessage .= " 🕒 $format Makes a single NPC perform an action with dialogue";
        }
        
        if ($this->command_types_enabled['impersonation']) {
            $userCommand = $this->getUserCommandName('impersonation');
            $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
            $helpMessage .= " 🗣️ $format Speak on behalf of the player";
        }
        
        if ($this->command_types_enabled['spawn']) {
            $userCommand = $this->getUserCommandName('spawn');
            $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
            $helpMessage .= " 👥 $format Create a bio for a new custom AI NPC";
        }

        if ($this->command_types_enabled['encounter']) {
            $userCommand = $this->getUserCommandName('encounter');
            $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
            $helpMessage .= " ⚔️ $format Create an enemy encounter";
        }

        $this->sendMessage($helpMessage);
    }

    /**
     * Executes a user command by sanitizing input, validating format and length, and invoking the external manager script.
     *
     * For "encounter" commands, performs additional API processing before execution. Returns true on successful execution, or false if validation fails or an error occurs.
     *
     * @param string $user The username issuing the command.
     * @param string $task The task or action to perform.
     * @param string $type The command type (e.g., instruction, encounter).
     * @param string $freeText The user-provided command text.
     * @return bool True if the command was executed successfully, false otherwise.
     */
    public function executeCommand($user, $task, $type, $freeText) {
        // First sanitize common text characters that won't alter meaning
        // Handle both ASCII and Unicode quotes/apostrophes (smart quotes from Apple devices)
        $sanitizedFreeText = preg_replace('/[\'"`\x{2018}\x{2019}\x{201C}\x{201D}\x{201A}\x{201E}\x{00AB}\x{00BB}\x{2039}\x{203A}]/u', "", $freeText); // remove all quote variants
        // Handle various dash types (en-dash, em-dash, etc.)
        $sanitizedFreeText = preg_replace('/[_\-\x{2013}\x{2014}]/u', ' ', $sanitizedFreeText); // Convert dashes/underscores to spaces

        // Input validation - only allow alphanumeric characters, spaces, and basic punctuation
        if (!preg_match('/^[a-zA-Z0-9\s\.,!?]+$/', $sanitizedFreeText)) {
            $this->handleInvalidCommand("❌ Invalid command format. Only letters, numbers, spaces and basic punctuation are allowed.");
            return false;
        }

        // Command length limit
        if (strlen($sanitizedFreeText) > 1024) {
            $this->handleInvalidCommand("❌ Command too long. Maximum length is 1024 characters.");
            return false;
        }

        // Use the sanitized text for command execution
        if (!file_exists($this->php_path)) {
            $this->handleInvalidCommand("❌ System error: PHP executable not found");
            return false;
        }

        if (!file_exists($this->manager_script)) {
            $this->handleInvalidCommand("❌ System error: Manager script not found");
            return false;
        }

        // Handle encounter command special processing (future API call scaffold)
        if ($type === 'encounter') {
            // Call the encounter API handler and check if successful
            if (!$this->handleEncounterAPI($user, $sanitizedFreeText)) {
                // API call failed, error already handled in handleEncounterAPI
                return false;
            }
            
            // API call successful, now treat encounter the same as instruction
            $type = 'instruction';
        }

        // Execute command with proper escaping and using configured paths
        $cmd = sprintf('%s %s %s %s %s',
            escapeshellarg($this->php_path),
            escapeshellarg($this->manager_script),
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
            $this->invalid_command_count = 0;
            return true;
        } else {
            error_log("Command execution failed. Output: " . implode("\n", $output));
            $this->handleInvalidCommand("❌ Error executing command");
            return false;
        }
    }

    /**
     * Handles moderation-specific commands such as displaying help or reporting current permission settings.
     *
     * Supports 'help' to list available commands and 'permissions' to show current moderation and command enablement status. Sends an error message for unknown moderation commands.
     */
    public function handleModerationCommand($user, $type, $freeText) {
        switch (strtolower($type)) {
            case 'help':
                // Send help message to chat with user-facing command names
                $helpMessage = "📖 Commands: ";
                
                if ($this->command_types_enabled['instruction']) {
                    $userCommand = $this->getUserCommandName('instruction');
                    $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
                    $helpMessage .= "🎬 $format | ";
                }
                
                if ($this->command_types_enabled['suggestion']) {
                    $userCommand = $this->getUserCommandName('suggestion');
                    $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
                    $helpMessage .= "🕒 $format | ";
                }
                
                if ($this->command_types_enabled['impersonation']) {
                    $userCommand = $this->getUserCommandName('impersonation');
                    $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
                    $helpMessage .= "🗣️ $format | ";
                }
                
                if ($this->command_types_enabled['spawn']) {
                    $userCommand = $this->getUserCommandName('spawn');
                    $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
                    $helpMessage .= "👥 $format | ";
                }
                
                if ($this->command_types_enabled['encounter']) {
                    $userCommand = $this->getUserCommandName('encounter');
                    $format = $this->use_command_prefix ? "{$this->command_prefix}:$userCommand:" : "$userCommand:";
                    $helpMessage .= "⚔️ $format | ";
                }
                
                $helpMessage .= "🔒 Moderation:permissions:";
                $this->sendMessage($helpMessage);
                break;
                
            case 'permissions':
                // Send current permission settings
                $permMessage = sprintf("🔒 Current Permissions: Mods Only: %s | Subs Only: %s | Whitelist: %s | Commands: %s%s%s%s%s",
                    $this->mods_only ? "✅" : "❌",
                    $this->subs_only ? "✅" : "❌",
                    $this->whitelist_enabled ? "✅" : "❌",
                    $this->command_types_enabled['instruction'] ? "🎬" : "❌",
                    $this->command_types_enabled['suggestion'] ? "🕒" : "❌",
                    $this->command_types_enabled['impersonation'] ? "🗣️" : "❌",
                    $this->command_types_enabled['spawn'] ? "👥" : "❌",
                    $this->command_types_enabled['encounter'] ? "⚔️" : "❌"
                );
                $this->sendMessage($permMessage);
                break;
                
            default:
                $this->handleInvalidCommand("❌ Unknown moderation command. Use Moderation:help: to see available commands.");
                break;
        }
    }
    
    /**
     * Processes an encounter command by validating the NPC type and triggering a spawn via a REST API call.
     *
     * Validates that the encounter description contains exactly one allowed NPC type, then makes a REST API request using PowerShell to spawn a random number (1-3) of that NPC. Returns true on success, or false if validation fails or the API call encounters an error.
     *
     * @param string $user The user who initiated the encounter command.
     * @param string $freeText The encounter description or request text.
     * @return bool True if the encounter was successfully spawned; false otherwise.
     */
    private function handleEncounterAPI($user, $freeText) {
        
        // Convert to lowercase for case-insensitive matching
        $lowerText = strtolower($freeText);
        
        // Find all matching NPCs in the text
        $foundNpcs = array();
        foreach ($this->legal_encounter_npcs as $npc) {
            // Check if NPC name appears as a prefix in any word of the text
            if (preg_match('/\b' . preg_quote($npc, '/') . '/i', $freeText)) {
                $foundNpcs[] = $npc;
            }
        }
        
        // Validate exactly one NPC was found
        if (empty($foundNpcs)) {
            $this->handleInvalidCommand("❌ No valid NPC type found. Valid types: " . implode(', ', $this->legal_encounter_npcs));
            return false;
        }
        
        if (count($foundNpcs) > 1) {
            $this->handleInvalidCommand("❌ Multiple NPC types found: " . implode(', ', $foundNpcs) . ". Please specify only one NPC type.");
            return false;
        }
        
        // Get the single valid NPC
        $selectedNpc = $foundNpcs[0];
        
        // Generate random count between 1-3
        $count = rand(1, 3);
        
        
        // Make API request using WSL's Windows integration to call PowerShell
        echo "🔍 Starting encounter API call for user: $user, NPC: $selectedNpc, count: $count\n";
        
        try {
            echo "📡 Using Windows PowerShell via WSL integration...\n";
            
            // Use powershell.exe through WSL's Windows integration
            // PowerShell will run in Windows context and can access localhost:8965
            $psCommand = '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe -Command "try { Invoke-RestMethod -Uri \'http://localhost:8965/encounter?npc=' . urlencode($selectedNpc) . '&count=' . $count . '\' -Method GET -TimeoutSec 10; Write-Output \'Success\' } catch { Write-Error $_.Exception.Message; exit 1 }"';
            
            echo "🔧 PowerShell command: $psCommand\n";
            
            $output = [];
            $returnCode = 0;
            exec($psCommand . ' 2>&1', $output, $returnCode);
            
            $allOutput = implode("\n", $output);
            echo "📋 PowerShell output: $allOutput\n";
            echo "📊 Return code: $returnCode\n";
            
            if ($returnCode !== 0) {
                echo "❌ PowerShell request failed\n";
                error_log("Encounter API PowerShell error (code: $returnCode): $allOutput for URL: http://localhost:8965/encounter?npc=" . urlencode($selectedNpc) . "&count=" . $count);
                $this->handleInvalidCommand("❌ Failed to connect to encounter system (PowerShell error: $returnCode)");
                return false;
            }
            
            echo "✅ PowerShell request successful!\n";
            
            // Log successful encounter
            echo "🎉 Encounter spawned successfully!\n";
            error_log("Encounter spawned by $user: $count x $selectedNpc (via PowerShell)");
            
            return true;
            
        } catch (Exception $e) {
            echo "💥 Exception: " . $e->getMessage() . "\n";
            error_log("Encounter API exception: " . $e->getMessage());
            $this->handleInvalidCommand("❌ Encounter system error occurred");
            return false;
        }
    }
} 