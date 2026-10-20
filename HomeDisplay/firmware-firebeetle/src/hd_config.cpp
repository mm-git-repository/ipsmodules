#include "hd_config.h"

#include <Preferences.h>
#include <WiFi.h>

static const char *NS = "homedisplay_fb";
static const uint32_t CONFIG_MAGIC = 0x48444642u; // 'HDFB'

static bool prefsOpen(Preferences &prefs, bool readOnly) {
  if (prefs.begin(NS, readOnly)) return true;
  return prefs.begin(NS, !readOnly);
}

static String hostFromServiceUrl(const String &url) {
  int scheme = url.indexOf("://");
  if (scheme < 0) return url;
  int start = scheme + 3;
  int end = url.indexOf(':', start);
  if (end < 0) end = url.indexOf('/', start);
  if (end < 0) end = url.length();
  return url.substring(start, end);
}

static bool isPrivateIpv4Host(const String &host) {
  if (host.startsWith("192.168.") || host.startsWith("10.")) return true;
  if (!host.startsWith("172.")) return false;
  const int dot = host.indexOf('.', 4);
  const String second = dot > 0 ? host.substring(4, dot) : host.substring(4);
  const int oct = second.toInt();
  return oct >= 16 && oct <= 31;
}

/** Ohne Schema → http://. https:// + lokale IP → http:// (IPS ohne SSL). */
static String normalizeServiceUrl(String url) {
  url.trim();
  while (url.endsWith("/")) url.remove(url.length() - 1);
  if (url.isEmpty()) return url;
  if (!url.startsWith("http://") && !url.startsWith("https://")) {
    url = "http://" + url;
  }
  if (url.startsWith("https://") && isPrivateIpv4Host(hostFromServiceUrl(url))) {
    url = "http://" + url.substring(8);
  }
  return url;
}

bool hdConfigLoad(HdDeviceConfig &cfg) {
  Preferences prefs;
  if (!prefsOpen(prefs, true)) {
    Serial.println(F("[cfg] NVS open (read) fehlgeschlagen"));
    return false;
  }
  const uint32_t magic = prefs.getUInt("magic", 0);
  cfg.wifiSsid = prefs.getString("ssid", "");
  cfg.wifiPass = prefs.getString("pass", "");
  const String rawBase = prefs.getString("base", "");
  cfg.serviceUrl = normalizeServiceUrl(rawBase);
  cfg.deviceToken = prefs.getString("token", "");
  cfg.displayModel = static_cast<DisplayModel>(prefs.getUChar("dmodel", 0));
  cfg.displayColor = static_cast<DisplayColor>(prefs.getUChar("dcolor", 0));
  cfg.displayFastRefresh = prefs.getBool("dfast", true);
  if (cfg.serviceUrl != rawBase && !cfg.serviceUrl.isEmpty()) {
    prefs.end();
    if (prefsOpen(prefs, false)) {
      prefs.putString("base", cfg.serviceUrl);
      prefs.end();
      Serial.printf("[cfg] Dienstadresse angepasst: %s\n", cfg.serviceUrl.c_str());
    }
  } else {
    prefs.end();
  }

#ifdef HOMEDISPLAY_FB_DEFAULT_DISPLAY_042
  cfg.displayModel = DisplayModel::GDEY042_400x300;
  cfg.displayColor = DisplayColor::GREY;
#endif

  Serial.printf("[cfg] geladen: magic=%s ssid_len=%u base_len=%u\n",
                magic == CONFIG_MAGIC ? "ok" : "nein",
                static_cast<unsigned>(cfg.wifiSsid.length()),
                static_cast<unsigned>(cfg.serviceUrl.length()));
  return true;
}

bool hdConfigSave(const HdDeviceConfig &cfg) {
  Preferences prefs;
  if (!prefsOpen(prefs, false)) {
    Serial.println(F("[cfg] NVS open (write) fehlgeschlagen"));
    return false;
  }
  const String base = normalizeServiceUrl(cfg.serviceUrl);
  prefs.putUInt("magic", CONFIG_MAGIC);
  prefs.putString("ssid", cfg.wifiSsid);
  prefs.putString("pass", cfg.wifiPass);
  prefs.putString("base", base);
  prefs.putString("token", cfg.deviceToken);
  prefs.putUChar("dmodel", static_cast<uint8_t>(cfg.displayModel));
  prefs.putUChar("dcolor", static_cast<uint8_t>(cfg.displayColor));
  prefs.putBool("dfast", cfg.displayFastRefresh);
  prefs.end();

  Preferences verify;
  if (!prefsOpen(verify, true)) return false;
  const bool ok = verify.getUInt("magic", 0) == CONFIG_MAGIC &&
                  verify.getString("ssid", "") == cfg.wifiSsid &&
                  verify.getString("base", "") == base;
  verify.end();
  if (!ok) {
    Serial.println(F("[cfg] NVS Verify fehlgeschlagen"));
    return false;
  }
  Serial.println(F("[cfg] NVS gespeichert und verifiziert"));
  return true;
}

bool hdConfigValid(const HdDeviceConfig &cfg) {
  return cfg.wifiSsid.length() > 0 && cfg.serviceUrl.length() > 0;
}

bool hdConfigFactoryReset() {
  Preferences prefs;
  if (!prefsOpen(prefs, false)) return false;
  prefs.clear();
  prefs.end();
  return true;
}

void hdConfigClearDeviceToken(HdDeviceConfig &cfg) {
  cfg.deviceToken = "";
  Preferences prefs;
  if (prefsOpen(prefs, false)) {
    prefs.putString("token", "");
    prefs.remove("pair_code");
    prefs.remove("layout_sha");
    prefs.remove("config_sha");
    prefs.end();
  }
}

static const char *displayLabel(DisplayModel m) {
  return m == DisplayModel::GDEY075_800x480 ? "7.5\" (800x480)" : "4.2\" (400x300)";
}

static const char *colorLabel(DisplayColor c) {
  return c == DisplayColor::RED ? "S/W/R (3C)" : "S/W";
}

void hdConfigPrintSummary(const HdDeviceConfig &cfg) {
  Serial.println(F("-- Konfiguration (NVS) --"));
  Serial.printf("  Dienstadresse: %s\n", cfg.serviceUrl.c_str());
  Serial.printf("  WLAN-SSID: %s\n", cfg.wifiSsid.c_str());
  Serial.println(F("  WLAN-Passwort: ***"));
  Serial.printf("  Display: %s\n", displayLabel(cfg.displayModel));
  Serial.printf("  Farbe: %s\n", colorLabel(cfg.displayColor));
  Serial.printf("  Kopplung: %s\n", cfg.deviceToken.length() ? "Token gesetzt" : "offen (Code anzeigen)");
}

String hdConfigSoftApSsid() {
  uint8_t mac[6];
  WiFi.macAddress(mac);
  char buf[32];
  snprintf(buf, sizeof(buf), "Hausanzeige-Setup-%02X%02X", mac[4], mac[5]);
  return String(buf);
}
