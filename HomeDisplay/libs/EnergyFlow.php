<?php

declare(strict_types=1);

/**
 * Leistungsfluss wie Home Assistant „Power Flow Card Plus“:
 * getrennte oder vorzeichenbehaftete Entitäten, daraus Linienflüsse berechnen.
 */
final class HomeDisplayEnergyFlow
{
    public const MODES = ['signed', 'split'];

    /** @param array<string, mixed> $tile @return list<string> */
    public static function variablePropertyKeys(array $tile): array
    {
        $keys = self::mode($tile) === 'split'
            ? ['gridImportVariableId', 'gridExportVariableId', 'pvVariableId', 'batteryChargeVariableId', 'batteryDischargeVariableId']
            : ['gridVariableId', 'pvVariableId', 'batteryVariableId'];
        if (self::homeOverride($tile)) {
            $keys[] = 'loadVariableId';
        }
        $soc = (int) ($tile['batterySocVariableId'] ?? 0);
        if ($soc > 0) {
            $keys[] = 'batterySocVariableId';
        }
        return $keys;
    }

    /** @param array<string, mixed> $tile */
    public static function mode(array $tile): string
    {
        $mode = (string) ($tile['flowMode'] ?? 'signed');
        return in_array($mode, self::MODES, true) ? $mode : 'signed';
    }

    /** @param array<string, mixed> $tile */
    public static function homeOverride(array $tile): bool
    {
        return !array_key_exists('homeOverride', $tile) || (bool) $tile['homeOverride'];
    }

    /**
     * @param array<string, mixed> $tile
     * @param array<string, float|null> $readings
     * @return array<string, mixed>
     */
    public static function compute(array $tile, array $readings, int $decimals, string $unit): array
    {
        $tol = max(0.0, (float) ($tile['flowThreshold'] ?? 15));
        $homeOverride = self::homeOverride($tile);

        if (self::mode($tile) === 'split') {
            $gridIn = max(0.0, (float) ($readings['gridImport'] ?? 0));
            $gridOut = max(0.0, (float) ($readings['gridExport'] ?? 0));
            $pv = max(0.0, (float) ($readings['pv'] ?? 0));
            $batIn = max(0.0, (float) ($readings['batteryCharge'] ?? 0));
            $batOut = max(0.0, (float) ($readings['batteryDischarge'] ?? 0));
        } else {
            $gridSigned = (float) ($readings['grid'] ?? 0);
            $gridIn = max(0.0, $gridSigned);
            $gridOut = max(0.0, -$gridSigned);
            $pv = max(0.0, (float) ($readings['pv'] ?? 0));
            $batSigned = (float) ($readings['battery'] ?? 0);
            $batIn = max(0.0, $batSigned);
            $batOut = max(0.0, -$batSigned);
        }

        $homeCalc = $gridIn + $pv + $batOut - $gridOut - $batIn;
        $loadMeas = $readings['load'] ?? null;
        $home = ($homeOverride && $loadMeas !== null)
            ? max(0.0, (float) $loadMeas)
            : max(0.0, $homeCalc);

        $gridToHome = $gridIn;
        $homeToGrid = $gridOut;
        $batteryToHome = $batOut;
        $homeToBattery = $batIn;

        $solarToHome = min($pv, max(0.0, $home));
        $pvRemaining = $pv - $solarToHome;
        $solarToBattery = min($pvRemaining, $homeToBattery);
        $pvRemaining -= $solarToBattery;
        $solarToGrid = min($pvRemaining, $homeToGrid);
        $pvRemaining -= $solarToGrid;
        if ($pvRemaining > $tol) {
            $solarToHome += $pvRemaining;
        }

        $lineWatts = [
            'gridToHome' => $gridToHome,
            'homeToGrid' => $homeToGrid,
            'solarToHome' => $solarToHome,
            'solarToGrid' => $solarToGrid,
            'solarToBattery' => $solarToBattery,
            'batteryToHome' => $batteryToHome,
            'homeToBattery' => $homeToBattery,
        ];
        $lines = [];
        foreach ($lineWatts as $id => $w) {
            $lines[$id] = [
                'w' => $w,
                'active' => $w > $tol,
            ];
        }

        $gridSigned = $gridIn - $gridOut;
        $batSigned = $batIn - $batOut;

        $nodes = [
            'grid' => self::node($gridSigned, $decimals, $unit),
            'pv' => self::node($pv, $decimals, $unit),
            'load' => self::node($home, $decimals, $unit),
            'battery' => self::node($batSigned, $decimals, $unit),
        ];

        $soc = $readings['soc'] ?? null;
        $result = [
            'grid' => $nodes['grid'],
            'pv' => $nodes['pv'],
            'load' => $nodes['load'],
            'battery' => $nodes['battery'],
            'lines' => $lines,
            'homeCalculated' => !$homeOverride || $loadMeas === null,
            'homeCalcW' => $homeCalc,
        ];
        if ($soc !== null) {
            $result['soc'] = [
                'w' => (float) $soc,
                'text' => number_format((float) $soc, max(0, min(1, (int) ($tile['socDecimals'] ?? 0))), ',', '') . ' %',
            ];
        }
        return $result;
    }

    /** @return array{w: float, text: string} */
    private static function node(float $w, int $decimals, string $unit): array
    {
        $text = number_format($w, $decimals, ',', '');
        if ($unit !== '') {
            $text .= ' ' . $unit;
        }
        return ['w' => $w, 'text' => $text];
    }
}
