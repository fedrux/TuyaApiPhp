# TuyaApiPhp
Connect to Tuya APIs easy with TuyaApiPhp.php

Class Reference — `TuyaApiPhp`
--------------------------------

This project includes a lightweight PHP client in `TuyaApiPhp.php` that wraps Tuya Cloud operations used by the energy manager. The class purpose is to:

- Obtain an access token with client credentials.
- Sign requests to Tuya Cloud using HMAC-SHA256 per Tuya spec.
- List devices (v2 API) with pagination.
- Query device status and send commands to devices (v1 endpoints).

Below is a concise description of the main public methods and example usage patterns. All examples assume you have instantiated the class:

```php
$tuya = new TuyaApiPhp($client_id, $client_secret, $region = 'eu');
```

- `__construct(string $client_id, string $client_secret, string $region = 'eu')`
  - Stores credentials and region for API calls.
  - Usage: `new TuyaApiPhp('CLIENT_ID','CLIENT_SECRET','eu');`

- `tuyaSign($client_id, $secret, $access_token, $method, $path, $queryString = '', $body = '')`
  - Internal helper that builds the Tuya canonical string-to-sign and returns `['sign','t','nonce']`.
  - Important: uses sorted, RFC3986-encoded query parameters so signatures remain stable for requests with query strings.
  - You don't normally call this method directly (the class uses it internally), but if you inspect requests it returns the signature fields used in the headers.

- `getToken(): string`
  - Requests `/v1.0/token?grant_type=1` and stores `access_token` on the instance.
  - Throws an `Exception` on error. Typical usage is internal; `pathRequester()` will call this when needed.

- `pathRequester(string $path)`
  - Centralized GET helper. Ensures an access token is present, signs the request using `tuyaSign`, and performs a GET to the full URL built from `https://openapi.tuya{region}.com{path}`.
  - Returns the decoded `result` array from Tuya, or throws an Exception with contextual message.
  - Example: `$devicesPage = $tuya->pathRequester('/v2.0/cloud/thing/device?page_size=20');`

- `tuyaCurlGet(string $url, array $headers)`
  - Low-level cURL GET wrapper used by `pathRequester`.
  - Parses JSON, detects cURL failures and Tuya-style error payloads (e.g., `success:false` or numeric `code`) and throws exceptions.
  - This method is private but useful to review when debugging request/response issues.

- `getAllDevices(int $page_size = 20, bool $fetch_all = true): array`
  - Fetches the v2 device list `/v2.0/cloud/thing/device` and pages via `last_id`.
  - Returns a flattened array of device objects. `last_id` values are rawurlencoded before signing to avoid signature/URL mismatch.
  - Example: `$all = $tuya->getAllDevices(50); // returns up to 50 devices or more if fetch_all true`

- `getDeviceIdByName(string $device_name, bool $search_custom_name = true): ?string`
  - Scans devices returned by `getAllDevices()` and returns the first matching `id`.
  - Searches `customName` (preferred) and `name`. Returns `null` if not found.
  - Example: `$id = $tuya->getDeviceIdByName('Lavastoviglie');`

- `getDeviceStatus(string $device_id): array`
  - Calls `/v1.0/devices/{device_id}/status` via `pathRequester` and returns the device status array. Throws on errors.
  - Example: `$status = $tuya->getDeviceStatus('bf196098d123b958b0ullh');`

- `setDeviceStatus(string $device_id, array $commands): array`
  - Sends a command POST to `/v1.0/iot-03/devices/{device_id}/commands`.
  - `commands` is an array of objects like `[['code'=>'switch_1','value'=>true]]`.
  - The method builds a JSON body, signs it (HMAC uses body hash), performs a cURL POST, and throws on Tuya error payloads.
  - Example: `$tuya->setDeviceStatus($id, [['code'=>'switch_1','value'=>false]]);`

- `getDeviceInfo(string $identifier, bool $searchByName = false): array`
  - Attempts to read single-device info using `/v1.0/devices/{id}` when `identifier` is an id (preferred), otherwise falls back to scanning the device list for a matching `id`, `name`, or `customName`.
  - Throws `Exception` if no device found.
  - Example: `$info = $tuya->getDeviceInfo('bf196098d123b958b0ullh');`
  - Example (by name): `$info = $tuya->getDeviceInfo('Lavastoviglie', true);`

- `isDeviceOnline(string $device_id_or_name): bool`
  - Convenience method that finds a device by id or name and returns the `isOnline`/`online` flag (boolean). Throws if device not found.
  - The method safely scans the v2 device list and returns `false` when online flag is absent.
  - Example: `if ($tuya->isDeviceOnline('Lavastoviglie')) { /* device online */ }`

Practical notes and tips
------------------------
- Signing: Tuya requires a deterministic canonical query when building the signature. The client now sorts and encodes query parameters before signing to avoid `sign invalid` errors.
- Token refresh: The current client fetches a token when `access_token` is empty; it does not automatically refresh on explicit 401 responses. Wrap calls in try/catch and call `getToken()` again on token-related errors.
- Error handling: Methods throw `Exception` with Tuya error messages and numeric codes where available. Catch and handle these in your orchestration logic.
- Secrets: Keep `client_id` and `client_secret` out of source control — use `.env` and `getenv()` in test scripts.

Example usage snippet — full flow
```php
require_once 'TuyaApiPhp.php';

$tuya = new TuyaApiPhp(getenv('TUYA_CLIENT_ID'), getenv('TUYA_CLIENT_SECRET'), getenv('TUYA_REGION') ?: 'eu');

try {
    // List devices
    $devices = $tuya->getAllDevices(50);

    // Find a device by friendly name
    $id = $tuya->getDeviceIdByName('Lavastoviglie');
    if ($id) {
        // Read status
        $status = $tuya->getDeviceStatus($id);

        // Send a command
        $tuya->setDeviceStatus($id, [['code' => 'switch_1', 'value' => false]]);
    }
} catch (Exception $e) {
    echo "Tuya error: " . $e->getMessage();
}
```



