<?php

declare(strict_types=1);

require_once __DIR__ . '/GardenaSmartDevices.php';

/**
 * Gen2 schedule parse/format for lwm2mserver schedule/* objects (read-only in IPS).
 */
final class GardenaSmartSchedules
{
    public const GEN2_MAX_SLOTS = 36;

    /** @var array<string, int> */
    private const DAY_BITS = [
        'mo' => 0,
        'tu' => 1,
        'we' => 2,
        'th' => 3,
        'fr' => 4,
        'sa' => 5,
        'so' => 6,
    ];

    private const REPETITION_DAILY = 16383;

    /**
     * @param array<string, mixed> $deviceData
     * @return list<array<string, mixed>>
     */
    public static function parseGen2Rules(array $deviceData): array
    {
        $schedules = $deviceData['schedule'] ?? null;
        if (!is_array($schedules)) {
            return [];
        }

        $rules = [];
        foreach ($schedules as $slotId => $sch) {
            if ($slotId === '_urn' || !is_array($sch)) {
                continue;
            }
            $startSec = (int) (GardenaSmartDevices::fieldValue($sch['start_offset_seconds'] ?? null) ?? 0);
            $endSec = (int) (GardenaSmartDevices::fieldValue($sch['end_offset_seconds'] ?? null) ?? 0);
            $repValue = (int) (GardenaSmartDevices::fieldValue($sch['repetition_value'] ?? null) ?? 0);
            if (self::isEmptySlot($startSec, $endSec, $repValue)) {
                continue;
            }
            $days = self::decodeDays($repValue);
            $rules[] = [
                'slot' => (string) $slotId,
                'active' => true,
                'valve' => (int) (GardenaSmartDevices::fieldValue($sch['actuator'] ?? null) ?? 0),
                'start' => self::secondsToHm($startSec),
                'end' => self::secondsToHm($endSec),
                'mo' => $days['mo'],
                'tu' => $days['tu'],
                'we' => $days['we'],
                'th' => $days['th'],
                'fr' => $days['fr'],
                'sa' => $days['sa'],
                'so' => $days['so'],
            ];
        }

        usort($rules, static fn(array $a, array $b): int => ((int) $a['slot']) <=> ((int) $b['slot']));

        return $rules;
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<string>
     */
    public static function formatRulesLines(array $rules): array
    {
        $lines = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['active'])) {
                continue;
            }
            $days = [];
            foreach (self::DAY_BITS as $k => $_) {
                if (!empty($rule[$k])) {
                    $days[] = strtoupper($k);
                }
            }
            $lines[] = sprintf(
                'Slot %s V%s %s–%s (%s)',
                (string) ($rule['slot'] ?? '?'),
                (string) ($rule['valve'] ?? '?'),
                (string) ($rule['start'] ?? '?'),
                (string) ($rule['end'] ?? '?'),
                $days === [] ? 'keine Tage' : implode(',', $days)
            );
        }

        return $lines;
    }

    private static function isEmptySlot(int $startSec, int $endSec, int $repValue): bool
    {
        return $startSec === 0 && $endSec === 0 && $repValue === 0;
    }

    /**
     * @return array<string, bool>
     */
    private static function decodeDays(int $value): array
    {
        $all = $value === self::REPETITION_DAILY || $value === 127 || $value >= 16383;
        $result = [];
        foreach (self::DAY_BITS as $key => $bit) {
            if ($all) {
                $result[$key] = true;
            } else {
                $result[$key] = ($value & (1 << $bit)) !== 0;
            }
        }

        return $result;
    }

    private static function secondsToHm(int $seconds): string
    {
        $seconds = max(0, $seconds % 86400);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $h, $m);
    }
}
