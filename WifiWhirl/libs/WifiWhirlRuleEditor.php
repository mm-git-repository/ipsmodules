<?php

declare(strict_types=1);

/**
 * Konvertierung zwischen Property-Regelzeilen (mo–so) und Editor-Zeilen (Wochentags-Preset).
 */
final class WifiWhirlRuleEditor
{
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

    /** @return list<array<string, mixed>> */
    public static function editorRowsFromProperty(mixed $raw, bool $heater): array
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

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $editor = self::propertyRowToEditorRow($row, $heater);
            if ($editor !== null) {
                $rows[] = $editor;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $editorRows
     * @return list<array<string, mixed>>
     */
    public static function propertyRowsFromEditor(array $editorRows, bool $heater): array
    {
        $rows = [];
        foreach ($editorRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $property = self::editorRowToPropertyRow($row, $heater);
            if ($property !== null) {
                $rows[] = $property;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $row */
    private static function propertyRowToEditorRow(array $row, bool $heater): ?array
    {
        $mo = WifiWhirlAutomation::toBool($row['mo'] ?? false);
        $tu = WifiWhirlAutomation::toBool($row['tu'] ?? false);
        $we = WifiWhirlAutomation::toBool($row['we'] ?? false);
        $th = WifiWhirlAutomation::toBool($row['th'] ?? false);
        $fr = WifiWhirlAutomation::toBool($row['fr'] ?? false);
        $sa = WifiWhirlAutomation::toBool($row['sa'] ?? false);
        $so = WifiWhirlAutomation::toBool($row['so'] ?? false);

        $editor = [
            'active' => WifiWhirlAutomation::toBool($row['active'] ?? false),
            'weekdays' => self::weekdaysToPreset($mo, $tu, $we, $th, $fr, $sa, $so),
            'start' => trim((string) ($row['start'] ?? '08:00')),
            'end' => trim((string) ($row['end'] ?? '20:00')),
        ];

        if ($heater) {
            $editor['targetTemp'] = max(20, min(40, (int) ($row['targetTemp'] ?? 38)));
            $editor['pvGated'] = WifiWhirlAutomation::toBool($row['pvGated'] ?? false);
        }

        return $editor;
    }

    /** @param array<string, mixed> $row */
    private static function editorRowToPropertyRow(array $row, bool $heater): ?array
    {
        if (!WifiWhirlAutomation::toBool($row['active'] ?? false)) {
            return null;
        }

        $days = self::presetToWeekdays((int) ($row['weekdays'] ?? self::PRESET_MO_FR));
        $property = array_merge([
            'active' => true,
            'start' => trim((string) ($row['start'] ?? '08:00')),
            'end' => trim((string) ($row['end'] ?? '20:00')),
        ], $days);

        if ($heater) {
            $property['targetTemp'] = max(20, min(40, (int) ($row['targetTemp'] ?? 38)));
            $property['pvGated'] = WifiWhirlAutomation::toBool($row['pvGated'] ?? false);
        }

        return $property;
    }

    private static function clampPreset(int $preset): int
    {
        return max(self::PRESET_MO_FR, min(self::PRESET_MO_SA, $preset));
    }
}
