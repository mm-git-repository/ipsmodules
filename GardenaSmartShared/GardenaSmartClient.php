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
            if (($msg['success'] ?? null) !== true) {
                $this->log('WSS', 'discover reply failed: ' . json_encode($msg, JSON_UNESCAPED_UNICODE));
                continue;
            }
            $payload = $msg['payload'] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            foreach ($payload as $deviceId => $deviceData) {
                if (is_string($deviceId) && is_array($deviceData)) {
                    $devices[$deviceId] = $deviceData;
                }
            }
        }
        $this->log('WSS', 'discover done — ' . count($devices) . ' device(s)');

        return ['devices' => $devices, 'raw' => $replies];
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
            fclose($fp);
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
            fclose($fp);
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
        while ($want !== [] && microtime(true) < $deadline) {
            $raw = $this->recvFrame($fp, max(0.2, $deadline - microtime(true)));
            if ($raw === null) {
                continue;
            }
            $rawCount++;
            $buffer .= $raw;
            $decoded = json_decode($buffer, true);
            if (!is_array($decoded)) {
                $this->log('WSS', 'recv non-json/fragment len=' . strlen($buffer) . ' preview=' . substr($buffer, 0, 160));
                if (strlen($buffer) > 1_000_000) {
                    $buffer = '';
                }
                continue;
            }
            $buffer = '';
            $list = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($list as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $rid = isset($msg['request_id']) ? (string) $msg['request_id'] : '';
                if ($rid !== '' && isset($want[$rid])) {
                    $got[] = $msg;
                    unset($want[$rid]);
                    continue;
                }
                // Gateway sometimes omits / remaps request_id — accept success replies FIFO
                if (array_key_exists('success', $msg) && $want !== []) {
                    $fallbackId = (string) array_key_first($want);
                    $this->log(
                        'WSS',
                        'matched reply FIFO (want_id=' . $fallbackId
                        . ' got_rid=' . ($rid !== '' ? $rid : '-')
                        . ' success=' . json_encode($msg['success'] ?? null) . ')'
                    );
                    $got[] = $msg;
                    unset($want[$fallbackId]);
                    continue;
                }
                $this->log(
                    'WSS',
                    'unmatched reply rid=' . ($rid !== '' ? $rid : '-')
                    . ' success=' . json_encode($msg['success'] ?? null)
                    . ' keys=' . implode(',', array_keys($msg))
                );
            }
        }
        if ($want !== []) {
            $this->log(
                'WSS',
                'TIMEOUT got=' . count($got) . '/' . $pending
                . ' frames=' . $rawCount
                . ' missing=' . implode(',', array_keys($want))
            );
            throw new RuntimeException(
                'Timeout: nicht alle Antworten vom Gateway erhalten ('
                . count($got) . '/' . $pending . ')'
            );
        }

        return $got;
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
     * Write Gen2 device schedules (lwm2mserver schedule/*).
     * Strategy: one WSS round-trip per slot (all fields batched), then best-effort clear of unused slots.
     *
     * @param list<array<string, mixed>> $rules Editor rules from DeviceScheduleRules
     * @return list<array<string, mixed>>
     */
    public function writeGen2Schedules(string $deviceId, array $rules): array
    {
        require_once __DIR__ . '/GardenaSmartSchedules.php';
        $parts = GardenaSmartSchedules::buildGen2WriteRequests($deviceId, $rules);
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

        // Group by schedule slot → one batch per slot (typically 5 fields)
        $bySlot = [];
        foreach ($activeReqs as $req) {
            $path = (string) ($req['entity']['path'] ?? '');
            if (preg_match('#^schedule/(\d+)/#', $path, $m)) {
                $bySlot[$m[1]][] = $req;
            } else {
                $bySlot['_'][] = $req;
            }
        }

        $this->log(
            'Schedule',
            'writeGen2 device=' . $deviceId
            . ' slots=' . count($bySlot)
            . ' activeWrites=' . count($activeReqs)
            . ' clearWrites=' . count($clearReqs)
        );

        $previousTimeout = $this->timeoutSec;
        $this->timeoutSec = max($previousTimeout, 20);
        $allReplies = [];
        try {
            $slotIndex = 0;
            foreach ($bySlot as $slot => $reqs) {
                $slotIndex++;
                $this->log('Schedule', sprintf('slot %s batch %d/%d fields=%d', (string) $slot, $slotIndex, count($bySlot), count($reqs)));
                $replies = $this->exchange($reqs);
                foreach ($replies as $msg) {
                    if (($msg['success'] ?? null) !== true) {
                        $detail = is_string($msg['error'] ?? null)
                            ? (string) $msg['error']
                            : 'Gateway lehnte Zeitplan-Write ab (Slot ' . $slot . ')';
                        $this->log('Schedule', 'slot write failed: ' . $detail);
                        throw new RuntimeException($detail);
                    }
                    $allReplies[] = $msg;
                }
                if ($slotIndex < count($bySlot)) {
                    usleep(120000);
                }
            }
            $this->log('Schedule', 'active writes OK (' . count($allReplies) . ')');

            if ($clearReqs !== []) {
                // Do not block save on clearing unused slots — send and briefly drain
                try {
                    $this->exchangeFireAndForget($clearReqs, 3.0);
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
                if ($this->recvFrame($fp, max(0.15, $deadline - microtime(true))) === null) {
                    // idle — stop early if nothing more arrives
                    if (microtime(true) + 0.4 >= $deadline) {
                        break;
                    }
                }
            }
        } finally {
            fclose($fp);
        }
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
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeoutSec,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($fp === false) {
            throw new RuntimeException('TCP/TLS-Verbindung fehlgeschlagen: ' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($fp, $this->timeoutSec);

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
            fclose($fp);
            $this->log('WSS', 'handshake failed: ' . trim(strtok($response, "\r\n") ?: 'keine Antwort'));
            throw new RuntimeException('WebSocket-Handshake fehlgeschlagen: ' . trim(strtok($response, "\r\n") ?: 'keine Antwort'));
        }
        $this->log('WSS', 'connected wss://' . $this->host . ':' . $this->port);

        return $fp;
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
