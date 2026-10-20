<?php

declare(strict_types=1);

/** IPS-Bilder: Integer-Variable mit Media-ID, String-URL oder Medienobjekt (Typ Bild). */
final class HomeDisplayMedia
{
    public const ROLE_MEDIA_ID = 'mediaId';
    public const ROLE_IMAGE_URL = 'imageUrl';
    public const ROLE_MEDIA_OBJECT = 'mediaObject';

    public static function looksLikeImageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }
        if (preg_match('#\.(jpe?g|png|gif|webp|bmp)(\?|#|$)#i', $url)) {
            return true;
        }
        return (bool) preg_match('#/(snapshot|image|capture|photo|jpg|jpeg|png|media|picture)#i', $url);
    }

    public static function isImageMediaId(int $mediaId): bool
    {
        if ($mediaId <= 0) {
            return false;
        }
        if (!function_exists('IPS_MediaExists') || !IPS_MediaExists($mediaId)) {
            return false;
        }
        $media = self::getMedia($mediaId);
        if (!is_array($media)) {
            return false;
        }
        return (int) ($media['MediaType'] ?? -1) === 1;
    }

    /** @return array<string, mixed>|null */
    public static function getMedia(int $mediaId): ?array
    {
        if ($mediaId <= 0) {
            return null;
        }
        if (function_exists('IPS_GetMedia')) {
            $media = @IPS_GetMedia($mediaId);
            if (is_array($media)) {
                return $media;
            }
        }
        if (function_exists('IPS_Media_Get')) {
            $media = @IPS_Media_Get($mediaId);
            return is_array($media) ? $media : null;
        }
        return null;
    }

    public static function imageMediaIdFromVariable(int $variableId): int
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return 0;
        }
        $variable = IPS_GetVariable($variableId);
        $type = (int) ($variable['VariableType'] ?? -1);
        if ($type === 1) {
            $mediaId = (int) GetValue($variableId);
            return self::isImageMediaId($mediaId) ? $mediaId : 0;
        }
        if ($type === 3) {
            $raw = trim((string) GetValue($variableId));
            if ($raw !== '' && ctype_digit($raw)) {
                $mediaId = (int) $raw;
                return self::isImageMediaId($mediaId) ? $mediaId : 0;
            }
        }
        return 0;
    }

    public static function variableMediaRole(int $variableId): string
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return '';
        }
        if (self::imageMediaIdFromVariable($variableId) > 0) {
            return self::ROLE_MEDIA_ID;
        }
        $variable = IPS_GetVariable($variableId);
        $type = (int) ($variable['VariableType'] ?? -1);
        if ($type === 3) {
            $url = trim((string) GetValue($variableId));
            if (self::looksLikeImageUrl($url)) {
                return self::ROLE_IMAGE_URL;
            }
            $profile = strtolower((string) ($variable['VariableCustomProfile'] ?? $variable['VariableProfile'] ?? ''));
            if ($profile !== '' && preg_match('/url|link|snapshot|bild|image|photo|kamera|camera|media/', $profile)) {
                return self::ROLE_IMAGE_URL;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $tile */
    public static function resolveImageMediaId(array $tile): int
    {
        $rawObject = $tile['mediaObjectId'] ?? 0;
        if (is_string($rawObject) && ctype_digit(trim($rawObject))) {
            $rawObject = (int) trim($rawObject);
        }
        $direct = (int) $rawObject;
        if ($direct > 0 && self::isImageMediaId($direct)) {
            return $direct;
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId > 0) {
            return self::imageMediaIdFromVariable($variableId);
        }
        return 0;
    }

    /** @param array<string, mixed> $tile */
    public static function cameraImageUrl(array $tile): string
    {
        if (self::resolveImageMediaId($tile) > 0) {
            return '';
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return '';
        }
        return trim((string) GetValue($variableId));
    }

    /** Datei unter IPS-Kernel (media/…, absoluter Pfad oder data:image-URL). */
    public static function readBinaryFromPath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^data:image/([a-z0-9+.-]+);base64,#i', $raw, $match) === 1) {
            $pos = strpos($raw, ',');
            if ($pos === false) {
                return null;
            }
            $binary = base64_decode(substr($raw, $pos + 1), true);
            return is_string($binary) && $binary !== '' ? $binary : null;
        }
        $kernel = rtrim(IPS_GetKernelDir(), '\\/');
        if (preg_match('#^https?://#i', $raw)) {
            return null;
        }
        if (ctype_digit($raw)) {
            $mediaId = (int) $raw;
            if ($mediaId > 0) {
                $fromMedia = self::mediaBytes($mediaId);
                if ($fromMedia !== null && $fromMedia !== '') {
                    return $fromMedia;
                }
            }
        }
        if (str_contains($raw, ':') || str_starts_with($raw, '\\\\')) {
            $path = $raw;
        } elseif (str_starts_with(str_replace('\\', '/', $raw), '/')) {
            $path = $kernel . str_replace('/', DIRECTORY_SEPARATOR, $raw);
        } elseif (preg_match('#^media[/\\\\]#i', $raw)) {
            $path = $kernel . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $raw);
        } else {
            $path = $kernel . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $raw;
        }
        if (!is_file($path)) {
            return null;
        }
        $binary = file_get_contents($path);
        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    public static function mediaBytes(int $mediaId): ?string
    {
        if ($mediaId <= 0 || !function_exists('IPS_MediaExists') || !IPS_MediaExists($mediaId)) {
            return null;
        }
        if (function_exists('IPS_GetMediaContent')) {
            $encoded = @IPS_GetMediaContent($mediaId);
            if (is_string($encoded) && $encoded !== '') {
                $binary = base64_decode($encoded, true);
                if (is_string($binary) && $binary !== '') {
                    return $binary;
                }
            }
        }
        if (!self::isImageMediaId($mediaId)) {
            return null;
        }
        $media = self::getMedia($mediaId);
        if (!is_array($media)) {
            return null;
        }
        $file = (string) ($media['MediaFile'] ?? $media['File'] ?? '');
        if ($file === '') {
            return null;
        }
        $kernel = rtrim(IPS_GetKernelDir(), '\\/');
        if (str_contains($file, ':') || str_starts_with($file, '\\\\')) {
            $path = $file;
        } elseif (str_starts_with(str_replace('\\', '/', $file), 'media/')) {
            $path = $kernel . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        } else {
            $path = $kernel . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $file;
        }
        if (!is_file($path)) {
            return null;
        }
        $binary = file_get_contents($path);
        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    /** Bildquelle aktualisieren (Image Grabber / Kamera-Instanz mit Aktion). */
    public static function refreshCameraVariable(int $variableId): void
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return;
        }
        try {
            $variable = IPS_GetVariable($variableId);
            $action = (int) ($variable['VariableAction'] ?? 0);
            $custom = (int) ($variable['VariableCustomAction'] ?? 0);
            if ($action > 0 || $custom > 0) {
                @RequestAction($variableId, true);
                usleep(300000);
            }
        } catch (Throwable $e) {
            unset($e);
        }
    }

    /** Medien aus Kachel (Objekt-ID oder Integer-Variable), ohne URL-Abruf. */
    public static function snapshotMediaBinary(array $tile): ?string
    {
        $mediaId = self::resolveImageMediaId($tile);
        if ($mediaId > 0) {
            $binary = self::mediaBytes($mediaId);
            if ($binary !== null && $binary !== '') {
                return $binary;
            }
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId > 0) {
            $binary = self::variableMediaBytes($variableId);
            if ($binary !== null && $binary !== '') {
                return $binary;
            }
        }
        return null;
    }

    public static function variableMediaBytes(int $variableId): ?string
    {
        $mediaId = self::imageMediaIdFromVariable($variableId);
        return $mediaId > 0 ? self::mediaBytes($mediaId) : null;
    }

    public static function publishableMediaBinding(int $variableId, int $mediaObjectId): bool
    {
        if ($mediaObjectId > 0 && self::isImageMediaId($mediaObjectId)) {
            return true;
        }
        if ($variableId > 0 && self::imageMediaIdFromVariable($variableId) > 0) {
            return true;
        }
        return false;
    }
}
