<?php

declare(strict_types=1);

/**
 * Model-number mapping and helpers (protocol knowledge, not a copy of LGPL sources).
 */
final class GardenaSmartDevices
{
    public const KIND_VALVE = 'valve';
    public const KIND_POWER = 'power';
    public const KIND_SENSOR = 'sensor';
    public const KIND_PUMP = 'pump';
    public const KIND_MOWER = 'mower';
    public const KIND_UNKNOWN = 'unknown';

    /** @var array<string, array{kind:string,name:string,generation:int,valves:int}> */
    private const MODELS = [
        '18869' => ['kind' => self::KIND_VALVE, 'name' => 'Water Control', 'generation' => 1, 'valves' => 1],
        '2812' => ['kind' => self::KIND_VALVE, 'name' => 'Water Control', 'generation' => 2, 'valves' => 1],
        '2814' => ['kind' => self::KIND_VALVE, 'name' => 'Dual Water Control', 'generation' => 2, 'valves' => 2],
        '2826' => ['kind' => self::KIND_VALVE, 'name' => 'Pipeline Water Control', 'generation' => 2, 'valves' => 1],
        '31653' => ['kind' => self::KIND_VALVE, 'name' => 'Irrigation Control', 'generation' => 1, 'valves' => 6],
        '469' => ['kind' => self::KIND_VALVE, 'name' => 'Irrigation Control', 'generation' => 2, 'valves' => 6],
        '35279' => ['kind' => self::KIND_POWER, 'name' => 'Power Adapter', 'generation' => 1, 'valves' => 0],
        '18845' => ['kind' => self::KIND_SENSOR, 'name' => 'smart Sensor', 'generation' => 1, 'valves' => 0],
        '19040' => ['kind' => self::KIND_SENSOR, 'name' => 'smart Sensor II', 'generation' => 1, 'valves' => 0],
        '22538' => ['kind' => self::KIND_PUMP, 'name' => 'Pump', 'generation' => 1, 'valves' => 0],
    ];

    public static function info(string $modelNumber): array
    {
        return self::MODELS[$modelNumber] ?? [
            'kind' => self::KIND_UNKNOWN,
            'name' => 'Unknown (' . $modelNumber . ')',
            'generation' => 0,
            'valves' => 0,
        ];
    }

    public static function moduleIdForKind(string $kind): ?string
    {
        return match ($kind) {
            self::KIND_VALVE => GardenaSmartGuids::VALVE,
            self::KIND_POWER => GardenaSmartGuids::POWER,
            self::KIND_SENSOR => GardenaSmartGuids::SENSOR,
            default => null,
        };
    }

    public static function serviceForGeneration(int $generation): string
    {
        return $generation >= 2 ? 'lwm2mserver' : 'lemonbeatd';
    }

    /**
     * @param array<string, mixed> $deviceData
     */
    public static function extractModelNumber(array $deviceData): string
    {
        $vs = $deviceData['device']['0']['model_number']['vs'] ?? '';

        return is_string($vs) ? $vs : '';
    }

    /**
     * @param array<string, mixed> $deviceData
     */
    public static function extractDisplayName(array $deviceData, string $fallback): string
    {
        $actuators = $deviceData['actuator'] ?? null;
        if (is_array($actuators)) {
            $names = [];
            foreach ($actuators as $key => $act) {
                if ($key === '_urn' || !is_array($act)) {
                    continue;
                }
                $n = $act['name']['vs'] ?? null;
                if (is_string($n) && $n !== '') {
                    $names[] = $n;
                }
            }
            if ($names !== []) {
                return implode(' / ', $names);
            }
        }

        foreach (['device_name', 'name', 'label'] as $field) {
            $vs = $deviceData['device']['0'][$field]['vs'] ?? null;
            if (is_string($vs) && $vs !== '') {
                return $vs;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $field
     */
    public static function fieldValue(mixed $field): mixed
    {
        if (!is_array($field)) {
            return $field;
        }
        foreach (['vb', 'vi', 'vf', 'vs', 'vo', 'vt', 'ai', 'as'] as $k) {
            if (array_key_exists($k, $field)) {
                return $field[$k];
            }
        }

        return null;
    }

    /**
     * Format Gen2 schedule objects as human-readable lines.
     *
     * @param array<string, mixed> $deviceData
     * @return list<string>
     */
    public static function formatDeviceSchedules(array $deviceData): array
    {
        $lines = [];
        $schedules = $deviceData['schedule'] ?? null;
        if (!is_array($schedules)) {
            $raw = $deviceData['lemonbeat']['0']['schedule_config']['vo'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $lines[] = 'Gen1 schedule_config (base64, ' . strlen($raw) . ' chars)';
            }

            return $lines;
        }

        $actuatorNames = [];
        $actuators = $deviceData['actuator'] ?? [];
        if (is_array($actuators)) {
            foreach ($actuators as $aid => $act) {
                if ($aid === '_urn' || !is_array($act)) {
                    continue;
                }
                $actuatorNames[(int) $aid] = (string) ($act['name']['vs'] ?? ('Ventil ' . $aid));
            }
        }

        foreach ($schedules as $sid => $sch) {
            if ($sid === '_urn' || !is_array($sch)) {
                continue;
            }
            $actuator = (int) (self::fieldValue($sch['actuator'] ?? null) ?? -1);
            $start = (int) (self::fieldValue($sch['start_offset_seconds'] ?? null) ?? 0);
            $end = (int) (self::fieldValue($sch['end_offset_seconds'] ?? null) ?? 0);
            $rep = (int) (self::fieldValue($sch['repetition_value'] ?? null) ?? 0);
            $name = $actuatorNames[$actuator] ?? ('Ventil ' . $actuator);
            $lines[] = sprintf(
                '#%s %s %s–%s (%s)',
                $sid,
                $name,
                self::secondsToHm($start),
                self::secondsToHm($end),
                self::repetitionLabel($rep)
            );
        }

        return $lines;
    }

    private static function secondsToHm(int $seconds): string
    {
        $seconds = max(0, $seconds % 86400);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $h, $m);
    }

    private static function repetitionLabel(int $value): string
    {
        if ($value === 0) {
            return 'keine Tage';
        }
        // Observed 16383 ≈ täglich; bitmask decoding may vary by FW — keep compact.
        if ($value >= 127) {
            return 'täglich';
        }
        $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $parts = [];
        for ($i = 0; $i < 7; $i++) {
            if (($value & (1 << $i)) !== 0) {
                $parts[] = $days[$i];
            }
        }

        return $parts === [] ? ('mask ' . $value) : implode(',', $parts);
    }
}
