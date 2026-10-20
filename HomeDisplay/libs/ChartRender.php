<?php

declare(strict_types=1);

/** Monochromes Diagramm-PNG für E-Ink (Phase 6). */
final class HomeDisplayChartRender
{
    /**
     * @param list<list<float>> $seriesList
     * @param list<float>       $yesterday
     */
    public static function png(
        int $width,
        int $height,
        array $seriesList,
        array $yesterday,
        float $yMin,
        float $yMax,
        string $style
    ): string {
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
        $gray = imagecolorallocate($img, 160, 160, 160);
        imagefilledrectangle($img, 0, 0, $width, $height, $white);
        $range = $yMax - $yMin;
        if ($range <= 0.0) {
            $range = 1.0;
        }
        $pad = 4;
        $plotW = $width - 2 * $pad;
        $plotH = $height - 2 * $pad;
        self::drawPolyline($img, $yesterday, $plotW, $plotH, $pad, $yMin, $range, $gray, false);
        $fillArea = $style === 'area';
        foreach ($seriesList as $index => $series) {
            if ($series === []) {
                continue;
            }
            $color = $index === 0 ? $black : $gray;
            self::drawPolyline($img, $series, $plotW, $plotH, $pad, $yMin, $range, $color, $fillArea && $index === 0);
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }

    /** @param list<float> $series */
    private static function drawPolyline(
        \GdImage $img,
        array $series,
        int $plotW,
        int $plotH,
        int $pad,
        float $yMin,
        float $range,
        int $color,
        bool $fillArea
    ): void {
        $n = count($series);
        if ($n < 2) {
            return;
        }
        $points = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $pad + (int) round(($plotW * $i) / ($n - 1));
            $y = $pad + $plotH - (int) round((($series[$i] - $yMin) / $range) * $plotH);
            $points[] = [$x, $y];
        }
        if ($fillArea) {
            $poly = [$pad, $pad + $plotH];
            foreach ($points as $pt) {
                $poly[] = $pt[0];
                $poly[] = $pt[1];
            }
            $poly[] = $pad + $plotW;
            $poly[] = $pad + $plotH;
            imagefilledpolygon($img, $poly, $color);
        }
        for ($i = 1; $i < $n; $i++) {
            imageline($img, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $color);
        }
    }
}
