<?php
// Test file to verify hook can be loaded
define('NOREQUIRESOC', '1');
define('NOCSRFCHECK', '1');

require '../../main.inc.php';

echo "<!DOCTYPE html><html><head><title>WhatsApp Hook Test</title></head><body>";
echo "<h1>WhatsApp Hook Loading Test</h1>";

// Check if module is enabled
if (empty($conf->whatsappdati->enabled)) {
    echo "<p style='color:red'>❌ Module is NOT enabled</p>";
} else {
    echo "<p style='color:green'>✓ Module is enabled</p>";
}

// Check if hook file exists
$hookFile = __DIR__ . '/actions_whatsappdati.class.php';
if (file_exists($hookFile)) {
    echo "<p style='color:green'>✓ Hook file exists at: " . htmlspecialchars($hookFile) . "</p>";
} else {
    echo "<p style='color:red'>❌ Hook file NOT found at: " . htmlspecialchars($hookFile) . "</p>";
}

// Try to load the hook
try {
    require_once $hookFile;
    echo "<p style='color:green'>✓ Hook file loaded successfully</p>";
    
    if (class_exists('ActionsWhatsappdati')) {
        echo "<p style='color:green'>✓ Class ActionsWhatsappdati exists</p>";
        
        $hook = new ActionsWhatsappdati($db);
        echo "<p style='color:green'>✓ Hook instance created</p>";
        
        // Try to call the hook
        $parameters = array('context' => 'test');
        $object = new stdClass();
        $action = '';
        
        $result = $hook->printCommonFooter($parameters, $object, $action);
        echo "<p style='color:green'>✓ printCommonFooter executed, returned: " . $result . "</p>";
        
        if (!empty($hook->resprints)) {
            echo "<h3>Hook Output:</h3>";
            echo "<pre>" . htmlspecialchars($hook->resprints) . "</pre>";
        }
        
    } else {
        echo "<p style='color:red'>❌ Class ActionsWhatsappdati does NOT exist after loading file</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error loading hook: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Check permissions
if (!empty($user->rights->whatsappdati->conversation->read)) {
    echo "<p style='color:green'>✓ User has 'read conversation' permission</p>";
} else {
    echo "<p style='color:red'>❌ User does NOT have 'read conversation' permission</p>";
}

// Check if hooks are registered
global $hookmanager;
if (isset($hookmanager)) {
    echo "<h3>Registered Hooks:</h3>";
    echo "<pre>";
    print_r($hookmanager);
    echo "</pre>";
}

echo "</body></html>";
