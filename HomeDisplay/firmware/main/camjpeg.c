#include "camjpeg.h"

#include "camtjpgd.h"
#include "esp_heap_caps.h"

#include <string.h>

/* Huffman-Tabellen (JD_FASTDECODE 2) und Eingabepuffer brauchen gut 13 KB. Nur ein Task dekodiert.
 * Interner Speicher ist schneller, ist aber knapp; sonst PSRAM. */
#define JPEG_WORK_BYTES 20480

static uint8_t *jpeg_work;

static bool jpeg_work_ready(void)
{
    if (jpeg_work) return true;
    jpeg_work = heap_caps_aligned_alloc(4, JPEG_WORK_BYTES, MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT);
    if (!jpeg_work) jpeg_work = heap_caps_aligned_alloc(4, JPEG_WORK_BYTES, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    return jpeg_work != NULL;
}

typedef struct {
    const uint8_t *data;
    size_t length;
    size_t pos;
    uint16_t *dst;
    int width;
    int height;
} jpeg_ctx_t;

static size_t jpeg_in(JDEC *jd, uint8_t *buffer, size_t count)
{
    jpeg_ctx_t *ctx = (jpeg_ctx_t *) jd->device;
    size_t left = ctx->length > ctx->pos ? ctx->length - ctx->pos : 0;
    if (count > left) count = left;
    if (buffer) memcpy(buffer, ctx->data + ctx->pos, count);
    ctx->pos += count;
    return count;
}

/* Der Decoder liefert RGB565 je Block. LVGL läuft mit 16 Bit ohne Byte-Tausch. */
static int jpeg_out(JDEC *jd, void *bitmap, JRECT *rect)
{
    jpeg_ctx_t *ctx = (jpeg_ctx_t *) jd->device;
    const uint16_t *p = (const uint16_t *) bitmap;
    int block_w = (int) rect->right - (int) rect->left + 1;
    if (rect->left >= ctx->width) return 1;
    int copy_w = block_w;
    if ((int) rect->left + copy_w > ctx->width) copy_w = ctx->width - (int) rect->left;
    for (int y = rect->top; y <= (int) rect->bottom; y++, p += block_w) {
        if (y >= ctx->height) break;
        memcpy(ctx->dst + (size_t) y * (size_t) ctx->width + rect->left, p, (size_t) copy_w * sizeof(uint16_t));
    }
    return 1;
}

static int scaled(int size, int scale)
{
    return ((size - 1) >> scale) + 1;
}

int homedisplay_jpeg_probe(const uint8_t *data, size_t length, size_t max_px, int *width, int *height, int *scale)
{
    if (!data || length < 4 || !width || !height || !scale) return JDR_PAR;
    if (!jpeg_work_ready()) return JDR_MEM1;
    jpeg_ctx_t ctx = {.data = data, .length = length};
    JDEC jd;
    JRESULT result = camjd_prepare(&jd, jpeg_in, jpeg_work, JPEG_WORK_BYTES, &ctx);
    if (result != JDR_OK) return (int) result;
    int s = 0;
    while (s < 3 && (size_t) scaled(jd.width, s) * (size_t) scaled(jd.height, s) > max_px) s++;
    *scale = s;
    *width = scaled(jd.width, s);
    *height = scaled(jd.height, s);
    if ((size_t) *width * (size_t) *height > max_px) return JDR_MEM1;
    return JDR_OK;
}

int homedisplay_jpeg_decode(const uint8_t *data, size_t length, int scale, uint16_t *dst, int width, int height)
{
    if (!data || !dst || width < 1 || height < 1 || scale < 0 || scale > 3) return JDR_PAR;
    if (!jpeg_work_ready()) return JDR_MEM1;
    jpeg_ctx_t ctx = {.data = data, .length = length, .dst = dst, .width = width, .height = height};
    JDEC jd;
    JRESULT result = camjd_prepare(&jd, jpeg_in, jpeg_work, JPEG_WORK_BYTES, &ctx);
    if (result != JDR_OK) return (int) result;
    result = camjd_decomp(&jd, jpeg_out, (uint8_t) scale);
    return (int) result;
}
