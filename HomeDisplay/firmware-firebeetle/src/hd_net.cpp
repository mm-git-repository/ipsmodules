#include "hd_net.h"

#include <WebServer.h>
#include <WiFi.h>

#include "epaper_driver.h"
#include "hd_config.h"

static bool wifiUp = false;

static void onWifiEvent(WiFiEvent_t event) {
  if (event == ARDUINO_EVENT_WIFI_STA_GOT_IP) wifiUp = true;
  if (event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED) wifiUp = false;
}

void hdNetStartStation(const HdDeviceConfig &cfg) {
  WiFi.onEvent(onWifiEvent);
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(cfg.wifiSsid.c_str(), cfg.wifiPass.c_str());
}

bool hdNetConnected() {
  return wifiUp && WiFi.status() == WL_CONNECTED;
}

static String urlDecode(const String &src) {
  String out;
  out.reserve(src.length());
  for (size_t i = 0; i < src.length(); i++) {
    if (src[i] == '+') out += ' ';
    else if (src[i] == '%' && i + 2 < src.length()) {
      char hex[3] = {src[i + 1], src[i + 2], 0};
      out += static_cast<char>(strtol(hex, nullptr, 16));
      i += 2;
    } else out += src[i];
  }
  return out;
}

void hdNetRunSetupPortal(HdDeviceConfig &cfg) {
  const String apSsid = hdConfigSoftApSsid();
  epaperShowStatus("Setup-WLAN", apSsid.c_str());
  WiFi.mode(WIFI_AP);
  WiFi.softAP(apSsid.c_str(), "hausanzeige");
  WebServer server(80);
  server.on("/", HTTP_GET, [&]() {
    server.send(200, "text/html",
                "<!doctype html><html lang=de><meta charset=utf-8><meta name=viewport content=\"width=device-width\">"
                "<title>Hausanzeige</title><style>body{font-family:sans-serif;max-width:420px;margin:2em auto;padding:0 1em}"
                "label{display:block;margin-top:1em}input{width:100%;padding:.5em}button{margin-top:1.5em;padding:.7em 1.2em}"
                "</style><h1>Hausanzeige einrichten</h1><form method=post action=/save>"
                "<label>WLAN<input name=ssid required maxlength=32></label>"
                "<label>Passwort<input name=password type=password maxlength=63></label>"
                "<label>Dienstadresse<input name=adapter placeholder=http://192.168.1.10:3777/hook/homedisplay maxlength=150></label>"
                "<p>Schema wie in IPS: SSL aus → <code>http://</code>, SSL an → <code>https://</code></p>"
                "<label>Display<select name=dmodel><option value=0>4.2 400x300</option><option value=1>7.5 800x480</option></select></label>"
                "<label>Farbe<select name=dcolor><option value=0>S/W</option><option value=1>S/W/R</option></select></label>"
                "<button>Speichern</button></form>");
  });
  server.on("/save", HTTP_POST, [&]() {
    cfg.wifiSsid = urlDecode(server.arg("ssid"));
    cfg.wifiPass = urlDecode(server.arg("password"));
    cfg.serviceUrl = urlDecode(server.arg("adapter"));
    cfg.displayModel = server.arg("dmodel") == "1" ? DisplayModel::GDEY075_800x480 : DisplayModel::GDEY042_400x300;
    cfg.displayColor = server.arg("dcolor") == "1" ? DisplayColor::RED : DisplayColor::GREY;
    hdConfigSave(cfg);
    server.send(200, "text/html", "<html><body>Gespeichert. Neustart …</body></html>");
    delay(800);
    ESP.restart();
  });
  server.begin();
  while (true) {
    server.handleClient();
    delay(10);
  }
}
