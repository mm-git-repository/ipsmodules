<?php

declare(strict_types=1);

/**
 * Deklarative Entitäts-Definitionen für WifiWhirl (HTTP /getpolldata/ JSON-Felder).
 */
final class WifiWhirlEntities
{
    public const SOURCE_STATES = 'states';
    public const SOURCE_TIMES = 'times';
    public const SOURCE_OTHER = 'other';
    public const SOURCE_COMPUTED = 'computed';

    /**
     * @return list<array{
     *   ident: string,
     *   name: string,
     *   ipsType: int,
     *   profile: string,
     *   source: string,
     *   field: string,
     *   action: bool,
     *   cmd: int|null,
     *   scale: float,
     *   min: int|float|null,
     *   max: int|float|null
     * }>
     */
    public static function definitions(): array
    {
        return [
            // Schalter
            ['ident' => 'Power', 'name' => 'Ein/Aus', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_STATES, 'field' => 'PWR', 'action' => true, 'cmd' => 22, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'Pump', 'name' => 'Filterpumpe', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_STATES, 'field' => 'FLT', 'action' => true, 'cmd' => 4, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'Heater', 'name' => 'Heizung', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_COMPUTED, 'field' => 'heater', 'action' => true, 'cmd' => 3, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'AirJet', 'name' => 'AirJet', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_STATES, 'field' => 'AIR', 'action' => true, 'cmd' => 2, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'HydroJet', 'name' => 'HydroJet', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_STATES, 'field' => 'HJT', 'action' => true, 'cmd' => 11, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'KeyLock', 'name' => 'Tastensperre', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_STATES, 'field' => 'LCK', 'action' => true, 'cmd' => 23, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'UnitFahrenheit', 'name' => 'Anzeige °F', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_STATES, 'field' => 'UNT', 'action' => true, 'cmd' => 1, 'scale' => 1.0, 'min' => null, 'max' => null],

            // Steuerbare Zahlen
            ['ident' => 'Brightness', 'name' => 'Helligkeit', 'ipsType' => 1, 'profile' => 'WWHL.Brightness', 'source' => self::SOURCE_STATES, 'field' => 'BRT', 'action' => true, 'cmd' => 12, 'scale' => 1.0, 'min' => 0, 'max' => 8],
            ['ident' => 'TargetTemperature', 'name' => 'Zieltemperatur', 'ipsType' => 1, 'profile' => '~Temperature', 'source' => self::SOURCE_STATES, 'field' => 'TGTC', 'action' => true, 'cmd' => 0, 'scale' => 1.0, 'min' => 20, 'max' => 40],
            ['ident' => 'AmbientTemperature', 'name' => 'Umgebungstemperatur', 'ipsType' => 1, 'profile' => '~Temperature', 'source' => self::SOURCE_STATES, 'field' => 'AMBC', 'action' => true, 'cmd' => 15, 'scale' => 1.0, 'min' => -20, 'max' => 50],
            ['ident' => 'PhValue', 'name' => 'pH-Wert', 'ipsType' => 2, 'profile' => 'WWHL.pH', 'source' => self::SOURCE_TIMES, 'field' => 'PHVAL', 'action' => true, 'cmd' => 27, 'scale' => 10.0, 'min' => 0, 'max' => 14],
            ['ident' => 'ChlorineValue', 'name' => 'Chlorgehalt', 'ipsType' => 2, 'profile' => 'WWHL.mgL', 'source' => self::SOURCE_TIMES, 'field' => 'CLVAL', 'action' => true, 'cmd' => 28, 'scale' => 10.0, 'min' => 0, 'max' => 10],
            ['ident' => 'CyanuricAcid', 'name' => 'Cyanursäure', 'ipsType' => 2, 'profile' => 'WWHL.mgL', 'source' => self::SOURCE_TIMES, 'field' => 'CYAVAL', 'action' => true, 'cmd' => 29, 'scale' => 10.0, 'min' => 0, 'max' => 100],
            ['ident' => 'Alkalinity', 'name' => 'Alkalität', 'ipsType' => 1, 'profile' => 'WWHL.mgL', 'source' => self::SOURCE_TIMES, 'field' => 'ALKVAL', 'action' => true, 'cmd' => 30, 'scale' => 1.0, 'min' => 0, 'max' => 300],

            // Binär / Status
            ['ident' => 'Ready', 'name' => 'Bereit', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_COMPUTED, 'field' => 'ready', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'Reachable', 'name' => 'Erreichbar', 'ipsType' => 0, 'profile' => '~Switch', 'source' => self::SOURCE_COMPUTED, 'field' => 'reachable', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],

            // Temperaturen (Sensor)
            ['ident' => 'WaterTemperature', 'name' => 'Wassertemperatur', 'ipsType' => 2, 'profile' => '~Temperature', 'source' => self::SOURCE_STATES, 'field' => 'TMPC', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],

            // Energie
            ['ident' => 'EnergyTotal', 'name' => 'Gesamtenergieverbrauch', 'ipsType' => 2, 'profile' => 'WWHL.kWh', 'source' => self::SOURCE_TIMES, 'field' => 'KWH', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'EnergyToday', 'name' => 'Energieverbrauch heute', 'ipsType' => 2, 'profile' => 'WWHL.kWh', 'source' => self::SOURCE_TIMES, 'field' => 'KWHD', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'Power', 'name' => 'Leistungsaufnahme', 'ipsType' => 1, 'profile' => 'WWHL.W', 'source' => self::SOURCE_TIMES, 'field' => 'WATT', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'TimeToReady', 'name' => 'Bereit in (h)', 'ipsType' => 1, 'profile' => 'WWHL.hours', 'source' => self::SOURCE_COMPUTED, 'field' => 'T2R', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],

            // Laufzeiten (Stunden)
            ['ident' => 'UptimeHours', 'name' => 'Betriebszeit', 'ipsType' => 2, 'profile' => 'WWHL.hours', 'source' => self::SOURCE_COMPUTED, 'field' => 'UPTIME', 'action' => false, 'cmd' => null, 'scale' => 3600.0, 'min' => null, 'max' => null],
            ['ident' => 'PumpTimeHours', 'name' => 'Pumpenlaufzeit', 'ipsType' => 2, 'profile' => 'WWHL.hours', 'source' => self::SOURCE_COMPUTED, 'field' => 'PUMPTIME', 'action' => false, 'cmd' => null, 'scale' => 3600.0, 'min' => null, 'max' => null],
            ['ident' => 'HeaterTimeHours', 'name' => 'Heizungslaufzeit', 'ipsType' => 2, 'profile' => 'WWHL.hours', 'source' => self::SOURCE_COMPUTED, 'field' => 'HEATINGTIME', 'action' => false, 'cmd' => null, 'scale' => 3600.0, 'min' => null, 'max' => null],
            ['ident' => 'AirJetTimeHours', 'name' => 'AirJet-Laufzeit', 'ipsType' => 2, 'profile' => 'WWHL.hours', 'source' => self::SOURCE_COMPUTED, 'field' => 'AIRTIME', 'action' => false, 'cmd' => null, 'scale' => 3600.0, 'min' => null, 'max' => null],

            // Wartung — Zeitstempel
            ['ident' => 'ChlorineLast', 'name' => 'Letzte Chlorung', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'CLTIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'FilterChangeLast', 'name' => 'Letzter Filterwechsel', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'FTIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'FilterCleanLast', 'name' => 'Letzte Filterreinigung', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'FCTIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'WaterChangeLast', 'name' => 'Letzter Wasserwechsel', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'WCTIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],

            // Wartung — Intervalle (Tage)
            ['ident' => 'ChlorineInterval', 'name' => 'Chlorintervall', 'ipsType' => 1, 'profile' => 'WWHL.days', 'source' => self::SOURCE_TIMES, 'field' => 'CLINT', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'FilterChangeInterval', 'name' => 'Filterwechselintervall', 'ipsType' => 1, 'profile' => 'WWHL.days', 'source' => self::SOURCE_TIMES, 'field' => 'FINT', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'FilterCleanInterval', 'name' => 'Filterreinigungsintervall', 'ipsType' => 1, 'profile' => 'WWHL.days', 'source' => self::SOURCE_TIMES, 'field' => 'FCINT', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'WaterChangeInterval', 'name' => 'Wasserwechselintervall', 'ipsType' => 1, 'profile' => 'WWHL.days', 'source' => self::SOURCE_TIMES, 'field' => 'WCINT', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],

            // Wasserqualität — Messzeitpunkte
            ['ident' => 'PhMeasuredAt', 'name' => 'pH zuletzt gemessen', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'PHTIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'ChlorineMeasuredAt', 'name' => 'Chlor zuletzt gemessen', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'CLVTIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'CyanuricMeasuredAt', 'name' => 'Cyanursäure zuletzt gemessen', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'CYATIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'AlkalinityMeasuredAt', 'name' => 'Alkalität zuletzt gemessen', 'ipsType' => 1, 'profile' => '~UnixTimestamp', 'source' => self::SOURCE_TIMES, 'field' => 'ALKTIME', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],

            // Netzwerk / System
            ['ident' => 'WifiSsid', 'name' => 'WLAN-SSID', 'ipsType' => 3, 'profile' => 'WWHL.Text', 'source' => self::SOURCE_OTHER, 'field' => 'SSID', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'WifiRssi', 'name' => 'WLAN-Signalstärke', 'ipsType' => 1, 'profile' => 'WWHL.dBm', 'source' => self::SOURCE_OTHER, 'field' => 'RSSI', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'IpAddress', 'name' => 'IP-Adresse', 'ipsType' => 3, 'profile' => 'WWHL.Text', 'source' => self::SOURCE_OTHER, 'field' => 'IP', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'FirmwareVersion', 'name' => 'Firmware', 'ipsType' => 3, 'profile' => 'WWHL.Text', 'source' => self::SOURCE_OTHER, 'field' => 'FW', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'PumpModel', 'name' => 'Pumpenmodell', 'ipsType' => 3, 'profile' => 'WWHL.Text', 'source' => self::SOURCE_OTHER, 'field' => 'MODEL', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
            ['ident' => 'ErrorText', 'name' => 'Fehler', 'ipsType' => 3, 'profile' => 'WWHL.Text', 'source' => self::SOURCE_STATES, 'field' => 'ERR', 'action' => false, 'cmd' => null, 'scale' => 1.0, 'min' => null, 'max' => null],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitionMap(): array
    {
        $map = [];
        foreach (self::definitions() as $def) {
            $map[$def['ident']] = $def;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $states
     * @param array<string, mixed> $times
     * @param array<string, mixed> $other
     */
    public static function resolveValue(array $def, array $states, array $times, array $other): mixed
    {
        if ($def['source'] === self::SOURCE_COMPUTED) {
            return self::resolveComputed($def['field'], $states, $times);
        }

        $bucket = match ($def['source']) {
            self::SOURCE_STATES => $states,
            self::SOURCE_TIMES => $times,
            self::SOURCE_OTHER => $other,
            default => [],
        };

        if (!array_key_exists($def['field'], $bucket)) {
            return null;
        }

        $raw = $bucket[$def['field']];
        if ($raw === null || $raw === '') {
            return null;
        }

        if ($def['ipsType'] === 0) {
            return self::toBool($raw);
        }

        if ($def['scale'] !== 1.0 && is_numeric($raw)) {
            return ((float) $raw) / $def['scale'];
        }

        if ($def['ipsType'] === 1) {
            return (int) round((float) $raw);
        }

        if ($def['ipsType'] === 2) {
            return (float) $raw;
        }

        return (string) $raw;
    }

    /**
     * @param array<string, mixed> $states
     * @param array<string, mixed> $times
     */
    private static function resolveComputed(string $field, array $states, array $times): mixed
    {
        return match ($field) {
            'heater' => self::toBool(($states['RED'] ?? 0)) || self::toBool($states['GRN'] ?? 0),
            'ready' => self::computeReady($states),
            'T2R' => self::computeTimeToReady($times),
            'UPTIME', 'PUMPTIME', 'HEATINGTIME', 'AIRTIME' => self::secondsToHours($times[$field] ?? null),
            default => null,
        };
    }

    /** @param array<string, mixed> $states */
    private static function computeReady(array $states): bool
    {
        $tmp = isset($states['TMP']) ? (float) $states['TMP'] : (isset($states['TMPC']) ? (float) $states['TMPC'] : 0.0);
        $tgt = isset($states['TGT']) ? (float) $states['TGT'] : (isset($states['TGTC']) ? (float) $states['TGTC'] : 0.0);
        if ($tmp <= 30.0) {
            return false;
        }

        return $tmp >= ($tgt - 1.0);
    }

    /** @param array<string, mixed> $times */
    private static function computeTimeToReady(array $times): int
    {
        if (!array_key_exists('T2R', $times)) {
            return 0;
        }
        $t2r = (int) $times['T2R'];
        if ($t2r === -2) {
            return 0;
        }
        if ($t2r === -1) {
            return 999;
        }

        return $t2r;
    }

    private static function secondsToHours(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        return round(((float) $raw) / 3600.0, 2);
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $defMap
     */
    public static function commandPayload(string $ident, mixed $value, array $defMap): ?array
    {
        if (!isset($defMap[$ident])) {
            return null;
        }
        $def = $defMap[$ident];
        if (!$def['action'] || $def['cmd'] === null) {
            return null;
        }

        if ($def['ipsType'] === 0) {
            $cmdValue = self::toBool($value);
        } elseif ($def['scale'] !== 1.0) {
            $cmdValue = (int) round(((float) $value) * $def['scale']);
        } elseif ($def['ipsType'] === 1) {
            $cmdValue = (int) $value;
        } else {
            $cmdValue = is_numeric($value) ? (float) $value : $value;
        }

        return [
            'CMD' => $def['cmd'],
            'VALUE' => $cmdValue,
        ];
    }

    public static function buttonCommand(int $cmd): array
    {
        return [
            'CMD' => $cmd,
            'VALUE' => true,
        ];
    }
}
