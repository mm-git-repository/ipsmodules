idf_component_register(
    SRCS "main.c" "board.c" "ch422g.c" "net.c" "client.c" "ui.c" "ota.c"
    INCLUDE_DIRS "."
    REQUIRES esp_lcd esp_lcd_touch esp_lcd_touch_gt911 esp_lvgl_port esp_wifi esp_http_client esp_http_server nvs_flash mdns json app_update mbedtls esp_timer spiffs esp_driver_i2c
)
