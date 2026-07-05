<?php

declare(strict_types=1);

/**
 * Minimaler HTTP-Client für WifiWhirl REST-Endpunkte.
 */
final class WifiWhirlHttpClient
{
    private const CONNECT_TIMEOUT_SEC = 3.0;
    private const TRANSFER_TIMEOUT_SEC = 6.0;

    public function __construct(
        private readonly string $host,
        private readonly float $connectTimeoutSec = self::CONNECT_TIMEOUT_SEC,
        private readonly float $transferTimeoutSec = self::TRANSFER_TIMEOUT_SEC,
    ) {
    }

    public function getPollData(): ?array
    {
        $body = $this->request('GET', '/getpolldata/');
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->normalizePollPayload($decoded);
    }

    /** @return array{states: array<string, mixed>, times: array<string, mixed>, other: array<string, mixed>}|null */
    public function getFallbackData(): ?array
    {
        $statesBody = $this->request('GET', '/getstates/');
        $tempsBody = $this->request('GET', '/gettemps/');
        if ($statesBody === null && $tempsBody === null) {
            return null;
        }

        $states = [];
        if (is_string($statesBody) && $statesBody !== '') {
            $raw = json_decode($statesBody, true);
            if (is_array($raw)) {
                $states = [
                    'FLT' => $raw['pump'] ?? null,
                    'RED' => $raw['heater'] ?? null,
                    'GRN' => $raw['heater'] ?? null,
                    'AIR' => $raw['bubbles'] ?? null,
                    'HJT' => $raw['jets'] ?? null,
                    'PWR' => $raw['power'] ?? null,
                    'LCK' => $raw['lock'] ?? null,
                ];
            }
        }

        if (is_string($tempsBody) && $tempsBody !== '') {
            $raw = json_decode($tempsBody, true);
            if (is_array($raw)) {
                $states['TMPC'] = $raw['currentC'] ?? null;
                $states['TGTC'] = $raw['targetC'] ?? null;
                $states['AMBC'] = $raw['ambientC'] ?? null;
                $states['UNT'] = isset($raw['unit']) && strtoupper((string) $raw['unit']) === 'F' ? 1 : 0;
            }
        }

        return [
            'states' => $states,
            'times' => [],
            'other' => [],
        ];
    }

    /** @param array<string, mixed> $payload */
    public function sendCommand(array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $response = $this->request('POST', '/sendcommand/', $json, 'application/json');

        return $response !== null;
    }

    /**
     * @param array<int|string, mixed> $decoded
     * @return array{states: array<string, mixed>, times: array<string, mixed>, other: array<string, mixed>}|null
     */
    private function normalizePollPayload(array $decoded): ?array
    {
        if ($decoded === []) {
            return null;
        }

        if (array_is_list($decoded) && count($decoded) >= 3) {
            $states = is_array($decoded[0]) ? $decoded[0] : [];
            $times = is_array($decoded[1]) ? $decoded[1] : [];
            $other = is_array($decoded[2]) ? $decoded[2] : [];

            return [
                'states' => $states,
                'times' => $times,
                'other' => $other,
            ];
        }

        if (isset($decoded['states']) || isset($decoded['times']) || isset($decoded['other'])) {
            return [
                'states' => is_array($decoded['states'] ?? null) ? $decoded['states'] : [],
                'times' => is_array($decoded['times'] ?? null) ? $decoded['times'] : [],
                'other' => is_array($decoded['other'] ?? null) ? $decoded['other'] : [],
            ];
        }

        return [
            'states' => $decoded,
            'times' => [],
            'other' => [],
        ];
    }

    private function request(string $method, string $path, ?string $body = null, ?string $contentType = null): ?string
    {
        $host = trim($this->host);
        if ($host === '') {
            return null;
        }

        $url = 'http://' . $host . $path;

        if (!function_exists('curl_init')) {
            return $this->requestViaFileGetContents($method, $url, $body, $contentType);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = [];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int) ceil($this->connectTimeoutSec),
            CURLOPT_TIMEOUT => (int) ceil($this->transferTimeoutSec),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        return (string) $response;
    }

    private function requestViaFileGetContents(string $method, string $url, ?string $body, ?string $contentType): ?string
    {
        $headerLines = [];
        if ($contentType !== null) {
            $headerLines[] = 'Content-Type: ' . $contentType;
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => (int) ceil($this->transferTimeoutSec),
                'ignore_errors' => true,
            ],
        ];

        if ($method === 'POST' && $body !== null) {
            $opts['http']['content'] = $body;
        }

        $ctx = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        return (string) $response;
    }
}
