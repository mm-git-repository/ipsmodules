#pragma once
#include <Arduino.h>
#include <stdint.h>

enum class DisplayModel : uint8_t {
  GDEY042_400x300 = 0,
  GDEY075_800x480 = 1,
};

enum class DisplayColor : uint8_t {
  GREY = 0,
  RED = 1,
};

struct HdDeviceConfig {
  String wifiSsid;
  String wifiPass;
  String serviceUrl;
  String deviceToken;
  DisplayModel displayModel = DisplayModel::GDEY042_400x300;
  DisplayColor displayColor = DisplayColor::GREY;
  bool displayFastRefresh = true;
};
