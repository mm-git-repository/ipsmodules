<?php

declare(strict_types=1);

/**
 * WebFront-Regel-Slots: Serialisierung zwischen kompakten Slot-Variablen und Property-JSON.
 */
final class WifiWhirlRuleSlots
{
    public const MAX_RULE_SLOTS = 4;

    public const PRESET_MO_FR = 0;
    public const PRESET_MO_SO = 1;
    public const PRESET_SA_SO = 2;
    public const PRESET_MO_SA = 3;

    /** @var array<int, array{mo: bool, tu: bool, we: bool, th: bool, fr: bool, sa: bool, so: bool}> */
    private const PRESET_WEEKDAYS = [
        self::PRESET_MO_FR => ['mo' => true, 'tu' => true, 'we' => true, 'th' => true, 'fr' => true, 'sa' => false, 'so' => false],
        self::PRESET_MO_SO => ['mo' => true, 'tu' => true, 'we' => true, 'th' => true, 'fr' => true, 'sa' => true, 'so' => true],
        self::PRESET_SA_SO => ['mo' => false, 'tu' => false, 'we' => false, 'th' => false, 'fr' => false, 'sa' => true, 'so' => true],
        self::PRESET_MO_SA => ['mo' => true, 'tu' => true, 'we' => true, 'th' => true, 'fr' => true, 'sa' => true, 'so' => false],
    ];

    /** @return array{mo: bool, tu: bool, we: bool, th: bool, fr: bool, sa: bool, so: bool} */
    public static function presetToWeekdays(int $preset): array
    {
        return self::PRESET_WEEKDAYS[self::clampPreset($preset)] ?? self::PRESET_WEEKDAYS[self::PRESET_MO_FR];
    }

    public static function weekdaysToPreset(
        bool $mo,
        bool $tu,
        bool $we,
        bool $th,
        bool $fr,
        bool $sa,
        bool $so,
    ): int {
        foreach (self::PRESET_WEEKDAYS as $preset => $days) {
            if (
                $days['mo'] === $mo
                && $days['tu'] === $tu
                && $days['we'] === $we
                && $days['th'] === $th
                && $days['fr'] === $fr
                && $days['sa'] === $sa
                && $days['so'] === $so
            ) {
                return $preset;
            }
        }

        return self::PRESET_MO_SO;
    }

    public static function weekdaysExactlyMatchPreset(
        bool $mo,
        bool $tu,
        bool $we,
        bool $th,
        bool $fr,
        bool $sa,
        bool $so,
    ): bool {
        foreach (self::PRESET_WEEKDAYS as $days) {
            if (
                $days['mo'] === $mo
                && $days['tu'] === $tu
                && $days['we'] === $we
                && $days['th'] === $th
                && $days['fr'] === $fr
                && $days['sa'] === $sa
                && $days['so'] === $so
            ) {
                return true;
            }
        }

        return false;
    }

    public static function weekdaysMatchPreset(
        bool $mo,
        bool $tu,
        bool $we,
        bool $th,
        bool $fr,
        bool $sa,
        bool $so,
    ): bool {
        return self::weekdaysExactlyMatchPreset($mo, $tu, $we, $th, $fr, $sa, $so);
    }

    /** @return array<string, mixed> */
    public static function defaultPumpSlot(): array
    {
        return [
            'active' => false,
            'weekdays' => self::PRESET_MO_FR,
            'start' => '08:00',
            'end' => '20:00',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaultHeaterSlot(): array
    {
        return [
            'active' => false,
            'weekdays' => self::PRESET_MO_FR,
            'start' => '08:00',
            'end' => '20:00',
            'targetTemp' => 38,
            'pvGated' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    public static function rulesToPumpSlots(array $rules): array
    {
        return self::rulesToSlots($rules, false);
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    public static function rulesToHeaterSlots(array $rules): array
    {
        return self::rulesToSlots($rules, true);
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @return list<array<string, mixed>>
     */
    public static function pumpSlotsToPropertyRows(array $slots): array
    {
        $rows = [];
        foreach ($slots as $slot) {
            if (!is_array($slot) || !self::toBool($slot['active'] ?? false)) {
                continue;
            }
            $rows[] = self::pumpSlotToPropertyRow($slot);
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @return list<array<string, mixed>>
     */
    public static function heaterSlotsToPropertyRows(array $slots): array
    {
        $rows = [];
        foreach ($slots as $slot) {
            if (!is_array($slot) || !self::toBool($slot['active'] ?? false)) {
                continue;
            }
            $rows[] = self::heaterSlotToPropertyRow($slot);
        }

        return $rows;
    }

    /**
     * @return array{type: string, index: int, field: string}|null
     */
    public static function parseSlotIdent(string $ident): ?array
    {
        if (preg_match('/^Auto(Pump|Heater)(\d+)(Active|Weekdays|Start|End|TargetTemp|PvGated)$/', $ident, $m) !== 1) {
            return null;
        }

        $index = (int) $m[2];
        if ($index < 1 || $index > self::MAX_RULE_SLOTS) {
            return null;
        }

        $field = $m[3];
        if ($m[1] === 'Pump' && in_array($field, ['TargetTemp', 'PvGated'], true)) {
            return null;
        }

        return [
            'type' => strtolower($m[1]),
            'index' => $index,
            'field' => lcfirst($field),
        ];
    }

    public static function isSlotIdent(string $ident): bool
    {
        return self::parseSlotIdent($ident) !== null;
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private static function rulesToSlots(array $rules, bool $heater): array
    {
        $slots = [];
        for ($i = 0; $i < self::MAX_RULE_SLOTS; ++$i) {
            $slots[] = $heater ? self::defaultHeaterSlot() : self::defaultPumpSlot();
        }

        $limit = min(count($rules), self::MAX_RULE_SLOTS);
        for ($i = 0; $i < $limit; ++$i) {
            $rule = $rules[$i];
            $slots[$i] = self::ruleToSlot($rule, $heater);
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private static function ruleToSlot(array $rule, bool $heater): array
    {
        $slot = $heater ? self::defaultHeaterSlot() : self::defaultPumpSlot();
        $slot['active'] = self::toBool($rule['active'] ?? false);
        $slot['weekdays'] = self::weekdaysToPreset(
            self::toBool($rule['mo'] ?? false),
            self::toBool($rule['tu'] ?? false),
            self::toBool($rule['we'] ?? false),
            self::toBool($rule['th'] ?? false),
            self::toBool($rule['fr'] ?? false),
            self::toBool($rule['sa'] ?? false),
            self::toBool($rule['so'] ?? false),
        );
        $slot['start'] = (string) ($rule['start'] ?? '08:00');
        $slot['end'] = (string) ($rule['end'] ?? '20:00');

        if ($heater) {
            $slot['targetTemp'] = max(20, min(40, (int) ($rule['targetTemp'] ?? 38)));
            $slot['pvGated'] = self::toBool($rule['pvGated'] ?? false);
        }

        return $slot;
    }

    /** @param array<string, mixed> $slot */
    private static function pumpSlotToPropertyRow(array $slot): array
    {
        $days = self::presetToWeekdays(self::clampPreset((int) ($slot['weekdays'] ?? self::PRESET_MO_FR)));

        return array_merge([
            'active' => true,
            'start' => trim((string) ($slot['start'] ?? '08:00')),
            'end' => trim((string) ($slot['end'] ?? '20:00')),
        ], $days);
    }

    /** @param array<string, mixed> $slot */
    private static function heaterSlotToPropertyRow(array $slot): array
    {
        return array_merge(self::pumpSlotToPropertyRow($slot), [
            'targetTemp' => max(20, min(40, (int) ($slot['targetTemp'] ?? 38))),
            'pvGated' => self::toBool($slot['pvGated'] ?? false),
        ]);
    }

    private static function clampPreset(int $preset): int
    {
        return max(self::PRESET_MO_FR, min(self::PRESET_MO_SA, $preset));
    }

    private static function toBool(mixed $value): bool
    {
        return WifiWhirlAutomation::toBool($value);
    }
}
