<?php

declare(strict_types=1);

/**
 * Konvertierung zwischen Property-Regelzeilen (mo–so) und Editor-Zeilen (HTML-Kachel).
 */
final class WifiWhirlRuleEditor
{
    private const TARGET_TEMP_MIN = 7;
    private const TARGET_TEMP_MAX = 40;

    /** @var list<string> */
    private const DAY_KEYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'so'];

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

    /** @param array<string, mixed> $row */
    private static function propertyRowToEditorRow(array $row, bool $heater): ?array
    {
        $editor = [
            'active' => WifiWhirlAutomation::toBool($row['active'] ?? false),
            'start' => trim((string) ($row['start'] ?? '08:00')),
            'end' => trim((string) ($row['end'] ?? '20:00')),
        ];

        foreach (self::DAY_KEYS as $day) {
            $editor[$day] = WifiWhirlAutomation::toBool($row[$day] ?? false);
        }

        if ($heater) {
            $editor['targetTemp'] = self::clampTargetTemp((int) ($row['targetTemp'] ?? 38));
            $editor['pvGated'] = WifiWhirlAutomation::toBool($row['pvGated'] ?? false);
        }

        return $editor;
    }

    /** @param array<string, mixed> $row */
    private static function editorRowToPropertyRow(array $row, bool $heater): ?array
    {
        $property = [
            'active' => WifiWhirlAutomation::toBool($row['active'] ?? false),
            'start' => trim((string) ($row['start'] ?? '08:00')),
            'end' => trim((string) ($row['end'] ?? '20:00')),
        ];

        foreach (self::DAY_KEYS as $day) {
            $property[$day] = WifiWhirlAutomation::toBool($row[$day] ?? false);
        }

        if ($heater) {
            $property['targetTemp'] = self::clampTargetTemp((int) ($row['targetTemp'] ?? 38));
            $property['pvGated'] = WifiWhirlAutomation::toBool($row['pvGated'] ?? false);
        }

        return $property;
    }

    private static function clampTargetTemp(int $value): int
    {
        return max(self::TARGET_TEMP_MIN, min(self::TARGET_TEMP_MAX, $value));
    }
}
