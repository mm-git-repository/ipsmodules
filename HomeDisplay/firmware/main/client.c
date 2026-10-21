#include "homedisplay.h"
#include "serial_menu.h"

#include "cJSON.h"
#include "esp_crt_bundle.h"
#include "esp_heap_caps.h"
#include "esp_http_client.h"
#include "esp_log.h"
#include "esp_ota_ops.h"
#include "esp_random.h"
#include "esp_system.h"
#include "esp_task_wdt.h"
#include "esp_timer.h"
#include "esp_wifi.h"
#include "mdns.h"

#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "freertos/task.h"

#include <stdio.h>
#include <string.h>
#include <sys/stat.h>

static const char *TAG = "client";

#define LAYOUT_CACHE "/storage/layout.json"
#define CONFIG_CACHE "/storage/config.json"
#define STATE_CACHE "/storage/state.json"

typedef struct {
    char tile[40];
    char action[40];
    int value;
} action_message_t;
static char base_url[160];
static char token[40];
static char pairing_code[8];
static char layout_sha[80];
static char config_sha[80];
static char firmware_sha[80];
static char firmware_signature[700];
static char firmware_version[24];
static char update_error[80];
static char rollback_reason[80];
static char last_command[24];
static int progress;
static int state_interval = 3;
static int idle_interval = 15;
static int maintenance_from = 3;
static int maintenance_to = 5;
static int last_seq = -1;
static int failures;
static int64_t adapter_now;
static int64_t adapter_now_at;
static int64_t last_success_at;
static int64_t started_at;
static bool layout_held;
static bool pending_verify;
static bool online;
static QueueHandle_t actions;

static void remember(const char *key, const char *value)
{
    homedisplay_nvs_set(key, value);
}

static void recall(const char *key, char *value, size_t length)
{
    homedisplay_nvs_get(key, value, length);
}

static bool write_cache(const char *path, const char *content)
{
    char temp[64];
    snprintf(temp, sizeof(temp), "%s.part", path);
    FILE *file = fopen(temp, "w");
    if (!file) return false;
    size_t length = strlen(content);
    bool ok = fwrite(content, 1, length, file) == length;
    fclose(file);
    if (!ok) {
        remove(temp);
        return false;
    }
    remove(path);
    return rename(temp, path) == 0;
}

static bool read_cache(const char *path, char *buffer, size_t length)
{
    FILE *file = fopen(path, "r");
    if (!file) return false;
    size_t read = fread(buffer, 1, length - 1, file);
    fclose(file);
    buffer[read] = '\0';
    return read > 0;
}

static int rssi_now(void)
{
    wifi_ap_record_t info;
    if (esp_wifi_sta_get_ap_info(&info) != ESP_OK) return 0;
    return info.rssi;
}

static void set_online(bool is_online)
{
    if (is_online) last_success_at = esp_timer_get_time();
    if (is_online != online) {
        if (is_online) {
            ESP_LOGI(TAG, "Adapter erreichbar");
        } else {
            ESP_LOGI(TAG, "Adapter nicht erreichbar");
        }
    }
    online = is_online;
    homedisplay_ui_set_online(is_online, rssi_now());
}

static esp_err_t request(const char *path, const char *method, const char *body, char *response, size_t response_length)
{
    if (!homedisplay_wifi_connected()) return ESP_ERR_WIFI_NOT_CONNECT;
    char url[240];
    snprintf(url, sizeof(url), "%s%s", base_url, path);
    esp_http_client_config_t config = {
        .url = url,
        .timeout_ms = 8000,
        .crt_bundle_attach = esp_crt_bundle_attach,
    };
#if CONFIG_HOMEDISPLAY_TLS_INSECURE
    config.skip_cert_common_name_check = true;
    config.crt_bundle_attach = NULL;
#endif
    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (!client) return ESP_ERR_NO_MEM;
    esp_http_client_set_method(client, strcmp(method, "POST") == 0 ? HTTP_METHOD_POST : HTTP_METHOD_GET);
    if (token[0] != '\0') {
        char header[64];
        snprintf(header, sizeof(header), "Bearer %s", token);
        esp_http_client_set_header(client, "Authorization", header);
    }
    if (body) {
        esp_http_client_set_header(client, "Content-Type", "application/json");
    }
    esp_err_t error = esp_http_client_open(client, body ? strlen(body) : 0);
    if (error == ESP_OK && body) esp_http_client_write(client, body, strlen(body));
    if (error == ESP_OK) {
        esp_http_client_fetch_headers(client);
        int status = esp_http_client_get_status_code(client);
        int total = 0;
        while (total < (int) response_length - 1) {
            int chunk = esp_http_client_read(client, response + total, response_length - 1 - total);
            if (chunk < 0) {
                error = ESP_FAIL;
                break;
            }
            if (chunk == 0) break;
            total += chunk;
        }
        response[total < 0 ? 0 : total] = '\0';
        if (error == ESP_OK && status == 401) {
            ESP_LOGW(TAG, "Token abgelehnt, Kopplung erforderlich");
            token[0] = '\0';
            remember("token", "");
            error = ESP_ERR_INVALID_STATE;
        } else if (error == ESP_OK && status >= 400) {
            error = ESP_FAIL;
        }
    }
    esp_http_client_close(client);
    esp_http_client_cleanup(client);
    return error;
}

static void discover(void)
{
    mdns_result_t *results = NULL;
    if (mdns_query_ptr("_homedisplay", "_tcp", 3000, 4, &results) != ESP_OK || !results) return;
    mdns_result_t *item = results;
    while (item) {
        if (item->txt) {
            for (int index = 0; index < item->txt_count; index++) {
                if (strcmp(item->txt[index].key, "url") == 0 && item->txt[index].value) {
                    snprintf(base_url, sizeof(base_url), "%s", item->txt[index].value);
                    remember("base", base_url);
                }
            }
        }
        item = item->next;
    }
    mdns_query_results_free(results);
}

static void make_code(void)
{
    static const char alphabet[] = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    for (int index = 0; index < 6; index++) pairing_code[index] = alphabet[esp_random() % (sizeof(alphabet) - 1)];
    pairing_code[6] = '\0';
}

static void pair_until_token(void)
{
    make_code();
    char line[64];
    snprintf(line, sizeof(line), "Kopplungscode\n%s", pairing_code);
    homedisplay_ui_status(line);
    char body[96];
    char response[512];
    snprintf(body, sizeof(body), "{\"code\":\"%s\",\"firmware\":\"" HOMEDISPLAY_FIRMWARE_VERSION "\"}", pairing_code);
    while (token[0] == '\0') {
        esp_task_wdt_reset();
        if (!homedisplay_wifi_connected()) {
            homedisplay_ui_status("Warte auf WLAN");
        } else {
            homedisplay_ui_status(line);
            if (base_url[0] == '\0') discover();
            if (base_url[0] != '\0' && request("/v1/hello", "POST", body, response, sizeof(response)) == ESP_OK) {
                cJSON *root = cJSON_Parse(response);
                const char *status = cJSON_GetStringValue(cJSON_GetObjectItem(root, "status"));
                const char *received = cJSON_GetStringValue(cJSON_GetObjectItem(root, "token"));
                if (status && strcmp(status, "paired") == 0 && received) {
                    snprintf(token, sizeof(token), "%s", received);
                    remember("token", token);
                }
                cJSON_Delete(root);
            }
        }
        vTaskDelay(pdMS_TO_TICKS(3000));
    }
    homedisplay_ui_status("Hausanzeige verbunden");
}

static int version_of(const char *text)
{
    int major = 0, minor = 0, patch = 0;
    if (text) sscanf(text, "%d.%d.%d", &major, &minor, &patch);
    return major * 10000 + minor * 100 + patch;
}

static int hour_now(void)
{
    int64_t now = adapter_now + (esp_timer_get_time() - adapter_now_at) / 1000000;
    if (now < 0) return -1;
    return (int) ((now % 86400) / 3600);
}

static bool maintenance_open(void)
{
    int hour = hour_now();
    if (hour < 0) return false;
    if (maintenance_from == maintenance_to) return true;
    if (maintenance_from < maintenance_to) return hour >= maintenance_from && hour < maintenance_to;
    return hour >= maintenance_from || hour < maintenance_to;
}

static bool link_ok(void)
{
    return rssi_now() >= -80 && esp_get_free_heap_size() > 80000;
}

static void remember_time(cJSON *root)
{
    cJSON *now = cJSON_GetObjectItem(root, "now");
    if (!now) return;
    int64_t value = (int64_t) now->valuedouble;
    if (value <= 0) return;
    /* Die RTC wird höchstens einmal pro Stunde nachgestellt. */
    static int64_t rtc_written_at;
    adapter_now = value;
    adapter_now_at = esp_timer_get_time();
    homedisplay_ui_set_time(adapter_now);
    if (rtc_written_at == 0 || adapter_now_at - rtc_written_at > 3600000000LL) {
        if (homedisplay_rtc_write(adapter_now) == ESP_OK) rtc_written_at = adapter_now_at;
    }
}

static void copy_string(cJSON *item, char *target, size_t length)
{
    const char *value = cJSON_GetStringValue(item);
    snprintf(target, length, "%s", value ? value : "");
}

static esp_err_t download_to_file(const char *path, const char *target)
{
    char url[260];
    snprintf(url, sizeof(url), "%s%s", base_url, path);
    esp_http_client_config_t config = {
        .url = url,
        .timeout_ms = 15000,
        .crt_bundle_attach = esp_crt_bundle_attach,
    };
#if CONFIG_HOMEDISPLAY_TLS_INSECURE
    config.skip_cert_common_name_check = true;
    config.crt_bundle_attach = NULL;
#endif
    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (!client) return ESP_ERR_NO_MEM;
    char header[64];
    snprintf(header, sizeof(header), "Bearer %s", token);
    esp_http_client_set_header(client, "Authorization", header);
    esp_err_t error = esp_http_client_open(client, 0);
    if (error != ESP_OK) {
        esp_http_client_cleanup(client);
        return error;
    }
    esp_http_client_fetch_headers(client);
    if (esp_http_client_get_status_code(client) != 200) {
        esp_http_client_close(client);
        esp_http_client_cleanup(client);
        return ESP_FAIL;
    }
    char temp[96];
    snprintf(temp, sizeof(temp), "%s.part", target);
    FILE *file = fopen(temp, "wb");
    if (!file) {
        esp_http_client_close(client);
        esp_http_client_cleanup(client);
        return ESP_FAIL;
    }
    char buffer[1024];
    while (true) {
        esp_task_wdt_reset();
        int read = esp_http_client_read(client, buffer, sizeof(buffer));
        if (read < 0) {
            error = ESP_FAIL;
            break;
        }
        if (read == 0) break;
        if (fwrite(buffer, 1, read, file) != (size_t) read) {
            error = ESP_FAIL;
            break;
        }
    }
    fclose(file);
    esp_http_client_close(client);
    esp_http_client_cleanup(client);
    if (error == ESP_OK) {
        remove(target);
        if (rename(temp, target) != 0) error = ESP_FAIL;
    } else {
        remove(temp);
    }
    return error;
}

static void fetch_one_image(const char *id, const char *file)
{
    if (!id || !id[0] || !file || !file[0]) return;
    char target[96];
    snprintf(target, sizeof(target), "/storage/%s", file);
    struct stat info;
    if (stat(target, &info) == 0 && info.st_size > 0) return;
    char path[64];
    snprintf(path, sizeof(path), "/v1/images/%s", id);
    homedisplay_ui_status("Lade Bilder");
    if (download_to_file(path, target) != ESP_OK) {
        ESP_LOGW(TAG, "Bild %s konnte nicht geladen werden", id);
    }
}

/* Lädt Kachelbilder und Seitenhintergründe in den Flash, bevor die Ansicht gezeichnet wird. */
static void fetch_images(const char *layout_json)
{
    cJSON *root = cJSON_Parse(layout_json);
    if (!root) return;
    cJSON *page = NULL;
    cJSON_ArrayForEach(page, cJSON_GetObjectItem(root, "pages")) {
        fetch_one_image(
            cJSON_GetStringValue(cJSON_GetObjectItem(page, "backgroundId")),
            cJSON_GetStringValue(cJSON_GetObjectItem(page, "backgroundFile")));
        cJSON *tile = NULL;
        cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
            const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
            if (!kind || strcmp(kind, "image") != 0) continue;
            fetch_one_image(
                cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageId")),
                cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageFile")));
        }
    }
    cJSON_Delete(root);
}

#define CAM_STAMP_MAX 16
typedef struct {
    char id[40];
    int stamp;
} cam_stamp_entry_t;
static cam_stamp_entry_t cam_stamps[CAM_STAMP_MAX];

static bool cam_stamp_unchanged(const char *tile_id, int stamp)
{
    for (int index = 0; index < CAM_STAMP_MAX; index++) {
        if (cam_stamps[index].id[0] && strcmp(cam_stamps[index].id, tile_id) == 0) {
            if (cam_stamps[index].stamp == stamp) return true;
            cam_stamps[index].stamp = stamp;
            return false;
        }
    }
    for (int index = 0; index < CAM_STAMP_MAX; index++) {
        if (!cam_stamps[index].id[0]) {
            snprintf(cam_stamps[index].id, sizeof(cam_stamps[index].id), "%s", tile_id);
            cam_stamps[index].stamp = stamp;
            return false;
        }
    }
    return false;
}

static const char *camera_file_for_tile(cJSON *layout_root, const char *tile_id)
{
    cJSON *page = NULL;
    cJSON_ArrayForEach(page, cJSON_GetObjectItem(layout_root, "pages")) {
        cJSON *tile = NULL;
        cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
            const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
            const char *id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
            if (kind && strcmp(kind, "camera") == 0 && id && tile_id && strcmp(id, tile_id) == 0) {
                return cJSON_GetStringValue(cJSON_GetObjectItem(tile, "cameraFile"));
            }
        }
    }
    return NULL;
}

static void fetch_one_camera(const char *tile_id, const char *file)
{
    if (!tile_id || !tile_id[0] || !file || !file[0]) return;
    char target[96];
    snprintf(target, sizeof(target), "/storage/%s", file);
    char path[80];
    snprintf(path, sizeof(path), "/v1/camera/%s", tile_id);
    homedisplay_ui_status("Lade Kamera");
    if (download_to_file(path, target) != ESP_OK) {
        ESP_LOGW(TAG, "Kamera %s konnte nicht geladen werden", tile_id);
        return;
    }
    homedisplay_ui_refresh_camera(tile_id, file);
}

static cJSON *read_layout_cache(void)
{
    FILE *layout_file = fopen(LAYOUT_CACHE, "r");
    if (!layout_file) return NULL;
    fseek(layout_file, 0, SEEK_END);
    long size = ftell(layout_file);
    if (size <= 0 || size > 16000) {
        fclose(layout_file);
        return NULL;
    }
    fseek(layout_file, 0, SEEK_SET);
    char *layout_text = malloc((size_t) size + 1);
    if (!layout_text) {
        fclose(layout_file);
        return NULL;
    }
    if (fread(layout_text, 1, (size_t) size, layout_file) != (size_t) size) {
        free(layout_text);
        fclose(layout_file);
        return NULL;
    }
    layout_text[size] = '\0';
    fclose(layout_file);
    cJSON *layout = cJSON_Parse(layout_text);
    free(layout_text);
    return layout;
}

static void fetch_layout_cameras(const char *layout_json)
{
    cJSON *root = cJSON_Parse(layout_json);
    if (!root) return;
    cJSON *page = NULL;
    cJSON_ArrayForEach(page, cJSON_GetObjectItem(root, "pages")) {
        cJSON *tile = NULL;
        cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
            const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
            if (!kind || strcmp(kind, "camera") != 0) continue;
            fetch_one_camera(
                cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId")),
                cJSON_GetStringValue(cJSON_GetObjectItem(tile, "cameraFile")));
        }
    }
    cJSON_Delete(root);
}

static void sync_cameras_from_state(const char *state_json)
{
    cJSON *state = cJSON_Parse(state_json);
    if (!state) return;
    if (cJSON_IsTrue(cJSON_GetObjectItem(state, "unchanged"))) {
        cJSON_Delete(state);
        return;
    }
    cJSON *layout = read_layout_cache();
    cJSON *tile = NULL;
    cJSON_ArrayForEach(tile, cJSON_GetObjectItem(state, "tiles")) {
        cJSON *stamp_item = cJSON_GetObjectItem(tile, "cameraSeq");
        if (!stamp_item || !cJSON_IsNumber(stamp_item)) continue;
        const char *tile_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
        int stamp = stamp_item->valueint;
        if (!tile_id || stamp <= 0) continue;
        if (cam_stamp_unchanged(tile_id, stamp)) continue;
        const char *file = layout ? camera_file_for_tile(layout, tile_id) : NULL;
        if (file) fetch_one_camera(tile_id, file);
    }
    if (layout) cJSON_Delete(layout);
    cJSON_Delete(state);
}

static cam_stamp_entry_t media_stamps[CAM_STAMP_MAX];

static bool media_stamp_unchanged(const char *tile_id, int stamp)
{
    for (int index = 0; index < CAM_STAMP_MAX; index++) {
        if (media_stamps[index].id[0] && strcmp(media_stamps[index].id, tile_id) == 0) {
            if (media_stamps[index].stamp == stamp) return true;
            media_stamps[index].stamp = stamp;
            return false;
        }
    }
    for (int index = 0; index < CAM_STAMP_MAX; index++) {
        if (!media_stamps[index].id[0]) {
            snprintf(media_stamps[index].id, sizeof(media_stamps[index].id), "%s", tile_id);
            media_stamps[index].stamp = stamp;
            return false;
        }
    }
    return false;
}

static const char *media_file_for_tile(cJSON *layout_root, const char *tile_id)
{
    cJSON *page = NULL;
    cJSON_ArrayForEach(page, cJSON_GetObjectItem(layout_root, "pages")) {
        cJSON *tile = NULL;
        cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
            const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
            const char *id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
            const char *source = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageSource"));
            if (kind && strcmp(kind, "image") == 0 && source && strcmp(source, "media") == 0 && id && tile_id && strcmp(id, tile_id) == 0) {
                return cJSON_GetStringValue(cJSON_GetObjectItem(tile, "mediaFile"));
            }
        }
    }
    return NULL;
}

static void fetch_one_media(const char *tile_id, const char *file)
{
    if (!tile_id || !tile_id[0] || !file || !file[0]) return;
    char target[96];
    snprintf(target, sizeof(target), "/storage/%s", file);
    char path[80];
    snprintf(path, sizeof(path), "/v1/media/%s", tile_id);
    homedisplay_ui_status("Lade Medien");
    if (download_to_file(path, target) != ESP_OK) {
        ESP_LOGW(TAG, "Medien %s konnte nicht geladen werden", tile_id);
        return;
    }
    homedisplay_ui_refresh_image(tile_id, file);
}

static void fetch_layout_media(const char *layout_json)
{
    cJSON *root = cJSON_Parse(layout_json);
    if (!root) return;
    cJSON *page = NULL;
    cJSON_ArrayForEach(page, cJSON_GetObjectItem(root, "pages")) {
        cJSON *tile = NULL;
        cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
            const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
            const char *source = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageSource"));
            if (!kind || strcmp(kind, "image") != 0 || !source || strcmp(source, "media") != 0) continue;
            fetch_one_media(
                cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId")),
                cJSON_GetStringValue(cJSON_GetObjectItem(tile, "mediaFile")));
        }
    }
    cJSON_Delete(root);
}

static void sync_media_from_state(const char *state_json)
{
    cJSON *state = cJSON_Parse(state_json);
    if (!state) return;
    if (cJSON_IsTrue(cJSON_GetObjectItem(state, "unchanged"))) {
        cJSON_Delete(state);
        return;
    }
    cJSON *layout = read_layout_cache();
    cJSON *tile = NULL;
    cJSON_ArrayForEach(tile, cJSON_GetObjectItem(state, "tiles")) {
        cJSON *stamp_item = cJSON_GetObjectItem(tile, "mediaSeq");
        if (!stamp_item || !cJSON_IsNumber(stamp_item)) continue;
        const char *tile_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
        int stamp = stamp_item->valueint;
        if (!tile_id || stamp <= 0) continue;
        if (media_stamp_unchanged(tile_id, stamp)) continue;
        const char *file = layout ? media_file_for_tile(layout, tile_id) : NULL;
        if (file) fetch_one_media(tile_id, file);
    }
    if (layout) cJSON_Delete(layout);
    cJSON_Delete(state);
}

static void apply_config_document(const char *json)
{
    homedisplay_ui_apply_config(json);
    cJSON *document = cJSON_Parse(json);
    if (!document) return;
    cJSON *item = cJSON_GetObjectItem(document, "stateIntervalSec");
    if (item) state_interval = item->valueint;
    item = cJSON_GetObjectItem(document, "idleStateIntervalSec");
    if (item) idle_interval = item->valueint;
    item = cJSON_GetObjectItem(document, "maintenanceFrom");
    if (item) maintenance_from = item->valueint;
    item = cJSON_GetObjectItem(document, "maintenanceTo");
    if (item) maintenance_to = item->valueint;
    cJSON_Delete(document);
}

/* Zuletzt bekannte Ansicht, Einstellungen und Werte aus dem Flash, damit das Panel ohne Adapter nicht leer bleibt. */
static void restore_cache(char *buffer, size_t length)
{
    if (read_cache(CONFIG_CACHE, buffer, length)) {
        apply_config_document(buffer);
        recall("config_sha", config_sha, sizeof(config_sha));
    }
    if (read_cache(LAYOUT_CACHE, buffer, length)) {
        homedisplay_ui_apply_layout(buffer);
        recall("layout_sha", layout_sha, sizeof(layout_sha));
        if (read_cache(STATE_CACHE, buffer, length)) {
            homedisplay_ui_apply_state(buffer);
        }
        homedisplay_ui_status("Zwischengespeicherte Ansicht");
    }
}

/* Befehle aus dem Manifest: restart, testPattern, resync. Jeder Befehl wird nur einmal ausgeführt. */
static void run_command(cJSON *root)
{
    cJSON *command = cJSON_GetObjectItem(root, "command");
    const char *name = cJSON_GetStringValue(cJSON_GetObjectItem(command, "name"));
    const char *id = cJSON_GetStringValue(cJSON_GetObjectItem(command, "id"));
    if (!name || !id || strcmp(id, last_command) == 0) return;
    snprintf(last_command, sizeof(last_command), "%s", id);
    remember("command", last_command);
    ESP_LOGI(TAG, "Befehl %s", name);
    if (strcmp(name, "restart") == 0) {
        homedisplay_ui_status("Neustart");
        vTaskDelay(pdMS_TO_TICKS(500));
        esp_restart();
    } else if (strcmp(name, "testPattern") == 0) {
        homedisplay_ui_test_pattern(true);
    } else if (strcmp(name, "testPatternOff") == 0) {
        homedisplay_ui_test_pattern(false);
    } else if (strcmp(name, "resync") == 0) {
        layout_sha[0] = '\0';
        config_sha[0] = '\0';
        last_seq = -1;
    }
}

static void pull_manifest(char *response, size_t length)
{
    if (request("/v1/manifest", "GET", NULL, response, length) != ESP_OK) return;
    cJSON *root = cJSON_Parse(response);
    if (!root) return;
    remember_time(root);
    run_command(root);
    cJSON *firmware = cJSON_GetObjectItem(root, "firmware");
    cJSON *config = cJSON_GetObjectItem(root, "config");
    cJSON *layout = cJSON_GetObjectItem(root, "layout");
    copy_string(cJSON_GetObjectItem(firmware, "version"), firmware_version, sizeof(firmware_version));
    copy_string(cJSON_GetObjectItem(firmware, "sha256"), firmware_sha, sizeof(firmware_sha));
    copy_string(cJSON_GetObjectItem(firmware, "signature"), firmware_signature, sizeof(firmware_signature));
    char next_config[80];
    char next_layout[80];
    copy_string(cJSON_GetObjectItem(config, "sha256"), next_config, sizeof(next_config));
    copy_string(cJSON_GetObjectItem(layout, "sha256"), next_layout, sizeof(next_layout));
    const char *min_firmware = cJSON_GetStringValue(cJSON_GetObjectItem(layout, "minFirmware"));
    layout_held = min_firmware && version_of(HOMEDISPLAY_FIRMWARE_VERSION) < version_of(min_firmware);
    cJSON_Delete(root);
    if (next_config[0] && strcmp(next_config, config_sha) != 0 && request("/v1/config", "GET", NULL, response, length) == ESP_OK) {
        snprintf(config_sha, sizeof(config_sha), "%s", next_config);
        apply_config_document(response);
        write_cache(CONFIG_CACHE, response);
        remember("config_sha", config_sha);
    }
    if (!layout_held && next_layout[0] && strcmp(next_layout, layout_sha) != 0 && request("/v1/layout", "GET", NULL, response, length) == ESP_OK) {
        fetch_images(response);
        fetch_layout_cameras(response);
        fetch_layout_media(response);
        snprintf(layout_sha, sizeof(layout_sha), "%s", next_layout);
        homedisplay_ui_apply_layout(response);
        write_cache(LAYOUT_CACHE, response);
        remember("layout_sha", layout_sha);
        last_seq = -1;
    }
}

static void post_action(const char *tile_id, const char *action_id, int value)
{
    if (!actions) return;
    action_message_t message = {0};
    snprintf(message.tile, sizeof(message.tile), "%s", tile_id);
    snprintf(message.action, sizeof(message.action), "%s", action_id);
    message.value = value;
    xQueueSend(actions, &message, 0);
}

static void send_queued_action(void)
{
    action_message_t message;
    if (!actions || xQueueReceive(actions, &message, 0) != pdTRUE) return;
    char request_id[20];
    snprintf(request_id, sizeof(request_id), "%08lx%08lx", (unsigned long) esp_random(), (unsigned long) esp_random());
    char body[220];
    snprintf(body, sizeof(body), "{\"tileId\":\"%s\",\"actionId\":\"%s\",\"value\":%d,\"requestId\":\"%s\"}", message.tile, message.action, message.value, request_id);
    char response[256];
    /* Ein Wiederholungsversuch mit derselben requestId; der Adapter entdoppelt. */
    if (request("/v1/actions", "POST", body, response, sizeof(response)) != ESP_OK) {
        vTaskDelay(pdMS_TO_TICKS(500));
        request("/v1/actions", "POST", body, response, sizeof(response));
    }
}

static void send_health(char *response, size_t length)
{
    char body[480];
    int64_t uptime = (esp_timer_get_time() - started_at) / 1000000;
    int64_t data_age = last_success_at ? (esp_timer_get_time() - last_success_at) / 1000000 : -1;
    snprintf(body, sizeof(body),
             "{\"firmware\":\"" HOMEDISPLAY_FIRMWARE_VERSION "\",\"available\":\"%s\",\"progress\":%d,\"updateError\":\"%s\",\"rollback\":\"%s\","
             "\"rssi\":%d,\"heap\":%lu,\"minHeap\":%lu,\"uptime\":%lld,\"resetReason\":%d,\"dataAge\":%lld,\"frames\":%lu,\"failures\":%d}",
             firmware_version, progress, update_error, rollback_reason,
             rssi_now(), (unsigned long) esp_get_free_heap_size(), (unsigned long) esp_get_minimum_free_heap_size(),
             (long long) uptime, (int) esp_reset_reason(), (long long) data_age, (unsigned long) homedisplay_ui_frames(), failures);
    request("/v1/health", "POST", body, response, length);
}

static void maybe_update(void)
{
    if (firmware_sha[0] == '\0' || firmware_signature[0] == '\0') return;
    if (strcmp(firmware_version, HOMEDISPLAY_FIRMWARE_VERSION) == 0) return;
    if (!maintenance_open()) {
        snprintf(update_error, sizeof(update_error), "Wartet auf das Wartungsfenster");
        return;
    }
    if (homedisplay_ui_busy() || !link_ok()) {
        snprintf(update_error, sizeof(update_error), "Aktualisierung zurückgestellt");
        return;
    }
    homedisplay_ui_lock(true);
    homedisplay_ui_status("Aktualisierung");
    progress = 0;
    char url[220];
    snprintf(url, sizeof(url), "%s/v1/firmware", base_url);
    esp_err_t error = homedisplay_ota_run(url, token, firmware_sha, firmware_signature, &progress);
    homedisplay_ui_lock(false);
    if (error != ESP_OK) snprintf(update_error, sizeof(update_error), "Aktualisierung fehlgeschlagen");
}

static void confirm_boot(void)
{
    if (!pending_verify) return;
    esp_ota_mark_app_valid_cancel_rollback();
    pending_verify = false;
    rollback_reason[0] = '\0';
    remember("rollback", "");
}

static void client_task(void *argument)
{
    (void) argument;
    started_at = esp_timer_get_time();
    esp_task_wdt_add(NULL);
    const esp_partition_t *running = esp_ota_get_running_partition();
    esp_ota_img_states_t image_state;
    if (running && esp_ota_get_state_partition(running, &image_state) == ESP_OK && image_state == ESP_OTA_IMG_PENDING_VERIFY) {
        pending_verify = true;
    }
    recall("rollback", rollback_reason, sizeof(rollback_reason));
    recall("base", base_url, sizeof(base_url));
    recall("token", token, sizeof(token));
    recall("command", last_command, sizeof(last_command));
    if (strlen(CONFIG_HOMEDISPLAY_ADAPTER_URL) > 0 && base_url[0] == '\0') snprintf(base_url, sizeof(base_url), "%s", CONFIG_HOMEDISPLAY_ADAPTER_URL);
    mdns_init();
    homedisplay_ui_set_action(post_action);
    char *response = heap_caps_malloc(16384, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    size_t response_length = 16384;
    if (!response) {
        response = malloc(8192);
        response_length = 8192;
    }
    if (!response) {
        homedisplay_ui_status("Zu wenig Speicher");
        esp_task_wdt_delete(NULL);
        vTaskDelete(NULL);
        return;
    }
    restore_cache(response, response_length);
    if (token[0] == '\0') pair_until_token();
    int64_t next_manifest = 0;
    int64_t verify_deadline = esp_timer_get_time() + 180000000;
    int64_t test_pattern_until = CONFIG_HOMEDISPLAY_BOOT_TEST_SECONDS > 0 ? esp_timer_get_time() + (int64_t) CONFIG_HOMEDISPLAY_BOOT_TEST_SECONDS * 1000000 : 0;
    while (true) {
        homedisplay_serial_poll();
        esp_task_wdt_reset();
        if (test_pattern_until && esp_timer_get_time() > test_pattern_until) {
            homedisplay_ui_test_pattern(false);
            test_pattern_until = 0;
        }
        if (token[0] == '\0') pair_until_token();
        int64_t now = esp_timer_get_time();
        if (!homedisplay_wifi_connected()) {
            set_online(false);
            homedisplay_ui_status("WLAN getrennt");
            vTaskDelay(pdMS_TO_TICKS(2000));
            continue;
        }
        if (now >= next_manifest) {
            pull_manifest(response, response_length);
            int jitter = esp_random() % 60;
            next_manifest = now + (int64_t) (900 + jitter) * 1000000;
            maybe_update();
        }
        send_queued_action();
        char path[40];
        snprintf(path, sizeof(path), "/v1/state?since=%d", last_seq);
        esp_err_t error = request(path, "GET", NULL, response, response_length);
        if (error == ESP_OK) {
            failures = 0;
            cJSON *root = cJSON_Parse(response);
            cJSON *seq = root ? cJSON_GetObjectItem(root, "seq") : NULL;
            bool unchanged = root && cJSON_IsTrue(cJSON_GetObjectItem(root, "unchanged"));
            if (seq) last_seq = seq->valueint;
            if (root) remember_time(root);
            if (root) cJSON_Delete(root);
            sync_cameras_from_state(response);
            sync_media_from_state(response);
            homedisplay_ui_apply_state(response);
            if (!unchanged) write_cache(STATE_CACHE, response);
            set_online(true);
            confirm_boot();
            homedisplay_ui_status(layout_held ? "Ansicht wartet auf ein neueres Programm" : "Hausanzeige verbunden");
        } else if (error != ESP_ERR_WIFI_NOT_CONNECT) {
            failures++;
            if (failures >= 3) {
                set_online(false);
                homedisplay_ui_status("Dienst nicht erreichbar");
            }
            if (failures == 20 && strlen(CONFIG_HOMEDISPLAY_ADAPTER_URL) == 0) {
                /* Adapter eventuell umgezogen: erneut per mDNS suchen. */
                discover();
            }
        }
        if (online) send_health(response, response_length);
        if (pending_verify && esp_timer_get_time() > verify_deadline) {
            snprintf(rollback_reason, sizeof(rollback_reason), "Keine Bestätigung");
            remember("rollback", rollback_reason);
            esp_ota_mark_app_invalid_rollback_and_reboot();
        }
        int wait = homedisplay_ui_busy() ? state_interval : idle_interval;
        if (!online) wait = failures > 10 ? 30 : 5;
        if (wait < 2) wait = 2;
        vTaskDelay(pdMS_TO_TICKS(wait * 1000));
    }
}

void homedisplay_client_start(void)
{
    actions = xQueueCreate(4, sizeof(action_message_t));
    xTaskCreate(client_task, "homedisplay", 16384, NULL, 4, NULL);
}
