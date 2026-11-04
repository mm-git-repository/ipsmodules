<?php

declare(strict_types=1);

/** Verlaufsdaten aus dem IPS-Archiv für Diagramm-Kacheln. */
final class HomeDisplayChart
{
    /** Archive Control von IP-Symcon. */
    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

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
        $period = self::normalizePeriod($period);
        $end = time();
        $span = match ($period) {
            '1h' => 3600,
            '6h' => 6 * 3600,
            '24h' => 86400,
            default => 7 * 86400,
        };

        return self::seriesBetween($variableId, $period, $end - 2 * $span, $end - $span);
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
        if ($id === 0 && function_exists('IPS_GetInstanceList') && function_exists('IPS_GetInstance')) {
            foreach (IPS_GetInstanceList() as $candidate) {
                $name = (string) (IPS_GetInstance($candidate)['ModuleInfo']['ModuleName'] ?? '');
                if (strcasecmp($name, 'Archive Control') === 0 || strcasecmp($name, 'Archiv') === 0) {
                    $id = (int) $candidate;
                    break;
                }
            }
        }

        return $id;
    }

    /**
     * @return list<float> ältester Wert zuerst
     */
    public static function series(int $variableId, string $period): array
    {
        $period = self::normalizePeriod($period);

        return self::seriesBetween($variableId, $period, 0, time());
    }

    /** @var array{archive: int, logged: bool, agg: int, log: int, out: int, note: string} */
    private static array $lastTrace = [
        'archive' => 0,
        'logged' => false,
        'agg' => 0,
        'log' => 0,
        'out' => 0,
        'note' => 'noData',
    ];

    /** @return array{archive: int, logged: bool, agg: int, log: int, out: int, note: string} */
    public static function lastTrace(): array
    {
        return self::$lastTrace;
    }

    /**
     * Aggregation mit echter Spanne bleibt die Quelle. Sonst entsteht der Stufenverlauf
     * aus dem letzten Wert davor, den geloggten Werten und dem aktuellen Wert.
     * Ein konstanter Verlauf wird als waagerechte Linie gezeichnet.
     *
     * @return list<float>
     */
    private static function seriesBetween(int $variableId, string $period, int $rangeStart, int $rangeEnd): array
    {
        if ($variableId <= 0 || $rangeEnd <= 0) {
            self::rememberTrace(0, false, 0, 0, 0, 'noData');

            return [];
        }
        $loggedFlag = self::variableLogged($variableId);
        if (!$loggedFlag) {
            self::rememberTrace(0, false, 0, 0, 0, 'notLogged');

            return [];
        }
        $archive = self::archiveId();
        if ($archive <= 0) {
            self::rememberTrace(0, true, 0, 0, 0, 'noArchive');

            return [];
        }
        [$start, $level] = self::alignedWindow($period, $rangeStart, $rangeEnd);
        $aggregated = self::aggregatedSeries($archive, $variableId, $level, $start, $rangeEnd);
        if (self::seriesHasSpan($aggregated)) {
            $out = self::downsample(self::roundSeries($aggregated), self::MAX_POINTS);
            self::rememberTrace($archive, true, count($aggregated), 0, count($out), '');

            return $out;
        }
        $prior = self::loggedSeries($archive, $variableId, 0, max(0, $start), 1);
        $logged = self::loggedSeries($archive, $variableId, $start, $rangeEnd, 1000);
        $values = [];
        if ($prior !== []) {
            $values[] = $prior[0];
        }
        foreach ($logged as $value) {
            if ($values !== [] && abs($values[count($values) - 1] - $value) < 0.000001) {
                continue;
            }
            $values[] = $value;
        }
        if ($rangeEnd >= time() - 5) {
            $current = self::currentNumeric($variableId);
            if ($current !== null && ($values === [] || abs($values[count($values) - 1] - $current) > 0.000001)) {
                $values[] = $current;
            }
        }
        if (count($values) === 1) {
            $values[] = $values[0];
        }
        if (count($values) < 2) {
            self::rememberTrace($archive, true, count($aggregated), count($logged), 0, 'noData');

            return [];
        }
        $out = self::downsample(self::roundSeries($values), self::MAX_POINTS);
        if (count($out) < 2) {
            $out = [round($values[0], 3), round($values[count($values) - 1], 3)];
        }
        self::rememberTrace($archive, true, count($aggregated), count($logged), count($out), '');

        return $out;
    }

    private static function rememberTrace(int $archive, bool $logged, int $agg, int $log, int $out, string $note): void
    {
        self::$lastTrace = [
            'archive' => $archive,
            'logged' => $logged,
            'agg' => $agg,
            'log' => $log,
            'out' => $out,
            'note' => $note,
        ];
    }

    private static function currentNumeric(int $variableId): ?float
    {
        if ($variableId <= 0 || !function_exists('GetValue') || !function_exists('IPS_VariableExists') || !IPS_VariableExists($variableId)) {
            return null;
        }

        return self::numericValue(GetValue($variableId));
    }

    /** @param list<float> $values */
    private static function seriesHasSpan(array $values): bool
    {
        if (count($values) < 2) {
            return false;
        }
        $min = $values[0];
        $max = $values[0];
        foreach ($values as $value) {
            if ($value < $min) {
                $min = $value;
            }
            if ($value > $max) {
                $max = $value;
            }
        }

        return ($max - $min) > 0.000001;
    }

    /** @return array{0: int, 1: int} Start auf der Grenze, dazu die Aggregationsstufe. */
    private static function alignedWindow(string $period, int $rawStart, int $end): array
    {
        if ($period === '1h') {
            $start = $rawStart > 0 ? $rawStart : $end - 3600;
            $start -= $start % 300;

            return [$start, 5];
        }
        if ($period === '6h' || $period === '24h') {
            $start = $rawStart > 0 ? $rawStart : $end - ($period === '6h' ? 6 * 3600 : 86400);
            $start = (int) strtotime(date('Y-m-d H:00:00', $start));

            return [$start, 0];
        }
        $start = $rawStart > 0 ? $rawStart : $end - 7 * 86400;
        $start = (int) strtotime(date('Y-m-d 00:00:00', $start));

        return [$start, 1];
    }

    /** @param list<float> $values @return list<float> */
    private static function ensureSpan(int $archive, int $variableId, int $start, array $values): array
    {
        if (count($values) >= 2) {
            return $values;
        }
        $prior = self::loggedSeries($archive, $variableId, 0, max(0, $start), 1);
        $baseline = $prior[0] ?? ($values[0] ?? null);
        if ($baseline === null) {
            return [];
        }
        if ($values === [] || $values[0] === $baseline) {
            return [$baseline, $baseline];
        }

        return [$baseline, $values[0]];
    }

    /** @param list<float> $values @return list<float> */
    private static function roundSeries(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_finite($value)) {
                continue;
            }
            $out[] = round($value, 3);
        }

        return $out;
    }

    /** @return list<float> */
    private static function loggedSeries(int $archive, int $variableId, int $start, int $end, int $limit = 500): array
    {
        if (!function_exists('AC_GetLoggedValues')) {
            return [];
        }
        $raw = AC_GetLoggedValues($archive, $variableId, $start, $end, $limit);
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
