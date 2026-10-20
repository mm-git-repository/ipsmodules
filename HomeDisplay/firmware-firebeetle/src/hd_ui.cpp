#include "hd_ui.h"

#include <GxEPD2_GFX.h>
#include <ArduinoJson.h>
#include <LittleFS.h>
#include <WiFi.h>

#include "board_pins.h"
#include "epaper_driver.h"
#include "hd_client.h"
#include "hd_config.h"
#include "hd_media.h"

#ifndef HOMEDISPLAY_FB_FIRMWARE_VERSION
#define HOMEDISPLAY_FB_FIRMWARE_VERSION "0.1.0"
#endif

static int currentPage = 0;
static unsigned long lastButtonMs = 0;
static bool lastButton = true;
static String lastStateDigest;
static String lastLayoutDigest;
static bool uiPartialEnabled = true;
static bool uiServerFrame = false;
static int uiTargetW = 400;
static int uiTargetH = 300;
static uint8_t *uiPrevMono = nullptr;
static size_t uiPrevMonoLen = 0;

struct TileHashEntry {
  char tileId[40];
  uint32_t hash;
};

static TileHashEntry tileHashCache[48];
static int tileHashCacheCount = 0;

static constexpr int kMaxPartialTiles = 4;

static uint32_t hashString(const char *text) {
  uint32_t hash = 5381;
  if (!text) return hash;
  while (*text) hash = ((hash << 5) + hash) + static_cast<uint8_t>(*text++);
  return hash;
}

static String readFile(const char *path) {
  if (!LittleFS.exists(path)) return "";
  File f = LittleFS.open(path, "r");
  if (!f) return "";
  String s = f.readString();
  f.close();
  return s;
}

static JsonObjectConst findStateTile(JsonArrayConst tiles, const char *tileId) {
  for (JsonObjectConst t : tiles) {
    if (strcmp(t["tileId"] | "", tileId) == 0) return t;
  }
  return JsonObjectConst();
}

static uint8_t fitTextSize(const char *text, int16_t w, int16_t h, bool withIcon) {
  if (!text || !text[0]) return 1;
  int16_t maxH = withIcon ? (h * 2) / 3 : h;
  for (uint8_t size = 4; size >= 1; size--) {
    int16_t tw = static_cast<int16_t>(strlen(text)) * 6 * size;
    int16_t th = 8 * size;
    if (tw <= w - 8 && th <= maxH - 4) return size;
  }
  return 1;
}

static void drawFitTile(Adafruit_GFX *gfx, int16_t x, int16_t y, int16_t w, int16_t h, const char *iconLabel, const char *mainText) {
  gfx->drawRect(x, y, w, h, GxEPD_BLACK);
  bool hasIcon = iconLabel && iconLabel[0];
  uint8_t mainSize = fitTextSize(mainText, w, h, hasIcon);
  int16_t th = 8 * mainSize;
  int16_t tw = static_cast<int16_t>(strlen(mainText)) * 6 * mainSize;
  int16_t cy = y + (h - th) / 2;
  if (hasIcon) {
    uint8_t iconSize = fitTextSize(iconLabel, w, h / 3, false);
    int16_t ih = 8 * iconSize;
    cy = y + ih + (h - ih - th) / 2;
    gfx->setTextSize(iconSize);
    gfx->setTextColor(GxEPD_BLACK);
    int16_t itw = static_cast<int16_t>(strlen(iconLabel)) * 6 * iconSize;
    gfx->setCursor(x + (w - itw) / 2, y + 4 + ih);
    gfx->print(iconLabel);
  }
  gfx->setTextSize(mainSize);
  gfx->setCursor(x + (w - tw) / 2, cy);
  gfx->print(mainText);
}

static void drawChartTile(Adafruit_GFX *gfx, JsonObject tile, JsonObjectConst st, int16_t x, int16_t y, int16_t w, int16_t h) {
  gfx->drawRect(x, y, w, h, GxEPD_BLACK);
  const char *title = tile["title"] | "Chart";
  const char *stateText = st["text"] | "—";
  gfx->setTextSize(1);
  gfx->setTextColor(GxEPD_BLACK);
  gfx->setCursor(x + 3, y + 9);
  gfx->print(title);
  int16_t tw = static_cast<int16_t>(strlen(stateText)) * 6;
  gfx->setCursor(x + w - tw - 3, y + 9);
  gfx->print(stateText);
  gfx->drawFastHLine(x + 2, y + 12, w - 4, GxEPD_BLACK);
  JsonArrayConst series = st["series"].as<JsonArrayConst>();
  if (series.isNull() || series.size() < 2) return;
  float minV = st["seriesMin"] | series[0].as<float>();
  float maxV = st["seriesMax"] | series[0].as<float>();
  if (maxV <= minV) {
    maxV = minV + 1.0f;
  }
  int16_t chartY = y + 14;
  int16_t chartH = h - 18;
  int16_t chartX = x + 3;
  int16_t chartW = w - 6;
  if (chartH < 4 || chartW < 4) return;
  size_t n = series.size();
  int16_t px = chartX;
  int16_t py = chartY + chartH;
  for (size_t i = 0; i < n; i++) {
    float v = series[i].as<float>();
    int16_t nx = chartX + static_cast<int16_t>((chartW * static_cast<int32_t>(i)) / static_cast<int32_t>(n - 1));
    int16_t ny = chartY + chartH - static_cast<int16_t>(((v - minV) / (maxV - minV)) * chartH);
    if (i > 0) gfx->drawLine(px, py, nx, ny, GxEPD_BLACK);
    px = nx;
    py = ny;
  }
}

static void drawBattery(Adafruit_GFX *gfx, int16_t x, int16_t y, int16_t w, int16_t h, float pct) {
  int16_t bw = w - 8;
  int16_t bh = h / 3;
  int16_t bx = x + 4;
  int16_t by = y + (h - bh) / 2;
  gfx->drawRect(bx, by, bw, bh, GxEPD_BLACK);
  gfx->fillRect(bx + bw, by + bh / 4, 4, bh / 2, GxEPD_BLACK);
  int16_t fill = static_cast<int16_t>((bw - 4) * pct / 100.0f);
  if (fill > 0) gfx->fillRect(bx + 2, by + 2, fill, bh - 4, GxEPD_BLACK);
}

static float flowWatts(JsonObjectConst st, const char *key) {
  if (st.isNull()) return 0.0f;
  JsonObjectConst flows = st["flows"];
  if (flows.isNull()) return 0.0f;
  JsonObjectConst node = flows[key];
  if (node.isNull() || !node["w"].is<float>()) return 0.0f;
  return node["w"].as<float>();
}

static void drawFlowLine(Adafruit_GFX *gfx, int16_t x1, int16_t y1, int16_t x2, int16_t y2, bool active) {
  if (!active) return;
  gfx->drawLine(x1, y1, x2, y2, GxEPD_BLACK);
  int16_t mx = (x1 + x2) / 2;
  int16_t my = (y1 + y2) / 2;
  if (abs(x2 - x1) > abs(y2 - y1)) {
    gfx->drawLine(mx - 2, my - 2, mx + 2, my, GxEPD_BLACK);
    gfx->drawLine(mx - 2, my + 2, mx + 2, my, GxEPD_BLACK);
  } else {
    gfx->drawLine(mx, my - 2, mx + 2, my + 2, GxEPD_BLACK);
    gfx->drawLine(mx, my - 2, mx - 2, my + 2, GxEPD_BLACK);
  }
}

static bool flowLineActive(JsonObjectConst st, const char *lineId, bool fallback) {
  JsonObjectConst flows = st["flows"];
  if (flows.isNull()) return fallback;
  JsonObjectConst lines = flows["lines"];
  if (lines.isNull()) return fallback;
  JsonObjectConst line = lines[lineId];
  if (line.isNull()) return fallback;
  return line["active"] | false;
}

static void drawEnergyFlowTile(Adafruit_GFX *gfx, JsonObject tile, JsonObjectConst st, int16_t x, int16_t y, int16_t w, int16_t h) {
  gfx->drawRect(x, y, w, h, GxEPD_BLACK);
  float threshold = tile["flowThreshold"] | 15.0f;
  float grid = flowWatts(st, "grid");
  float pv = flowWatts(st, "pv");
  float battery = flowWatts(st, "battery");
  int16_t cx = x + w / 2;
  int16_t cy = y + h / 2 + 2;
  int16_t topY = y + 10;
  int16_t leftX = x + 8;
  int16_t rightX = x + w - 8;
  bool hasLines = !st.isNull() && !st["flows"]["lines"].isNull();
  drawFlowLine(gfx, cx, topY + 6, cx, cy - 10, hasLines ? flowLineActive(st, "solarToHome", false) : pv > threshold);
  drawFlowLine(gfx, leftX + 6, cy, cx - 12, cy, hasLines ? flowLineActive(st, "gridToHome", false) : grid > threshold);
  drawFlowLine(gfx, cx - 12, cy, leftX + 6, cy, hasLines ? flowLineActive(st, "homeToGrid", false) : grid < -threshold);
  drawFlowLine(gfx, cx + 12, cy, rightX - 6, cy, hasLines ? flowLineActive(st, "homeToBattery", false) : battery > threshold);
  drawFlowLine(gfx, rightX - 6, cy, cx + 12, cy, hasLines ? flowLineActive(st, "batteryToHome", false) : battery < -threshold);
  gfx->fillCircle(cx, cy, 4, GxEPD_BLACK);
  gfx->setTextSize(1);
  gfx->setTextColor(GxEPD_BLACK);
  gfx->setCursor(cx - 6, topY);
  gfx->print("PV");
  gfx->setCursor(leftX, cy + 10);
  gfx->print("Netz");
  gfx->setCursor(cx - 10, cy + 12);
  gfx->print("Haus");
  gfx->setCursor(rightX - 18, cy + 10);
  gfx->print("Akku");
}

static void drawValueTile(Adafruit_GFX *gfx, JsonObject tile, JsonObjectConst st, int16_t x, int16_t y, int16_t w, int16_t h) {
  const char *color = st["color"] | "neutral";
  bool invert = strcmp(color, "warn") == 0 || strcmp(color, "alarm") == 0;
  if (invert) gfx->fillRect(x, y, w, h, GxEPD_BLACK);
  uint16_t fg = invert ? GxEPD_WHITE : GxEPD_BLACK;
  uint16_t bg = invert ? GxEPD_BLACK : GxEPD_WHITE;
  gfx->drawRect(x, y, w, h, fg);
  const char *title = tile["title"];
  if (title && title[0]) {
    gfx->setTextColor(fg, bg);
    gfx->setTextSize(1);
    gfx->setCursor(x + 4, y + 10);
    gfx->print(title);
  }
  const char *control = tile["control"] | "switch";
  if (strcmp(control, "battery") == 0 && !st.isNull()) {
    float vmin = tile["valueMin"] | 0.0f;
    float vmax = tile["valueMax"] | 100.0f;
    float val = st["value"].is<float>() ? st["value"].as<float>() : vmin;
    float pct = vmax > vmin ? (val - vmin) * 100.0f / (vmax - vmin) : 0;
    if (pct < 0) pct = 0;
    if (pct > 100) pct = 100;
    drawBattery(gfx, x, y + 14, w, h - 18, pct);
    return;
  }
  gfx->setTextColor(fg, bg);
  gfx->setTextSize(w > 200 ? 2 : 1);
  gfx->setCursor(x + 4, y + h / 2);
  const char *text = st["text"] | "—";
  gfx->print(text);
}

static void drawSystemTile(Adafruit_GFX *gfx, int16_t x, int16_t y, int16_t w, int16_t h) {
  gfx->drawRect(x, y, w, h, GxEPD_BLACK);
  gfx->setTextSize(1);
  gfx->setTextColor(GxEPD_BLACK);
  gfx->setCursor(x + 4, y + 12);
  gfx->printf("FW %s", HOMEDISPLAY_FB_FIRMWARE_VERSION);
  gfx->setCursor(x + 4, y + 26);
  gfx->printf("WLAN %d dBm", WiFi.RSSI());
  gfx->setCursor(x + 4, y + 40);
  gfx->print(hdClientOnline() ? "Online" : "Offline");
  gfx->setCursor(x + 4, y + 54);
  gfx->printf("Heap %u", ESP.getFreeHeap());
}

static void loadUiFlags() {
  String cfgJson = readFile("/config.json");
  JsonDocument cfgDoc;
  if (!cfgJson.isEmpty() && deserializeJson(cfgDoc, cfgJson) == DeserializationError::Ok) {
    uiPartialEnabled = cfgDoc["epdPartialRefresh"] | true;
    uiServerFrame = cfgDoc["epdServerFrame"] | false;
    uiTargetW = cfgDoc["targetWidth"] | 400;
    uiTargetH = cfgDoc["targetHeight"] | 300;
  }
  HdDeviceConfig cfg;
  if (hdConfigLoad(cfg)) {
    uiPartialEnabled = uiPartialEnabled && cfg.displayFastRefresh;
  }
}

static uint32_t hashTileState(JsonObjectConst st, const char *kind) {
  if (st.isNull()) return 0;
  String buf;
  buf += st["text"].as<const char *>();
  buf += '|';
  buf += st["color"].as<const char *>();
  buf += '|';
  if (st["value"].is<float>()) {
    buf += st["value"].as<float>();
  } else if (st["value"].is<int>()) {
    buf += st["value"].as<int>();
  }
  buf += '|';
  buf += st["forecast"].as<const char *>();
  buf += '|';
  buf += st["presenceState"].as<const char *>();
  buf += '|';
  buf += st["cameraSeq"].as<const char *>();
  JsonArrayConst series = st["series"].as<JsonArrayConst>();
  if (!series.isNull()) {
    buf += '|';
    buf += series.size();
    if (series.size() > 0) {
      buf += '|';
      buf += series[0].as<float>();
      buf += '|';
      buf += series[series.size() - 1].as<float>();
    }
  }
  if (strcmp(kind, "energyflow") == 0 && !st["flows"].isNull()) {
    buf += '|';
    JsonObjectConst flows = st["flows"];
    if (!flows["grid"].isNull() && flows["grid"]["w"].is<float>()) buf += flows["grid"]["w"].as<float>();
    if (!flows["pv"].isNull() && flows["pv"]["w"].is<float>()) buf += flows["pv"]["w"].as<float>();
  }
  return hashString(buf.c_str());
}

static uint32_t cachedTileHash(const char *tileId) {
  if (!tileId || !tileId[0]) return 0;
  for (int i = 0; i < tileHashCacheCount; i++) {
    if (strcmp(tileHashCache[i].tileId, tileId) == 0) return tileHashCache[i].hash;
  }
  return 0;
}

static void setCachedTileHash(const char *tileId, uint32_t hash) {
  if (!tileId || !tileId[0]) return;
  for (int i = 0; i < tileHashCacheCount; i++) {
    if (strcmp(tileHashCache[i].tileId, tileId) == 0) {
      tileHashCache[i].hash = hash;
      return;
    }
  }
  if (tileHashCacheCount >= static_cast<int>(sizeof(tileHashCache) / sizeof(tileHashCache[0]))) return;
  strncpy(tileHashCache[tileHashCacheCount].tileId, tileId, sizeof(tileHashCache[0].tileId) - 1);
  tileHashCache[tileHashCacheCount].tileId[sizeof(tileHashCache[0].tileId) - 1] = '\0';
  tileHashCache[tileHashCacheCount].hash = hash;
  tileHashCacheCount++;
}

static void resetTileHashCache() {
  tileHashCacheCount = 0;
}

static void drawSingleTile(Adafruit_GFX *gfx, JsonObject tile, JsonObjectConst st, int16_t x, int16_t y, int16_t w, int16_t h) {
  const char *kind = tile["kind"] | "value";
  if (strcmp(kind, "separator") == 0) {
    gfx->drawFastHLine(x + 2, y + h / 2, w - 4, GxEPD_BLACK);
  } else if (strcmp(kind, "label") == 0) {
    drawFitTile(gfx, x, y, w, h, "", tile["text"] | "");
  } else if (strcmp(kind, "display") == 0) {
    const char *source = tile["displaySource"] | "variable";
    const char *mainText = "—";
    if (strcmp(source, "text") == 0) {
      mainText = tile["text"] | "—";
    } else if (!st.isNull()) {
      mainText = st["text"] | "—";
    }
    drawFitTile(gfx, x, y, w, h, "", mainText);
  } else if (strcmp(kind, "energyflow") == 0) {
    drawEnergyFlowTile(gfx, tile, st, x, y, w, h);
  } else if (strcmp(kind, "chart") == 0) {
    drawChartTile(gfx, tile, st, x, y, w, h);
  } else if (strcmp(kind, "scene") == 0) {
    drawFitTile(gfx, x, y, w, h, "Szene", tile["title"] | "Scene");
  } else if (strcmp(kind, "weather") == 0) {
    const char *mainText = st["text"] | "—";
    drawFitTile(gfx, x, y, w, h, "Wetter", mainText);
  } else if (strcmp(kind, "presence") == 0) {
    const char *mainText = st["text"] | "—";
    drawFitTile(gfx, x, y, w, h, "Status", mainText);
  } else if (strcmp(kind, "nav") == 0) {
    gfx->drawRect(x, y, w, h, GxEPD_BLACK);
    gfx->setCursor(x + 4, y + h / 2);
    gfx->print("-> ");
    gfx->print(tile["targetPage"] | "");
  } else if (strcmp(kind, "system") == 0) {
    drawSystemTile(gfx, x, y, w, h);
  } else if (strcmp(kind, "image") == 0 || strcmp(kind, "camera") == 0) {
    hdMediaDrawTile(gfx, tile, st, x, y, w, h);
  } else {
    drawValueTile(gfx, tile, st, x, y, w, h);
  }
}

static JsonObject gPartialTile;
static JsonObjectConst gPartialSt;
static int16_t gPartialX = 0;
static int16_t gPartialY = 0;
static int16_t gPartialW = 0;
static int16_t gPartialH = 0;

static void drawPartialTilePage(Adafruit_GFX *gfx) {
  int16_t ax = static_cast<int16_t>((gPartialX / 8) * 8);
  int16_t aw = static_cast<int16_t>(gPartialW + (gPartialX - ax));
  aw = static_cast<int16_t>((aw + 7) & ~7);
  gfx->fillRect(ax, gPartialY, aw, gPartialH, GxEPD_WHITE);
  drawSingleTile(gfx, gPartialTile, gPartialSt, gPartialX, gPartialY, gPartialW, gPartialH);
}

static void partialUpdateTile(JsonObject tile, JsonObjectConst st, int16_t x, int16_t y, int16_t w, int16_t h) {
  gPartialTile = tile;
  gPartialSt = st;
  gPartialX = x;
  gPartialY = y;
  gPartialW = w;
  gPartialH = h;
  epaperPartialRefresh(x, y, w, h, drawPartialTilePage);
}

static bool monoRegionDiff(const uint8_t *prev, const uint8_t *next, int16_t screenW, int16_t x, int16_t y, int16_t w, int16_t h) {
  if (!prev || !next) return true;
  int16_t ax = static_cast<int16_t>((x / 8) * 8);
  int16_t aw = static_cast<int16_t>(w + (x - ax));
  aw = static_cast<int16_t>((aw + 7) & ~7);
  const int16_t rowBytes = static_cast<int16_t>(screenW / 8);
  for (int16_t row = 0; row < h; row++) {
    int16_t sy = y + row;
    if (sy < 0) continue;
    for (int16_t bx = 0; bx < aw / 8; bx++) {
      int16_t sx = static_cast<int16_t>(ax + bx * 8);
      size_t idx = static_cast<size_t>(sy * rowBytes + sx / 8);
      if (prev[idx] != next[idx]) return true;
    }
  }
  return false;
}

static void copyMonoPage(uint8_t *dst, const uint8_t *src, size_t len) {
  if (dst && src) memcpy(dst, src, len);
}

static bool refreshFromServerFrame(JsonObject page, int cols, int rows, int header, int screenW, int screenH) {
  if (!uiServerFrame || !epaperPartialRefreshAvailable()) return false;
  char path[48];
  snprintf(path, sizeof(path), "/v1/epd/frame/%d", currentPage);
  int httpCode = 0;
  String mono = hdClientGetBinary(path, &httpCode);
  const size_t expected = static_cast<size_t>((screenW * screenH) / 8);
  if (httpCode != 200 || mono.length() != expected) return false;

  const uint8_t *next = reinterpret_cast<const uint8_t *>(mono.c_str());
  const int cellW = screenW / cols;
  const int cellH = (screenH - header) / rows;
  const bool needFull = uiPrevMono == nullptr || uiPrevMonoLen != expected || epaperPartialsSinceFull() >= 18;
  if (needFull) {
    epaperDrawMonoFull(next, static_cast<int16_t>(screenW), static_cast<int16_t>(screenH));
    if (uiPrevMono == nullptr || uiPrevMonoLen != expected) {
      free(uiPrevMono);
      uiPrevMono = static_cast<uint8_t *>(malloc(expected));
      uiPrevMonoLen = expected;
    }
    if (uiPrevMono) copyMonoPage(uiPrevMono, next, expected);
    return true;
  }

  JsonArray tiles = page["tiles"].as<JsonArray>();
  if (tiles.isNull()) return false;
  int updates = 0;
  for (JsonObject tile : tiles) {
    int col = tile["col"] | 0;
    int row = tile["row"] | 0;
    int colSpan = tile["colSpan"] | 1;
    int rowSpan = tile["rowSpan"] | 1;
    int16_t x = static_cast<int16_t>(col * cellW);
    int16_t y = static_cast<int16_t>(header + row * cellH);
    int16_t w = static_cast<int16_t>(colSpan * cellW);
    int16_t h = static_cast<int16_t>(rowSpan * cellH);
    if (!monoRegionDiff(uiPrevMono, next, static_cast<int16_t>(screenW), x, y, w, h)) continue;
    epaperDrawMonoRegion(next, static_cast<int16_t>(screenW), static_cast<int16_t>(screenH), x, y, w, h);
    updates++;
  }
  if (updates == 0) {
    return false;
  }
  if (uiPrevMono) copyMonoPage(uiPrevMono, next, expected);
  return true;
}

static void drawPage(Adafruit_GFX *gfx) {
  String layoutJson = readFile("/layout.json");
  String stateJson = readFile("/state.json");
  if (layoutJson.isEmpty()) {
    gfx->fillScreen(GxEPD_WHITE);
    gfx->setCursor(8, 40);
    gfx->print("Kein Layout");
    return;
  }
  JsonDocument layoutDoc;
  if (deserializeJson(layoutDoc, layoutJson) != DeserializationError::Ok) return;
  JsonDocument stateDoc;
  JsonArrayConst stateTiles;
  if (!stateJson.isEmpty() && deserializeJson(stateDoc, stateJson) == DeserializationError::Ok) {
    stateTiles = stateDoc["tiles"].as<JsonArrayConst>();
    hdMediaSyncFromState(stateTiles);
  }
  int cols = layoutDoc["columns"] | 3;
  int rows = layoutDoc["rows"] | 4;
  JsonArray pages = layoutDoc["pages"].as<JsonArray>();
  if (pages.isNull() || pages.size() == 0) return;
  if (currentPage >= static_cast<int>(pages.size())) currentPage = 0;
  JsonObject page = pages[currentPage].as<JsonObject>();
  const char *pageTitle = page["title"] | "";
  uint16_t W = epaperWidth();
  uint16_t H = epaperHeight();
  int header = 18;
  gfx->fillScreen(GxEPD_WHITE);
  gfx->setTextSize(1);
  gfx->setTextColor(GxEPD_BLACK);
  gfx->setCursor(4, 12);
  gfx->print(pageTitle);
  gfx->drawFastHLine(0, header, W, GxEPD_BLACK);
  int cellW = W / cols;
  int cellH = (H - header) / rows;
  JsonArray tiles = page["tiles"].as<JsonArray>();
  if (tiles.isNull()) return;
  for (JsonObject tile : tiles) {
    int col = tile["col"] | 0;
    int row = tile["row"] | 0;
    int colSpan = tile["colSpan"] | 1;
    int rowSpan = tile["rowSpan"] | 1;
    int16_t x = col * cellW;
    int16_t y = header + row * cellH;
    int16_t w = colSpan * cellW;
    int16_t h = rowSpan * cellH;
    const char *tileId = tile["tileId"] | "";
    JsonObjectConst st = findStateTile(stateTiles, tileId);
    drawSingleTile(gfx, tile, st, x, y, w, h);
  }
}

static void fullRedraw() {
  free(uiPrevMono);
  uiPrevMono = nullptr;
  uiPrevMonoLen = 0;
  epaperFullRefresh([](Adafruit_GFX *gfx) { drawPage(gfx); });
}

void hdUiBegin() {
  hdMediaBegin();
  currentPage = 0;
  pinMode(BUTTON_GPIO, INPUT_PULLUP);
  loadUiFlags();
}

void hdUiRefreshFromDisk() {
  loadUiFlags();
  String layoutJson = readFile("/layout.json");
  String stateJson = readFile("/state.json");
  if (layoutJson.isEmpty()) return;

  char layoutDigest[12];
  snprintf(layoutDigest, sizeof(layoutDigest), "%08lx", static_cast<unsigned long>(hashString(layoutJson.c_str())));
  const bool layoutChanged = lastLayoutDigest != layoutDigest;
  if (layoutChanged) {
    lastLayoutDigest = layoutDigest;
    resetTileHashCache();
    free(uiPrevMono);
    uiPrevMono = nullptr;
    uiPrevMonoLen = 0;
  }

  if (stateJson.length()) {
    uint32_t crc = hashString(stateJson.c_str());
    char digest[12];
    snprintf(digest, sizeof(digest), "%08lx", static_cast<unsigned long>(crc));
    if (!layoutChanged && lastStateDigest == digest) return;
    lastStateDigest = digest;
  }

  JsonDocument layoutDoc;
  if (deserializeJson(layoutDoc, layoutJson) != DeserializationError::Ok) return;
  hdMediaEnsureLayoutImages(layoutDoc);

  JsonDocument stateDoc;
  JsonArrayConst stateTiles;
  if (!stateJson.isEmpty() && deserializeJson(stateDoc, stateJson) == DeserializationError::Ok) {
    stateTiles = stateDoc["tiles"].as<JsonArrayConst>();
    hdMediaSyncFromState(stateTiles);
  }

  int cols = layoutDoc["columns"] | 3;
  int rows = layoutDoc["rows"] | 4;
  JsonArray pages = layoutDoc["pages"].as<JsonArray>();
  if (pages.isNull() || pages.size() == 0) return;
  if (currentPage >= static_cast<int>(pages.size())) currentPage = 0;
  JsonObject page = pages[currentPage].as<JsonObject>();
  const int header = 18;
  const int screenW = uiTargetW > 0 ? uiTargetW : static_cast<int>(epaperWidth());
  const int screenH = uiTargetH > 0 ? uiTargetH : static_cast<int>(epaperHeight());
  const int cellW = screenW / cols;
  const int cellH = (screenH - header) / rows;

  if (refreshFromServerFrame(page, cols, rows, header, screenW, screenH)) {
    JsonArray tiles = page["tiles"].as<JsonArray>();
    if (!tiles.isNull()) {
      for (JsonObject tile : tiles) {
        const char *tileId = tile["tileId"] | "";
        JsonObjectConst st = findStateTile(stateTiles, tileId);
        setCachedTileHash(tileId, hashTileState(st, tile["kind"] | "value"));
      }
    }
    return;
  }

  if (layoutChanged || !uiPartialEnabled || !epaperPartialRefreshAvailable() || epaperPartialsSinceFull() >= 18) {
    fullRedraw();
    JsonArray tiles = page["tiles"].as<JsonArray>();
    if (!tiles.isNull()) {
      for (JsonObject tile : tiles) {
        const char *tileId = tile["tileId"] | "";
        JsonObjectConst st = findStateTile(stateTiles, tileId);
        setCachedTileHash(tileId, hashTileState(st, tile["kind"] | "value"));
      }
    }
    return;
  }

  JsonArray tiles = page["tiles"].as<JsonArray>();
  if (tiles.isNull()) {
    fullRedraw();
    return;
  }

  int dirtyCount = 0;
  for (JsonObject tile : tiles) {
    const char *tileId = tile["tileId"] | "";
    const char *kind = tile["kind"] | "value";
    JsonObjectConst st = findStateTile(stateTiles, tileId);
    const uint32_t hash = hashTileState(st, kind);
    if (hash != cachedTileHash(tileId)) dirtyCount++;
  }

  if (dirtyCount == 0) return;

  if (dirtyCount > kMaxPartialTiles) {
    fullRedraw();
    for (JsonObject tile : tiles) {
      const char *tileId = tile["tileId"] | "";
      setCachedTileHash(tileId, hashTileState(findStateTile(stateTiles, tileId), tile["kind"] | "value"));
    }
    return;
  }

  for (JsonObject tile : tiles) {
    const char *tileId = tile["tileId"] | "";
    const char *kind = tile["kind"] | "value";
    JsonObjectConst st = findStateTile(stateTiles, tileId);
    const uint32_t hash = hashTileState(st, kind);
    if (hash == cachedTileHash(tileId)) continue;
    int col = tile["col"] | 0;
    int row = tile["row"] | 0;
    int colSpan = tile["colSpan"] | 1;
    int rowSpan = tile["rowSpan"] | 1;
    int16_t x = static_cast<int16_t>(col * cellW);
    int16_t y = static_cast<int16_t>(header + row * cellH);
    int16_t w = static_cast<int16_t>(colSpan * cellW);
    int16_t h = static_cast<int16_t>(rowSpan * cellH);
    if (strcmp(kind, "image") == 0 || strcmp(kind, "camera") == 0) {
      fullRedraw();
      resetTileHashCache();
      for (JsonObject t2 : tiles) {
        const char *id2 = t2["tileId"] | "";
        setCachedTileHash(id2, hashTileState(findStateTile(stateTiles, id2), t2["kind"] | "value"));
      }
      return;
    }
    partialUpdateTile(tile, st, x, y, w, h);
    setCachedTileHash(tileId, hash);
  }
}

void hdUiNextPage() {
  String layoutJson = readFile("/layout.json");
  JsonDocument layoutDoc;
  if (layoutJson.isEmpty() || deserializeJson(layoutDoc, layoutJson) != DeserializationError::Ok) return;
  JsonArray pages = layoutDoc["pages"].as<JsonArray>();
  if (pages.isNull() || pages.size() == 0) return;
  currentPage = (currentPage + 1) % static_cast<int>(pages.size());
  fullRedraw();
}

int hdUiPageCount() {
  String layoutJson = readFile("/layout.json");
  JsonDocument layoutDoc;
  if (layoutJson.isEmpty() || deserializeJson(layoutDoc, layoutJson) != DeserializationError::Ok) return 0;
  JsonArray pages = layoutDoc["pages"].as<JsonArray>();
  return pages.isNull() ? 0 : static_cast<int>(pages.size());
}

void hdUiHandleButton() {
  bool pressed = digitalRead(BUTTON_GPIO) == LOW;
  unsigned long now = millis();
  if (pressed && !lastButton && now - lastButtonMs > 400) {
    lastButtonMs = now;
    hdUiNextPage();
  }
  lastButton = pressed;
}
