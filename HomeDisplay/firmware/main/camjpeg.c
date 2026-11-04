#include "camjpeg.h"

#include "lvgl.h"

#if LV_USE_SJPG

#include "extra/libs/sjpg/tjpgd.h"

#include <string.h>

#define JPEG_WORK_BYTES 4096

static uint8_t jpeg_work[JPEG_WORK_BYTES] __attribute__((aligned(4)));

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

/* TJpgDec liefert RGB888 je Block. LVGL läuft mit 16 Bit ohne Byte-Tausch. */
static int jpeg_out(JDEC *jd, void *bitmap, JRECT *rect)
{
    jpeg_ctx_t *ctx = (jpeg_ctx_t *) jd->device;
    const uint8_t *p = (const uint8_t *) bitmap;
    int row_width = (int) rect->right - (int) rect->left + 1;
    for (int y = rect->top; y <= (int) rect->bottom; y++) {
        if (y >= ctx->height) {
            p += row_width * 3;
            continue;
        }
        uint16_t *row = ctx->dst + (size_t) y * (size_t) ctx->width;
        for (int x = rect->left; x <= (int) rect->right; x++, p += 3) {
            if (x >= ctx->width) continue;
            row[x] = (uint16_t) (((p[0] & 0xF8) << 8) | ((p[1] & 0xFC) << 3) | (p[2] >> 3));
        }
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
    jpeg_ctx_t ctx = {.data = data, .length = length};
    JDEC jd;
    JRESULT result = jd_prepare(&jd, jpeg_in, jpeg_work, JPEG_WORK_BYTES, &ctx);
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
    jpeg_ctx_t ctx = {.data = data, .length = length, .dst = dst, .width = width, .height = height};
    JDEC jd;
    JRESULT result = jd_prepare(&jd, jpeg_in, jpeg_work, JPEG_WORK_BYTES, &ctx);
    if (result != JDR_OK) return (int) result;
    result = jd_decomp(&jd, jpeg_out, (uint8_t) scale);
    return (int) result;
}

#else

int homedisplay_jpeg_probe(const uint8_t *data, size_t length, size_t max_px, int *width, int *height, int *scale)
{
    (void) data;
    (void) length;
    (void) max_px;
    (void) width;
    (void) height;
    (void) scale;
    return 8;
}

int homedisplay_jpeg_decode(const uint8_t *data, size_t length, int scale, uint16_t *dst, int width, int height)
{
    (void) data;
    (void) length;
    (void) scale;
    (void) dst;
    (void) width;
    (void) height;
    return 8;
}

#endif
