#pragma once
#include "device_types.h"

void hdNetStartStation(const HdDeviceConfig &cfg);
bool hdNetConnected();
void hdNetRunSetupPortal(HdDeviceConfig &cfg);
