<?php

declare(strict_types=1);

/**
 * Reihenfolge der Schreibbefehle und die Dimmrechnung.
 * Das Modul spricht nur IPS-Variablen an. Die Reihenfolge verhindert, dass eine Lampe
 * zuerst voll eingeschaltet und danach auf den Zielwert gedimmt wird.
 */
final class HueSwitchActions
{
    public static function isOff(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value === false;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value == 0.0;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) $value == 0.0;
        }

        return false;
    }

    public static function clamp(float $value, float $min, float $max): float
    {
        if ($max < $min) {
            $swap = $min;
            $min = $max;
            $max = $swap;
        }

        return min($max, max($min, $value));
    }

    /**
     * @return list<array{kind: string, value: float|bool}>
     */
    public static function brightnessSequence(bool $off, float $target, bool $hasSwitch): array
    {
        $target = self::clamp($target, 0.0, 100.0);
        if ($target <= 0.0) {
            $steps = [
                ['kind' => 'brightness', 'value' => 0.0],
            ];
            if ($hasSwitch) {
                $steps[] = ['kind' => 'switch', 'value' => false];
            }

            return $steps;
        }
        if (!$off) {
            return [
                ['kind' => 'brightness', 'value' => $target],
            ];
        }
        $steps = [
            ['kind' => 'brightness', 'value' => 0.0],
            ['kind' => 'brightness', 'value' => $target],
        ];
        if ($hasSwitch) {
            $steps[] = ['kind' => 'switch', 'value' => true];
        }

        return $steps;
    }

    public static function shiftedLevel(float $current, float $delta, float $min, float $max): float
    {
        return self::clamp($current + $delta, $min, $max);
    }

    /** 1 im Uhrzeigersinn, -1 dagegen, 0 wenn der Text keine Richtung enthält. */
    public static function directionSign(string $raw, bool $invert): int
    {
        $text = HueSwitchEvents::normalize($raw);
        if ($text === '') {
            return 0;
        }
        if (str_contains($text, 'counter')) {
            $sign = -1;
        } elseif (str_contains($text, 'clock')) {
            $sign = 1;
        } else {
            return 0;
        }

        return $invert ? -$sign : $sign;
    }

    public static function dialDelta(int $steps, float $percentPerStep, int $sign): float
    {
        if ($steps <= 0 || $percentPerStep == 0.0 || $sign === 0) {
            return 0.0;
        }

        return $steps * $percentPerStep * $sign;
    }

    public static function holdAllowed(int $lastMs, int $nowMs, int $gapMs = 300): bool
    {
        return $lastMs <= 0 || ($nowMs - $lastMs) >= $gapMs;
    }
}
