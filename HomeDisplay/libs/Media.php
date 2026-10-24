<?php

declare(strict_types=1);

/** IPS-Bilder: Integer-Variable mit Media-ID, String-URL oder Medienobjekt (Typ Bild). */
final class HomeDisplayMedia
{
    public const ROLE_MEDIA_ID = 'mediaId';
    public const ROLE_IMAGE_URL = 'imageUrl';
    public const ROLE_MEDIA_OBJECT = 'mediaObject';

    public static function looksLikeVideoStreamSource(string $raw): bool
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return false;
        }
        if (str_starts_with($raw, 'rtsp://') || str_starts_with($raw, 'rtsps://')) {
            return true;
        }
        return (bool) preg_match('/\b(h265|hevc|h\.265|codec=h265|video\/mp4)\b/', $raw);
    }

    public static function looksLikeImageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }
        if (self::looksLikeVideoStreamSource($url)) {
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
        $type = (int) ($media['MediaType'] ?? -1);
        if ($type === 1) {
            return true;
        }
        // ONVIF Image Grabber und ähnliche Module: Typ manchmal unklar, Inhalt trotzdem Bild.
        if (function_exists('IPS_GetMediaContent')) {
            $encoded = @IPS_GetMediaContent($mediaId);
            if (is_string($encoded) && $encoded !== '') {
                $binary = base64_decode($encoded, true);
                if (is_string($binary) && $binary !== '' && self::looksLikeImageBytes($binary)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function looksLikeImageBytes(string $binary): bool
    {
        if ($binary === '' || strlen($binary) < 16) {
            return false;
        }
        if (strncmp($binary, "\xFF\xD8\xFF", 3) === 0) {
            return true;
        }
        if (strncmp($binary, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return true;
        }
        if (strncmp($binary, 'GIF87a', 6) === 0 || strncmp($binary, 'GIF89a', 6) === 0) {
            return true;
        }
        return strncmp($binary, 'BM', 2) === 0;
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
            return self::usableImageMediaId($mediaId);
        }
        if ($type === 3) {
            $raw = trim((string) GetValue($variableId));
            if ($raw !== '' && ctype_digit($raw)) {
                return self::usableImageMediaId((int) $raw);
            }
            if ($raw !== '' && self::looksLikeMediaFileRef($raw)) {
                $fromPath = self::mediaIdFromFileRef($raw);
                if ($fromPath > 0) {
                    return $fromPath;
                }
            }
        }
        return 0;
    }

    public static function looksLikeMediaFileRef(string $raw): bool
    {
        $raw = trim(str_replace('\\', '/', $raw));
        if ($raw === '') {
            return false;
        }
        if (preg_match('#^media/[^/]+$#i', $raw)) {
            return true;
        }
        if (preg_match('#^(ONVIF|IG|IMG|ImageGrabber)_\d+\.jpe?g$#i', $raw)) {
            return true;
        }
        return (bool) preg_match('#\.(jpe?g|png|gif|webp|bmp)$#i', $raw);
    }

    public static function normalizeMediaFileRef(string $raw): string
    {
        $raw = trim(str_replace('\\', '/', $raw));
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^media/(.+)$#i', $raw, $match) === 1) {
            return 'media/' . $match[1];
        }
        if (preg_match('#^(ONVIF|IG|IMG|ImageGrabber)_\d+\.jpe?g$#i', $raw)) {
            return 'media/' . $raw;
        }
        return $raw;
    }

    public static function mediaIdFromFileRef(string $raw): int
    {
        $norm = self::normalizeMediaFileRef($raw);
        if ($norm === '') {
            return 0;
        }
        $base = basename($norm);
        if ($base === '') {
            return 0;
        }
        static $cache = [];
        $key = strtolower($norm);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $found = 0;
        $ids = [];
        if (function_exists('IPS_GetMediaList')) {
            $list = @IPS_GetMediaList();
            if (is_array($list)) {
                $ids = $list;
            }
        }
        if ($ids === [] && function_exists('IPS_GetMediaListByType')) {
            foreach ([1, 3] as $type) {
                $chunk = @IPS_GetMediaListByType($type);
                if (is_array($chunk)) {
                    foreach ($chunk as $id) {
                        $ids[] = $id;
                    }
                }
            }
        }
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || !IPS_MediaExists($id)) {
                continue;
            }
            $media = self::getMedia($id);
            if (!is_array($media)) {
                continue;
            }
            $file = str_replace('\\', '/', trim((string) ($media['MediaFile'] ?? $media['File'] ?? '')));
            if ($file === '') {
                continue;
            }
            $fileNorm = self::normalizeMediaFileRef($file);
            if (strcasecmp($fileNorm, $norm) === 0 || strcasecmp(basename($fileNorm), $base) === 0) {
                $found = $id;
                break;
            }
        }
        $cache[$key] = $found;
        return $found;
    }

    public static function isStreamMediaId(int $mediaId): bool
    {
        if ($mediaId <= 0) {
            return false;
        }
        $media = self::getMedia($mediaId);
        if (!is_array($media)) {
            return false;
        }
        return (int) ($media['MediaType'] ?? -1) === 3;
    }

    public static function usableImageMediaId(int $mediaId): int
    {
        if ($mediaId <= 0) {
            return 0;
        }
        if (!function_exists('IPS_MediaExists') || !IPS_MediaExists($mediaId)) {
            return 0;
        }
        return $mediaId;
    }

    public static function variableMediaRole(int $variableId): string
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return '';
        }
        if (self::imageMediaIdFromVariable($variableId) > 0) {
            return self::ROLE_MEDIA_ID;
        }
        if (self::imageMediaIdNearVariable($variableId) > 0) {
            return self::ROLE_MEDIA_ID;
        }
        $variable = IPS_GetVariable($variableId);
        $type = (int) ($variable['VariableType'] ?? -1);
        if ($type === 3) {
            $url = trim((string) GetValue($variableId));
            if (self::looksLikeMediaFileRef($url) && self::mediaIdFromFileRef($url) > 0) {
                return self::ROLE_MEDIA_ID;
            }
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
        if ($direct > 0 && IPS_ObjectExists($direct)) {
            $object = IPS_GetObject($direct);
            if ((int) ($object['ObjectType'] ?? -1) === 1) {
                $fromInstance = self::imageMediaIdForInstance($direct);
                if ($fromInstance > 0) {
                    return $fromInstance;
                }
            }
        }
        if ($direct > 0 && self::usableImageMediaId($direct) > 0) {
            return $direct;
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId > 0) {
            $fromVar = self::imageMediaIdFromVariable($variableId);
            if ($fromVar > 0) {
                return $fromVar;
            }
            return self::imageMediaIdNearVariable($variableId);
        }
        return 0;
    }

    /** Medien „Image“ unter derselben Grabber-Instanz (ONVIF u. a.). */
    public static function imageMediaIdNearVariable(int $variableId): int
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return 0;
        }
        $object = IPS_GetObject($variableId);
        $parent = (int) ($object['ParentID'] ?? 0);
        if ($parent <= 0 || !self::isInstanceObject($parent)) {
            return 0;
        }
        return self::imageMediaIdForInstance($parent);
    }

    /** ONVIF Image Grabber legt Medien mit Ident IMAGE unter der Instanz an. */
    public static function imageMediaIdForInstance(int $instanceId): int
    {
        if ($instanceId <= 0 || !self::isInstanceObject($instanceId)) {
            return 0;
        }
        if (function_exists('IPS_GetObjectIdByIdent')) {
            foreach (['IMAGE', 'Image', 'Bild', 'Picture', 'Snapshot'] as $ident) {
                $childId = (int) @IPS_GetObjectIdByIdent($ident, $instanceId);
                if ($childId > 0 && self::usableImageMediaId($childId) > 0) {
                    return $childId;
                }
            }
        }
        if (!function_exists('IPS_GetObjectListByParent')) {
            return 0;
        }
        $fallback = 0;
        foreach (IPS_GetObjectListByParent($instanceId) as $childId) {
            $childId = (int) $childId;
            if ($childId <= 0 || !IPS_ObjectExists($childId)) {
                continue;
            }
            $child = IPS_GetObject($childId);
            if ((int) ($child['ObjectType'] ?? -1) !== 5) {
                continue;
            }
            if (self::usableImageMediaId($childId) <= 0) {
                continue;
            }
            $ident = (string) ($child['ObjectIdent'] ?? '');
            if ($ident !== '' && in_array(strtolower($ident), ['image', 'bild', 'picture', 'snapshot'], true)) {
                return $childId;
            }
            if ($fallback <= 0 && self::isImageMediaId($childId)) {
                $fallback = $childId;
            }
        }
        return $fallback;
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
        return self::normalizeCameraSource((string) GetValue($variableId));
    }

    public static function normalizeCameraSource(string $raw): string
    {
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($raw === '') {
            return '';
        }
        $line = preg_split('/\r\n|\r|\n/', $raw)[0] ?? $raw;
        return trim((string) $line);
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
        if (is_file($path)) {
            $binary = file_get_contents($path);
            if (is_string($binary) && $binary !== '' && self::looksLikeImageBytes($binary)) {
                return $binary;
            }
        }
        $mediaId = self::mediaIdFromFileRef($raw);
        if ($mediaId > 0) {
            return self::mediaBytes($mediaId);
        }
        return null;
    }

    /** Medien unter Bildergreifer/ONVIF (Inhalt oft nur im RAM bis IPS_GetMediaContent). */
    public static function isGrabberImageMedia(int $mediaId): bool
    {
        if ($mediaId <= 0 || !IPS_ObjectExists($mediaId)) {
            return false;
        }
        $object = IPS_GetObject($mediaId);
        if ((int) ($object['ObjectType'] ?? -1) !== 5) {
            return false;
        }
        $parent = (int) ($object['ParentID'] ?? 0);
        return $parent > 0 && self::isInstanceObject($parent);
    }

    public static function mediaBytes(int $mediaId): ?string
    {
        if ($mediaId <= 0 || !function_exists('IPS_MediaExists') || !IPS_MediaExists($mediaId)) {
            return null;
        }
        if (self::isStreamMediaId($mediaId)) {
            return null;
        }
        $fromCache = self::mediaBytesFromContentApi($mediaId);
        if ($fromCache !== null) {
            return $fromCache;
        }
        $media = self::getMedia($mediaId);
        if (!is_array($media)) {
            return null;
        }
        $file = (string) ($media['MediaFile'] ?? $media['File'] ?? '');
        if ($file !== '') {
            $binary = self::readBinaryFromPath($file);
            if ($binary !== null && self::looksLikeImageBytes($binary)) {
                return $binary;
            }
        }
        $mediaType = (int) ($media['MediaType'] ?? -1);
        if ($mediaType === 1 || self::isImageMediaId($mediaId) || self::isGrabberImageMedia($mediaId)) {
            return self::mediaBytesFromContentApi($mediaId);
        }
        return null;
    }

    private static function mediaBytesFromContentApi(int $mediaId): ?string
    {
        if (!function_exists('IPS_GetMediaContent')) {
            return null;
        }
        $encoded = @IPS_GetMediaContent($mediaId);
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }
        if (self::looksLikeImageBytes($encoded)) {
            return $encoded;
        }
        $normalized = preg_replace('/\s+/', '', $encoded);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }
        foreach ([true, false] as $strict) {
            $binary = base64_decode($normalized, $strict);
            if (is_string($binary) && $binary !== '' && self::looksLikeImageBytes($binary)) {
                return $binary;
            }
        }
        return null;
    }

    public static function mediaBytesWithRetry(int $mediaId, int $attempts = 24): ?string
    {
        if ($mediaId <= 0) {
            return null;
        }
        $attempts = max(1, $attempts);
        for ($i = 0; $i < $attempts; $i++) {
            $binary = self::mediaBytes($mediaId);
            if ($binary !== null && $binary !== '') {
                return $binary;
            }
            if ($i + 1 < $attempts) {
                usleep(250000);
            }
        }
        return null;
    }

    /** Snapshot vor dem Lesen anstoßen (ONVIF Image Grabber, Image Grabber, Variable-Aktion). */
    public static function refreshCameraTile(array $tile): void
    {
        $instanceId = self::resolveSnapshotInstanceId($tile);
        if ($instanceId > 0) {
            self::requestSnapshotUpdate($instanceId);
            return;
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId > 0) {
            self::refreshCameraVariable($variableId);
        }
    }

    /** @param array<string, mixed> $tile */
    public static function resolveSnapshotInstanceId(array $tile): int
    {
        $mediaId = self::resolveImageMediaId($tile);
        if ($mediaId <= 0) {
            $rawObject = $tile['mediaObjectId'] ?? 0;
            if (is_string($rawObject) && ctype_digit(trim($rawObject))) {
                $rawObject = (int) trim($rawObject);
            }
            $mediaId = (int) $rawObject;
        }
        if ($mediaId > 0 && IPS_ObjectExists($mediaId)) {
            $object = IPS_GetObject($mediaId);
            $parent = (int) ($object['ParentID'] ?? 0);
            if ($parent > 0 && self::isInstanceObject($parent)) {
                return $parent;
            }
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId > 0 && IPS_VariableExists($variableId)) {
            $object = IPS_GetObject($variableId);
            $parent = (int) ($object['ParentID'] ?? 0);
            if ($parent > 0 && self::isInstanceObject($parent)) {
                return $parent;
            }
        }
        return 0;
    }

    public static function requestSnapshotUpdate(int $instanceId): bool
    {
        if ($instanceId <= 0 || !IPS_ObjectExists($instanceId)) {
            return false;
        }
        try {
            if (function_exists('ONVIF_UpdateImage')) {
                for ($try = 0; $try < 3; $try++) {
                    $ok = @ONVIF_UpdateImage($instanceId);
                    if ($ok === true) {
                        return true;
                    }
                    usleep(500000);
                }
            }
        } catch (Throwable $e) {
            unset($e);
        }
        try {
            if (function_exists('IG_UpdateImage')) {
                for ($try = 0; $try < 2; $try++) {
                    $ok = @IG_UpdateImage($instanceId);
                    if ($ok === true) {
                        return true;
                    }
                    usleep(400000);
                }
            }
        } catch (Throwable $e) {
            unset($e);
        }
        foreach (['UpdateImage', 'Refresh', 'Update', 'RefreshImage', 'GetSnapshot', 'Snapshot'] as $ident) {
            try {
                @IPS_RequestAction($instanceId, $ident, true);
                return true;
            } catch (Throwable $e) {
                unset($e);
            }
        }
        return false;
    }

    /** @param array<string, mixed> $tile */
    public static function fetchCameraBinary(array $tile): ?string
    {
        $binary = self::snapshotMediaBinary($tile, false);
        if ($binary !== null && $binary !== '' && self::looksLikeImageBytes($binary)) {
            return $binary;
        }
        for ($round = 0; $round < 3; $round++) {
            self::refreshCameraTile($tile);
            usleep(400000);
            $binary = self::snapshotMediaBinary($tile, true);
            if ($binary !== null && $binary !== '' && self::looksLikeImageBytes($binary)) {
                return $binary;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $tile */
    public static function snapshotUpdateHint(array $tile): string
    {
        $instanceId = self::resolveSnapshotInstanceId($tile);
        if ($instanceId <= 0) {
            return 'Keine Grabber-Instanz erkannt – Medienobjekt „Image“ unter dem Bildergreifer wählen (Kachel: Quelle „Medien“).';
        }
        $grabber = IPS_GetName($instanceId);
        $mediaId = self::resolveImageMediaId($tile);
        $mediaBit = $mediaId > 0 ? (' · Medien „' . IPS_GetName($mediaId) . '“ (ID ' . $mediaId . ')') : '';
        return 'Kein JPEG nach Snapshot – Grabber „' . $grabber . '“ in Symcon: Quelle/URL prüfen, ggf. ONVIF IO + JPEG-Profil (kein H.265-Stream), Konfiguration „Zeige Bild“ oder IG_UpdateImage testen' . $mediaBit . '.';
    }

    /** Bildquelle aktualisieren (Variable mit Aktion). */
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
                usleep(400000);
                return;
            }
            $object = IPS_GetObject($variableId);
            $parent = (int) ($object['ParentID'] ?? 0);
            if ($parent > 0 && self::isInstanceObject($parent)) {
                self::requestSnapshotUpdate($parent);
            }
        } catch (Throwable $e) {
            unset($e);
        }
    }

    private static function isInstanceObject(int $objectId): bool
    {
        if ($objectId <= 0 || !IPS_ObjectExists($objectId)) {
            return false;
        }
        $object = IPS_GetObject($objectId);
        return (int) ($object['ObjectType'] ?? -1) === 1;
    }

    /** Medien aus Kachel (Objekt-ID oder Integer-Variable), ohne URL-Abruf. */
    public static function snapshotMediaBinary(array $tile, bool $patient = true): ?string
    {
        $mediaId = self::resolveImageMediaId($tile);
        if ($mediaId > 0 && !self::isStreamMediaId($mediaId)) {
            $binary = self::mediaBytes($mediaId);
            if ($binary === null && $patient) {
                $binary = self::mediaBytesWithRetry($mediaId);
            }
            if ($binary !== null && $binary !== '' && self::looksLikeImageBytes($binary)) {
                return $binary;
            }
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId > 0) {
            $binary = self::variableMediaBytes($variableId);
            if ($binary !== null && $binary !== '') {
                return $binary;
            }
            if (IPS_VariableExists($variableId)) {
                $raw = trim((string) GetValue($variableId));
                if ($raw !== '' && self::looksLikeMediaFileRef($raw)) {
                    $binary = self::readBinaryFromPath($raw);
                    if ($binary !== null && $binary !== '') {
                        return $binary;
                    }
                }
            }
        }
        return null;
    }

    public static function cameraStreamBindingError(array $tile): ?string
    {
        $mediaId = self::resolveImageMediaId($tile);
        if ($mediaId > 0 && self::isStreamMediaId($mediaId)) {
            return 'ONVIF Media Stream (RTSP/H.265) liefert kein Standbild – stattdessen ONVIF Image Grabber und Medienobjekt „Image“ (JPEG, z. B. media/ONVIF_….jpg).';
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId > 0 && IPS_VariableExists($variableId)) {
            $raw = trim((string) GetValue($variableId));
            if ($raw !== '' && self::looksLikeVideoStreamSource($raw)) {
                return 'Variable enthält RTSP/H.265 – für die Kamera-Kachel ONVIF Image Grabber mit Quelle „Medien“ (JPEG-Snapshot), nicht den Video-Stream.';
            }
        }
        return null;
    }

    public static function variableMediaBytes(int $variableId): ?string
    {
        $mediaId = self::imageMediaIdFromVariable($variableId);
        if ($mediaId <= 0) {
            $mediaId = self::imageMediaIdNearVariable($variableId);
        }
        return $mediaId > 0 ? self::mediaBytesWithRetry($mediaId) : null;
    }

    public static function publishableMediaBinding(int $variableId, int $mediaObjectId): bool
    {
        if ($mediaObjectId > 0 && self::usableImageMediaId($mediaObjectId) > 0) {
            return true;
        }
        if ($variableId > 0 && self::imageMediaIdFromVariable($variableId) > 0) {
            return true;
        }
        if ($variableId > 0 && self::imageMediaIdNearVariable($variableId) > 0) {
            return true;
        }
        return false;
    }
}
