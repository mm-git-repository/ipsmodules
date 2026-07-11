<?php

declare(strict_types=1);

/**
 * DP-Zuordnung und Skalierung für Yieryi / PH-W218-ähnliche Tuya-Sensoren.
 */
final class TuyaWaterQualityMapping
{
    /** Standard-Mapping (8-in-1 / PH-W218) — nach Geräte-Test anpassen. */
    public const DEFAULT_JSON = '{"ph":{"dp":106,"scale":0.01},"temperature":{"dp":8,"scale":0.1},"tds":{"dp":111,"scale":1},"ec":{"dp":116,"scale":1},"orp":{"dp":131,"scale":1}}';

    /** YINMIK Water Quality Tester (szjcy, product u5xgcpcngk3pfxb4). */
    public const YINMIK_SZJCY_JSON = '{"tds":{"dp":1,"scale":0.001},"temperature":{"dp":2,"scale":0.1}}';

    public static function presetForProductId(string $productId): string
    {
        $productId = strtolower(trim($productId));
        if ($productId === 'u5xgcpcngk3pfxb4') {
            return self::YINMIK_SZJCY_JSON;
        }

        return self::DEFAULT_JSON;
    }

    public static function presetForCategory(string $category): string
    {
        $category = strtolower(trim($category));
        if ($category === 'szjcy') {
            return self::YINMIK_SZJCY_JSON;
        }

        return self::DEFAULT_JSON;
    }

    /**
     * @return array<string, array{dp: int, scale: float}>
     */
    public static function parse(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode(self::DEFAULT_JSON, true);
        }

        if (!is_array($decoded)) {
            $decoded = json_decode(self::DEFAULT_JSON, true);
        }

        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $ident => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $dp = (int) ($entry['dp'] ?? 0);
            if ($dp <= 0) {
                continue;
            }
            $result[(string) $ident] = [
                'dp' => $dp,
                'scale' => (float) ($entry['scale'] ?? 1.0),
            ];
        }

        return $result;
    }

    /** Cloud-Status-Codes (Tuya HA API) → Messgröße. */
    private const CLOUD_CODE_ALIASES = [
        'tds' => ['tds', 'tds_in', 'tds_out', 'tdslife'],
        'temperature' => ['temperature', 'temp', 'temp_current', 'temp_current_f'],
        'ph' => ['ph', 'ph_value'],
        'ec' => ['ec', 'conductivity', 'ec_value'],
        'orp' => ['orp', 'orp_value'],
    ];

    /**
     * @param array<string|int, mixed> $dps
     * @param array<string, array{dp: int, scale: float}> $mapping
     * @return array<string, float|null>
     */
    public static function apply(array $dps, array $mapping): array
    {
        $values = [
            'ph' => null,
            'orp' => null,
            'ec' => null,
            'tds' => null,
            'temperature' => null,
        ];

        foreach ($mapping as $ident => $cfg) {
            $raw = self::pickRawValue($ident, $dps, $cfg);
            if ($raw === null || $raw === '' || !is_numeric($raw)) {
                continue;
            }

            $scale = self::resolveScale($ident, $dps, $cfg['scale']);
            $values[$ident] = round(((float) $raw) * $scale, 3);
        }

        return $values;
    }

    /**
     * @param array<string|int, mixed> $dps
     * @param array{dp: int, scale: float} $cfg
     */
    private static function pickRawValue(string $ident, array $dps, array $cfg): mixed
    {
        $dp = (string) $cfg['dp'];
        if (array_key_exists($dp, $dps)) {
            return $dps[$dp];
        }
        if (array_key_exists((int) $dp, $dps)) {
            return $dps[(int) $dp];
        }
        if (array_key_exists($ident, $dps)) {
            return $dps[$ident];
        }

        foreach (self::CLOUD_CODE_ALIASES[$ident] ?? [] as $code) {
            if (array_key_exists($code, $dps)) {
                return $dps[$code];
            }
        }

        return null;
    }

    /**
     * Cloud liefert bei szjcy oft bereits skalierte Werte (tds_in, temp_current).
     *
     * @param array<string|int, mixed> $dps
     */
    private static function resolveScale(string $ident, array $dps, float $cfgScale): float
    {
        foreach (self::CLOUD_CODE_ALIASES[$ident] ?? [] as $code) {
            if (!array_key_exists($code, $dps)) {
                continue;
            }

            if ($ident === 'tds' && in_array($code, ['tds_in', 'tds_out', 'tdslife'], true)) {
                return 1.0;
            }
            if ($ident === 'temperature' && in_array($code, ['temp_current', 'temp'], true)) {
                return 1.0;
            }
            if ($ident === 'temperature' && $code === 'temp_current_f') {
                return 1.0;
            }
        }

        return $cfgScale;
    }
}
