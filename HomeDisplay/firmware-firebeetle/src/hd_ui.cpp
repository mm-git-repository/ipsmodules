#include "hd_ui.h"

#include <GxEPD2_GFX.h>
#include <ArduinoJson.h>
#include <LittleFS.h>
#include <WiFi.h>

#include "board_pins.h"
#include "epaper_driver.h"
#include "hd_client.h"
#include "hd_media.h"

#ifndef HOMEDISPLAY_FB_FIRMWARE_VERSION
#define HOMEDISPLAY_FB_FIRMWARE_VERSION "0.1.0"
#endif

static int currentPage = 0;
static unsigned long lastButtonMs = 0;
static bool lastButton = true;

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
    const char *kind = tile["kind"] | "value";
    const char *tileId = tile["tileId"] | "";
    JsonObjectConst st = findStateTile(stateTiles, tileId);
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
}

static void fullRedraw() {
  epaperFullRefresh([](Adafruit_GFX *gfx) { drawPage(gfx); });
}

void hdUiBegin() {
  hdMediaBegin();
  currentPage = 0;
  pinMode(BUTTON_GPIO, INPUT_PULLUP);
}

void hdUiRefreshFromDisk() {
  String layoutJson = readFile("/layout.json");
  if (layoutJson.length()) {
    JsonDocument layoutDoc;
    if (deserializeJson(layoutDoc, layoutJson) == DeserializationError::Ok) {
      hdMediaEnsureLayoutImages(layoutDoc);
    }
  }
  fullRedraw();
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
