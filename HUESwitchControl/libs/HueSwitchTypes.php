<?php

declare(strict_types=1);

/**
 * Schaltertypen. Ein neuer Hue-Schalter ist ein Eintrag in all().
 */
final class HueSwitchTypes
{
    /**
     * @return array<string, array{name: string, buttons: array<string, array{name: string}>, dials: array<string, array{name: string}>}>
     */
    public static function all(): array
    {
        return [
            'tapdial' => [
                'name' => 'Hue Tap Dial',
                'buttons' => [
                    'button1' => ['name' => 'Taste 1'],
                    'button2' => ['name' => 'Taste 2'],
                    'button3' => ['name' => 'Taste 3'],
                    'button4' => ['name' => 'Taste 4'],
                ],
                'dials' => [
                    'dial' => ['name' => 'Drehrad'],
                ],
            ],
        ];
    }

    /**
     * @return array{name: string, buttons: array<string, array{name: string}>, dials: array<string, array{name: string}>}|null
     */
    public static function get(string $type): ?array
    {
        $all = self::all();

        return $all[$type] ?? null;
    }

    /** @return list<array{caption: string, value: string}> */
    public static function eventOptions(): array
    {
        return [
            ['caption' => 'Kurz', 'value' => 'short'],
            ['caption' => 'Doppelt kurz', 'value' => 'double'],
            ['caption' => 'Lang', 'value' => 'long'],
            ['caption' => 'Halten', 'value' => 'hold'],
            ['caption' => 'Lang losgelassen', 'value' => 'longrelease'],
        ];
    }

    /** @return list<array{caption: string, value: string}> */
    public static function actionOptions(): array
    {
        return [
            ['caption' => 'Einschalten', 'value' => 'on'],
            ['caption' => 'Ausschalten', 'value' => 'off'],
            ['caption' => 'Umschalten', 'value' => 'toggle'],
            ['caption' => 'Helligkeit setzen', 'value' => 'set'],
            ['caption' => 'Heller um', 'value' => 'up'],
            ['caption' => 'Dunkler um', 'value' => 'down'],
        ];
    }

    public static function eventCaption(string $event): string
    {
        foreach (self::eventOptions() as $option) {
            if ($option['value'] === $event) {
                return $option['caption'];
            }
        }

        return $event;
    }
}
