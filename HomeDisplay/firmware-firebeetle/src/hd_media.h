#pragma once
#include <Adafruit_GFX.h>
#include <ArduinoJson.h>

void hdMediaBegin();
void hdMediaSyncFromState(JsonArrayConst stateTiles);
void hdMediaEnsureLayoutImages(JsonDocument &layout);
void hdMediaDrawTile(Adafruit_GFX *gfx, JsonObject tile, JsonObjectConst stateTile, int16_t x, int16_t y, int16_t w, int16_t h);
