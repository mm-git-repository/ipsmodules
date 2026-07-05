<?php

declare(strict_types=1);

/**
 * Wasserqualitäts-Auswertung für PoolControl (pH + ORP, Hysterese).
 */
final class PoolControlEvaluator
{
    public const STATE_OK = 'ok';
    public const STATE_PH_LOW = 'ph_low';
    public const STATE_PH_HIGH = 'ph_high';
    public const STATE_ORP_LOW = 'orp_low';
    public const STATE_ORP_HIGH = 'orp_high';
    public const STATE_SENSOR_OFFLINE = 'sensor_offline';

    /**
     * @param array{
     *   ph: float|null,
     *   orp: int|null,
     *   sensorReachable: bool,
     *   lastUpdate: int,
     *   maxAgeSec: int
     * } $input
     * @param array{
     *   phAlarmMin: float,
     *   phAlarmMax: float,
     *   phTargetMin: float,
     *   phTargetMax: float,
     *   orpAlarmMin: int,
     *   orpAlarmMax: int,
     *   orpTargetMin: int,
     *   orpTargetMax: int,
     *   phHysteresis: float,
     *   orpHysteresis: int
     * } $limits
     * @return array{
     *   state: string,
     *   phInRange: bool,
     *   orpInRange: bool,
     *   phAlarm: bool,
     *   orpAlarm: bool
     * }
     */
    public static function evaluate(array $input, array $limits): array
    {
        $now = time();
        $age = $input['lastUpdate'] > 0 ? ($now - $input['lastUpdate']) : PHP_INT_MAX;

        if (!$input['sensorReachable'] || $age > $input['maxAgeSec']) {
            return [
                'state' => self::STATE_SENSOR_OFFLINE,
                'phInRange' => false,
                'orpInRange' => false,
                'phAlarm' => true,
                'orpAlarm' => true,
            ];
        }

        $ph = $input['ph'];
        $orp = $input['orp'];

        $phInRange = $ph !== null
            && $ph >= ($limits['phTargetMin'] + $limits['phHysteresis'])
            && $ph <= ($limits['phTargetMax'] - $limits['phHysteresis']);

        $orpInRange = $orp !== null
            && $orp >= ($limits['orpTargetMin'] + $limits['orpHysteresis'])
            && $orp <= ($limits['orpTargetMax'] - $limits['orpHysteresis']);

        $phAlarm = $ph !== null && ($ph < $limits['phAlarmMin'] || $ph > $limits['phAlarmMax']);
        $orpAlarm = $orp !== null && ($orp < $limits['orpAlarmMin'] || $orp > $limits['orpAlarmMax']);

        $state = self::STATE_OK;
        if ($ph !== null && $ph < $limits['phAlarmMin']) {
            $state = self::STATE_PH_LOW;
        } elseif ($ph !== null && $ph > $limits['phAlarmMax']) {
            $state = self::STATE_PH_HIGH;
        } elseif ($orp !== null && $orp < $limits['orpAlarmMin']) {
            $state = self::STATE_ORP_LOW;
        } elseif ($orp !== null && $orp > $limits['orpAlarmMax']) {
            $state = self::STATE_ORP_HIGH;
        }

        return [
            'state' => $state,
            'phInRange' => $phInRange,
            'orpInRange' => $orpInRange,
            'phAlarm' => $phAlarm,
            'orpAlarm' => $orpAlarm,
        ];
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_OK => 'OK',
            self::STATE_PH_LOW => 'pH zu niedrig',
            self::STATE_PH_HIGH => 'pH zu hoch',
            self::STATE_ORP_LOW => 'ORP zu niedrig',
            self::STATE_ORP_HIGH => 'ORP zu hoch',
            self::STATE_SENSOR_OFFLINE => 'Sensor offline',
            default => $state,
        };
    }
}
