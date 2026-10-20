#include <Arduino.h>
#include <LittleFS.h>

#include "epaper_driver.h"
#include "hd_client.h"
#include "hd_config.h"
#include "hd_net.h"
#include "hd_serial_menu.h"
#include "hd_ui.h"

static HdDeviceConfig gCfg;

static void onClientRefresh() {
  hdUiRefreshFromDisk();
}

void setup() {
  Serial.begin(115200);
  delay(300);
  Serial.println(F("[hd] boot"));

  hdConfigLoad(gCfg);
  epaperInit(gCfg);
  epaperShowTestPattern();

  hdSerialBootWindow(gCfg, 8000);

  if (!hdConfigValid(gCfg)) {
    epaperShowStatus("Einrichtung", "Serial: m+Enter");
    Serial.println(F("[cfg] Keine gültige Konfiguration – Serial-Menü (0=Assistent) oder Setup-WLAN."));
    if (hdSerialRunMenu(gCfg, false)) {
      ESP.restart();
    }
    epaperShowStatus("Setup-WLAN", hdConfigSoftApSsid().c_str());
    hdNetRunSetupPortal(gCfg);
  }

  epaperShowStatus("WLAN …", gCfg.wifiSsid.c_str());
  hdNetStartStation(gCfg);
  unsigned long start = millis();
  while (!hdNetConnected() && millis() - start < 45000) {
    hdSerialPoll(gCfg);
    delay(250);
  }
  if (!hdNetConnected()) {
    epaperShowStatus("WLAN fehlgeschlagen", "Serial: m+Enter");
    Serial.println(F("[wifi] fehlgeschlagen – 15s für Serial-Menü (m+Enter)."));
    hdSerialBootWindow(gCfg, 15000);
    if (hdSerialRunMenu(gCfg, true)) {
      ESP.restart();
    }
    hdNetRunSetupPortal(gCfg);
  }

  hdUiBegin();
  hdClientBegin(gCfg);
  hdClientSetRefreshCallback(onClientRefresh);
  if (LittleFS.open("/layout.json", "r")) {
    hdUiRefreshFromDisk();
  } else {
    epaperShowStatus("Verbunden", "Warte auf Layout");
  }
}

void loop() {
  hdSerialPoll(gCfg);
  hdClientTick();
  hdUiHandleButton();
  delay(20);
}
