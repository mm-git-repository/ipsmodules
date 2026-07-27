<?php

declare(strict_types=1);

/**
 * Minimal WebSocket client for Gardena smart Gateway (wss://host:8443).
 * Request/response style suitable for IP-Symcon timers (no persistent daemon).
 */
final class GardenaSmartClient
{
    private const COMMAND_SOURCE = '18';
    private const DEFAULT_TIMEOUT_SEC = 12;

    private string $host;
    private string $password;
    private int $port;
    private bool $tlsInsecure;
    private int $timeoutSec;

    /** @var null|callable(string, string): void */
    private $logger;

    public function __construct(
        string $host,
        string $password,
        int $port = 8443,
        bool $tlsInsecure = true,
        int $timeoutSec = self::DEFAULT_TIMEOUT_SEC,
        ?callable $logger = null
    ) {
        $this->host = trim($host);
        $this->password = $password;
        $this->port = $port > 0 ? $port : 8443;
        $this->tlsInsecure = $tlsInsecure;
        $this->timeoutSec = max(3, $timeoutSec);
        $this->logger = $logger;
    }

    private function log(string $topic, string $message): void
    {
        if ($this->logger === null) {
            return;
        }
        try {
            ($this->logger)($topic, $message);
        } catch (Throwable) {
            // never break control path due to logging
        }
    }

    /**
     * Discover devices from lemonbeatd + lwm2mserver.
     *
     * @return array{devices: array<string, array<string, mixed>>, raw: list<array<string, mixed>>}
     */
    public function discover(): array
    {
        $this->log('WSS', 'discover start (lemonbeatd + lwm2mserver)');
        $requests = [
            $this->buildRequest('read', 'lemonbeatd', 'devices'),
            $this->buildRequest('read', 'lwm2mserver', 'devices'),
        ];
        $replies = $this->exchange($requests);
        $devices = [];
        foreach ($replies as $msg) {
            $payload = $this->extractDiscoverPayload($msg);
            if ($payload === null) {
                $this->log(
                    'WSS',
                    'discover reply ignored keys=' . implode(',', array_keys($msg))
                    . ' preview=' . substr((string) json_encode($msg, JSON_UNESCAPED_UNICODE), 0, 240)
                );
                continue;
            }
            foreach ($payload as $deviceId => $deviceData) {
                if (is_string($deviceId) && is_array($deviceData)) {
                    // Later replies (e.g. lwm2mserver) overwrite / merge richer data
                    if (!isset($devices[$deviceId])) {
                        $devices[$deviceId] = $deviceData;
                    } else {
                        $devices[$deviceId] = array_replace_recursive($devices[$deviceId], $deviceData);
                    }
                }
            }
        }
        $this->log('WSS', 'discover done — ' . count($devices) . ' device(s)');

        return ['devices' => $devices, 'raw' => $replies];
    }

    /**
     * Accept classic {success,payload} replies and bare device maps (gateway sometimes omits the wrapper).
     *
     * @param array<string, mixed> $msg
     * @return null|array<string, array<string, mixed>>
     */
    private function extractDiscoverPayload(array $msg): ?array
    {
        if (($msg['success'] ?? null) === true && is_array($msg['payload'] ?? null)) {
            return $this->isDeviceMap($msg['payload']) ? $msg['payload'] : null;
        }
        if (is_array($msg['payload'] ?? null) && $this->isDeviceMap($msg['payload'])) {
            return $msg['payload'];
        }
        if ($this->isDeviceMap($msg)) {
            return $msg;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $map
     */
    private function isDeviceMap(array $map): bool
    {
        if ($map === [] || array_is_list($map)) {
            return false;
        }
        // Wrapper keys from normal replies
        if (isset($map['success']) || isset($map['request_id']) || isset($map['error'])) {
            return false;
        }
        $deviceLike = 0;
        foreach ($map as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                return false;
            }
            // Gardena device ids are long hex / SGTIN-like strings
            if (strlen($key) < 8) {
                return false;
            }
            if (
                isset($value['actuator'])
                || isset($value['device'])
                || isset($value['schedule'])
                || isset($value['lemonbeat'])
                || isset($value['connection_status'])
                || isset($value['sg_common'])
            ) {
                $deviceLike++;
            }
        }

        return $deviceLike > 0;
    }

    /**
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>>
     */
    public function exchange(array $requests): array
    {
        if ($this->host === '' || $this->password === '') {
            throw new RuntimeException('Host oder Passwort fehlt');
        }

        $this->log('WSS', 'exchange batch size=' . count($requests) . ' timeout=' . $this->timeoutSec . 's');
        $fp = $this->connect();
        try {
            return $this->exchangeOnConnection($fp, $requests);
        } finally {
            $this->closeStream($fp);
        }
    }

    /**
     * Send requests one-by-one on a single connection (needed for Gen2 schedule writes).
     *
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>>
     */
    public function exchangeSequential(array $requests): array
    {
        if ($this->host === '' || $this->password === '') {
            throw new RuntimeException('Host oder Passwort fehlt');
        }
        if ($requests === []) {
            return [];
        }

        $this->log('WSS', 'exchangeSequential count=' . count($requests) . ' timeout=' . $this->timeoutSec . 's');
        $fp = $this->connect();
        $all = [];
        try {
            foreach ($requests as $idx => $req) {
                $path = (string) (($req['entity']['path'] ?? '') ?: '?');
                $op = (string) ($req['op'] ?? '?');
                $this->log('WSS', sprintf('seq %d/%d %s %s', $idx + 1, count($requests), $op, $path));
                try {
                    $replies = $this->exchangeOnConnection($fp, [$req]);
                    foreach ($replies as $msg) {
                        $ok = (($msg['success'] ?? null) === true) ? 'ok' : 'fail';
                        $this->log('WSS', sprintf('seq %d/%d reply=%s', $idx + 1, count($requests), $ok));
                        $all[] = $msg;
                    }
                } catch (Throwable $e) {
                    $this->log('WSS', sprintf('seq %d/%d ERROR: %s', $idx + 1, count($requests), $e->getMessage()));
                    throw $e;
                }
                if ($idx < count($requests) - 1) {
                    usleep(80000);
                }
            }
        } finally {
            $this->closeStream($fp);
        }

        return $all;
    }

    /**
     * @param resource $fp
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>>
     */
    private function exchangeOnConnection($fp, array $requests): array
    {
        $this->sendJson($fp, $requests);
        $want = [];
        foreach ($requests as $req) {
            if (isset($req['request_id'])) {
                $want[(string) $req['request_id']] = true;
            }
        }
        $pending = count($want);
        $got = [];
        $deadline = microtime(true) + $this->timeoutSec;
        $buffer = '';
        $rawCount = 0;
        $previews = [];
        while ($want !== [] && microtime(true) < $deadline) {
            $raw = $this->recvFrame($fp, max(0.2, $deadline - microtime(true)));
            if ($raw === null) {
                continue;
            }
            $rawCount++;
            $previews[] = substr($raw, 0, 220);
            $buffer .= $raw;
            $chunks = $this->extractJsonValues($buffer);
            if ($chunks === []) {
                $this->log('WSS', 'recv non-json/fragment len=' . strlen($buffer) . ' preview=' . substr($buffer, 0, 160));
                if (strlen($buffer) > 1_000_000) {
                    $buffer = '';
                }
                continue;
            }
            // Keep only unconsumed tail (extractJsonValues clears fully parsed buffer via ref)
            foreach ($chunks as $decoded) {
                foreach ($this->flattenReplyMessages($decoded) as $msg) {
                    $this->consumeReplyMessage($msg, $want, $got);
                }
            }
        }
        if ($want !== []) {
            $this->log(
                'WSS',
                'TIMEOUT got=' . count($got) . '/' . $pending
                . ' frames=' . $rawCount
                . ' missing=' . implode(',', array_keys($want))
                . ' preview=' . implode(' || ', $previews)
            );
            throw new RuntimeException(
                'Timeout: nicht alle Antworten vom Gateway erhalten ('
                . count($got) . '/' . $pending
                . ', frames=' . $rawCount . ')'
            );
        }

        return $got;
    }

    /**
     * Parse one or more JSON values from a growing buffer (handles concat / multi-value streams).
     *
     * @return list<mixed>
     */
    private function extractJsonValues(string &$buffer): array
    {
        $out = [];
        $buffer = ltrim($buffer);
        while ($buffer !== '') {
            $decoded = json_decode($buffer, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $out[] = $decoded;
                $buffer = '';
                break;
            }
            // Try progressive trim: find a complete JSON value by scanning braces/brackets
            $len = strlen($buffer);
            $complete = false;
            for ($i = 1; $i <= $len; $i++) {
                $slice = substr($buffer, 0, $i);
                $decoded = json_decode($slice, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                $out[] = $decoded;
                $buffer = ltrim(substr($buffer, $i));
                $complete = true;
                break;
            }
            if (!$complete) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param mixed $decoded
     * @return list<array<string, mixed>>
     */
    private function flattenReplyMessages(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }
        if ($decoded === []) {
            return [];
        }
        if (array_is_list($decoded)) {
            $out = [];
            foreach ($decoded as $item) {
                foreach ($this->flattenReplyMessages($item) as $msg) {
                    $out[] = $msg;
                }
            }

            return $out;
        }
        foreach (['responses', 'results', 'datas', 'data', 'payload'] as $key) {
            if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
                continue;
            }
            // payload may be value object (vi/vs) — only flatten list-like wrappers
            if ($key === 'payload' && !array_is_list($decoded[$key]) && (
                array_key_exists('vi', $decoded[$key])
                || array_key_exists('vs', $decoded[$key])
                || array_key_exists('vo', $decoded[$key])
            )) {
                continue;
            }
            $inner = $this->flattenReplyMessages($decoded[$key]);
            if ($inner !== []) {
                return $inner;
            }
        }

        return [$decoded];
    }

    /**
     * @param array<string, mixed> $msg
     * @param array<string, true> $want
     * @param list<array<string, mixed>> $got
     */
    private function consumeReplyMessage(array $msg, array &$want, array &$got): void
    {
        if ($want === []) {
            return;
        }
        $rid = isset($msg['request_id']) ? (string) $msg['request_id'] : '';
        if ($rid !== '' && isset($want[$rid])) {
            $got[] = $msg;
            unset($want[$rid]);
            $this->log('WSS', 'matched exact request_id=' . $rid);

            return;
        }
        // Accept any object as one outstanding reply (gateway often omits/changes request_id)
        $fallbackId = (string) array_key_first($want);
        $got[] = $msg;
        unset($want[$fallbackId]);
        $this->log(
            'WSS',
            'matched FIFO want_id=' . $fallbackId
            . ' got_rid=' . ($rid !== '' ? $rid : '-')
            . ' keys=' . implode(',', array_keys($msg))
            . ' preview=' . substr((string) json_encode($msg, JSON_UNESCAPED_UNICODE), 0, 180)
        );
    }

    /**
     * Write Gen2 device schedules (lwm2mserver schedule/*).
     * One field per request; accept any gateway frame as ACK (writes often omit success/request_id).
     *
     * @param list<array<string, mixed>> $rules Editor rules from DeviceScheduleRules
     * @param int $previousMaxSlot Highest previously used slot (for clearing removed entries)
     * @return list<array<string, mixed>>
     */
    public function writeGen2Schedules(string $deviceId, array $rules, int $previousMaxSlot = -1): array
    {
        require_once __DIR__ . '/GardenaSmartSchedules.php';
        $parts = GardenaSmartSchedules::buildGen2WriteRequests($deviceId, $rules, $previousMaxSlot);
        $activeRaw = $parts['active'] ?? [];
        $clearRaw = $parts['clear'] ?? [];
        if ($activeRaw === [] && $clearRaw === []) {
            throw new RuntimeException('Keine gültigen Zeitplan-Einträge zum Speichern');
        }

        $toRequests = function (array $raw) use ($deviceId): array {
            $requests = [];
            foreach ($raw as $item) {
                $entity = $item['entity'] ?? [];
                $requests[] = $this->buildRequest(
                    (string) ($item['op'] ?? 'write'),
                    (string) ($entity['service'] ?? 'lwm2mserver'),
                    (string) ($entity['path'] ?? ''),
                    is_array($item['payload'] ?? null) ? $item['payload'] : null,
                    (string) ($entity['device'] ?? $deviceId)
                );
            }

            return $requests;
        };

        $activeReqs = $toRequests($activeRaw);
        $clearReqs = $toRequests($clearRaw);

        $this->log(
            'Schedule',
            'writeGen2 device=' . $deviceId
            . ' activeWrites=' . count($activeReqs)
            . ' clearWrites=' . count($clearReqs)
        );

        $previousTimeout = $this->timeoutSec;
        $this->timeoutSec = max($previousTimeout, 8);
        $allReplies = [];
        try {
            $allReplies = $this->exchangeWritesLoosely($activeReqs);
            $this->assertNoWriteFailures($allReplies);
            $this->log('Schedule', 'active writes done (acks=' . count($allReplies) . '/' . count($activeReqs) . ')');

            if ($clearReqs !== []) {
                try {
                    $this->exchangeFireAndForget($clearReqs, 2.5);
                    $this->log('Schedule', 'clear writes sent best-effort (' . count($clearReqs) . ')');
                } catch (Throwable $e) {
                    $this->log('Schedule', 'clear writes best-effort failed: ' . $e->getMessage());
                }
            }
        } finally {
            $this->timeoutSec = $previousTimeout;
        }

        return $allReplies;
    }

    /**
     * Send writes one-by-one; any JSON frame counts as ACK. Missing ACKs are tolerated (logged).
     * Reconnects periodically — long sessions on the Gardena gateway often drop.
     *
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>>
     */
    private function exchangeWritesLoosely(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $fp = $this->connect();
        $got = [];
        $needReconnect = false;
        try {
            foreach ($requests as $idx => $req) {
                // Fresh connection every 4 writes reduces gateway dropouts
                if ($needReconnect || ($idx > 0 && ($idx % 4) === 0)) {
                    $this->closeStream($fp);
                    usleep(250000);
                    $fp = $this->connect();
                    $needReconnect = false;
                }
                $path = (string) (($req['entity']['path'] ?? '') ?: '?');
                $this->log('WSS', sprintf('write %d/%d %s', $idx + 1, count($requests), $path));
                try {
                    $this->sendJson($fp, [$req]);
                } catch (Throwable $e) {
                    $this->log('WSS', 'send failed, reconnecting: ' . $e->getMessage());
                    $this->closeStream($fp);
                    usleep(300000);
                    $fp = $this->connect();
                    $this->sendJson($fp, [$req]);
                }
                $deadline = microtime(true) + min(3.5, (float) $this->timeoutSec);
                $acked = false;
                $idleAfterAck = null;
                while (microtime(true) < $deadline) {
                    $raw = $this->recvFrame($fp, max(0.15, $deadline - microtime(true)));
                    if ($raw === null) {
                        if ($acked && $idleAfterAck !== null && (microtime(true) - $idleAfterAck) > 0.25) {
                            break;
                        }
                        // Connection may have closed
                        $meta = is_resource($fp) ? @stream_get_meta_data($fp) : null;
                        if (is_array($meta) && !empty($meta['eof'])) {
                            $this->log('WSS', 'write connection EOF — reconnect for next field');
                            $needReconnect = true;
                            break;
                        }
                        continue;
                    }
                    $this->log('WSS', 'write frame preview=' . substr($raw, 0, 220));
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    foreach ($this->flattenReplyMessages($decoded) as $msg) {
                        if ($this->isExplicitWriteFailure($msg)) {
                            $detail = is_string($msg['error'] ?? null)
                                ? (string) $msg['error']
                                : 'Gateway lehnte Write ab (' . $path . ')';
                            throw new RuntimeException($detail);
                        }
                        // "op":"update" push after write is a valid ACK
                        $got[] = $msg;
                        $acked = true;
                        $idleAfterAck = microtime(true);
                    }
                }
                if (!$acked) {
                    $this->log('WSS', sprintf('write %d/%d no ACK (continuing) %s', $idx + 1, count($requests), $path));
                }
                if ($idx < count($requests) - 1) {
                    usleep(150000);
                }
            }
        } finally {
            $this->closeStream($fp);
            // Let websocketd / lwm2m settle before verify-discover
            usleep(800000);
        }

        return $got;
    }

    /** @param array<string, mixed> $msg */
    private function isExplicitWriteFailure(array $msg): bool
    {
        if (array_key_exists('success', $msg) && $msg['success'] === false) {
            return true;
        }
        if (array_key_exists('ok', $msg) && $msg['ok'] === false) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $replies
     */
    private function assertNoWriteFailures(array $replies): void
    {
        foreach ($replies as $msg) {
            if ($this->isExplicitWriteFailure($msg)) {
                $detail = is_string($msg['error'] ?? null)
                    ? (string) $msg['error']
                    : 'Gateway lehnte Zeitplan-Write ab';
                throw new RuntimeException($detail);
            }
        }
    }

    /**
     * Send requests and wait briefly for replies without failing on incomplete responses.
     *
     * @param list<array<string, mixed>> $requests
     */
    private function exchangeFireAndForget(array $requests, float $waitSec = 3.0): void
    {
        if ($requests === []) {
            return;
        }
        $fp = $this->connect();
        try {
            $this->sendJson($fp, $requests);
            $deadline = microtime(true) + max(0.5, $waitSec);
            while (microtime(true) < $deadline) {
                $raw = $this->recvFrame($fp, max(0.15, $deadline - microtime(true)));
                if ($raw === null) {
                    if (microtime(true) + 0.4 >= $deadline) {
                        break;
                    }
                    continue;
                }
                $this->log('WSS', 'forget frame preview=' . substr($raw, 0, 180));
            }
        } finally {
            $this->closeStream($fp);
        }
    }

    /**
     * Gen2 valve start (Dual/Water/Pipeline).
     *
     * @return list<array<string, mixed>>
     */
    public function startValveGen2(string $deviceId, int $valveId, int $durationSeconds): array
    {
        $durationSeconds = max(60, (int) (round($durationSeconds / 60) * 60));
        $req = $this->buildRequest(
            'execute',
            'lwm2mserver',
            'actuator/' . $valveId . '/start',
            ['as' => [self::COMMAND_SOURCE, (string) $durationSeconds]],
            $deviceId
        );

        return $this->exchange([$req]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stopValveGen2(string $deviceId, int $valveId): array
    {
        $req = $this->buildRequest(
            'execute',
            'lwm2mserver',
            'actuator/' . $valveId . '/stop',
            ['as' => [self::COMMAND_SOURCE]],
            $deviceId
        );

        return $this->exchange([$req]);
    }

    /**
     * Gen1: clear schedule_config blob (Power/Gen1 valve — write not fully supported).
     *
     * @return list<array<string, mixed>>
     */
    public function clearGen1ScheduleConfig(string $deviceId): array
    {
        $req = $this->buildRequest(
            'write',
            'lemonbeatd',
            'lemonbeat/0/schedule_config',
            ['vo' => ''],
            $deviceId
        );

        return $this->exchange([$req]);
    }

    /**
     * Gen1 Power: clear sun_schedule_config.
     *
     * @return list<array<string, mixed>>
     */
    public function clearGen1SunScheduleConfig(string $deviceId): array
    {
        $req = $this->buildRequest(
            'write',
            'lemonbeatd',
            'lemonbeat/0/sun_schedule_config',
            ['vo' => ''],
            $deviceId
        );

        return $this->exchange([$req]);
    }

    /**
     * Gen1 watering timer (Water Control / Irrigation / Pump style).
     *
     * @return list<array<string, mixed>>
     */
    public function setWateringTimerGen1(string $deviceId, int $valveId, int $seconds): array
    {
        $resource = 'lemonbeat/0/watering_timer_' . ($valveId + 1);
        $req = $this->buildRequest('write', 'lemonbeatd', $resource, ['vi' => $seconds], $deviceId);

        return $this->exchange([$req]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function setPowerTimer(string $deviceId, int $seconds): array
    {
        $req = $this->buildRequest(
            'write',
            'lemonbeatd',
            'lemonbeat/0/power_timer',
            ['vi' => $seconds],
            $deviceId
        );

        return $this->exchange([$req]);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    public function buildRequest(
        string $op,
        string $service,
        string $path,
        ?array $payload = null,
        ?string $deviceId = null
    ): array {
        $entity = [
            'path' => $path,
            'service' => $service,
        ];
        if ($deviceId !== null && $deviceId !== '') {
            $entity['device'] = $deviceId;
        }
        $req = [
            'entity' => $entity,
            'op' => $op,
            'request_id' => $this->uuid(),
        ];
        if ($payload !== null) {
            $req['payload'] = $payload;
        }

        return $req;
    }

    /** @return resource */
    private function connect()
    {
        $remote = 'ssl://' . $this->host . ':' . $this->port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => !$this->tlsInsecure,
                'verify_peer_name' => !$this->tlsInsecure,
                'allow_self_signed' => $this->tlsInsecure,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            ],
        ]);

        $lastErrno = 0;
        $lastErrstr = '';
        $fp = false;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            // Drain stale OpenSSL errors
            while (openssl_error_string() !== false) {
                // clear
            }
            $errno = 0;
            $errstr = '';
            $fp = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                max(5, $this->timeoutSec),
                STREAM_CLIENT_CONNECT,
                $ctx
            );
            if ($fp !== false) {
                break;
            }
            $lastErrno = $errno;
            $lastErrstr = $errstr;
            $ssl = [];
            while (($e = openssl_error_string()) !== false) {
                $ssl[] = $e;
            }
            $this->log(
                'WSS',
                sprintf(
                    'connect attempt %d/4 failed errno=%d err=%s ssl=%s',
                    $attempt,
                    $errno,
                    $errstr !== '' ? $errstr : '-',
                    $ssl !== [] ? implode(' | ', $ssl) : '-'
                )
            );
            usleep(350000 * $attempt);
        }
        if ($fp === false) {
            throw new RuntimeException(
                'TCP/TLS-Verbindung fehlgeschlagen: '
                . ($lastErrstr !== '' ? $lastErrstr : 'keine Details')
                . ' (' . $lastErrno . ')'
            );
        }
        stream_set_timeout($fp, $this->timeoutSec);
        stream_set_blocking($fp, true);

        $key = base64_encode(random_bytes(16));
        $auth = base64_encode('_:' . $this->password);
        $headers = [
            'GET / HTTP/1.1',
            'Host: ' . $this->host . ':' . $this->port,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
            'Authorization: Basic ' . $auth,
            '',
            '',
        ];
        fwrite($fp, implode("\r\n", $headers));

        $response = '';
        while (($line = fgets($fp)) !== false) {
            $response .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }
        if (!preg_match('#^HTTP/1\.[01] 101#', $response)) {
            $this->closeStream($fp);
            $this->log('WSS', 'handshake failed: ' . trim(strtok($response, "\r\n") ?: 'keine Antwort'));
            throw new RuntimeException('WebSocket-Handshake fehlgeschlagen: ' . trim(strtok($response, "\r\n") ?: 'keine Antwort'));
        }
        $this->log('WSS', 'connected wss://' . $this->host . ':' . $this->port);

        return $fp;
    }

    /**
     * Safe fclose for PHP 8+: closing an already-closed stream throws TypeError (@ does not suppress it).
     *
     * @param resource|null $fp
     */
    private function closeStream(&$fp): void
    {
        if (!is_resource($fp)) {
            $fp = null;

            return;
        }
        try {
            fclose($fp);
        } catch (Throwable) {
            // already closed / invalid
        }
        $fp = null;
    }

    /** @param resource $fp */
    private function sendJson($fp, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('JSON-Encode fehlgeschlagen');
        }
        $this->sendFrame($fp, $payload);
    }

    /** @param resource $fp */
    private function sendFrame($fp, string $payload): void
    {
        $len = strlen($payload);
        $frame = chr(0x81); // text, FIN
        $maskBit = 0x80;
        if ($len <= 125) {
            $frame .= chr($maskBit | $len);
        } elseif ($len <= 65535) {
            $frame .= chr($maskBit | 126) . pack('n', $len);
        } else {
            // 64-bit length: high 32 bits zero, low 32 bits length
            $frame .= chr($maskBit | 127) . pack('NN', 0, $len);
        }
        $mask = random_bytes(4);
        $frame .= $mask;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        $frame .= $masked;
        $written = fwrite($fp, $frame);
        if ($written === false || $written < strlen($frame)) {
            throw new RuntimeException('WebSocket-Senden fehlgeschlagen');
        }
    }

    /**
     * @param resource $fp
     */
    private function recvFrame($fp, float $timeoutSec): ?string
    {
        $read = [$fp];
        $write = null;
        $except = null;
        $sec = (int) floor($timeoutSec);
        $usec = (int) (($timeoutSec - $sec) * 1_000_000);
        $n = stream_select($read, $write, $except, $sec, $usec);
        if ($n === false || $n === 0) {
            return null;
        }

        $header = $this->readExact($fp, 2);
        if ($header === null) {
            return null;
        }
        $b1 = ord($header[0]);
        $b2 = ord($header[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $len = $b2 & 0x7F;
        if ($len === 126) {
            $ext = $this->readExact($fp, 2);
            if ($ext === null) {
                return null;
            }
            $arr = unpack('n', $ext);
            $len = $arr[1] ?? 0;
        } elseif ($len === 127) {
            $ext = $this->readExact($fp, 8);
            if ($ext === null) {
                return null;
            }
            $arr = unpack('N2', $ext);
            $len = (int) (($arr[2] ?? 0));
        }
        $mask = null;
        if ($masked) {
            $mask = $this->readExact($fp, 4);
            if ($mask === null) {
                return null;
            }
        }
        $payload = $len > 0 ? $this->readExact($fp, $len) : '';
        if ($payload === null) {
            return null;
        }
        if ($mask !== null) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }
        if ($opcode === 0x8) {
            return null;
        }
        if ($opcode === 0x9) {
            // ping → pong
            $this->sendControl($fp, 0xA, $payload);

            return $this->recvFrame($fp, $timeoutSec);
        }
        if ($opcode !== 0x1 && $opcode !== 0x0) {
            return null;
        }

        return $payload;
    }

    /** @param resource $fp */
    private function sendControl($fp, int $opcode, string $payload): void
    {
        $len = strlen($payload);
        $frame = chr(0x80 | ($opcode & 0x0F)) . chr(0x80 | $len) . random_bytes(4);
        $mask = substr($frame, -4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($fp, substr($frame, 0, 2) . $mask . $masked);
    }

    /** @param resource $fp */
    private function readExact($fp, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($fp, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    return null;
                }
                if (feof($fp)) {
                    return null;
                }
                usleep(10000);
                continue;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
