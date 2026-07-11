<?php

declare(strict_types=1);

/**
 * Tuya IoT OpenAPI — voller Gerätestatus (pH/ORP/EC/TDS/Temp) via Developer-Cloud.
 * Erfordert eigenes Projekt auf iot.tuya.com (Access ID/Secret, App verknüpft).
 */
final class TuyaOpenApiClient
{
    private const HTTP_TIMEOUT_SEC = 20;

    /** @var array<string, string> */
    private const REGION_HOSTS = [
        'eu' => 'openapi.tuyaeu.com',
        'us' => 'openapi.tuyaus.com',
        'cn' => 'openapi.tuyacn.com',
        'in' => 'openapi.tuyain.com',
    ];

    private string $accessId;
    private string $accessSecret;
    private string $host;
    /** @var array<string, mixed> */
    private array $tokenInfo;

    /**
     * @param array<string, mixed>|null $tokenInfo
     */
    public function __construct(string $accessId, string $accessSecret, string $region, ?array $tokenInfo = null)
    {
        $this->accessId = trim($accessId);
        $this->accessSecret = trim($accessSecret);
        $region = strtolower(trim($region));
        $this->host = self::REGION_HOSTS[$region] ?? self::REGION_HOSTS['eu'];
        $this->tokenInfo = is_array($tokenInfo) ? $tokenInfo : [];
    }

    public static function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));
        if (isset(self::REGION_HOSTS[$region])) {
            return $region;
        }

        return 'eu';
    }

    /**
     * @return array{ok: bool, dps: array<string|int, mixed>, online: bool, error: string, status_range: array<string, mixed>, cloud_codes: list<string>}
     */
    public function fetchDeviceStatus(string $deviceId): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return $this->emptyResult('Device ID fehlt');
        }

        if ($this->accessId === '' || $this->accessSecret === '') {
            return $this->emptyResult('IoT Access ID oder Secret fehlt');
        }

        $response = $this->request('GET', 'v1.0/iot-03/devices/' . rawurlencode($deviceId) . '/status');
        if ($response === null) {
            return $this->emptyResult('IoT-Cloud Netzwerkfehler');
        }

        if (!($response['success'] ?? false)) {
            $msg = (string) ($response['msg'] ?? $response['errorMsg'] ?? 'IoT-Status-Abfrage fehlgeschlagen');

            return $this->emptyResult($msg);
        }

        $dps = TuyaCloudSharing::statusListToDps($response['result'] ?? []);
        if ($dps === []) {
            return $this->emptyResult('IoT-Cloud lieferte keinen Status — ggf. DP-Instruction-Modus auf iot.tuya.com aktivieren');
        }

        $specs = $this->fetchSpecifications($deviceId);

        return [
            'ok' => true,
            'dps' => $dps,
            'online' => true,
            'error' => '',
            'status_range' => $specs['status_range'],
            'cloud_codes' => $specs['codes'],
        ];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function testConnection(): array
    {
        if ($this->accessId === '' || $this->accessSecret === '') {
            return ['ok' => false, 'error' => 'Access ID oder Secret fehlt'];
        }

        $this->refreshTokenIfNeeded(true);
        if (($this->tokenInfo['access_token'] ?? '') === '') {
            return ['ok' => false, 'error' => 'Token konnte nicht bezogen werden — Access ID/Secret/Region prüfen'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTokenInfo(): array
    {
        return $this->tokenInfo;
    }

    /**
     * @return array{status_range: array<string, mixed>, codes: list<string>}
     */
    private function fetchSpecifications(string $deviceId): array
    {
        $response = $this->request('GET', 'v1.1/devices/' . rawurlencode($deviceId) . '/specifications');
        if ($response === null || !($response['success'] ?? false)) {
            return ['status_range' => [], 'codes' => []];
        }

        $result = $response['result'] ?? [];
        if (!is_array($result)) {
            return ['status_range' => [], 'codes' => []];
        }

        $statusRange = [];
        $codes = [];
        foreach ($result['status'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string) ($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $codes[] = $code;
            $statusRange[$code] = $item;
        }

        return ['status_range' => $statusRange, 'codes' => $codes];
    }

    /**
     * @param array<string, string>|null $query
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, ?array $query = null, ?array $body = null): ?array
    {
        $this->refreshTokenIfNeeded();

        $path = ltrim($path, '/');
        $signPath = '/' . $path;
        $url = 'https://' . $this->host . $signPath;

        if ($query !== null && $query !== []) {
            ksort($query);
            $queryString = http_build_query($query);
            $signPath .= '?' . $queryString;
            $url .= '?' . $queryString;
        }

        $bodyJson = null;
        if ($body !== null && $body !== []) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
            $bodyJson = is_string($encoded) ? $encoded : '{}';
        }

        $timestamp = (string) (int) (microtime(true) * 1000);
        $accessToken = (string) ($this->tokenInfo['access_token'] ?? '');
        $sign = $this->sign($method, $signPath, $timestamp, $bodyJson ?? '', $accessToken !== '');

        $headers = [
            'client_id' => $this->accessId,
            'sign' => $sign,
            't' => $timestamp,
            'sign_method' => 'HMAC-SHA256',
        ];
        if ($accessToken !== '') {
            $headers['access_token'] = $accessToken;
        }

        $raw = $this->httpRaw($method, $url, $bodyJson, $headers);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!$this->isTokenInvalidResponse($decoded)) {
            return $decoded;
        }

        $this->tokenInfo = [];
        $this->refreshTokenIfNeeded(true);
        $accessToken = (string) ($this->tokenInfo['access_token'] ?? '');
        if ($accessToken === '') {
            return $decoded;
        }

        $sign = $this->sign($method, $signPath, $timestamp, $bodyJson ?? '', true);
        $headers['sign'] = $sign;
        $headers['access_token'] = $accessToken;

        $raw = $this->httpRaw($method, $url, $bodyJson, $headers);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function refreshTokenIfNeeded(bool $force = false): void
    {
        if (!$force) {
            $expireMs = (int) ($this->tokenInfo['expire_ms'] ?? 0);
            $nowMs = (int) (microtime(true) * 1000);
            if (($this->tokenInfo['access_token'] ?? '') !== '' && $expireMs - 120000 > $nowMs) {
                return;
            }
        }

        $timestamp = (string) (int) (microtime(true) * 1000);
        $signPath = '/v1.0/token?grant_type=1';
        $sign = $this->sign('GET', $signPath, $timestamp, '', false);
        $url = 'https://' . $this->host . $signPath;

        $raw = $this->httpRaw('GET', $url, null, [
            'client_id' => $this->accessId,
            'sign' => $sign,
            't' => $timestamp,
            'sign_method' => 'HMAC-SHA256',
        ]);
        if (!is_string($raw) || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !($decoded['success'] ?? false)) {
            return;
        }

        $result = $decoded['result'] ?? [];
        if (!is_array($result)) {
            return;
        }

        $t = (int) ($decoded['t'] ?? $timestamp);
        $expireSec = (int) ($result['expire_time'] ?? $result['expireTime'] ?? 7200);
        $this->tokenInfo = [
            't' => $t,
            'expire_ms' => $t + ($expireSec * 1000),
            'access_token' => (string) ($result['access_token'] ?? $result['accessToken'] ?? ''),
            'refresh_token' => (string) ($result['refresh_token'] ?? $result['refreshToken'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function isTokenInvalidResponse(array $response): bool
    {
        if (($response['success'] ?? false) === true) {
            return false;
        }

        $msg = strtolower((string) ($response['msg'] ?? $response['errorMsg'] ?? ''));
        $code = (string) ($response['code'] ?? '');

        return str_contains($msg, 'token') || $code === '1010';
    }

    private function sign(string $method, string $pathWithQuery, string $timestamp, string $body, bool $withToken): string
    {
        $payload = $this->accessId;
        if ($withToken) {
            $payload .= (string) ($this->tokenInfo['access_token'] ?? '');
        }
        $payload .= $timestamp;
        $payload .= $method . "\n";
        $payload .= hash('sha256', $body) . "\n";
        $payload .= "\n";
        $payload .= $pathWithQuery;

        return strtoupper(hash_hmac('sha256', $payload, $this->accessSecret));
    }

    /**
     * @param array<string, string> $headers
     */
    private function httpRaw(string $method, string $url, ?string $body, array $headers): ?string
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
            if ($body !== null) {
                $headerList[] = 'Content-Type: application/json';
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SEC,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerList,
            ]);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $raw = curl_exec($ch);
            curl_close($ch);

            return is_string($raw) ? $raw : null;
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }
        if ($body !== null) {
            $headerLines[] = 'Content-Type: application/json';
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => self::HTTP_TIMEOUT_SEC,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }

        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);

        return is_string($raw) ? $raw : null;
    }

    /**
     * @return array{ok: bool, dps: array<string|int, mixed>, online: bool, error: string, status_range: array<string, mixed>, cloud_codes: list<string>}
     */
    private function emptyResult(string $error): array
    {
        return [
            'ok' => false,
            'dps' => [],
            'online' => false,
            'error' => $error,
            'status_range' => [],
            'cloud_codes' => [],
        ];
    }
}
