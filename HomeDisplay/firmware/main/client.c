#include "homedisplay.h"

#include "cJSON.h"
#include "esp_crt_bundle.h"
#include "esp_http_client.h"
#include "esp_log.h"
#include "esp_random.h"
#include "mdns.h"
#include "nvs.h"
#include "nvs_flash.h"

#include <string.h>

static const char *TAG = "client";
static char base_url[160];
static char token[40];
static char pairing_code[8];

static void remember(const char *key, const char *value)
{
    nvs_handle_t handle;
    if (nvs_open("homedisplay", NVS_READWRITE, &handle) != ESP_OK) {
        return;
    }
    nvs_set_str(handle, key, value);
    nvs_commit(handle);
    nvs_close(handle);
}

static void recall(const char *key, char *value, size_t length)
{
    nvs_handle_t handle;
    value[0] = '\0';
    if (nvs_open("homedisplay", NVS_READONLY, &handle) != ESP_OK) {
        return;
    }
    size_t size = length;
    nvs_get_str(handle, key, value, &size);
    nvs_close(handle);
}

static esp_err_t request(const char *path, const char *method, const char *body, char *response, size_t response_length)
{
    char url[220];
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
    if (error == ESP_OK && body) {
        esp_http_client_write(client, body, strlen(body));
    }
    if (error == ESP_OK) {
        esp_http_client_fetch_headers(client);
        int read = esp_http_client_read_response(client, response, response_length - 1);
        if (read < 0) {
            error = ESP_FAIL;
        } else {
            response[read] = '\0';
        }
    }
    esp_http_client_close(client);
    esp_http_client_cleanup(client);
    return error;
}

static void discover(void)
{
    mdns_result_t *results = NULL;
    if (mdns_query_ptr("_homedisplay", "_tcp", 3000, 4, &results) != ESP_OK || !results) {
        return;
    }
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
    for (int index = 0; index < 6; index++) {
        pairing_code[index] = alphabet[esp_random() % (sizeof(alphabet) - 1)];
    }
    pairing_code[6] = '\0';
}

static void pair_until_token(void)
{
    make_code();
    char line[64];
    snprintf(line, sizeof(line), "Kopplungscode\n%s", pairing_code);
    homedisplay_ui_status(line);
    char body[96];
    char response[256];
    snprintf(body, sizeof(body), "{\"code\":\"%s\",\"firmware\":\"" HOMEDISPLAY_FIRMWARE_VERSION "\"}", pairing_code);
    while (token[0] == '\0') {
        if (base_url[0] == '\0') {
            discover();
        }
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

static void client_task(void *argument)
{
    (void) argument;
    recall("base", base_url, sizeof(base_url));
    recall("token", token, sizeof(token));
    if (strlen(CONFIG_HOMEDISPLAY_ADAPTER_URL) > 0) {
        snprintf(base_url, sizeof(base_url), "%s", CONFIG_HOMEDISPLAY_ADAPTER_URL);
    }
    mdns_init();
    if (token[0] == '\0') {
        pair_until_token();
    }
    char response[4096];
    while (true) {
        if (request("/v1/state", "GET", NULL, response, sizeof(response)) == ESP_OK) {
            homedisplay_ui_apply_state(response);
        } else {
            homedisplay_ui_status("Adapter nicht erreichbar");
        }
        char health[96];
        snprintf(health, sizeof(health), "{\"firmware\":\"" HOMEDISPLAY_FIRMWARE_VERSION "\"}");
        request("/v1/health", "POST", health, response, sizeof(response));
        vTaskDelay(pdMS_TO_TICKS(3000));
    }
}

void homedisplay_client_start(void)
{
    xTaskCreate(client_task, "homedisplay", 8192, NULL, 4, NULL);
}
