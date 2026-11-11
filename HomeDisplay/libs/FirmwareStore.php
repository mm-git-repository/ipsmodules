<?php

declare(strict_types=1);

/**
 * Programme der Waveshare-Anzeige: das mit dem Modul gelieferte Release (firmware/release) und
 * hochgeladene Releases (config/HomeDisplay/firmware). Jedes Release besteht aus der Programmdatei
 * und der Beschreibung firmware.json (Version, SHA-256, Signatur), wie sie tools/release.ps1 schreibt.
 */
final class HomeDisplayFirmwareStore
{
    /** Größe der OTA-Partition. */
    public const MAX_BYTES = 3145728;
    public const DEFAULT_CHECK_MINUTES = 60;

    public static function dir(): string
    {
        $dir = rtrim(IPS_GetKernelDir(), '\\/') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'HomeDisplay'
            . DIRECTORY_SEPARATOR . 'firmware';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function moduleDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'firmware';
    }

    /** @return array{offered: string, checkMinutes: int, uploads: list<array<string, mixed>>} */
    private static function index(): array
    {
        $path = self::dir() . DIRECTORY_SEPARATOR . 'index.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $data = is_array($data) ? $data : [];
        $offered = (string) ($data['offered'] ?? 'latest');
        if ($offered !== 'latest' && !self::isVersion($offered)) {
            $offered = 'latest';
        }
        $uploads = [];
        foreach ((array) ($data['uploads'] ?? []) as $entry) {
            if (is_array($entry) && preg_match('/^fw-[a-f0-9]{12}$/', (string) ($entry['id'] ?? '')) && self::isVersion((string) ($entry['version'] ?? ''))) {
                $uploads[] = $entry;
            }
        }
        return [
            'offered' => $offered,
            'checkMinutes' => self::clampMinutes((int) ($data['checkMinutes'] ?? self::DEFAULT_CHECK_MINUTES)),
            'uploads' => $uploads,
        ];
    }

    /** @param array<string, mixed> $index */
    private static function saveIndex(array $index): void
    {
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents(self::dir() . DIRECTORY_SEPARATOR . 'index.json', $json, LOCK_EX) === false) {
            throw new RuntimeException('Firmware-Verzeichnis ist nicht beschreibbar.');
        }
    }

    private static function isVersion(string $value): bool
    {
        return (bool) preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,4}$/', $value);
    }

    private static function clampMinutes(int $minutes): int
    {
        return max(5, min(1440, $minutes > 0 ? $minutes : self::DEFAULT_CHECK_MINUTES));
    }

    /** Wie oft eine Anzeige auf ein neues Programm prüft. */
    public static function checkMinutes(): int
    {
        return self::index()['checkMinutes'];
    }

    /** @return array{offered: string, checkMinutes: int} */
    public static function settings(): array
    {
        $index = self::index();
        return ['offered' => $index['offered'], 'checkMinutes' => $index['checkMinutes']];
    }

    /** @param string $offered „latest“ oder die höchste Version, die angeboten wird */
    public static function saveSettings(string $offered, int $checkMinutes): void
    {
        $offered = trim($offered);
        if ($offered !== 'latest' && !self::isVersion($offered)) {
            throw new InvalidArgumentException('Angebotene Version ist ungültig.');
        }
        if ($checkMinutes < 5 || $checkMinutes > 1440) {
            throw new InvalidArgumentException('Die Prüfung ist zwischen 5 und 1440 Minuten einstellbar.');
        }
        $index = self::index();
        $index['offered'] = $offered;
        $index['checkMinutes'] = $checkMinutes;
        self::saveIndex($index);
    }

    /** @return list<string> */
    private static function publicKeys(): array
    {
        $keys = [];
        foreach (glob(self::moduleDir() . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'ota-public*.pem') ?: [] as $file) {
            $pem = (string) file_get_contents($file);
            if ($pem !== '') {
                $keys[] = $pem;
            }
        }
        return $keys;
    }

    private static function signatureValid(string $data, string $signatureB64): bool
    {
        $signature = base64_decode($signatureB64, true);
        if ($signature === false || $signature === '') {
            return false;
        }
        if (!function_exists('openssl_verify')) {
            return true;
        }
        foreach (self::publicKeys() as $pem) {
            $key = openssl_pkey_get_public($pem);
            if ($key !== false && openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $descriptor */
    private static function check(string $data, array $descriptor): string
    {
        if (!self::isVersion((string) ($descriptor['version'] ?? ''))) {
            return 'Die Version in firmware.json fehlt oder ist ungültig (erwartet 0.6.78).';
        }
        $requires = (string) ($descriptor['requires'] ?? '');
        if ($requires !== '' && !self::isVersion($requires)) {
            return 'Das Feld requires in firmware.json ist ungültig.';
        }
        $size = strlen($data);
        if ($size < 100000 || $size > self::MAX_BYTES) {
            return 'Die Programmdatei hat eine unzulässige Größe (' . $size . ' Byte, höchstens ' . self::MAX_BYTES . ').';
        }
        if ($data[0] !== "\xE9") {
            return 'Das ist keine ESP32-Programmdatei (homedisplay.bin erwartet).';
        }
        if (isset($descriptor['size']) && (int) $descriptor['size'] !== $size) {
            return 'Die Größe passt nicht zu firmware.json. Beide Dateien müssen aus demselben Release stammen.';
        }
        if (!hash_equals(hash('sha256', $data), strtolower((string) ($descriptor['sha256'] ?? '')))) {
            return 'Die Prüfsumme passt nicht zu firmware.json. Beide Dateien müssen aus demselben Release stammen.';
        }
        if (!self::signatureValid($data, (string) ($descriptor['signature'] ?? ''))) {
            return 'Die Signatur gehört zu keinem bekannten Schlüssel. Das Release muss mit release.ps1 signiert sein.';
        }
        return '';
    }

    /** Mitgeliefertes Release und die Zwischenversion für Anzeigen, die den neuen Schlüssel noch nicht kennen. @return list<array<string, mixed>> */
    private static function bundledAll(): array
    {
        $base = self::moduleDir() . DIRECTORY_SEPARATOR . 'release';
        $list = [];
        $current = self::bundledAt($base, 'bundled');
        if ($current !== null) {
            $list[] = $current;
        }
        $bridge = self::bundledAt($base . DIRECTORY_SEPARATOR . 'bridge', 'bridge');
        if ($bridge !== null) {
            $list[] = $bridge;
        }
        return $list;
    }

    /** @return array<string, mixed>|null */
    private static function bundledAt(string $dir, string $id): ?array
    {
        $binary = $dir . DIRECTORY_SEPARATOR . 'homedisplay.bin';
        $descriptorFile = $dir . DIRECTORY_SEPARATOR . 'firmware.json';
        if (!is_file($binary) || !is_file($descriptorFile)) {
            return null;
        }
        $descriptor = json_decode((string) file_get_contents($descriptorFile), true);
        if (!is_array($descriptor)) {
            return null;
        }
        $data = (string) file_get_contents($binary);
        $error = self::check($data, $descriptor);
        return [
            'id' => $id,
            'source' => 'bundled',
            'version' => (string) ($descriptor['version'] ?? ''),
            'requires' => (string) ($descriptor['requires'] ?? ''),
            'sha256' => strtolower((string) ($descriptor['sha256'] ?? '')),
            'signature' => (string) ($descriptor['signature'] ?? ''),
            'size' => strlen($data),
            'built' => (string) ($descriptor['built'] ?? ''),
            'uploaded' => 0,
            'note' => '',
            'path' => $binary,
            'valid' => $error === '',
            'error' => $error,
        ];
    }

    /** Alle Releases, höchste Version zuerst. @return list<array<string, mixed>> */
    public static function releases(): array
    {
        $list = [];
        foreach (self::index()['uploads'] as $entry) {
            $path = self::dir() . DIRECTORY_SEPARATOR . $entry['id'] . '.bin';
            $present = is_file($path) && filesize($path) === (int) ($entry['size'] ?? -1);
            $list[] = [
                'id' => (string) $entry['id'],
                'source' => 'upload',
                'version' => (string) $entry['version'],
                'requires' => (string) ($entry['requires'] ?? ''),
                'sha256' => (string) ($entry['sha256'] ?? ''),
                'signature' => (string) ($entry['signature'] ?? ''),
                'size' => (int) ($entry['size'] ?? 0),
                'built' => (string) ($entry['built'] ?? ''),
                'uploaded' => (int) ($entry['uploaded'] ?? 0),
                'note' => (string) ($entry['note'] ?? ''),
                'path' => $path,
                'valid' => $present,
                'error' => $present ? '' : 'Programmdatei fehlt',
            ];
        }
        foreach (self::bundledAll() as $bundled) {
            $list[] = $bundled;
        }
        usort($list, static function (array $a, array $b): int {
            $order = version_compare((string) $b['version'], (string) $a['version']);
            return $order !== 0 ? $order : ($a['source'] === 'upload' ? -1 : 1);
        });
        return $list;
    }

    /**
     * Das Programm, das einer Anzeige mit dieser Version angeboten wird: das höchste gültige Release bis zur
     * eingestellten Obergrenze, dessen Voraussetzung (requires) die Anzeige erfüllt. Eine Anzeige mit alter
     * Signatur bekommt so zuerst das Übergangs-Release.
     *
     * @return array<string, mixed>|null
     */
    public static function pick(string $installed): ?array
    {
        $installed = self::isVersion($installed) ? $installed : '0.0.0';
        $limit = self::index()['offered'];
        foreach (self::releases() as $release) {
            if (!$release['valid']) {
                continue;
            }
            if ($limit !== 'latest' && version_compare((string) $release['version'], $limit, '>')) {
                continue;
            }
            if ($release['requires'] !== '' && version_compare($installed, (string) $release['requires'], '<')) {
                continue;
            }
            return $release;
        }
        return null;
    }

    /** @param array<string, mixed> $descriptor @return array<string, mixed> */
    public static function add(string $data, array $descriptor, string $note = ''): array
    {
        $error = self::check($data, $descriptor);
        if ($error !== '') {
            throw new InvalidArgumentException($error);
        }
        $hash = hash('sha256', $data);
        $id = 'fw-' . substr($hash, 0, 12);
        $version = (string) $descriptor['version'];
        $index = self::index();
        $kept = [];
        foreach ($index['uploads'] as $entry) {
            if ((string) $entry['version'] === $version || (string) $entry['id'] === $id) {
                @unlink(self::dir() . DIRECTORY_SEPARATOR . $entry['id'] . '.bin');
                continue;
            }
            $kept[] = $entry;
        }
        $path = self::dir() . DIRECTORY_SEPARATOR . $id . '.bin';
        if (file_put_contents($path, $data, LOCK_EX) === false) {
            throw new RuntimeException('Programmdatei konnte nicht gespeichert werden.');
        }
        $entry = [
            'id' => $id,
            'version' => $version,
            'requires' => (string) ($descriptor['requires'] ?? ''),
            'sha256' => $hash,
            'signature' => (string) $descriptor['signature'],
            'size' => strlen($data),
            'built' => (string) ($descriptor['built'] ?? ''),
            'uploaded' => time(),
            'note' => mb_substr(trim($note), 0, 120),
        ];
        $kept[] = $entry;
        $index['uploads'] = $kept;
        self::saveIndex($index);
        return $entry;
    }

    public static function remove(string $id): void
    {
        $index = self::index();
        $kept = [];
        $found = false;
        foreach ($index['uploads'] as $entry) {
            if ((string) $entry['id'] === $id) {
                $found = true;
                @unlink(self::dir() . DIRECTORY_SEPARATOR . $entry['id'] . '.bin');
                continue;
            }
            $kept[] = $entry;
        }
        if (!$found) {
            throw new InvalidArgumentException('Nur hochgeladene Programme lassen sich löschen.');
        }
        $index['uploads'] = $kept;
        self::saveIndex($index);
    }

    /** Für die Oberfläche, ohne Pfad und Signatur. @param array<string, mixed> $release @return array<string, mixed> */
    public static function describe(array $release): array
    {
        unset($release['path'], $release['signature']);
        return $release;
    }
}
