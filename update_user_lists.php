<?php
header('Content-Type: application/json');

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['listType']) || !isset($data['users']) || !is_array($data['users'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// Validate list type
if (!in_array($data['listType'], ['whitelist', 'blacklist'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid list type']);
    exit;
}

// File paths
$lists_file = __DIR__ . "/user_lists.json";
$flag_file = __DIR__ . "/lists_updated.flag";

// Load existing lists
$lists = [
    'whitelist' => [],
    'blacklist' => []
];

if (file_exists($lists_file)) {
    $file_content = file_get_contents($lists_file);
    if ($file_content !== false) {
        $loaded_lists = json_decode($file_content, true);
        if (is_array($loaded_lists)) {
            $lists = array_merge($lists, $loaded_lists);
        }
    }
}

// Clean and validate usernames
$cleaned_users = array_map(function($username) {
    return strtolower(trim($username));
}, $data['users']);

// Remove empty usernames and duplicates
$cleaned_users = array_filter($cleaned_users, function($username) {
    return !empty($username) && preg_match('/^[a-z0-9_]{1,25}$/', $username);
});
$cleaned_users = array_unique($cleaned_users);

// Update the specified list
$lists[$data['listType']] = array_values($cleaned_users);

// Sort the lists
sort($lists['whitelist']);
sort($lists['blacklist']);

// Save the updated lists
$success = file_put_contents($lists_file, json_encode($lists));

if ($success) {
    // Create the flag file to signal the bot service
    file_put_contents($flag_file, date('Y-m-d H:i:s'));
    
    echo json_encode([
        'success' => true,
        'lists' => $lists
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save user lists'
    ]);
}
?> 