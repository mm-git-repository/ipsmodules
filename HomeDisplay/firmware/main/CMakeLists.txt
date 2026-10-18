idf_component_register(
    SRCS "main.c" "board.c" "client.c" "ui.c" "ota.c"
    INCLUDE_DIRS "."
    REQUIRES esp_lcd esp_lcd_touch esp_lcd_touch_gt911 esp_lvgl_port esp_io_expander esp_io_expander_ch422g esp_wifi esp_http_client nvs_flash mdns json app_update mbedtls esp_timer spiffs
)
