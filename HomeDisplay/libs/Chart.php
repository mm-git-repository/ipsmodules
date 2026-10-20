<?php

declare(strict_types=1);

/** Verlaufsdaten aus dem IPS-Archiv für Diagramm-Kacheln. */
final class HomeDisplayChart
{
    private const ARCHIVE_MODULE = '{EF43BF2F-932F-421C-9794-D0CC2C47F6A3}';

    public const PERIODS = ['1h', '6h', '24h', '7d'];

    public const STYLES = ['line', 'area'];

    public const Y_MODES = ['auto', 'fixed'];

    private const MAX_POINTS = 56;

    public static function variableLogged(int $variableId): bool
    {
        if ($variableId <= 0 || !function_exists('IPS_VariableExists') || !IPS_VariableExists($variableId)) {
            return false;
        }
        if (function_exists('IPS_GetVariableLogging')) {
            return (bool) IPS_GetVariableLogging($variableId);
        }

        return true;
    }

    public static function normalizeYMode(string $mode): string
    {
        return in_array($mode, self::Y_MODES, true) ? $mode : 'auto';
    }

    /** @param array<string, mixed> $tile @return list<array{variableId: int, label: string}> */
    public static function seriesDefinitions(array $tile): array
    {
        $defs = [];
        if (isset($tile['chartSeries']) && is_array($tile['chartSeries'])) {
            foreach ($tile['chartSeries'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $variableId = (int) ($row['variableId'] ?? 0);
                if ($variableId <= 0) {
                    continue;
                }
                $defs[] = [
                    'variableId' => $variableId,
                    'label' => trim((string) ($row['label'] ?? '')),
                ];
            }
        }
        if ($defs === []) {
            $variableId = (int) ($tile['variableId'] ?? 0);
            if ($variableId > 0) {
                $defs[] = ['variableId' => $variableId, 'label' => ''];
            }
        }

        return $defs;
    }

    /**
     * @param list<list<float>> $allSeries
     * @return array{0: float, 1: float}
     */
    public static function yBounds(array $tile, array $allSeries): array
    {
        if (self::normalizeYMode((string) ($tile['chartYMode'] ?? 'auto')) === 'fixed') {
            $min = (float) ($tile['chartYMin'] ?? 0);
            $max = (float) ($tile['chartYMax'] ?? 100);
            if ($max <= $min) {
                $max = $min + 1.0;
            }

            return [$min, $max];
        }
        $flat = [];
        foreach ($allSeries as $series) {
            foreach ($series as $value) {
                $flat[] = (float) $value;
            }
        }
        if ($flat === []) {
            return [0.0, 1.0];
        }
        $min = min($flat);
        $max = max($flat);
        if ($min === $max) {
            $min -= 1.0;
            $max += 1.0;
        }

        return [$min, $max];
    }

    /** @return list<float> Verlauf für den Vortag (gleicher Zeitraum). */
    public static function seriesYesterday(int $variableId, string $period): array
    {
        if ($variableId <= 0) {
            return [];
        }
        $archive = self::archiveId();
        if ($archive <= 0) {
            return [];
        }
        $period = self::normalizePeriod($period);
        $end = time();
        if ($period === '1h') {
            return self::downsample(self::loggedSeries($archive, $variableId, $end - 7200, $end - 3600), self::MAX_POINTS);
        }
        if ($period === '6h') {
            return self::downsample(self::aggregatedSeries($archive, $variableId, 0, $end - 12 * 3600, $end - 6 * 3600), self::MAX_POINTS);
        }
        if ($period === '24h') {
            return self::downsample(self::aggregatedSeries($archive, $variableId, 0, $end - 2 * 86400, $end - 86400), self::MAX_POINTS);
        }

        return self::downsample(self::aggregatedSeries($archive, $variableId, 1, $end - 14 * 86400, $end - 7 * 86400), self::MAX_POINTS);
    }

    public static function normalizePeriod(string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : '24h';
    }

    public static function normalizeStyle(string $style): string
    {
        return in_array($style, self::STYLES, true) ? $style : 'area';
    }

    private static function archiveId(): int
    {
        static $id = -1;
        if ($id >= 0) {
            return $id;
        }
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            $id = 0;

            return 0;
        }
        $list = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE);
        $id = count($list) > 0 ? (int) $list[0] : 0;

        return $id;
    }

    /**
     * @return list<float> ältester Wert zuerst
     */
    public static function series(int $variableId, string $period): array
    {
        if ($variableId <= 0) {
            return [];
        }
        $archive = self::archiveId();
        if ($archive <= 0) {
            return [];
        }
        $end = time();
        $period = self::normalizePeriod($period);
        if ($period === '1h') {
            $values = self::loggedSeries($archive, $variableId, $end - 3600, $end);
        } elseif ($period === '6h') {
            $values = self::aggregatedSeries($archive, $variableId, 0, $end - 6 * 3600, $end);
        } elseif ($period === '24h') {
            $values = self::aggregatedSeries($archive, $variableId, 0, $end - 86400, $end);
        } else {
            $values = self::aggregatedSeries($archive, $variableId, 1, $end - 7 * 86400, $end);
        }

        return self::downsample($values, self::MAX_POINTS);
    }

    /** @return list<float> */
    private static function loggedSeries(int $archive, int $variableId, int $start, int $end): array
    {
        if (!function_exists('AC_GetLoggedValues')) {
            return [];
        }
        $raw = AC_GetLoggedValues($archive, $variableId, $start, $end, 500);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_reverse($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = self::numericValue($row['Value'] ?? null);
            if ($v !== null) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** @return list<float> */
    private static function aggregatedSeries(int $archive, int $variableId, int $level, int $start, int $end): array
    {
        if (!function_exists('AC_GetAggregatedValues')) {
            return [];
        }
        $raw = AC_GetAggregatedValues($archive, $variableId, $level, $start, $end, self::MAX_POINTS * 3);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_reverse($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = self::numericValue($row['Avg'] ?? $row['Sum'] ?? $row['Value'] ?? null);
            if ($v === null && isset($row['Max'])) {
                $v = self::numericValue($row['Max']);
            }
            if ($v !== null) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private static function numericValue(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (is_bool($raw)) {
            return $raw ? 1.0 : 0.0;
        }
        if (is_string($raw) && is_numeric($raw)) {
            return (float) $raw;
        }

        return null;
    }

    /** @param list<float> $values @return list<float> */
    private static function downsample(array $values, int $max): array
    {
        $n = count($values);
        if ($n <= $max) {
            return $values;
        }
        $step = $n / $max;
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $values[(int) floor($i * $step)];
        }

        return $out;
    }
}
