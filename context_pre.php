<?php
/**
 * Inject recent Twitch chat lines into prompt nearby sections.
 * Lives in CHIM-Twitch-Bot so prompt behavior is configured from this repo.
 */

if (!function_exists('twitchContextClamp')) {
    function twitchContextClamp($value, $min, $max) {
        $value = intval($value);
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}

if (!function_exists('twitchContextReadBotSettings')) {
    function twitchContextReadBotSettings() {
        $envPath = __DIR__ . DIRECTORY_SEPARATOR . "bot_env.json";
        if (!file_exists($envPath)) {
            return [false, 25];
        }

        $raw = file_get_contents($envPath);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return [false, 25];
        }

        $enabled = isset($data["TBOT_TWITCH_CONTEXT_ENABLED"]) && (string)$data["TBOT_TWITCH_CONTEXT_ENABLED"] === "1";
        $count = twitchContextClamp($data["TBOT_TWITCH_CONTEXT_COUNT"] ?? 25, 10, 100);
        return [$enabled, $count];
    }
}

if (!function_exists('twitchContextIsBotRunning')) {
    function twitchContextIsBotRunning() {
        $pidFile = __DIR__ . DIRECTORY_SEPARATOR . "bot_pid.txt";
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = trim((string)@file_get_contents($pidFile));
        if ($pid === "" || !ctype_digit($pid)) {
            return false;
        }

        $procPath = "/proc/" . $pid;
        if (is_dir($procPath)) {
            $cmdLinePath = $procPath . "/cmdline";
            if (file_exists($cmdLinePath)) {
                $cmdline = (string)@file_get_contents($cmdLinePath);
                if (strpos($cmdline, "service.php") !== false && strpos($cmdline, "CHIM-Twitch-Bot") !== false) {
                    return true;
                }
            }
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill((int)$pid, 0);
        }

        return false;
    }
}

if (!function_exists('twitchContextReadMessages')) {
    function twitchContextReadMessages() {
        $enginePath = isset($GLOBALS["ENGINE_PATH"]) ? rtrim($GLOBALS["ENGINE_PATH"], "/\\") : "";
        $candidates = [];

        if ($enginePath !== "") {
            $candidates[] = $enginePath . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR . "twitch_chat_context.json";
        }
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . "twitch_chat_context.json";

        foreach ($candidates as $candidate) {
            if (!file_exists($candidate)) {
                continue;
            }
            $raw = file_get_contents($candidate);
            if ($raw === false || trim($raw) === "") {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded["messages"]) && is_array($decoded["messages"])) {
                return $decoded["messages"];
            }
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}

if (!function_exists('twitchContextCleanText')) {
    function twitchContextCleanText($value, $maxLen = 220) {
        $value = preg_replace('/[\r\n\t]+/', ' ', (string)$value);
        $value = trim($value);
        if (strlen($value) > $maxLen) {
            $value = substr($value, 0, $maxLen);
        }
        return $value;
    }
}

if (!function_exists('twitchContextInjectIntoNearbySections')) {
    function twitchContextInjectIntoNearbySections($sections, $contextBlock) {
        if (!is_string($sections)) {
            $sections = "";
        }

        if (strpos($sections, "<twitch_chat>") !== false) {
            return $sections;
        }

        if (strpos($sections, "</points_of_interest>") !== false) {
            return preg_replace('/<\/points_of_interest>/', "</points_of_interest>\n" . $contextBlock, $sections, 1);
        }

        return rtrim($sections) . "\n" . $contextBlock;
    }
}

// Prevent accidental double execution if another legacy file still exists.
if (!empty($GLOBALS["TWITCH_CONTEXT_ALREADY_INJECTED"])) {
    return;
}
$GLOBALS["TWITCH_CONTEXT_ALREADY_INJECTED"] = true;

list($enabled, $messageCount) = twitchContextReadBotSettings();
if (!$enabled) {
    if (class_exists('Logger')) {
        Logger::debug("[TWITCH_CONTEXT] Disabled in TBOT settings.");
    }
    return;
}

if (!twitchContextIsBotRunning()) {
    if (class_exists('Logger')) {
        Logger::debug("[TWITCH_CONTEXT] Bot not running, skipping context injection.");
    }
    return;
}

$messages = twitchContextReadMessages();
if (empty($messages) || !is_array($messages)) {
    if (class_exists('Logger')) {
        Logger::debug("[TWITCH_CONTEXT] Enabled but no chat messages available.");
    }
    return;
}

$recentMessages = array_slice($messages, -1 * $messageCount);
$lines = [];
foreach ($recentMessages as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $user = twitchContextCleanText($entry["user"] ?? "", 40);
    $message = twitchContextCleanText($entry["message"] ?? "", 220);
    if ($user === "" || $message === "") {
        continue;
    }

    $lines[] = "## {$user}: {$message}";
}

if (empty($lines)) {
    if (class_exists('Logger')) {
        Logger::debug("[TWITCH_CONTEXT] Messages found but no valid lines after sanitization.");
    }
    return;
}

$contextBlock = "<twitch_chat>\n" .
    "# TWITCH CHAT CONTEXT (offscreen viewers)\n" .
    "# This block is real valid information and available as offscreen audience chatter.\n" .
    "# If someones asks about chat/viewers/comments/reactions, acknowledge this block and use it in your response.\n" .
    "# Twitch viewers are NOT physically present NPCs. Never use twitch usernames as action targets or listeners.\n" .
    implode("\n", $lines) .
    "\n</twitch_chat>";

$currentNearby = $GLOBALS["PROMPT_NEARBY_SECTIONS"] ?? "";
$GLOBALS["PROMPT_NEARBY_SECTIONS"] = twitchContextInjectIntoNearbySections($currentNearby, $contextBlock);

if (class_exists('Logger')) {
    Logger::debug("[TWITCH_CONTEXT] Injected " . count($lines) . " chat lines under points_of_interest as <twitch_chat>.");
}

