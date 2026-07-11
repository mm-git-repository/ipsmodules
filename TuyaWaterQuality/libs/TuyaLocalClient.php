<?php

declare(strict_types=1);

/**
 * Minimaler Tuya-Lokalprotokoll-Client (3.3/3.4/3.5) für Yieryi & ähnliche Sensoren.
 *
 * Paketformat 0x55AA: Prefix, Seq, Cmd, Length, Payload, CRC32, Suffix 0xAA55
 */
final class TuyaLocalClient
{
    private const PREFIX_SEND = 0x000055AA;
    private const PREFIX_RECV = 0x000055AA;
    private const PREFIX_RECV_ALT = 0x000066AA;
    private const SUFFIX = 0x0000AA55;
    private const CMD_STATUS = 0x0000000A;
    private const CMD_CONTROL_NEW = 0x0000000D;
    private const CMD_DP_QUERY_NEW = 0x00000010;
    private const DEFAULT_PORT = 6668;
    private const SOCKET_TIMEOUT_SEC = 5;
    /** @var string 3.3 + 12 NUL (tinytuya PROTOCOL_33_HEADER) */
    private const PROTOCOL_HEADER_33 = "3.3\0\0\0\0\0\0\0\0\0\0\0\0";

    /** @var list<int> */
    private static $crcTable = [];

    private string $deviceId;
    private string $localKey;
    private string $protocolVersion;
    private int $seqNo = 0;

    /** @var list<int> */
    private $dpsQueryKeys = [];

    /** @var null|callable(string): void */
    private $debugLogger;

    /**
     * @param list<int> $dpsQueryKeys
     */
    public function __construct(
        string $deviceId,
        string $localKey,
        string $protocolVersion = '3.3',
        ?callable $debugLogger = null,
        array $dpsQueryKeys = []
    ) {
        $this->deviceId = trim($deviceId);
        $this->localKey = trim($localKey);
        $this->protocolVersion = trim($protocolVersion) !== '' ? trim($protocolVersion) : '3.3';
        $this->debugLogger = $debugLogger;
        $this->dpsQueryKeys = $dpsQueryKeys;
    }

    private function isDevice22(): bool
    {
        return strlen($this->deviceId) === 22;
    }

    /**
     * @return array{ok: bool, dps: array<string|int, mixed>, error: string}
     */
    public function fetchStatus(string $host): array
    {
        if ($this->deviceId === '' || $this->localKey === '') {
            return ['ok' => false, 'dps' => [], 'error' => 'Device ID oder Local Key fehlt'];
        }

        $host = trim($host);
        if ($host === '') {
            return ['ok' => false, 'dps' => [], 'error' => 'Host fehlt'];
        }

        if (!function_exists('socket_create')) {
            return ['ok' => false, 'dps' => [], 'error' => 'PHP socket extension fehlt'];
        }

        $this->log(sprintf(
            'Start host=%s:%d devId=%s proto=%s keyLen=%d device22=%s',
            $host,
            self::DEFAULT_PORT,
            $this->deviceId,
            $this->protocolVersion,
            strlen($this->localKey),
            $this->isDevice22() ? 'yes' : 'no',
        ));

        $attempts = $this->buildFetchAttempts();
        $lastError = 'Keine Antwort vom Gerät';

        foreach ($attempts as $attempt) {
            $result = $this->fetchStatusAttempt($host, $attempt);
            if ($result['ok']) {
                return $result;
            }
            $lastError = $result['error'];
        }

        return ['ok' => false, 'dps' => [], 'error' => $lastError];
    }

    /**
     * @param array{mode: string, command: int, json: string} $attempt
     * @return array{ok: bool, dps: array<string|int, mixed>, error: string}
     */
    private function fetchStatusAttempt(string $host, array $attempt): array
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return ['ok' => false, 'dps' => [], 'error' => 'Socket konnte nicht erstellt werden'];
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => self::SOCKET_TIMEOUT_SEC, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => self::SOCKET_TIMEOUT_SEC, 'usec' => 0]);

        $connected = @socket_connect($socket, $host, self::DEFAULT_PORT);
        if (!$connected) {
            $err = socket_last_error($socket);
            $errMsg = function_exists('socket_strerror') ? socket_strerror($err) : (string) $err;
            socket_close($socket);
            $this->log('Connect fehlgeschlagen: ' . $errMsg);

            return ['ok' => false, 'dps' => [], 'error' => 'Verbindung fehlgeschlagen (' . $errMsg . ')'];
        }

        $this->log('TCP verbunden');

        try {
            $this->seqNo = 0;
            $this->log(sprintf(
                'Versuch mode=%s cmd=0x%02X json=%s',
                $attempt['mode'],
                $attempt['command'],
                $attempt['json'],
            ));

            $encrypted = $this->encrypt($attempt['json'], $this->deriveKey());
            $wirePayload = $this->wrapWirePayload($encrypted, $attempt['command']);
            $packet = $this->packMessage($attempt['command'], $wirePayload);
            $this->log(sprintf(
                'Sende Paket seq=%d len=%d wireLen=%d encLen=%d',
                $this->seqNo,
                strlen($packet),
                strlen($wirePayload),
                strlen($encrypted),
            ));

            $written = @socket_write($socket, $packet, strlen($packet));
            if ($written === false || $written === 0) {
                $this->log('socket_write fehlgeschlagen');

                return ['ok' => false, 'dps' => [], 'error' => 'Senden fehlgeschlagen'];
            }

            $response = $this->readResponse($socket);
            if ($response === null) {
                $this->log('Keine/leere Antwort (Timeout nach ' . self::SOCKET_TIMEOUT_SEC . 's)');

                return ['ok' => false, 'dps' => [], 'error' => 'Keine Antwort vom Gerät (' . $attempt['mode'] . ')'];
            }

            $this->log(sprintf('Empfangen %d Bytes, hex=%s', strlen($response), $this->hexPreview($response)));

            $decoded = $this->decodeMessage($response);
            if (!$decoded['ok']) {
                $this->log('Decode: ' . $decoded['error']);

                return ['ok' => false, 'dps' => [], 'error' => $decoded['error']];
            }

            $this->log('Payload JSON: ' . $decoded['payload']);
            $dps = $this->extractDps($decoded['payload']);
            $this->log('DPS count=' . count($dps) . ' keys=' . implode(',', array_map('strval', array_keys($dps))));

            if ($dps === []) {
                return ['ok' => false, 'dps' => [], 'error' => 'Antwort ohne DPS-Daten'];
            }

            return ['ok' => true, 'dps' => $dps, 'error' => ''];
        } finally {
            socket_close($socket);
        }
    }

    /**
     * @return list<array{mode: string, command: int, json: string}>
     */
    private function buildFetchAttempts(): array
    {
        $attempts = [];

        if ($this->isDevice22()) {
            $attempts[] = [
                'mode' => 'device22',
                'command' => self::CMD_CONTROL_NEW,
                'json' => $this->buildDevice22Payload(),
            ];
        }

        if (in_array($this->protocolVersion, ['3.4', '3.5'], true)) {
            $attempts[] = [
                'mode' => 'v34',
                'command' => self::CMD_DP_QUERY_NEW,
                'json' => $this->buildModernPayload(),
            ];
        }

        $attempts[] = [
            'mode' => 'default33',
            'command' => self::CMD_STATUS,
            'json' => $this->buildLegacy33Payload(),
        ];

        return $attempts;
    }

    private function buildLegacy33Payload(): string
    {
        $json = [
            'gwId' => $this->deviceId,
            'devId' => $this->deviceId,
        ];

        return $this->encodeJson($json);
    }

    private function buildModernPayload(): string
    {
        $json = [
            'devId' => $this->deviceId,
            'uid' => '',
            't' => (string) time(),
        ];

        return $this->encodeJson($json);
    }

    private function buildDevice22Payload(): string
    {
        $dps = [];
        $keys = $this->dpsQueryKeys !== [] ? $this->dpsQueryKeys : [1, 2];
        foreach ($keys as $dp) {
            $dps[(string) $dp] = null;
        }

        $json = [
            'devId' => $this->deviceId,
            'uid' => '',
            't' => (string) time(),
            'dps' => $dps,
        ];

        return $this->encodeJson($json);
    }

    /** @param array<string, mixed> $json */
    private function encodeJson(array $json): string
    {
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return '{}';
        }

        return str_replace(' ', '', $encoded);
    }

    private function wrapWirePayload(string $encrypted, int $command): string
    {
        if ($this->needsProtocolHeader($command)) {
            return self::PROTOCOL_HEADER_33 . $encrypted;
        }

        return $encrypted;
    }

    private function needsProtocolHeader(int $command): bool
    {
        if (in_array($command, [self::CMD_STATUS, self::CMD_DP_QUERY_NEW], true)) {
            return false;
        }

        return in_array($this->protocolVersion, ['3.2', '3.3', '3.4', '3.5'], true);
    }

    private function log(string $message): void
    {
        if ($this->debugLogger !== null) {
            ($this->debugLogger)($message);
        }
    }

    private function hexPreview(string $data, int $maxBytes = 48): string
    {
        $slice = substr($data, 0, $maxBytes);
        $hex = strtoupper(bin2hex($slice));
        if (strlen($data) > $maxBytes) {
            $hex .= '…';
        }

        return $hex;
    }

    private function buildStatusPayload(): string
    {
        if ($this->isDevice22()) {
            return $this->buildDevice22Payload();
        }

        if (in_array($this->protocolVersion, ['3.4', '3.5'], true)) {
            return $this->buildModernPayload();
        }

        return $this->buildLegacy33Payload();
    }

    private function packMessage(int $command, string $wirePayload): string
    {
        $this->seqNo = ($this->seqNo + 1) % 0xFFFFFFFF;
        $payloadLen = strlen($wirePayload) + 8;

        $header = pack('N', self::PREFIX_SEND)
            . pack('N', $this->seqNo)
            . pack('N', $command)
            . pack('N', $payloadLen);

        $dataForCrc = $header . $wirePayload;
        $crc = self::crc32($dataForCrc);

        return $dataForCrc . pack('N', $crc) . pack('N', self::SUFFIX);
    }

    /**
     * @return array{ok: bool, payload: string, error: string}
     */
    private function decodeMessage(string $raw): array
    {
        if (strlen($raw) < 28) {
            return ['ok' => false, 'payload' => '', 'error' => 'Antwort zu kurz'];
        }

        $prefix = unpack('N', substr($raw, 0, 4))[1] ?? 0;
        if ($prefix !== self::PREFIX_RECV && $prefix !== self::PREFIX_RECV_ALT) {
            return ['ok' => false, 'payload' => '', 'error' => 'Ungültiges Antwort-Präfix'];
        }

        $payloadLength = unpack('N', substr($raw, 12, 4))[1] ?? 0;
        if ($payloadLength < 8) {
            return ['ok' => false, 'payload' => '', 'error' => 'Ungültige Payload-Länge'];
        }

        $bodyLength = $payloadLength - 8;
        $body = substr($raw, 16, $bodyLength);
        if ($body === false || strlen($body) !== $bodyLength) {
            return ['ok' => false, 'payload' => '', 'error' => 'Payload unvollständig'];
        }

        $encrypted = $body;
        if ($bodyLength >= 15 && substr($body, 0, 3) === '3.3') {
            $encrypted = substr($body, 15);
        } elseif ($bodyLength > 4 && $body[0] !== '{') {
            $encrypted = substr($body, 4);
        }

        $decrypted = $this->decrypt($encrypted, $this->deriveKey());
        if ($decrypted === null) {
            return ['ok' => false, 'payload' => '', 'error' => 'Entschlüsselung fehlgeschlagen (Local Key?)'];
        }

        return ['ok' => true, 'payload' => $decrypted, 'error' => ''];
    }

    /**
     * @return array<string|int, mixed>
     */
    private function extractDps(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['data']['dps']) && is_array($data['data']['dps'])) {
            return $data['data']['dps'];
        }

        if (isset($data['dps']) && is_array($data['dps'])) {
            return $data['dps'];
        }

        return [];
    }

    private function deriveKey(): string
    {
        $key = $this->localKey;
        if (strlen($key) === 16) {
            return $key;
        }

        return substr(md5($key, true), 0, 16);
    }

    private function encrypt(string $data, string $key): string
    {
        $padded = $this->pkcs7Pad($data, 16);

        return openssl_encrypt($padded, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING) ?: '';
    }

    private function decrypt(string $data, string $key): ?string
    {
        $decrypted = openssl_decrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        if (!is_string($decrypted)) {
            return null;
        }

        return $this->pkcs7Unpad($decrypted);
    }

    private function pkcs7Pad(string $data, int $blockSize): string
    {
        $pad = $blockSize - (strlen($data) % $blockSize);

        return $data . str_repeat(chr($pad), $pad);
    }

    private function pkcs7Unpad(string $data): ?string
    {
        $len = strlen($data);
        if ($len === 0) {
            return null;
        }

        $pad = ord($data[$len - 1]);
        if ($pad < 1 || $pad > 16) {
            return $data;
        }

        return substr($data, 0, $len - $pad);
    }

    private function readResponse($socket): ?string
    {
        $header = @socket_read($socket, 16, PHP_BINARY_READ);
        if ($header === false || strlen($header) < 16) {
            $this->log(sprintf(
                'Header unvollständig (%d Bytes)',
                is_string($header) ? strlen($header) : 0,
            ));

            return null;
        }

        $prefix = unpack('N', substr($header, 0, 4))[1] ?? 0;
        $payloadLength = unpack('N', substr($header, 12, 4))[1] ?? 0;
        $this->log(sprintf('Header prefix=0x%08X payloadLen=%d', $prefix, $payloadLength));

        $remaining = max(0, $payloadLength);
        $buffer = $header;
        $read = 0;

        while ($read < $remaining) {
            $chunk = @socket_read($socket, $remaining - $read, PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
            $read += strlen($chunk);
        }

        if ($read < $remaining) {
            $this->log(sprintf('Payload unvollständig: %d/%d Bytes', $read, $remaining));
        }

        $footer = @socket_read($socket, 8, PHP_BINARY_READ);
        if (is_string($footer) && $footer !== '') {
            $buffer .= $footer;
            $this->log('Footer: ' . $this->hexPreview($footer, 8));
        }

        if (strlen($buffer) <= 16) {
            return null;
        }

        return $buffer;
    }

    private static function crc32(string $data): int
    {
        if (self::$crcTable === []) {
            for ($i = 0; $i < 256; $i++) {
                $c = $i;
                for ($j = 0; $j < 8; $j++) {
                    if ($c & 1) {
                        $c = 0xEDB88320 ^ ($c >> 1);
                    } else {
                        $c >>= 1;
                    }
                }
                self::$crcTable[$i] = $c;
            }
        }

        $crc = 0xFFFFFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc = self::$crcTable[($crc ^ ord($data[$i])) & 0xFF] ^ ($crc >> 8);
        }

        return ($crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
    }
}
