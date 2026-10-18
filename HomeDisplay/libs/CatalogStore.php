<?php

declare(strict_types=1);

/**
 * Gemeinsamer Katalog für Layouts und Konfigurationen.
 * Liegt außerhalb der Instanzen, damit jede Panel-Instanz daraus wählen kann.
 */
final class HomeDisplayCatalogStore
{
    private const ICONS = ['energy', 'temperature', 'light', 'shutter', 'pump', 'valve', 'alarm'];

    public static function path(): string
    {
        $dir = rtrim(IPS_GetKernelDir(), '\\/') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'HomeDisplay';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'catalog.json';
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            $empty = self::emptyCatalog();
            self::save($empty);
            return $empty;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return self::emptyCatalog();
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return self::emptyCatalog();
        }
        $data['layouts'] = is_array($data['layouts'] ?? null) ? $data['layouts'] : [];
        $data['configs'] = is_array($data['configs'] ?? null) ? $data['configs'] : [];
        $data['contract'] = 1;
        return $data;
    }

    /** @param array<string, mixed> $catalog */
    public static function save(array $catalog): void
    {
        $path = self::path();
        $catalog['contract'] = 1;
        $json = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Katalog konnte nicht gespeichert werden.');
        }
        $fh = fopen($path, 'cb');
        if ($fh === false) {
            throw new RuntimeException('Katalogdatei nicht beschreibbar.');
        }
        flock($fh, LOCK_EX);
        ftruncate($fh, 0);
        fwrite($fh, $json);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** @return array<string, mixed> */
    public static function emptyCatalog(): array
    {
        return [
            'contract' => 1,
            'layouts' => [[
                'id' => 'layout-start',
                'name' => 'Start',
                'version' => 1,
                'published' => true,
                'columns' => 2,
                'pages' => [[
                    'id' => 'start',
                    'title' => 'Start',
                    'tiles' => [],
                ]],
            ]],
            'configs' => [[
                'id' => 'config-standard',
                'name' => 'Standard',
                'version' => 1,
                'published' => true,
                'idleMode' => 0,
                'nightDim' => true,
                'dayBrightness' => 70,
                'nightBrightness' => 15,
                'nightFrom' => 22,
                'nightTo' => 6,
                'stateIntervalSec' => 3,
                'idleStateIntervalSec' => 15,
                'maintenanceFrom' => 3,
                'maintenanceTo' => 5,
            ]],
        ];
    }

    /** @param array<string, mixed> $layout */
    public static function validateLayout(array $layout): void
    {
        $name = trim((string) ($layout['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Layout ohne Namen.');
        }
        $pages = $layout['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new InvalidArgumentException('Layout ohne Seite.');
        }
        foreach ($pages as $page) {
            if (!is_array($page)) {
                throw new InvalidArgumentException('Ungültige Seite.');
            }
            $tiles = $page['tiles'] ?? [];
            if (!is_array($tiles)) {
                throw new InvalidArgumentException('Ungültige Kacheln.');
            }
            $seen = [];
            foreach ($tiles as $tile) {
                if (!is_array($tile)) {
                    throw new InvalidArgumentException('Ungültige Kachel.');
                }
                $tileId = (string) ($tile['tileId'] ?? '');
                if ($tileId === '' || isset($seen[$tileId])) {
                    throw new InvalidArgumentException('Jede Kachel braucht eine eigene tileId.');
                }
                $seen[$tileId] = true;
                $icon = (string) ($tile['icon'] ?? '');
                if (!in_array($icon, self::ICONS, true)) {
                    throw new InvalidArgumentException('Unbekanntes Icon: ' . $icon);
                }
                foreach (['col', 'row'] as $key) {
                    if (!isset($tile[$key]) || (int) $tile[$key] < 0) {
                        throw new InvalidArgumentException('Kachel ohne Rasterzelle.');
                    }
                }
                if (isset($tile['x']) || isset($tile['y'])) {
                    throw new InvalidArgumentException('Freie Pixelkoordinaten sind nicht erlaubt.');
                }
            }
        }
    }

    /** @param array<string, mixed> $layout @return array<string, mixed> */
    public static function publicLayout(array $layout): array
    {
        $pages = [];
        foreach ($layout['pages'] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $tiles = [];
            foreach ($page['tiles'] ?? [] as $tile) {
                if (!is_array($tile)) {
                    continue;
                }
                $public = [
                    'tileId' => (string) $tile['tileId'],
                    'icon' => (string) $tile['icon'],
                    'title' => (string) ($tile['title'] ?? ''),
                    'col' => (int) $tile['col'],
                    'row' => (int) $tile['row'],
                    'colSpan' => max(1, (int) ($tile['colSpan'] ?? 1)),
                    'rowSpan' => max(1, (int) ($tile['rowSpan'] ?? 1)),
                ];
                $actionId = trim((string) ($tile['actionId'] ?? ''));
                if ($actionId !== '') {
                    $public['actionId'] = $actionId;
                    $public['confirm'] = (bool) ($tile['confirm'] ?? false);
                }
                $tiles[] = $public;
            }
            $pages[] = [
                'id' => (string) ($page['id'] ?? 'page'),
                'title' => (string) ($page['title'] ?? ''),
                'tiles' => $tiles,
            ];
        }
        return [
            'id' => (string) $layout['id'],
            'name' => (string) $layout['name'],
            'version' => (int) $layout['version'],
            'columns' => max(1, (int) ($layout['columns'] ?? 2)),
            'pages' => $pages,
        ];
    }

    /** @param array<string, mixed> $catalog @return array<string, mixed>|null */
    public static function find(array $catalog, string $bucket, string $id): ?array
    {
        foreach ($catalog[$bucket] ?? [] as $item) {
            if (is_array($item) && (string) ($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return null;
    }
}
