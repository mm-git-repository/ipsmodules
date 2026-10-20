#pragma once
#include "device_types.h"
#include <Adafruit_GFX.h>

void epaperInit(const HdDeviceConfig &cfg);
uint16_t epaperWidth();
uint16_t epaperHeight();
Adafruit_GFX *epaperGfx();
void epaperFullRefresh(void (*draw)(Adafruit_GFX *gfx));
void epaperShowStatus(const char *line1, const char *line2 = nullptr);
void epaperShowTestPattern();
