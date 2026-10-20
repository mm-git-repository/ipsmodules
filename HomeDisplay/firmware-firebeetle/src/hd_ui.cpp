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
      gfx->setTextSize(strcmp(tile["size"] | "small", "large") == 0 ? 2 : 1);
      gfx->setCursor(x + 4, y + h / 2);
      gfx->print(tile["text"] | "");
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
