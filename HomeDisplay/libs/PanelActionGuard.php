<?php

declare(strict_types=1);

/**
 * Freigabeprüfung für Aktionen vom Panel.
 *
 * Das Panel meldet nur tileId, actionId und einen Wert. Diese Klasse entscheidet, ob daraus ein
 * RequestAction in IP-Symcon wird: Kachel muss schaltbar sein, actionId muss zur Kachel passen,
 * die Variable braucht eine Aktion, der Wert liegt im freigegebenen Bereich und die Rate pro Gerät
 * bleibt begrenzt. Skript- oder Objekt-IDs vom Panel werden nie entgegengenommen.
 */
final class HomeDisplayPanelActionGuard
{
    public const MAX_ACTIONS_PER_MINUTE = 30;

    public const REMEMBERED_REQUESTS = 50;

    /**
     * @param array<string, mixed>|null $tile      Kachel aus dem veröffentlichten Layout des Geräts
     * @param array<string, mixed>      $body      Anfrage des Panels
     * @param list<string>              $seen      Zuletzt verarbeitete requestIds dieses Geräts
     * @param list<int>                 $recent    Zeitstempel der letzten Aktionen dieses Geräts
     * @return array{duplicate: bool, variableId: int, value: mixed}
     */
    public static function authorize(?array $tile, array $body, array $seen, array $recent, int $now): array
    {
        $requestId = trim((string) ($body['requestId'] ?? ''));
        $tileId = trim((string) ($body['tileId'] ?? ''));
        $actionId = trim((string) ($body['actionId'] ?? ''));
        if ($requestId === '' || $tileId === '' || $actionId === '') {
            throw new InvalidArgumentException('Aktion unvollständig.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $requestId)) {
            throw new InvalidArgumentException('Ungültige requestId.');
        }
        foreach (['scriptId', 'script', 'objectId', 'variableId', 'command'] as $forbidden) {
            if (array_key_exists($forbidden, $body)) {
                throw new InvalidArgumentException('Dieses Feld nimmt der Dienst nicht an: ' . $forbidden);
            }
        }
        if (in_array($requestId, $seen, true)) {
            return ['duplicate' => true, 'variableId' => 0, 'value' => null];
        }
        $window = array_filter($recent, static fn ($stamp): bool => (int) $stamp > $now - 60);
        if (count($window) >= self::MAX_ACTIONS_PER_MINUTE) {
            throw new RuntimeException('Zu viele Aktionen in kurzer Zeit.');
        }
        $tileKind = (string) ($tile['kind'] ?? 'value');
        if ($tile === null || !in_array($tileKind, ['value', 'scene'], true)) {
            throw new InvalidArgumentException('Aktion nicht freigegeben.');
        }
        if ((string) ($tile['actionId'] ?? '') === '' || (string) $tile['actionId'] !== $actionId) {
            throw new InvalidArgumentException('Aktion nicht freigegeben.');
        }
        if ($tileKind === 'scene') {
            $steps = is_array($tile['sceneSteps'] ?? null) ? $tile['sceneSteps'] : [];
            if ($steps === [] && (int) ($tile['variableId'] ?? 0) > 0) {
                $steps = [['variableId' => (int) $tile['variableId'], 'value' => true]];
            }
            $resolved = [];
            foreach ($steps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $variableId = (int) ($step['variableId'] ?? 0);
                if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
                    throw new InvalidArgumentException('Keine Zielvariable.');
                }
                $variable = IPS_GetVariable($variableId);
                if ((int) ($variable['VariableAction'] ?? 0) <= 0 && (int) ($variable['VariableCustomAction'] ?? 0) <= 0) {
                    throw new InvalidArgumentException('Variable ist nicht schaltbar.');
                }
                $item = [
                    'variableId' => $variableId,
                    'value' => self::sceneValue($variable, $step['value'] ?? null),
                    'delayMs' => max(0, min(10000, (int) ($step['delayMs'] ?? 0))),
                ];
                $colorId = (int) ($step['colorVariableId'] ?? 0);
                $color = strtolower(trim((string) ($step['color'] ?? '')));
                if ($colorId > 0 && preg_match('/^#([0-9a-f]{6})$/', $color, $match)) {
                    if (!IPS_VariableExists($colorId)) {
                        throw new InvalidArgumentException('Farbvariable fehlt.');
                    }
                    $item['colorVariableId'] = $colorId;
                    $item['colorValue'] = hexdec($match[1]);
                }
                $resolved[] = $item;
            }
            if ($resolved === []) {
                throw new InvalidArgumentException('Szene ohne Lampen.');
            }
            return ['duplicate' => false, 'variableId' => 0, 'value' => null, 'steps' => $resolved];
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            throw new InvalidArgumentException('Keine Zielvariable.');
        }
        $variable = IPS_GetVariable($variableId);
        if ((int) ($variable['VariableAction'] ?? 0) <= 0 && (int) ($variable['VariableCustomAction'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Variable ist nicht schaltbar.');
        }
        return [
            'duplicate' => false,
            'variableId' => $variableId,
            'value' => self::value($tile, GetValue($variableId), $body['value'] ?? null),
        ];
    }

    /**
     * Schreibt den gesendeten Zielwert. Bei Ja/Nein wird 0 zu false und jeder andere Wert zu true.
     *
     * @param array<string, mixed> $tile
     */
    public static function value(array $tile, mixed $current, mixed $value): mixed
    {
        $kind = (string) ($tile['actionKind'] ?? 'switch');
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Wert fehlt.');
        }
        $number = (float) $value;
        if ($kind === 'dim' || $kind === 'position') {
            [$min, $max] = self::span($tile, $kind === 'dim');
            if ($number < $min || $number > $max) {
                throw new InvalidArgumentException('Wert außerhalb des zulässigen Bereichs.');
            }
        }
        return self::storedValue($current, $number);
    }

    /**
     * @param array<string, mixed> $tile
     * @return array{0: float, 1: float}
     */
    private static function span(array $tile, bool $dim): array
    {
        if ($dim) {
            $off = array_key_exists('valueOff', $tile) ? (float) $tile['valueOff'] : (array_key_exists('valueMin', $tile) ? (float) $tile['valueMin'] : 0.0);
            $on = array_key_exists('valueOn', $tile) ? (float) $tile['valueOn'] : (array_key_exists('valueMax', $tile) ? (float) $tile['valueMax'] : 100.0);
        } else {
            $off = array_key_exists('valueMin', $tile) ? (float) $tile['valueMin'] : 0.0;
            $on = array_key_exists('valueMax', $tile) ? (float) $tile['valueMax'] : 100.0;
        }
        return [min($off, $on), max($off, $on)];
    }

    private static function storedValue(mixed $current, float $number): mixed
    {
        if (is_bool($current)) {
            return $number != 0.0;
        }
        if (is_int($current)) {
            return (int) round($number);
        }
        return $number;
    }

    /** @param array<string, mixed> $variable */
    private static function sceneValue(array $variable, mixed $value): mixed
    {
        if ((int) ($variable['VariableType'] ?? 0) === 0) {
            return (bool) $value;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Wert fehlt.');
        }
        $number = (float) $value;
        if ($number < 0 || $number > 100) {
            throw new InvalidArgumentException('Wert außerhalb des zulässigen Bereichs.');
        }
        return (int) ($variable['VariableType'] ?? 0) === 1 ? (int) round($number) : $number;
    }

    /**
     * Merkt requestId und Zeitstempel und kürzt die Listen.
     *
     * @param array<string, mixed> $entry Laufzeitdaten des Geräts
     * @return array<string, mixed>
     */
    public static function remember(array $entry, string $requestId, string $tileId, int $now): array
    {
        $seen = is_array($entry['requests'] ?? null) ? $entry['requests'] : [];
        $seen[] = $requestId;
        $entry['requests'] = array_slice($seen, -self::REMEMBERED_REQUESTS);
        $recent = is_array($entry['recent'] ?? null) ? $entry['recent'] : [];
        $recent[] = $now;
        $entry['recent'] = array_values(array_filter($recent, static fn ($stamp): bool => (int) $stamp > $now - 60));
        $pending = is_array($entry['pending'] ?? null) ? $entry['pending'] : [];
        $pending[$tileId] = $requestId;
        $entry['pending'] = $pending;
        $entry['seq'] = (int) ($entry['seq'] ?? 0) + 1;
        return $entry;
    }
}
