<?php

declare(strict_types=1);

/**
 * Tuya Cloud Sharing API (Home Assistant QR flow) — Einrichtung, Local Key und Cloud-Status.
 */
final class TuyaCloudSharing
{
    public const CLIENT_ID = 'HA_3y9q4ak7g4ephrvke';
    public const SCHEMA = 'haauthorize';
    public const QR_LOGIN_PREFIX = 'tuyaSmart--qrLogin?token=';
    private const LOGIN_HOST = 'apigw.iotbing.com';
    private const HTTP_TIMEOUT_SEC = 20;

    /**
     * @return array{ok: bool, token: string, error: string}
     */
    public function requestQrToken(string $userCode): array
    {
        $userCode = trim($userCode);
        if ($userCode === '') {
            return ['ok' => false, 'token' => '', 'error' => 'User Code fehlt'];
        }

        $url = sprintf(
            'https://%s/v1.0/m/life/home-assistant/qrcode/tokens?clientid=%s&usercode=%s&schema=%s',
            self::LOGIN_HOST,
            rawurlencode(self::CLIENT_ID),
            rawurlencode($userCode),
            rawurlencode(self::SCHEMA),
        );

        $response = $this->httpRequest('POST', $url);
        if ($response === null) {
            return ['ok' => false, 'token' => '', 'error' => 'QR-Anfrage fehlgeschlagen (Netzwerk)'];
        }

        if (!($response['success'] ?? false)) {
            return [
                'ok' => false,
                'token' => '',
                'error' => (string) ($response['msg'] ?? $response['errorMsg'] ?? 'QR-Anfrage abgelehnt'),
            ];
        }

        $token = (string) ($response['result']['qrcode'] ?? $response['result']['qrCode'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'token' => '', 'error' => 'Kein QR-Token in Antwort'];
        }

        return ['ok' => true, 'token' => $token, 'error' => ''];
    }

    /**
     * @return array{ok: bool, session: array<string, mixed>, error: string}
     */
    public function pollLoginResult(string $qrToken, string $userCode): array
    {
        $qrToken = trim($qrToken);
        $userCode = trim($userCode);
        if ($qrToken === '' || $userCode === '') {
            return ['ok' => false, 'session' => [], 'error' => 'QR-Token oder User Code fehlt'];
        }

        $url = sprintf(
            'https://%s/v1.0/m/life/home-assistant/qrcode/tokens/%s?clientid=%s&usercode=%s',
            self::LOGIN_HOST,
            rawurlencode($qrToken),
            rawurlencode(self::CLIENT_ID),
            rawurlencode($userCode),
        );

        $response = $this->httpRequest('GET', $url);
        if ($response === null) {
            return ['ok' => false, 'session' => [], 'error' => 'Login-Abfrage fehlgeschlagen'];
        }

        if (!($response['success'] ?? false)) {
            return [
                'ok' => false,
                'session' => [],
                'error' => (string) ($response['msg'] ?? $response['errorMsg'] ?? 'Noch nicht angemeldet — QR in Tuya Smart scannen'),
            ];
        }

        $result = $response['result'] ?? [];
        if (!is_array($result)) {
            return ['ok' => false, 'session' => [], 'error' => 'Ungültige Login-Antwort'];
        }

        $result['t'] = $response['t'] ?? (int) (microtime(true) * 1000);
        $session = [
            'user_code' => $userCode,
            'terminal_id' => (string) ($result['terminal_id'] ?? $result['terminalId'] ?? ''),
            'endpoint' => (string) ($result['endpoint'] ?? 'https://apigw.tuyaeu.com'),
            'token_info' => [
                't' => $result['t'],
                'uid' => (string) ($result['uid'] ?? ''),
                'expire_time' => (int) ($result['expire_time'] ?? $result['expireTime'] ?? 0),
                'access_token' => (string) ($result['access_token'] ?? $result['accessToken'] ?? ''),
                'refresh_token' => (string) ($result['refresh_token'] ?? $result['refreshToken'] ?? ''),
            ],
        ];

        if ($session['token_info']['access_token'] === '') {
            return ['ok' => false, 'session' => [], 'error' => 'Access Token fehlt in Login-Antwort'];
        }

        return ['ok' => true, 'session' => $session, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $session
     * @return array{ok: bool, devices: list<array<string, mixed>>, error: string}
     */
    public function fetchDevices(array $session): array
    {
        $api = $this->createCustomerApi($session);
        if ($api === null) {
            return ['ok' => false, 'devices' => [], 'error' => 'Cloud-Session unvollständig'];
        }

        $homesResponse = $api->get('/v1.0/m/life/users/homes');
        if ($homesResponse === null || !($homesResponse['success'] ?? false)) {
            return ['ok' => false, 'devices' => [], 'error' => 'Homes konnten nicht geladen werden'];
        }

        $devices = [];
        foreach (self::normalizeResultList($homesResponse['result'] ?? null) as $home) {
            if (!is_array($home)) {
                continue;
            }
            $homeId = (string) ($home['ownerId'] ?? $home['homeId'] ?? $home['id'] ?? '');
            if ($homeId === '') {
                continue;
            }

            $devResponse = $api->get('/v1.0/m/life/ha/home/devices', ['homeId' => $homeId]);
            if ($devResponse === null || !($devResponse['success'] ?? false)) {
                continue;
            }

            foreach (self::normalizeResultList($devResponse['result'] ?? null) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $localKey = (string) ($item['local_key'] ?? $item['localKey'] ?? '');
                $id = (string) ($item['id'] ?? $item['devId'] ?? '');
                if ($id === '' || $localKey === '') {
                    continue;
                }

                $devices[] = [
                    'id' => $id,
                    'name' => (string) ($item['name'] ?? $item['product_name'] ?? $id),
                    'local_key' => $localKey,
                    'ip' => (string) ($item['ip'] ?? ''),
                    'product_id' => (string) ($item['product_id'] ?? $item['productId'] ?? ''),
                    'category' => (string) ($item['category'] ?? ''),
                    'online' => (bool) ($item['online'] ?? false),
                    'status' => $item['status'] ?? [],
                ];
            }
        }

        if ($devices === []) {
            $resultType = gettype($homesResponse['result'] ?? null);

            return [
                'ok' => false,
                'devices' => [],
                'error' => 'Keine Geräte mit Local Key gefunden (API result: ' . $resultType . ')',
            ];
        }

        return ['ok' => true, 'devices' => $devices, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $session
     * @return array{ok: bool, dps: array<string|int, mixed>, online: bool, error: string}
     */
    public function fetchDeviceStatus(array &$session, string $deviceId): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return ['ok' => false, 'dps' => [], 'online' => false, 'error' => 'Device ID fehlt'];
        }

        $api = $this->createCustomerApi($session);
        if ($api === null) {
            return ['ok' => false, 'dps' => [], 'online' => false, 'error' => 'Cloud-Session unvollständig'];
        }

        $response = $api->get('/v1.0/m/life/ha/devices/detail', ['devIds' => $deviceId]);
        $session['token_info'] = $api->getTokenInfo();
        if ($response === null || !($response['success'] ?? false)) {
            $msg = is_array($response) ? (string) ($response['msg'] ?? $response['errorMsg'] ?? 'Cloud-Abfrage fehlgeschlagen') : 'Cloud-Abfrage fehlgeschlagen';

            return $this->fetchDeviceStatusViaHomes($api, $session, $deviceId, $msg);
        }

        $items = self::normalizeResultList($response['result'] ?? null);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? $item['devId'] ?? '');
            if ($id !== $deviceId) {
                continue;
            }

            $dps = self::statusListToDps($item['status'] ?? []);
            if ($dps === []) {
                return [
                    'ok' => false,
                    'dps' => [],
                    'online' => (bool) ($item['online'] ?? false),
                    'error' => 'Cloud-Antwort ohne Status-Daten',
                ];
            }

            return [
                'ok' => true,
                'dps' => $dps,
                'online' => (bool) ($item['online'] ?? false),
                'error' => '',
            ];
        }

        return ['ok' => false, 'dps' => [], 'online' => false, 'error' => 'Gerät nicht in Cloud-Antwort gefunden'];
    }

    /**
     * @return array{ok: bool, dps: array<string|int, mixed>, online: bool, error: string}
     */
    private function fetchDeviceStatusViaHomes(
        TuyaCloudCustomerApi $api,
        array &$session,
        string $deviceId,
        string $previousError,
    ): array {
        $homesResponse = $api->get('/v1.0/m/life/users/homes');
        $session['token_info'] = $api->getTokenInfo();
        if ($homesResponse === null || !($homesResponse['success'] ?? false)) {
            return ['ok' => false, 'dps' => [], 'online' => false, 'error' => $previousError];
        }

        foreach (self::normalizeResultList($homesResponse['result'] ?? null) as $home) {
            if (!is_array($home)) {
                continue;
            }
            $homeId = (string) ($home['ownerId'] ?? $home['homeId'] ?? $home['id'] ?? '');
            if ($homeId === '') {
                continue;
            }

            $devResponse = $api->get('/v1.0/m/life/ha/home/devices', ['homeId' => $homeId]);
            $session['token_info'] = $api->getTokenInfo();
            if ($devResponse === null || !($devResponse['success'] ?? false)) {
                continue;
            }

            foreach (self::normalizeResultList($devResponse['result'] ?? null) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (string) ($item['id'] ?? $item['devId'] ?? '');
                if ($id !== $deviceId) {
                    continue;
                }

                $dps = self::statusListToDps($item['status'] ?? []);
                if ($dps === []) {
                    return [
                        'ok' => false,
                        'dps' => [],
                        'online' => (bool) ($item['online'] ?? false),
                        'error' => $previousError . ' (Homes-Liste ohne Status)',
                    ];
                }

                return [
                    'ok' => true,
                    'dps' => $dps,
                    'online' => (bool) ($item['online'] ?? false),
                    'error' => '',
                ];
            }
        }

        return ['ok' => false, 'dps' => [], 'online' => false, 'error' => $previousError];
    }

    /**
     * @param mixed $status
     * @return array<string|int, mixed>
     */
    public static function statusListToDps(mixed $status): array
    {
        $dps = [];

        if (!is_array($status)) {
            return $dps;
        }

        if ($status !== [] && !self::isListArray($status)) {
            foreach ($status as $key => $value) {
                if (is_numeric($key)) {
                    $dps[(string) $key] = $value;
                }
            }

            return $dps;
        }

        foreach ($status as $item) {
            if (!is_array($item)) {
                continue;
            }

            $value = $item['value'] ?? null;
            if ($value === null) {
                continue;
            }

            $dpId = $item['dpId'] ?? $item['dp_id'] ?? null;
            if ($dpId !== null && is_numeric($dpId)) {
                $dps[(string) $dpId] = $value;
                continue;
            }

            $code = (string) ($item['code'] ?? '');
            if ($code !== '' && ctype_digit($code)) {
                $dps[$code] = $value;
            }
        }

        return $dps;
    }

    /**
     * @return list<mixed>
     */
    private static function normalizeResultList(mixed $result): array
    {
        if (is_array($result)) {
            if ($result !== [] && !self::isListArray($result)) {
                foreach (['list', 'devices', 'homes', 'result'] as $key) {
                    if (isset($result[$key]) && is_array($result[$key])) {
                        return array_values($result[$key]);
                    }
                }
            }

            return array_values($result);
        }

        if (is_string($result) && $result !== '') {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                return self::normalizeResultList($decoded);
            }
        }

        return [];
    }

    private static function isListArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function createCustomerApi(array $session): ?TuyaCloudCustomerApi
    {
        $tokenInfo = $session['token_info'] ?? null;
        if (!is_array($tokenInfo)) {
            return null;
        }

        $endpoint = trim((string) ($session['endpoint'] ?? ''));
        $userCode = trim((string) ($session['user_code'] ?? ''));
        if ($endpoint === '' || $userCode === '') {
            return null;
        }

        return new TuyaCloudCustomerApi(
            self::CLIENT_ID,
            $userCode,
            $endpoint,
            $tokenInfo,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpRequest(string $method, string $url, ?array $body = null, ?array $headers = null): ?array
    {
        if (function_exists('curl_init')) {
            return $this->httpRequestCurl($method, $url, $body, $headers);
        }

        $headerLines = ['Content-Type: application/json'];
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                $headerLines[] = $key . ': ' . $value;
            }
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => self::HTTP_TIMEOUT_SEC,
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null && $method !== 'GET') {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
            $opts['http']['content'] = is_string($encoded) ? $encoded : '{}';
        }

        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpRequestCurl(string $method, string $url, ?array $body, ?array $headers): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headerList = ['Content-Type: application/json'];
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                $headerList[] = $key . ': ' . $value;
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SEC,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerList,
        ]);

        if ($body !== null && $method !== 'GET') {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($encoded) ? $encoded : '{}');
        }

        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}

/**
 * Verschlüsselte Tuya Sharing API (AES-GCM + HMAC).
 */
final class TuyaCloudCustomerApi
{
    /** @var string */
    private $clientId;
    /** @var string */
    private $userCode;
    /** @var string */
    private $endpoint;
    /** @var array<string, mixed> */
    private $tokenInfo;
    /** @var bool */
    private $refreshing = false;

    /**
     * @param array<string, mixed> $tokenInfo
     */
    public function __construct(
        string $clientId,
        string $userCode,
        string $endpoint,
        array $tokenInfo
    ) {
        $this->clientId = $clientId;
        $this->userCode = $userCode;
        $this->endpoint = $endpoint;
        $this->tokenInfo = $tokenInfo;
    }

    /**
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>|null
     */
    public function get(string $path, ?array $params = null): ?array
    {
        return $this->request('GET', $path, $params, null);
    }

    /**
     * @param array<string, mixed>|null $params
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, ?array $params, ?array $body): ?array
    {
        $this->refreshAccessTokenIfNeeded();

        $requestId = $this->uuidV4();
        $sid = '';
        $hashKey = md5($requestId . (string) ($this->tokenInfo['refresh_token'] ?? ''));
        $secret = $this->secretGenerating($requestId, $sid, $hashKey);

        $queryEnc = '';
        $queryParams = [];
        if ($params !== null && $params !== []) {
            $queryEnc = $this->aesGcmEncrypt($this->formToJson($params), $secret);
            $queryParams['encdata'] = $queryEnc;
        }

        $bodyEnc = '';
        $jsonBody = null;
        if ($body !== null && $body !== []) {
            $bodyEnc = $this->aesGcmEncrypt($this->formToJson($body), $secret);
            $jsonBody = ['encdata' => $bodyEnc];
        }

        $timeMs = (string) (int) (microtime(true) * 1000);
        $headers = [
            'X-appKey' => $this->clientId,
            'X-requestId' => $requestId,
            'X-sid' => $sid,
            'X-time' => $timeMs,
        ];

        $accessToken = (string) ($this->tokenInfo['access_token'] ?? '');
        if ($accessToken !== '') {
            $headers['X-token'] = $accessToken;
        }

        $headers['X-sign'] = $this->restfulSign($hashKey, $queryEnc, $bodyEnc, $headers);

        $url = rtrim($this->endpoint, '/') . $path;
        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        $raw = $this->httpRaw($method, $url, $jsonBody, $headers);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!($decoded['success'] ?? false)) {
            return $decoded;
        }

        if (isset($decoded['result']) && is_string($decoded['result']) && $decoded['result'] !== '') {
            $plain = $this->aesGcmDecrypt($decoded['result'], $secret);
            if ($plain === null) {
                $plain = $this->aesGcmDecryptConcatBase64($decoded['result'], $secret);
            }
            if ($plain !== null) {
                $parsed = json_decode($plain, true);
                $decoded['result'] = is_array($parsed) ? $parsed : $plain;
            }
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTokenInfo(): array
    {
        return $this->tokenInfo;
    }

    private function refreshAccessTokenIfNeeded(): void
    {
        if ($this->refreshing) {
            return;
        }

        $t = (int) ($this->tokenInfo['t'] ?? 0);
        $expireSec = (int) ($this->tokenInfo['expire_time'] ?? 0);
        $expireMs = $t + ($expireSec * 1000);
        $nowMs = (int) (microtime(true) * 1000);
        if ($expireMs - 60000 > $nowMs) {
            return;
        }

        $refresh = (string) ($this->tokenInfo['refresh_token'] ?? '');
        if ($refresh === '') {
            return;
        }

        $this->refreshing = true;
        try {
            $response = $this->request('GET', '/v1.0/m/token/' . $refresh, null, null);
            if ($response === null || !($response['success'] ?? false)) {
                return;
            }

            $result = $response['result'] ?? [];
            if (!is_array($result)) {
                return;
            }

            $this->tokenInfo = [
                't' => (int) ($response['t'] ?? $nowMs),
                'expire_time' => (int) ($result['expireTime'] ?? $result['expire_time'] ?? 0),
                'uid' => (string) ($result['uid'] ?? $this->tokenInfo['uid'] ?? ''),
                'access_token' => (string) ($result['accessToken'] ?? $result['access_token'] ?? ''),
                'refresh_token' => (string) ($result['refreshToken'] ?? $result['refresh_token'] ?? $refresh),
            ];
        } finally {
            $this->refreshing = false;
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function httpRaw(string $method, string $url, ?array $body, array $headers): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            $headerList = [];
            foreach ($headers as $key => $value) {
                $headerList[] = $key . ': ' . $value;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerList,
            ]);

            if ($body !== null) {
                $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($encoded) ? $encoded : '{}');
            }

            $raw = curl_exec($ch);
            curl_close($ch);

            return is_string($raw) ? $raw : null;
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
            $opts['http']['content'] = is_string($encoded) ? $encoded : '{}';
        }

        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);

        return is_string($raw) ? $raw : null;
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function randomNonce(int $length = 12): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTWXYZabcdefhijkmnprstwxyz2345678';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /** @param array<string, mixed> $content */
    private function formToJson(array $content): string
    {
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function aesGcmEncrypt(string $rawData, string $secret): string
    {
        $nonce = $this->randomNonce(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $rawData,
            'aes-128-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );

        if ($cipher === false) {
            return '';
        }

        return base64_encode($nonce) . base64_encode($cipher . $tag);
    }

    private function aesGcmDecrypt(string $cipherData, string $secret): ?string
    {
        $raw = base64_decode($cipherData, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $cipherText = substr($raw, 12, -16);

        $plain = openssl_decrypt(
            $cipherText,
            'aes-128-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        return is_string($plain) ? $plain : null;
    }

    /** Fallback: base64(nonce) + base64(ciphertext) wie Request-Format. */
    private function aesGcmDecryptConcatBase64(string $cipherData, string $secret): ?string
    {
        $nonceB64Len = 16;
        if (strlen($cipherData) <= $nonceB64Len) {
            return null;
        }

        $nonce = base64_decode(substr($cipherData, 0, $nonceB64Len), true);
        $cipherB64 = substr($cipherData, $nonceB64Len);
        if ($nonce === false || strlen($nonce) !== 12 || $cipherB64 === '') {
            return null;
        }

        $raw = base64_decode($cipherB64, true);
        if ($raw === false || strlen($raw) < 16) {
            return null;
        }

        $tag = substr($raw, -16);
        $cipherText = substr($raw, 0, -16);

        $plain = openssl_decrypt(
            $cipherText,
            'aes-128-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        return is_string($plain) ? $plain : null;
    }

    private function secretGenerating(string $rid, string $sid, string $hashKey): string
    {
        $message = $hashKey;
        if ($sid !== '') {
            $mod = 16;
            $length = min(strlen($sid), $mod);
            $ecode = '';
            for ($i = 0; $i < $length; $i++) {
                $idx = ord($sid[$i]) % $mod;
                $ecode .= $sid[$idx];
            }
            $message .= '_' . $ecode;
        }

        $digest = hash_hmac('sha256', $message, $rid, true);

        return substr(bin2hex($digest), 0, 16);
    }

    /** @param array<string, string> $headers */
    private function restfulSign(string $hashKey, string $queryEnc, string $bodyEnc, array $headers): string
    {
        $order = ['X-appKey', 'X-requestId', 'X-sid', 'X-time', 'X-token'];
        $signStr = '';
        foreach ($order as $item) {
            $val = $headers[$item] ?? '';
            if ($val !== '') {
                $signStr .= $item . '=' . $val . '||';
            }
        }
        if (strlen($signStr) >= 2 && substr($signStr, -2) === '||') {
            $signStr = substr($signStr, 0, -2);
        }

        $signStr .= $queryEnc . $bodyEnc;

        return hash_hmac('sha256', $signStr, $hashKey);
    }
}
