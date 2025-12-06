<?php
/**
 * example.php
 * Simple usage examples for TuyaApiPhp.php
 * - Reads credentials from environment variables (do NOT commit real secrets)
 * - Shows common operations: list devices, lookup by name, get info, check online
 * - Non-destructive: command send is shown but commented out
 */

require_once __DIR__ . '/TuyaApiPhp.php';

$client_id = getenv('TUYA_CLIENT_ID') ?: null;
$client_secret = getenv('TUYA_CLIENT_SECRET') ?: null;
$region = getenv('TUYA_REGION') ?: 'eu';

if (empty($client_id) || empty($client_secret)) {
    echo "ERROR: Set TUYA_CLIENT_ID and TUYA_CLIENT_SECRET in environment before running.\n";
    exit(1);
}

$tuya = new TuyaApiPhp($client_id, $client_secret, $region);

// Safe wrapper to run an action and display result or error
function runCall(callable $fn) {
    try {
        $res = $fn();
        echo "RESULT:\n";
        print_r($res);
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n--- List first page of devices (up to 20) ---\n";
runCall(function() use ($tuya) {
    return $tuya->getAllDevices(20, false); // fetch single page
});

$friendlyName = 'Lavastoviglie';
echo "\n--- Lookup device id by friendly name: {$friendlyName} ---\n";
runCall(function() use ($tuya, $friendlyName) {
    return $tuya->getDeviceIdByName($friendlyName);
});

$deviceIdentifier = $tuya->getDeviceIdByName($friendlyName) ?: null;
if ($deviceIdentifier) {
    echo "\n--- Get device info (by id) for {$deviceIdentifier} ---\n";
    runCall(function() use ($tuya, $deviceIdentifier) {
        return $tuya->getDeviceInfo($deviceIdentifier);
    });

    echo "\n--- Check if device is online ---\n";
    runCall(function() use ($tuya, $deviceIdentifier) {
        return $tuya->isDeviceOnline($deviceIdentifier);
    });

    echo "\n--- Get device status ---\n";
    runCall(function() use ($tuya, $deviceIdentifier) {
        return $tuya->getDeviceStatus($deviceIdentifier);
    });

    // Example: send a non-destructive command (commented out). Uncomment to use.
    // Note: use the correct 'code' for your device
    /*
    echo "\n--- Send sample command (turn off) ---\n";
    runCall(function() use ($tuya, $deviceIdentifier) {
        return $tuya->setDeviceStatus($deviceIdentifier, [['code' => 'switch_1', 'value' => false]]);
    });
    */

} else {
    echo "Device with name '{$friendlyName}' not found; skipping device-specific checks.\n";
}

echo "\nExample script finished.\n";
