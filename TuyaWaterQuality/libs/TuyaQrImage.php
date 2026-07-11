<?php

declare(strict_types=1);

/**
 * QR-Bild für Tuya Smart Login (Setup im Formular).
 */
final class TuyaQrImage
{
    private const QR_SIZE = 280;

    public static function chartUrl(string $payload): string
    {
        return 'https://quickchart.io/qr?text=' . rawurlencode($payload)
            . '&size=' . self::QR_SIZE
            . '&margin=1';
    }

    public static function fetchPngDataUri(string $payload): ?string
    {
        $url = self::chartUrl($payload);
        $png = self::httpGet($url);
        if ($png === null || $png === '') {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($png);
    }

    public static function popupMessage(string $payload): string
    {
        return "QR-Code wird im Browser geöffnet.\n\n"
            . "Tuya Smart App: + → Scannen\n\n"
            . "Falls kein Browser öffnet, Instanz-Konfiguration schließen und erneut öffnen — "
            . "der QR-Code erscheint dann im Panel „Tuya-Kopplung“.\n\n"
            . "Danach: „Auf Anmeldung warten“ klicken.";
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
                return null;
            }

            return $raw;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
