#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/* JPEG aus dem Speicher nach RGB565 dekodieren (TJpgDec aus LVGL, kein Dateizugriff).
 * Nur vom Client-Task aufrufen, der Arbeitspuffer ist gemeinsam. */

/* Liest Breite und Höhe. Wählt die kleinste Verkleinerung (0 bis 3), bei der w*h höchstens max_px ist.
 * Liefert 0 bei Erfolg, sonst den TJpgDec-Fehlercode (3 = Speicher, 8 = progressiv oder nicht unterstützt). */
int homedisplay_jpeg_probe(const uint8_t *data, size_t length, size_t max_px, int *width, int *height, int *scale);

/* Dekodiert in dst (width*height Pixel zu 2 Byte). Liefert 0 bei Erfolg. */
int homedisplay_jpeg_decode(const uint8_t *data, size_t length, int scale, uint16_t *dst, int width, int height);
