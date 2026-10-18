#include "homedisplay.h"
#include "ota_pub.h"

#include "esp_crt_bundle.h"
#include "esp_http_client.h"
#include "esp_log.h"
#include "esp_ota_ops.h"
#include "esp_system.h"
#include "esp_task_wdt.h"
#include "mbedtls/base64.h"
#include "mbedtls/pk.h"
#include "mbedtls/sha256.h"

#include <stdio.h>
#include <string.h>

static const char *TAG = "ota";

static int hex_value(char character)
{
    if (character >= '0' && character <= '9') return character - '0';
    if (character >= 'a' && character <= 'f') return character - 'a' + 10;
    if (character >= 'A' && character <= 'F') return character - 'A' + 10;
    return -1;
}

static bool hash_matches(const unsigned char hash[32], const char *hex)
{
    if (!hex || strlen(hex) != 64) return false;
    for (int index = 0; index < 32; index++) {
        int high = hex_value(hex[index * 2]);
        int low = hex_value(hex[index * 2 + 1]);
        if (high < 0 || low < 0 || hash[index] != (unsigned char) ((high << 4) | low)) return false;
    }
    return true;
}

esp_err_t homedisplay_ota_run(const char *url, const char *token, const char *sha256_hex, const char *signature_b64, int *progress)
{
    if (!url || !sha256_hex || !signature_b64 || signature_b64[0] == '\0') return ESP_ERR_INVALID_ARG;
    const esp_partition_t *slot = esp_ota_get_next_update_partition(NULL);
    if (!slot) return ESP_ERR_NOT_FOUND;
    esp_ota_handle_t handle = 0;
    esp_err_t error = esp_ota_begin(slot, OTA_WITH_SEQUENTIAL_WRITES, &handle);
    if (error != ESP_OK) return error;

    esp_http_client_config_t config = {
        .url = url,
        .timeout_ms = 20000,
        .crt_bundle_attach = esp_crt_bundle_attach,
    };
#if CONFIG_HOMEDISPLAY_TLS_INSECURE
    config.skip_cert_common_name_check = true;
    config.crt_bundle_attach = NULL;
#endif
    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (token && token[0]) {
        char header[80];
        snprintf(header, sizeof(header), "Bearer %s", token);
        esp_http_client_set_header(client, "Authorization", header);
    }
    error = esp_http_client_open(client, 0);
    if (error != ESP_OK) {
        esp_ota_abort(handle);
        esp_http_client_cleanup(client);
        return error;
    }
    int total = esp_http_client_fetch_headers(client);
    mbedtls_sha256_context sha;
    mbedtls_sha256_init(&sha);
    mbedtls_sha256_starts(&sha, 0);
    char buffer[1024];
    int received = 0;
    while (true) {
        esp_task_wdt_reset();
        int read = esp_http_client_read(client, buffer, sizeof(buffer));
        if (read < 0) {
            error = ESP_FAIL;
            break;
        }
        if (read == 0) break;
        if (esp_ota_write(handle, buffer, read) != ESP_OK) {
            error = ESP_FAIL;
            break;
        }
        mbedtls_sha256_update(&sha, (const unsigned char *) buffer, read);
        received += read;
        if (progress && total > 0) *progress = received * 100 / total;
        if (esp_get_free_heap_size() < 40000) {
            error = ESP_ERR_NO_MEM;
            break;
        }
    }
    esp_http_client_close(client);
    esp_http_client_cleanup(client);
    unsigned char hash[32];
    mbedtls_sha256_finish(&sha, hash);
    mbedtls_sha256_free(&sha);
    if (error != ESP_OK || !hash_matches(hash, sha256_hex)) {
        esp_ota_abort(handle);
        return error == ESP_OK ? ESP_ERR_INVALID_CRC : error;
    }

    unsigned char signature[512];
    size_t signature_length = 0;
    if (mbedtls_base64_decode(signature, sizeof(signature), &signature_length, (const unsigned char *) signature_b64, strlen(signature_b64)) != 0) {
        esp_ota_abort(handle);
        return ESP_ERR_INVALID_CRC;
    }
    mbedtls_pk_context key;
    mbedtls_pk_init(&key);
    if (mbedtls_pk_parse_public_key(&key, (const unsigned char *) HOMEDISPLAY_OTA_PUBLIC_KEY, strlen(HOMEDISPLAY_OTA_PUBLIC_KEY) + 1) != 0 ||
        mbedtls_pk_verify(&key, MBEDTLS_MD_SHA256, hash, sizeof(hash), signature, signature_length) != 0) {
        mbedtls_pk_free(&key);
        esp_ota_abort(handle);
        return ESP_ERR_INVALID_CRC;
    }
    mbedtls_pk_free(&key);
    error = esp_ota_end(handle);
    if (error != ESP_OK) return error;
    error = esp_ota_set_boot_partition(slot);
    if (error != ESP_OK) return error;
    ESP_LOGI(TAG, "Firmware geprüft, Neustart");
    esp_restart();
    return ESP_OK;
}
