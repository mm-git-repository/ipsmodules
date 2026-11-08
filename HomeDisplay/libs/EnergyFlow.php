<?php

declare(strict_types=1);

/**
 * Energiefluss-Kachel: Messwerte in einer Tabelle (Netzbezug, Einspeisung, PV, Batterie),
 * gleiche Typen werden aufsummiert. Der Hausverbrauch wird daraus errechnet.
 * Einzelne Verbraucher stehen darunter.
 */
final class HomeDisplayEnergyFlow
{
    public const MODES = ['signed', 'split'];

    /** Typen der Quellentabelle: Schlüssel => Beschriftung. */
    public const TYPES = [
        'gridImport' => 'Netzbezug',
        'gridExport' => 'Netzeinspeisung',
        'grid' => 'Netz (+ Bezug / − Einspeisung)',
        'pv' => 'PV-Erzeugung',
        'batteryCharge' => 'Batterie laden',
        'batteryDischarge' => 'Batterie entladen',
        'battery' => 'Batterie (+ laden / − entladen)',
        'soc' => 'Batterie-Ladestand (%)',
    ];

    public const MAX_SOURCES = 16;
    public const MAX_CONSUMERS = 8;

    /**
     * Quellentabelle. Ältere Kacheln mit festen Variablenfeldern werden hier umgesetzt.
     *
     * @param array<string, mixed> $tile
     * @return list<array{type: string, variableId: int, invert: bool}>
     */
    public static function sources(array $tile): array
    {
        $out = [];
        if (is_array($tile['flowSources'] ?? null)) {
            foreach ($tile['flowSources'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $type = (string) ($row['type'] ?? '');
                if (!isset(self::TYPES[$type])) {
                    continue;
                }
                $out[] = [
                    'type' => $type,
                    'variableId' => (int) ($row['variableId'] ?? 0),
                    'invert' => !empty($row['invert']),
                ];
            }
            return array_slice($out, 0, self::MAX_SOURCES);
        }
        $legacy = [
            'gridImport' => 'gridImportVariableId',
            'gridExport' => 'gridExportVariableId',
            'pv' => 'pvVariableId',
            'soc' => 'batterySocVariableId',
        ];
        if (self::mode($tile) === 'split') {
            $legacy['batteryCharge'] = 'batteryChargeVariableId';
            $legacy['batteryDischarge'] = 'batteryDischargeVariableId';
        } else {
            $legacy['battery'] = 'batteryVariableId';
        }
        if ((int) ($tile['gridImportVariableId'] ?? 0) <= 0 && (int) ($tile['gridExportVariableId'] ?? 0) <= 0) {
            $legacy['grid'] = 'gridVariableId';
        }
        foreach ($legacy as $type => $key) {
            $id = (int) ($tile[$key] ?? 0);
            if ($id > 0) {
                $out[] = ['type' => $type, 'variableId' => $id, 'invert' => false];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $tile
     * @return list<array{title: string, variableId: int}>
     */
    public static function consumers(array $tile): array
    {
        $out = [];
        foreach (is_array($tile['flowConsumers'] ?? null) ? $tile['flowConsumers'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'variableId' => (int) ($row['variableId'] ?? 0),
            ];
        }
        return array_slice($out, 0, self::MAX_CONSUMERS);
    }

    /** @param array<string, mixed> $tile @return list<int> */
    public static function variableIds(array $tile): array
    {
        $ids = [];
        foreach (self::sources($tile) as $row) {
            if ($row['variableId'] > 0) {
                $ids[] = $row['variableId'];
            }
        }
        foreach (self::consumers($tile) as $row) {
            if ($row['variableId'] > 0) {
                $ids[] = $row['variableId'];
            }
        }
        return $ids;
    }

    /** @param array<string, mixed> $tile */
    public static function mode(array $tile): string
    {
        $mode = (string) ($tile['flowMode'] ?? 'signed');
        return in_array($mode, self::MODES, true) ? $mode : 'signed';
    }

    /**
     * Welche Knoten die Kachel zeigt (aus den Typen der Quellentabelle).
     *
     * @param array<string, mixed> $tile
     * @return array{pv: bool, gridIn: bool, gridOut: bool, batIn: bool, batOut: bool, soc: bool}
     */
    public static function nodes(array $tile): array
    {
        $has = ['pv' => false, 'gridIn' => false, 'gridOut' => false, 'batIn' => false, 'batOut' => false, 'soc' => false];
        foreach (self::sources($tile) as $row) {
            switch ($row['type']) {
                case 'pv': $has['pv'] = true; break;
                case 'gridImport': $has['gridIn'] = true; break;
                case 'gridExport': $has['gridOut'] = true; break;
                case 'grid': $has['gridIn'] = true; $has['gridOut'] = true; break;
                case 'batteryCharge': $has['batIn'] = true; break;
                case 'batteryDischarge': $has['batOut'] = true; break;
                case 'battery': $has['batIn'] = true; $has['batOut'] = true; break;
                case 'soc': $has['soc'] = true; break;
            }
        }
        return $has;
    }

    /**
     * Werte und Flüsse wie die Energieverteilung in Home Assistant.
     * Linien in fester Reihenfolge: PV→Haus, PV→Netz, PV→Batterie, Netz→Haus, Netz→Batterie, Batterie→Haus.
     * Jede Linie trägt den Anteil am größten Fluss in Prozent (0–100); Netz→Batterie ist negativ, wenn die Batterie ins Netz speist.
     *
     * @param array<string, mixed> $tile
     * @param list<float|null> $sourceValues Messwerte in Reihenfolge von sources()
     * @param list<float|null> $consumerValues Messwerte in Reihenfolge von consumers()
     * @return array{quality: string, flow: array<string, mixed>}
     */
    public static function compute(array $tile, array $sourceValues, array $consumerValues, int $decimals, string $unit): array
    {
        $sum = ['gridIn' => 0.0, 'gridOut' => 0.0, 'pv' => 0.0, 'batIn' => 0.0, 'batOut' => 0.0];
        $seen = false;
        $soc = null;
        foreach (self::sources($tile) as $index => $row) {
            $value = $sourceValues[$index] ?? null;
            if ($value === null) {
                continue;
            }
            $seen = true;
            if ($row['invert']) {
                $value = -$value;
            }
            switch ($row['type']) {
                case 'gridImport': $sum['gridIn'] += max(0.0, $value); break;
                case 'gridExport': $sum['gridOut'] += max(0.0, $value); break;
                case 'grid':
                    $sum['gridIn'] += max(0.0, $value);
                    $sum['gridOut'] += max(0.0, -$value);
                    break;
                case 'pv': $sum['pv'] += max(0.0, $value); break;
                case 'batteryCharge': $sum['batIn'] += max(0.0, $value); break;
                case 'batteryDischarge': $sum['batOut'] += max(0.0, $value); break;
                case 'battery':
                    $sum['batIn'] += max(0.0, $value);
                    $sum['batOut'] += max(0.0, -$value);
                    break;
                case 'soc':
                    if ($soc === null) {
                        $soc = $value;
                    }
                    break;
            }
        }

        $home = max(0.0, $sum['gridIn'] + $sum['pv'] + $sum['batOut'] - $sum['gridOut'] - $sum['batIn']);

        $pvHome = min($sum['pv'], $home);
        $pvLeft = $sum['pv'] - $pvHome;
        $pvBat = min($pvLeft, $sum['batIn']);
        $pvLeft -= $pvBat;
        $pvGrid = min($pvLeft, $sum['gridOut']);
        $batHome = min($sum['batOut'], max(0.0, $home - $pvHome));
        $gridHome = min($sum['gridIn'], max(0.0, $home - $pvHome - $batHome));
        $gridBat = max(0.0, $sum['batIn'] - $pvBat);
        $batGrid = max(0.0, $sum['gridOut'] - $pvGrid);
        $lines = [$pvHome, $pvGrid, $pvBat, $gridHome, $gridBat - $batGrid, $batHome];
        $peak = 0.0;
        foreach ($lines as $w) {
            $peak = max($peak, abs($w));
        }
        $pct = [];
        foreach ($lines as $w) {
            $share = $peak > 0 ? (int) round(100 * $w / $peak) : 0;
            $pct[] = abs($share) < 1 ? 0 : $share;
        }
        $mixTotal = $pvHome + $batHome + $gridHome;
        $mix = $mixTotal > 0
            ? [(int) round(100 * $pvHome / $mixTotal), (int) round(100 * $batHome / $mixTotal), (int) round(100 * $gridHome / $mixTotal)]
            : [0, 0, 0];

        $consumers = [];
        foreach (self::consumers($tile) as $index => $row) {
            $value = $consumerValues[$index] ?? null;
            $consumers[] = [
                't' => $row['title'] !== '' ? $row['title'] : 'Verbraucher',
                'v' => $value === null ? '-' : self::format(max(0.0, $value), $decimals, $unit),
            ];
        }

        return [
            'quality' => $seen ? 'ok' : 'empty',
            'flow' => [
                'pv' => self::format($sum['pv'], $decimals, $unit),
                'gridIn' => self::format($sum['gridIn'], $decimals, $unit),
                'gridOut' => self::format($sum['gridOut'], $decimals, $unit),
                'batIn' => self::format($sum['batIn'], $decimals, $unit),
                'batOut' => self::format($sum['batOut'], $decimals, $unit),
                'home' => self::format($home, $decimals, $unit),
                'soc' => $soc === null ? '' : number_format($soc, 0, ',', '') . ' %',
                'l' => $pct,
                'mix' => $mix,
                'c' => $consumers,
            ],
        ];
    }

    private static function format(float $w, int $decimals, string $unit): string
    {
        $text = number_format($w, $decimals, ',', '.');
        if ($unit !== '') {
            $text .= ' ' . $unit;
        }
        return $text;
    }
}
