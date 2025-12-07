<?php
/**
 * Docs API Tuya:
 * https://developer.tuya.com/en/docs/iot/new-singnature?id=Kbw0q34cs2e5g
 * https://eu.platform.tuya.com/cloud/basic?id=p1765036332595re5fw5&toptab=related&region=EU&deviceTab=all
 * https://developer.tuya.com/en/docs/cloud/e2512fb901?id=Kag2yag3tiqn5

 * TuyaApiPhp Class - PHP class to interact with Tuya Cloud API
 * Supports:
 *  - Login and access token generation
 * - Reading device status
 * - Batch device request
 * Requirements:
 * - PHP 7.4
 */


class TuyaApiPhp {
    private string $client_id;
    private string $client_secret;
    private string $region;
    private string $access_token;
    private $pathToken = "/v1.0/token?grant_type=1"; // grant_type=1 => Client Credentials

    public function __construct(string $client_id, string $client_secret, string $region = 'eu') {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->region = $region;
    }

    public function tuyaSign($client_id, $secret, $access_token, $method, $path, $queryString = '', $body = '') {
    $t = round(microtime(true) * 1000);
    $nonce = bin2hex(random_bytes(8));

    // Content-SHA256 (hash del body o stringa vuota)
    $bodyHash = hash('sha256', $body);

    // Build canonical query string: sort keys alphabetically and RFC3986-encode
    $canonicalQuery = '';
    if (!empty($queryString)) {
        // Parse query string into array
        parse_str($queryString, $params);
        if (is_array($params) && count($params) > 0) {
            ksort($params);
            $pairs = [];
            foreach ($params as $k => $v) {
                if (is_array($v)) {
                    // flatten arrays by preserving order of values
                    foreach ($v as $item) {
                        $pairs[] = rawurlencode($k) . '=' . rawurlencode((string)$item);
                    }
                } else {
                    $pairs[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
                }
            }
            $canonicalQuery = implode('&', $pairs);
        }
    }

    // stringToSign secondo spec Tuya (use canonical query)
    $url = $path . ($canonicalQuery ? '?' . $canonicalQuery : '');
    $stringToSign = $method . "\n" .
                    $bodyHash . "\n" .
                    "" . "\n" .
                    $url;

    // Costruzione str da firmare
    if ($access_token) {
        $str = $client_id . $access_token . $t . $nonce . $stringToSign;
    } else {
        $str = $client_id . $t . $nonce . $stringToSign;
    }

    $sign = strtoupper(hash_hmac('sha256', $str, $secret));

    return [
        'sign' => $sign,
        't' => $t,
        'nonce' => $nonce
    ];
}

/**
 * Get access token from Tuya API
 * 
 * @return string The access token
 * @throws Exception on error
 */
public function getToken(): string {
        $signData = $this->tuyaSign($this->client_id, $this->client_secret, null, "GET", $this->pathToken);
        $urlToken = "https://openapi.tuya{$this->region}.com{$this->pathToken}";
        $headersToken = [
            "client_id: {$this->client_id}",
            "sign: {$signData['sign']}",
            "sign_method: HMAC-SHA256",
            "t: {$signData['t']}",
            "nonce: {$signData['nonce']}",
            "Content-Type: application/json"
        ];

        try {
            $response = $this->tuyaCurlGet($urlToken, $headersToken);
        } catch (Exception $e) {
            throw new Exception("getToken failed: " . $e->getMessage(), $e->getCode());
        }

        if (isset($response['result']['access_token'])) {
            $this->access_token = $response['result']['access_token'];
            return $this->access_token;
        }

        throw new Exception("Errore token: " . json_encode($response));
}


private function pathRequester($path) {
    if (empty($this->access_token)) {
        $this->getToken();
    }

    // Split path and query string for proper signature calculation
    $pathParts = explode('?', $path, 2);
    $basePath = $pathParts[0];
    $queryString = isset($pathParts[1]) ? $pathParts[1] : '';

    // tuyaSign requires the base path and query string separately
    $signData = $this->tuyaSign($this->client_id, $this->client_secret, $this->access_token, "GET", $basePath, $queryString);

    $url = "https://openapi.tuya{$this->region}.com{$path}";
    $headers = [
        "client_id: {$this->client_id}",
        "access_token: {$this->access_token}",
        "sign: {$signData['sign']}",
        "sign_method: HMAC-SHA256",
        "t: {$signData['t']}",
        "nonce: {$signData['nonce']}",
        "Content-Type: application/json"
    ];

    try {
        $response = $this->tuyaCurlGet($url, $headers);
    } catch (Exception $e) {
        throw new Exception("Errore path: ". $path ." Message: " . $e->getMessage(), $e->getCode());
    }

    if (isset($response['result']) && is_array($response['result'])) {
        return $response['result'];
    } else {
        throw new Exception("Errore path: ". $path."; Eccezione: " . json_encode($response));
    }
}

/**
 * First 20 devices for the account
 * 
 * @return array List of devices
 * @throws Exception on error
 */
/**
 * Get devices from Tuya Cloud (v2.0 API) using `last_id` pagination.
 * The v2 API paginates by passing the last returned device id from previous page.
 *
 * @param int $page_size Number of items per page (default 20)
 * @param bool $fetch_all If true, fetch subsequent pages until no more results
 * @return array Flattened list of devices
 * @throws Exception on error
 */
public function getAllDevices(int $page_size = 20, bool $fetch_all = true): array {
    $page_size = min(max(1, (int)$page_size), 200);
    $last_id = null;
    $all = [];

    while (true) {
        $path = "/v2.0/cloud/thing/device?page_size={$page_size}";
        if (!empty($last_id)) {
            $path .= "&last_id=" . rawurlencode($last_id);
        }

        $pageResult = $this->pathRequester($path);
        if (!is_array($pageResult) || count($pageResult) === 0) {
            break;
        }

        foreach ($pageResult as $d) {
            $all[] = $d;
        }

        if (!$fetch_all) {
            break;
        }

        // Prepare next last_id (id of the last element in the returned page)
        $lastElement = end($pageResult);
        if (isset($lastElement['id'])) {
            $last_id = $lastElement['id'];
        } else {
            break;
        }

        // If returned page has fewer items than page_size, there are no more pages
        if (count($pageResult) < $page_size) {
            break;
        }
    
    } 
    return $all;
}


public function printAllDevices(): void {
    $devices = $this->getAllDevices();
    echo "<hr>\n";
    foreach ($devices as $device) {
        print_r($device);
        echo "<hr>\n";
    }
}  


/**
 * Find device ID by device name (searches both 'name' and 'customName')
 * 
 * @param string $device_name The name to search for
 * @param bool $search_custom_name If true, search 'customName' field; if false search 'name' field
 * @return string|null The device ID or null if not found
 * @throws Exception on error
 */
public function getDeviceIdByName(string $device_name, bool $search_custom_name = true): ?string {
    $devices = $this->getAllDevices();
    $field = $search_custom_name ? 'customName' : 'name';
    
    foreach ($devices as $device) {
        if (isset($device[$field]) && $device[$field] === $device_name) {
            return $device['id'];
        }
    }
    return null;
}


/**
 * Perform a GET request with cURL and handle errors
 * 
 * @param string $url The URL to request
 * @param array $headers The headers to include
 * @return array The decoded JSON response
 * @throws Exception on error
 */
private function tuyaCurlGet($url, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Curl error: " . $err);
    }
    curl_close($ch);

    $decoded = json_decode($res, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from Tuya: " . json_last_error_msg());
    }

    // Tuya can return errors with shape: [ 'code' => 1100, 'msg' => 'param is empty' ]
    if (is_array($decoded)) {
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            $msg = $decoded['msg'] ?? json_encode($decoded);
            $code = isset($decoded['code']) ? (int)$decoded['code'] : 0;
            throw new Exception("Tuya API error: " . $msg, $code);
        }
        if (array_key_exists('code', $decoded) && $decoded['code'] !== 0 && $decoded['code'] !== 'SUCCESS') {
            $msg = $decoded['msg'] ?? json_encode($decoded);
            $code = is_numeric($decoded['code']) ? (int)$decoded['code'] : 0;
            throw new Exception("Tuya API error: " . $msg, $code);
        }
    }

    return $decoded;
}


/**
 * Get device status
 * 
 * https://developer.tuya.com/en/docs/cloud/e2512fb901?id=Kag2yag3tiqn5
 * @param string $device_id The ID of the device
 * @return array The device status
 * @throws Exception on error
 */
public function getDeviceStatus(string $device_id): array {

    $path = "/v1.0/devices/{$device_id}/status";
    return $this->pathRequester($path);
}


/**
 * Set device status by sending commands
 * 
 * https://developer.tuya.com/en/docs/cloud/e2512fb901?id=Kag2yag3tiqn5
 * @param string $device_id The ID of the device
 * @param array $commands Array of command arrays, each with 'code' and 'value'
 * @return array The API response
 * @throws Exception on error
 */
public function setDeviceStatus(string $device_id, array $commands): array {

   $path = "/v1.0/iot-03/devices/{$device_id}/commands";
    
    // Prepara il body della richiesta
    $body = json_encode([
        "commands" => $commands
    ]);

    $signData = $this->tuyaSign($this->client_id, $this->client_secret, $this->access_token, "POST", $path, '', $body);

    $url = "https://openapi.tuya{$this->region}.com{$path}";
    $headers = [
        "client_id: {$this->client_id}",
        "access_token: {$this->access_token}",
        "sign: {$signData['sign']}",
        "sign_method: HMAC-SHA256",
        "t: {$signData['t']}",
        "nonce: {$signData['nonce']}",
        "Content-Type: application/json"
    ];

    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $res = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("Curl error: " . $err);
        }
        curl_close($ch);

        $response = json_decode($res, true);
        if ($response === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Tuya: " . json_last_error_msg());
        }

        if (isset($response['result'])) {
            return $response;
        }

        // If API returned an error array like ['code'=>1100,'msg'=>'param is empty']
        if (is_array($response) && isset($response['code'])) {
            $msg = $response['msg'] ?? json_encode($response);
            $code = is_numeric($response['code']) ? (int)$response['code'] : 0;
            throw new Exception("Errore invio comando: " . $msg, $code);
        }

        throw new Exception("Errore invio comando: " . json_encode($response));
    } catch (Exception $e) {
        throw new Exception("setDeviceStatus failed: " . $e->getMessage(), $e->getCode());
    }
}


    /**
     * Return true if device is online. 
     * Checks the device info endpoint for the 'online' flag, or falls back to status.
     * 
     * @param string $device_id The ID of the device
     * @return bool True if device is online, false otherwise  
     * @throws Exception on error
     */
    public function isDeviceOnline(string $device_id_or_name): bool {
        $devices = $this->getAllDevices();

        foreach ($devices as $device) {
            if ((isset($device['id']) && $device['id'] === $device_id_or_name) ||
                (isset($device['name']) && $device['name'] === $device_id_or_name) ||
                (isset($device['customName']) && $device['customName'] === $device_id_or_name)) {

                if (isset($device['isOnline'])) return (bool)$device['isOnline'];
                if (isset($device['online'])) return (bool)$device['online'];

                return false;
            }
        }

        throw new Exception("Device {$device_id_or_name} non trovato.");
    }

    public function getDeviceInfo(string $device_id): array {
        $path = "/v1.0/devices/{$device_id}";
        return $this->pathRequester($path);
    }

}
