#include "homedisplay.h"

#include "cJSON.h"
#include "esp_lcd_panel_ops.h"
#include "esp_lvgl_port.h"
#include "lvgl.h"

static lv_obj_t *status_label;
static lv_obj_t *grid;

void homedisplay_ui_init(esp_lcd_panel_handle_t panel, esp_lcd_touch_handle_t touch)
{
    const lvgl_port_cfg_t port_config = ESP_LVGL_PORT_INIT_CONFIG();
    lvgl_port_init(&port_config);
    const lvgl_port_display_cfg_t display_config = {
        .panel_handle = panel,
        .buffer_size = HOMEDISPLAY_LCD_H_RES * 40,
        .double_buffer = false,
        .hres = HOMEDISPLAY_LCD_H_RES,
        .vres = HOMEDISPLAY_LCD_V_RES,
        .flags = {.buff_spiram = true},
    };
    const lvgl_port_display_rgb_cfg_t rgb_config = {};
    lv_display_t *display = lvgl_port_add_disp_rgb(&display_config, &rgb_config);
    const lvgl_port_touch_cfg_t touch_config = {.disp = display, .handle = touch};
    lvgl_port_add_touch(&touch_config);

    lvgl_port_lock(0);
    lv_obj_t *screen = lv_scr_act();
    lv_obj_set_style_bg_color(screen, lv_color_hex(0x102033), 0);
    status_label = lv_label_create(screen);
    lv_obj_set_style_text_color(status_label, lv_color_hex(0xF4F7FB), 0);
    lv_obj_set_style_text_font(status_label, &lv_font_montserrat_28, 0);
    lv_label_set_text(status_label, "HomeDisplay\nSuche Adapter");
    lv_obj_align(status_label, LV_ALIGN_TOP_MID, 0, 24);
    grid = lv_obj_create(screen);
    lv_obj_set_size(grid, 980, 500);
    lv_obj_align(grid, LV_ALIGN_BOTTOM_MID, 0, -12);
    lv_obj_set_style_bg_opa(grid, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(grid, 0, 0);
    lv_obj_set_flex_flow(grid, LV_FLEX_FLOW_ROW_WRAP);
    lvgl_port_unlock();
}

void homedisplay_ui_status(const char *text)
{
    if (!status_label) {
        return;
    }
    lvgl_port_lock(0);
    lv_label_set_text(status_label, text);
    lvgl_port_unlock();
}

void homedisplay_ui_apply_state(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root || !grid) {
        cJSON_Delete(root);
        return;
    }
    cJSON *tiles = cJSON_GetObjectItem(root, "tiles");
    lvgl_port_lock(0);
    lv_obj_clean(grid);
    cJSON *tile = NULL;
    cJSON_ArrayForEach(tile, tiles) {
        const char *text = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "text"));
        lv_obj_t *card = lv_obj_create(grid);
        lv_obj_set_size(card, 300, 120);
        lv_obj_t *label = lv_label_create(card);
        lv_label_set_text(label, text ? text : "—");
        lv_obj_center(label);
    }
    lvgl_port_unlock();
    cJSON_Delete(root);
}
