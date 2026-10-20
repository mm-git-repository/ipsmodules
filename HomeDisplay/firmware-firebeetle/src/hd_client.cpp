#include "hd_client.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <LittleFS.h>
#include <Preferences.h>
#include <WiFiClientSecure.h>
#include <esp_random.h>

#include "epaper_driver.h"

#ifndef HOMEDISPLAY_FB_FIRMWARE_VERSION
#define HOMEDISPLAY_FB_FIRMWARE_VERSION "0.1.0"
#endif

static HdDeviceConfig *gCfg = nullptr;
static HdClientRefreshFn refreshFn = nullptr;
static char pairingCode[8];
static bool online = false;
static int stateIntervalSec = 10;
static int lastSeq = -1;
static unsigned long nextManifestMs = 0;
static unsigned long nextStateMs = 0;
static String layoutSha;
static String configSha;
static int manifestFailures = 0;

static bool httpRequest(const char *path, const char *method, const char *body, String &response, bool *is401) {
  if (!gCfg || gCfg->serviceUrl.isEmpty() || !WiFi.isConnected()) return false;
  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  String url = gCfg->serviceUrl + path;
  if (!http.begin(client, url)) return false;
  http.setTimeout(12000);
  if (gCfg->deviceToken.length()) {
    http.addHeader("Authorization", "Bearer " + gCfg->deviceToken);
  }
  int code = 0;
  if (body && strcmp(method, "POST") == 0) {
    http.addHeader("Content-Type", "application/json");
    code = http.POST(body);
  } else if (strcmp(method, "POST") == 0) {
    code = http.POST("");
  } else {
    code = http.GET();
  }
  if (is401) *is401 = (code == 401);
  if (code > 0) response = http.getString();
  http.end();
  return code >= 200 && code < 300;
}

bool hdClientHttpJson(const char *path, const char *method, const char *body, String &out) {
  bool unauthorized = false;
  if (!httpRequest(path, method, body, out, &unauthorized)) return false;
  if (unauthorized) {
    gCfg->deviceToken = "";
    Preferences prefs;
    if (prefs.begin("homedisplay_fb", false)) {
      prefs.putString("token", "");
      prefs.end();
    }
    return false;
  }
  return true;
}

String hdClientGetBinary(const char *path, int *httpCode) {
  String empty;
  if (!gCfg || gCfg->serviceUrl.isEmpty()) return empty;
  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  if (!http.begin(client, gCfg->serviceUrl + path)) return empty;
  if (gCfg->deviceToken.length()) http.addHeader("Authorization", "Bearer " + gCfg->deviceToken);
  int code = http.GET();
  if (httpCode) *httpCode = code;
  String data;
  if (code == 200) data = http.getString();
  http.end();
  return data;
}

static void makePairingCode() {
  static const char *alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  for (int i = 0; i < 6; i++) pairingCode[i] = alphabet[esp_random() % 32];
  pairingCode[6] = '\0';
}

static void pairLoop() {
  makePairingCode();
  char line[64];
  snprintf(line, sizeof(line), "Kopplungscode\n%s", pairingCode);
  epaperShowStatus(line, "In IPS eintragen");
  while (gCfg->deviceToken.isEmpty()) {
    if (!WiFi.isConnected()) {
      delay(2000);
      continue;
    }
    char body[96];
    snprintf(body, sizeof(body), "{\"code\":\"%s\",\"firmware\":\"%s\"}", pairingCode, HOMEDISPLAY_FB_FIRMWARE_VERSION);
    String response;
    if (hdClientHttpJson("/v1/hello", "POST", body, response)) {
      JsonDocument doc;
      if (deserializeJson(doc, response) == DeserializationError::Ok) {
        const char *status = doc["status"];
        const char *token = doc["token"];
        if (status && strcmp(status, "paired") == 0 && token) {
          gCfg->deviceToken = token;
          Preferences prefs;
          if (prefs.begin("homedisplay_fb", false)) {
            prefs.putString("token", gCfg->deviceToken);
            prefs.end();
          }
        }
      }
    }
    delay(3000);
  }
  epaperShowStatus("Verbunden", "Hausanzeige");
}

static bool writeFile(const char *path, const String &content) {
  File f = LittleFS.open(path, "w");
  if (!f) return false;
  size_t n = f.print(content);
  f.close();
  return n == content.length();
}

static String readFile(const char *path) {
  File f = LittleFS.open(path, "r");
  if (!f) return "";
  String s = f.readString();
  f.close();
  return s;
}

static void loadMeta() {
  Preferences prefs;
  if (!prefs.begin("homedisplay_fb", true)) return;
  layoutSha = prefs.getString("layout_sha", "");
  configSha = prefs.getString("config_sha", "");
  prefs.end();
}

static void saveMeta() {
  Preferences prefs;
  if (!prefs.begin("homedisplay_fb", false)) return;
  prefs.putString("layout_sha", layoutSha);
  prefs.putString("config_sha", configSha);
  prefs.end();
}

static void applyConfig(const String &json) {
  JsonDocument doc;
  if (deserializeJson(doc, json) != DeserializationError::Ok) return;
  stateIntervalSec = doc["stateIntervalSec"] | 10;
  if (stateIntervalSec < 5) stateIntervalSec = 5;
}

static void pullManifest() {
  String response;
  if (!hdClientHttpJson("/v1/manifest", "GET", nullptr, response)) {
    manifestFailures++;
    if (manifestFailures >= 3) online = false;
    return;
  }
  manifestFailures = 0;
  online = true;
  JsonDocument doc;
  if (deserializeJson(doc, response) != DeserializationError::Ok) return;
  JsonObject cmd = doc["command"];
  if (!cmd.isNull()) {
    const char *name = cmd["name"];
    if (name && strcmp(name, "restart") == 0) ESP.restart();
    if (name && strcmp(name, "testPattern") == 0) epaperShowTestPattern();
    if (name && strcmp(name, "resync") == 0) {
      layoutSha = "";
      configSha = "";
      lastSeq = -1;
      saveMeta();
    }
  }
  const char *nextConfig = doc["config"]["sha256"];
  const char *nextLayout = doc["layout"]["sha256"];
  if (nextConfig && configSha != nextConfig) {
    String cfgJson;
    if (hdClientHttpJson("/v1/config", "GET", nullptr, cfgJson)) {
      configSha = nextConfig;
      writeFile("/config.json", cfgJson);
      applyConfig(cfgJson);
      saveMeta();
    }
  }
  if (nextLayout && layoutSha != nextLayout) {
    String layoutJson;
    if (hdClientHttpJson("/v1/layout", "GET", nullptr, layoutJson)) {
      layoutSha = nextLayout;
      writeFile("/layout.json", layoutJson);
      saveMeta();
      if (refreshFn) refreshFn();
    }
  }
}

static void pullState() {
  char path[48];
  snprintf(path, sizeof(path), "/v1/state?since=%d", lastSeq);
  String response;
  if (!hdClientHttpJson(path, "GET", nullptr, response)) {
    manifestFailures++;
    return;
  }
  manifestFailures = 0;
  online = true;
  JsonDocument doc;
  if (deserializeJson(doc, response) != DeserializationError::Ok) return;
  if (doc["seq"].is<int>()) lastSeq = doc["seq"].as<int>();
  if (doc["unchanged"] | false) return;
  writeFile("/state.json", response);
  if (refreshFn) refreshFn();
}

static void sendHealth() {
  char body[320];
  snprintf(body, sizeof(body),
           "{\"firmware\":\"%s\",\"rssi\":%d,\"heap\":%u,\"uptime\":%lu,\"dataAge\":0,\"frames\":0,\"failures\":%d}",
           HOMEDISPLAY_FB_FIRMWARE_VERSION, WiFi.RSSI(), ESP.getFreeHeap(), millis() / 1000, manifestFailures);
  String discard;
  hdClientHttpJson("/v1/health", "POST", body, discard);
}

void hdClientBegin(HdDeviceConfig &cfg) {
  gCfg = &cfg;
  if (!LittleFS.begin(true)) {
    epaperShowStatus("LittleFS Fehler");
  }
  loadMeta();
  String cachedCfg = readFile("/config.json");
  if (cachedCfg.length()) applyConfig(cachedCfg);
  if (cfg.deviceToken.isEmpty()) pairLoop();
  nextManifestMs = 0;
  nextStateMs = 0;
}

void hdClientSetRefreshCallback(HdClientRefreshFn fn) {
  refreshFn = fn;
}

void hdClientTick() {
  if (!gCfg) return;
  if (gCfg->deviceToken.isEmpty()) pairLoop();
  unsigned long now = millis();
  if (now >= nextManifestMs) {
    pullManifest();
    sendHealth();
    nextManifestMs = now + 900000UL;
  }
  if (now >= nextStateMs) {
    pullState();
    nextStateMs = now + static_cast<unsigned long>(stateIntervalSec) * 1000UL;
  }
}

const char *hdClientPairingCode() {
  return pairingCode;
}

bool hdClientOnline() {
  return online;
}

int hdClientStateIntervalSec() {
  return stateIntervalSec;
}

const String &hdClientBaseUrl() {
  static String empty;
  return gCfg ? gCfg->serviceUrl : empty;
}

const String &hdClientToken() {
  static String empty;
  return gCfg ? gCfg->deviceToken : empty;
}

void hdClientPostAction(const char *tileId, const char *actionId, int value) {
  char body[200];
  snprintf(body, sizeof(body),
           "{\"tileId\":\"%s\",\"actionId\":\"%s\",\"value\":%d,\"requestId\":\"%08lx\"}",
           tileId, actionId, value, static_cast<unsigned long>(esp_random()));
  String discard;
  hdClientHttpJson("/v1/actions", "POST", body, discard);
}

void hdClientTriggerResync() {
  layoutSha = "";
  lastSeq = -1;
  saveMeta();
}
