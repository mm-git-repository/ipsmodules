<?php

declare(strict_types=1);

/**
 * Calculated water usage helpers (open duration × liters/hour).
 */
final class GardenaSmartWaterUsage
{
    /**
     * Built-in presets (id => meta). Custom gateway presets use id "custom:*".
     *
     * @return list<array{id:string,label:string,length:string,pressure:string,litersPerHour:float}>
     */
    public static function builtinPresets(): array
    {
        return [
            [
                'id' => 'builtin:custom',
                'label' => 'Benutzerdefiniert',
                'length' => '',
                'pressure' => '',
                'litersPerHour' => 0.0,
            ],
            [
                'id' => 'builtin:perl-20m',
                'label' => 'Gardena Perl-Regner',
                'length' => '20 m',
                'pressure' => '2 bar',
                'litersPerHour' => 300.0,
            ],
            [
                'id' => 'builtin:spray',
                'label' => 'Sprühregner (mittel)',
                'length' => '',
                'pressure' => '2 bar',
                'litersPerHour' => 500.0,
            ],
            [
                'id' => 'builtin:drip',
                'label' => 'Tropfrohr / Micro-Drip',
                'length' => '10 m',
                'pressure' => '1.5 bar',
                'litersPerHour' => 120.0,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $gatewayPresets
     * @return list<array{id:string,label:string,length:string,pressure:string,litersPerHour:float}>
     */
    public static function mergePresets(array $gatewayPresets): array
    {
        $out = self::builtinPresets();
        foreach ($gatewayPresets as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? $row['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = 'custom:' . $idx . ':' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($label));
            }
            $out[] = [
                'id' => $id,
                'label' => $label,
                'length' => trim((string) ($row['length'] ?? '')),
                'pressure' => trim((string) ($row['pressure'] ?? '')),
                'litersPerHour' => max(0.0, (float) ($row['litersPerHour'] ?? $row['lph'] ?? 0)),
            ];
        }

        return $out;
    }

    public static function litersForSeconds(float $litersPerHour, int $seconds): float
    {
        if ($litersPerHour <= 0 || $seconds <= 0) {
            return 0.0;
        }

        return round(($litersPerHour * $seconds) / 3600.0, 3);
    }

    public static function formatLiters(float $liters): string
    {
        if ($liters >= 100) {
            return number_format($liters, 0, ',', '.') . ' L';
        }
        if ($liters >= 10) {
            return number_format($liters, 1, ',', '.') . ' L';
        }

        return number_format($liters, 2, ',', '.') . ' L';
    }

    /**
     * @param array<string, mixed> $state UsageState JSON structure
     * @return array<string, mixed>
     */
    public static function emptyValveState(): array
    {
        $nowKeys = self::periodKeys(time());

        return [
            'openSince' => 0,
            'lastTick' => 0,
            'sessionLiters' => 0.0,
            'dayKey' => $nowKeys['day'],
            'weekKey' => $nowKeys['week'],
            'yearKey' => $nowKeys['year'],
            'day' => 0.0,
            'week' => 0.0,
            'year' => 0.0,
            'total' => 0.0,
        ];
    }

    /**
     * @return array{day:string,week:string,year:string}
     */
    public static function periodKeys(int $ts): array
    {
        return [
            'day' => date('Y-m-d', $ts),
            'week' => date('o-\WW', $ts),
            'year' => date('Y', $ts),
        ];
    }

    /**
     * Apply period rollover in-place.
     *
     * @param array<string, mixed> $valveState
     */
    public static function applyRollover(array &$valveState, int $ts): void
    {
        $keys = self::periodKeys($ts);
        if ((string) ($valveState['dayKey'] ?? '') !== $keys['day']) {
            $valveState['day'] = 0.0;
            $valveState['dayKey'] = $keys['day'];
        }
        if ((string) ($valveState['weekKey'] ?? '') !== $keys['week']) {
            $valveState['week'] = 0.0;
            $valveState['weekKey'] = $keys['week'];
        }
        if ((string) ($valveState['yearKey'] ?? '') !== $keys['year']) {
            $valveState['year'] = 0.0;
            $valveState['yearKey'] = $keys['year'];
        }
    }

    /**
     * @param array<string, mixed> $valveState
     */
    public static function addLiters(array &$valveState, float $liters): void
    {
        if ($liters <= 0) {
            return;
        }
        $valveState['day'] = round(((float) ($valveState['day'] ?? 0)) + $liters, 3);
        $valveState['week'] = round(((float) ($valveState['week'] ?? 0)) + $liters, 3);
        $valveState['year'] = round(((float) ($valveState['year'] ?? 0)) + $liters, 3);
        $valveState['total'] = round(((float) ($valveState['total'] ?? 0)) + $liters, 3);
        $valveState['sessionLiters'] = round(((float) ($valveState['sessionLiters'] ?? 0)) + $liters, 3);
    }

    /**
     * Update tracking for one valve based on open state.
     *
     * @param array<string, mixed> $valveState
     * @return array<string, mixed>
     */
    public static function tick(array $valveState, bool $open, float $litersPerHour, bool $trackingEnabled, int $now): array
    {
        if ($valveState === []) {
            $valveState = self::emptyValveState();
        }
        self::applyRollover($valveState, $now);

        if (!$trackingEnabled || $litersPerHour <= 0) {
            if (!$open) {
                $valveState['openSince'] = 0;
                $valveState['lastTick'] = 0;
                $valveState['sessionLiters'] = 0.0;
            }

            return $valveState;
        }

        $openSince = (int) ($valveState['openSince'] ?? 0);
        $lastTick = (int) ($valveState['lastTick'] ?? 0);

        if ($open) {
            if ($openSince <= 0) {
                $valveState['openSince'] = $now;
                $valveState['lastTick'] = $now;
                $valveState['sessionLiters'] = 0.0;

                return $valveState;
            }
            $from = $lastTick > 0 ? $lastTick : $openSince;
            $seconds = max(0, $now - $from);
            self::addLiters($valveState, self::litersForSeconds($litersPerHour, $seconds));
            $valveState['lastTick'] = $now;

            return $valveState;
        }

        // closing
        if ($openSince > 0) {
            $from = $lastTick > 0 ? $lastTick : $openSince;
            $seconds = max(0, $now - $from);
            self::addLiters($valveState, self::litersForSeconds($litersPerHour, $seconds));
        }
        $valveState['openSince'] = 0;
        $valveState['lastTick'] = 0;
        $valveState['sessionLiters'] = 0.0;

        return $valveState;
    }

    /**
     * @param array<string, mixed> $valveState
     * @return array<string, mixed>
     */
    public static function resetCounters(array $valveState): array
    {
        $keys = self::periodKeys(time());
        $valveState['day'] = 0.0;
        $valveState['week'] = 0.0;
        $valveState['year'] = 0.0;
        $valveState['total'] = 0.0;
        $valveState['sessionLiters'] = 0.0;
        $valveState['dayKey'] = $keys['day'];
        $valveState['weekKey'] = $keys['week'];
        $valveState['yearKey'] = $keys['year'];
        // keep openSince/lastTick if currently open so tracking continues

        return $valveState;
    }
}
