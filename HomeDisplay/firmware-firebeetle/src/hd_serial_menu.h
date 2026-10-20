#pragma once
#include "device_types.h"

/** Blockierendes Menü; @return true → Neustart ausführen. */
bool hdSerialRunMenu(HdDeviceConfig &cfg, bool allowCancel);

/** Nach dem Boot: ms lang „m“+Enter abwarten. */
void hdSerialBootWindow(HdDeviceConfig &cfg, unsigned long ms);

/** In loop(): erkennt „m“+Enter und öffnet das Menü (blockierend). */
void hdSerialPoll(HdDeviceConfig &cfg);
