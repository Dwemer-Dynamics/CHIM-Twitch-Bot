<?php
header('Content-Type: application/json');

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username'])) {
    echo json_encode(['success' => false, 'error' => 'Missing username']);
    exit;
}

// Clean and validate username
$username = trim($data['username']);
// Convert to lowercase since Twitch usernames are case-insensitive
$username = strtolower($username);

// Basic validation - just ensure it's not empty and doesn't contain spaces or special characters
if (empty($username) || strlen($username) > 25 || preg_match('/[\s<>\'\"\\\\]/', $username)) {
    echo json_encode(['success' => false, 'error' => 'Invalid username format']);
    exit;
}

// Load existing lists
$lists_file = __DIR__ . "/user_lists.json";
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

// Check if user is already blacklisted
if (in_array($username, $lists['blacklist'])) {
    echo json_encode(['success' => true, 'message' => 'User was already blacklisted']);
    exit;
}

// Add to blacklist
$lists['blacklist'][] = $username;

// Sort the list
sort($lists['blacklist']);

// Save the updated lists
$success = file_put_contents($lists_file, json_encode($lists));

if ($success) {
    // Create a timestamp file to signal the service to reload the lists
    touch(__DIR__ . "/lists_updated.flag");
    
    echo json_encode([
        'success' => true,
        'message' => "User '$username' has been added to the blacklist",
        'lists' => $lists
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save blacklist'
    ]);
}
?> 