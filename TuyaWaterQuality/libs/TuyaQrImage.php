<?php

declare(strict_types=1);

/**
 * QR-Bild für Tuya Smart Login (Setup-Popup im Formular).
 */
final class TuyaQrImage
{
    public static function popupHtml(string $payload): string
    {
        $safePayload = htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $img = self::imageTag($payload);

        return '<div style="text-align:center;font-family:system-ui,sans-serif;max-width:360px;">'
            . '<p><strong>Tuya Smart App:</strong> + → Scannen</p>'
            . $img
            . '<p style="font-size:11px;word-break:break-all;opacity:0.75;margin-top:8px;">'
            . $safePayload
            . '</p>'
            . '<p style="font-size:12px;">Danach im Formular „Auf Anmeldung warten“ klicken.</p>'
            . '</div>';
    }

    private static function imageTag(string $payload): string
    {
        $url = 'https://quickchart.io/qr?text=' . rawurlencode($payload) . '&size=280&margin=1';
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<img src="' . $safeUrl . '" width="280" height="280" alt="QR-Code" style="image-rendering:pixelated;">';
    }
}
