<?php

declare(strict_types=1);

/**
 * Gemeinsamer Katalog für Layouts und Konfigurationen.
 * Liegt außerhalb der Instanzen, damit jede Panel-Instanz daraus wählen kann.
 */
require_once __DIR__ . '/Media.php';
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
                'rows' => 4,
                'showTabs' => true,
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

    public static function imagesDir(): string
    {
        $dir = dirname(self::path()) . DIRECTORY_SEPARATOR . 'images';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** @return array<string, array<string, mixed>> */
    public static function imageIndex(): array
    {
        $path = self::imagesDir() . DIRECTORY_SEPARATOR . 'index.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, array<string, mixed>> $index */
    private static function saveImageIndex(array $index): void
    {
        $path = self::imagesDir() . DIRECTORY_SEPARATOR . 'index.json';
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json !== false) {
            file_put_contents($path, $json, LOCK_EX);
        }
    }

    public static function imageFileName(string $id): string
    {
        $index = self::imageIndex();
        $ext = (string) ($index[$id]['ext'] ?? 'png');
        return $id . '.' . $ext;
    }

    public static function imagePath(string $id): string
    {
        return self::imagesDir() . DIRECTORY_SEPARATOR . self::imageFileName($id);
    }

    public static function imageExists(string $id): bool
    {
        return preg_match('/^[a-f0-9]{16}$/', $id) === 1 && is_file(self::imagePath($id));
    }

    /**
     * Speichert ein hochgeladenes Bild. Mit GD wird es auf höchstens 1024×600 verkleinert und als PNG abgelegt.
     * @return array<string, mixed>
     */
    public static function saveImage(string $name, string $binary): array
    {
        if ($binary === '' || strlen($binary) > 6 * 1024 * 1024) {
            throw new InvalidArgumentException('Das Bild ist leer oder größer als 6 MB.');
        }
        $info = @getimagesizefromstring($binary);
        if (!is_array($info)) {
            throw new InvalidArgumentException('Die Datei ist kein lesbares Bild.');
        }
        $mime = (string) ($info['mime'] ?? '');
        $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
        $width = (int) $info[0];
        $height = (int) $info[1];
        if (function_exists('imagecreatefromstring')) {
            $source = @imagecreatefromstring($binary);
            if ($source !== false) {
                $maxWidth = 1024;
                $maxHeight = 600;
                $scale = min(1.0, $maxWidth / max(1, $width), $maxHeight / max(1, $height));
                $targetWidth = max(1, (int) round($width * $scale));
                $targetHeight = max(1, (int) round($height * $scale));
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                ob_start();
                imagepng($target, null, 6);
                $encoded = ob_get_clean();
                imagedestroy($target);
                imagedestroy($source);
                if (is_string($encoded) && $encoded !== '') {
                    $binary = $encoded;
                    $ext = 'png';
                    $width = $targetWidth;
                    $height = $targetHeight;
                }
            }
        } elseif ($ext !== 'png' && $ext !== 'jpg') {
            throw new InvalidArgumentException('Ohne Bildbibliothek werden nur PNG und JPEG angenommen.');
        }
        $id = substr(hash('sha256', $binary), 0, 16);
        $index = self::imageIndex();
        $index[$id] = [
            'name' => trim($name) !== '' ? trim($name) : $id,
            'ext' => $ext,
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
        ];
        file_put_contents(self::imagesDir() . DIRECTORY_SEPARATOR . $id . '.' . $ext, $binary, LOCK_EX);
        self::saveImageIndex($index);
        return ['id' => $id] + $index[$id];
    }

    public static function deleteImage(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new InvalidArgumentException('Ungültige Bildkennung.');
        }
        $catalog = self::load();
        foreach ($catalog['layouts'] as $layout) {
            if (is_array($layout) && in_array($id, HomeDisplayPanelSchema::imageIds($layout), true)) {
                throw new InvalidArgumentException('Das Bild wird in der Ansicht „' . (string) ($layout['name'] ?? '') . '“ verwendet.');
            }
        }
        $path = self::imagePath($id);
        if (is_file($path)) {
            unlink($path);
        }
        $index = self::imageIndex();
        unset($index[$id]);
        self::saveImageIndex($index);
    }

    /** @return list<array<string, mixed>> */
    public static function listImages(): array
    {
        $rows = [];
        foreach (self::imageIndex() as $id => $meta) {
            if (is_array($meta) && is_file(self::imagesDir() . DIRECTORY_SEPARATOR . $id . '.' . (string) ($meta['ext'] ?? 'png'))) {
                $rows[] = ['id' => (string) $id] + $meta;
            }
        }
        usort($rows, static function ($left, $right) {
            return strcasecmp((string) $left['name'], (string) $right['name']);
        });
        return $rows;
    }
}
