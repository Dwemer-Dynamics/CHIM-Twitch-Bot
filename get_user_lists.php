<?php
header('Content-Type: application/json');

// File paths for the lists
$lists_file = __DIR__ . "/user_lists.json";

// Default empty lists
$lists = [
    'whitelist' => [],
    'blacklist' => []
];

// Load existing lists if the file exists
if (file_exists($lists_file)) {
    $file_content = file_get_contents($lists_file);
    if ($file_content !== false) {
        $loaded_lists = json_decode($file_content, true);
        if (is_array($loaded_lists)) {
            // Ensure both lists exist in the loaded data
            $lists['whitelist'] = $loaded_lists['whitelist'] ?? [];
            $lists['blacklist'] = $loaded_lists['blacklist'] ?? [];
        }
    }
}

// Sort the lists alphabetically
sort($lists['whitelist']);
sort($lists['blacklist']);

echo json_encode([
    'success' => true,
    'lists' => $lists
]);
?> 