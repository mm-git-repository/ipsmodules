<?php

declare(strict_types=1);

require_once __DIR__ . '/GardenaSmartDevices.php';

/**
 * Gen2 schedule parse/encode for lwm2mserver schedule/* objects.
 */
final class GardenaSmartSchedules
{
    public const GEN2_MAX_SLOTS = 4;

    public static function supportsDeviceScheduleWrite(int $generation, string $deviceKind = 'valve'): bool
    {
        return $generation >= 2 && in_array($deviceKind, ['valve', 'water'], true);
    }

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
    private const REPETITION_TYPE_WEEKLY = 1;

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
     * Normalize editor rules: keep active entries with valid times, assign slots 0..3.
     *
     * @param list<array<string, mixed>> $rules
     * @return array{ok: bool, rules: list<array<string, mixed>>, error: string}
     */
    public static function normalizeRules(array $rules): array
    {
        $normalized = [];
        $activeCount = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (empty($rule['active'])) {
                continue;
            }
            $start = trim((string) ($rule['start'] ?? ''));
            $end = trim((string) ($rule['end'] ?? ''));
            if (self::hmToSeconds($start) === null || self::hmToSeconds($end) === null) {
                return [
                    'ok' => false,
                    'rules' => [],
                    'error' => 'Ungültige Zeitangabe (erwartet HH:MM)',
                ];
            }
            $hasDay = false;
            foreach (self::DAY_BITS as $key => $_) {
                if (!empty($rule[$key])) {
                    $hasDay = true;
                    break;
                }
            }
            if (!$hasDay) {
                return [
                    'ok' => false,
                    'rules' => [],
                    'error' => 'Jeder aktive Eintrag braucht mindestens einen Wochentag',
                ];
            }
            $activeCount++;
            if ($activeCount > self::GEN2_MAX_SLOTS) {
                return [
                    'ok' => false,
                    'rules' => [],
                    'error' => 'Maximal ' . self::GEN2_MAX_SLOTS . ' Zeitplan-Einträge möglich',
                ];
            }
            $entry = [
                'slot' => (string) (count($normalized)),
                'active' => true,
                'valve' => max(0, (int) ($rule['valve'] ?? 0)),
                'start' => self::secondsToHm((int) self::hmToSeconds($start)),
                'end' => self::secondsToHm((int) self::hmToSeconds($end)),
            ];
            foreach (self::DAY_BITS as $key => $_) {
                $entry[$key] = !empty($rule[$key]);
            }
            $normalized[] = $entry;
        }

        return ['ok' => true, 'rules' => $normalized, 'error' => ''];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>> WSS request arrays
     */
    public static function buildGen2WriteRequests(string $deviceId, array $rules): array
    {
        $normalized = self::normalizeRules($rules);
        $active = $normalized['ok'] ? $normalized['rules'] : [];
        $usedSlots = [];
        $requests = [];

        foreach ($active as $rule) {
            $slot = (string) ($rule['slot'] ?? '');
            if ($slot === '') {
                continue;
            }
            $startSec = self::hmToSeconds((string) ($rule['start'] ?? '00:00'));
            $endSec = self::hmToSeconds((string) ($rule['end'] ?? '00:00'));
            if ($startSec === null || $endSec === null) {
                continue;
            }
            $usedSlots[$slot] = true;
            $fields = [
                'actuator' => (int) ($rule['valve'] ?? 0),
                'start_offset_seconds' => $startSec,
                'end_offset_seconds' => $endSec,
                'repetition_type' => self::REPETITION_TYPE_WEEKLY,
                'repetition_value' => self::encodeDays($rule),
            ];
            foreach ($fields as $field => $value) {
                $requests[] = self::writeRequest($deviceId, $slot, $field, $value);
            }
        }

        for ($i = 0; $i < self::GEN2_MAX_SLOTS; $i++) {
            $slot = (string) $i;
            if (isset($usedSlots[$slot])) {
                continue;
            }
            // Minimal clear — enough to disable unused Gen2 slots
            foreach ([
                'start_offset_seconds' => 0,
                'end_offset_seconds' => 0,
                'repetition_value' => 0,
            ] as $field => $value) {
                $requests[] = self::writeRequest($deviceId, $slot, $field, $value);
            }
        }

        return $requests;
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

    /**
     * @return array<string, mixed>
     */
    private static function writeRequest(string $deviceId, string $slot, string $field, int $value): array
    {
        return [
            'op' => 'write',
            'entity' => [
                'device' => $deviceId,
                'service' => 'lwm2mserver',
                'path' => 'schedule/' . $slot . '/' . $field,
            ],
            'payload' => ['vi' => $value],
        ];
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

    /**
     * @param array<string, mixed> $rule
     */
    private static function encodeDays(array $rule): int
    {
        $all = true;
        foreach (self::DAY_BITS as $key => $_) {
            if (empty($rule[$key])) {
                $all = false;
                break;
            }
        }
        if ($all) {
            return self::REPETITION_DAILY;
        }
        $mask = 0;
        foreach (self::DAY_BITS as $key => $bit) {
            if (!empty($rule[$key])) {
                $mask |= (1 << $bit);
            }
        }

        return $mask;
    }

    private static function secondsToHm(int $seconds): string
    {
        $seconds = max(0, $seconds % 86400);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $h, $m);
    }

    private static function hmToSeconds(string $hm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return ($h * 3600) + ($min * 60);
    }
}
