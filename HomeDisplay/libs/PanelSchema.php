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

    public const DEFAULT_COLUMNS = 4;

    public const DEFAULT_ROWS = 5;

    public const MAX_PAGES = 8;

    public const ICONS = [
        'bathtub', 'armchair', 'floorLamp', 'book', 'power',
        'energy', 'temperature', 'light', 'shutter', 'pump', 'valve', 'alarm', 'home', 'clock', 'info',
        'lightOn', 'lightOff', 'humidity', 'sun', 'moon', 'water', 'heat', 'cool', 'fan', 'door', 'lock', 'plug', 'batteryIcon', 'wifi', 'car',
        'motion', 'window', 'garage', 'smoke', 'thermostat', 'solar', 'camera', 'tv', 'speaker', 'washing', 'bed', 'bell', 'leaf', 'fire', 'kitchen', 'pool', 'hall', 'kids',
        'bubbles', 'bubbleChart', 'waves', 'shutterOpen', 'valveOpen', 'valveClosed', 'pumpOff', 'doorOpen', 'doorClosed',
        'garageOpen', 'lockOpen', 'fanOff', 'plugOff', 'bellOff', 'wifiOff', 'speakerOff', 'tvOff', 'waterOff', 'coolOff',
        'fireOff', 'motionOff', 'smokeOff', 'windowOpen', 'blindsOpen', 'blindsClosed',
    ];

    public const BATTERY_LABELS = ['inside', 'left', 'right', 'top', 'bottom'];

    public const DISPLAY_PROFILES = [
        'waveshare-5b-1024x600' => [
            'label' => 'Waveshare 5B (1024×600)',
            'width' => 1024,
            'height' => 600,
            'defaultColumns' => 3,
            'defaultRows' => 4,
            'maxColumns' => 8,
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
            'maxColumns' => 8,
            'maxRows' => 6,
        ],
    ];

    /** system: Diagnosekachel, die das Panel selbst mit Firmware, WLAN, Laufzeit und Datenalter füllt. */
    public const KINDS = ['value', 'display', 'chart', 'energyflow', 'nav', 'label', 'image', 'camera', 'separator', 'scene', 'weather', 'presence', 'multi', 'contacts', 'gauge', 'countdown', 'appointment'];

    public const FONT_PX = [16, 20, 24, 28, 32, 36, 40, 48];

    public const LABEL_STYLES = ['text', 'section'];

    public const ACTIONS = ['switch', 'dim', 'position'];

    /** switch: Tippen. dimSwitch: Ein/Aus eines Dimmwerts. slider: nur Ziehen. combo: Schalter und Balken. battery: Ladestand grafisch. */
    public const CONTROLS = ['switch', 'dimSwitch', 'slider', 'combo', 'battery', 'blinds', 'setpoint'];

    public const COLORS = ['neutral', 'ok', 'warn', 'alarm'];

    public const SIZES = ['small', 'large'];

    public const MAX_ROWS = 6;

    /**
     * Raster einer Seite. Fehlt es an der Seite, gilt das alte Raster der Ansicht, sonst 4 x 5.
     * Anteile (1 bis 3) je Spalte und Zeile; fehlen sie oder passt die Länge nicht, sind alle gleich.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $layout
     * @return array{columns: int, rows: int, columnWeights: list<int>, rowWeights: list<int>}
     */
    public static function pageGrid(array $page, array $layout = []): array
    {
        $columns = (int) ($page['columns'] ?? $layout['columns'] ?? self::DEFAULT_COLUMNS);
        $rows = (int) ($page['rows'] ?? $layout['rows'] ?? self::DEFAULT_ROWS);
        $columns = max(1, $columns);
        $rows = max(1, $rows);
        return [
            'columns' => $columns,
            'rows' => $rows,
            'columnWeights' => self::trackWeights($page['columnWeights'] ?? null, $columns),
            'rowWeights' => self::trackWeights($page['rowWeights'] ?? null, $rows),
        ];
    }

    /** @return list<int> */
    private static function trackWeights($raw, int $count): array
    {
        if (!is_array($raw) || count($raw) !== $count) {
            return array_fill(0, $count, 1);
        }
        $weights = [];
        foreach (array_values($raw) as $value) {
            $weight = (int) $value;
            $weights[] = ($weight >= 1 && $weight <= 3) ? $weight : 1;
        }
        return $weights;
    }

    /**
     * Helligkeit und Ruheanzeige der Ansicht. Null, solange die Ansicht diese Werte noch nicht trägt.
     *
     * @param array<string, mixed> $layout
     * @return array{nightDim: bool, dayBrightness: int, nightBrightness: int, nightFrom: int, nightTo: int, idleMode: int, idleClockStyle: string, idleTimeoutSec: int}|null
     */
    public static function displayBehavior(array $layout): ?array
    {
        if (!array_key_exists('dayBrightness', $layout)) {
            return null;
        }
        $day = (int) $layout['dayBrightness'];
        $night = (int) ($layout['nightBrightness'] ?? 15);
        if ($day < 0 || $day > 100 || $night < 0 || $night > 100) {
            throw new InvalidArgumentException('Helligkeit liegt zwischen 0 und 100.');
        }
        $from = (int) ($layout['nightFrom'] ?? 22);
        $to = (int) ($layout['nightTo'] ?? 6);
        if ($from < 0 || $from > 23 || $to < 0 || $to > 23) {
            throw new InvalidArgumentException('Stunde liegt zwischen 0 und 23.');
        }
        $idle = (int) ($layout['idleMode'] ?? 0);
        if ($idle < 0 || $idle > 2) {
            throw new InvalidArgumentException('Unbekannte Ruheanzeige.');
        }
        $style = (string) ($layout['idleClockStyle'] ?? 'digital');
        if (!in_array($style, ['digital', 'analog', 'word', 'swabian'], true)) {
            throw new InvalidArgumentException('Unbekannter Uhrstil.');
        }
        $timeout = (int) ($layout['idleTimeoutSec'] ?? 60);
        if ($timeout < 5 || $timeout > 3600) {
            throw new InvalidArgumentException('Die Ruheanzeige startet nach 5 bis 3600 Sekunden.');
        }
        return [
            'nightDim' => ($layout['nightDim'] ?? true) !== false,
            'dayBrightness' => $day,
            'nightBrightness' => $night,
            'nightFrom' => $from,
            'nightTo' => $to,
            'idleMode' => $idle,
            'idleClockStyle' => $style,
            'idleTimeoutSec' => $timeout,
        ];
    }

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
        self::theme($layout);
        self::displayBehavior($layout);
        $pages = $layout['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new InvalidArgumentException('Ansicht ohne Seite.');
        }
        if (count($pages) > self::MAX_PAGES) {
            throw new InvalidArgumentException('Eine Ansicht hat höchstens ' . self::MAX_PAGES . ' Seiten.');
        }
        $pageIds = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                throw new InvalidArgumentException('Ungültige Seite.');
            }
            $pageId = (string) ($page['id'] ?? '');
            if (!preg_match('/^[a-z0-9_-]{1,24}$/', $pageId) || isset($pageIds[$pageId])) {
                throw new InvalidArgumentException('Ungültige oder doppelte Seite: ' . $pageId);
            }
            $pageIds[$pageId] = true;
            $grid = self::pageGrid($page, $layout);
            if ($grid['columns'] < 1 || $grid['columns'] > $maxColumns) {
                throw new InvalidArgumentException('Seite „' . $pageId . '“ hat zu viele Spalten für das Display-Profil.');
            }
            if ($grid['rows'] < 1 || $grid['rows'] > $maxRows) {
                throw new InvalidArgumentException('Seite „' . $pageId . '“ hat zu viele Zeilen für das Display-Profil.');
            }
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
            $pageGrid = self::pageGrid($page, $layout);
            $columns = $pageGrid['columns'];
            $rows = $pageGrid['rows'];
            $tiles = $page['tiles'] ?? [];
            if (!is_array($tiles)) {
                throw new InvalidArgumentException('Ungültige Kacheln.');
            }
            $occupied = [];
            /* Hintergrundbilder belegen keine Zellen: Wert-Kacheln dürfen darüber liegen. */
            $backdropCells = [];
            $coverCells = [];
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
                if ($kind === 'system') {
                    continue; /* Legacy Anzeigestatus – nicht mehr gültig */
                }
                if (!in_array($kind, self::KINDS, true)) {
                    throw new InvalidArgumentException('Unbekannte Kachelart: ' . $kind);
                }
                self::validateTypography($tile);
                $col = self::cell($tile, 'col');
                $row = self::cell($tile, 'row');
                $colSpan = max(1, (int) ($tile['colSpan'] ?? 1));
                $rowSpan = max(1, (int) ($tile['rowSpan'] ?? 1));
                if ($col + $colSpan > $columns || $row + $rowSpan > $rows) {
                    throw new InvalidArgumentException('Kachel liegt außerhalb des Rasters.');
                }
                $backdrop = $kind === 'image' && !empty($tile['imageBackground']);
                for ($x = $col; $x < $col + $colSpan; $x++) {
                    for ($y = $row; $y < $row + $rowSpan; $y++) {
                        $key = $pageId . ':' . $x . ':' . $y;
                        $cells = $backdrop ? $backdropCells : $occupied;
                        if (isset($cells[$key])) {
                            throw new InvalidArgumentException('Kacheln überlappen sich.');
                        }
                        if ($backdrop) {
                            $backdropCells[$key] = true;
                        } else {
                            $occupied[$key] = true;
                            if ($kind !== 'value') {
                                $coverCells[$key] = true;
                            }
                        }
                    }
                }
                self::validateKind($tile, $kind, $pageIds, $publish);
            }
            /* Nur Wert-Kacheln dürfen auf einem Hintergrundbild liegen, wie im Editor. */
            foreach ($coverCells as $key => $unused) {
                if (isset($backdropCells[$key])) {
                    throw new InvalidArgumentException('Über einem Hintergrundbild sind nur Wert-Kacheln erlaubt.');
                }
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
                if ($kind === 'system') {
                    continue;
                }
                if ($kind === 'display') {
                    $kind = 'value';
                    if (!isset($tile['displaySource'])) {
                        $tile['displaySource'] = isset($tile['text']) && (string) $tile['text'] !== '' && (int) ($tile['variableId'] ?? 0) <= 0
                            ? 'text' : 'variable';
                    }
                }
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
                self::publishTileChrome($public, $tile);
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
                    $textSize = (string) ($tile['textSize'] ?? '');
                    if (in_array($textSize, ['small', 'normal', 'large', 'xlarge'], true)) {
                        $public['textSize'] = $textSize;
                    }
                    if (!empty($tile['textBold'])) {
                        $public['textBold'] = true;
                    }
                    if (!empty($tile['textItalic'])) {
                        $public['textItalic'] = true;
                    }
                    if (!empty($tile['textUnderline'])) {
                        $public['textUnderline'] = true;
                    }
                }
                if ($kind === 'separator') {
                    $public['orientation'] = (string) ($tile['orientation'] ?? '') === 'vertical' ? 'vertical' : 'horizontal';
                    $public['thickness'] = max(1, min(12, (int) ($tile['thickness'] ?? 2)));
                    $lineColor = strtolower(trim((string) ($tile['lineColor'] ?? '')));
                    if (preg_match('/^#[0-9a-f]{6}$/', $lineColor)) {
                        $public['lineColor'] = $lineColor;
                    }
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
                    if (!empty($tile['chartShowAxes'])) {
                        $public['chartShowAxes'] = true;
                    }
                    if (!empty($tile['chartShowGrid'])) {
                        $public['chartShowGrid'] = true;
                    }
                    if (array_key_exists('chartShowValue', $tile) && !$tile['chartShowValue']) {
                        $public['chartShowValue'] = false;
                    }
                    $chartColor = strtolower(trim((string) ($tile['chartColor'] ?? '')));
                    if (preg_match('/^#[0-9a-f]{6}$/', $chartColor) === 1) {
                        $public['chartColor'] = $chartColor;
                    }
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
                    $iconMode = (string) ($tile['iconMode'] ?? 'static');
                    if ($iconMode === 'toggle') {
                        $public['iconMode'] = 'toggle';
                        $public['iconOn'] = (string) ($tile['iconOn'] ?? 'lightOn');
                        $public['iconOff'] = (string) ($tile['iconOff'] ?? 'lightOff');
                    }
                    if (array_key_exists('showIcon', $tile) && $tile['showIcon'] === false) {
                        $public['showIcon'] = false;
                    }
                    if (!empty($tile['showStateText'])) {
                        $public['showStateText'] = true;
                    }
                    $textSize = (string) ($tile['textSize'] ?? '');
                    if (in_array($textSize, ['small', 'normal', 'large', 'xlarge'], true)) {
                        $public['textSize'] = $textSize;
                    }
                    if (!empty($tile['textBold'])) {
                        $public['textBold'] = true;
                    }
                    if (!empty($tile['textItalic'])) {
                        $public['textItalic'] = true;
                    }
                    if (!empty($tile['textUnderline'])) {
                        $public['textUnderline'] = true;
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

                if ($kind === 'image') {
                    $source = (string) ($tile['imageSource'] ?? 'catalog');
                    if ($source === 'media') {
                        $public['imageSource'] = 'media';
                        $public['mediaFile'] = self::mediaFileName((string) $tile['tileId']);
                    } else {
                        $imageId = (string) ($tile['imageId'] ?? '');
                        $public['imageSource'] = 'catalog';
                        $public['imageId'] = $imageId;
                        $public['imageFile'] = HomeDisplayCatalogStore::panelImageFileName($imageId);
                    }
                    if (!empty($tile['imageBackground'])) {
                        $public['imageBackground'] = true;
                    }
                }
                if ($kind === 'camera') {
                    $public['cameraFile'] = self::cameraFileName((string) $tile['tileId']);
                    $pace = (string) ($tile['cameraPace'] ?? '');
                    $public['cameraPace'] = $pace === 'interval' ? 'interval' : 'live';
                    $fit = (string) ($tile['cameraFit'] ?? '');
                    if ($fit === 'cover' || $fit === 'contain') {
                        $public['cameraFit'] = $fit;
                    }
                    $fps = (int) ($tile['cameraLiveFps'] ?? 3);
                    if ($fps < 1 || $fps > 5) {
                        $fps = 3;
                    }
                    if ($public['cameraPace'] === 'live') {
                        $public['cameraLiveFps'] = $fps;
                    }
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
                    $source = (string) ($tile['displaySource'] ?? 'variable');
                    $public['displaySource'] = in_array($source, ['variable', 'text'], true) ? $source : 'variable';
                    if ($public['displaySource'] === 'text') {
                        $public['text'] = (string) ($tile['text'] ?? '');
                    } else {
                        $public['variableId'] = (int) ($tile['variableId'] ?? 0);
                        $public['decimals'] = max(0, min(3, (int) ($tile['decimals'] ?? 0)));
                    }
                    $prefix = trim((string) ($tile['prefix'] ?? ''));
                    $suffix = trim((string) ($tile['suffix'] ?? ''));
                    if ($suffix === '') {
                        $suffix = trim((string) ($tile['unit'] ?? ''));
                    }
                    if ($prefix !== '') {
                        $public['prefix'] = $prefix;
                    }
                    if ($suffix !== '') {
                        $public['suffix'] = $suffix;
                        $public['unit'] = $suffix;
                    }
                    $actionId = trim((string) ($tile['actionId'] ?? ''));
                    $control = (string) ($tile['control'] ?? 'switch');
                    if (!in_array($control, self::CONTROLS, true)) {
                        $control = 'switch';
                    }
                    $actionKind = (string) ($tile['actionKind'] ?? '');
                    $isDimmer = in_array($control, ['dimSwitch', 'slider', 'combo'], true)
                        || ($actionKind === 'dim' && $control !== 'setpoint');
                    $icon = (string) ($tile['icon'] ?? '');
                    if ($isDimmer) {
                        unset($public['icon']);
                    } elseif ($icon !== '' && in_array($icon, self::ICONS, true)) {
                        $public['icon'] = $icon;
                    }
                    $plainValue = $actionId === '' && !$isDimmer && $control !== 'battery';
                    if ($plainValue) {
                        $place = (string) ($tile['iconPlace'] ?? '');
                        if (!in_array($place, ['before', 'after', 'none'], true)) {
                            $place = ($icon !== '' && in_array($icon, self::ICONS, true)) ? 'before' : 'none';
                        }
                        if ($place === 'none') {
                            unset($public['icon']);
                        } elseif ($icon !== '' && in_array($icon, self::ICONS, true)) {
                            $public['iconPlace'] = $place;
                        }
                    }
                    if ($actionId !== '') {
                        $public['control'] = $control;
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
                    if ($control === 'switch' || in_array($control, ['dimSwitch', 'slider', 'combo'], true)) {
                        $dim = $control !== 'switch';
                        $public['valueOn'] = array_key_exists('valueOn', $tile)
                            ? (float) $tile['valueOn']
                            : ($dim ? (float) ($tile['valueMax'] ?? 100) : 1.0);
                        $public['valueOff'] = array_key_exists('valueOff', $tile)
                            ? (float) $tile['valueOff']
                            : ($dim ? (float) ($tile['valueMin'] ?? 0) : 0.0);
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
                    if (array_key_exists('showIcon', $tile) && $tile['showIcon'] === false) {
                        $public['showIcon'] = false;
                    }
                    if (!empty($tile['showStateText'])) {
                        $public['showStateText'] = true;
                    }
                    if ($control === 'combo' || in_array($control, ['dimSwitch', 'slider'], true)) {
                        if (array_key_exists('dimShowSwitch', $tile) && $tile['dimShowSwitch'] === false) {
                            $public['dimShowSwitch'] = false;
                        }
                        if (array_key_exists('dimShowBar', $tile) && $tile['dimShowBar'] === false) {
                            $public['dimShowBar'] = false;
                        }
                        if (!empty($tile['dimShowStateText'])) {
                            $public['dimShowStateText'] = true;
                        }
                        $tap = (string) ($tile['dimTap'] ?? '');
                        if (in_array($tap, ['on', 'off'], true)) {
                            $public['dimTap'] = $tap;
                        }
                    }
                    $textColor = strtolower(trim((string) ($tile['textColor'] ?? '')));
                    if (preg_match('/^#[0-9a-f]{6}$/', $textColor)) {
                        $public['textColor'] = $textColor;
                    }
                    $textSize = (string) ($tile['textSize'] ?? '');
                    if (in_array($textSize, ['small', 'large', 'xlarge'], true)) {
                        $public['textSize'] = $textSize;
                    }
                }
                if ($kind === 'multi' || $kind === 'contacts') {
                    $public['rows'] = self::publicRows($tile, $kind === 'multi');
                }
                if ($kind === 'gauge') {
                    $public['variableId'] = (int) ($tile['variableId'] ?? 0);
                    $public['valueMin'] = (float) ($tile['valueMin'] ?? 0);
                    $public['valueMax'] = (float) ($tile['valueMax'] ?? 100);
                    $public['unit'] = (string) ($tile['unit'] ?? '');
                    $public['decimals'] = max(0, min(3, (int) ($tile['decimals'] ?? 0)));
                }
                if ($kind === 'countdown') {
                    $public['countdownMode'] = (string) ($tile['countdownMode'] ?? '') === 'seconds' ? 'seconds' : 'until';
                    $public['variableId'] = (int) ($tile['variableId'] ?? 0);
                }
                if ($kind === 'appointment') {
                    $public['variableId'] = (int) ($tile['variableId'] ?? 0);
                }
                $tiles[] = $public;
            }
            $publicPage = [
                'id' => (string) ($page['id'] ?? ''),
                'title' => (string) ($page['title'] ?? self::PAGES[$page['id'] ?? ''] ?? ''),
            ] + self::pageGrid($page, $layout) + [
                'tiles' => $tiles,
            ];
            $pageIcon = (string) ($page['icon'] ?? '');
            if ($pageIcon !== '' && in_array($pageIcon, self::ICONS, true)) {
                $publicPage['icon'] = $pageIcon;
            }
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
            'showTabs' => !array_key_exists('showTabs', $layout) || (bool) $layout['showTabs'],
            'colorMode' => ((string) ($layout['colorMode'] ?? 'color') === 'mono') ? 'mono' : 'color',
            'theme' => self::theme($layout),
            'displayProfile' => $profileId,
            'targetWidth' => (int) $profile['width'],
            'targetHeight' => (int) $profile['height'],
            'pages' => $pages,
        ];
    }

    /** @param array<string, mixed> $public @param array<string, mixed> $tile */
    private static function publishTileChrome(array &$public, array $tile): void
    {
        if (array_key_exists('showTitle', $tile) && $tile['showTitle'] === false) {
            $public['showTitle'] = false;
        }
        if (array_key_exists('showTitleIcon', $tile)) {
            $public['showTitleIcon'] = (bool) $tile['showTitleIcon'];
        }
        $titleIcon = (string) ($tile['titleIcon'] ?? '');
        if ($titleIcon !== '' && in_array($titleIcon, self::ICONS, true)) {
            $public['titleIcon'] = $titleIcon;
        }
        $align = (string) ($tile['titleAlign'] ?? '');
        if (in_array($align, ['center', 'right'], true)) {
            $public['titleAlign'] = $align;
        }
        $titlePx = self::resolvedPx($tile, 'titlePx', '');
        if ($titlePx !== null) {
            $public['titlePx'] = $titlePx;
        }
        $textPx = self::resolvedPx($tile, 'textPx', 'textSize');
        if ($textPx !== null) {
            $public['textPx'] = $textPx;
        }
        if (!empty($tile['titleBold'])) {
            $public['titleBold'] = true;
        }
        if (!empty($tile['titleItalic'])) {
            $public['titleItalic'] = true;
        }
        if (!empty($tile['textBold'])) {
            $public['textBold'] = true;
        }
        if (!empty($tile['textItalic'])) {
            $public['textItalic'] = true;
        }
        if (!empty($tile['textUnderline'])) {
            $public['textUnderline'] = true;
        }
        if (!empty($tile['showAge'])) {
            $public['showAge'] = true;
        }
        foreach (['bgColor', 'onColor'] as $key) {
            $value = strtolower(trim((string) ($tile[$key] ?? '')));
            if (preg_match('/^#[0-9a-f]{6}$/', $value)) {
                $public[$key] = $value;
            }
        }
    }

    /** @param array<string, mixed> $layout @return array{background: string, button: string, accent: string} */
    public static function theme(array $layout): array
    {
        $defaults = ['background' => '#102033', 'button' => '#2a4f7c', 'accent' => '#ffb547'];
        $theme = is_array($layout['theme'] ?? null) ? $layout['theme'] : [];
        $out = [];
        foreach ($defaults as $key => $fallback) {
            $value = strtolower(trim((string) ($theme[$key] ?? '')));
            if ($value === '') {
                $out[$key] = $fallback;
                continue;
            }
            if (!preg_match('/^#[0-9a-f]{6}$/', $value)) {
                throw new InvalidArgumentException('Ungültige Farbe für ' . $key . '.');
            }
            $out[$key] = $value;
        }
        return $out;
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
        $gridProfile = self::profile(self::defaultProfileId());
        foreach (self::PAGES as $id => $title) {
            $pages[$id] = [
                'id' => $id, 'title' => $title,
                'columns' => (int) $gridProfile['defaultColumns'], 'rows' => (int) $gridProfile['defaultRows'],
                'tiles' => [],
            ];
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
        return [
            'name' => $name,
            'displayProfile' => self::defaultProfileId(),
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
        $pages[] = ['id' => 'start', 'title' => 'Start', 'columns' => $columns, 'rows' => $rows, 'tiles' => [
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
            $pages[] = ['id' => $pageId, 'title' => self::PAGES[$pageId], 'columns' => $columns, 'rows' => $rows, 'tiles' => $tiles];
        }
        $pages[] = ['id' => 'system', 'title' => 'System', 'columns' => $columns, 'rows' => $rows, 'tiles' => [
            ['tileId' => 'system-title', 'kind' => 'label', 'text' => 'System', 'size' => 'large', 'col' => 0, 'row' => 0, 'colSpan' => 2, 'rowSpan' => 1],
            ['tileId' => 'system-home', 'kind' => 'nav', 'icon' => 'home', 'title' => 'Start', 'targetPage' => 'start', 'col' => 2, 'row' => 0, 'colSpan' => 1, 'rowSpan' => 1],
            ['tileId' => 'system-info', 'kind' => 'label', 'text' => 'Geraeteinfos: Serial-Menue', 'col' => 0, 'row' => 1, 'colSpan' => 3, 'rowSpan' => 1],
        ]];
        return [
            'name' => $name,
            'displayProfile' => $profileId,
            'showTabs' => false,
            'nightDim' => true,
            'dayBrightness' => 70,
            'nightBrightness' => 15,
            'nightFrom' => 22,
            'nightTo' => 6,
            'idleMode' => 0,
            'idleClockStyle' => 'digital',
            'idleTimeoutSec' => 60,
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
    /** @param array<string, mixed> $tile */
    private static function resolvedPx(array $tile, string $pxKey, string $legacyKey): ?int
    {
        if (isset($tile[$pxKey]) && is_numeric($tile[$pxKey])) {
            $px = (int) $tile[$pxKey];
            if (!in_array($px, self::FONT_PX, true)) {
                throw new InvalidArgumentException('Schriftgröße muss 16, 20, 24, 28, 32, 36, 40 oder 48 px sein.');
            }
            return $px;
        }
        if ($legacyKey === '') {
            return null;
        }
        $name = (string) ($tile[$legacyKey] ?? '');
        $map = ['small' => 20, 'normal' => 28, 'large' => 36, 'xlarge' => 48];
        if (isset($map[$name])) {
            return $map[$name];
        }
        if ($name !== '' && $name !== 'auto') {
            throw new InvalidArgumentException('Unbekannte Schriftgröße.');
        }
        return null;
    }

    /** @param array<string, mixed> $tile */
    private static function validateTypography(array $tile): void
    {
        self::resolvedPx($tile, 'titlePx', '');
        self::resolvedPx($tile, 'textPx', 'textSize');
    }

    /** @param array<string, mixed> $tile @return list<array<string, mixed>> */
    private static function publicRows(array $tile, bool $withFormat): array
    {
        $rows = [];
        $source = is_array($tile['rows'] ?? null) ? $tile['rows'] : [];
        foreach (array_slice($source, 0, 6) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [
                'variableId' => (int) ($row['variableId'] ?? 0),
                'title' => mb_substr(trim((string) ($row['title'] ?? '')), 0, 40),
            ];
            if ($withFormat) {
                $item['unit'] = mb_substr((string) ($row['unit'] ?? ''), 0, 12);
                $item['decimals'] = max(0, min(3, (int) ($row['decimals'] ?? 0)));
            }
            $rows[] = $item;
        }
        return $rows;
    }

    private static function validateKind(array $tile, string $kind, array $pageIds, bool $publish): void
    {
        if ($kind === 'value') {
            $source = (string) ($tile['displaySource'] ?? 'variable');
            if (!in_array($source, ['variable', 'text'], true)) {
                throw new InvalidArgumentException('Unbekannte Anzeigequelle.');
            }
            if ($source === 'text') {
                if ($publish && trim((string) ($tile['text'] ?? '')) === '') {
                    throw new InvalidArgumentException('Wertkachel ohne Text.');
                }
                $icon = (string) ($tile['icon'] ?? '');
                if ($icon !== '' && !in_array($icon, self::ICONS, true)) {
                    throw new InvalidArgumentException('Unbekanntes Symbol: ' . $icon);
                }
                return;
            }
            $iconMode = (string) ($tile['iconMode'] ?? 'static');
            if ($iconMode === 'toggle') {
                foreach (['iconOn', 'iconOff'] as $key) {
                    if (!in_array((string) ($tile[$key] ?? ''), self::ICONS, true)) {
                        throw new InvalidArgumentException('Unbekanntes Symbol: ' . (string) ($tile[$key] ?? ''));
                    }
                }
            } else {
                $icon = (string) ($tile['icon'] ?? '');
                if ($icon !== '' && !in_array($icon, self::ICONS, true)) {
                    throw new InvalidArgumentException('Unbekanntes Symbol: ' . $icon);
                }
            }
            $decimals = (int) ($tile['decimals'] ?? 0);
            if ($decimals < 0 || $decimals > 3) {
                throw new InvalidArgumentException('Nachkommastellen liegen zwischen 0 und 3.');
            }
            if (isset($tile['color']) && !in_array((string) $tile['color'], self::COLORS, true)) {
                throw new InvalidArgumentException('Unbekannte Farbe.');
            }
            $actionId = trim((string) ($tile['actionId'] ?? ''));
            if ($actionId === '' && !isset($tile['control'])) {
                if ($publish && ((int) ($tile['variableId'] ?? 0) <= 0 || !IPS_VariableExists((int) ($tile['variableId'] ?? 0)))) {
                    throw new InvalidArgumentException('Wertkachel ohne Variable.');
                }
                return;
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
            $textSize = (string) ($tile['textSize'] ?? '');
            if ($textSize !== '' && !in_array($textSize, ['small', 'normal', 'large', 'xlarge'], true)) {
                throw new InvalidArgumentException('Unbekannte Schriftgröße.');
            }
            return;
        }
        if ($kind === 'separator') {
            $orientation = (string) ($tile['orientation'] ?? 'horizontal');
            if (!in_array($orientation, ['horizontal', 'vertical'], true)) {
                throw new InvalidArgumentException('Unbekannte Linienausrichtung.');
            }
            if (isset($tile['thickness']) && ((int) $tile['thickness'] < 1 || (int) $tile['thickness'] > 12)) {
                throw new InvalidArgumentException('Liniendicke liegt zwischen 1 und 12.');
            }
            $lineColor = strtolower(trim((string) ($tile['lineColor'] ?? '')));
            if ($lineColor !== '' && !preg_match('/^#[0-9a-f]{6}$/', $lineColor)) {
                throw new InvalidArgumentException('Unbekannte Linienfarbe.');
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
            $iconMode = (string) ($tile['iconMode'] ?? 'static');
            if ($iconMode === 'toggle') {
                foreach (['iconOn', 'iconOff'] as $key) {
                    if (!in_array((string) ($tile[$key] ?? ''), self::ICONS, true)) {
                        throw new InvalidArgumentException('Unbekanntes Symbol: ' . (string) ($tile[$key] ?? ''));
                    }
                }
            }
            $textSize = (string) ($tile['textSize'] ?? '');
            if ($textSize !== '' && !in_array($textSize, ['small', 'normal', 'large', 'xlarge'], true)) {
                throw new InvalidArgumentException('Unbekannte Textgröße.');
            }
            $steps = is_array($tile['sceneSteps'] ?? null) ? $tile['sceneSteps'] : [];
            if ($steps === [] && (int) ($tile['variableId'] ?? 0) > 0) {
                $steps = [['variableId' => (int) $tile['variableId'], 'value' => true]];
            }
            if ($publish && $steps === []) {
                throw new InvalidArgumentException('Szenenkachel ohne Lampen.');
            }
            $actionId = trim((string) ($tile['actionId'] ?? ''));
            if ($publish && $actionId === '') {
                throw new InvalidArgumentException('Szenenkachel ohne freigegebene Aktion.');
            }
            if ($actionId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $actionId)) {
                throw new InvalidArgumentException('Ungültige actionId.');
            }
            if ($publish) {
                foreach ($steps as $step) {
                    if (!is_array($step)) {
                        throw new InvalidArgumentException('Szenenschritt ungültig.');
                    }
                    self::assertSwitchable((int) ($step['variableId'] ?? 0), 'Szenen-Lampe');
                    $colorId = (int) ($step['colorVariableId'] ?? 0);
                    if ($colorId > 0) {
                        self::assertSwitchable($colorId, 'Szenen-Farbe');
                    }
                }
            }
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
            return; /* Legacy: wird beim Veröffentlichen übersprungen */
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
        if ($kind === 'multi' || $kind === 'contacts') {
            $rows = is_array($tile['rows'] ?? null) ? $tile['rows'] : [];
            if (count($rows) > 6) {
                throw new InvalidArgumentException('Höchstens sechs Zeilen.');
            }
            if ($publish && $rows === []) {
                throw new InvalidArgumentException($kind === 'multi' ? 'Mehrwert ohne Zeilen.' : 'Kontakte ohne Zeilen.');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('Zeile ungültig.');
                }
                $vid = (int) ($row['variableId'] ?? 0);
                if ($publish && ($vid <= 0 || !IPS_VariableExists($vid))) {
                    throw new InvalidArgumentException('Zeile ohne Variable.');
                }
            }
            return;
        }
        if ($kind === 'gauge' || $kind === 'countdown' || $kind === 'appointment') {
            $vid = (int) ($tile['variableId'] ?? 0);
            if ($publish && ($vid <= 0 || !IPS_VariableExists($vid))) {
                throw new InvalidArgumentException('Kachel ohne Variable.');
            }
            if ($kind === 'countdown') {
                $mode = (string) ($tile['countdownMode'] ?? 'until');
                if (!in_array($mode, ['until', 'seconds'], true)) {
                    throw new InvalidArgumentException('Unbekannter Countdown-Modus.');
                }
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

    private static function assertSwitchable(int $variableId, string $label): void
    {
        if ($variableId <= 0 || !function_exists('IPS_VariableExists') || !IPS_VariableExists($variableId)) {
            throw new InvalidArgumentException($label . ' ohne Variable.');
        }
        $variable = IPS_GetVariable($variableId);
        if ((int) ($variable['VariableAction'] ?? 0) <= 0 && (int) ($variable['VariableCustomAction'] ?? 0) <= 0) {
            throw new InvalidArgumentException($label . ' ist nicht schaltbar.');
        }
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
        if ($kind === 'scene') {
            $steps = is_array($tile['sceneSteps'] ?? null) ? $tile['sceneSteps'] : [];
            foreach ($steps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $id = (int) ($step['variableId'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
