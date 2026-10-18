#include "homedisplay.h"

#include "esp_event.h"
#include "esp_http_server.h"
#include "esp_log.h"
#include "esp_mac.h"
#include "esp_netif.h"
#include "esp_timer.h"
#include "esp_wifi.h"
#include "nvs.h"

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"

#include <stdio.h>
#include <string.h>

static const char *TAG = "net";
static bool connected;
static int retry_delay = 2;
static int64_t disconnected_at;
static esp_timer_handle_t retry_timer;
static httpd_handle_t provisioning_server;

void homedisplay_nvs_set(const char *key, const char *value)
{
    nvs_handle_t handle;
    if (nvs_open("homedisplay", NVS_READWRITE, &handle) != ESP_OK) return;
    nvs_set_str(handle, key, value ? value : "");
    nvs_commit(handle);
    nvs_close(handle);
}

void homedisplay_nvs_get(const char *key, char *value, size_t length)
{
    nvs_handle_t handle;
    value[0] = '\0';
    if (nvs_open("homedisplay", NVS_READONLY, &handle) != ESP_OK) return;
    size_t size = length;
    if (nvs_get_str(handle, key, value, &size) != ESP_OK) value[0] = '\0';
    nvs_close(handle);
}

static void retry_connect(void *argument)
{
    (void) argument;
    ESP_LOGI(TAG, "WLAN neu verbinden");
    esp_wifi_connect();
}

/* Exponentieller Rückzug 2 s bis 60 s; nach fünf Minuten ohne WLAN startet der Treiber neu. */
static void wifi_event(void *argument, esp_event_base_t base, int32_t id, void *data)
{
    (void) argument;
    (void) data;
    if (base == WIFI_EVENT && id == WIFI_EVENT_STA_START) {
        esp_wifi_connect();
        return;
    }
    if (base == WIFI_EVENT && id == WIFI_EVENT_STA_DISCONNECTED) {
        if (connected) disconnected_at = esp_timer_get_time();
        connected = false;
        if (disconnected_at && esp_timer_get_time() - disconnected_at > 300000000) {
            ESP_LOGW(TAG, "Fünf Minuten ohne WLAN, Treiber wird neu gestartet");
            esp_wifi_stop();
            esp_wifi_start();
            disconnected_at = esp_timer_get_time();
            retry_delay = 2;
            return;
        }
        esp_timer_start_once(retry_timer, (uint64_t) retry_delay * 1000000);
        retry_delay = retry_delay >= 60 ? 60 : retry_delay * 2;
        return;
    }
    if (base == IP_EVENT && id == IP_EVENT_STA_GOT_IP) {
        connected = true;
        retry_delay = 2;
        disconnected_at = 0;
        ESP_LOGI(TAG, "WLAN verbunden");
    }
}

void homedisplay_wifi_start(const char *ssid, const char *password)
{
    esp_netif_init();
    esp_event_loop_create_default();
    esp_netif_create_default_wifi_sta();
    wifi_init_config_t config = WIFI_INIT_CONFIG_DEFAULT();
    esp_wifi_init(&config);
    const esp_timer_create_args_t timer_args = {.callback = retry_connect, .name = "wifi-retry"};
    esp_timer_create(&timer_args, &retry_timer);
    esp_event_handler_register(WIFI_EVENT, ESP_EVENT_ANY_ID, wifi_event, NULL);
    esp_event_handler_register(IP_EVENT, IP_EVENT_STA_GOT_IP, wifi_event, NULL);
    wifi_config_t wifi = {0};
    strncpy((char *) wifi.sta.ssid, ssid, sizeof(wifi.sta.ssid) - 1);
    strncpy((char *) wifi.sta.password, password, sizeof(wifi.sta.password) - 1);
    wifi.sta.pmf_cfg.capable = true;
    esp_wifi_set_mode(WIFI_MODE_STA);
    esp_wifi_set_ps(WIFI_PS_NONE);
    esp_wifi_set_config(WIFI_IF_STA, &wifi);
    esp_wifi_start();
}

bool homedisplay_wifi_connected(void)
{
    return connected;
}

static const char provisioning_page[] =
    "<!doctype html><html lang=\"de\"><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\">"
    "<title>HomeDisplay einrichten</title>"
    "<style>body{font-family:sans-serif;max-width:420px;margin:2em auto;padding:0 1em}label{display:block;margin-top:1em}"
    "input{width:100%;padding:.5em;font-size:1em}button{margin-top:1.5em;padding:.7em 1.2em;font-size:1em}</style>"
    "<h1>HomeDisplay einrichten</h1><form method=\"post\" action=\"/save\">"
    "<label>WLAN-Name<input name=\"ssid\" required maxlength=\"32\"></label>"
    "<label>WLAN-Passwort<input name=\"password\" type=\"password\" maxlength=\"63\"></label>"
    "<label>Adapter-URL (leer = automatisch suchen)<input name=\"adapter\" placeholder=\"https://192.168.1.10:3777/hook/homedisplay\" maxlength=\"150\"></label>"
    "<button type=\"submit\">Speichern und neu starten</button></form></html>";

static void url_decode(char *text)
{
    char *read = text;
    char *write = text;
    while (*read) {
        if (*read == '+') {
            *write++ = ' ';
            read++;
        } else if (*read == '%' && read[1] && read[2]) {
            char hex[3] = {read[1], read[2], 0};
            *write++ = (char) strtol(hex, NULL, 16);
            read += 3;
        } else {
            *write++ = *read++;
        }
    }
    *write = '\0';
}

static void form_field(const char *body, const char *name, char *value, size_t length)
{
    value[0] = '\0';
    char key[40];
    snprintf(key, sizeof(key), "%s=", name);
    const char *start = strstr(body, key);
    if (!start) return;
    if (start != body && start[-1] != '&') {
        start = strstr(start + 1, key);
        if (!start) return;
    }
    start += strlen(key);
    const char *end = strchr(start, '&');
    size_t size = end ? (size_t) (end - start) : strlen(start);
    if (size >= length) size = length - 1;
    memcpy(value, start, size);
    value[size] = '\0';
    url_decode(value);
}

static esp_err_t page_handler(httpd_req_t *request)
{
    httpd_resp_set_type(request, "text/html; charset=utf-8");
    return httpd_resp_send(request, provisioning_page, HTTPD_RESP_USE_STRLEN);
}

static esp_err_t save_handler(httpd_req_t *request)
{
    char body[512];
    int received = httpd_req_recv(request, body, sizeof(body) - 1);
    if (received <= 0) return httpd_resp_send_500(request);
    body[received] = '\0';
    char ssid[33];
    char password[65];
    char adapter[160];
    form_field(body, "ssid", ssid, sizeof(ssid));
    form_field(body, "password", password, sizeof(password));
    form_field(body, "adapter", adapter, sizeof(adapter));
    if (ssid[0] == '\0') return httpd_resp_send_err(request, HTTPD_400_BAD_REQUEST, "WLAN-Name fehlt");
    homedisplay_nvs_set("ssid", ssid);
    homedisplay_nvs_set("pass", password);
    if (adapter[0]) homedisplay_nvs_set("base", adapter);
    httpd_resp_set_type(request, "text/html; charset=utf-8");
    httpd_resp_send(request, "<meta charset=\"utf-8\"><h1>Gespeichert</h1><p>Das Panel startet neu.</p>", HTTPD_RESP_USE_STRLEN);
    vTaskDelay(pdMS_TO_TICKS(1500));
    esp_restart();
    return ESP_OK;
}

static esp_err_t redirect_handler(httpd_req_t *request)
{
    httpd_resp_set_status(request, "302 Found");
    httpd_resp_set_hdr(request, "Location", "http://192.168.4.1/");
    return httpd_resp_send(request, NULL, 0);
}

/* Offenes Einrichtungs-WLAN für zehn Minuten; danach Neustart und erneuter Versuch mit gespeicherten Daten. */
void homedisplay_provision_start(void)
{
    esp_netif_init();
    esp_event_loop_create_default();
    esp_netif_create_default_wifi_ap();
    wifi_init_config_t config = WIFI_INIT_CONFIG_DEFAULT();
    esp_wifi_init(&config);
    uint8_t mac[6];
    esp_read_mac(mac, ESP_MAC_WIFI_SOFTAP);
    wifi_config_t ap = {0};
    snprintf((char *) ap.ap.ssid, sizeof(ap.ap.ssid), "HomeDisplay-Setup-%02X%02X", mac[4], mac[5]);
    ap.ap.ssid_len = strlen((char *) ap.ap.ssid);
    ap.ap.max_connection = 2;
    ap.ap.authmode = WIFI_AUTH_OPEN;
    esp_wifi_set_mode(WIFI_MODE_AP);
    esp_wifi_set_config(WIFI_IF_AP, &ap);
    esp_wifi_start();

    httpd_config_t server_config = HTTPD_DEFAULT_CONFIG();
    server_config.uri_match_fn = httpd_uri_match_wildcard;
    if (httpd_start(&provisioning_server, &server_config) == ESP_OK) {
        httpd_uri_t page = {.uri = "/", .method = HTTP_GET, .handler = page_handler};
        httpd_uri_t save = {.uri = "/save", .method = HTTP_POST, .handler = save_handler};
        httpd_uri_t any = {.uri = "/*", .method = HTTP_GET, .handler = redirect_handler};
        httpd_register_uri_handler(provisioning_server, &page);
        httpd_register_uri_handler(provisioning_server, &save);
        httpd_register_uri_handler(provisioning_server, &any);
    }
    char line[120];
    snprintf(line, sizeof(line), "Einrichtung: WLAN %s\nverbinden und http://192.168.4.1 öffnen", (char *) ap.ap.ssid);
    homedisplay_ui_status(line);
    vTaskDelay(pdMS_TO_TICKS(600000));
    esp_restart();
}
