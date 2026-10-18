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
        if ($tile === null || (string) ($tile['kind'] ?? 'value') !== 'value') {
            throw new InvalidArgumentException('Aktion nicht freigegeben.');
        }
        if ((string) ($tile['actionId'] ?? '') === '' || (string) $tile['actionId'] !== $actionId) {
            throw new InvalidArgumentException('Aktion nicht freigegeben.');
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
     * Zielwert aus Kachelart und aktuellem Wert. Schalten ohne Wert bedeutet Umschalten.
     *
     * @param array<string, mixed> $tile
     */
    public static function value(array $tile, mixed $current, mixed $value): mixed
    {
        $kind = (string) ($tile['actionKind'] ?? 'switch');
        if ($kind === 'switch' || is_bool($current)) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value) && ($value === 0 || $value === 1)) {
                return $value === 1;
            }
            return !((bool) $current);
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Wert fehlt.');
        }
        $number = (float) $value;
        $min = array_key_exists('valueMin', $tile) ? (float) $tile['valueMin'] : 0.0;
        $max = array_key_exists('valueMax', $tile) ? (float) $tile['valueMax'] : 100.0;
        if ($number < $min || $number > $max) {
            throw new InvalidArgumentException('Wert außerhalb des zulässigen Bereichs.');
        }
        return is_int($current) ? (int) round($number) : $number;
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
