#include "homedisplay.h"

#include "cJSON.h"
#include "esp_crt_bundle.h"
#include "esp_heap_caps.h"
#include "esp_http_client.h"
#include "esp_log.h"
#include "esp_ota_ops.h"
#include "esp_random.h"
#include "esp_timer.h"
#include "esp_wifi.h"
#include "mdns.h"
#include "nvs.h"
#include "nvs_flash.h"

#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"

#include <stdio.h>
#include <string.h>
#include <sys/stat.h>

static const char *TAG = "client";

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
static int progress;
static int state_interval = 3;
static int idle_interval = 15;
static int maintenance_from = 3;
static int maintenance_to = 5;
static int last_seq = -1;
static int64_t adapter_now;
static int64_t adapter_now_at;
static bool layout_held;
static bool pending_verify;
static QueueHandle_t actions;

static void remember(const char *key, const char *value)
{
    nvs_handle_t handle;
    if (nvs_open("homedisplay", NVS_READWRITE, &handle) != ESP_OK) return;
    nvs_set_str(handle, key, value);
    nvs_commit(handle);
    nvs_close(handle);
}

static void recall(const char *key, char *value, size_t length)
{
    nvs_handle_t handle;
    value[0] = '\0';
    if (nvs_open("homedisplay", NVS_READONLY, &handle) != ESP_OK) return;
    size_t size = length;
    nvs_get_str(handle, key, value, &size);
    nvs_close(handle);
}

static esp_err_t request(const char *path, const char *method, const char *body, char *response, size_t response_length)
{
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
    esp_http_client_set_method(client, strcmp(method, "POST") == 0 ? HTTP_METHOD_POST : HTTP_METHOD_GET);
    if (token[0] != '\0') {
        char header[64];
        snprintf(header, sizeof(header), "Bearer %s", token);
        esp_http_client_set_header(client, "Authorization", header);
    }
    if (body) {
        esp_http_client_set_header(client, "Content-Type", "application/json");
        esp_http_client_set_post_field(client, body, strlen(body));
    }
    esp_err_t error = esp_http_client_open(client, body ? strlen(body) : 0);
    if (error == ESP_OK && body) esp_http_client_write(client, body, strlen(body));
    if (error == ESP_OK) {
        esp_http_client_fetch_headers(client);
        int read = 0;
        int total = 0;
        while (total < (int) response_length - 1) {
            int chunk = esp_http_client_read(client, response + total, response_length - 1 - total);
            if (chunk < 0) {
                error = ESP_FAIL;
                break;
            }
            if (chunk == 0) break;
            total += chunk;
            read = total;
        }
        if (error == ESP_OK) response[read] = '\0';
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
        vTaskDelay(pdMS_TO_TICKS(3000));
    }
    homedisplay_ui_status("HomeDisplay verbunden");
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
    wifi_ap_record_t info;
    if (esp_wifi_sta_get_ap_info(&info) != ESP_OK) return false;
    return info.rssi >= -80 && esp_get_free_heap_size() > 80000;
}

static void remember_time(cJSON *root)
{
    cJSON *now = cJSON_GetObjectItem(root, "now");
    if (!now) return;
    adapter_now = (int64_t) now->valuedouble;
    adapter_now_at = esp_timer_get_time();
    homedisplay_ui_set_time(adapter_now);
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

/* Lädt alle Bilder einer Ansicht in den Flash, bevor die Ansicht gezeichnet wird. */
static void fetch_images(const char *layout_json)
{
    cJSON *root = cJSON_Parse(layout_json);
    if (!root) return;
    cJSON *page = NULL;
    cJSON_ArrayForEach(page, cJSON_GetObjectItem(root, "pages")) {
        cJSON *tile = NULL;
        cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
            const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
            const char *file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageFile"));
            const char *id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageId"));
            if (!kind || strcmp(kind, "image") != 0 || !file || !file[0] || !id) continue;
            char target[96];
            snprintf(target, sizeof(target), "/storage/%s", file);
            struct stat info;
            if (stat(target, &info) == 0 && info.st_size > 0) continue;
            char path[64];
            snprintf(path, sizeof(path), "/v1/images/%s", id);
            homedisplay_ui_status("Lade Bilder");
            if (download_to_file(path, target) != ESP_OK) {
                ESP_LOGW(TAG, "Bild %s konnte nicht geladen werden", id);
            }
        }
    }
    cJSON_Delete(root);
}

static void pull_manifest(char *response, size_t length)
{
    if (request("/v1/manifest", "GET", NULL, response, length) != ESP_OK) return;
    cJSON *root = cJSON_Parse(response);
    if (!root) return;
    remember_time(root);
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
    if (next_config[0] && strcmp(next_config, config_sha) != 0 && request("/v1/config", "GET", NULL, response, length) == ESP_OK) {
        snprintf(config_sha, sizeof(config_sha), "%s", next_config);
        homedisplay_ui_apply_config(response);
        cJSON *document = cJSON_Parse(response);
        if (document) {
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
    }
    if (!layout_held && next_layout[0] && strcmp(next_layout, layout_sha) != 0 && request("/v1/layout", "GET", NULL, response, length) == ESP_OK) {
        fetch_images(response);
        snprintf(layout_sha, sizeof(layout_sha), "%s", next_layout);
        homedisplay_ui_apply_layout(response);
    }
    cJSON_Delete(root);
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
    request("/v1/actions", "POST", body, response, sizeof(response));
}

static void send_health(char *response, size_t length)
{
    char body[320];
    snprintf(body, sizeof(body),
             "{\"firmware\":\"" HOMEDISPLAY_FIRMWARE_VERSION "\",\"available\":\"%s\",\"progress\":%d,\"updateError\":\"%s\",\"rollback\":\"%s\"}",
             firmware_version, progress, update_error, rollback_reason);
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
        snprintf(update_error, sizeof(update_error), "Update zurückgestellt");
        return;
    }
    homedisplay_ui_lock(true);
    homedisplay_ui_status("Update");
    progress = 0;
    char url[220];
    snprintf(url, sizeof(url), "%s/v1/firmware", base_url);
    esp_err_t error = homedisplay_ota_run(url, token, firmware_sha, firmware_signature, &progress);
    homedisplay_ui_lock(false);
    if (error != ESP_OK) snprintf(update_error, sizeof(update_error), "Update fehlgeschlagen");
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
    const esp_partition_t *running = esp_ota_get_running_partition();
    esp_ota_img_states_t image_state;
    if (running && esp_ota_get_state_partition(running, &image_state) == ESP_OK && image_state == ESP_OTA_IMG_PENDING_VERIFY) {
        pending_verify = true;
    }
    recall("rollback", rollback_reason, sizeof(rollback_reason));
    recall("base", base_url, sizeof(base_url));
    recall("token", token, sizeof(token));
    if (strlen(CONFIG_HOMEDISPLAY_ADAPTER_URL) > 0) snprintf(base_url, sizeof(base_url), "%s", CONFIG_HOMEDISPLAY_ADAPTER_URL);
    mdns_init();
    homedisplay_ui_set_action(post_action);
    if (token[0] == '\0') pair_until_token();
    char *response = heap_caps_malloc(16384, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    size_t response_length = 16384;
    if (!response) {
        response = malloc(8192);
        response_length = 8192;
    }
    if (!response) {
        homedisplay_ui_status("Zu wenig Speicher");
        vTaskDelete(NULL);
        return;
    }
    int64_t next_manifest = 0;
    int64_t verify_deadline = esp_timer_get_time() + 180000000;
    while (true) {
        int64_t now = esp_timer_get_time();
        if (now >= next_manifest) {
            pull_manifest(response, response_length);
            int jitter = esp_random() % 60;
            next_manifest = now + (int64_t) (900 + jitter) * 1000000;
            maybe_update();
        }
        send_queued_action();
        char path[40];
        snprintf(path, sizeof(path), "/v1/state?since=%d", last_seq);
        if (request(path, "GET", NULL, response, response_length) == ESP_OK) {
            cJSON *root = cJSON_Parse(response);
            cJSON *seq = root ? cJSON_GetObjectItem(root, "seq") : NULL;
            if (seq) last_seq = seq->valueint;
            if (root) cJSON_Delete(root);
            homedisplay_ui_apply_state(response);
            confirm_boot();
            homedisplay_ui_status(layout_held ? "Layout wartet auf neuere Firmware" : "HomeDisplay verbunden");
        } else {
            homedisplay_ui_status("Adapter nicht erreichbar");
        }
        send_health(response, response_length);
        if (pending_verify && esp_timer_get_time() > verify_deadline) {
            snprintf(rollback_reason, sizeof(rollback_reason), "Keine Bestätigung");
            remember("rollback", rollback_reason);
            esp_ota_mark_app_invalid_rollback_and_reboot();
        }
        int wait = homedisplay_ui_busy() ? state_interval : idle_interval;
        if (wait < 2) wait = 2;
        vTaskDelay(pdMS_TO_TICKS(wait * 1000));
    }
}

void homedisplay_client_start(void)
{
    actions = xQueueCreate(4, sizeof(action_message_t));
    xTaskCreate(client_task, "homedisplay", 16384, NULL, 4, NULL);
}
