<?php

declare(strict_types=1);

/**
 * Tuya-Lokalprotokoll-Client (3.3/3.4/3.5) für Yieryi & ähnliche Sensoren.
 *
 * Paketformat 0x55AA: Prefix, Seq, Cmd, Length, [Retcode], Payload, CRC/HMAC, Suffix 0xAA55
 */
final class TuyaLocalClient
{
    private const PREFIX_SEND = 0x000055AA;
    private const PREFIX_RECV = 0x000055AA;
    private const PREFIX_RECV_ALT = 0x000066AA;
    private const PREFIX_6699_SEND = 0x00006699;
    private const SUFFIX = 0x0000AA55;
    private const SUFFIX_6699 = 0x00009966;
    private const CMD_SESS_KEY_NEG_START = 0x00000003;
    private const CMD_SESS_KEY_NEG_RESP = 0x00000004;
    private const CMD_SESS_KEY_NEG_FINISH = 0x00000005;
    private const CMD_HEART_BEAT = 0x00000009;
    private const CMD_STATUS = 0x0000000A;
    private const CMD_CONTROL_NEW = 0x0000000D;
    private const CMD_DP_QUERY_NEW = 0x00000010;
    private const DEFAULT_PORT = 6668;
    private const SOCKET_TIMEOUT_SEC = 3;
    private const SOCKET_TIMEOUT_QUICK_SEC = 2;
    private const SEND_WAIT_USEC = 150000;
    /** @var string */
    private const LOCAL_NONCE = '0123456789abcdef';
    /** @var string 3.3 + 12 NUL */
    private const PROTOCOL_HEADER_33 = "3.3\0\0\0\0\0\0\0\0\0\0\0\0";
    /** @var string 3.2 + 12 NUL (tinytuya behandelt 3.2 wie device22) */
    private const PROTOCOL_HEADER_32 = "3.2\0\0\0\0\0\0\0\0\0\0\0\0";
    /** @var string */
    private const PROTOCOL_HEADER_34 = "3.4\0\0\0\0\0\0\0\0\0\0\0\0";
    /** @var string */
    private const PROTOCOL_HEADER_35 = "3.5\0\0\0\0\0\0\0\0\0\0\0\0";

    /** @var null|string */
    private $discoveredProtocol = null;

    /** @var list<int> */
    private static $crcTable = [];

    private string $deviceId;
    private string $localKey;
    private string $protocolVersion;
    private int $seqNo = 0;
    private string $sessionKey = '';
    private string $localNonce = '';
    private string $remoteNonce = '';

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
        array $dpsQueryKeys = [],
        ?string $discoveredProtocol = null
    ) {
        $this->deviceId = trim($deviceId);
        $this->localKey = trim($localKey);
        $this->protocolVersion = trim($protocolVersion) !== '' ? trim($protocolVersion) : '3.3';
        $this->debugLogger = $debugLogger;
        $this->dpsQueryKeys = $dpsQueryKeys;
        $discovered = trim((string) $discoveredProtocol);
        $this->discoveredProtocol = $discovered !== '' ? $discovered : null;
    }

    private function isDevice22(): bool
    {
        return strlen($this->deviceId) === 22;
    }

    /**
     * @return array{ok: bool, dps: array<string|int, mixed>, error: string}
     */
    public function fetchStatus(string $host, bool $quickPoll = false): array
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
            'Start host=%s:%d devId=%s proto=%s scanProto=%s keyLen=%d device22=%s quick=%s',
            $host,
            self::DEFAULT_PORT,
            $this->deviceId,
            $this->protocolVersion,
            $this->discoveredProtocol ?? '-',
            strlen($this->localKey),
            $this->isDevice22() ? 'yes' : 'no',
            $quickPoll ? 'yes' : 'no',
        ));

        $attempts = $this->buildFetchAttempts($quickPoll);
        $lastError = 'Keine Antwort vom Gerät';
        $zeroByteDisconnects = 0;

        foreach ($attempts as $attempt) {
            $result = $this->fetchStatusAttempt($host, $attempt, $quickPoll);
            if ($result['ok']) {
                return $result;
            }
            $lastError = $result['error'];
            if ($this->isZeroByteDisconnectError($lastError)) {
                $zeroByteDisconnects++;
                $abortAfter = $quickPoll ? 1 : 2;
                if ($zeroByteDisconnects >= $abortAfter) {
                    $this->log('Abbruch nach 0-Bytes-Antwort — vermutlich kein Tuya-LAN am Gerät');
                    break;
                }
            }
        }

        return ['ok' => false, 'dps' => [], 'error' => $lastError . ' — Keine Tuya-Antwort auf Port 6668; ggf. Cloud-Fallback aktivieren'];
    }

    private function isZeroByteDisconnectError(string $error): bool
    {
        return str_contains($error, '0 Bytes')
            || str_contains($error, 'ohne Antwort')
            || str_contains($error, 'Header unvollständig');
    }

    /**
     * @param array{mode: string, command: int, json: string, proto: string, keyMode: string, session: bool} $attempt
     * @return array{ok: bool, dps: array<string|int, mixed>, error: string}
     */
    private function fetchStatusAttempt(string $host, array $attempt, bool $quickPoll = false): array
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return ['ok' => false, 'dps' => [], 'error' => 'Socket konnte nicht erstellt werden'];
        }

        $timeoutSec = $quickPoll ? self::SOCKET_TIMEOUT_QUICK_SEC : self::SOCKET_TIMEOUT_SEC;
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeoutSec, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeoutSec, 'usec' => 0]);

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
            $this->sessionKey = '';
            $proto = $attempt['proto'];
            $realKey = $this->deriveKey($attempt['keyMode']);

            $this->log(sprintf(
                'Versuch mode=%s cmd=0x%02X proto=%s key=%s session=%s json=%s',
                $attempt['mode'],
                $attempt['command'],
                $proto,
                $attempt['keyMode'],
                $attempt['session'] ? 'yes' : 'no',
                $attempt['json'],
            ));

            if ($proto === '3.5' && $attempt['session']) {
                return $this->fetchStatusAttempt35($socket, $attempt, $realKey);
            }

            if (!$attempt['session'] && in_array($proto, ['3.2', '3.3'], true)) {
                $this->sendHeartbeat($socket, $realKey, $proto);
            }

            $hmacKey = null;
            $encryptKey = $realKey;

            if ($attempt['session']) {
                if (!$this->negotiateSessionKey($socket, $realKey, $proto)) {
                    return ['ok' => false, 'dps' => [], 'error' => 'Session-Key-Verhandlung fehlgeschlagen (' . $attempt['mode'] . ')'];
                }
                $encryptKey = $this->sessionKey;
                $hmacKey = $this->sessionKey;
            }

            $encrypted = $this->encrypt($attempt['json'], $encryptKey);
            $wirePayload = $this->wrapWirePayload($encrypted, $attempt['command'], $proto);
            $packet = $this->packMessage($attempt['command'], $wirePayload, $hmacKey);
            $this->log(sprintf(
                'Sende Paket seq=%d len=%d wireLen=%d encLen=%d hmac=%s hex=%s',
                $this->seqNo,
                strlen($packet),
                strlen($wirePayload),
                strlen($encrypted),
                $hmacKey !== null ? 'yes' : 'no',
                $this->hexPreview($packet, 64),
            ));

            $written = @socket_write($socket, $packet, strlen($packet));
            if ($written === false || $written === 0) {
                $this->log('socket_write fehlgeschlagen');

                return ['ok' => false, 'dps' => [], 'error' => 'Senden fehlgeschlagen'];
            }

            usleep(self::SEND_WAIT_USEC);

            $response = $this->readResponse($socket, $hmacKey);
            if ($response === null) {
                $emptyMsg = $this->describeEmptyRead($socket);
                $this->log($emptyMsg);

                return ['ok' => false, 'dps' => [], 'error' => $emptyMsg . ' (' . $attempt['mode'] . ')'];
            }

            $this->log(sprintf('Empfangen %d Bytes, hex=%s', strlen($response), $this->hexPreview($response)));

            $decoded = $this->decodeMessage($response, $encryptKey, $proto, $hmacKey !== null);
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
     * @return list<array{mode: string, command: int, json: string, proto: string, keyMode: string, session: bool}>
     */
    private function buildFetchAttempts(bool $quickPoll = false): array
    {
        if ($quickPoll && $this->isDevice22()) {
            $proto = in_array($this->protocolVersion, ['3.2', '3.3'], true)
                ? $this->protocolVersion
                : '3.3';
            $attempts = [];
            foreach ($this->keyModes() as $keyMode) {
                $attempts[] = $this->attemptSpec(
                    'device22',
                    self::CMD_CONTROL_NEW,
                    $this->buildDevice22Payload(true),
                    $proto,
                    $keyMode,
                    false,
                );
            }

            return $attempts;
        }

        $attempts = [];
        $keyModes = $this->keyModes();
        $configuredProto = $this->protocolVersion;
        $scanProto = $this->normalizeProtocol($this->discoveredProtocol);
        $primaryProtos = [];
        if ($scanProto !== null) {
            $primaryProtos[] = $scanProto;
        }
        if (!in_array($configuredProto, $primaryProtos, true)) {
            $primaryProtos[] = $configuredProto;
        }
        foreach (['3.2', '3.3', '3.4', '3.5'] as $fallbackProto) {
            if (!in_array($fallbackProto, $primaryProtos, true)) {
                $primaryProtos[] = $fallbackProto;
            }
        }

        foreach ($primaryProtos as $proto) {
            if ($this->isDevice22() && in_array($proto, ['3.2', '3.3'], true)) {
                foreach ($keyModes as $keyMode) {
                    $attempts[] = $this->attemptSpec('device22', self::CMD_CONTROL_NEW, $this->buildDevice22Payload(true), $proto, $keyMode, false);
                    $attempts[] = $this->attemptSpec('device22-nodps', self::CMD_CONTROL_NEW, $this->buildDevice22Payload(false), $proto, $keyMode, false);
                    $attempts[] = $this->attemptSpec('device22-uid', self::CMD_CONTROL_NEW, $this->buildDevice22Payload(true, true), $proto, $keyMode, false);
                }
            }

            if (in_array($proto, ['3.4', '3.5'], true)) {
                foreach ($keyModes as $keyMode) {
                    $attempts[] = $this->attemptSpec('v34-query', self::CMD_DP_QUERY_NEW, $this->buildModernPayload(), $proto, $keyMode, true);
                    if ($this->isDevice22()) {
                        $attempts[] = $this->attemptSpec('v34-device22', self::CMD_CONTROL_NEW, $this->buildDevice22Payload(true), $proto, $keyMode, true);
                    }
                }
            }

            if ($proto === '3.3') {
                foreach ($keyModes as $keyMode) {
                    $attempts[] = $this->attemptSpec('default33', self::CMD_STATUS, $this->buildLegacy33Payload(), '3.3', $keyMode, false);
                }
            }
        }

        return $attempts;
    }

    private function normalizeProtocol(?string $proto): ?string
    {
        if ($proto === null) {
            return null;
        }

        $proto = trim($proto);
        if (in_array($proto, ['3.2', '3.3', '3.4', '3.5'], true)) {
            return $proto;
        }

        if (preg_match('/3\.[2345]/', $proto, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @return array{mode: string, command: int, json: string, proto: string, keyMode: string, session: bool}
     */
    private function attemptSpec(
        string $mode,
        int $command,
        string $json,
        string $proto,
        string $keyMode,
        bool $session
    ): array {
        return [
            'mode' => $mode,
            'command' => $command,
            'json' => $json,
            'proto' => $proto,
            'keyMode' => $keyMode,
            'session' => $session,
        ];
    }

    /** @return list<string> */
    private function keyModes(): array
    {
        $modes = ['raw'];
        if (strlen($this->localKey) !== 16) {
            return $modes;
        }

        return ['raw', 'md5'];
    }

    private function buildLegacy33Payload(): string
    {
        return $this->encodeJson([
            'gwId' => $this->deviceId,
            'devId' => $this->deviceId,
        ]);
    }

    private function buildModernPayload(): string
    {
        return $this->encodeJson([
            'devId' => $this->deviceId,
            'uid' => '',
            't' => (string) time(),
        ]);
    }

    private function buildDevice22Payload(bool $withDps, bool $uidAsDevId = false): string
    {
        $json = [
            'devId' => $this->deviceId,
            'uid' => $uidAsDevId ? $this->deviceId : '',
            't' => (string) time(),
        ];

        if ($withDps) {
            $dps = [];
            $keys = $this->dpsQueryKeys !== [] ? $this->dpsQueryKeys : [1, 2];
            foreach ($keys as $dp) {
                $dps[(string) $dp] = null;
            }
            $json['dps'] = $dps;
        }

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

    private function protocolHeader(string $proto): string
    {
        if ($proto === '3.5') {
            return self::PROTOCOL_HEADER_35;
        }
        if ($proto === '3.4') {
            return self::PROTOCOL_HEADER_34;
        }
        if ($proto === '3.2') {
            return self::PROTOCOL_HEADER_32;
        }

        return self::PROTOCOL_HEADER_33;
    }

    private function sendHeartbeat($socket, string $key, string $proto): void
    {
        $this->log('Sende Heartbeat (0x09) vor Abfrage');
        $payload = $this->encrypt('{}', $key);
        $packet = $this->packMessage(self::CMD_HEART_BEAT, $payload, null);
        $this->log('Heartbeat hex=' . $this->hexPreview($packet, 48));
        @socket_write($socket, $packet, strlen($packet));
        usleep(100000);
        $response = $this->readResponse($socket, null);
        if ($response !== null) {
            $this->log(sprintf('Heartbeat Antwort %d Bytes', strlen($response)));
        }
        $this->seqNo = 0;
    }

    private function wrapWirePayload(string $encrypted, int $command, string $proto): string
    {
        if ($this->needsProtocolHeader($command)) {
            return $this->protocolHeader($proto) . $encrypted;
        }

        return $encrypted;
    }

    private function needsProtocolHeader(int $command): bool
    {
        if (in_array($command, [self::CMD_STATUS, self::CMD_DP_QUERY_NEW, self::CMD_HEART_BEAT, self::CMD_SESS_KEY_NEG_START, self::CMD_SESS_KEY_NEG_RESP, self::CMD_SESS_KEY_NEG_FINISH], true)) {
            return false;
        }

        return true;
    }

    private function negotiateSessionKey($socket, string $realKey, string $proto): bool
    {
        $this->localNonce = self::LOCAL_NONCE;
        $this->remoteNonce = '';
        $hmacKey = $realKey;

        $this->log('Session Schritt 1: SESS_KEY_NEG_START');

        $step1Payload = $this->encrypt($this->localNonce, $realKey);
        $step1Packet = $this->packMessage(self::CMD_SESS_KEY_NEG_START, $step1Payload, $hmacKey);
        if (@socket_write($socket, $step1Packet, strlen($step1Packet)) === false) {
            $this->log('Session Schritt 1: Senden fehlgeschlagen');

            return false;
        }

        usleep(self::SEND_WAIT_USEC);

        $response = $this->readResponse($socket, $hmacKey);
        if ($response === null) {
            $this->log('Session Schritt 2: ' . $this->describeEmptyRead($socket));

            return false;
        }

        $message = $this->parseMessage($response, $hmacKey);
        if ($message === null) {
            $this->log('Session Schritt 2: Parse fehlgeschlagen');

            return false;
        }

        if ($message['cmd'] !== self::CMD_SESS_KEY_NEG_RESP) {
            $this->log(sprintf('Session Schritt 2: unerwartetes cmd=0x%X', $message['cmd']));

            return false;
        }

        $payload = $this->decrypt($message['body'], $realKey);
        if ($payload === null || strlen($payload) < 48) {
            $this->log('Session Schritt 2: Payload zu kurz oder Entschlüsselung fehlgeschlagen');

            return false;
        }

        $this->remoteNonce = substr($payload, 0, 16);
        $hmacCheck = hash_hmac('sha256', $this->localNonce, $realKey, true);
        if (!hash_equals($hmacCheck, substr($payload, 16, 32))) {
            $this->log('Session Schritt 2: HMAC-Prüfung fehlgeschlagen (Local Key?)');

            return false;
        }

        $this->log('Session Schritt 3: SESS_KEY_NEG_FINISH');
        $finishPayload = hash_hmac('sha256', $this->remoteNonce, $realKey, true);
        $step3Wire = $this->encryptRawBlock($finishPayload, $realKey);
        $step3Packet = $this->packMessage(self::CMD_SESS_KEY_NEG_FINISH, $step3Wire, $hmacKey);
        if (@socket_write($socket, $step3Packet, strlen($step3Packet)) === false) {
            $this->log('Session Schritt 3: Senden fehlgeschlagen');

            return false;
        }

        $xored = '';
        for ($i = 0; $i < 16; $i++) {
            $xored .= chr(ord($this->localNonce[$i]) ^ ord($this->remoteNonce[$i]));
        }

        if ($proto === '3.4') {
            $this->sessionKey = $this->encryptRawBlock($xored, $realKey);
        } else {
            $this->sessionKey = substr($this->encryptRawBlock($xored, $realKey), 0, 16);
        }

        $this->log('Session-Key-Verhandlung erfolgreich');

        return strlen($this->sessionKey) === 16;
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

    private function packMessage(int $command, string $wirePayload, ?string $hmacKey = null): string
    {
        $this->seqNo = ($this->seqNo + 1) % 0xFFFFFFFF;
        $footerLen = $hmacKey !== null ? 36 : 8;
        $payloadLen = strlen($wirePayload) + $footerLen;

        $header = pack('N', self::PREFIX_SEND)
            . pack('N', $this->seqNo)
            . pack('N', $command)
            . pack('N', $payloadLen);

        $data = $header . $wirePayload;
        if ($hmacKey !== null) {
            $hmac = hash_hmac('sha256', $data, $hmacKey, true);

            return $data . $hmac . pack('N', self::SUFFIX);
        }

        $crc = self::crc32($data);

        return $data . pack('N', $crc) . pack('N', self::SUFFIX);
    }

    /**
     * @return array{ok: bool, payload: string, error: string}
     */
    private function decodeMessage(string $raw, string $key, string $proto, bool $withHmac): array
    {
        $message = $this->parseMessage($raw, $withHmac ? $key : null);
        if ($message === null) {
            return ['ok' => false, 'payload' => '', 'error' => 'Antwort konnte nicht gelesen werden'];
        }

        $body = $message['body'];
        $encrypted = $body;
        $header = $this->protocolHeader($proto);

        if (strlen($body) >= 15 && substr($body, 0, 3) === substr($header, 0, 3)) {
            $encrypted = substr($body, 15);
        } elseif ($body !== '' && $body[0] !== '{') {
            if (strlen($body) >= 15) {
                $encrypted = substr($body, 15);
            }
        }

        $decrypted = $this->decrypt($encrypted, $key);
        if ($decrypted === null) {
            return ['ok' => false, 'payload' => '', 'error' => 'Entschlüsselung fehlgeschlagen (Local Key?)'];
        }

        return ['ok' => true, 'payload' => $decrypted, 'error' => ''];
    }

    /**
     * @return null|array{cmd: int, retcode: int, body: string}
     */
    private function parseMessage(string $raw, ?string $hmacKey = null): ?array
    {
        if (strlen($raw) < 28) {
            return null;
        }

        $prefix = unpack('N', substr($raw, 0, 4))[1] ?? 0;
        if ($prefix !== self::PREFIX_RECV && $prefix !== self::PREFIX_RECV_ALT) {
            return null;
        }

        $cmd = unpack('N', substr($raw, 8, 4))[1] ?? 0;
        $payloadLength = unpack('N', substr($raw, 12, 4))[1] ?? 0;
        $headerLen = 16;
        $footerLen = $hmacKey !== null ? 36 : 8;
        $expectedLen = $headerLen + $payloadLength;

        if (strlen($raw) < $expectedLen) {
            return null;
        }

        $frame = substr($raw, 0, $expectedLen);
        $bodyWithFooter = substr($frame, $headerLen, $payloadLength);
        if ($bodyWithFooter === false) {
            return null;
        }

        if ($hmacKey !== null) {
            if (strlen($bodyWithFooter) < $footerLen + 4) {
                return null;
            }
            $hmac = substr($bodyWithFooter, -$footerLen, 32);
            $suffix = unpack('N', substr($bodyWithFooter, -4))[1] ?? 0;
            if ($suffix !== self::SUFFIX) {
                return null;
            }
            $expectedHmac = hash_hmac('sha256', substr($frame, 0, $expectedLen - $footerLen), $hmacKey, true);
            if (!hash_equals($expectedHmac, $hmac)) {
                $this->log('HMAC der Antwort ungültig');

                return null;
            }
        } else {
            $crc = unpack('N', substr($bodyWithFooter, -8, 4))[1] ?? 0;
            $suffix = unpack('N', substr($bodyWithFooter, -4))[1] ?? 0;
            $expectedCrc = self::crc32(substr($frame, 0, $expectedLen - 8));
            if ($suffix !== self::SUFFIX || $crc !== $expectedCrc) {
                $this->log(sprintf('CRC der Antwort ungültig (crc=0x%08X erwartet=0x%08X)', $crc, $expectedCrc));
            }
        }

        $retcode = unpack('N', substr($bodyWithFooter, 0, 4))[1] ?? 0;
        $body = substr($bodyWithFooter, 4, strlen($bodyWithFooter) - 4 - $footerLen);
        if ($body === false) {
            return null;
        }

        return [
            'cmd' => $cmd,
            'retcode' => $retcode,
            'body' => $body,
        ];
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

    private function deriveKey(string $mode = 'raw'): string
    {
        if ($mode === 'md5') {
            return substr(md5($this->localKey, true), 0, 16);
        }

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

    private function encryptRawBlock(string $data, string $key): string
    {
        if (strlen($data) !== 16) {
            return '';
        }

        return openssl_encrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING) ?: '';
    }

    private function decrypt(string $data, string $key): ?string
    {
        if ($data === '') {
            return null;
        }

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

    private function describeEmptyRead($socket): string
    {
        $meta = @socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        if (is_int($meta) && $meta !== 0 && function_exists('socket_strerror')) {
            return 'Gerät schloss Verbindung ohne Antwort (' . socket_strerror($meta) . ')';
        }

        return 'Gerät schloss Verbindung ohne Antwort (0 Bytes — ggf. Cloud-Fallback nutzen)';
    }

    /**
     * @param array{mode: string, command: int, json: string, proto: string, keyMode: string, session: bool} $attempt
     * @return array{ok: bool, dps: array<string|int, mixed>, error: string}
     */
    private function fetchStatusAttempt35($socket, array $attempt, string $realKey): array
    {
        if (!$this->negotiateSession35($socket, $realKey)) {
            return ['ok' => false, 'dps' => [], 'error' => 'Session-Key-Verhandlung fehlgeschlagen (3.5)'];
        }

        $plain = $attempt['json'];
        if ($this->needsProtocolHeader($attempt['command'])) {
            $plain = self::PROTOCOL_HEADER_35 . $plain;
        }

        $packet = $this->pack6699Message($attempt['command'], $plain, $this->sessionKey);
        $this->log(sprintf('Sende 3.5-Paket seq=%d len=%d', $this->seqNo, strlen($packet)));

        if (@socket_write($socket, $packet, strlen($packet)) === false) {
            return ['ok' => false, 'dps' => [], 'error' => 'Senden fehlgeschlagen (3.5)'];
        }

        usleep(self::SEND_WAIT_USEC);

        $message = $this->read6699Response($socket, $this->sessionKey);
        if ($message === null) {
            $this->log($this->describeEmptyRead($socket));

            return ['ok' => false, 'dps' => [], 'error' => 'Keine Antwort vom Gerät (' . $attempt['mode'] . ', 3.5)'];
        }

        $body = $message['body'];
        if (strlen($body) >= 15 && substr($body, 0, 3) === '3.5') {
            $body = substr($body, 15);
        }

        $this->log('Payload JSON (3.5): ' . $body);
        $dps = $this->extractDps($body);
        if ($dps === []) {
            return ['ok' => false, 'dps' => [], 'error' => 'Antwort ohne DPS-Daten (3.5)'];
        }

        return ['ok' => true, 'dps' => $dps, 'error' => ''];
    }

    private function negotiateSession35($socket, string $realKey): bool
    {
        $this->localNonce = self::LOCAL_NONCE;
        $this->remoteNonce = '';

        $this->log('Session 3.5 Schritt 1: SESS_KEY_NEG_START');
        $step1Packet = $this->pack6699Message(self::CMD_SESS_KEY_NEG_START, $this->localNonce, $realKey);
        if (@socket_write($socket, $step1Packet, strlen($step1Packet)) === false) {
            return false;
        }

        usleep(self::SEND_WAIT_USEC);

        $message = $this->read6699Response($socket, $realKey);
        if ($message === null) {
            $this->log('Session 3.5 Schritt 2: ' . $this->describeEmptyRead($socket));

            return false;
        }

        if ($message['cmd'] !== self::CMD_SESS_KEY_NEG_RESP) {
            $this->log(sprintf('Session 3.5 Schritt 2: unerwartetes cmd=0x%X', $message['cmd']));

            return false;
        }

        $payload = $message['body'];
        if (strlen($payload) < 48) {
            $this->log('Session 3.5 Schritt 2: Payload zu kurz');

            return false;
        }

        $this->remoteNonce = substr($payload, 0, 16);
        $hmacCheck = hash_hmac('sha256', $this->localNonce, $realKey, true);
        if (!hash_equals($hmacCheck, substr($payload, 16, 32))) {
            $this->log('Session 3.5 Schritt 2: HMAC-Prüfung fehlgeschlagen (Local Key?)');

            return false;
        }

        $finishPayload = hash_hmac('sha256', $this->remoteNonce, $realKey, true);
        $step3Packet = $this->pack6699Message(self::CMD_SESS_KEY_NEG_FINISH, $finishPayload, $realKey);
        if (@socket_write($socket, $step3Packet, strlen($step3Packet)) === false) {
            return false;
        }

        $xored = '';
        for ($i = 0; $i < 16; $i++) {
            $xored .= chr(ord($this->localNonce[$i]) ^ ord($this->remoteNonce[$i]));
        }

        $iv = substr($this->localNonce, 0, 12);
        $derived = $this->encryptGcm($xored, $realKey, '', $iv);
        $this->sessionKey = substr($derived, 12, 16);
        $this->log('Session-Key-Verhandlung 3.5 erfolgreich');

        return strlen($this->sessionKey) === 16;
    }

    private function pack6699Message(int $command, string $plaintextPayload, string $key): string
    {
        $this->seqNo = ($this->seqNo + 1) % 0xFFFFFFFF;
        $msgLen = strlen($plaintextPayload) + 28;

        $header = pack('N', self::PREFIX_6699_SEND)
            . pack('n', 0)
            . pack('N', $this->seqNo)
            . pack('N', $command)
            . pack('N', $msgLen);

        $aad = substr($header, 4);
        $encrypted = $this->encryptGcm($plaintextPayload, $key, $aad, $this->makeGcmIv());

        return $header . $encrypted . pack('N', self::SUFFIX_6699);
    }

    /**
     * @return null|array{cmd: int, body: string}
     */
    private function read6699Response($socket, string $key): ?array
    {
        $header = $this->recvExact($socket, 18);
        if ($header === null) {
            return null;
        }

        $prefix = unpack('N', substr($header, 0, 4))[1] ?? 0;
        if ($prefix !== self::PREFIX_6699_SEND && $prefix !== 0x00006699) {
            $this->log(sprintf('6699 unerwartetes Präfix 0x%08X', $prefix));

            return null;
        }

        $cmd = unpack('N', substr($header, 10, 4))[1] ?? 0;
        $payloadLength = unpack('N', substr($header, 14, 4))[1] ?? 0;
        $blob = $this->recvExact($socket, $payloadLength + 4);
        if ($blob === null || strlen($blob) < $payloadLength + 4) {
            return null;
        }

        $encrypted = substr($blob, 0, $payloadLength);
        $aad = substr($header, 4);
        $plain = $this->decryptGcm($encrypted, $key, $aad);
        if ($plain === null) {
            $this->log('6699 Entschlüsselung fehlgeschlagen');

            return null;
        }

        return ['cmd' => $cmd, 'body' => $plain];
    }

    private function encryptGcm(string $plain, string $key, string $aad, string $iv): string
    {
        $tag = '';
        $cipherText = openssl_encrypt($plain, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if (!is_string($cipherText)) {
            return '';
        }

        return $iv . $cipherText . $tag;
    }

    private function decryptGcm(string $blob, string $key, string $aad): ?string
    {
        if (strlen($blob) < 28) {
            return null;
        }

        $iv = substr($blob, 0, 12);
        $tag = substr($blob, -16);
        $cipherText = substr($blob, 12, -16);
        $plain = openssl_decrypt($cipherText, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if (!is_string($plain)) {
            return null;
        }

        return $plain;
    }

    private function makeGcmIv(): string
    {
        return substr(str_pad((string) (int) (microtime(true) * 10000000), 12, '0', STR_PAD_LEFT), 0, 12);
    }

    private function readResponse($socket, ?string $hmacKey = null): ?string
    {
        $header = $this->recvExact($socket, 16);
        if ($header === null) {
            $this->log('Header unvollständig — ' . $this->describeEmptyRead($socket));

            return null;
        }

        $prefix = unpack('N', substr($header, 0, 4))[1] ?? 0;
        $payloadLength = unpack('N', substr($header, 12, 4))[1] ?? 0;
        $this->log(sprintf('Header prefix=0x%08X payloadLen=%d hmac=%s', $prefix, $payloadLength, $hmacKey !== null ? 'yes' : 'no'));

        if ($payloadLength <= 0 || $payloadLength > 4096) {
            $this->log('Header payloadLen unplausibel');

            return null;
        }

        $rest = $this->recvExact($socket, $payloadLength);
        if ($rest === null) {
            $this->log(sprintf('Payload unvollständig (%d erwartet)', $payloadLength));

            return null;
        }

        return $header . $rest;
    }

    private function recvExact($socket, int $length): ?string
    {
        $buffer = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @socket_read($socket, $remaining, PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
                if ($buffer === '') {
                    return null;
                }

                break;
            }
            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        if (strlen($buffer) < $length) {
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
