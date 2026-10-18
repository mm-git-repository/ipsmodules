#include "homedisplay.h"

#include <string.h>

#include "esp_event.h"
#include "esp_log.h"
#include "esp_netif.h"
#include "esp_wifi.h"
#include "nvs_flash.h"

static const char *TAG = "main";

static void wifi_start(void)
{
    esp_netif_init();
    esp_event_loop_create_default();
    esp_netif_create_default_wifi_sta();
    wifi_init_config_t config = WIFI_INIT_CONFIG_DEFAULT();
    esp_wifi_init(&config);
    wifi_config_t wifi = {0};
    strncpy((char *) wifi.sta.ssid, CONFIG_HOMEDISPLAY_WIFI_SSID, sizeof(wifi.sta.ssid) - 1);
    strncpy((char *) wifi.sta.password, CONFIG_HOMEDISPLAY_WIFI_PASSWORD, sizeof(wifi.sta.password) - 1);
    esp_wifi_set_mode(WIFI_MODE_STA);
    esp_wifi_set_config(WIFI_IF_STA, &wifi);
    esp_wifi_start();
    esp_wifi_connect();
}

void app_main(void)
{
    esp_err_t error = nvs_flash_init();
    if (error == ESP_ERR_NVS_NO_FREE_PAGES || error == ESP_ERR_NVS_NEW_VERSION_FOUND) {
        ESP_ERROR_CHECK(nvs_flash_erase());
        ESP_ERROR_CHECK(nvs_flash_init());
    }
    esp_lcd_panel_handle_t panel = NULL;
    esp_lcd_touch_handle_t touch = NULL;
    ESP_ERROR_CHECK(homedisplay_board_init(&panel, &touch));
    homedisplay_ui_init(panel, touch);
    if (strlen(CONFIG_HOMEDISPLAY_WIFI_SSID) == 0) {
        homedisplay_ui_status("WLAN in menuconfig eintragen");
        return;
    }
    wifi_start();
    ESP_LOGI(TAG, "Firmware %s", HOMEDISPLAY_FIRMWARE_VERSION);
    homedisplay_client_start();
}
