#include "hd_serial_menu.h"

#include <LittleFS.h>

#include "hd_config.h"

static String readLine() {
  String line;
  for (;;) {
    while (!Serial.available()) delay(10);
    int c = Serial.read();
    if (c == '\n') break;
    if (c != '\r') line += static_cast<char>(c);
  }
  line.trim();
  return line;
}

static String trimSlashes(String s) {
  s.trim();
  while (s.endsWith("/")) s.remove(s.length() - 1);
  return s;
}

static void printHeader() {
  Serial.println();
  Serial.println(F("========== Hausanzeige – Serial-Menü (115200) =========="));
  Serial.println(F("Zeile eingeben + Enter. Passwort leer = offenes WLAN."));
  Serial.println(F("Pflicht für Speichern: WLAN-SSID und Dienstadresse (Menü 1+2 oder Assistent 0)."));
}

static bool saveAndVerify(HdDeviceConfig &cfg) {
  if (!hdConfigValid(cfg)) {
    Serial.println(F("[cfg] Unvollständig – bitte Dienstadresse (1) und WLAN-SSID (2) setzen."));
    return false;
  }
  if (!hdConfigSave(cfg)) {
    Serial.println(F("[cfg] Speichern fehlgeschlagen (NVS)."));
    return false;
  }
  HdDeviceConfig check;
  hdConfigLoad(check);
  if (!hdConfigValid(check)) {
    Serial.println(F("[cfg] Nach dem Speichern ungültig – NVS prüfen."));
    return false;
  }
  cfg = check;
  return true;
}

static void promptServiceUrl(HdDeviceConfig &cfg) {
  Serial.println(F("\nDienstadresse (Hook-URL, ohne abschließenden Slash):"));
  Serial.println(F("  z.B. http://192.168.1.10:3777/hook/homedisplay (SSL in IPS aus)"));
  Serial.println(F("       oder https://… (nur wenn WebServer::SSL in IPS aktiv ist)"));
  Serial.printf("  Aktuell: %s\n", cfg.serviceUrl.c_str());
  Serial.print(F("> "));
  String v = readLine();
  if (v.length()) cfg.serviceUrl = trimSlashes(v);
}

static void promptDisplay(HdDeviceConfig &cfg) {
  Serial.println(F("\nDisplay:"));
  Serial.println(F("  1 = 4.2\" 400x300"));
  Serial.println(F("  2 = 7.5\" 800x480"));
  Serial.print(F("Wahl [1-2, Enter=behalten]: "));
  String a = readLine();
  if (a == "2") cfg.displayModel = DisplayModel::GDEY075_800x480;
  else if (a == "1") cfg.displayModel = DisplayModel::GDEY042_400x300;
}

static void promptColor(HdDeviceConfig &cfg) {
  Serial.println(F("\nFarbe:"));
  Serial.println(F("  1 = S/W"));
  Serial.println(F("  2 = S/W/R (3C)"));
  Serial.print(F("Wahl [1-2, Enter=behalten]: "));
  String a = readLine();
  if (a == "2") cfg.displayColor = DisplayColor::RED;
  else if (a == "1") cfg.displayColor = DisplayColor::GREY;
}

static void runWizard(HdDeviceConfig &cfg) {
  Serial.println(F("\n--- Einrichtungs-Assistent ---"));
  promptServiceUrl(cfg);
  Serial.println(F("\nWLAN-SSID:"));
  Serial.print(F("> "));
  cfg.wifiSsid = readLine();
  Serial.println(F("\nWLAN-Passwort:"));
  Serial.print(F("> "));
  cfg.wifiPass = readLine();
  promptDisplay(cfg);
  promptColor(cfg);
  saveAndVerify(cfg);
}

static void factoryResetPrompt() {
  Serial.println(F("\n[!] Factory Reset: NVS + Layout-Cache (LittleFS) werden gelöscht."));
  Serial.println(F("    Zur Bestätigung RESET eingeben:"));
  Serial.print(F("> "));
  if (readLine() != "RESET") {
    Serial.println(F("Abgebrochen."));
    return;
  }
  hdConfigFactoryReset();
  if (LittleFS.begin(false)) {
    LittleFS.format();
    Serial.println(F("[cfg] LittleFS formatiert."));
  }
  Serial.println(F("[cfg] Werkseinstellungen gelöscht – Neustart …"));
  delay(500);
  ESP.restart();
}

bool hdSerialRunMenu(HdDeviceConfig &cfg, bool allowCancel) {
  while (Serial.available()) Serial.read();
  printHeader();
  for (;;) {
    hdConfigPrintSummary(cfg);
    Serial.println(F("\n--- Menü ---"));
    Serial.println(F("  1  Dienstadresse (Hook-URL)"));
    Serial.println(F("  2  WLAN-SSID"));
    Serial.println(F("  3  WLAN-Passwort"));
    Serial.println(F("  4  Displaygröße"));
    Serial.println(F("  5  Display-Farbe"));
    Serial.println(F("  k  Kopplung löschen (Device-Token)"));
    Serial.println(F("  0  Assistent (alle Pflichtfelder)"));
    Serial.println(F("  f  Factory Reset (NVS + Cache)"));
    if (allowCancel) Serial.println(F("  x  Menü verlassen (ohne Neustart)"));
    Serial.println(F("  s  Speichern & Neustart"));
    Serial.print(F("\nWahl: "));

    String choice = readLine();
    if (allowCancel && (choice == "x" || choice == "X")) return false;

    if (choice == "1") {
      promptServiceUrl(cfg);
    } else if (choice == "2") {
      Serial.println(F("\nWLAN-SSID:"));
      Serial.print(F("> "));
      cfg.wifiSsid = readLine();
    } else if (choice == "3") {
      Serial.println(F("\nWLAN-Passwort:"));
      Serial.print(F("> "));
      cfg.wifiPass = readLine();
    } else if (choice == "4") {
      promptDisplay(cfg);
    } else if (choice == "5") {
      promptColor(cfg);
    } else if (choice == "k" || choice == "K") {
      hdConfigClearDeviceToken(cfg);
      Serial.println(F("[cfg] Token gelöscht – nach Neustart neuer Kopplungscode."));
    } else if (choice == "0") {
      runWizard(cfg);
    } else if (choice == "f" || choice == "F") {
      factoryResetPrompt();
    } else if (choice == "s" || choice == "S") {
      if (saveAndVerify(cfg)) {
        Serial.println(F("[cfg] Neustart in 1 s …"));
        Serial.flush();
        delay(1000);
        return true;
      }
    } else {
      Serial.println(F("Unbekannte Eingabe."));
    }
  }
}

static void pollLineBuffer(HdDeviceConfig &cfg, String &buf) {
  while (Serial.available()) {
    int c = Serial.read();
    if (c == '\n') {
      buf.trim();
      if (buf.equalsIgnoreCase("m")) {
        if (hdSerialRunMenu(cfg, true)) ESP.restart();
      }
      buf = "";
    } else if (c != '\r') {
      buf += static_cast<char>(c);
    }
  }
}

void hdSerialBootWindow(HdDeviceConfig &cfg, unsigned long ms) {
  Serial.println(F("[cfg] Serial-Menü: innerhalb 8s \"m\" + Enter drücken."));
  String buf;
  unsigned long t0 = millis();
  while (millis() - t0 < ms) {
    pollLineBuffer(cfg, buf);
    delay(15);
  }
}

void hdSerialPoll(HdDeviceConfig &cfg) {
  static String buf;
  pollLineBuffer(cfg, buf);
}
