#include "hd_media.h"

#include <GxEPD2_GFX.h>
#include <LittleFS.h>
#include <TJpg_Decoder.h>

#include "hd_client.h"

struct DrawCtx {
  Adafruit_GFX *gfx;
  int16_t dx;
  int16_t dy;
  int16_t dw;
  int16_t dh;
  int16_t imgW;
  int16_t imgH;
};

static DrawCtx jpgCtx;
static char lastFetchKey[64];
static int lastFetchSeq = -1;

static bool jpgOutput(int16_t x, int16_t y, uint16_t w, uint16_t h, uint16_t *bitmap) {
  if (!jpgCtx.gfx || !bitmap) return false;
  for (uint16_t row = 0; row < h; row++) {
    int16_t py = jpgCtx.dy + ((y + row) * jpgCtx.dh) / jpgCtx.imgH;
    if (py < jpgCtx.dy || py >= jpgCtx.dy + jpgCtx.dh) continue;
    for (uint16_t col = 0; col < w; col++) {
      int16_t px = jpgCtx.dx + ((x + col) * jpgCtx.dw) / jpgCtx.imgW;
      if (px < jpgCtx.dx || px >= jpgCtx.dx + jpgCtx.dw) continue;
      uint16_t c = bitmap[col];
      uint8_t r = (c >> 11) << 3;
      uint8_t g = ((c >> 5) & 0x3F) << 2;
      uint8_t b = (c & 0x1F) << 3;
      uint16_t gray = (r + g + b) / 3;
      jpgCtx.gfx->drawPixel(px, py, gray < 128 ? GxEPD_BLACK : GxEPD_WHITE);
    }
  }
  return true;
}

static bool drawJpegBytes(Adafruit_GFX *gfx, const uint8_t *data, size_t len, int16_t x, int16_t y, int16_t w, int16_t h) {
  if (!data || len < 4) return false;
  jpgCtx.gfx = gfx;
  jpgCtx.dx = x;
  jpgCtx.dy = y;
  jpgCtx.dw = w;
  jpgCtx.dh = h;
  jpgCtx.imgW = 1;
  jpgCtx.imgH = 1;
  uint16_t iw = 0;
  uint16_t ih = 0;
  if (TJpgDec.getJpgSize(&iw, &ih, data, len) != JDR_OK || iw == 0 || ih == 0) return false;
  jpgCtx.imgW = iw;
  jpgCtx.imgH = ih;
  TJpgDec.setJpgScale(1);
  TJpgDec.setCallback(jpgOutput);
  return TJpgDec.drawJpg(0, 0, data, len) == JDR_OK;
}

static bool drawCachedFile(Adafruit_GFX *gfx, const char *path, int16_t x, int16_t y, int16_t w, int16_t h) {
  File f = LittleFS.open(path, "r");
  if (!f) return false;
  size_t len = f.size();
  if (len == 0 || len > 120000) {
    f.close();
    return false;
  }
  uint8_t *buf = static_cast<uint8_t *>(malloc(len));
  if (!buf) {
    f.close();
    return false;
  }
  f.read(buf, len);
  f.close();
  bool ok = false;
  if (len > 2 && buf[0] == 0xFF && buf[1] == 0xD8) {
    ok = drawJpegBytes(gfx, buf, len, x, y, w, h);
  } else {
    gfx->drawRect(x, y, w, h, GxEPD_BLACK);
    gfx->setCursor(x + 4, y + h / 2);
    gfx->print("Bild");
    ok = true;
  }
  free(buf);
  return ok;
}

static void cacheBinary(const char *path, const String &body) {
  if (body.length() == 0) return;
  File f = LittleFS.open(path, "w");
  if (!f) return;
  f.write(reinterpret_cast<const uint8_t *>(body.c_str()), body.length());
  f.close();
}

static void fetchIfNeeded(const char *cachePath, const char *urlPath, const char *key, int seq) {
  if (seq <= 0) return;
  char tag[80];
  snprintf(tag, sizeof(tag), "%s:%d", key, seq);
  if (strcmp(tag, lastFetchKey) == 0 && lastFetchSeq == seq) {
    File test = LittleFS.open(cachePath, "r");
    if (test) {
      test.close();
      return;
    }
  }
  String body = hdClientGetBinary(urlPath);
  if (body.length()) {
    cacheBinary(cachePath, body);
    strncpy(lastFetchKey, tag, sizeof(lastFetchKey) - 1);
    lastFetchKey[sizeof(lastFetchKey) - 1] = '\0';
    lastFetchSeq = seq;
  }
}

void hdMediaBegin() {
  TJpgDec.setJpgScale(1);
  TJpgDec.setCallback(jpgOutput);
}

void hdMediaSyncFromState(JsonArrayConst stateTiles) {
  for (JsonObjectConst st : stateTiles) {
    const char *tileId = st["tileId"];
    if (!tileId) continue;
    char path[48];
    if (st["cameraSeq"].is<int>()) {
      int seq = st["cameraSeq"].as<int>();
      snprintf(path, sizeof(path), "/cam_%s.jpg", tileId);
      char url[56];
      snprintf(url, sizeof(url), "/v1/camera/%s", tileId);
      fetchIfNeeded(path, url, tileId, seq);
    }
    if (st["mediaSeq"].is<int>()) {
      int seq = st["mediaSeq"].as<int>();
      snprintf(path, sizeof(path), "/med_%s.jpg", tileId);
      char url[56];
      snprintf(url, sizeof(url), "/v1/media/%s", tileId);
      fetchIfNeeded(path, url, tileId, seq);
    }
  }
}

void hdMediaEnsureLayoutImages(JsonDocument &layout) {
  JsonArray pages = layout["pages"].as<JsonArray>();
  if (pages.isNull()) return;
  for (JsonObject page : pages) {
    JsonArray tiles = page["tiles"].as<JsonArray>();
    if (tiles.isNull()) continue;
    for (JsonObject tile : tiles) {
      const char *kind = tile["kind"];
      if (!kind || strcmp(kind, "image") != 0) continue;
      const char *source = tile["imageSource"] | "catalog";
      const char *tileId = tile["tileId"];
      if (!tileId) continue;
      char path[48];
      char url[64];
      if (strcmp(source, "media") == 0) {
        snprintf(path, sizeof(path), "/img_%s.jpg", tileId);
        snprintf(url, sizeof(url), "/v1/media/%s", tileId);
      } else {
        const char *imageId = tile["imageId"];
        if (!imageId || !imageId[0]) continue;
        snprintf(path, sizeof(path), "/img_%s.jpg", tileId);
        snprintf(url, sizeof(url), "/v1/images/%s", imageId);
      }
      File test = LittleFS.open(path, "r");
      if (test) {
        test.close();
        continue;
      }
      String body = hdClientGetBinary(url);
      if (body.length()) cacheBinary(path, body);
    }
  }
}

void hdMediaDrawTile(Adafruit_GFX *gfx, JsonObject tile, JsonObjectConst stateTile, int16_t x, int16_t y, int16_t w, int16_t h) {
  const char *kind = tile["kind"];
  if (!kind) return;
  gfx->drawRect(x, y, w, h, GxEPD_BLACK);
  char path[48];
  const char *tileId = tile["tileId"];
  if (!tileId) return;
  if (strcmp(kind, "image") == 0) {
    snprintf(path, sizeof(path), "/img_%s.jpg", tileId);
    if (!drawCachedFile(gfx, path, x + 1, y + 1, w - 2, h - 2)) {
      gfx->setCursor(x + 4, y + h / 2);
      gfx->print("Bild");
    }
    return;
  }
  if (strcmp(kind, "camera") == 0) {
    snprintf(path, sizeof(path), "/cam_%s.jpg", tileId);
    if (stateTile.isNull() || (stateTile["quality"] | "empty") == "empty") {
      gfx->setCursor(x + 4, y + h / 2);
      gfx->print("Kamera");
      return;
    }
    if (!drawCachedFile(gfx, path, x + 1, y + 1, w - 2, h - 2)) {
      gfx->setCursor(x + 4, y + h / 2);
      gfx->print("...");
    }
    return;
  }
}
