<?php
// Debug script to isolate test_command.php issues

echo "=== Debug Test Script ===\n";

// Step 1: Test require_once
echo "1. Testing require_once command.php...\n";
try {
    require_once __DIR__ . '/command.php';
    echo "   ✅ Successfully loaded command.php\n";
} catch (Exception $e) {
    echo "   ❌ Error loading command.php: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 2: Test environment loading
echo "2. Testing environment loading...\n";
try {
    $env_file = __DIR__ . "/bot_env.json";
    if (file_exists($env_file)) {
        $env_vars = json_decode(file_get_contents($env_file), true);
        if ($env_vars) {
            foreach ($env_vars as $key => $value) {
                putenv("$key=$value");
            }
            echo "   ✅ Successfully loaded environment variables\n";
        } else {
            echo "   ⚠️ bot_env.json exists but couldn't decode JSON\n";
        }
    } else {
        echo "   ⚠️ bot_env.json doesn't exist\n";
    }
} catch (Exception $e) {
    echo "   ❌ Error loading environment: " . $e->getMessage() . "\n";
}

// Step 3: Test CommandHandler instantiation
echo "3. Testing CommandHandler instantiation...\n";
try {
    $messages = [];
    $commandHandler = new CommandHandler(null, null, function($msg) use (&$messages) {
        $messages[] = $msg;
    });
    echo "   ✅ Successfully created CommandHandler\n";
} catch (Exception $e) {
    echo "   ❌ Error creating CommandHandler: " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

// Step 4: Test simple command parsing
echo "4. Testing simple command parsing...\n";
try {
    $result = $commandHandler->parseCommand('@local_tester', '!help', true, true);
    echo "   ✅ Successfully parsed help command, result: " . ($result ? 'true' : 'false') . "\n";
    echo "   Messages: " . implode(', ', $messages) . "\n";
} catch (Exception $e) {
    echo "   ❌ Error parsing command: " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
}

// Step 5: Test encounter command parsing
echo "5. Testing encounter command parsing...\n";
try {
    $messages = []; // Reset messages
    $result = $commandHandler->parseCommand('@local_tester', 'encounter:bandits attack', true, true);
    echo "   Result: " . ($result ? 'true' : 'false') . "\n";
    echo "   Messages: " . implode(', ', $messages) . "\n";
} catch (Exception $e) {
    echo "   ❌ Error parsing encounter command: " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "=== Debug Complete ===\n";
?> 