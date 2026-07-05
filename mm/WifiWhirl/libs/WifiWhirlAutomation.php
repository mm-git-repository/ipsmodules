<?php

declare(strict_types=1);

/**
 * IPS-seitige Zeitplan- und PV-Gate-Logik für WifiWhirl (ohne IPS-Abhängigkeiten).
 */
final class WifiWhirlAutomation
{
    public const TYPE_PUMP = 'pump';
    public const TYPE_HEATER = 'heater';

    /**
     * @return list<array{
     *   active: bool,
     *   type: string,
     *   mo: bool, tu: bool, we: bool, th: bool, fr: bool, sa: bool, so: bool,
     *   start: string,
     *   end: string,
     *   targetTemp: int,
     *   pvGated: bool
     * }>
     */
    public static function parseRules(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $rules = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rule = self::normalizeRule($row);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function normalizeRule(array $row): ?array
    {
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        if (!in_array($type, [self::TYPE_PUMP, self::TYPE_HEATER], true)) {
            return null;
        }

        $start = self::normalizeTime((string) ($row['start'] ?? ''));
        $end = self::normalizeTime((string) ($row['end'] ?? ''));
        if ($start === null || $end === null || $start >= $end) {
            return null;
        }

        $targetTemp = (int) ($row['targetTemp'] ?? 30);
        $targetTemp = max(20, min(40, $targetTemp));

        return [
            'active' => self::toBool($row['active'] ?? false),
            'type' => $type,
            'mo' => self::toBool($row['mo'] ?? false),
            'tu' => self::toBool($row['tu'] ?? false),
            'we' => self::toBool($row['we'] ?? false),
            'th' => self::toBool($row['th'] ?? false),
            'fr' => self::toBool($row['fr'] ?? false),
            'sa' => self::toBool($row['sa'] ?? false),
            'so' => self::toBool($row['so'] ?? false),
            'start' => $start,
            'end' => $end,
            'targetTemp' => $targetTemp,
            'pvGated' => self::toBool($row['pvGated'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function isInWindow(array $rule, DateTimeInterface $now): bool
    {
        if (!$rule['active']) {
            return false;
        }

        if (!self::isWeekdayActive($rule, $now)) {
            return false;
        }

        $current = self::minutesOfDay($now);
        $start = self::timeToMinutes($rule['start']);
        $end = self::timeToMinutes($rule['end']);

        return $current >= $start && $current < $end;
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    public static function activeWindowEndUnix(array $rules, string $type, DateTimeInterface $now): ?int
    {
        $latest = null;
        foreach ($rules as $rule) {
            if (($rule['type'] ?? '') !== $type || !self::isInWindow($rule, $now)) {
                continue;
            }
            $end = self::windowEndUnix($rule, $now);
            if ($latest === null || $end > $latest) {
                $latest = $end;
            }
        }

        return $latest;
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function windowEndUnix(array $rule, DateTimeInterface $now): int
    {
        $dt = DateTimeImmutable::createFromInterface($now);
        [$hour, $minute] = array_map('intval', explode(':', $rule['end']));

        return (int) $dt->setTime($hour, $minute, 0)->getTimestamp();
    }

    /**
     * @param array{
     *   thresholdW: float,
     *   onDelaySec: int,
     *   offDelaySec: int,
     *   hysteresisW: float
     * } $config
     * @param array{
     *   gateOpen: bool,
     *   aboveSince: int,
     *   belowSince: int
     * } $state
     * @return array{gateOpen: bool, aboveSince: int, belowSince: int}
     */
    public static function evaluatePvGate(float $surplusW, array $config, array $state, int $now): array
    {
        $threshold = max(0.0, $config['thresholdW']);
        $hysteresis = max(0.0, $config['hysteresisW']);
        $onDelay = max(0, $config['onDelaySec']);
        $offDelay = max(0, $config['offDelaySec']);
        $offThreshold = max(0.0, $threshold - $hysteresis);

        $gateOpen = $state['gateOpen'];
        $aboveSince = $state['aboveSince'];
        $belowSince = $state['belowSince'];

        if ($gateOpen) {
            if ($surplusW < $offThreshold) {
                if ($belowSince <= 0) {
                    $belowSince = $now;
                }
                if ($now - $belowSince >= $offDelay) {
                    $gateOpen = false;
                    $belowSince = 0;
                    $aboveSince = 0;
                }
            } else {
                $belowSince = 0;
            }
        } else {
            if ($surplusW >= $threshold) {
                if ($aboveSince <= 0) {
                    $aboveSince = $now;
                }
                if ($now - $aboveSince >= $onDelay) {
                    $gateOpen = true;
                    $aboveSince = 0;
                    $belowSince = 0;
                }
            } else {
                $aboveSince = 0;
            }
        }

        return [
            'gateOpen' => $gateOpen,
            'aboveSince' => $aboveSince,
            'belowSince' => $belowSince,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array{
     *   thresholdW: float,
     *   onDelaySec: int,
     *   offDelaySec: int,
     *   hysteresisW: float
     * } $pvConfig
     * @param array{
     *   gateOpen: bool,
     *   aboveSince: int,
     *   belowSince: int
     * } $pvState
     * @return array{
     *   pump: bool,
     *   heater: bool,
     *   targetTemp: int,
     *   status: string,
     *   pvGateOpen: bool,
     *   pvState: array{gateOpen: bool, aboveSince: int, belowSince: int},
     *   pumpWindowActive: bool,
     *   heaterWindowActive: bool,
     *   pvGatedHeaterWindow: bool
     * }
     */
    public static function evaluate(
        array $rules,
        float $surplusW,
        DateTimeInterface $now,
        array $pvConfig,
        array $pvState,
        bool $pvVariableConfigured,
    ): array {
        $nowUnix = (int) $now->getTimestamp();

        $pumpWindowActive = false;
        $heaterWindowActive = false;
        $pvGatedHeaterWindow = false;
        $heaterTargetTemps = [];

        foreach ($rules as $rule) {
            if (!self::isInWindow($rule, $now)) {
                continue;
            }
            if ($rule['type'] === self::TYPE_PUMP) {
                $pumpWindowActive = true;
            }
            if ($rule['type'] === self::TYPE_HEATER) {
                $heaterWindowActive = true;
                if ($rule['pvGated']) {
                    $pvGatedHeaterWindow = true;
                }
            }
        }

        $needsPvGate = $heaterWindowActive && $pvGatedHeaterWindow;
        $updatedPvState = $pvState;
        if ($needsPvGate && $pvVariableConfigured) {
            $updatedPvState = self::evaluatePvGate($surplusW, $pvConfig, $pvState, $nowUnix);
        } elseif (!$needsPvGate) {
            $updatedPvState = [
                'gateOpen' => false,
                'aboveSince' => 0,
                'belowSince' => 0,
            ];
        }

        $pvGateOpen = $needsPvGate && $pvVariableConfigured && $updatedPvState['gateOpen'];

        $pumpDesired = $pumpWindowActive;
        $heaterDesired = false;
        $targetTemp = 30;

        foreach ($rules as $rule) {
            if ($rule['type'] !== self::TYPE_HEATER || !self::isInWindow($rule, $now)) {
                continue;
            }
            $allowed = !$rule['pvGated'] || ($pvVariableConfigured && $pvGateOpen);
            if (!$allowed) {
                continue;
            }
            $heaterDesired = true;
            $targetTemp = max($targetTemp, (int) $rule['targetTemp']);
        }

        $status = self::buildStatus(
            $pumpDesired,
            $heaterDesired,
            $heaterWindowActive,
            $pvGatedHeaterWindow,
            $pvVariableConfigured,
            $pvGateOpen,
            $surplusW,
            $pvConfig['thresholdW'],
        );

        return [
            'pump' => $pumpDesired,
            'heater' => $heaterDesired,
            'targetTemp' => $targetTemp,
            'status' => $status,
            'pvGateOpen' => $pvGateOpen,
            'pvState' => $updatedPvState,
            'pumpWindowActive' => $pumpWindowActive,
            'heaterWindowActive' => $heaterWindowActive,
            'pvGatedHeaterWindow' => $pvGatedHeaterWindow,
        ];
    }

    private static function buildStatus(
        bool $pumpDesired,
        bool $heaterDesired,
        bool $heaterWindowActive,
        bool $pvGatedHeaterWindow,
        bool $pvVariableConfigured,
        bool $pvGateOpen,
        float $surplusW,
        float $thresholdW,
    ): string {
        $parts = [];
        if ($pumpDesired) {
            $parts[] = 'Pumpe: Zeitfenster aktiv';
        }
        if ($heaterDesired) {
            $parts[] = 'Heizung: ein';
        } elseif ($heaterWindowActive && $pvGatedHeaterWindow) {
            if (!$pvVariableConfigured) {
                $parts[] = 'Heizung: PV-Variable fehlt';
            } elseif (!$pvGateOpen) {
                $parts[] = sprintf('Heizung: PV wartet (%.0f W, Schwelle %.0f W)', $surplusW, $thresholdW);
            }
        } elseif ($heaterWindowActive) {
            $parts[] = 'Heizung: aus';
        }
        if ($parts === []) {
            return 'Kein aktives Zeitfenster';
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function isWeekdayActive(array $rule, DateTimeInterface $now): bool
    {
        $key = self::weekdayKey((int) $now->format('N'));

        return $key !== '' && !empty($rule[$key]);
    }

    private static function weekdayKey(int $isoWeekday): string
    {
        return match ($isoWeekday) {
            1 => 'mo',
            2 => 'tu',
            3 => 'we',
            4 => 'th',
            5 => 'fr',
            6 => 'sa',
            7 => 'so',
            default => '',
        };
    }

    private static function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private static function timeToMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return ($h * 60) + $m;
    }

    private static function minutesOfDay(DateTimeInterface $dt): int
    {
        return ((int) $dt->format('G') * 60) + (int) $dt->format('i');
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));

            return in_array($v, ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
