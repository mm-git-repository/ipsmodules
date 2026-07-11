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
            $dp = (string) $cfg['dp'];
            if (!array_key_exists($dp, $dps) && !array_key_exists((int) $dp, $dps)) {
                if (!array_key_exists($ident, $dps)) {
                    continue;
                }
                $raw = $dps[$ident];
            } else {
                $raw = $dps[$dp] ?? $dps[(int) $dp] ?? null;
            }
            if ($raw === null || $raw === '' || !is_numeric($raw)) {
                continue;
            }
            $values[$ident] = round(((float) $raw) * $cfg['scale'], 3);
        }

        return $values;
    }
}
