#pragma once

#include "esp_err.h"
#include "esp_lcd_panel_ops.h"
#include "esp_lcd_touch.h"

#define HOMEDISPLAY_FIRMWARE_VERSION "0.1.0"
#define HOMEDISPLAY_LCD_H_RES 1024
#define HOMEDISPLAY_LCD_V_RES 600

esp_err_t homedisplay_board_init(esp_lcd_panel_handle_t *panel, esp_lcd_touch_handle_t *touch);
void homedisplay_ui_init(esp_lcd_panel_handle_t panel, esp_lcd_touch_handle_t touch);
void homedisplay_ui_status(const char *text);
void homedisplay_ui_apply_state(const char *json);
void homedisplay_client_start(void);
