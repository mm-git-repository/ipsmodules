<?php

declare(strict_types=1);

require_once __DIR__ . '/ChartRender.php';

/** Monochromes Vollbild (1 Bit/Pixel, zeilenweise) für E-Ink — optional serverseitig gerendert. */
final class HomeDisplayEpdFrame
{
    /**
     * @param array<string, mixed> $layout
     * @param list<array<string, mixed>> $stateTiles
     */
    public static function monoPage(int $width, int $height, array $layout, array $stateTiles, int $pageIndex): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return '';
        }
        $width = max(80, min(800, $width));
        $height = max(60, min(600, $height));
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return '';
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        $gray = imagecolorallocate($img, 120, 120, 120);
        imagefilledrectangle($img, 0, 0, $width, $height, $white);

        $cols = max(1, (int) ($layout['columns'] ?? 2));
        $rows = max(1, (int) ($layout['rows'] ?? 4));
        $pages = $layout['pages'] ?? [];
        if (!is_array($pages) || $pages === []) {
            $mono = self::gdToMono($img, $width, $height);
            imagedestroy($img);

            return $mono;
        }
        if ($pageIndex < 0 || $pageIndex >= count($pages)) {
            $pageIndex = 0;
        }
        $page = $pages[$pageIndex];
        if (!is_array($page)) {
            imagedestroy($img);
            return '';
        }
        $header = 18;
        $title = (string) ($page['title'] ?? '');
        if ($title !== '') {
            imagestring($img, 2, 4, 2, self::clip($title, 48), $black);
        }
        imageline($img, 0, $header, $width, $header, $black);
        $cellW = (int) floor($width / $cols);
        $cellH = (int) floor(($height - $header) / $rows);
        $byId = [];
        foreach ($stateTiles as $st) {
            if (!is_array($st)) {
                continue;
            }
            $id = (string) ($st['tileId'] ?? '');
            if ($id !== '') {
                $byId[$id] = $st;
            }
        }
        foreach ($page['tiles'] ?? [] as $tile) {
            if (!is_array($tile)) {
                continue;
            }
            $col = (int) ($tile['col'] ?? 0);
            $row = (int) ($tile['row'] ?? 0);
            $colSpan = max(1, (int) ($tile['colSpan'] ?? 1));
            $rowSpan = max(1, (int) ($tile['rowSpan'] ?? 1));
            $x = $col * $cellW;
            $y = $header + $row * $cellH;
            $w = $colSpan * $cellW;
            $h = $rowSpan * $cellH;
            $kind = (string) ($tile['kind'] ?? 'value');
            $tileId = (string) ($tile['tileId'] ?? '');
            $st = $byId[$tileId] ?? [];
            self::drawTile($img, $tile, is_array($st) ? $st : [], $x, $y, $w, $h, $black, $gray, $white);
        }

        $mono = self::gdToMono($img, $width, $height);
        imagedestroy($img);

        return $mono;
    }

    /** @param array<string, mixed> $tile @param array<string, mixed> $st */
    private static function drawTile(
        \GdImage $img,
        array $tile,
        array $st,
        int $x,
        int $y,
        int $w,
        int $h,
        int $black,
        int $gray,
        int $white
    ): void {
        $kind = (string) ($tile['kind'] ?? 'value');
        if ($kind === 'separator') {
            imageline($img, $x + 2, $y + (int) ($h / 2), $x + $w - 2, $y + (int) ($h / 2), $black);

            return;
        }
        if ($kind === 'label') {
            imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $black);
            $text = (string) ($tile['text'] ?? '');
            imagestring($img, 3, $x + 4, $y + (int) ($h / 2) - 8, self::clip($text, 32), $black);

            return;
        }
        imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $black);
        $title = (string) ($tile['title'] ?? '');
        if ($title !== '') {
            imagestring($img, 1, $x + 3, $y + 2, self::clip($title, 28), $black);
        }
        $main = '—';
        if ($kind === 'display' && (string) ($tile['displaySource'] ?? 'variable') === 'text') {
            $main = (string) ($tile['text'] ?? '—');
        } elseif (isset($st['text'])) {
            $main = (string) $st['text'];
        } elseif ($kind === 'scene') {
            $main = $title !== '' ? $title : 'Szene';
        } elseif ($kind === 'nav') {
            $main = '→ ' . (string) ($tile['targetPage'] ?? '');
        } elseif ($kind === 'weather') {
            $main = (string) ($st['text'] ?? '—');
        } elseif ($kind === 'presence') {
            $main = (string) ($st['text'] ?? '—');
        }
        $color = (string) ($st['color'] ?? 'neutral');
        if ($color === 'warn' || $color === 'alarm') {
            imagefilledrectangle($img, $x + 1, $y + 1, $x + $w - 2, $y + $h - 2, $black);
            imagestring($img, 2, $x + 4, $y + (int) ($h / 2) - 4, self::clip($main, 24), $white);
        } else {
            imagestring($img, 2, $x + 4, $y + (int) ($h / 2) - 4, self::clip($main, 24), $black);
        }
        if ($kind === 'chart' && isset($st['series']) && is_array($st['series'])) {
            $series = array_map(static fn ($v) => is_numeric($v) ? (float) $v : 0.0, $st['series']);
            $yMin = (float) ($st['seriesMin'] ?? ($series[0] ?? 0));
            $yMax = (float) ($st['seriesMax'] ?? ($series[0] ?? 1));
            $chartH = max(20, $h - 22);
            $chartW = max(20, $w - 8);
            $png = HomeDisplayChartRender::png($chartW, $chartH, [$series], [], $yMin, $yMax, 'line');
            if ($png !== '') {
                $chartImg = @imagecreatefromstring($png);
                if ($chartImg !== false) {
                    imagecopy($img, $chartImg, $x + 4, $y + 14, 0, 0, $chartW, $chartH);
                    imagedestroy($chartImg);
                }
            }
        }
    }

    private static function clip(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) {
            return $text;
        }

        return substr($text, 0, max(0, $maxLen - 1)) . '…';
    }

    private static function gdToMono(\GdImage $img, int $width, int $height): string
    {
        $out = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x += 8) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $px = $x + $bit;
                    if ($px >= $width) {
                        continue;
                    }
                    $rgb = imagecolorat($img, $px, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $lum = (int) (($r * 299 + $g * 587 + $b * 114) / 1000);
                    if ($lum < 160) {
                        $byte |= (0x80 >> $bit);
                    }
                }
                $out .= chr($byte);
            }
        }

        return $out;
    }
}
