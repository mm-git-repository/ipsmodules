#pragma once
#include "device_types.h"
#include <Adafruit_GFX.h>

void epaperInit(const HdDeviceConfig &cfg);
uint16_t epaperWidth();
uint16_t epaperHeight();
Adafruit_GFX *epaperGfx();
bool epaperPartialRefreshAvailable();
int epaperPartialsSinceFull();
void epaperFullRefresh(void (*draw)(Adafruit_GFX *gfx));
void epaperPartialRefresh(int16_t x, int16_t y, int16_t w, int16_t h, void (*draw)(Adafruit_GFX *gfx));
void epaperDrawMonoFull(const uint8_t *mono, int16_t w, int16_t h);
void epaperDrawMonoRegion(const uint8_t *mono, int16_t screenW, int16_t screenH, int16_t x, int16_t y, int16_t w, int16_t h);
void epaperShowStatus(const char *line1, const char *line2 = nullptr);
void epaperShowTestPattern();
