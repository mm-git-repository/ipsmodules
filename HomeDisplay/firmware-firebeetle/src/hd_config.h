#pragma once
#include "device_types.h"

bool hdConfigLoad(HdDeviceConfig &cfg);
bool hdConfigSave(const HdDeviceConfig &cfg);
bool hdConfigValid(const HdDeviceConfig &cfg);
bool hdConfigFactoryReset();
void hdConfigClearDeviceToken(HdDeviceConfig &cfg);
void hdConfigPrintSummary(const HdDeviceConfig &cfg);
String hdConfigSoftApSsid();
