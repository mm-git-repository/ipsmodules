#include "homedisplay.h"

#include "cJSON.h"
#include "esp_lcd_panel_ops.h"
#include "esp_lvgl_port.h"
#include "esp_timer.h"
#include "lvgl.h"

#include <stdint.h>
#include <stdio.h>
#include <string.h>

#define TILE_MAX 48

typedef struct {
    char tile_id[40];
    char action_id[40];
    char target_page[24];
    char title[48];
    int value;
    bool has_action;
    bool confirm;
    lv_obj_t *card;
    lv_obj_t *label;
} tile_view_t;

static lv_obj_t *status_label;
static lv_obj_t *page_bar;
static lv_obj_t *grid;
static lv_obj_t *clock_layer;
static lv_obj_t *clock_label;
static cJSON *layout_root;
static tile_view_t tiles[TILE_MAX];
static int tile_count;
static int page_index;
static int columns = 2;
static int idle_mode;
static int day_brightness = 70;
static int night_brightness = 15;
static int night_from = 22;
static int night_to = 6;
static bool locked;
static int64_t touched_at;
static int64_t clock_base;
static int64_t clock_set_at;
static char confirm_tile[40];
static int64_t confirm_at;
static homedisplay_action_fn action_callback;

static const char *icon_symbol(const char *icon)
{
    if (!icon) return LV_SYMBOL_MINUS;
    if (strcmp(icon, "energy") == 0) return LV_SYMBOL_CHARGE;
    if (strcmp(icon, "temperature") == 0) return LV_SYMBOL_TINT;
    if (strcmp(icon, "light") == 0) return LV_SYMBOL_EYE_OPEN;
    if (strcmp(icon, "shutter") == 0) return LV_SYMBOL_UP;
    if (strcmp(icon, "pump") == 0) return LV_SYMBOL_LOOP;
    if (strcmp(icon, "valve") == 0) return LV_SYMBOL_MINUS;
    if (strcmp(icon, "alarm") == 0) return LV_SYMBOL_WARNING;
    if (strcmp(icon, "home") == 0) return LV_SYMBOL_HOME;
    if (strcmp(icon, "clock") == 0) return LV_SYMBOL_BELL;
    if (strcmp(icon, "info") == 0) return LV_SYMBOL_LIST;
    return LV_SYMBOL_MINUS;
}

static void note_touch(void)
{
    touched_at = esp_timer_get_time();
}

static void tile_event(lv_event_t *event)
{
    if (locked) return;
    note_touch();
    tile_view_t *tile = lv_event_get_user_data(event);
    if (!tile || !tile->has_action || !action_callback) return;
    int64_t now = esp_timer_get_time();
    if (tile->confirm && (strcmp(confirm_tile, tile->tile_id) != 0 || now - confirm_at > 4000000)) {
        snprintf(confirm_tile, sizeof(confirm_tile), "%s", tile->tile_id);
        confirm_at = now;
        lv_label_set_text(tile->label, "Nochmal tippen");
        return;
    }
    confirm_tile[0] = '\0';
    lv_label_set_text(tile->label, "wird ausgeführt");
    action_callback(tile->tile_id, tile->action_id, tile->value);
}

static void show_page(int index);

static void page_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    show_page((int) (intptr_t) lv_event_get_user_data(event));
}

static int page_by_id(const char *id)
{
    cJSON *pages = layout_root ? cJSON_GetObjectItem(layout_root, "pages") : NULL;
    int count = cJSON_GetArraySize(pages);
    for (int index = 0; index < count; index++) {
        const char *candidate = cJSON_GetStringValue(cJSON_GetObjectItem(cJSON_GetArrayItem(pages, index), "id"));
        if (candidate && id && strcmp(candidate, id) == 0) return index;
    }
    return 0;
}

static void nav_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    tile_view_t *tile = lv_event_get_user_data(event);
    if (tile) show_page(page_by_id(tile->target_page));
}

static int json_int(cJSON *object, const char *key, int fallback)
{
    cJSON *item = cJSON_GetObjectItem(object, key);
    return cJSON_IsNumber(item) ? item->valueint : fallback;
}

static void show_page(int index)
{
    cJSON *pages = layout_root ? cJSON_GetObjectItem(layout_root, "pages") : NULL;
    int count = cJSON_GetArraySize(pages);
    if (count <= 0) return;
    if (index < 0 || index >= count) index = 0;
    page_index = index;
    cJSON *page = cJSON_GetArrayItem(pages, index);
    bool show_tabs = !cJSON_IsFalse(cJSON_GetObjectItem(layout_root, "showTabs"));
    lv_obj_clean(page_bar);
    lv_obj_clean(grid);
    tile_count = 0;
    if (show_tabs) {
        lv_obj_clear_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
        lv_obj_set_size(grid, 980, 500);
        for (int item = 0; item < count; item++) {
            cJSON *candidate = cJSON_GetArrayItem(pages, item);
            lv_obj_t *button = lv_btn_create(page_bar);
            lv_obj_t *caption = lv_label_create(button);
            const char *title = cJSON_GetStringValue(cJSON_GetObjectItem(candidate, "title"));
            lv_label_set_text(caption, title ? title : "");
            if (item == index) lv_obj_set_style_bg_color(button, lv_color_hex(0xFFB547), 0);
            lv_obj_add_event_cb(button, page_event, LV_EVENT_CLICKED, (void *) (intptr_t) item);
        }
    } else {
        lv_obj_add_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
        lv_obj_set_size(grid, 980, 556);
    }
    columns = json_int(layout_root, "columns", 2);
    if (columns < 1) columns = 1;
    if (columns > 4) columns = 4;
    int rows = json_int(layout_root, "rows", 4);
    if (rows < 1) rows = 1;
    if (rows > 6) rows = 6;
    int grid_h = show_tabs ? 500 : 556;
    int cell_w = 960 / columns;
    int cell_h = (grid_h - 20) / rows;
    cJSON *tile = NULL;
    cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
        if (tile_count >= TILE_MAX) break;
        tile_view_t *view = &tiles[tile_count++];
        memset(view, 0, sizeof(*view));
        const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
        const char *tile_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
        const char *action_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "actionId"));
        const char *title = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "title"));
        const char *icon = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "icon"));
        if (!kind) kind = "value";
        snprintf(view->tile_id, sizeof(view->tile_id), "%s", tile_id ? tile_id : "");
        snprintf(view->title, sizeof(view->title), "%s", title ? title : "");
        view->confirm = cJSON_IsTrue(cJSON_GetObjectItem(tile, "confirm"));
        int col = json_int(tile, "col", 0);
        int row = json_int(tile, "row", 0);
        int col_span = json_int(tile, "colSpan", 1);
        int row_span = json_int(tile, "rowSpan", 1);
        if (col_span < 1) col_span = 1;
        if (row_span < 1) row_span = 1;
        view->card = lv_obj_create(grid);
        lv_obj_set_pos(view->card, col * cell_w, row * cell_h);
        lv_obj_set_size(view->card, cell_w * col_span - 8, cell_h * row_span - 8);
        lv_obj_set_style_bg_color(view->card, lv_color_hex(0x1D3350), 0);
        lv_obj_set_style_border_width(view->card, 0, 0);
        lv_obj_set_style_text_color(view->card, lv_color_hex(0xF4F7FB), 0);
        if (strcmp(kind, "nav") == 0) {
            const char *target = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "targetPage"));
            snprintf(view->target_page, sizeof(view->target_page), "%s", target ? target : "");
            lv_obj_set_style_bg_color(view->card, lv_color_hex(0x2A4F7C), 0);
            lv_obj_t *name = lv_label_create(view->card);
            char caption[80];
            snprintf(caption, sizeof(caption), "%s %s", icon && icon[0] ? icon_symbol(icon) : LV_SYMBOL_RIGHT, view->title);
            lv_label_set_text(name, caption);
            lv_obj_set_style_text_font(name, &lv_font_montserrat_28, 0);
            lv_obj_center(name);
            lv_obj_add_event_cb(view->card, nav_event, LV_EVENT_CLICKED, view);
            continue;
        }
        if (strcmp(kind, "label") == 0) {
            const char *text = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "text"));
            const char *size = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "size"));
            lv_obj_set_style_bg_opa(view->card, LV_OPA_TRANSP, 0);
            lv_obj_t *label = lv_label_create(view->card);
            lv_label_set_text(label, text ? text : "");
            lv_label_set_long_mode(label, LV_LABEL_LONG_WRAP);
            lv_obj_set_width(label, cell_w * col_span - 32);
            if (size && strcmp(size, "large") == 0) lv_obj_set_style_text_font(label, &lv_font_montserrat_28, 0);
            lv_obj_align(label, LV_ALIGN_LEFT_MID, 0, 0);
            continue;
        }
        if (strcmp(kind, "separator") == 0) {
            lv_obj_set_style_bg_opa(view->card, LV_OPA_TRANSP, 0);
            lv_obj_t *line = lv_obj_create(view->card);
            lv_obj_set_size(line, cell_w * col_span - 40, 2);
            lv_obj_set_style_bg_color(line, lv_color_hex(0x5A7391), 0);
            lv_obj_set_style_border_width(line, 0, 0);
            lv_obj_center(line);
            continue;
        }
        if (strcmp(kind, "image") == 0) {
            const char *file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageFile"));
            lv_obj_set_style_bg_color(view->card, lv_color_hex(0x0B1626), 0);
            lv_obj_set_style_pad_all(view->card, 0, 0);
            lv_obj_t *image = lv_img_create(view->card);
            char path[80];
            snprintf(path, sizeof(path), "S:/%s", file ? file : "");
            lv_img_set_src(image, path);
            lv_obj_center(image);
            continue;
        }
        view->has_action = action_id && action_id[0];
        if (view->has_action) snprintf(view->action_id, sizeof(view->action_id), "%s", action_id);
        lv_obj_t *symbol = lv_label_create(view->card);
        lv_label_set_text(symbol, icon_symbol(icon));
        lv_obj_align(symbol, LV_ALIGN_LEFT_MID, 8, 0);
        lv_obj_t *name = lv_label_create(view->card);
        lv_label_set_text(name, view->title);
        lv_obj_align(name, LV_ALIGN_TOP_LEFT, 36, 8);
        view->label = lv_label_create(view->card);
        lv_label_set_text(view->label, "—");
        lv_obj_set_style_text_font(view->label, &lv_font_montserrat_28, 0);
        lv_obj_align(view->label, LV_ALIGN_BOTTOM_LEFT, 36, -8);
        if (view->has_action) lv_obj_add_event_cb(view->card, tile_event, LV_EVENT_CLICKED, view);
    }
    lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
}

static void apply_backlight(void)
{
    if (idle_mode == 2 && homedisplay_ui_busy() == false && esp_timer_get_time() - touched_at > 60000000) {
        homedisplay_board_backlight(false);
        return;
    }
    homedisplay_board_backlight(true);
}

static void clock_tick(lv_timer_t *timer)
{
    (void) timer;
    int64_t now = clock_base + (esp_timer_get_time() - clock_set_at) / 1000000;
    if (now < 0) now = 0;
    int seconds = (int) (now % 86400);
    int hour = seconds / 3600;
    bool night = night_from == night_to || (night_from < night_to ? hour >= night_from && hour < night_to : hour >= night_from || hour < night_to);
    (void) night;
    (void) day_brightness;
    (void) night_brightness;
    if (locked || !layout_root) {
        apply_backlight();
        return;
    }
    bool idle = esp_timer_get_time() - touched_at > 60000000;
    if (idle && idle_mode == 0) {
        char line[16];
        snprintf(line, sizeof(line), "%02d:%02d", hour, (seconds / 60) % 60);
        lv_label_set_text(clock_label, line);
        lv_obj_clear_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
    } else if (idle_mode != 0) {
        lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
    }
    apply_backlight();
}

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
    lv_obj_set_style_text_font(status_label, &lv_font_montserrat_20, 0);
    lv_label_set_text(status_label, "HomeDisplay sucht den Adapter");
    lv_obj_align(status_label, LV_ALIGN_TOP_MID, 0, 4);
    page_bar = lv_obj_create(screen);
    lv_obj_set_size(page_bar, 980, 48);
    lv_obj_align(page_bar, LV_ALIGN_TOP_MID, 0, 32);
    lv_obj_set_flex_flow(page_bar, LV_FLEX_FLOW_ROW);
    lv_obj_set_style_bg_opa(page_bar, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(page_bar, 0, 0);
    grid = lv_obj_create(screen);
    lv_obj_set_size(grid, 980, 500);
    lv_obj_align(grid, LV_ALIGN_BOTTOM_MID, 0, -8);
    lv_obj_set_style_bg_opa(grid, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(grid, 0, 0);
    clock_layer = lv_obj_create(screen);
    lv_obj_set_size(clock_layer, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES);
    lv_obj_set_style_bg_color(clock_layer, lv_color_hex(0x102033), 0);
    clock_label = lv_label_create(clock_layer);
    lv_obj_set_style_text_font(clock_label, &lv_font_montserrat_28, 0);
    lv_obj_set_style_text_color(clock_label, lv_color_hex(0xF4F7FB), 0);
    lv_label_set_text(clock_label, "--:--");
    lv_obj_center(clock_label);
    lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
    lv_obj_add_event_cb(clock_layer, page_event, LV_EVENT_CLICKED, (void *) (intptr_t) 0);
    lvgl_port_unlock();
    touched_at = esp_timer_get_time();
    lv_timer_create(clock_tick, 1000, NULL);
}

void homedisplay_ui_status(const char *text)
{
    if (!status_label || !text) return;
    lvgl_port_lock(0);
    lv_label_set_text(status_label, text);
    lvgl_port_unlock();
}

void homedisplay_ui_apply_layout(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root) return;
    if (layout_root) cJSON_Delete(layout_root);
    layout_root = root;
    lvgl_port_lock(0);
    show_page(0);
    lvgl_port_unlock();
}

void homedisplay_ui_apply_config(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root) return;
    cJSON *item = cJSON_GetObjectItem(root, "idleMode");
    if (item) idle_mode = item->valueint;
    item = cJSON_GetObjectItem(root, "dayBrightness");
    if (item) day_brightness = item->valueint;
    item = cJSON_GetObjectItem(root, "nightBrightness");
    if (item) night_brightness = item->valueint;
    item = cJSON_GetObjectItem(root, "nightFrom");
    if (item) night_from = item->valueint;
    item = cJSON_GetObjectItem(root, "nightTo");
    if (item) night_to = item->valueint;
    cJSON_Delete(root);
    if (day_brightness <= 0 && night_brightness <= 0) homedisplay_board_backlight(false);
}

void homedisplay_ui_apply_state(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root) return;
    if (cJSON_IsTrue(cJSON_GetObjectItem(root, "unchanged"))) {
        cJSON_Delete(root);
        return;
    }
    cJSON *now = cJSON_GetObjectItem(root, "now");
    if (now) homedisplay_ui_set_time((int64_t) now->valuedouble);
    lvgl_port_lock(0);
    cJSON *tile = NULL;
    cJSON_ArrayForEach(tile, cJSON_GetObjectItem(root, "tiles")) {
        const char *tile_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
        const char *text = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "text"));
        if (!tile_id) continue;
        for (int index = 0; index < tile_count; index++) {
            if (strcmp(tiles[index].tile_id, tile_id) == 0 && tiles[index].label) {
                lv_label_set_text(tiles[index].label, text ? text : "—");
            }
        }
    }
    lvgl_port_unlock();
    cJSON_Delete(root);
}

void homedisplay_ui_set_time(int64_t unix_time)
{
    clock_base = unix_time;
    clock_set_at = esp_timer_get_time();
}

void homedisplay_ui_set_action(homedisplay_action_fn callback)
{
    action_callback = callback;
}

void homedisplay_ui_lock(bool value)
{
    locked = value;
}

bool homedisplay_ui_busy(void)
{
    return locked || esp_timer_get_time() - touched_at < 5000000;
}
