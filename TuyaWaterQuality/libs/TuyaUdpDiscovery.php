<?php

declare(strict_types=1);

/**
 * Tuya-UDP-Discovery (Ports 6666/6667/7000) + TCP-Probe Port 6668.
 */
final class TuyaUdpDiscovery
{
    private const CMD_REQ_DEVINFO = 0x00000025;
    private const PREFIX_6699 = 0x00006699;
    private const SUFFIX_6699 = 0x00009966;
    private const PREFIX_55AA = 0x000055AA;
    private const SUFFIX_55AA = 0x0000AA55;

    private static function udpKey(): string
    {
        return md5('yGAdlopoPVldABfn', true);
    }

    /**
     * @return list<array{id: string, ip: string, version: string}>
     */
    public static function scan(int $timeoutSec = 5, ?string $hintIp = null): array
    {
        if (!function_exists('socket_create')) {
            return [];
        }

        $hintIp = trim((string) $hintIp);
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return [];
        }

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        if (defined('SO_REUSEADDR')) {
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        }
        @socket_bind($socket, '0.0.0.0', 0);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 200000]);

        $targets = self::buildTargets($hintIp);
        self::sendProbes($socket, $targets, $hintIp);

        $found = [];
        $deadline = microtime(true) + max(2, $timeoutSec);
        while (microtime(true) < $deadline) {
            $buf = '';
            $from = '';
            $port = 0;
            $bytes = @socket_recvfrom($socket, $buf, 8192, 0, $from, $port);
            if ($bytes === false || $bytes <= 0 || $buf === '') {
                continue;
            }

            $entry = self::parseDatagram($buf, $from !== '' ? $from : $hintIp);
            if ($entry === null) {
                continue;
            }

            $found[$entry['id']] = $entry;
        }

        socket_close($socket);

        return array_values($found);
    }

    public static function tcpPortOpen(string $host, int $port = 6668, int $timeoutSec = 2): bool
    {
        $host = trim($host);
        if ($host === '' || !function_exists('socket_create')) {
            return false;
        }

        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return false;
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeoutSec, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeoutSec, 'usec' => 0]);

        $ok = @socket_connect($socket, $host, $port);
        socket_close($socket);

        return $ok === true;
    }

    /**
     * @return list<array{host: string, port: int}>
     */
    private static function buildTargets(string $hintIp): array
    {
        $targets = [
            ['host' => '255.255.255.255', 'port' => 6666],
            ['host' => '255.255.255.255', 'port' => 6667],
            ['host' => '255.255.255.255', 'port' => 7000],
        ];

        if ($hintIp !== '' && filter_var($hintIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $hintIp);
            if (count($parts) === 4) {
                $subnetBroadcast = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.255';
                foreach ([6666, 6667, 7000] as $port) {
                    $targets[] = ['host' => $subnetBroadcast, 'port' => $port];
                    $targets[] = ['host' => $hintIp, 'port' => $port];
                }
            }
        }

        return $targets;
    }

    /**
     * @param list<array{host: string, port: int}> $targets
     */
    private static function sendProbes($socket, array $targets, string $hintIp): void
    {
        $plainPayload = json_encode([
            'from' => 'app',
            'id' => 'scan',
            'method' => 'discovery',
            'params' => [],
            't' => (int) (microtime(true) * 1000),
            'version' => '1.0',
        ], JSON_UNESCAPED_UNICODE);

        if (!is_string($plainPayload)) {
            return;
        }

        $sent = [];
        foreach ($targets as $target) {
            $key = $target['host'] . ':' . $target['port'];
            if (isset($sent[$key])) {
                continue;
            }
            $sent[$key] = true;

            if ($target['port'] === 6666) {
                @socket_sendto($socket, $plainPayload, strlen($plainPayload), 0, $target['host'], $target['port']);
                continue;
            }

            if ($target['port'] === 6667) {
                $encrypted = self::encryptUdpLegacy($plainPayload);
                if ($encrypted !== '') {
                    @socket_sendto($socket, $encrypted, strlen($encrypted), 0, $target['host'], $target['port']);
                }
                continue;
            }

            if ($target['port'] === 7000) {
                $clientIp = $hintIp !== '' ? $hintIp : '255.255.255.255';
                $solicit = json_encode([
                    'from' => 'app',
                    'ip' => $clientIp,
                ], JSON_UNESCAPED_UNICODE);
                if (!is_string($solicit)) {
                    continue;
                }
                $packet = self::pack6699Discovery(self::CMD_REQ_DEVINFO, $solicit);
                if ($packet !== '') {
                    @socket_sendto($socket, $packet, strlen($packet), 0, $target['host'], $target['port']);
                }
            }
        }
    }

    /**
     * @return null|array{id: string, ip: string, version: string}
     */
    private static function parseDatagram(string $buf, string $fromIp): ?array
    {
        $decoded = json_decode($buf, true);
        if (!is_array($decoded)) {
            $plain = self::decryptUdp($buf);
            if ($plain === null) {
                return null;
            }
            $decoded = json_decode($plain, true);
            if (!is_array($decoded)) {
                return null;
            }
        }

        $id = (string) ($decoded['gwId'] ?? $decoded['devId'] ?? $decoded['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $ip = (string) ($decoded['ip'] ?? $fromIp);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = $fromIp;
        }

        $version = (string) ($decoded['version'] ?? $decoded['ver'] ?? '?');

        return [
            'id' => $id,
            'ip' => $ip,
            'version' => $version,
        ];
    }

    private static function decryptUdp(string $msg): ?string
    {
        if (strlen($msg) < 16) {
            return null;
        }

        $prefix = unpack('N', substr($msg, 0, 4))[1] ?? 0;
        if ($prefix === self::PREFIX_55AA && strlen($msg) > 28) {
            $encrypted = substr($msg, 20, -8);
            $plain = openssl_decrypt($encrypted, 'aes-128-ecb', self::udpKey(), OPENSSL_RAW_DATA);

            return is_string($plain) ? rtrim($plain, "\0") : null;
        }

        if ($prefix === self::PREFIX_6699) {
            return self::decrypt6699Udp($msg);
        }

        $plain = openssl_decrypt($msg, 'aes-128-ecb', self::udpKey(), OPENSSL_RAW_DATA);

        return is_string($plain) ? rtrim($plain, "\0") : null;
    }

    private static function decrypt6699Udp(string $msg): ?string
    {
        if (strlen($msg) < 38) {
            return null;
        }

        $headerLen = 18;
        $aad = substr($msg, 4, $headerLen - 4);
        $payloadLength = unpack('N', substr($msg, 14, 4))[1] ?? 0;
        $blob = substr($msg, $headerLen, $payloadLength);
        if ($blob === false || strlen($blob) < 28) {
            return null;
        }

        $iv = substr($blob, 0, 12);
        $tag = substr($blob, -16);
        $cipherText = substr($blob, 12, -16);
        $plain = openssl_decrypt($cipherText, 'aes-128-gcm', self::udpKey(), OPENSSL_RAW_DATA, $iv, $tag, $aad);

        return is_string($plain) ? rtrim($plain, "\0") : null;
    }

    private static function encryptUdpLegacy(string $plain): string
    {
        $padded = self::pkcs7Pad($plain, 16);
        $encrypted = openssl_encrypt($padded, 'aes-128-ecb', self::udpKey(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        if (!is_string($encrypted)) {
            return '';
        }

        return $encrypted;
    }

    private static function pack6699Discovery(int $command, string $plaintextPayload): string
    {
        $seqNo = 1;
        $msgLen = strlen($plaintextPayload) + 28;

        $header = pack('N', self::PREFIX_6699)
            . pack('n', 0)
            . pack('N', $seqNo)
            . pack('N', $command)
            . pack('N', $msgLen);

        $aad = substr($header, 4);
        $iv = substr(str_pad((string) (int) (microtime(true) * 10000000), 12, '0', STR_PAD_LEFT), 0, 12);
        $tag = '';
        $cipherText = openssl_encrypt($plaintextPayload, 'aes-128-gcm', self::udpKey(), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if (!is_string($cipherText)) {
            return '';
        }

        return $header . $iv . $cipherText . $tag . pack('N', self::SUFFIX_6699);
    }

    private static function pkcs7Pad(string $data, int $blockSize): string
    {
        $pad = $blockSize - (strlen($data) % $blockSize);

        return $data . str_repeat(chr($pad), $pad);
    }
}
