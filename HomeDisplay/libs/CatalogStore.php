<?php

declare(strict_types=1);

/**
 * Gemeinsamer Katalog für Layouts und Konfigurationen.
 * Liegt außerhalb der Instanzen, damit jede Panel-Instanz daraus wählen kann.
 */
require_once __DIR__ . '/PanelSchema.php';

final class HomeDisplayCatalogStore
{

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
    public static function validateLayout(array $layout, bool $publish = false): void
    {
        HomeDisplayPanelSchema::validateLayout($layout, $publish);
    }

    /** @param array<string, mixed> $layout @return array<string, mixed> */
    public static function publicLayout(array $layout): array
    {
        return HomeDisplayPanelSchema::publicLayout($layout);
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
