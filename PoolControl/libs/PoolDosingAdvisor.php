<?php

declare(strict_types=1);

/**
 * Berechnet informative Dosierungsempfehlungen (Bayrol Miami Outdoor Setup).
 */
final class PoolDosingAdvisor
{
    public const CHLOR_MODE_TAB = 'multi_tab';
    public const CHLOR_MODE_LIQUID = 'liquid_chlorine';

    /**
     * @param array{
     *   volumeLiters: float,
     *   chlorMode: string,
     *   liquidChlorPercent: float,
     *   liquidChlorMlFactor: float,
     *   phPowderGramsPer01Per1000L: float,
     *   maxTabsPerWeek: int,
     *   phTargetMin: float,
     *   phTargetMax: float,
     *   orpTargetMin: int,
     *   orpTargetMax: int,
     *   disclaimer: string
     * } $config
     * @param array{
     *   ph: float|null,
     *   orp: int|null,
     *   waterQualityState: string
     * } $input
     * @return array{summary: string, detail: array<string, string>}
     */
    public static function recommend(array $config, array $input): array
    {
        $lines = [];
        $detail = [];

        $ph = $input['ph'];
        $orp = $input['orp'];
        $state = $input['waterQualityState'];
        $volume = max(1.0, $config['volumeLiters']);

        if ($state === 'sensor_offline') {
            $summary = 'Sensor offline — keine Empfehlung möglich.';

            return ['summary' => $summary, 'detail' => ['status' => $summary]];
        }

        if ($ph !== null) {
            if ($ph < $config['phTargetMin']) {
                $delta = $config['phTargetMin'] - $ph;
                $grams = self::phPowderGrams($volume, $delta, $config['phPowderGramsPer01Per1000L']);
                $text = sprintf(
                    'pH zu niedrig (%.2f): ca. %.0f g Bayrol pH-Plus-Pulver langsam einstreuen, 2 h umwälzen, erneut messen.',
                    $ph,
                    $grams
                );
                $lines[] = $text;
                $detail['ph'] = $text;
            } elseif ($ph > $config['phTargetMax']) {
                $delta = $ph - $config['phTargetMax'];
                $grams = self::phPowderGrams($volume, $delta, $config['phPowderGramsPer01Per1000L']);
                $text = sprintf(
                    'pH zu hoch (%.2f): ca. %.0f g Bayrol pH-Minus-Pulver langsam einstreuen, 2 h umwälzen, erneut messen.',
                    $ph,
                    $grams
                );
                $lines[] = $text;
                $detail['ph'] = $text;
            } else {
                $detail['ph'] = sprintf('pH im Soll (%.2f).', $ph);
            }
        }

        if ($orp !== null) {
            if ($orp < $config['orpTargetMin']) {
                if ($config['chlorMode'] === self::CHLOR_MODE_LIQUID) {
                    $delta = $config['orpTargetMin'] - $orp;
                    $ml = self::liquidChlorMl($volume, $delta, $config['liquidChlorPercent'], $config['liquidChlorMlFactor']);
                    $text = sprintf(
                        'ORP zu niedrig (%d mV): ca. %.0f ml Flüssigchlor (%.1f %% aktiv), 30 min umwälzen, erneut messen.',
                        $orp,
                        $ml,
                        $config['liquidChlorPercent']
                    );
                } else {
                    $text = sprintf(
                        'ORP zu niedrig (%d mV): Bayrol 20g Multi-Tab Dosierer prüfen; Richtwert 1 Tab / %.0f Tage bei %.0f l (max. %d/Woche).',
                        $orp,
                        max(2.0, 14.0 * (669.0 / $volume)),
                        $volume,
                        $config['maxTabsPerWeek']
                    );
                }
                $lines[] = $text;
                $detail['chlor'] = $text;
            } elseif ($orp > $config['orpTargetMax']) {
                $text = sprintf(
                    'ORP zu hoch (%d mV): kein Chlor/keine Tabs nachfüllen; Pumpe laufen lassen; ggf. Teilwasserwechsel.',
                    $orp
                );
                $lines[] = $text;
                $detail['chlor'] = $text;
            } else {
                $detail['chlor'] = sprintf('ORP im Soll (%d mV).', $orp);
            }
        }

        if ($state === 'ok' && $lines === []) {
            $summary = 'Wasserqualität im Soll — keine Dosierung nötig.';
        } elseif ($lines === []) {
            $summary = 'Keine Empfehlung (Messwerte fehlen).';
        } else {
            $summary = implode(' ', $lines);
        }

        if ($config['disclaimer'] !== '') {
            $summary .= ' ' . $config['disclaimer'];
        }

        return ['summary' => trim($summary), 'detail' => $detail];
    }

    private static function phPowderGrams(float $volumeLiters, float $deltaPh, float $gramsPer01Per1000L): float
    {
        if ($deltaPh <= 0) {
            return 0.0;
        }

        $steps = $deltaPh / 0.1;

        return round(($volumeLiters / 1000.0) * $gramsPer01Per1000L * $steps, 0);
    }

    private static function liquidChlorMl(float $volumeLiters, int $deltaOrp, float $percentActive, float $factor): float
    {
        if ($deltaOrp <= 0 || $percentActive <= 0) {
            return 0.0;
        }

        $base = ($volumeLiters / 1000.0) * $factor * ($deltaOrp / 100.0);
        $concentrationFactor = 6.0 / max(0.1, $percentActive);

        return round(max(5.0, $base * $concentrationFactor), 0);
    }
}
