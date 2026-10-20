<?php

declare(strict_types=1);

/**
 * Vertrag einer Kachel: Seite, Rasterzelle, Art, Symbol, Variable und freigegebene Aktion.
 * Freie Pixelkoordinaten und Skriptaufrufe nimmt das Schema nicht an.
 */
final class HomeDisplayPanelSchema
{
    public const PAGES = [
        'start' => 'Start',
        'energie' => 'Energie',
        'klima' => 'Klima',
        'licht' => 'Licht',
        'rollladen' => 'Rollladen',
        'pool' => 'Pool und Whirlpool',
        'garten' => 'Garten',
        'system' => 'System',
    ];

    public const ICONS = [
        'energy', 'temperature', 'light', 'shutter', 'pump', 'valve', 'alarm', 'home', 'clock', 'info',
        'lightOn', 'lightOff', 'humidity', 'sun', 'moon', 'water', 'heat', 'cool', 'fan', 'door', 'lock', 'plug', 'batteryIcon', 'wifi', 'car',
    ];

    public const BATTERY_LABELS = ['inside', 'left', 'right', 'top', 'bottom'];

    public const DISPLAY_PROFILES = [
        'waveshare-5b-1024x600' => [
            'label' => 'Waveshare 5B (1024×600)',
            'width' => 1024,
            'height' => 600,
            'defaultColumns' => 3,
            'defaultRows' => 4,
            'maxColumns' => 4,
            'maxRows' => 6,
        ],
        'firebeetle-epd-400x300' => [
            'label' => 'FireBeetle E-Ink 4,2" (400×300)',
            'width' => 400,
            'height' => 300,
            'defaultColumns' => 3,
            'defaultRows' => 4,
            'maxColumns' => 3,
            'maxRows' => 4,
        ],
        'firebeetle-epd-800x480' => [
            'label' => 'FireBeetle E-Ink 7,5" (800×480)',
            'width' => 800,
            'height' => 480,
            'defaultColumns' => 4,
            'defaultRows' => 6,
            'maxColumns' => 4,
            'maxRows' => 6,
        ],
    ];

    /** system: Diagnosekachel, die das Panel selbst mit Firmware, WLAN, Laufzeit und Datenalter füllt. */
    public const KINDS = ['value', 'display', 'chart', 'energyflow', 'nav', 'label', 'image', 'camera', 'separator', 'system', 'scene', 'weather', 'presence'];

    public const LABEL_STYLES = ['text', 'section'];

    public const ACTIONS = ['switch', 'dim', 'position'];

    /** switch: Tippen. dimSwitch: Ein/Aus eines Dimmwerts. slider: nur Ziehen. combo: Schalter und Balken. battery: Ladestand grafisch. */
    public const CONTROLS = ['switch', 'dimSwitch', 'slider', 'combo', 'battery', 'blinds', 'setpoint'];

    public const COLORS = ['neutral', 'ok', 'warn', 'alarm'];

    public const SIZES = ['small', 'large'];

    public const MAX_ROWS = 6;

    /** @param array<string, mixed> $layout */
    public static function validateLayout(array $layout, bool $publish): void
    {
        if (trim((string) ($layout['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Ansicht ohne Namen.');
        }
        $profileId = (string) ($layout['displayProfile'] ?? self::defaultProfileId());
        self::profile($profileId);
        $profile = self::profile($profileId);
        $maxColumns = (int) $profile['maxColumns'];
        $maxRows = (int) $profile['maxRows'];
        $columns = (int) ($layout['columns'] ?? 0);
        if ($columns < 1 || $columns > $maxColumns) {
            throw new InvalidArgumentException('Das Raster hat zu viele Spalten für das Display-Profil.');
        }
        $rows = (int) ($layout['rows'] ?? 4);
        if ($rows < 1 || $rows > $maxRows) {
            throw new InvalidArgumentException('Das Raster hat zu viele Zeilen für das Display-Profil.');
        }
        $pages = $layout['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new InvalidArgumentException('Ansicht ohne Seite.');
        }
        $pageIds = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                throw new InvalidArgumentException('Ungültige Seite.');
            }
            $pageId = (string) ($page['id'] ?? '');
            if (!isset(self::PAGES[$pageId]) || isset($pageIds[$pageId])) {
                throw new InvalidArgumentException('Unbekannte oder doppelte Seite: ' . $pageId);
            }
            $pageIds[$pageId] = true;
            $background = (string) ($page['backgroundId'] ?? '');
            if ($background === '') {
                continue;
            }
            if (!preg_match('/^[a-f0-9]{16}$/', $background)) {
                throw new InvalidArgumentException('Ungültiges Hintergrundbild.');
            }
            if ($publish && !HomeDisplayCatalogStore::imageExists($background)) {
                throw new InvalidArgumentException('Das Hintergrundbild einer Seite wurde gelöscht.');
            }
        }
        $seen = [];
        foreach ($pages as $page) {
            $pageId = (string) $page['id'];
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
                $group = trim((string) ($tile['group'] ?? ''));
                if ($group !== '' && mb_strlen($group) > 40) {
                    throw new InvalidArgumentException('Gruppenname ist zu lang (max. 40 Zeichen).');
                }
                $tileId = (string) ($tile['tileId'] ?? '');
                if ($tileId === '' || isset($seen[$tileId]) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $tileId)) {
                    throw new InvalidArgumentException('Jede Kachel braucht eine eigene tileId.');
                }
                $seen[$tileId] = true;
                $kind = (string) ($tile['kind'] ?? 'value');
                if (!in_array($kind, self::KINDS, true)) {
                    throw new InvalidArgumentException('Unbekannte Kachelart: ' . $kind);
                }
                $col = self::cell($tile, 'col');
                $row = self::cell($tile, 'row');
                $colSpan = max(1, (int) ($tile['colSpan'] ?? 1));
                $rowSpan = max(1, (int) ($tile['rowSpan'] ?? 1));
                if ($col + $colSpan > $columns || $row + $rowSpan > $rows) {
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
                self::validateKind($tile, $kind, $pageIds, $publish);
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
                $kind = (string) ($tile['kind'] ?? 'value');
                $public = [
                    'tileId' => (string) $tile['tileId'],
                    'kind' => $kind,
                    'icon' => (string) ($tile['icon'] ?? ''),
                    'title' => (string) ($tile['title'] ?? ''),
                    'col' => (int) $tile['col'],
                    'row' => (int) $tile['row'],
                    'colSpan' => max(1, (int) ($tile['colSpan'] ?? 1)),
                    'rowSpan' => max(1, (int) ($tile['rowSpan'] ?? 1)),
                ];
                $group = trim((string) ($tile['group'] ?? ''));
                if ($group !== '') {
                    $public['group'] = mb_substr($group, 0, 40);
                }
                if ($kind === 'nav') {
                    $public['targetPage'] = (string) ($tile['targetPage'] ?? 'start');
                }
                if ($kind === 'label') {
                    $public['text'] = (string) ($tile['text'] ?? '');
                    $labelStyle = (string) ($tile['labelStyle'] ?? 'text');
                    $public['labelStyle'] = in_array($labelStyle, self::LABEL_STYLES, true) ? $labelStyle : 'text';
                }
                if ($kind === 'chart') {
                    $public['chartPeriod'] = HomeDisplayChart::normalizePeriod((string) ($tile['chartPeriod'] ?? '24h'));
                    $public['chartStyle'] = HomeDisplayChart::normalizeStyle((string) ($tile['chartStyle'] ?? 'area'));
                    $public['chartYMode'] = HomeDisplayChart::normalizeYMode((string) ($tile['chartYMode'] ?? 'auto'));
                    if ($public['chartYMode'] === 'fixed') {
                        $public['chartYMin'] = (float) ($tile['chartYMin'] ?? 0);
                        $public['chartYMax'] = (float) ($tile['chartYMax'] ?? 100);
                    }
                    $public['chartCompareYesterday'] = !empty($tile['chartCompareYesterday']);
                    $refresh = (int) ($tile['chartRefreshSec'] ?? 0);
                    if ($refresh > 0) {
                        $public['chartRefreshSec'] = max(60, min(86400, $refresh));
                    }
                    $public['chartBitmap'] = !array_key_exists('chartBitmap', $tile) || (bool) $tile['chartBitmap'];
                    $public['chartFile'] = self::chartFileName((string) $tile['tileId']);
                    $public['unit'] = (string) ($tile['unit'] ?? '');
                    $public['decimals'] = max(0, min(3, (int) ($tile['decimals'] ?? 0)));
                    $defs = HomeDisplayChart::seriesDefinitions($tile);
                    if ($defs !== []) {
                        $public['chartSeries'] = $defs;
                    }
                    $icon = (string) ($tile['icon'] ?? '');
                    if ($icon !== '' && in_array($icon, self::ICONS, true)) {
                        $public['icon'] = $icon;
                    }
                }
                if ($kind === 'scene') {
                    $actionId = trim((string) ($tile['actionId'] ?? ''));
                    if ($actionId !== '') {
                        $public['actionId'] = $actionId;
                        $public['confirm'] = (bool) ($tile['confirm'] ?? false);
                    }
                }
                if ($kind === 'weather') {
                    $public['unit'] = (string) ($tile['unit'] ?? '°C');
                    $public['decimals'] = max(0, min(3, (int) ($tile['decimals'] ?? 1)));
                    if (isset($tile['forecastVariableId'])) {
                        $public['forecastVariableId'] = (int) $tile['forecastVariableId'];
                    }
                }
                if ($kind === 'presence') {
                    $public['presenceMode'] = in_array((string) ($tile['presenceMode'] ?? 'home'), ['home', 'alarm'], true)
                        ? (string) ($tile['presenceMode'] ?? 'home')
                        : 'home';
                }
                if ($kind === 'display') {
                    $source = (string) ($tile['displaySource'] ?? 'variable');
                    $public['displaySource'] = in_array($source, ['variable', 'text'], true) ? $source : 'variable';
                    if ($public['displaySource'] === 'text') {
                        $public['text'] = (string) ($tile['text'] ?? '');
                    } else {
                        $public['variableId'] = (int) ($tile['variableId'] ?? 0);
                        $public['unit'] = (string) ($tile['unit'] ?? '');
                        $public['decimals'] = max(0, min(3, (int) ($tile['decimals'] ?? 0)));
                    }
                    $icon = (string) ($tile['icon'] ?? '');
                    if ($icon !== '') {
                        $public['icon'] = $icon;
                    }
                }
                if ($kind === 'image') {
                    $source = (string) ($tile['imageSource'] ?? 'catalog');
                    if ($source === 'media') {
                        $public['imageSource'] = 'media';
                        $public['mediaFile'] = self::mediaFileName((string) $tile['tileId']);
                    } else {
                        $imageId = (string) ($tile['imageId'] ?? '');
                        $public['imageSource'] = 'catalog';
                        $public['imageId'] = $imageId;
                        $public['imageFile'] = HomeDisplayCatalogStore::imageFileName($imageId);
                    }
                }
                if ($kind === 'camera') {
                    $public['cameraFile'] = self::cameraFileName((string) $tile['tileId']);
                }
                if ($kind === 'energyflow') {
                    $mode = (string) ($tile['flowMode'] ?? 'signed');
                    $public['flowMode'] = in_array($mode, HomeDisplayEnergyFlow::MODES, true) ? $mode : 'signed';
                    $public['homeOverride'] = !array_key_exists('homeOverride', $tile) || (bool) $tile['homeOverride'];
                    foreach ([
                        'gridVariableId', 'pvVariableId', 'loadVariableId', 'batteryVariableId',
                        'gridImportVariableId', 'gridExportVariableId', 'batteryChargeVariableId', 'batteryDischargeVariableId',
                        'batterySocVariableId',
                    ] as $flowKey) {
                        if (isset($tile[$flowKey])) {
                            $public[$flowKey] = (int) $tile[$flowKey];
                        }
                    }
                    $public['unit'] = (string) ($tile['unit'] ?? 'W');
                    $public['decimals'] = max(0, min(3, (int) ($tile['decimals'] ?? 0)));
                    $public['flowThreshold'] = max(0, (float) ($tile['flowThreshold'] ?? 15));
                    $public['socDecimals'] = max(0, min(1, (int) ($tile['socDecimals'] ?? 0)));
                }
                if ($kind === 'value') {
                    $actionId = trim((string) ($tile['actionId'] ?? ''));
                    $control = (string) ($tile['control'] ?? 'switch');
                    if (!in_array($control, self::CONTROLS, true)) {
                        $control = 'switch';
                    }
                    $public['control'] = $control;
                    if ($actionId !== '') {
                        $public['actionId'] = $actionId;
                        $public['actionKind'] = (string) ($tile['actionKind'] ?? 'switch');
                        $public['confirm'] = (bool) ($tile['confirm'] ?? false);
                        if (isset($tile['valueMin']) || in_array($control, ['dimSwitch', 'slider', 'combo'], true)) {
                            $public['valueMin'] = (float) ($tile['valueMin'] ?? 0);
                        }
                        if (isset($tile['valueMax']) || in_array($control, ['dimSwitch', 'slider', 'combo'], true)) {
                            $public['valueMax'] = (float) ($tile['valueMax'] ?? 100);
                        }
                    }
                    if (isset($tile['threshold'], $tile['color'])) {
                        $public['threshold'] = (float) $tile['threshold'];
                        $public['color'] = (string) $tile['color'];
                    }
                    if ($control === 'battery') {
                        $public['valueMin'] = (float) ($tile['valueMin'] ?? 0);
                        $public['valueMax'] = (float) ($tile['valueMax'] ?? 100);
                        $public['batteryPad'] = max(0, min(48, (int) ($tile['batteryPad'] ?? 8)));
                        $label = (string) ($tile['batteryLabel'] ?? 'inside');
                        $public['batteryLabel'] = in_array($label, self::BATTERY_LABELS, true) ? $label : 'inside';
                    }
                    if (in_array($control, ['dimSwitch', 'slider', 'combo', 'blinds'], true)) {
                        $public['valueMin'] = (float) ($tile['valueMin'] ?? 0);
                        $public['valueMax'] = (float) ($tile['valueMax'] ?? 100);
                        if (isset($tile['dimShowPercent']) && $tile['dimShowPercent'] === false) {
                            $public['dimShowPercent'] = false;
                        }
                        if (($tile['dimLayout'] ?? '') === 'stacked') {
                            $public['dimLayout'] = 'stacked';
                        }
                        if (isset($tile['dimTapTrack'])) {
                            $tap = $tile['dimTapTrack'];
                            if ($tap === 'on' || $tap === true) {
                                $public['dimTapTrack'] = 'on';
                            } elseif ($tap === 'off' || $tap === false) {
                                $public['dimTapTrack'] = 'off';
                            }
                        }
                    }
                    if ($control === 'setpoint' && isset($tile['setpointVariableId'])) {
                        $public['setpointVariableId'] = (int) $tile['setpointVariableId'];
                        $public['setpointStep'] = (float) ($tile['setpointStep'] ?? 0.5);
                    }
                    $iconMode = (string) ($tile['iconMode'] ?? 'static');
                    if ($iconMode === 'toggle') {
                        $public['iconMode'] = 'toggle';
                        $public['iconOn'] = (string) ($tile['iconOn'] ?? 'lightOn');
                        $public['iconOff'] = (string) ($tile['iconOff'] ?? 'lightOff');
                    }
                }
                $tiles[] = $public;
            }
            $publicPage = [
                'id' => (string) ($page['id'] ?? ''),
                'title' => (string) ($page['title'] ?? self::PAGES[$page['id']] ?? ''),
                'tiles' => $tiles,
            ];
            $background = (string) ($page['backgroundId'] ?? '');
            if ($background !== '') {
                $publicPage['backgroundId'] = $background;
                $publicPage['backgroundFile'] = HomeDisplayCatalogStore::imageFileName($background);
            }
            $pages[] = $publicPage;
        }
        $profileId = (string) ($layout['displayProfile'] ?? self::defaultProfileId());
        $profile = self::profile($profileId);
        return [
            'id' => (string) ($layout['id'] ?? ''),
            'name' => (string) ($layout['name'] ?? ''),
            'version' => (int) ($layout['version'] ?? 0),
            'columns' => max(1, (int) ($layout['columns'] ?? 2)),
            'rows' => max(1, (int) ($layout['rows'] ?? 4)),
            'showTabs' => !array_key_exists('showTabs', $layout) || (bool) $layout['showTabs'],
            'displayProfile' => $profileId,
            'targetWidth' => (int) $profile['width'],
            'targetHeight' => (int) $profile['height'],
            'pages' => $pages,
        ];
    }

    public static function defaultProfileId(): string
    {
        return 'waveshare-5b-1024x600';
    }

    /** @return array<string, mixed> */
    public static function profile(string $id): array
    {
        if (!isset(self::DISPLAY_PROFILES[$id])) {
            throw new InvalidArgumentException('Unbekanntes Display-Profil: ' . $id);
        }
        return self::DISPLAY_PROFILES[$id];
    }

    /** @param array<string, mixed> $layout @return list<string> */
    public static function imageIds(array $layout): array
    {
        $ids = [];
        foreach ($layout['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $background = (string) ($page['backgroundId'] ?? '');
            if ($background !== '') {
                $ids[$background] = true;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (is_array($tile) && ($tile['kind'] ?? '') === 'image' && (string) ($tile['imageId'] ?? '') !== '') {
                    $ids[(string) $tile['imageId']] = true;
                }
            }
        }
        return array_keys($ids);
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
                'kind' => 'value',
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
            $control = (string) ($row['Control'] ?? 'switch');
            if (in_array($control, self::CONTROLS, true) && $control !== 'switch') {
                $tile['control'] = $control;
            }
            if ($action || in_array($control, ['dimSwitch', 'slider', 'combo'], true)) {
                $tile['actionId'] = 'a' . $index;
                $tile['actionKind'] = in_array($control, ['dimSwitch', 'slider', 'combo'], true)
                    ? 'dim'
                    : (string) ($row['ActionKind'] ?? 'switch');
                $tile['confirm'] = !empty($row['Confirm']);
                if ($tile['actionKind'] === 'dim') {
                    $tile['valueMin'] = 0;
                    $tile['valueMax'] = 100;
                }
            }
            $pages[$pageId]['tiles'][] = $tile;
        }
        $used = array_values(array_filter($pages, static function ($page) {
            return $page['tiles'] !== [];
        }));
        if ($used === []) {
            $used = [$pages['start']];
        }
        $profile = self::profile(self::defaultProfileId());
        return [
            'name' => $name,
            'displayProfile' => self::defaultProfileId(),
            'columns' => (int) $profile['defaultColumns'],
            'rows' => (int) $profile['defaultRows'],
            'showTabs' => true,
            'pages' => $used,
        ];
    }

    /**
     * Vollständige Dashboard-Vorlage für 1024×600: Startseite mit Tab-Wechslern,
     * je eine Seite für Energie, Klima, Licht, Rollladen, Pool und Garten sowie eine Systemseite.
     * Variablen werden anschließend im Designer zugeordnet.
     *
     * @return array<string, mixed>
     */
    public static function dashboardLayout(string $name = 'Übersicht', string $displayProfile = ''): array
    {
        $profileId = $displayProfile !== '' ? $displayProfile : self::defaultProfileId();
        $profile = self::profile($profileId);
        $columns = (int) $profile['defaultColumns'];
        $rows = (int) $profile['defaultRows'];
        $pages = [];
        $pages[] = ['id' => 'start', 'title' => 'Start', 'tiles' => [
            ['tileId' => 'start-title', 'kind' => 'label', 'text' => 'Zuhause', 'size' => 'large', 'col' => 0, 'row' => 0, 'colSpan' => 3, 'rowSpan' => 1],
            ['tileId' => 'nav-energie', 'kind' => 'nav', 'icon' => 'energy', 'title' => 'Energie', 'targetPage' => 'energie', 'col' => 0, 'row' => 1, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'nav-klima', 'kind' => 'nav', 'icon' => 'temperature', 'title' => 'Klima', 'targetPage' => 'klima', 'col' => 1, 'row' => 1, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'nav-licht', 'kind' => 'nav', 'icon' => 'light', 'title' => 'Licht', 'targetPage' => 'licht', 'col' => 2, 'row' => 1, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'nav-rollladen', 'kind' => 'nav', 'icon' => 'shutter', 'title' => 'Rollladen', 'targetPage' => 'rollladen', 'col' => 0, 'row' => 2, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'nav-pool', 'kind' => 'nav', 'icon' => 'pump', 'title' => 'Pool', 'targetPage' => 'pool', 'col' => 1, 'row' => 2, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'nav-garten', 'kind' => 'nav', 'icon' => 'valve', 'title' => 'Garten', 'targetPage' => 'garten', 'col' => 2, 'row' => 2, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'start-netz', 'kind' => 'value', 'icon' => 'energy', 'title' => 'Netzbezug', 'col' => 0, 'row' => 3, 'colSpan' => 1, 'rowSpan' => 1, 'variableId' => 0, 'unit' => 'W', 'decimals' => 0],
            ['tileId' => 'start-aussen', 'kind' => 'value', 'icon' => 'temperature', 'title' => 'Außen', 'col' => 1, 'row' => 3, 'colSpan' => 1, 'rowSpan' => 1, 'variableId' => 0, 'unit' => '°C', 'decimals' => 1],
            ['tileId' => 'nav-system', 'kind' => 'nav', 'icon' => 'info', 'title' => 'System', 'targetPage' => 'system', 'col' => 2, 'row' => 3, 'colSpan' => 1, 'rowSpan' => 1],
        ]];
        $valuePages = [
            'energie' => ['energy', 'W', 0, ['Netzbezug', 'Einspeisung', 'Erzeugung', 'Hausverbrauch', 'Batterie', 'Wallbox']],
            'klima' => ['temperature', '°C', 1, ['Wohnzimmer', 'Schlafzimmer', 'Küche', 'Bad', 'Außen', 'Luftfeuchte']],
            'licht' => ['light', '', 0, ['Wohnzimmer', 'Esszimmer', 'Küche', 'Flur', 'Terrasse', 'Garten']],
            'rollladen' => ['shutter', '%', 0, ['Wohnzimmer', 'Esszimmer', 'Küche', 'Schlafzimmer', 'Kinderzimmer', 'Bad']],
            'pool' => ['pump', '', 1, ['Wassertemperatur', 'pH', 'ORP', 'Pumpe', 'Heizung', 'Whirlpool']],
            'garten' => ['valve', '', 0, ['Ventil 1', 'Ventil 2', 'Ventil 3', 'Bodenfeuchte', 'Regen', 'Mähroboter']],
        ];
        $actionPages = ['licht' => 'switch', 'rollladen' => 'position', 'pool' => 'switch', 'garten' => 'switch'];
        foreach ($valuePages as $pageId => [$icon, $unit, $decimals, $titles]) {
            $tiles = [[
                'tileId' => $pageId . '-title', 'kind' => 'label', 'text' => self::PAGES[$pageId], 'size' => 'large',
                'col' => 0, 'row' => 0, 'colSpan' => 2, 'rowSpan' => 1,
            ], [
                'tileId' => $pageId . '-home', 'kind' => 'nav', 'icon' => 'home', 'title' => 'Start', 'targetPage' => 'start',
                'col' => 2, 'row' => 0, 'colSpan' => 1, 'rowSpan' => 1,
            ]];
            foreach ($titles as $index => $title) {
                $tile = [
                    'tileId' => $pageId . '-' . ($index + 1),
                    'kind' => 'value',
                    'icon' => $icon,
                    'title' => $title,
                    'col' => $index % 3,
                    'row' => 1 + intdiv($index, 3),
                    'colSpan' => 1,
                    'rowSpan' => 1,
                    'variableId' => 0,
                    'unit' => $unit,
                    'decimals' => $decimals,
                ];
                $pages_action = $actionPages[$pageId] ?? '';
                $isSensor = in_array($title, ['Bodenfeuchte', 'Regen', 'Wassertemperatur', 'pH', 'ORP'], true);
                if ($pages_action !== '' && !$isSensor) {
                    $tile['actionId'] = $pageId . '-a' . ($index + 1);
                    $tile['actionKind'] = $pages_action;
                    $tile['confirm'] = $pageId === 'garten';
                    if ($pages_action === 'position') {
                        $tile['valueMin'] = 0;
                        $tile['valueMax'] = 100;
                    }
                }
                $tiles[] = $tile;
            }
            $pages[] = ['id' => $pageId, 'title' => self::PAGES[$pageId], 'tiles' => $tiles];
        }
        $pages[] = ['id' => 'system', 'title' => 'System', 'tiles' => [
            ['tileId' => 'system-title', 'kind' => 'label', 'text' => 'System', 'size' => 'large', 'col' => 0, 'row' => 0, 'colSpan' => 2, 'rowSpan' => 1],
            ['tileId' => 'system-home', 'kind' => 'nav', 'icon' => 'home', 'title' => 'Start', 'targetPage' => 'start', 'col' => 2, 'row' => 0, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'system-info', 'kind' => 'system', 'title' => 'Panel', 'col' => 0, 'row' => 1, 'colSpan' => 3, 'rowSpan' => 3],
        ]];
        return [
            'name' => $name,
            'displayProfile' => $profileId,
            'columns' => $columns,
            'rows' => $rows,
            'showTabs' => false,
            'pages' => $pages,
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
            throw new InvalidArgumentException('Unbekannte Vorlage.');
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
        foreach (['x', 'y', 'scriptId', 'script', 'entityId', 'item', 'objectId', 'url'] as $key) {
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

    /** @param array<string, mixed> $tile @param array<string, bool> $pageIds */
    private static function validateKind(array $tile, string $kind, array $pageIds, bool $publish): void
    {
        if ($kind === 'value') {
            $iconMode = (string) ($tile['iconMode'] ?? 'static');
            if ($iconMode === 'toggle') {
                foreach (['iconOn', 'iconOff'] as $key) {
                    if (!in_array((string) ($tile[$key] ?? ''), self::ICONS, true)) {
                        throw new InvalidArgumentException('Unbekanntes Symbol: ' . (string) ($tile[$key] ?? ''));
                    }
                }
            } elseif (!in_array((string) ($tile['icon'] ?? ''), self::ICONS, true)) {
                throw new InvalidArgumentException('Unbekanntes Symbol: ' . (string) ($tile['icon'] ?? ''));
            }
            $decimals = (int) ($tile['decimals'] ?? 0);
            if ($decimals < 0 || $decimals > 3) {
                throw new InvalidArgumentException('Nachkommastellen liegen zwischen 0 und 3.');
            }
            if (isset($tile['color']) && !in_array((string) $tile['color'], self::COLORS, true)) {
                throw new InvalidArgumentException('Unbekannte Farbe.');
            }
            $control = (string) ($tile['control'] ?? 'switch');
            if (!in_array($control, self::CONTROLS, true)) {
                throw new InvalidArgumentException('Unbekannte Bedienung: ' . $control);
            }
            if ($control === 'battery') {
                if ($publish && ((int) ($tile['variableId'] ?? 0) <= 0 || !IPS_VariableExists((int) $tile['variableId']))) {
                    throw new InvalidArgumentException('Batteriekachel ohne Variable.');
                }
                $batteryLabel = (string) ($tile['batteryLabel'] ?? 'inside');
                if ($batteryLabel !== '' && !in_array($batteryLabel, self::BATTERY_LABELS, true)) {
                    throw new InvalidArgumentException('Unbekannte Batterie-Label-Position.');
                }
                if (isset($tile['batteryPad']) && ((int) $tile['batteryPad'] < 0 || (int) $tile['batteryPad'] > 48)) {
                    throw new InvalidArgumentException('Batterie-Ränder liegen zwischen 0 und 48.');
                }
                return;
            }
            if (in_array($control, ['dimSwitch', 'slider', 'combo'], true)) {
                if ((string) ($tile['actionKind'] ?? 'dim') !== 'dim') {
                    throw new InvalidArgumentException('Dimmschalter, Dimmbalken und Kombischalter brauchen die Aktionsart Dimmen.');
                }
                if ($publish && trim((string) ($tile['actionId'] ?? '')) === '') {
                    throw new InvalidArgumentException('Dimmbedienung ohne freigegebene Aktion.');
                }
            }
            if ($control === 'blinds') {
                if ((string) ($tile['actionKind'] ?? 'position') !== 'position') {
                    throw new InvalidArgumentException('Rolladen brauchen die Aktionsart Position.');
                }
                if ($publish && trim((string) ($tile['actionId'] ?? '')) === '') {
                    throw new InvalidArgumentException('Rolladen ohne freigegebene Aktion.');
                }
            }
            if ($control === 'setpoint') {
                $setpointId = (int) ($tile['setpointVariableId'] ?? 0);
                if ($publish && ($setpointId <= 0 || !IPS_VariableExists($setpointId))) {
                    throw new InvalidArgumentException('Setpoint ohne Sollwert-Variable.');
                }
                if ($publish && trim((string) ($tile['actionId'] ?? '')) === '') {
                    throw new InvalidArgumentException('Setpoint ohne schaltbare Sollwert-Aktion.');
                }
            }
            self::validateAction($tile, $publish);
            return;
        }
        if ($kind === 'nav') {
            $target = (string) ($tile['targetPage'] ?? '');
            if (!isset($pageIds[$target])) {
                throw new InvalidArgumentException('Der Seitenwechsler zeigt auf eine Seite, die es in dieser Ansicht nicht gibt.');
            }
            $icon = (string) ($tile['icon'] ?? '');
            if ($icon !== '' && !in_array($icon, self::ICONS, true)) {
                throw new InvalidArgumentException('Unbekanntes Symbol: ' . $icon);
            }
            return;
        }
        if ($kind === 'label') {
            if (trim((string) ($tile['text'] ?? '')) === '') {
                throw new InvalidArgumentException('Textkachel ohne Text.');
            }
            $labelStyle = (string) ($tile['labelStyle'] ?? 'text');
            if (!in_array($labelStyle, self::LABEL_STYLES, true)) {
                throw new InvalidArgumentException('Unbekannter Textstil.');
            }
            if (isset($tile['size']) && !in_array((string) $tile['size'], self::SIZES, true)) {
                throw new InvalidArgumentException('Unbekannte Textgröße.');
            }
            return;
        }
        if ($kind === 'energyflow') {
            $mode = HomeDisplayEnergyFlow::mode($tile);
            $decimals = (int) ($tile['decimals'] ?? 0);
            if ($decimals < 0 || $decimals > 3) {
                throw new InvalidArgumentException('Nachkommastellen liegen zwischen 0 und 3.');
            }
            $threshold = (float) ($tile['flowThreshold'] ?? 15);
            if ($threshold < 0 || $threshold > 50000) {
                throw new InvalidArgumentException('Fluss-Schwellwert liegt zwischen 0 und 50000.');
            }
            if ($publish) {
                $required = ['gridImportVariableId', 'gridExportVariableId', 'pvVariableId'];
                if ($mode === 'split') {
                    $required[] = 'batteryChargeVariableId';
                    $required[] = 'batteryDischargeVariableId';
                } else {
                    $required[] = 'batteryVariableId';
                }
                if (HomeDisplayEnergyFlow::homeOverride($tile)) {
                    $required[] = 'loadVariableId';
                }
                foreach ($required as $flowKey) {
                    $vid = (int) ($tile[$flowKey] ?? 0);
                    if ($vid <= 0 || !IPS_VariableExists($vid)) {
                        throw new InvalidArgumentException('Energiefluss: Pflicht-Variable fehlt oder existiert nicht (' . $flowKey . ').');
                    }
                }
                $soc = (int) ($tile['batterySocVariableId'] ?? 0);
                if ($soc > 0 && !IPS_VariableExists($soc)) {
                    throw new InvalidArgumentException('Energiefluss: SOC-Variable existiert nicht.');
                }
            }
            return;
        }
        if ($kind === 'chart') {
            $decimals = (int) ($tile['decimals'] ?? 0);
            if ($decimals < 0 || $decimals > 3) {
                throw new InvalidArgumentException('Nachkommastellen liegen zwischen 0 und 3.');
            }
            HomeDisplayChart::normalizePeriod((string) ($tile['chartPeriod'] ?? '24h'));
            HomeDisplayChart::normalizeStyle((string) ($tile['chartStyle'] ?? 'area'));
            HomeDisplayChart::normalizeYMode((string) ($tile['chartYMode'] ?? 'auto'));
            $icon = (string) ($tile['icon'] ?? '');
            if ($icon !== '' && !in_array($icon, self::ICONS, true)) {
                throw new InvalidArgumentException('Unbekanntes Symbol: ' . $icon);
            }
            $defs = HomeDisplayChart::seriesDefinitions($tile);
            if ($publish && $defs === []) {
                throw new InvalidArgumentException('Diagrammkachel ohne Variable.');
            }
            foreach ($defs as $def) {
                $vid = (int) $def['variableId'];
                if ($publish && !IPS_VariableExists($vid)) {
                    throw new InvalidArgumentException('Diagrammkachel: Variable existiert nicht.');
                }
                if ($publish && !HomeDisplayChart::variableLogged($vid)) {
                    throw new InvalidArgumentException('Diagrammkachel: Variable „' . $vid . '“ ist nicht archiviert.');
                }
            }
            return;
        }
        if ($kind === 'scene') {
            $icon = (string) ($tile['icon'] ?? '');
            if ($icon !== '' && !in_array($icon, self::ICONS, true)) {
                throw new InvalidArgumentException('Unbekanntes Symbol: ' . $icon);
            }
            if ($publish && trim((string) ($tile['actionId'] ?? '')) === '') {
                throw new InvalidArgumentException('Szenenkachel ohne freigegebene Aktion.');
            }
            if ($publish && trim((string) ($tile['actionId'] ?? '')) !== '' && (int) ($tile['variableId'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Szenenkachel ohne Aktions-Variable.');
            }
            self::validateAction($tile, $publish);
            return;
        }
        if ($kind === 'weather') {
            if ($publish && ((int) ($tile['variableId'] ?? 0) <= 0 || !IPS_VariableExists((int) $tile['variableId']))) {
                throw new InvalidArgumentException('Wetterkachel ohne Temperaturvariable.');
            }
            $forecast = (int) ($tile['forecastVariableId'] ?? 0);
            if ($publish && $forecast > 0 && !IPS_VariableExists($forecast)) {
                throw new InvalidArgumentException('Wetterkachel: Prognose-Variable existiert nicht.');
            }
            return;
        }
        if ($kind === 'presence') {
            if ($publish && ((int) ($tile['variableId'] ?? 0) <= 0 || !IPS_VariableExists((int) $tile['variableId']))) {
                throw new InvalidArgumentException('Anwesenheitskachel ohne Variable.');
            }
            return;
        }
        if ($kind === 'display') {
            $source = (string) ($tile['displaySource'] ?? 'variable');
            if (!in_array($source, ['variable', 'text'], true)) {
                throw new InvalidArgumentException('Unbekannte Anzeigequelle.');
            }
            $icon = (string) ($tile['icon'] ?? '');
            if ($icon !== '' && !in_array($icon, self::ICONS, true)) {
                throw new InvalidArgumentException('Unbekanntes Symbol: ' . $icon);
            }
            $decimals = (int) ($tile['decimals'] ?? 0);
            if ($decimals < 0 || $decimals > 3) {
                throw new InvalidArgumentException('Nachkommastellen liegen zwischen 0 und 3.');
            }
            if (isset($tile['color']) && !in_array((string) $tile['color'], self::COLORS, true)) {
                throw new InvalidArgumentException('Unbekannte Farbe.');
            }
            if ($source === 'text') {
                if (trim((string) ($tile['text'] ?? '')) === '') {
                    throw new InvalidArgumentException('Anzeigekachel ohne Text.');
                }
                return;
            }
            if ($publish && ((int) ($tile['variableId'] ?? 0) <= 0 || !IPS_VariableExists((int) $tile['variableId']))) {
                throw new InvalidArgumentException('Anzeigekachel ohne Variable.');
            }
            return;
        }
        if ($kind === 'system') {
            return;
        }
        if ($kind === 'image') {
            $source = (string) ($tile['imageSource'] ?? 'catalog');
            if ($source === 'media') {
                $variableId = (int) ($tile['variableId'] ?? 0);
                $mediaObjectId = (int) ($tile['mediaObjectId'] ?? 0);
                if ($publish && !HomeDisplayMedia::publishableMediaBinding($variableId, $mediaObjectId)) {
                    throw new InvalidArgumentException('Bildkachel ohne Medien-Variable oder Medienobjekt (Bild).');
                }
                return;
            }
            $imageId = (string) ($tile['imageId'] ?? '');
            if (!preg_match('/^[a-f0-9]{16}$/', $imageId)) {
                throw new InvalidArgumentException('Bildkachel ohne Bild.');
            }
            if ($publish && !HomeDisplayCatalogStore::imageExists($imageId)) {
                throw new InvalidArgumentException('Das Bild der Kachel wurde gelöscht.');
            }
            return;
        }
        if ($kind === 'camera') {
            $source = (string) ($tile['cameraSource'] ?? 'url');
            if (!in_array($source, ['url', 'media'], true)) {
                throw new InvalidArgumentException('Ungültige Kameraquelle.');
            }
            $variableId = (int) ($tile['variableId'] ?? 0);
            $mediaObjectId = (int) ($tile['mediaObjectId'] ?? 0);
            if ($publish && $variableId <= 0 && $mediaObjectId <= 0) {
                throw new InvalidArgumentException($source === 'media'
                    ? 'Kamerakachel ohne Medien-Variable oder Medienobjekt.'
                    : 'Kamerakachel ohne Variable für die Bildadresse.');
            }
            if ($publish && $variableId > 0 && !IPS_VariableExists($variableId)) {
                throw new InvalidArgumentException('Kamerakachel: Variable existiert nicht.');
            }
            if ($publish) {
                $hasMedia = HomeDisplayMedia::resolveImageMediaId($tile) > 0;
                $url = HomeDisplayMedia::cameraImageUrl($tile);
                if ($source === 'media' && !$hasMedia) {
                    throw new InvalidArgumentException('Kameraquelle Medien: Integer-Variable mit Bild-Media-ID oder Medienobjekt wählen.');
                }
                if ($source === 'url' && !$hasMedia && $url === '' && $mediaObjectId <= 0) {
                    throw new InvalidArgumentException('Kameraquelle URL: Textvariable mit http(s)-Bildadresse wählen.');
                }
            }
        }
    }

    public static function cameraFileName(string $tileId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $tileId) ?? '';
        if ($safe === '') {
            $safe = 'cam';
        }
        return 'cam_' . substr($safe, 0, 32) . '.jpg';
    }

    public static function mediaFileName(string $tileId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $tileId) ?? '';
        if ($safe === '') {
            $safe = 'media';
        }
        return 'media_' . substr($safe, 0, 28) . '.jpg';
    }

    public static function chartFileName(string $tileId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $tileId) ?? '';
        if ($safe === '') {
            $safe = 'chart';
        }

        return 'chart_' . substr($safe, 0, 28) . '.png';
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
        $tileKind = (string) ($tile['kind'] ?? 'value');
        $kind = (string) ($tile['actionKind'] ?? '');
        if ($kind === '' && $tileKind === 'scene') {
            $kind = 'switch';
        }
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

    /** @param array<string, mixed> $layout @return list<int> */
    public static function layoutVariableIds(array $layout): array
    {
        $seen = [];
        foreach ($layout['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (!is_array($tile)) {
                    continue;
                }
                foreach (self::tileVariableIds($tile) as $id) {
                    $seen[$id] = $id;
                }
            }
        }

        return array_values($seen);
    }

    /** @param array<string, mixed> $tile @return list<int> */
    public static function tileVariableIds(array $tile): array
    {
        $kind = (string) ($tile['kind'] ?? 'value');
        $ids = [];
        if ($kind === 'energyflow') {
            foreach (HomeDisplayEnergyFlow::variablePropertyKeys($tile) as $prop) {
                $id = (int) ($tile[$prop] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }
        $primary = (int) ($tile['variableId'] ?? 0);
        if ($primary > 0) {
            $ids[] = $primary;
        }
        if ($kind === 'weather') {
            $forecast = (int) ($tile['forecastVariableId'] ?? 0);
            if ($forecast > 0) {
                $ids[] = $forecast;
            }
        }
        if ($kind === 'value' && (string) ($tile['control'] ?? '') === 'setpoint') {
            $setpoint = (int) ($tile['setpointVariableId'] ?? 0);
            if ($setpoint > 0) {
                $ids[] = $setpoint;
            }
        }
        if ($kind === 'chart') {
            foreach (HomeDisplayChart::seriesDefinitions($tile) as $def) {
                $id = (int) ($def['variableId'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
