#include "epaper_driver.h"

#include <GxEPD2_BW.h>
#include <GxEPD2_3C.h>
#include <SPI.h>
#include <gdey/GxEPD2_420_GDEY042T81.h>
#include <gdey/GxEPD2_750_GDEY075T7.h>
#include <gdey3c/GxEPD2_420c_GDEY042Z98.h>
#include <gdey3c/GxEPD2_750c_GDEY075Z08.h>

#include "epaper_pins.h"

static GxEPD2_BW<GxEPD2_420_GDEY042T81, GxEPD2_420_GDEY042T81::HEIGHT / 2> display042(
    GxEPD2_420_GDEY042T81(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));
static GxEPD2_BW<GxEPD2_750_GDEY075T7, GxEPD2_750_GDEY075T7::HEIGHT / 4> display075(
    GxEPD2_750_GDEY075T7(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));
static GxEPD2_3C<GxEPD2_420c_GDEY042Z98, GxEPD2_420c_GDEY042Z98::HEIGHT / 2> display042c(
    GxEPD2_420c_GDEY042Z98(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));
static GxEPD2_3C<GxEPD2_750c_GDEY075Z08, GxEPD2_750c_GDEY075Z08::HEIGHT / 4> display075c(
    GxEPD2_750c_GDEY075Z08(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));

static Adafruit_GFX *activeGfx = nullptr;
static bool use3c = false;
static bool use075 = false;

static constexpr unsigned long EPD_FULL_REFRESH_BW_MS = 5000;
static constexpr unsigned long EPD_FULL_REFRESH_BWR_MS = 28000;
static constexpr unsigned long EPD_BUSY_GUARD_THRESHOLD_MS = 2000;

static void ensureSpi() {
  static bool done = false;
  if (!done) {
    SPI.begin(EPD_SPI_SCK, EPD_SPI_MISO, EPD_SPI_MOSI);
    done = true;
  }
}

/** Wie papyrOS: Reset vor jedem Update, falls BUSY nicht greift. */
static void hardwareReset() {
  pinMode(EPD_RST, OUTPUT);
  pinMode(EPD_CS, OUTPUT);
  pinMode(EPD_DC, OUTPUT);
  digitalWrite(EPD_CS, HIGH);
  digitalWrite(EPD_RST, HIGH);
  delay(20);
  digitalWrite(EPD_RST, LOW);
  delay(40);
  digitalWrite(EPD_RST, HIGH);
  delay(200);
}

static void busyGuardDelay(unsigned long elapsedMs, unsigned long expectedMs) {
  if (elapsedMs < EPD_BUSY_GUARD_THRESHOLD_MS) {
    unsigned long wait = expectedMs > elapsedMs ? expectedMs - elapsedMs : 0;
    if (wait > 0) {
      Serial.printf("[epd] BUSY-Guard: warte %lu ms\n", wait);
      delay(wait);
    }
  }
}

static void initActivePanel(bool fullRefresh) {
  ensureSpi();
  hardwareReset();
  if (use3c && use075) {
    activeGfx = &display075c;
    display075c.init(115200, fullRefresh, EPD_RESET_DURATION_MS, false);
    display075c.setRotation(0);
    return;
  }
  if (use3c) {
    activeGfx = &display042c;
    display042c.init(115200, fullRefresh, EPD_RESET_DURATION_MS, false);
    display042c.setRotation(0);
    return;
  }
  if (use075) {
    activeGfx = &display075;
    display075.init(115200, fullRefresh, EPD_RESET_DURATION_MS, false);
    display075.setRotation(0);
    return;
  }
  activeGfx = &display042;
  display042.init(115200, fullRefresh, EPD_RESET_DURATION_MS, false);
  display042.setRotation(0);
}

void epaperInit(const HdDeviceConfig &cfg) {
  use075 = cfg.displayModel == DisplayModel::GDEY075_800x480;
  use3c = cfg.displayColor == DisplayColor::RED;
  Serial.printf("[epd] init model=%s color=%s\n", use075 ? "7.5" : "4.2", use3c ? "3C" : "BW");
  initActivePanel(true);
}

uint16_t epaperWidth() {
  return activeGfx ? activeGfx->width() : 400;
}

uint16_t epaperHeight() {
  return activeGfx ? activeGfx->height() : 300;
}

Adafruit_GFX *epaperGfx() {
  return activeGfx;
}

static void runPaged(void (*draw)(Adafruit_GFX *gfx), bool fullRefresh) {
  if (!draw) return;
  initActivePanel(fullRefresh);
  const unsigned long expectedMs = (use3c ? EPD_FULL_REFRESH_BWR_MS : EPD_FULL_REFRESH_BW_MS);
  unsigned long t0 = millis();
  if (use3c && use075) {
    display075c.setFullWindow();
    display075c.firstPage();
    do {
      draw(&display075c);
    } while (display075c.nextPage());
    display075c.hibernate();
  } else if (use3c) {
    display042c.setFullWindow();
    display042c.firstPage();
    do {
      draw(&display042c);
    } while (display042c.nextPage());
    display042c.hibernate();
  } else if (use075) {
    display075.setFullWindow();
    display075.firstPage();
    do {
      draw(&display075);
    } while (display075.nextPage());
    display075.hibernate();
  } else {
    display042.setFullWindow();
    display042.firstPage();
    do {
      draw(&display042);
    } while (display042.nextPage());
    display042.hibernate();
  }
  busyGuardDelay(millis() - t0, expectedMs);
}

void epaperFullRefresh(void (*draw)(Adafruit_GFX *gfx)) {
  runPaged(draw, true);
}

static const char *gStatusLine1 = "";
static const char *gStatusLine2 = "";

static void drawStatusPage(Adafruit_GFX *gfx) {
  gfx->fillScreen(GxEPD_WHITE);
  gfx->setTextColor(GxEPD_BLACK);
  gfx->setTextSize(2);
  gfx->setCursor(8, 40);
  gfx->print(gStatusLine1 ? gStatusLine1 : "");
  if (gStatusLine2 && gStatusLine2[0]) {
    gfx->setTextSize(1);
    gfx->setCursor(8, 80);
    gfx->print(gStatusLine2);
  }
}

void epaperShowStatus(const char *line1, const char *line2) {
  gStatusLine1 = line1;
  gStatusLine2 = line2;
  Serial.printf("[epd] status: %s / %s\n", line1 ? line1 : "", line2 ? line2 : "");
  epaperFullRefresh(drawStatusPage);
}

static void drawTestPatternPage(Adafruit_GFX *gfx) {
  const int w = gfx->width();
  const int h = gfx->height();
  gfx->fillScreen(GxEPD_WHITE);
  for (int i = 0; i < 8; i++) {
    gfx->fillRect(i * (w / 8), 0, w / 8, h / 3, (i & 1) ? GxEPD_BLACK : GxEPD_WHITE);
  }
  gfx->setTextColor(GxEPD_BLACK);
  gfx->setTextSize(2);
  gfx->setCursor(8, h - 24);
  gfx->print("HomeDisplay Test");
}

void epaperShowTestPattern() {
  epaperFullRefresh(drawTestPatternPage);
}
