#include "homedisplay.h"
#include "serial_menu.h"

#include <string.h>

#include "esp_log.h"
#include "esp_spiffs.h"
#include "esp_system.h"
#include "esp_task_wdt.h"
#include "nvs_flash.h"

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"

static const char *TAG = "main";

void app_main(void)
{
    esp_err_t error = nvs_flash_init();
    if (error == ESP_ERR_NVS_NO_FREE_PAGES || error == ESP_ERR_NVS_NEW_VERSION_FOUND) {
        ESP_ERROR_CHECK(nvs_flash_erase());
        ESP_ERROR_CHECK(nvs_flash_init());
    }
    esp_vfs_spiffs_conf_t storage = {
        .base_path = "/storage",
        .partition_label = "storage",
        .max_files = 8,
        .format_if_mount_failed = true,
    };
    if (esp_vfs_spiffs_register(&storage) != ESP_OK) {
        ESP_LOGW(TAG, "Bildspeicher konnte nicht eingebunden werden");
    }
    homedisplay_serial_console_init();

    /* Task-Watchdog: 60 s (Layout/Bilder können länger brauchen). IDF initialisiert oft schon. */
    esp_task_wdt_config_t watchdog = {.timeout_ms = 60000, .idle_core_mask = 0, .trigger_panic = true};
    if (esp_task_wdt_reconfigure(&watchdog) == ESP_ERR_INVALID_STATE) {
        esp_task_wdt_init(&watchdog);
    }

    esp_lcd_panel_handle_t panel = NULL;
    esp_lcd_touch_handle_t touch = NULL;
    ESP_ERROR_CHECK(homedisplay_board_init(&panel, &touch));
    homedisplay_ui_init(panel, touch);
    ESP_LOGI(TAG, "Firmware %s, Reset-Grund %d", HOMEDISPLAY_FIRMWARE_VERSION, (int) esp_reset_reason());
    homedisplay_ui_status("Start\nFirmware " HOMEDISPLAY_FIRMWARE_VERSION);
    homedisplay_serial_boot_window(8000);

    int64_t rtc_time = 0;
    if (homedisplay_rtc_read(&rtc_time) == ESP_OK) {
        homedisplay_ui_set_time(rtc_time);
    }

    if (CONFIG_HOMEDISPLAY_BOOT_TEST_SECONDS > 0) {
        homedisplay_ui_test_pattern(true);
    }

    char ssid[33];
    char password[65];
    homedisplay_nvs_get("ssid", ssid, sizeof(ssid));
    homedisplay_nvs_get("pass", password, sizeof(password));
    if (ssid[0] == '\0' && strlen(CONFIG_HOMEDISPLAY_WIFI_SSID) > 0) {
        snprintf(ssid, sizeof(ssid), "%s", CONFIG_HOMEDISPLAY_WIFI_SSID);
        snprintf(password, sizeof(password), "%s", CONFIG_HOMEDISPLAY_WIFI_PASSWORD);
    }
    if (ssid[0] == '\0') {
        homedisplay_ui_status("Einrichtung\nSerial: m + Enter oder Setup-WLAN");
        if (homedisplay_serial_run_menu(false)) {
            esp_restart();
        }
        homedisplay_provision_start();
        return;
    }
    char wlan_line[96];
    snprintf(wlan_line, sizeof(wlan_line), "WLAN verbinden\n%s", ssid);
    homedisplay_ui_status(wlan_line);
    homedisplay_wifi_start(ssid, password);
    homedisplay_client_start();
}
