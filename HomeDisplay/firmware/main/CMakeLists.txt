idf_component_register(
    SRCS "main.c" "board.c" "ch422g.c" "net.c" "client.c" "ui.c" "ota.c" "serial_menu.c"
        "fonts/montserrat_bold_20.c" "fonts/montserrat_bold_28.c" "fonts/montserrat_bold_36.c" "fonts/montserrat_bold_48.c"
        "fonts/montserrat_italic_20.c" "fonts/montserrat_italic_28.c" "fonts/montserrat_italic_36.c" "fonts/montserrat_italic_48.c"
        "fonts/montserrat_bolditalic_20.c" "fonts/montserrat_bolditalic_28.c" "fonts/montserrat_bolditalic_36.c" "fonts/montserrat_bolditalic_48.c"
        "fonts/mdi_28.c" "fonts/mdi_48.c"
    INCLUDE_DIRS "."
    REQUIRES esp_lcd esp_lcd_touch esp_lcd_touch_gt911 esp_lvgl_port esp_wifi esp_http_client esp_http_server nvs_flash mdns json app_update mbedtls esp_timer spiffs esp_driver_i2c esp_driver_usb_serial_jtag
)

target_compile_definitions(${COMPONENT_LIB} PRIVATE LV_LVGL_H_INCLUDE_SIMPLE)
