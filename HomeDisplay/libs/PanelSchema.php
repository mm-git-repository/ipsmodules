<?php

declare(strict_types=1);

/**
 * Vertrag einer Kachel: Seite, Rasterzelle, Icon, Variable und freigegebene Aktion.
 * Freie Pixelkoordinaten und Skriptaufrufe nimmt das Schema nicht an.
 */
final class HomeDisplayPanelSchema
{
    public const PAGES = [
        'start' => 'Start',
        'energie' => 'Energie',
        'klima' => 'Klima',
        'licht' => 'Licht und Rollladen',
        'pool' => 'Pool und Whirlpool',
        'garten' => 'Garten',
        'system' => 'System',
    ];

    public const ICONS = ['energy', 'temperature', 'light', 'shutter', 'pump', 'valve', 'alarm'];

    public const ACTIONS = ['switch', 'dim', 'position'];

    public const COLORS = ['neutral', 'ok', 'warn', 'alarm'];

    /** @param array<string, mixed> $layout */
    public static function validateLayout(array $layout, bool $publish): void
    {
        if (trim((string) ($layout['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Layout ohne Namen.');
        }
        $columns = (int) ($layout['columns'] ?? 0);
        if ($columns < 1 || $columns > 4) {
            throw new InvalidArgumentException('Das Raster hat eine bis vier Spalten.');
        }
        $pages = $layout['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new InvalidArgumentException('Layout ohne Seite.');
        }
        $seen = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                throw new InvalidArgumentException('Ungültige Seite.');
            }
            $pageId = (string) ($page['id'] ?? '');
            if (!isset(self::PAGES[$pageId])) {
                throw new InvalidArgumentException('Unbekannte Seite: ' . $pageId);
            }
            $tiles = $page['tiles'] ?? [];
            if (!is_array($tiles)) {
                throw new InvalidArgumentException('Ungültige Kacheln.');
            }
            $occupied = [];
            foreach ($tiles as $tile) {
                if (!is_array($tile)) {
                    throw new InvalidArgumentException('Ungültige Kachel.');
                }
                self::rejectForeignKeys($tile);
                $tileId = (string) ($tile['tileId'] ?? '');
                if ($tileId === '' || isset($seen[$tileId]) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $tileId)) {
                    throw new InvalidArgumentException('Jede Kachel braucht eine eigene tileId.');
                }
                $seen[$tileId] = true;
                $icon = (string) ($tile['icon'] ?? '');
                if (!in_array($icon, self::ICONS, true)) {
                    throw new InvalidArgumentException('Unbekanntes Icon: ' . $icon);
                }
                $col = self::cell($tile, 'col');
                $row = self::cell($tile, 'row');
                $colSpan = max(1, (int) ($tile['colSpan'] ?? 1));
                $rowSpan = max(1, (int) ($tile['rowSpan'] ?? 1));
                if ($col + $colSpan > $columns || $row > 11 || $rowSpan > 4) {
                    throw new InvalidArgumentException('Kachel liegt außerhalb des Rasters.');
                }
                for ($x = $col; $x < $col + $colSpan; $x++) {
                    for ($y = $row; $y < $row + $rowSpan; $y++) {
                        $key = $pageId . ':' . $x . ':' . $y;
                        if (isset($occupied[$key])) {
                            throw new InvalidArgumentException('Kacheln überlappen sich.');
                        }
                        $occupied[$key] = true;
                    }
                }
                $decimals = (int) ($tile['decimals'] ?? 0);
                if ($decimals < 0 || $decimals > 3) {
                    throw new InvalidArgumentException('Nachkommastellen liegen zwischen 0 und 3.');
                }
                if (isset($tile['color']) && !in_array((string) $tile['color'], self::COLORS, true)) {
                    throw new InvalidArgumentException('Unbekannte Farbe.');
                }
                self::validateAction($tile, $publish);
            }
        }
    }

    /** @param array<string, mixed> $config */
    public static function validateConfig(array $config): void
    {
        if (trim((string) ($config['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Konfiguration ohne Namen.');
        }
        $idle = (int) ($config['idleMode'] ?? 0);
        if ($idle < 0 || $idle > 2) {
            throw new InvalidArgumentException('Unbekannte Ruheanzeige.');
        }
        foreach (['dayBrightness', 'nightBrightness'] as $key) {
            if (isset($config[$key]) && ((int) $config[$key] < 0 || (int) $config[$key] > 100)) {
                throw new InvalidArgumentException('Helligkeit liegt zwischen 0 und 100.');
            }
        }
        foreach (['nightFrom', 'nightTo', 'maintenanceFrom', 'maintenanceTo'] as $key) {
            if (isset($config[$key]) && ((int) $config[$key] < 0 || (int) $config[$key] > 23)) {
                throw new InvalidArgumentException('Stunde liegt zwischen 0 und 23.');
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
                    $public['actionKind'] = (string) ($tile['actionKind'] ?? 'switch');
                    $public['confirm'] = (bool) ($tile['confirm'] ?? false);
                    if (isset($tile['valueMin'])) {
                        $public['valueMin'] = (float) $tile['valueMin'];
                    }
                    if (isset($tile['valueMax'])) {
                        $public['valueMax'] = (float) $tile['valueMax'];
                    }
                }
                if (isset($tile['threshold'], $tile['color'])) {
                    $public['threshold'] = (float) $tile['threshold'];
                    $public['color'] = (string) $tile['color'];
                }
                $tiles[] = $public;
            }
            $pages[] = [
                'id' => (string) ($page['id'] ?? ''),
                'title' => (string) ($page['title'] ?? self::PAGES[$page['id']] ?? ''),
                'tiles' => $tiles,
            ];
        }
        return [
            'id' => (string) ($layout['id'] ?? ''),
            'name' => (string) ($layout['name'] ?? ''),
            'version' => (int) ($layout['version'] ?? 0),
            'columns' => max(1, (int) ($layout['columns'] ?? 2)),
            'pages' => $pages,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function layoutFromRows(array $rows, string $name): array
    {
        usort($rows, static function ($left, $right) {
            return (int) ($left['Position'] ?? 0) <=> (int) ($right['Position'] ?? 0);
        });
        $pages = [];
        foreach (self::PAGES as $id => $title) {
            $pages[$id] = ['id' => $id, 'title' => $title, 'tiles' => []];
        }
        $index = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pageId = (string) ($row['Page'] ?? 'start');
            if (!isset($pages[$pageId])) {
                continue;
            }
            $index++;
            $action = !empty($row['Action']);
            $tile = [
                'tileId' => 't' . $index,
                'icon' => (string) ($row['Icon'] ?? 'energy'),
                'title' => (string) ($row['Title'] ?? 'Kachel'),
                'col' => (int) ($row['Col'] ?? 0),
                'row' => (int) ($row['Row'] ?? 0),
                'colSpan' => 1,
                'rowSpan' => 1,
                'variableId' => (int) ($row['VariableID'] ?? 0),
                'unit' => (string) ($row['Unit'] ?? ''),
                'decimals' => (int) ($row['Decimals'] ?? 0),
            ];
            if ($action) {
                $tile['actionId'] = 'a' . $index;
                $tile['actionKind'] = (string) ($row['ActionKind'] ?? 'switch');
                $tile['confirm'] = !empty($row['Confirm']);
            }
            $pages[$pageId]['tiles'][] = $tile;
        }
        $used = array_values(array_filter($pages, static function ($page) {
            return $page['tiles'] !== [];
        }));
        if ($used === []) {
            $used = [$pages['start']];
        }
        return [
            'name' => $name,
            'columns' => 2,
            'pages' => $used,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function presetRows(string $name): array
    {
        $presets = [
            'energy' => [
                ['Page' => 'energie', 'Icon' => 'energy', 'Title' => 'Netzbezug'],
                ['Page' => 'energie', 'Icon' => 'energy', 'Title' => 'Einspeisung'],
                ['Page' => 'energie', 'Icon' => 'energy', 'Title' => 'Erzeugung'],
                ['Page' => 'energie', 'Icon' => 'energy', 'Title' => 'Verbrauch'],
            ],
            'whirl' => [
                ['Page' => 'pool', 'Icon' => 'temperature', 'Title' => 'Whirlpool Temperatur'],
                ['Page' => 'pool', 'Icon' => 'pump', 'Title' => 'Whirlpool Pumpe', 'Action' => true, 'ActionKind' => 'switch'],
                ['Page' => 'pool', 'Icon' => 'temperature', 'Title' => 'Whirlpool Heizung', 'Action' => true, 'ActionKind' => 'switch'],
            ],
            'pool' => [
                ['Page' => 'pool', 'Icon' => 'temperature', 'Title' => 'pH'],
                ['Page' => 'pool', 'Icon' => 'alarm', 'Title' => 'ORP'],
                ['Page' => 'pool', 'Icon' => 'pump', 'Title' => 'Pool Pumpe', 'Action' => true, 'ActionKind' => 'switch'],
            ],
            'tuya' => [
                ['Page' => 'pool', 'Icon' => 'temperature', 'Title' => 'Wassertemperatur'],
                ['Page' => 'pool', 'Icon' => 'alarm', 'Title' => 'Leitfähigkeit'],
                ['Page' => 'pool', 'Icon' => 'alarm', 'Title' => 'Chlor'],
            ],
            'gardena' => [
                ['Page' => 'garten', 'Icon' => 'valve', 'Title' => 'Ventil A', 'Action' => true, 'ActionKind' => 'switch', 'Confirm' => true],
                ['Page' => 'garten', 'Icon' => 'valve', 'Title' => 'Ventil B', 'Action' => true, 'ActionKind' => 'switch', 'Confirm' => true],
                ['Page' => 'garten', 'Icon' => 'alarm', 'Title' => 'Sensor'],
            ],
        ];
        if (!isset($presets[$name])) {
            throw new InvalidArgumentException('Unbekanntes Preset.');
        }
        $rows = [];
        foreach ($presets[$name] as $index => $tile) {
            $rows[] = [
                'Position' => $index + 1,
                'Page' => $tile['Page'],
                'Col' => $index % 2,
                'Row' => intdiv($index, 2),
                'Icon' => $tile['Icon'],
                'Title' => $tile['Title'],
                'VariableID' => 0,
                'Unit' => '',
                'Decimals' => 0,
                'Action' => !empty($tile['Action']),
                'Confirm' => !empty($tile['Confirm']),
                'ActionKind' => $tile['ActionKind'] ?? 'switch',
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $tile */
    private static function rejectForeignKeys(array $tile): void
    {
        foreach (['x', 'y', 'scriptId', 'script', 'entityId', 'item', 'objectId'] as $key) {
            if (array_key_exists($key, $tile)) {
                throw new InvalidArgumentException('Dieses Feld gehört nicht ins Layout: ' . $key);
            }
        }
    }

    /** @param array<string, mixed> $tile */
    private static function cell(array $tile, string $key): int
    {
        if (!isset($tile[$key]) || (int) $tile[$key] < 0) {
            throw new InvalidArgumentException('Kachel ohne Rasterzelle.');
        }
        return (int) $tile[$key];
    }

    /** @param array<string, mixed> $tile */
    private static function validateAction(array $tile, bool $publish): void
    {
        $actionId = trim((string) ($tile['actionId'] ?? ''));
        if ($actionId === '') {
            return;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $actionId)) {
            throw new InvalidArgumentException('Ungültige actionId.');
        }
        $kind = (string) ($tile['actionKind'] ?? '');
        if (!in_array($kind, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Aktion muss Schalten, Dimmen oder Position sein.');
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if (!$publish) {
            return;
        }
        if ($variableId <= 0 || !function_exists('IPS_VariableExists') || !IPS_VariableExists($variableId)) {
            throw new InvalidArgumentException('Schaltbare Kachel ohne Variable.');
        }
        $variable = IPS_GetVariable($variableId);
        if ((int) ($variable['VariableAction'] ?? 0) <= 0 && (int) ($variable['VariableCustomAction'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Die Variable hat keine Aktion.');
        }
    }
}
