<?php

declare(strict_types=1);

/**
 * Wandelt den Text der Hue-Variable in ein Ereignis um.
 * Vergleicht ohne Großschreibung und ohne Leerzeichen, Unterstriche und Bindestriche.
 */
final class HueSwitchEvents
{
    private const KNOWN = [
        'shortrelease' => 'short',
        'doubleshortrelease' => 'double',
        'longpress' => 'long',
        'repeat' => 'hold',
        'longrelease' => 'longrelease',
        'initialpress' => 'ignore',
        'short' => 'short',
        'double' => 'double',
        'long' => 'long',
        'hold' => 'hold',
        'longrelease' => 'longrelease',
        'ignore' => 'ignore',
        'kurz' => 'short',
        'doppeltkurz' => 'double',
        'doppelt' => 'double',
        'lang' => 'long',
        'halten' => 'hold',
        'langlosgelassen' => 'longrelease',
        'losgelassen' => 'longrelease',
    ];

    public static function normalize(string $raw): string
    {
        $text = strtolower(trim($raw));

        return str_replace([' ', '_', '-', '.'], '', $text);
    }

    /**
     * short, double, long, hold, longrelease, ignore oder leer bei unbekannt.
     */
    public static function fromValue(string $raw): string
    {
        $key = self::normalize($raw);

        return self::KNOWN[$key] ?? '';
    }

    /**
     * start und repeat werden ausgewertet. Leer bedeutet: noch kein Action-Wert in diesem Stoß.
     * Andere Texte sind unbekannt.
     */
    public static function dialAction(string $raw): string
    {
        $key = self::normalize($raw);
        if ($key === '' || $key === 'start' || $key === 'repeat') {
            return $key;
        }

        return 'unknown';
    }
}
