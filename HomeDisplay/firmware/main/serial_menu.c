#include "serial_menu.h"

#include "homedisplay.h"

#include "esp_spiffs.h"
#include "esp_system.h"
#include "esp_timer.h"
#include "nvs.h"

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"

#include <stdio.h>
#include <string.h>
#include <strings.h>

static void trim_trailing_slashes(char *url)
{
    size_t length = strlen(url);
    while (length > 0 && url[length - 1] == '/') {
        url[--length] = '\0';
    }
}

static int read_char(void)
{
    int c = getchar();
    if (c == EOF) {
        return -1;
    }
    return c;
}

static void read_line(char *line, size_t length)
{
    size_t index = 0;
    line[0] = '\0';
    while (index + 1 < length) {
        int c = read_char();
        if (c < 0) {
            vTaskDelay(pdMS_TO_TICKS(10));
            continue;
        }
        if (c == '\r') {
            continue;
        }
        if (c == '\n') {
            break;
        }
        line[index++] = (char) c;
    }
    line[index] = '\0';
    while (index > 0 && (line[index - 1] == ' ' || line[index - 1] == '\t')) {
        line[--index] = '\0';
    }
}

static void flush_input(void)
{
    while (read_char() >= 0) {
    }
}

static void print_header(void)
{
    printf("\n========== Hausanzeige – Serial-Menü (115200) ==========\n");
    printf("Zeile eingeben + Enter. Passwort leer = offenes WLAN.\n");
    printf("Pflicht zum Speichern: WLAN-Name (Menü 2 oder Assistent 0).\n");
}

static bool config_valid(const char *ssid)
{
    return ssid && ssid[0] != '\0';
}

static void print_summary(void)
{
    char ssid[33];
    char pass[65];
    char base[160];
    char token[40];
    homedisplay_nvs_get("ssid", ssid, sizeof(ssid));
    homedisplay_nvs_get("pass", pass, sizeof(pass));
    homedisplay_nvs_get("base", base, sizeof(base));
    homedisplay_nvs_get("token", token, sizeof(token));
    printf("\n--- Aktuelle Werte ---\n");
    printf("  Dienstadresse: %s\n", base[0] ? base : "(automatisch per mDNS)");
    printf("  WLAN-Name:     %s\n", ssid[0] ? ssid : "(leer)");
    printf("  WLAN-Passwort: %s\n", pass[0] ? "(gesetzt)" : "(leer)");
    printf("  Kopplung:      %s\n", token[0] ? "(Token gesetzt)" : "(noch nicht gekoppelt)");
}

static bool save_and_verify(void)
{
    char ssid[33];
    homedisplay_nvs_get("ssid", ssid, sizeof(ssid));
    if (!config_valid(ssid)) {
        printf("[cfg] Unvollständig – bitte WLAN-Name setzen (Menü 2 oder Assistent 0).\n");
        return false;
    }
    char check[33];
    homedisplay_nvs_get("ssid", check, sizeof(check));
    if (!config_valid(check)) {
        printf("[cfg] Nach dem Speichern ungültig – NVS prüfen.\n");
        return false;
    }
    return true;
}

static void prompt_base(void)
{
    char base[160];
    homedisplay_nvs_get("base", base, sizeof(base));
    printf("\nDienstadresse (Hook-URL, ohne abschließenden Slash):\n");
    printf("  z.B. http://192.168.1.10:3777/hook/homedisplay\n");
    printf("  Aktuell: %s\n> ", base[0] ? base : "(leer = mDNS homedisplay.local)");
    char line[180];
    read_line(line, sizeof(line));
    if (line[0]) {
        trim_trailing_slashes(line);
        homedisplay_nvs_set("base", line);
    }
}

static void run_wizard(void)
{
    printf("\n--- Einrichtungs-Assistent ---\n");
    prompt_base();
    printf("\nWLAN-Name:\n> ");
    char line[80];
    read_line(line, sizeof(line));
    homedisplay_nvs_set("ssid", line);
    printf("\nWLAN-Passwort:\n> ");
    read_line(line, sizeof(line));
    homedisplay_nvs_set("pass", line);
    save_and_verify();
}

static void factory_reset(void)
{
    printf("\n[!] Factory Reset: NVS-Konfiguration und Bild-Cache werden gelöscht.\n");
    printf("    Zur Bestätigung RESET eingeben:\n> ");
    char line[32];
    read_line(line, sizeof(line));
    if (strcmp(line, "RESET") != 0) {
        printf("Abgebrochen.\n");
        return;
    }
    nvs_handle_t handle;
    if (nvs_open("homedisplay", NVS_READWRITE, &handle) == ESP_OK) {
        nvs_erase_all(handle);
        nvs_commit(handle);
        nvs_close(handle);
    }
    if (esp_spiffs_format("storage") == ESP_OK) {
        printf("[cfg] Bildspeicher formatiert.\n");
    }
    printf("[cfg] Werkseinstellungen gelöscht – Neustart …\n");
    vTaskDelay(pdMS_TO_TICKS(500));
    esp_restart();
}

static void poll_line_buffer(char *buffer, size_t length, bool *restart)
{
    while (true) {
        int c = read_char();
        if (c < 0) {
            break;
        }
        if (c == '\n') {
            size_t index = strlen(buffer);
            while (index > 0 && (buffer[index - 1] == ' ' || buffer[index - 1] == '\t' || buffer[index - 1] == '\r')) {
                buffer[--index] = '\0';
            }
            if (strcasecmp(buffer, "m") == 0) {
                if (homedisplay_serial_run_menu(true)) {
                    *restart = true;
                }
            }
            buffer[0] = '\0';
            break;
        }
        if (c != '\r') {
            size_t index = strlen(buffer);
            if (index + 1 < length) {
                buffer[index] = (char) c;
                buffer[index + 1] = '\0';
            }
        }
    }
}

bool homedisplay_serial_run_menu(bool allow_cancel)
{
    flush_input();
    print_header();
    homedisplay_ui_status("Serial-Menü\n115200 · Menü aktiv");
    for (;;) {
        print_summary();
        printf("\n--- Menü ---\n");
        printf("  1  Dienstadresse (Hook-URL)\n");
        printf("  2  WLAN-Name\n");
        printf("  3  WLAN-Passwort\n");
        printf("  k  Kopplung löschen (Device-Token)\n");
        printf("  0  Assistent (WLAN + optional Dienst)\n");
        printf("  f  Factory Reset (NVS + Cache)\n");
        if (allow_cancel) {
            printf("  x  Menü verlassen (ohne Neustart)\n");
        }
        printf("  s  Speichern & Neustart\n");
        printf("\nWahl: ");
        char choice[16];
        read_line(choice, sizeof(choice));
        if (allow_cancel && (choice[0] == 'x' || choice[0] == 'X')) {
            homedisplay_ui_status("Weiter starten");
            return false;
        }
        if (strcmp(choice, "1") == 0) {
            prompt_base();
        } else if (strcmp(choice, "2") == 0) {
            printf("\nWLAN-Name:\n> ");
            char line[40];
            read_line(line, sizeof(line));
            homedisplay_nvs_set("ssid", line);
        } else if (strcmp(choice, "3") == 0) {
            printf("\nWLAN-Passwort:\n> ");
            char line[80];
            read_line(line, sizeof(line));
            homedisplay_nvs_set("pass", line);
        } else if (strcasecmp(choice, "k") == 0) {
            homedisplay_nvs_set("token", "");
            printf("[cfg] Token gelöscht – nach Neustart neuer Kopplungscode.\n");
        } else if (strcmp(choice, "0") == 0) {
            run_wizard();
        } else if (strcasecmp(choice, "f") == 0) {
            factory_reset();
        } else if (strcasecmp(choice, "s") == 0) {
            if (save_and_verify()) {
                printf("[cfg] Neustart in 1 s …\n");
                fflush(stdout);
                vTaskDelay(pdMS_TO_TICKS(1000));
                return true;
            }
        } else {
            printf("Unbekannte Eingabe.\n");
        }
    }
}

void homedisplay_serial_boot_window(uint32_t ms)
{
    printf("[cfg] Serial-Menü: innerhalb %lu s \"m\" + Enter drücken.\n", (unsigned long) (ms / 1000));
    char hint[48];
    snprintf(hint, sizeof(hint), "Serial-Menü\nm + Enter (%lu s)", (unsigned long) (ms / 1000));
    homedisplay_ui_status(hint);
    char buffer[8] = {0};
    bool restart = false;
    int64_t deadline = esp_timer_get_time() + (int64_t) ms * 1000;
    while (esp_timer_get_time() < deadline) {
        poll_line_buffer(buffer, sizeof(buffer), &restart);
        if (restart) {
            esp_restart();
        }
        vTaskDelay(pdMS_TO_TICKS(15));
    }
}

void homedisplay_serial_poll(void)
{
    static char buffer[8] = {0};
    bool restart = false;
    poll_line_buffer(buffer, sizeof(buffer), &restart);
    if (restart) {
        esp_restart();
    }
}
