#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "esp_err.h"
#include "esp_lcd_panel_ops.h"
#include "esp_lcd_touch.h"

#define HOMEDISPLAY_FIRMWARE_VERSION "0.2.0"
#define HOMEDISPLAY_LCD_H_RES 1024
#define HOMEDISPLAY_LCD_V_RES 600

typedef void (*homedisplay_action_fn)(const char *tile_id, const char *action_id, int value);

esp_err_t homedisplay_board_init(esp_lcd_panel_handle_t *panel, esp_lcd_touch_handle_t *touch);
void homedisplay_board_backlight(bool on);
void homedisplay_ui_init(esp_lcd_panel_handle_t panel, esp_lcd_touch_handle_t touch);
void homedisplay_ui_status(const char *text);
void homedisplay_ui_apply_layout(const char *json);
void homedisplay_ui_apply_config(const char *json);
void homedisplay_ui_apply_state(const char *json);
void homedisplay_ui_set_time(int64_t unix_time);
void homedisplay_ui_set_action(homedisplay_action_fn callback);
void homedisplay_ui_lock(bool locked);
bool homedisplay_ui_busy(void);
void homedisplay_client_start(void);
esp_err_t homedisplay_ota_run(const char *url, const char *token, const char *sha256_hex, const char *signature_b64, int *progress);
