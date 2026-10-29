#include "homedisplay.h"
#include "fonts.h"

#include "cJSON.h"
#include "esp_lcd_panel_ops.h"
#include "esp_log.h"
#include "esp_lvgl_port.h"
#include "esp_netif.h"
#include "esp_system.h"
#include "esp_task_wdt.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "lvgl.h"

#include <math.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>

static const char *TAG_UI = "ui";

#define TILE_MAX 48
#define HEADER_H 68
#define HEADER_LEFT 72
#define HEADER_RIGHT 160
#define TAB_H 56
#define TAB_W 132

/* true nur wenn show_page vom Client-Task kommt – dann LVGL-Lock zwischen Kacheln freigeben. */
static bool show_page_may_yield;
static bool show_page_lock_lost;
#define CHART_SLOT_MAX 8
#define CHART_POINT_MAX 56
#define UI_CLIENT_LOCK_MS 2000
static lv_point_t chart_point_pool[CHART_SLOT_MAX][CHART_POINT_MAX];
static int chart_slot_count;
static bool color_mono;
static uint32_t theme_bg = 0x102033;
static uint32_t theme_button = 0x2A4F7C;
static uint32_t theme_accent = 0xFFB547;
static int64_t idle_timeout_us = 60000000;

typedef struct {
    char tile_id[40];
    char action_id[40];
    char target_page[24];
    char title[48];
    char control[16];
    int value;
    int value_min;
    int value_max;
    int value_on;
    int value_off;
    int level;
    bool has_action;
    bool confirm;
    bool applying;
    lv_obj_t *card;
    lv_obj_t *label;
    lv_obj_t *track;
    lv_obj_t *fill;
    lv_obj_t *knob;
    lv_obj_t *power;
    lv_obj_t *battery_shell;
    lv_obj_t *battery_fill;
    lv_obj_t *camera_img;
    lv_obj_t *image_img;
    lv_obj_t *chart_line;
    lv_point_t *chart_points;
    int chart_w;
    int chart_h;
    lv_obj_t *icon_label;
    lv_obj_t *title_icon;
    bool icon_large;
    char icon_name[24];
    char icon_mode[12];
    char icon_on[24];
    char icon_off[24];
    int battery_pad;
    uint32_t base_bg;
    uint32_t on_bg;
    uint32_t text_color;
    bool own_on;
    bool own_text;
    bool show_icon;
    bool show_state_text;
    bool dim_show_switch;
    bool dim_show_bar;
    bool dim_show_percent;
    bool dim_show_state_text;
    bool has_threshold;
    char dim_tap[8];
    char text_size[8];
    char status_color[8];
    float threshold;
    bool holding;
    int held_value;
    int64_t held_until;
    int64_t last_tap_at;
    bool text_bold;
    bool text_italic;
    bool text_underline;
} tile_view_t;

static void ui_wdt_kick(void)
{
    if (esp_task_wdt_status(NULL) == ESP_OK) {
        esp_task_wdt_reset();
    }
}

/* Client wartet höchstens 2 s. Sonst Watchdog füttern und dieses Update auslassen. */
static bool ui_client_lock(void)
{
    ui_wdt_kick();
    if (lvgl_port_lock(UI_CLIENT_LOCK_MS)) return true;
    ui_wdt_kick();
    ESP_LOGW(TAG_UI, "Anzeige belegt, Update ausgelassen");
    return false;
}

static uint32_t kind_bg(const char *kind)
{
    if (color_mono) {
        if (kind && (strcmp(kind, "camera") == 0 || strcmp(kind, "image") == 0)) return 0x101010;
        if (kind && strcmp(kind, "nav") == 0) return 0x2A2A2A;
        return 0x1A1A1A;
    }
    if (!kind) return 0x1D3350;
    if (strcmp(kind, "nav") == 0) return theme_button;
    if (strcmp(kind, "display") == 0 || strcmp(kind, "scene") == 0 || strcmp(kind, "value") == 0) return 0x1D3350;
    if (strcmp(kind, "weather") == 0) return 0x1A3348;
    if (strcmp(kind, "chart") == 0) return 0x1A2D45;
    if (strcmp(kind, "presence") == 0) return 0x1F2D3F;
    if (strcmp(kind, "energyflow") == 0) return 0x152238;
    if (strcmp(kind, "camera") == 0 || strcmp(kind, "image") == 0) return 0x0B1626;
    return 0x1D3350;
}

static uint32_t tile_on_color(const tile_view_t *tile)
{
    if (color_mono) return 0xFFFFFF;
    if (!tile) return 0;
    /* Ohne gesetzte "Farbe Ein" bleibt der Schalter unverändert, egal ob Ein oder Aus. */
    return tile->own_on ? tile->on_bg : tile->base_bg;
}
static uint32_t accent_tab(void) { return color_mono ? 0xFFFFFF : theme_accent; }
static uint32_t accent_tab_bg(void) { return color_mono ? 0x333333 : theme_button; }
static uint32_t text_fg(void) { return color_mono ? 0xFFFFFF : 0xF4F7FB; }

static void fold_german(char *dst, size_t dst_len, const char *src)
{
    if (!dst || dst_len == 0) return;
    size_t o = 0;
    if (!src) { dst[0] = '\0'; return; }
    for (const unsigned char *p = (const unsigned char *)src; *p && o + 1 < dst_len; ) {
        if (p[0] == 0xC3) {
            unsigned char c = p[1];
            const char *rep = NULL;
            if (c == 0xA4 || c == 0x84) rep = (c == 0x84) ? "Ae" : "ae";
            else if (c == 0xB6 || c == 0x96) rep = (c == 0x96) ? "Oe" : "oe";
            else if (c == 0xBC || c == 0x9C) rep = (c == 0x9C) ? "Ue" : "ue";
            else if (c == 0x9F) rep = "ss";
            if (rep) {
                for (const char *r = rep; *r && o + 1 < dst_len; r++) dst[o++] = *r;
                p += 2;
                continue;
            }
        }
        if ((p[0] & 0xE0) == 0xC0 && !p[1]) break;
        if ((p[0] & 0xF0) == 0xE0 && (!p[1] || !p[2])) break;
        if ((p[0] & 0xF8) == 0xF0 && (!p[1] || !p[2] || !p[3])) break;
        dst[o++] = (char)*p++;
    }
    dst[o] = '\0';
}

static void label_set_folded(lv_obj_t *label, const char *src)
{
    char buf[320];
    fold_german(buf, sizeof(buf), src ? src : "");
    lv_label_set_text(label, buf);
}

static void style_flat(lv_obj_t *obj)
{
    if (!obj) return;
    lv_obj_set_style_border_width(obj, 0, LV_PART_MAIN);
    lv_obj_set_style_outline_width(obj, 0, LV_PART_MAIN);
    lv_obj_set_style_shadow_width(obj, 0, LV_PART_MAIN);
    lv_obj_set_style_border_width(obj, 0, LV_PART_SCROLLBAR);
    lv_obj_set_style_bg_opa(obj, LV_OPA_TRANSP, LV_PART_SCROLLBAR);
    lv_obj_set_scrollbar_mode(obj, LV_SCROLLBAR_MODE_OFF);
    lv_obj_clear_flag(obj, LV_OBJ_FLAG_SCROLLABLE);
}

/* Status als leichte Flächenfarbe – keine Rahmenlinien am linken Rand. */
static void apply_status_color(tile_view_t *view, const char *color)
{
    if (!view || !view->card) return;
    uint32_t bg = view->base_bg;
    if (color && strcmp(color, "ok") == 0) bg = 0x1E6B3A;
    else if (color && strcmp(color, "warn") == 0) bg = 0x8A6A12;
    else if (color && strcmp(color, "alarm") == 0) bg = 0x8A2424;
    lv_obj_set_style_bg_color(view->card, lv_color_hex(bg), 0);
    style_flat(view->card);
}

static lv_obj_t *status_label;
static lv_obj_t *info_label;
static lv_obj_t *boot_layer;
static lv_obj_t *boot_title;
static lv_obj_t *boot_detail;
static lv_obj_t *boot_code;
static lv_obj_t *boot_footer;
static bool boot_screen_active = true;
static bool boot_layer_on_top;
static char setup_pair_code[8];
static char last_boot_title[120];
static char last_boot_detail[280];
static char last_setup_detail[512];
static char last_info_line[96];
static char last_system_report[320];
static lv_obj_t *home_button;
static lv_obj_t *page_bar;
static lv_obj_t *grid;
static lv_obj_t *page_background;
static lv_obj_t *clock_layer;
static lv_obj_t *clock_label;
static lv_obj_t *test_layer;
static lv_obj_t *test_label;
static lv_obj_t *system_label;
static cJSON *layout_root;
static tile_view_t tiles[TILE_MAX];
static int tile_count;
static int page_index;
static int columns = 2;
static int idle_mode;
static char idle_clock_style[12] = "digital";
static bool idle_active;
static lv_obj_t *dim_popup;
static lv_obj_t *dim_slider;
static lv_obj_t *dim_title_label;
static tile_view_t *dim_tile;
static lv_obj_t *analog_clock;
static lv_obj_t *analog_hour;
static lv_obj_t *analog_min;
static lv_point_t analog_hour_pts[2];
static lv_point_t analog_min_pts[2];
static lv_point_t analog_tick_pts[12][2];
static char last_state_json[8192];
static volatile bool refresh_now;
static volatile bool backlight_on = true;
static int day_brightness = 70;
static int night_brightness = 15;
static int night_from = 22;
static int night_to = 6;
static bool locked;
static bool online = true;
static int wifi_rssi;
static int64_t offline_since;
static int64_t touched_at;
static int64_t clock_base;
static int64_t clock_set_at;
static char confirm_tile[40];
static int64_t confirm_at;
static uint32_t frame_counter;
static uint32_t touch_counter;

static bool is_pairing_code(const char *text)
{
    if (!text || strlen(text) != 6) {
        return false;
    }
    for (size_t index = 0; text[index]; index++) {
        char c = text[index];
        if ((c >= 'A' && c <= 'Z') || (c >= '2' && c <= '9')) {
            continue;
        }
        return false;
    }
    return true;
}

static void set_boot_message(const char *title, const char *detail)
{
    if (!boot_layer || !boot_title) {
        return;
    }
    const char *head = title && title[0] ? title : "Hausanzeige";
    const char *sub = detail ? detail : "";
    if (strcmp(last_boot_title, head) == 0 && strcmp(last_boot_detail, sub) == 0) {
        return;
    }
    snprintf(last_boot_title, sizeof(last_boot_title), "%s", head);
    snprintf(last_boot_detail, sizeof(last_boot_detail), "%s", sub);

    label_set_folded(boot_title, head);
    bool code_mode = is_pairing_code(sub);
    if (setup_pair_code[0] && boot_code) {
        lv_label_set_text(boot_code, setup_pair_code);
        lv_obj_clear_flag(boot_code, LV_OBJ_FLAG_HIDDEN);
        if (sub[0] && !code_mode) {
            label_set_folded(boot_detail, sub);
            lv_obj_clear_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
        } else if (code_mode) {
            lv_obj_add_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
        }
    } else if (boot_code) {
        if (code_mode) {
            lv_label_set_text(boot_code, sub);
            lv_obj_clear_flag(boot_code, LV_OBJ_FLAG_HIDDEN);
            lv_obj_add_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
        } else {
            lv_obj_add_flag(boot_code, LV_OBJ_FLAG_HIDDEN);
            if (sub[0]) {
                label_set_folded(boot_detail, sub);
                lv_obj_clear_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
            } else {
                lv_obj_add_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
            }
        }
    } else if (boot_detail) {
        label_set_folded(boot_detail, sub);
        if (sub[0]) {
            lv_obj_clear_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
        } else {
            lv_obj_add_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
        }
    }
    if (boot_screen_active) {
        lv_obj_clear_flag(boot_layer, LV_OBJ_FLAG_HIDDEN);
        if (!boot_layer_on_top) {
            lv_obj_move_foreground(boot_layer);
            boot_layer_on_top = true;
        }
    }
}

static void hide_boot_screen(void)
{
    boot_screen_active = false;
    boot_layer_on_top = false;
    last_boot_title[0] = last_boot_detail[0] = '\0';
    if (boot_layer) {
        lv_obj_add_flag(boot_layer, LV_OBJ_FLAG_HIDDEN);
    }
}

static void split_status_text(const char *text, char *title, size_t title_len, char *detail, size_t detail_len)
{
    title[0] = detail[0] = '\0';
    if (!text || !text[0]) {
        snprintf(title, title_len, "Hausanzeige");
        return;
    }
    const char *break_at = strchr(text, '\n');
    if (break_at) {
        size_t head = (size_t) (break_at - text);
        if (head >= title_len) {
            head = title_len - 1;
        }
        memcpy(title, text, head);
        title[head] = '\0';
        snprintf(detail, detail_len, "%s", break_at + 1);
    } else {
        snprintf(title, title_len, "%s", text);
    }
}

static void build_boot_layer(lv_obj_t *screen)
{
    boot_layer = lv_obj_create(screen);
    lv_obj_set_size(boot_layer, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES);
    lv_obj_set_style_bg_color(boot_layer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_width(boot_layer, 0, 0);
    lv_obj_set_style_pad_all(boot_layer, 0, 0);
    lv_obj_clear_flag(boot_layer, LV_OBJ_FLAG_SCROLLABLE);

    lv_obj_t *card = lv_obj_create(boot_layer);
    lv_obj_set_size(card, 920, 480);
    lv_obj_center(card);
    lv_obj_set_style_bg_color(card, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_color(card, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_border_width(card, 2, 0);
    lv_obj_set_style_radius(card, 12, 0);
    lv_obj_set_style_pad_all(card, 32, 0);
    lv_obj_set_flex_flow(card, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_flex_align(card, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_clear_flag(card, LV_OBJ_FLAG_SCROLLABLE);

    boot_title = lv_label_create(card);
    lv_obj_set_width(boot_title, 840);
    lv_label_set_long_mode(boot_title, LV_LABEL_LONG_WRAP);
    lv_obj_set_style_text_align(boot_title, LV_TEXT_ALIGN_CENTER, 0);
    lv_obj_set_style_text_color(boot_title, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(boot_title, &lv_font_montserrat_48, 0);
    lv_label_set_text(boot_title, "Hausanzeige startet");

    boot_code = lv_label_create(card);
    lv_obj_set_width(boot_code, 840);
    lv_obj_set_style_text_align(boot_code, LV_TEXT_ALIGN_CENTER, 0);
    lv_obj_set_style_text_color(boot_code, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(boot_code, &lv_font_montserrat_48, 0);
    lv_label_set_text(boot_code, "");
    lv_obj_add_flag(boot_code, LV_OBJ_FLAG_HIDDEN);

    boot_detail = lv_label_create(card);
    lv_obj_set_width(boot_detail, 840);
    lv_label_set_long_mode(boot_detail, LV_LABEL_LONG_WRAP);
    lv_obj_set_style_text_align(boot_detail, LV_TEXT_ALIGN_CENTER, 0);
    lv_obj_set_style_text_color(boot_detail, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(boot_detail, &lv_font_montserrat_36, 0);
    lv_obj_set_style_text_line_space(boot_detail, 10, 0);
    lv_label_set_text(boot_detail, "");

    boot_footer = lv_label_create(boot_layer);
    lv_obj_set_style_text_color(boot_footer, lv_color_hex(0xAAAAAA), 0);
    lv_obj_set_style_text_font(boot_footer, &lv_font_montserrat_28, 0);
    char footer[64];
    snprintf(footer, sizeof(footer), "Firmware %s", HOMEDISPLAY_FIRMWARE_VERSION);
    lv_label_set_text(boot_footer, footer);
    lv_obj_align(boot_footer, LV_ALIGN_BOTTOM_MID, 0, -28);
}
static homedisplay_action_fn action_callback;

typedef struct {
    const char *id;
    unsigned cp;
} icon_cp_t;

/* Pictogrammers Material Design Icons, Apache 2.0. Ausschnitt in fonts/mdi_28.c und mdi_48.c. */
static const icon_cp_t ICON_CP[] = {
    {"energy", 0xF140B}, {"temperature", 0xF050F}, {"shutter", 0xF111C}, {"pump", 0xF1402},
    {"valve", 0xF1066}, {"alarm", 0xF078F}, {"home", 0xF02DC}, {"clock", 0xF0150}, {"info", 0xF02FD},
    {"humidity", 0xF058E}, {"sun", 0xF0599}, {"moon", 0xF0594}, {"water", 0xF058C}, {"heat", 0xF0438},
    {"cool", 0xF0717}, {"fan", 0xF0210}, {"door", 0xF081A}, {"lock", 0xF033E}, {"plug", 0xF06A5},
    {"batteryIcon", 0xF0079}, {"wifi", 0xF05A9}, {"car", 0xF010B}, {"motion", 0xF0D91}, {"window", 0xF05AE},
    {"garage", 0xF06D9}, {"smoke", 0xF0392}, {"thermostat", 0xF0393}, {"solar", 0xF0A72}, {"camera", 0xF07AE},
    {"tv", 0xF0502}, {"speaker", 0xF04C3}, {"washing", 0xF072A}, {"bed", 0xF02E3}, {"bell", 0xF009A},
    {"leaf", 0xF032A}, {"fire", 0xF0238},
};

static const char *pua(unsigned cp)
{
    static char buf[5];
    buf[0] = (char) (0xF0 | (cp >> 18));
    buf[1] = (char) (0x80 | ((cp >> 12) & 0x3F));
    buf[2] = (char) (0x80 | ((cp >> 6) & 0x3F));
    buf[3] = (char) (0x80 | (cp & 0x3F));
    buf[4] = '\0';
    return buf;
}

static unsigned icon_code(const char *icon, bool on)
{
    if (icon && strcmp(icon, "light") == 0) return on ? 0xF0335 : 0xF0336;
    if (icon && strcmp(icon, "lightOn") == 0) return 0xF0335;
    if (icon && strcmp(icon, "lightOff") == 0) return 0xF0336;
    if (icon) {
        for (size_t i = 0; i < sizeof(ICON_CP) / sizeof(ICON_CP[0]); i++) {
            if (strcmp(icon, ICON_CP[i].id) == 0) return ICON_CP[i].cp;
        }
    }
    return 0xF02FD;
}

static uint32_t icon_ink(const tile_view_t *tile)
{
    if (tile && tile->own_text) return tile->text_color;
    return 0xF4F7FB;
}

/* Das Icon bleibt immer in der Schriftfarbe und liegt damit vor der Fläche "Farbe Ein". */
static void paint_icon_label(lv_obj_t *label, const char *icon, bool on, bool large, uint32_t ink)
{
    if (!label) return;
    lv_label_set_text(label, pua(icon_code(icon, on)));
    lv_obj_set_style_text_font(label, large ? &mdi_48 : &mdi_28, 0);
    lv_obj_set_style_text_color(label, lv_color_hex(ink), 0);
}

static void show_icon_label(lv_obj_t *label, const tile_view_t *tile, const char *icon, bool on, bool large)
{
    paint_icon_label(label, icon, on, large, icon_ink(tile));
}

static void refresh_icons(tile_view_t *tile, bool on)
{
    if (!tile) return;
    const char *shown = NULL;
    if (strcmp(tile->icon_mode, "toggle") == 0) shown = on ? tile->icon_on : tile->icon_off;
    else if (tile->icon_name[0]) shown = tile->icon_name;
    if (tile->icon_label && shown) show_icon_label(tile->icon_label, tile, shown, on, tile->icon_large);
    if (tile->title_icon && tile->icon_name[0] && strcmp(tile->icon_mode, "toggle") != 0) {
        show_icon_label(tile->title_icon, tile, tile->icon_name, on, false);
    }
}

static void hold_target(tile_view_t *tile, int value)
{
    if (!tile) return;
    tile->holding = true;
    tile->held_value = value;
    tile->held_until = esp_timer_get_time() + 5000000LL;
}

static const lv_font_t *styled_font(const char *size, bool bold, bool italic)
{
    int px = 20;
    if (size && strcmp(size, "normal") == 0) px = 28;
    else if (size && strcmp(size, "large") == 0) px = 36;
    else if (size && strcmp(size, "xlarge") == 0) px = 48;
    if (bold && italic) {
        if (px >= 48) return &montserrat_bolditalic_48;
        if (px >= 36) return &montserrat_bolditalic_36;
        if (px >= 28) return &montserrat_bolditalic_28;
        return &montserrat_bolditalic_20;
    }
    if (bold) {
        if (px >= 48) return &montserrat_bold_48;
        if (px >= 36) return &montserrat_bold_36;
        if (px >= 28) return &montserrat_bold_28;
        return &montserrat_bold_20;
    }
    if (italic) {
        if (px >= 48) return &montserrat_italic_48;
        if (px >= 36) return &montserrat_italic_36;
        if (px >= 28) return &montserrat_italic_28;
        return &montserrat_italic_20;
    }
    if (px >= 48) return &lv_font_montserrat_48;
    if (px >= 36) return &lv_font_montserrat_36;
    if (px >= 28) return &lv_font_montserrat_28;
    return &lv_font_montserrat_20;
}

static void note_touch(void)
{
    touched_at = esp_timer_get_time();
    touch_counter++;
}

static bool control_is_dimmer(const char *control)
{
    return control && (strcmp(control, "dimSwitch") == 0 || strcmp(control, "slider") == 0 || strcmp(control, "combo") == 0);
}

/* Schalter ist Ein nur bei Wert An. Dimmer bleibt Ein, solange der Wert nicht Aus ist. */
static bool value_is_on(const tile_view_t *tile, int value)
{
    if (!tile) return false;
    if (control_is_dimmer(tile->control)) return value != tile->value_off;
    if (strcmp(tile->control, "switch") == 0) return value == tile->value_on;
    return value > tile->value_min;
}

static int toggle_target(const tile_view_t *tile)
{
    return tile->value == tile->value_on ? tile->value_off : tile->value_on;
}

static void level_span(const tile_view_t *tile, int *min_out, int *max_out)
{
    int min = tile->value_off;
    int max = tile->value_on;
    if (max < min) {
        int swap = min;
        min = max;
        max = swap;
    }
    if (max == min) max = min + 1;
    *min_out = min;
    *max_out = max;
}

static int dim_percent_of(const tile_view_t *tile, int value)
{
    int min = 0;
    int max = 1;
    level_span(tile, &min, &max);
    if (value < min) value = min;
    if (value > max) value = max;
    if (max == min) return 0;
    return (value - min) * 100 / (max - min);
}

/* Prozent oben rechts. Ein/Aus hängt in derselben Zeile dahinter, wenn beide an sind. */
static void paint_dim_label(tile_view_t *tile)
{
    if (!tile || !tile->label || !control_is_dimmer(tile->control)) return;
    if (!tile->dim_show_percent && !tile->dim_show_state_text) {
        lv_obj_add_flag(tile->label, LV_OBJ_FLAG_HIDDEN);
        return;
    }
    lv_obj_clear_flag(tile->label, LV_OBJ_FLAG_HIDDEN);
    bool on = value_is_on(tile, tile->value);
    char line[48];
    if (tile->dim_show_percent && tile->dim_show_state_text) {
        snprintf(line, sizeof(line), "%d %%  %s", dim_percent_of(tile, tile->value), on ? "Ein" : "Aus");
    } else if (tile->dim_show_percent) {
        snprintf(line, sizeof(line), "%d %%", dim_percent_of(tile, tile->value));
    } else {
        snprintf(line, sizeof(line), "%s", on ? "Ein" : "Aus");
    }
    label_set_folded(tile->label, line);
    lv_obj_align(tile->label, LV_ALIGN_TOP_RIGHT, -4, 2);
}

static void set_power_checked(tile_view_t *tile, bool on)
{
    if (!tile || !tile->power) return;
    bool checked = lv_obj_has_state(tile->power, LV_STATE_CHECKED);
    if (checked == on) return;
    if (on) lv_obj_add_state(tile->power, LV_STATE_CHECKED);
    else lv_obj_clear_state(tile->power, LV_STATE_CHECKED);
}

static void open_dim_popup(tile_view_t *tile);

static void tile_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    tile_view_t *tile = lv_event_get_user_data(event);
    if (!tile || !tile->has_action || !action_callback) return;
    if (control_is_dimmer(tile->control)) {
        lv_obj_t *target = lv_event_get_target(event);
        bool on_switch = tile->power && (target == tile->power || lv_obj_get_parent(target) == tile->power);
        bool on_bar = target == tile->track || target == tile->knob || target == tile->fill
            || (tile->track && lv_obj_get_parent(target) == tile->track);
        if (on_switch || on_bar) return;
    }
    int64_t now = esp_timer_get_time();
    if (!online) {
        lv_label_set_text(tile->label, "Keine Verbindung");
        return;
    }
    if (now - tile->last_tap_at < 700000) return;
    tile->last_tap_at = now;
    if (tile->confirm && (strcmp(confirm_tile, tile->tile_id) != 0 || now - confirm_at > 4000000)) {
        snprintf(confirm_tile, sizeof(confirm_tile), "%s", tile->tile_id);
        confirm_at = now;
        lv_label_set_text(tile->label, "Nochmal tippen");
        return;
    }
    confirm_tile[0] = '\0';
    /* Dimmer: Popup; reine Schalter: Zielzustand (Toggle), nicht den Ist-Wert. */
    if (strcmp(tile->control, "dimSwitch") == 0 || strcmp(tile->control, "slider") == 0
        || strcmp(tile->control, "combo") == 0) {
        if (strcmp(tile->dim_tap, "on") != 0 && strcmp(tile->dim_tap, "off") != 0) {
            open_dim_popup(tile);
        }
        return;
    }
    int next = toggle_target(tile);
    tile->value = next;
    refresh_icons(tile, value_is_on(tile, next));
    hold_target(tile, next);
    if (tile->card) {
        lv_obj_set_style_bg_color(tile->card, lv_color_hex(value_is_on(tile, next) ? tile_on_color(tile) : tile->base_bg), 0);
    }
    if (tile->label && tile->show_state_text) {
        label_set_folded(tile->label, value_is_on(tile, next) ? "Ein" : "Aus");
    }
    action_callback(tile->tile_id, tile->action_id, next);
}

static void show_page(int index);
static void apply_state_body(cJSON *root);
static void send_level(tile_view_t *tile, int value);
static void close_dim_popup(void)
{
    if (dim_popup) {
        lv_obj_add_flag(dim_popup, LV_OBJ_FLAG_HIDDEN);
    }
    dim_tile = NULL;
}

static void dim_slider_event(lv_event_t *event)
{
    if (lv_event_get_code(event) != LV_EVENT_RELEASED || !dim_tile) return;
    note_touch();
    int value = (int) lv_slider_get_value(dim_slider);
    send_level(dim_tile, value);
    close_dim_popup();
}

static void dim_popup_close_event(lv_event_t *event)
{
    (void) event;
    note_touch();
    close_dim_popup();
}

static void open_dim_popup(tile_view_t *tile)
{
    if (!tile || !dim_popup || !dim_slider) return;
    dim_tile = tile;
    int min = 0;
    int max = 1;
    level_span(tile, &min, &max);
    lv_slider_set_range(dim_slider, min, max);
    lv_slider_set_value(dim_slider, tile->value, LV_ANIM_OFF);
    if (dim_title_label) {
        lv_label_set_text(dim_title_label, tile->title[0] ? tile->title : "Dimmen");
    }
    lv_obj_clear_flag(dim_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_move_foreground(dim_popup);
}

static void set_idle_visible(bool idle)
{
    idle_active = idle;
    if (idle) {
        if (page_bar) lv_obj_add_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
        if (home_button) lv_obj_add_flag(home_button, LV_OBJ_FLAG_HIDDEN);
        if (info_label) lv_obj_add_flag(info_label, LV_OBJ_FLAG_HIDDEN);
        if (grid) lv_obj_add_flag(grid, LV_OBJ_FLAG_HIDDEN);
        if (clock_layer) {
            lv_obj_clear_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
            lv_obj_move_foreground(clock_layer);
        }
    } else {
        if (clock_layer) lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
        if (grid) lv_obj_clear_flag(grid, LV_OBJ_FLAG_HIDDEN);
        if (home_button) lv_obj_clear_flag(home_button, LV_OBJ_FLAG_HIDDEN);
        if (info_label) lv_obj_clear_flag(info_label, LV_OBJ_FLAG_HIDDEN);
        if (page_bar && layout_root && !cJSON_IsFalse(cJSON_GetObjectItem(layout_root, "showTabs"))) {
            cJSON *pages = cJSON_GetObjectItem(layout_root, "pages");
            if (cJSON_GetArraySize(pages) > 1) lv_obj_clear_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
        }
    }
}

static void dismiss_idle(lv_event_t *event)
{
    (void) event;
    note_touch();
    if (idle_active) {
        set_idle_visible(false);
        refresh_now = true;
    }
}

/* Winkel 0 = 12 Uhr, in Zehntelgrad. y wächst nach unten. */
static void clock_tip(lv_point_t *point, int angle_tenth, int radius)
{
    double rad = ((double) angle_tenth / 10.0 - 90.0) * 3.1415926535 / 180.0;
    point->x = (lv_coord_t) (180 + (int) lround(cos(rad) * radius));
    point->y = (lv_coord_t) (180 + (int) lround(sin(rad) * radius));
}

static lv_obj_t *clock_line(lv_obj_t *parent, lv_point_t *points, uint16_t count, int width)
{
    lv_obj_t *line = lv_line_create(parent);
    lv_obj_set_pos(line, 0, 0);
    lv_obj_set_size(line, 360, 360);
    style_flat(line);
    lv_obj_set_style_line_width(line, width, 0);
    lv_obj_set_style_line_color(line, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_line_rounded(line, true, 0);
    lv_obj_clear_flag(line, LV_OBJ_FLAG_CLICKABLE);
    lv_line_set_points(line, points, count);
    return line;
}

static void update_analog_hands(int hour, int minute)
{
    if (!analog_hour || !analog_min) return;
    int hour_angle = ((hour % 12) * 300) + (minute * 5);
    int minute_angle = minute * 60;
    analog_hour_pts[0].x = 180;
    analog_hour_pts[0].y = 180;
    clock_tip(&analog_hour_pts[1], hour_angle, 70);
    lv_line_set_points(analog_hour, analog_hour_pts, 2);
    analog_min_pts[0].x = 180;
    analog_min_pts[0].y = 180;
    clock_tip(&analog_min_pts[1], minute_angle, 110);
    lv_line_set_points(analog_min, analog_min_pts, 2);
}

static void show_page(int index);

static void page_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    show_page((int) (intptr_t) lv_event_get_user_data(event));
}

static void gesture_event(lv_event_t *event)
{
    (void) event;
    note_touch();
    if (locked || !layout_root) return;
    if (dim_popup && !lv_obj_has_flag(dim_popup, LV_OBJ_FLAG_HIDDEN)) return;
    lv_dir_t direction = lv_indev_get_gesture_dir(lv_indev_get_act());
    int count = cJSON_GetArraySize(cJSON_GetObjectItem(layout_root, "pages"));
    if (count <= 1) return;
    if (direction == LV_DIR_LEFT) show_page((page_index + 1) % count);
    if (direction == LV_DIR_RIGHT) show_page((page_index + count - 1) % count);
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

static uint32_t parse_hex_color(const char *text, uint32_t fallback)
{
    if (!text || text[0] != '#') return fallback;
    uint32_t value = 0;
    for (int index = 1; index <= 6; index++) {
        char digit = text[index];
        uint32_t nibble;
        if (digit >= '0' && digit <= '9') nibble = (uint32_t) (digit - '0');
        else if (digit >= 'a' && digit <= 'f') nibble = (uint32_t) (digit - 'a' + 10);
        else if (digit >= 'A' && digit <= 'F') nibble = (uint32_t) (digit - 'A' + 10);
        else return fallback;
        value = (value << 4) | nibble;
    }
    if (text[7] != '\0') return fallback;
    return value;
}

static void place_level(tile_view_t *tile, int value)
{
    if (!tile->track || !tile->knob) return;
    int min = 0;
    int max = 1;
    level_span(tile, &min, &max);
    if (value < min) value = min;
    if (value > max) value = max;
    lv_coord_t travel = lv_obj_get_width(tile->track) - 34;
    if (travel < 1) travel = 1;
    int x = (value - min) * travel / (max - min);
    lv_obj_set_pos(tile->knob, lv_obj_get_x(tile->track) + x, lv_obj_get_y(tile->track) + (lv_obj_get_height(tile->track) - 34) / 2);
    if (tile->fill) lv_obj_set_width(tile->fill, x + 17);
    tile->value = value;
    if (value > min) tile->level = value;
}

static void send_level(tile_view_t *tile, int value)
{
    if (!tile || tile->applying || !tile->has_action || !action_callback) return;
    if (!online) {
        if (tile->label) lv_label_set_text(tile->label, "Keine Verbindung");
        return;
    }
    tile->value = value;
    refresh_icons(tile, value_is_on(tile, value));
    paint_dim_label(tile);
    hold_target(tile, value);
    action_callback(tile->tile_id, tile->action_id, value);
}

/* Nur der Griff zählt. Tippen auf die Leiste ändert nichts. */
static void knob_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    tile_view_t *tile = lv_event_get_user_data(event);
    if (!tile || !tile->track) return;
    lv_event_code_t code = lv_event_get_code(event);
    if (code == LV_EVENT_CLICKED || code == LV_EVENT_PRESSING) {
        lv_point_t point;
        lv_indev_get_point(lv_indev_get_act(), &point);
        lv_area_t area;
        lv_obj_get_coords(tile->track, &area);
        int travel = lv_area_get_width(&area) - 34;
        if (travel < 1) travel = 1;
        int x = point.x - area.x1 - 17;
        if (x < 0) x = 0;
        if (x > travel) x = travel;
        int min = 0;
        int max = 1;
        level_span(tile, &min, &max);
        int value = min + x * (max - min) / travel;
        tile->applying = true;
        place_level(tile, value);
        if (tile->power) set_power_checked(tile, value_is_on(tile, value));
        if (tile->label) paint_dim_label(tile);
        tile->applying = false;
        if (code == LV_EVENT_CLICKED) send_level(tile, tile->value);
        return;
    }
    if (lv_event_get_code(event) == LV_EVENT_RELEASED) send_level(tile, tile->value);
}

static void power_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    tile_view_t *tile = lv_event_get_user_data(event);
    if (!tile || tile->applying || !tile->power) return;
    int value = toggle_target(tile);
    tile->applying = true;
    set_power_checked(tile, value_is_on(tile, value));
    place_level(tile, value);
    tile->applying = false;
    send_level(tile, value);
}

static void build_track(tile_view_t *view, int width)
{
    view->track = lv_obj_create(view->card);
    lv_obj_set_size(view->track, width, 18);
    lv_obj_set_style_bg_color(view->track, lv_color_hex(0x0B1626), 0);
    lv_obj_set_style_radius(view->track, 9, 0);
    lv_obj_set_style_border_width(view->track, 0, 0);
    lv_obj_set_style_pad_all(view->track, 0, 0);
    lv_obj_clear_flag(view->track, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_clear_flag(view->track, LV_OBJ_FLAG_CLICKABLE);
    view->fill = lv_obj_create(view->track);
    lv_obj_set_size(view->fill, 14, 18);
    lv_obj_set_style_bg_color(view->fill, lv_color_hex(0xFFB547), 0);
    lv_obj_set_style_radius(view->fill, 9, 0);
    lv_obj_set_style_border_width(view->fill, 0, 0);
    lv_obj_clear_flag(view->fill, LV_OBJ_FLAG_CLICKABLE);
    view->knob = lv_obj_create(view->card);
    lv_obj_set_size(view->knob, 34, 34);
    lv_obj_set_style_bg_color(view->knob, lv_color_hex(0xF4F7FB), 0);
    lv_obj_set_style_radius(view->knob, LV_RADIUS_CIRCLE, 0);
    lv_obj_set_style_border_width(view->knob, 0, 0);
    lv_obj_clear_flag(view->knob, LV_OBJ_FLAG_GESTURE_BUBBLE);
    lv_obj_add_event_cb(view->knob, knob_event, LV_EVENT_PRESSING, view);
    lv_obj_add_event_cb(view->knob, knob_event, LV_EVENT_RELEASED, view);
}

static void build_power(tile_view_t *view)
{
    view->power = lv_switch_create(view->card);
    lv_obj_set_style_anim_time(view->power, 0, LV_PART_MAIN);
    lv_obj_set_style_anim_time(view->power, 0, LV_PART_INDICATOR);
    lv_obj_set_style_anim_time(view->power, 0, LV_PART_KNOB);
    lv_obj_clear_flag(view->power, LV_OBJ_FLAG_GESTURE_BUBBLE);
    lv_obj_add_event_cb(view->power, power_event, LV_EVENT_VALUE_CHANGED, view);
}

static void place_battery(tile_view_t *tile, int value)
{
    if (!tile->battery_shell || !tile->battery_fill) return;
    int min = tile->value_min;
    int max = tile->value_max > min ? tile->value_max : min + 1;
    if (value < min) value = min;
    if (value > max) value = max;
    int inner = lv_obj_get_width(tile->battery_shell) - 8;
    if (inner < 8) inner = 8;
    int width = 4 + (value - min) * (inner - 4) / (max - min);
    if (width < 4) width = 4;
    lv_obj_set_width(tile->battery_fill, width);
    lv_color_t fill = lv_color_hex(0x3DDC84);
    if (value <= min + (max - min) / 5) fill = lv_color_hex(0xE05252);
    else if (value <= min + (max - min) / 3) fill = lv_color_hex(0xFFB547);
    lv_obj_set_style_bg_color(tile->battery_fill, fill, 0);
    tile->value = value;
}

static void build_battery(tile_view_t *view, int pad)
{
    int card_w = lv_obj_get_width(view->card);
    int shell_w = card_w > pad * 2 + 40 ? card_w - pad * 2 - 16 : 78;
    if (shell_w < 48) shell_w = 48;
    view->battery_shell = lv_obj_create(view->card);
    lv_obj_set_size(view->battery_shell, shell_w, 38);
    lv_obj_set_style_bg_color(view->battery_shell, lv_color_hex(0x0B1626), 0);
    lv_obj_set_style_radius(view->battery_shell, 6, 0);
    lv_obj_set_style_border_width(view->battery_shell, 2, 0);
    lv_obj_set_style_border_color(view->battery_shell, lv_color_hex(0xF4F7FB), 0);
    lv_obj_set_style_pad_all(view->battery_shell, 4, 0);
    lv_obj_clear_flag(view->battery_shell, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_t *cap = lv_obj_create(view->card);
    lv_obj_set_size(cap, 6, 18);
    lv_obj_set_style_bg_color(cap, lv_color_hex(0xF4F7FB), 0);
    lv_obj_set_style_radius(cap, 2, 0);
    lv_obj_set_style_border_width(cap, 0, 0);
    lv_obj_align_to(cap, view->battery_shell, LV_ALIGN_OUT_RIGHT_MID, 2, 0);
    view->battery_fill = lv_obj_create(view->battery_shell);
    lv_obj_set_size(view->battery_fill, 8, 26);
    lv_obj_set_style_bg_color(view->battery_fill, lv_color_hex(0x3DDC84), 0);
    lv_obj_set_style_radius(view->battery_fill, 3, 0);
    lv_obj_set_style_border_width(view->battery_fill, 0, 0);
    lv_obj_align(view->battery_fill, LV_ALIGN_LEFT_MID, 0, 0);
    lv_obj_align(view->battery_shell, LV_ALIGN_CENTER, -4, 8);
    place_battery(view, view->value_max);
}

static bool storage_image_ready_limit(const char *file, size_t max_bytes)
{
    if (!file || !file[0]) {
        return false;
    }
    char path[96];
    snprintf(path, sizeof(path), "/storage/%s", file);
    struct stat info;
    if (stat(path, &info) != 0 || info.st_size <= 0) {
        return false;
    }
    if (max_bytes > 0 && (size_t) info.st_size > max_bytes) {
        ESP_LOGW(TAG_UI, "Bild übersprungen (zu groß): %s (%ld Byte)", file, (long) info.st_size);
        return false;
    }
    return true;
}

static void show_background(cJSON *page)
{
    (void) page;
    /* Vollbild-Decode verdeckt das Raster und blockiert LVGL. Hintergrund bleibt aus. */
    if (page_background) {
        lv_obj_add_flag(page_background, LV_OBJ_FLAG_HIDDEN);
        lv_obj_move_background(page_background);
    }
}

static void show_page(int index)
{
    /* Schlanker Aufbau + Icons/Farben wie Editor. Keine Slider/Bilder beim Paint. */
    close_dim_popup();
    ui_wdt_kick();
    cJSON *pages = layout_root ? cJSON_GetObjectItem(layout_root, "pages") : NULL;
    int count = cJSON_GetArraySize(pages);
    if (count <= 0) return;
    if (index < 0 || index >= count) index = 0;
    page_index = index;
    cJSON *page = cJSON_GetArrayItem(pages, index);
    ESP_LOGI(TAG_UI, "Zeichne Seite %d/%d (einfach)", index + 1, count);
    show_background(page);
    ui_wdt_kick();
    lv_obj_t *screen = grid ? lv_obj_get_parent(grid) : NULL;
    if (screen) {
        lv_obj_set_style_bg_color(screen, lv_color_hex(color_mono ? 0x000000 : theme_bg), 0);
    }
    if (home_button) {
        lv_obj_set_style_bg_color(home_button, lv_color_hex(color_mono ? 0x333333 : theme_button), 0);
    }
    bool show_tabs = !cJSON_IsFalse(cJSON_GetObjectItem(layout_root, "showTabs"));
    lv_obj_clean(page_bar);
    lv_obj_clean(grid);
    tile_count = 0;
    chart_slot_count = 0;
    system_label = NULL;
    ui_wdt_kick();
    if (status_label) {
        lv_obj_add_flag(status_label, LV_OBJ_FLAG_HIDDEN);
    }
    /* Feste Kopfzeile, Raster absolut darunter. Kein Flex: das hat die Reiter oben und unten verteilt. */
    const int header_h = HEADER_H;
    const int grid_top = header_h;
    const int grid_h = HOMEDISPLAY_LCD_V_RES - grid_top;
    const int grid_w = HOMEDISPLAY_LCD_H_RES;
    lv_obj_clear_flag(grid, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_pos(grid, 0, grid_top);
    lv_obj_set_size(grid, grid_w, grid_h);
    style_flat(grid);
    if (show_tabs && count > 1) {
        lv_obj_set_layout(page_bar, 0);
        lv_obj_clear_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
        lv_obj_set_pos(page_bar, HEADER_LEFT, (HEADER_H - TAB_H) / 2);
        lv_obj_set_size(page_bar, HOMEDISPLAY_LCD_H_RES - HEADER_LEFT - HEADER_RIGHT, TAB_H);
        int tab_x = 0;
        for (int item = 0; item < count && item < 6; item++) {
            if (tab_x + TAB_W > HOMEDISPLAY_LCD_H_RES - HEADER_LEFT - HEADER_RIGHT) break;
            cJSON *candidate = cJSON_GetArrayItem(pages, item);
            lv_obj_t *button = lv_btn_create(page_bar);
            lv_obj_set_pos(button, tab_x, 0);
            lv_obj_set_size(button, TAB_W - 6, TAB_H);
            lv_obj_set_style_max_height(button, TAB_H, 0);
            lv_obj_set_style_pad_all(button, 0, 0);
            lv_obj_set_style_radius(button, 6, 0);
            style_flat(button);
            lv_obj_set_style_bg_color(button, lv_color_hex(item == index ? accent_tab() : accent_tab_bg()), 0);
            lv_obj_set_style_bg_opa(button, LV_OPA_COVER, 0);
            lv_obj_set_style_text_color(button, lv_color_hex(item == index ? 0x102033 : text_fg()), 0);
            lv_obj_t *caption = lv_label_create(button);
            const char *title = cJSON_GetStringValue(cJSON_GetObjectItem(candidate, "title"));
            label_set_folded(caption, title ? title : "");
            lv_label_set_long_mode(caption, LV_LABEL_LONG_DOT);
            lv_obj_set_width(caption, TAB_W - 16);
            lv_obj_set_style_text_font(caption, &lv_font_montserrat_28, 0);
            lv_obj_set_style_text_align(caption, LV_TEXT_ALIGN_CENTER, 0);
            lv_obj_center(caption);
            lv_obj_add_event_cb(button, page_event, LV_EVENT_CLICKED, (void *) (intptr_t) item);
            tab_x += TAB_W;
        }
    } else {
        lv_obj_add_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
    }
    if (home_button) lv_obj_move_foreground(home_button);
    if (info_label) lv_obj_move_foreground(info_label);
    columns = json_int(layout_root, "columns", 2);
    if (columns < 1) columns = 1;
    if (columns > 4) columns = 4;
    int rows = json_int(layout_root, "rows", 4);
    if (rows < 1) rows = 1;
    if (rows > 6) rows = 6;
    int cell_w = grid_w / columns;
    int cell_h = grid_h / rows;
    cJSON *tile = NULL;
    cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
        if (tile_count >= 24) break;
        const char *kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "kind"));
        if (!kind) kind = "value";
        if (strcmp(kind, "separator") == 0) {
            int col = json_int(tile, "col", 0);
            int row = json_int(tile, "row", 0);
            int col_span = json_int(tile, "colSpan", 1);
            int row_span = json_int(tile, "rowSpan", 1);
            if (col_span < 1) col_span = 1;
            if (row_span < 1) row_span = 1;
            const char *orient = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "orientation"));
            bool vertical = orient && strcmp(orient, "vertical") == 0;
            int thickness = json_int(tile, "thickness", 2);
            if (thickness < 1) thickness = 1;
            if (thickness > 12) thickness = 12;
            uint32_t line_color = parse_hex_color(cJSON_GetStringValue(cJSON_GetObjectItem(tile, "lineColor")), 0x5A7391);
            lv_obj_t *card = lv_obj_create(grid);
            lv_obj_set_pos(card, col * cell_w, row * cell_h);
            lv_obj_set_size(card, cell_w * col_span, cell_h * row_span);
            lv_obj_set_style_bg_opa(card, LV_OPA_TRANSP, 0);
            lv_obj_set_style_radius(card, 0, 0);
            style_flat(card);
            lv_obj_clear_flag(card, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_t *line = lv_obj_create(card);
            style_flat(line);
            lv_obj_set_style_radius(line, 0, 0);
            lv_obj_set_style_bg_color(line, lv_color_hex(line_color), 0);
            lv_obj_set_style_bg_opa(line, LV_OPA_COVER, 0);
            lv_obj_clear_flag(line, LV_OBJ_FLAG_CLICKABLE);
            if (vertical) {
                lv_obj_set_size(line, thickness, cell_h * row_span);
            } else {
                lv_obj_set_size(line, cell_w * col_span, thickness);
            }
            lv_obj_align(line, LV_ALIGN_CENTER, 0, 0);
            continue;
        }
        if (show_page_may_yield) {
            lvgl_port_unlock();
            ui_wdt_kick();
            vTaskDelay(pdMS_TO_TICKS(20));
            ui_wdt_kick();
            if (!lvgl_port_lock(UI_CLIENT_LOCK_MS)) {
                ui_wdt_kick();
                show_page_lock_lost = true;
                break;
            }
        } else {
            ui_wdt_kick();
        }

        tile_view_t *view = &tiles[tile_count];
        memset(view, 0, sizeof(*view));
        const char *tile_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
        const char *action_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "actionId"));
        const char *title = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "title"));
        const char *icon = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "icon"));
        snprintf(view->tile_id, sizeof(view->tile_id), "%s", tile_id ? tile_id : "");
        snprintf(view->title, sizeof(view->title), "%s", title ? title : "");
        if (icon && icon[0]) snprintf(view->icon_name, sizeof(view->icon_name), "%s", icon);
        view->confirm = cJSON_IsTrue(cJSON_GetObjectItem(tile, "confirm"));
        view->base_bg = kind_bg(kind);
        bool own_bg = false;
        if (!color_mono) {
            const char *own_bg_text = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "bgColor"));
            if (own_bg_text && own_bg_text[0] == '#') {
                view->base_bg = parse_hex_color(own_bg_text, view->base_bg);
                own_bg = true;
            }
            const char *own_on_text = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "onColor"));
            if (own_on_text && own_on_text[0] == '#') {
                view->on_bg = parse_hex_color(own_on_text, theme_accent);
                view->own_on = true;
            }
        }
        bool show_title = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "showTitle"));
        const char *title_align = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "titleAlign"));
        if (!title_align || !title_align[0]) title_align = "left";
        int col = json_int(tile, "col", 0);
        int row = json_int(tile, "row", 0);
        int col_span = json_int(tile, "colSpan", 1);
        int row_span = json_int(tile, "rowSpan", 1);
        if (col_span < 1) col_span = 1;
        if (row_span < 1) row_span = 1;
        ESP_LOGI(TAG_UI, "Kachel %d %s kind=%s heap=%lu", tile_count + 1, view->tile_id, kind,
                 (unsigned long) (esp_get_free_heap_size() / 1024));
        view->card = lv_obj_create(grid);
        lv_obj_set_pos(view->card, col * cell_w + 4, row * cell_h + 4);
        lv_obj_set_size(view->card, cell_w * col_span - 8, cell_h * row_span - 8);
        lv_obj_set_style_radius(view->card, 8, 0);
        lv_obj_set_style_bg_color(view->card, lv_color_hex(view->base_bg), 0);
        lv_obj_set_style_bg_opa(view->card, LV_OPA_COVER, 0);
        lv_obj_set_style_pad_all(view->card, 10, 0);
        lv_obj_set_style_text_color(view->card, lv_color_hex(0xF4F7FB), 0);
        style_flat(view->card);
        if (strcmp(kind, "nav") == 0) {
            const char *target = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "targetPage"));
            snprintf(view->target_page, sizeof(view->target_page), "%s", target ? target : "");
            lv_obj_t *row = lv_obj_create(view->card);
            lv_obj_set_size(row, LV_SIZE_CONTENT, LV_SIZE_CONTENT);
            style_flat(row);
            lv_obj_set_style_bg_opa(row, LV_OPA_TRANSP, 0);
            lv_obj_set_style_pad_all(row, 0, 0);
            lv_obj_set_flex_flow(row, LV_FLEX_FLOW_ROW);
            lv_obj_set_flex_align(row, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
            lv_obj_set_style_pad_column(row, 12, 0);
            lv_obj_clear_flag(row, LV_OBJ_FLAG_CLICKABLE);
            if (icon && icon[0]) {
                lv_obj_t *ic = lv_label_create(row);
                paint_icon_label(ic, icon, false, false, 0xF4F7FB);
                lv_obj_clear_flag(ic, LV_OBJ_FLAG_CLICKABLE);
            }
            if (show_title || !icon || !icon[0]) {
                lv_obj_t *name = lv_label_create(row);
                if (show_title) label_set_folded(name, view->title[0] ? view->title : "Weiter");
                else lv_label_set_text(name, LV_SYMBOL_RIGHT);
                lv_obj_set_style_text_font(name, &lv_font_montserrat_28, 0);
                lv_obj_clear_flag(name, LV_OBJ_FLAG_CLICKABLE);
            }
            lv_align_t nav_align = LV_ALIGN_CENTER;
            if (strcmp(title_align, "left") == 0) nav_align = LV_ALIGN_LEFT_MID;
            else if (strcmp(title_align, "right") == 0) nav_align = LV_ALIGN_RIGHT_MID;
            lv_obj_align(row, nav_align, 0, 0);
            lv_obj_add_event_cb(view->card, nav_event, LV_EVENT_CLICKED, view);
            tile_count++;
            continue;
        }
        if (strcmp(kind, "label") == 0) {
            const char *text = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "text"));
            lv_obj_set_style_bg_opa(view->card, LV_OPA_TRANSP, 0);
            lv_obj_t *label = lv_label_create(view->card);
            lv_label_set_text(label, text ? text : "");
            lv_label_set_long_mode(label, LV_LABEL_LONG_WRAP);
            lv_obj_set_width(label, cell_w * col_span - 24);
            const char *label_size = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "textSize"));
            bool label_bold = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textBold"));
            bool label_italic = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textItalic"));
            lv_obj_set_style_text_font(label, styled_font(label_size, label_bold, label_italic), 0);
            if (cJSON_IsTrue(cJSON_GetObjectItem(tile, "textUnderline"))) {
                lv_obj_set_style_text_decor(label, LV_TEXT_DECOR_UNDERLINE, 0);
            }
            tile_count++;
            continue;
        }
        if (strcmp(kind, "system") == 0) {
            /* Anzeigestatus-Kachel entfaellt */
            lv_obj_del(view->card);
            view->card = NULL;
            continue;
        }
        if (strcmp(kind, "image") == 0) {
            const char *file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageFile"));
            if (!file || !file[0]) file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "mediaFile"));
            lv_obj_set_style_pad_all(view->card, 0, 0);
            view->image_img = lv_img_create(view->card);
            (void) file;
            lv_obj_set_size(view->image_img, cell_w * col_span - 8, cell_h * row_span - 8);
            lv_obj_center(view->image_img);
            tile_count++;
            continue;
        }
        if (strcmp(kind, "camera") == 0) {
            const char *file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "cameraFile"));
            lv_obj_set_style_pad_all(view->card, 4, 0);
            lv_obj_t *name = lv_label_create(view->card);
            label_set_folded(name, view->title[0] ? view->title : "Kamera");
            lv_obj_set_style_text_font(name, &lv_font_montserrat_20, 0);
            lv_obj_align(name, LV_ALIGN_TOP_LEFT, 4, 2);
            view->camera_img = lv_img_create(view->card);
            (void) file;
            lv_obj_set_size(view->camera_img, cell_w * col_span - 16, cell_h * row_span - 36);
            lv_obj_align(view->camera_img, LV_ALIGN_BOTTOM_MID, 0, -4);
            tile_count++;
            continue;
        }
        /* value / display (Legacy) und Bedien-Kacheln */
        view->has_action = action_id && action_id[0];
        if (view->has_action) snprintf(view->action_id, sizeof(view->action_id), "%s", action_id);
        const char *control = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "control"));
        snprintf(view->control, sizeof(view->control), "%s", control ? control : (view->has_action ? "switch" : ""));
        view->value_min = json_int(tile, "valueMin", 0);
        view->value_max = json_int(tile, "valueMax", 100);
        if (view->value_max <= view->value_min) view->value_max = view->value_min + 100;
        bool dimmer_tile = strcmp(view->control, "dimSwitch") == 0 || strcmp(view->control, "slider") == 0 || strcmp(view->control, "combo") == 0;
        view->value_on = json_int(tile, "valueOn", dimmer_tile ? view->value_max : 1);
        view->value_off = json_int(tile, "valueOff", dimmer_tile ? view->value_min : 0);
        view->level = view->value_on;
        const char *icon_mode = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "iconMode"));
        snprintf(view->icon_mode, sizeof(view->icon_mode), "%s", icon_mode ? icon_mode : "static");
        const char *icon_on = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "iconOn"));
        const char *icon_off = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "iconOff"));
        snprintf(view->icon_on, sizeof(view->icon_on), "%s", icon_on ? icon_on : "lightOn");
        snprintf(view->icon_off, sizeof(view->icon_off), "%s", icon_off ? icon_off : "lightOff");
        bool icon_toggle = strcmp(view->icon_mode, "toggle") == 0;
        view->show_icon = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "showIcon"));
        view->show_state_text = cJSON_IsTrue(cJSON_GetObjectItem(tile, "showStateText"));
        view->dim_show_switch = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "dimShowSwitch"));
        view->dim_show_bar = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "dimShowBar"));
        view->dim_show_percent = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "dimShowPercent"));
        view->dim_show_state_text = cJSON_IsTrue(cJSON_GetObjectItem(tile, "dimShowStateText"));
        bool battery = strcmp(view->control, "battery") == 0;
        bool dim_switch = strcmp(view->control, "dimSwitch") == 0;
        bool slider = strcmp(view->control, "slider") == 0;
        bool combo = strcmp(view->control, "combo") == 0;
        bool dimmer = dim_switch || slider || combo;
        const char *action_kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "actionKind"));
        bool action_dim = action_kind && strcmp(action_kind, "dim") == 0 && strcmp(view->control, "setpoint") != 0;
        bool plain_value = strcmp(kind, "value") == 0 && !view->has_action && !dimmer && !battery;
        if (slider) view->dim_show_switch = false;
        if (dim_switch) view->dim_show_switch = true;
        const char *tap = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "dimTap"));
        snprintf(view->dim_tap, sizeof(view->dim_tap), "%s", tap && tap[0] ? tap : "popup");
        const char *text_size = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "textSize"));
        snprintf(view->text_size, sizeof(view->text_size), "%s", text_size ? text_size : "normal");
        const char *text_color = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "textColor"));
        if (text_color && text_color[0] == '#') {
            view->text_color = parse_hex_color(text_color, text_fg());
            view->own_text = true;
        }
        cJSON *threshold = cJSON_GetObjectItem(tile, "threshold");
        if (threshold && cJSON_IsNumber(threshold)) {
            view->has_threshold = true;
            view->threshold = (float) threshold->valuedouble;
            const char *status = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "color"));
            snprintf(view->status_color, sizeof(view->status_color), "%s", status ? status : "neutral");
        }

        /* Titelzeile: kleines Icon + Text */
        if (show_title) {
            lv_obj_t *title_row_icon = NULL;
            lv_align_t title_pos = LV_ALIGN_TOP_LEFT;
            lv_coord_t title_x = 0;
            if (strcmp(title_align, "center") == 0) title_pos = LV_ALIGN_TOP_MID;
            else if (strcmp(title_align, "right") == 0) title_pos = LV_ALIGN_TOP_RIGHT;
            if (icon && icon[0] && !icon_toggle && !dimmer && !action_dim && !plain_value) {
                lv_coord_t icon_x = strcmp(title_align, "right") == 0 ? -4 : (strcmp(title_align, "center") == 0 ? -28 : 0);
                title_row_icon = lv_label_create(view->card);
                view->title_icon = title_row_icon;
                show_icon_label(title_row_icon, view, icon, false, false);
                lv_obj_align(title_row_icon, title_pos, icon_x, 0);
                lv_obj_clear_flag(title_row_icon, LV_OBJ_FLAG_CLICKABLE);
                title_x = strcmp(title_align, "right") == 0 ? -40 : (strcmp(title_align, "center") == 0 ? 18 : 40);
            }
            lv_obj_t *name = lv_label_create(view->card);
            label_set_folded(name, view->title[0] ? view->title : "—");
            lv_obj_set_style_text_font(name, &lv_font_montserrat_20, 0);
            lv_obj_align(name, title_pos, title_x, 2);
        }

        if (strcmp(kind, "chart") == 0) {
            lv_obj_set_style_pad_all(view->card, 4, 0);
            int chart_w = cell_w * col_span - 16;
            int chart_h = cell_h * row_span - 16 - (show_title ? 28 : 0);
            if (chart_w < 8) chart_w = 8;
            if (chart_h < 8) chart_h = 8;
            view->chart_w = chart_w;
            view->chart_h = chart_h;
            if (chart_slot_count < CHART_SLOT_MAX) {
                view->chart_points = chart_point_pool[chart_slot_count++];
                view->chart_line = lv_line_create(view->card);
                style_flat(view->chart_line);
                lv_obj_set_style_line_width(view->chart_line, 3, 0);
                lv_obj_set_style_line_color(view->chart_line, lv_color_hex(color_mono ? 0xFFFFFF : theme_accent), 0);
                lv_obj_set_style_line_rounded(view->chart_line, true, 0);
                lv_obj_clear_flag(view->chart_line, LV_OBJ_FLAG_CLICKABLE);
                view->chart_points[0].x = 0;
                view->chart_points[0].y = (lv_coord_t) (chart_h - 1);
                view->chart_points[1].x = (lv_coord_t) (chart_w - 1);
                view->chart_points[1].y = (lv_coord_t) (chart_h - 1);
                lv_line_set_points(view->chart_line, view->chart_points, 2);
                lv_obj_align(view->chart_line, LV_ALIGN_BOTTOM_MID, 0, 0);
            }
            view->label = lv_label_create(view->card);
            lv_label_set_text(view->label, "—");
            lv_obj_set_style_text_font(view->label, &lv_font_montserrat_20, 0);
            lv_obj_align(view->label, LV_ALIGN_TOP_RIGHT, -4, 2);
            tile_count++;
            continue;
        }

        if (battery) {
            view->battery_pad = json_int(tile, "batteryPad", 8);
            build_battery(view, view->battery_pad);
            view->label = lv_label_create(view->card);
            lv_label_set_text(view->label, "—");
            lv_obj_set_style_text_font(view->label, &lv_font_montserrat_28, 0);
            lv_obj_align(view->label, LV_ALIGN_TOP_RIGHT, 0, 2);
            tile_count++;
            continue;
        }

        view->label = lv_label_create(view->card);
        lv_label_set_text(view->label, "—");
        bool value_bold = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textBold"));
        bool value_italic = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textItalic"));
        lv_obj_set_style_text_font(view->label, styled_font(view->text_size, value_bold, value_italic), 0);
        if (cJSON_IsTrue(cJSON_GetObjectItem(tile, "textUnderline"))) {
            lv_obj_set_style_text_decor(view->label, LV_TEXT_DECOR_UNDERLINE, 0);
        }
        if (view->own_text) lv_obj_set_style_text_color(view->label, lv_color_hex(view->text_color), 0);

        if (view->has_action && dimmer) {
            if (!color_mono && !own_bg) {
                view->base_bg = theme_button;
                lv_obj_set_style_bg_color(view->card, lv_color_hex(view->base_bg), 0);
            }
            if (view->dim_show_switch) {
                build_power(view);
                lv_obj_align(view->power, LV_ALIGN_LEFT_MID, 8, 6);
            }
            if (view->dim_show_bar) {
                int width = cell_w * col_span - (view->dim_show_switch ? 96 : 28);
                if (width < 48) width = 48;
                build_track(view, width);
                lv_obj_align(view->track, LV_ALIGN_BOTTOM_MID, view->dim_show_switch ? 16 : 0, -14);
                if (strcmp(view->dim_tap, "on") == 0) {
                    lv_obj_add_flag(view->track, LV_OBJ_FLAG_CLICKABLE);
                    lv_obj_add_event_cb(view->track, knob_event, LV_EVENT_CLICKED, view);
                }
            }
            if (view->dim_show_percent || view->dim_show_state_text) {
                paint_dim_label(view);
            } else {
                lv_obj_add_flag(view->label, LV_OBJ_FLAG_HIDDEN);
            }
            lv_obj_add_event_cb(view->card, tile_event, LV_EVENT_CLICKED, view);
        } else if (view->has_action) {
            if (!color_mono && !own_bg) {
                view->base_bg = theme_button;
                lv_obj_set_style_bg_color(view->card, lv_color_hex(view->base_bg), 0);
            }
            if (view->show_icon) {
                lv_coord_t icon_y = view->show_state_text ? -6 : (show_title ? 16 : 4);
                const char *shown = icon_toggle ? view->icon_off : (icon && icon[0] ? icon : "lightOff");
                view->icon_large = true;
                view->icon_label = lv_label_create(view->card);
                show_icon_label(view->icon_label, view, shown, false, true);
                lv_obj_align(view->icon_label, LV_ALIGN_CENTER, 0, icon_y);
                lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_CLICKABLE);
            }
            if (view->show_state_text) {
                lv_label_set_text(view->label, "Aus");
                lv_obj_align(view->label, LV_ALIGN_BOTTOM_MID, 0, -4);
            } else {
                lv_obj_add_flag(view->label, LV_OBJ_FLAG_HIDDEN);
            }
            lv_obj_add_event_cb(view->card, tile_event, LV_EVENT_CLICKED, view);
        } else if (plain_value) {
            lv_align_t col_align = LV_ALIGN_LEFT_MID;
            lv_flex_align_t cross = LV_FLEX_ALIGN_START;
            lv_text_align_t text_align = LV_TEXT_ALIGN_LEFT;
            if (strcmp(title_align, "center") == 0) {
                col_align = LV_ALIGN_CENTER;
                cross = LV_FLEX_ALIGN_CENTER;
                text_align = LV_TEXT_ALIGN_CENTER;
            } else if (strcmp(title_align, "right") == 0) {
                col_align = LV_ALIGN_RIGHT_MID;
                cross = LV_FLEX_ALIGN_END;
                text_align = LV_TEXT_ALIGN_RIGHT;
            }
            lv_obj_t *column = lv_obj_create(view->card);
            lv_obj_set_size(column, LV_SIZE_CONTENT, LV_SIZE_CONTENT);
            style_flat(column);
            lv_obj_set_style_bg_opa(column, LV_OPA_TRANSP, 0);
            lv_obj_set_style_pad_all(column, 0, 0);
            lv_obj_set_style_pad_row(column, 8, 0);
            lv_obj_set_flex_flow(column, LV_FLEX_FLOW_COLUMN);
            lv_obj_set_flex_align(column, LV_FLEX_ALIGN_CENTER, cross, cross);
            lv_obj_clear_flag(column, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_align(column, col_align, 0, show_title ? 12 : 0);
            if (icon && icon[0]) {
                view->icon_large = true;
                view->icon_label = lv_label_create(column);
                show_icon_label(view->icon_label, view, icon, false, true);
                lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_CLICKABLE);
            }
            lv_obj_set_parent(view->label, column);
            lv_obj_set_style_text_align(view->label, text_align, 0);
        } else {
            /* Reine Wertanzeige */
            if (icon && icon[0]) {
                view->icon_label = lv_label_create(view->card);
                show_icon_label(view->icon_label, view, icon, false, false);
                lv_obj_align(view->icon_label, LV_ALIGN_LEFT_MID, 0, 8);
                lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_CLICKABLE);
            }
            lv_obj_align(view->label, LV_ALIGN_BOTTOM_LEFT, 0, 0);
        }
        tile_count++;
    }
    lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
    idle_active = false;
    lv_obj_clear_flag(grid, LV_OBJ_FLAG_HIDDEN);
    lv_obj_move_foreground(grid);
    if (page_bar && !lv_obj_has_flag(page_bar, LV_OBJ_FLAG_HIDDEN)) lv_obj_move_foreground(page_bar);
    if (home_button) lv_obj_move_foreground(home_button);
    if (info_label) lv_obj_move_foreground(info_label);
    if (boot_layer) lv_obj_add_flag(boot_layer, LV_OBJ_FLAG_HIDDEN);
    if (last_state_json[0]) {
        cJSON *saved = cJSON_Parse(last_state_json);
        if (saved) {
            apply_state_body(saved);
            cJSON_Delete(saved);
        }
    }
    refresh_now = true;
    ESP_LOGI(TAG_UI, "Seite fertig (%d Kacheln)", tile_count);
}

static void apply_backlight(void)
{
    bool on = !(idle_mode == 2 && homedisplay_ui_busy() == false && esp_timer_get_time() - touched_at > idle_timeout_us);
    if (on && !backlight_on) refresh_now = true;
    backlight_on = on;
    homedisplay_board_backlight(on);
}

static void clock_tick(lv_timer_t *timer)
{
    (void) timer;
    frame_counter++;
    int64_t now = clock_base + (esp_timer_get_time() - clock_set_at) / 1000000;
    if (now < 0) now = 0;
    int seconds = (int) (now % 86400);
    int hour = seconds / 3600;
    bool night = night_from == night_to || (night_from < night_to ? hour >= night_from && hour < night_to : hour >= night_from || hour < night_to);
    (void) night;
    (void) day_brightness;
    (void) night_brightness;
    char info[96];
    /* Nur Ziffern – LVGL-Symbole wirken auf dem RGB-Panel unscharf. */
    snprintf(info, sizeof(info), "%02d:%02d", hour, (seconds / 60) % 60);
    (void) wifi_rssi;
    if (boot_screen_active) {
        apply_backlight();
        return;
    }
    if (info_label) {
        lv_obj_set_style_text_color(info_label, lv_color_hex(online ? 0xF4F7FB : 0x6E849C), 0);
    }
    if (strcmp(last_info_line, info) != 0) {
        snprintf(last_info_line, sizeof(last_info_line), "%s", info);
        lv_label_set_text(info_label, info);
    }
    if (test_layer && !lv_obj_has_flag(test_layer, LV_OBJ_FLAG_HIDDEN)) {
        char line[160];
        lv_indev_t *indev = lv_indev_get_next(NULL);
        lv_point_t point = {0, 0};
        if (indev) lv_indev_get_point(indev, &point);
        snprintf(line, sizeof(line), "Testbild  1024x600\nBilder %lu  Berührungen %lu\nBerührung x=%d y=%d  Laufzeit %lld s",
                 (unsigned long) frame_counter, (unsigned long) touch_counter, (int) point.x, (int) point.y, (long long) (esp_timer_get_time() / 1000000));
        lv_label_set_text(test_label, line);
        return;
    }
    if (locked || !layout_root) {
        apply_backlight();
        return;
    }
    bool idle = esp_timer_get_time() - touched_at > idle_timeout_us;
    if (idle && idle_mode == 0) {
        int minute = (seconds / 60) % 60;
        if (!idle_active) set_idle_visible(true);
        bool analog = strcmp(idle_clock_style, "analog") == 0;
        if (analog_clock) {
            if (analog) lv_obj_clear_flag(analog_clock, LV_OBJ_FLAG_HIDDEN);
            else lv_obj_add_flag(analog_clock, LV_OBJ_FLAG_HIDDEN);
        }
        if (clock_label) {
            if (analog) lv_obj_add_flag(clock_label, LV_OBJ_FLAG_HIDDEN);
            else {
                lv_obj_clear_flag(clock_label, LV_OBJ_FLAG_HIDDEN);
                char line[16];
                snprintf(line, sizeof(line), "%02d:%02d", hour, minute);
                lv_label_set_text(clock_label, line);
            }
        }
        if (analog) update_analog_hands(hour, minute);
    } else if (idle_active) {
        set_idle_visible(false);
        refresh_now = true;
    } else if (idle_mode != 0) {
        lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
    }
    apply_backlight();
}

static void build_test_layer(lv_obj_t *screen)
{
    /* Schlank halten: keine hunderte LVGL-Objekte beim Boot (RAM + Zeichenzeit). */
    static const uint32_t bars[8] = {0xFFFFFF, 0xFFFF00, 0x00FFFF, 0x00FF00, 0xFF00FF, 0xFF0000, 0x0000FF, 0x000000};
    test_layer = lv_obj_create(screen);
    lv_obj_set_size(test_layer, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES);
    lv_obj_set_pos(test_layer, 0, 0);
    lv_obj_set_style_pad_all(test_layer, 0, 0);
    lv_obj_set_style_border_width(test_layer, 0, 0);
    lv_obj_set_style_radius(test_layer, 0, 0);
    lv_obj_set_style_bg_color(test_layer, lv_color_hex(0x000000), 0);
    for (int index = 0; index < 8; index++) {
        lv_obj_t *bar = lv_obj_create(test_layer);
        lv_obj_set_size(bar, HOMEDISPLAY_LCD_H_RES / 8, HOMEDISPLAY_LCD_V_RES);
        lv_obj_set_pos(bar, index * (HOMEDISPLAY_LCD_H_RES / 8), 0);
        lv_obj_set_style_radius(bar, 0, 0);
        lv_obj_set_style_border_width(bar, 0, 0);
        lv_obj_set_style_bg_color(bar, lv_color_hex(bars[index]), 0);
    }
    test_label = lv_label_create(test_layer);
    lv_obj_set_style_text_color(test_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(test_label, &lv_font_montserrat_20, 0);
    lv_label_set_text(test_label, "Testbild");
    lv_obj_align(test_label, LV_ALIGN_BOTTOM_LEFT, 16, -16);
    lv_obj_add_event_cb(test_layer, (lv_event_cb_t) note_touch, LV_EVENT_PRESSED, NULL);
    lv_obj_add_flag(test_layer, LV_OBJ_FLAG_HIDDEN);
}

void homedisplay_ui_init(esp_lcd_panel_handle_t panel, esp_lcd_touch_handle_t touch)
{
    const lvgl_port_cfg_t port_config = ESP_LVGL_PORT_INIT_CONFIG();
    lvgl_port_init(&port_config);
    const lvgl_port_display_cfg_t display_config = {
        .panel_handle = panel,
        .buffer_size = HOMEDISPLAY_LCD_H_RES * 80,
        .double_buffer = false,
        .hres = HOMEDISPLAY_LCD_H_RES,
        .vres = HOMEDISPLAY_LCD_V_RES,
        .flags = {.buff_spiram = true},
    };
    const lvgl_port_display_rgb_cfg_t rgb_config = {};
    lv_disp_t *display = lvgl_port_add_disp_rgb(&display_config, &rgb_config);
    const lvgl_port_touch_cfg_t touch_config = {.disp = display, .handle = touch};
    lvgl_port_add_touch(&touch_config);

    lvgl_port_lock(0);
    lv_obj_t *screen = lv_scr_act();
    lv_obj_set_style_bg_color(screen, lv_color_hex(0x000000), 0);
    lv_obj_set_style_bg_opa(screen, LV_OPA_COVER, 0);
    lv_obj_set_style_pad_all(screen, 0, 0);
    style_flat(screen);
    lv_obj_add_event_cb(screen, gesture_event, LV_EVENT_GESTURE, NULL);
    page_background = lv_img_create(screen);
    lv_obj_clear_flag(page_background, LV_OBJ_FLAG_CLICKABLE);
    lv_obj_add_flag(page_background, LV_OBJ_FLAG_HIDDEN);
    lv_obj_move_background(page_background);
    status_label = lv_label_create(screen);
    lv_obj_set_style_text_color(status_label, lv_color_hex(0xF4F7FB), 0);
    lv_obj_set_style_text_font(status_label, &lv_font_montserrat_20, 0);
    lv_label_set_text(status_label, "");
    lv_obj_add_flag(status_label, LV_OBJ_FLAG_HIDDEN);
    info_label = lv_label_create(screen);
    lv_obj_set_style_text_color(info_label, lv_color_hex(0xF4F7FB), 0);
    lv_obj_set_style_text_font(info_label, &lv_font_montserrat_36, 0);
    lv_label_set_text(info_label, "--:--");
    lv_obj_align(info_label, LV_ALIGN_TOP_RIGHT, -12, (HEADER_H - 36) / 2);
    home_button = lv_btn_create(screen);
    lv_obj_set_size(home_button, TAB_H, TAB_H);
    lv_obj_set_pos(home_button, 8, (HEADER_H - TAB_H) / 2);
    lv_obj_set_style_bg_color(home_button, lv_color_hex(0x2A4F7C), 0);
    lv_obj_set_style_radius(home_button, 6, 0);
    style_flat(home_button);
    lv_obj_t *home_symbol = lv_label_create(home_button);
    paint_icon_label(home_symbol, "home", false, false, 0xF4F7FB);
    lv_obj_center(home_symbol);
    lv_obj_add_event_cb(home_button, page_event, LV_EVENT_CLICKED, (void *) (intptr_t) 0);
    page_bar = lv_obj_create(screen);
    lv_obj_set_layout(page_bar, 0);
    lv_obj_set_size(page_bar, HOMEDISPLAY_LCD_H_RES - HEADER_LEFT - HEADER_RIGHT, TAB_H);
    lv_obj_set_pos(page_bar, HEADER_LEFT, (HEADER_H - TAB_H) / 2);
    lv_obj_set_style_pad_all(page_bar, 0, 0);
    lv_obj_set_style_bg_opa(page_bar, LV_OPA_TRANSP, 0);
    lv_obj_set_style_radius(page_bar, 0, 0);
    style_flat(page_bar);
    grid = lv_obj_create(screen);
    lv_obj_set_pos(grid, 0, HEADER_H);
    lv_obj_set_size(grid, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES - HEADER_H);
    lv_obj_set_style_bg_opa(grid, LV_OPA_TRANSP, 0);
    lv_obj_set_style_pad_all(grid, 0, 0);
    lv_obj_set_style_radius(grid, 0, 0);
    style_flat(grid);
    clock_layer = lv_obj_create(screen);
    lv_obj_set_pos(clock_layer, 0, 0);
    lv_obj_set_size(clock_layer, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES);
    lv_obj_set_style_bg_color(clock_layer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_bg_opa(clock_layer, LV_OPA_COVER, 0);
    lv_obj_set_style_radius(clock_layer, 0, 0);
    style_flat(clock_layer);
    clock_label = lv_label_create(clock_layer);
    lv_obj_set_style_text_font(clock_label, &lv_font_montserrat_48, 0);
    lv_obj_set_style_text_color(clock_label, lv_color_hex(0xFFFFFF), 0);
    lv_label_set_text(clock_label, "--:--");
    lv_obj_center(clock_label);
    analog_clock = lv_obj_create(clock_layer);
    lv_obj_set_size(analog_clock, 360, 360);
    lv_obj_center(analog_clock);
    style_flat(analog_clock);
    lv_obj_set_style_radius(analog_clock, LV_RADIUS_CIRCLE, 0);
    lv_obj_set_style_bg_color(analog_clock, lv_color_hex(0x000000), 0);
    lv_obj_set_style_bg_opa(analog_clock, LV_OPA_COVER, 0);
    lv_obj_t *ring = lv_obj_create(analog_clock);
    lv_obj_set_size(ring, 336, 336);
    lv_obj_center(ring);
    style_flat(ring);
    lv_obj_set_style_radius(ring, LV_RADIUS_CIRCLE, 0);
    lv_obj_set_style_bg_opa(ring, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_color(ring, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_border_width(ring, 4, 0);
    lv_obj_set_style_border_opa(ring, LV_OPA_COVER, 0);
    lv_obj_clear_flag(ring, LV_OBJ_FLAG_CLICKABLE);
    for (int mark = 0; mark < 12; mark++) {
        double angle = (mark * 30.0 - 90.0) * 3.1415926535 / 180.0;
        bool major = mark % 3 == 0;
        int length = major ? 22 : 12;
        int outer = 164;
        clock_tip(&analog_tick_pts[mark][0], mark * 300, outer);
        clock_tip(&analog_tick_pts[mark][1], mark * 300, outer - length);
        clock_line(analog_clock, analog_tick_pts[mark], 2, major ? 6 : 3);
        char numeral[3];
        snprintf(numeral, sizeof(numeral), "%d", mark == 0 ? 12 : mark);
        lv_obj_t *digit = lv_label_create(analog_clock);
        lv_label_set_text(digit, numeral);
        lv_obj_set_style_text_font(digit, &lv_font_montserrat_28, 0);
        lv_obj_set_style_text_color(digit, lv_color_hex(0xFFFFFF), 0);
        lv_obj_set_style_text_opa(digit, LV_OPA_COVER, 0);
        lv_obj_clear_flag(digit, LV_OBJ_FLAG_CLICKABLE);
        lv_point_t text_size;
        lv_txt_get_size(&text_size, numeral, &lv_font_montserrat_28, 0, 0, LV_COORD_MAX, LV_TEXT_FLAG_NONE);
        double cosine = cos(angle);
        double sine = sin(angle);
        int text_radius = 160 - (int) (fabs(cosine) * text_size.x / 2.0 + fabs(sine) * text_size.y / 2.0);
        lv_obj_set_pos(digit,
                       180 + (int) (cosine * text_radius) - text_size.x / 2,
                       180 + (int) (sine * text_radius) - text_size.y / 2);
    }
    analog_hour_pts[0].x = 180;
    analog_hour_pts[0].y = 180;
    clock_tip(&analog_hour_pts[1], 0, 70);
    analog_min_pts[0].x = 180;
    analog_min_pts[0].y = 180;
    clock_tip(&analog_min_pts[1], 0, 110);
    analog_hour = clock_line(analog_clock, analog_hour_pts, 2, 8);
    analog_min = clock_line(analog_clock, analog_min_pts, 2, 5);
    lv_obj_t *hub = lv_obj_create(analog_clock);
    lv_obj_set_size(hub, 16, 16);
    lv_obj_align(hub, LV_ALIGN_CENTER, 0, 0);
    style_flat(hub);
    lv_obj_set_style_radius(hub, LV_RADIUS_CIRCLE, 0);
    lv_obj_set_style_bg_color(hub, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_bg_opa(hub, LV_OPA_COVER, 0);
    lv_obj_clear_flag(hub, LV_OBJ_FLAG_CLICKABLE);
    lv_obj_add_flag(analog_clock, LV_OBJ_FLAG_HIDDEN);
    lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
    lv_obj_add_event_cb(clock_layer, dismiss_idle, LV_EVENT_PRESSED, NULL);
    lv_obj_add_event_cb(analog_clock, dismiss_idle, LV_EVENT_PRESSED, NULL);
    dim_popup = lv_obj_create(screen);
    lv_obj_set_size(dim_popup, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES);
    lv_obj_set_style_bg_color(dim_popup, lv_color_hex(0x000000), 0);
    lv_obj_set_style_bg_opa(dim_popup, LV_OPA_80, 0);
    style_flat(dim_popup);
    lv_obj_t *dim_card = lv_obj_create(dim_popup);
    lv_obj_set_size(dim_card, 720, 340);
    lv_obj_center(dim_card);
    lv_obj_set_style_bg_color(dim_card, lv_color_hex(0x1A1A1A), 0);
    lv_obj_set_style_radius(dim_card, 12, 0);
    style_flat(dim_card);
    lv_obj_t *dim_title = lv_label_create(dim_card);
    dim_title_label = dim_title;
    lv_label_set_text(dim_title, "Dimmen");
    lv_obj_set_style_text_color(dim_title, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(dim_title, &lv_font_montserrat_28, 0);
    lv_obj_align(dim_title, LV_ALIGN_TOP_MID, 0, 24);
    dim_slider = lv_slider_create(dim_card);
    lv_obj_set_size(dim_slider, 640, 28);
    lv_obj_align(dim_slider, LV_ALIGN_TOP_MID, 0, 80);
    lv_obj_set_style_radius(dim_slider, 14, LV_PART_MAIN);
    lv_obj_set_style_radius(dim_slider, 14, LV_PART_INDICATOR);
    lv_obj_set_style_pad_all(dim_slider, 14, LV_PART_KNOB);
    lv_obj_set_style_radius(dim_slider, LV_RADIUS_CIRCLE, LV_PART_KNOB);
    lv_obj_add_event_cb(dim_slider, dim_slider_event, LV_EVENT_RELEASED, NULL);
    lv_obj_set_ext_click_area(dim_slider, 24);
    lv_obj_t *dim_close = lv_btn_create(dim_card);
    lv_obj_set_size(dim_close, 180, 56);
    lv_obj_align(dim_close, LV_ALIGN_BOTTOM_MID, 0, -28);
    lv_obj_t *dim_close_lbl = lv_label_create(dim_close);
    lv_label_set_text(dim_close_lbl, "OK");
    lv_obj_set_style_text_font(dim_close_lbl, &lv_font_montserrat_28, 0);
    lv_obj_center(dim_close_lbl);
    lv_obj_add_event_cb(dim_close, dim_popup_close_event, LV_EVENT_CLICKED, NULL);
    lv_obj_add_flag(dim_popup, LV_OBJ_FLAG_HIDDEN);
    build_test_layer(screen);
    build_boot_layer(screen);
    set_boot_message("Hausanzeige startet", "Anzeige bereit");
    lvgl_port_unlock();
    touched_at = esp_timer_get_time();
    offline_since = touched_at;
    lv_timer_create(clock_tick, 1000, NULL);
}

void homedisplay_ui_set_pairing_code(const char *code)
{
    if (!code) {
        setup_pair_code[0] = '\0';
        return;
    }
    snprintf(setup_pair_code, sizeof(setup_pair_code), "%s", code);
}

void homedisplay_ui_setup_progress(const char *detail)
{
    if (!detail || !boot_screen_active) {
        return;
    }
    if (strcmp(last_setup_detail, detail) == 0) {
        return;
    }
    snprintf(last_setup_detail, sizeof(last_setup_detail), "%s", detail);
    if (!ui_client_lock()) return;
    boot_screen_active = true;
    if (boot_title) {
        lv_label_set_text(boot_title, "Kopplungscode");
    }
    if (boot_code && setup_pair_code[0]) {
        lv_label_set_text(boot_code, setup_pair_code);
        lv_obj_clear_flag(boot_code, LV_OBJ_FLAG_HIDDEN);
    }
    if (boot_detail) {
        label_set_folded(boot_detail, detail);
        lv_obj_clear_flag(boot_detail, LV_OBJ_FLAG_HIDDEN);
    }
    if (boot_layer) {
        lv_obj_clear_flag(boot_layer, LV_OBJ_FLAG_HIDDEN);
        if (!boot_layer_on_top) {
            lv_obj_move_foreground(boot_layer);
            boot_layer_on_top = true;
        }
    }
    snprintf(last_boot_title, sizeof(last_boot_title), "Kopplungscode");
    snprintf(last_boot_detail, sizeof(last_boot_detail), "%s", detail);
    lvgl_port_unlock();
}

void homedisplay_ui_status(const char *text)
{
    if (!text) {
        return;
    }
    char title[120];
    char detail[280];
    split_status_text(text, title, sizeof(title), detail, sizeof(detail));
    if (boot_screen_active && setup_pair_code[0] && detail[0]) {
        homedisplay_ui_setup_progress(detail);
    }
    if (!ui_client_lock()) return;
    if (boot_screen_active && boot_layer && setup_pair_code[0] && detail[0]) {
        lvgl_port_unlock();
        return;
    }
    if (boot_screen_active && boot_layer) {
        set_boot_message(title, detail);
    }
    /* Statuszeile links oben absichtlich ausgeblendet. */
    if (status_label) {
        lv_obj_add_flag(status_label, LV_OBJ_FLAG_HIDDEN);
    }
    lvgl_port_unlock();
}

void homedisplay_ui_apply_layout(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root) return;
    cJSON *pages = cJSON_GetObjectItem(root, "pages");
    int count = cJSON_GetArraySize(pages);
    if (count <= 0) {
        cJSON_Delete(root);
        homedisplay_ui_setup_progress("Vorlage leer oder ungültig\nIn IPS der Instanz ein Layout zuweisen\nund veröffentlichen");
        return;
    }
    if (!ui_client_lock()) {
        cJSON_Delete(root);
        ESP_LOGW(TAG_UI, "Layout ausgelassen, Anzeige belegt");
        return;
    }
    if (layout_root) cJSON_Delete(layout_root);
    layout_root = root;
    const char *cm = cJSON_GetStringValue(cJSON_GetObjectItem(root, "colorMode"));
    color_mono = cm && strcmp(cm, "mono") == 0;
    cJSON *theme = cJSON_GetObjectItem(root, "theme");
    theme_bg = 0x102033;
    theme_button = 0x2A4F7C;
    theme_accent = 0xFFB547;
    if (cJSON_IsObject(theme)) {
        theme_bg = parse_hex_color(cJSON_GetStringValue(cJSON_GetObjectItem(theme, "background")), theme_bg);
        theme_button = parse_hex_color(cJSON_GetStringValue(cJSON_GetObjectItem(theme, "button")), theme_button);
        theme_accent = parse_hex_color(cJSON_GetStringValue(cJSON_GetObjectItem(theme, "accent")), theme_accent);
    }
    ESP_LOGI(TAG_UI, "Layout anwenden (%d Seiten, mono=%d, theme bg=%06lx button=%06lx accent=%06lx)",
             count, (int) color_mono, (unsigned long) theme_bg, (unsigned long) theme_button, (unsigned long) theme_accent);
    last_setup_detail[0] = '\0';
    /* Boot-Screen erst nach dem Zeichnen ausblenden – sonst sieht man RGB-Müll (grün). */
    show_page_may_yield = true;
    show_page_lock_lost = false;
    show_page(0);
    if (!show_page_lock_lost) {
        hide_boot_screen();
        lvgl_port_unlock();
    } else {
        ui_wdt_kick();
    }
    show_page_may_yield = false;
    ESP_LOGI(TAG_UI, "Layout fertig (%d Kacheln)", tile_count);
}

bool homedisplay_ui_layout_ready(void)
{
    return layout_root != NULL;
}

void homedisplay_ui_apply_config(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root) return;
    cJSON *item = cJSON_GetObjectItem(root, "idleMode");
    if (item) idle_mode = item->valueint;
    item = cJSON_GetObjectItem(root, "idleClockStyle");
    if (cJSON_IsString(item) && item->valuestring) {
        snprintf(idle_clock_style, sizeof(idle_clock_style), "%s", item->valuestring);
    }
    item = cJSON_GetObjectItem(root, "idleTimeoutSec");
    if (cJSON_IsNumber(item)) {
        int seconds = item->valueint;
        if (seconds < 5) seconds = 5;
        if (seconds > 3600) seconds = 3600;
        idle_timeout_us = (int64_t) seconds * 1000000;
    } else {
        idle_timeout_us = 60000000;
    }
    item = cJSON_GetObjectItem(root, "dayBrightness");
    if (item) day_brightness = item->valueint;
    item = cJSON_GetObjectItem(root, "nightBrightness");
    if (item) night_brightness = item->valueint;
    item = cJSON_GetObjectItem(root, "nightFrom");
    if (item) night_from = item->valueint;
    item = cJSON_GetObjectItem(root, "nightTo");
    if (item) night_to = item->valueint;
    cJSON_Delete(root);
    if (day_brightness <= 0 && night_brightness <= 0) {
        backlight_on = false;
        homedisplay_board_backlight(false);
    }
}

void homedisplay_ui_apply_state(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root) return;
    if (cJSON_IsTrue(cJSON_GetObjectItem(root, "unchanged"))) {
        cJSON_Delete(root);
        return;
    }
    if (json && json[0]) {
        snprintf(last_state_json, sizeof(last_state_json), "%s", json);
    }
    cJSON *now = cJSON_GetObjectItem(root, "now");
    if (now) homedisplay_ui_set_time((int64_t) now->valuedouble);
    if (!ui_client_lock()) {
        cJSON_Delete(root);
        return;
    }
    apply_state_body(root);
    lvgl_port_unlock();
    cJSON_Delete(root);
}

static void paint_chart(tile_view_t *view, cJSON *tile)
{
    if (!view || !view->chart_line || !view->chart_points) return;
    cJSON *series = cJSON_GetObjectItem(tile, "series");
    if (!cJSON_IsArray(series)) return;
    int total = cJSON_GetArraySize(series);
    if (total < 2) return;
    int count = total > CHART_POINT_MAX ? CHART_POINT_MAX : total;
    double y_min = 0;
    double y_max = 1;
    cJSON *min_item = cJSON_GetObjectItem(tile, "seriesMin");
    cJSON *max_item = cJSON_GetObjectItem(tile, "seriesMax");
    if (cJSON_IsNumber(min_item)) y_min = min_item->valuedouble;
    if (cJSON_IsNumber(max_item)) y_max = max_item->valuedouble;
    double range = y_max - y_min;
    if (range <= 0.0) range = 1.0;
    int plot_w = view->chart_w > 1 ? view->chart_w : 2;
    int plot_h = view->chart_h > 1 ? view->chart_h : 2;
    for (int index = 0; index < count; index++) {
        int source = count == total ? index : (int) ((long) index * (total - 1) / (count - 1));
        cJSON *point = cJSON_GetArrayItem(series, source);
        double value = cJSON_IsNumber(point) ? point->valuedouble : y_min;
        double norm = (value - y_min) / range;
        if (norm < 0.0) norm = 0.0;
        if (norm > 1.0) norm = 1.0;
        view->chart_points[index].x = (lv_coord_t) ((plot_w - 1) * index / (count - 1));
        view->chart_points[index].y = (lv_coord_t) (plot_h - 1 - (int) (norm * (plot_h - 1)));
    }
    lv_line_set_points(view->chart_line, view->chart_points, (uint16_t) count);
}

static void apply_state_body(cJSON *root)
{
    if (!root || cJSON_IsTrue(cJSON_GetObjectItem(root, "unchanged"))) return;
    cJSON *tile = NULL;
    cJSON_ArrayForEach(tile, cJSON_GetObjectItem(root, "tiles")) {
        const char *tile_id = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "tileId"));
        const char *text = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "text"));
        const char *color = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "color"));
        cJSON *value = cJSON_GetObjectItem(tile, "value");
        if (!tile_id) continue;
        for (int index = 0; index < tile_count; index++) {
            if (strcmp(tiles[index].tile_id, tile_id) != 0) continue;
            if (tiles[index].chart_line) paint_chart(&tiles[index], tile);
            int number = tiles[index].value;
            bool has_number = false;
            if (cJSON_IsNumber(value)) {
                number = (int) value->valuedouble;
                has_number = true;
            } else if (cJSON_IsBool(value)) {
                if (control_is_dimmer(tiles[index].control) || strcmp(tiles[index].control, "switch") == 0) {
                    number = cJSON_IsTrue(value) ? tiles[index].value_on : tiles[index].value_off;
                } else {
                    number = cJSON_IsTrue(value) ? 1 : 0;
                }
                has_number = true;
            }
            if (tiles[index].holding) {
                bool incoming_on = has_number && value_is_on(&tiles[index], number);
                bool held_on = value_is_on(&tiles[index], tiles[index].held_value);
                bool level = tiles[index].track || tiles[index].power;
                bool matched = has_number && (level ? number == tiles[index].held_value : incoming_on == held_on);
                if (esp_timer_get_time() > tiles[index].held_until || matched) {
                    tiles[index].holding = false;
                } else {
                    continue;
                }
            }
            if (tiles[index].label) label_set_folded(tiles[index].label, text ? text : "-");
            apply_status_color(&tiles[index], color);
            if (!has_number) continue;
            tiles[index].applying = true;
            tiles[index].value = number;
            bool on = value_is_on(&tiles[index], number);
            if (tiles[index].battery_fill && tiles[index].battery_shell) {
                place_battery(&tiles[index], number);
            } else if (tiles[index].track) {
                if (on) tiles[index].level = number;
                place_level(&tiles[index], number);
                if (tiles[index].power) set_power_checked(&tiles[index], on);
            }
            refresh_icons(&tiles[index], on);
            if (control_is_dimmer(tiles[index].control)) {
                paint_dim_label(&tiles[index]);
            } else if (tiles[index].has_action && tiles[index].label && tiles[index].show_state_text) {
                label_set_folded(tiles[index].label, on ? "Ein" : "Aus");
            }
            if (tiles[index].has_action && tiles[index].card && strcmp(tiles[index].control, "switch") == 0) {
                lv_obj_set_style_bg_color(tiles[index].card,
                    lv_color_hex(on ? tile_on_color(&tiles[index]) : tiles[index].base_bg), 0);
            }
            if (!tiles[index].has_action && tiles[index].has_threshold && tiles[index].card) {
                uint32_t bg = tiles[index].base_bg;
                if (number >= tiles[index].threshold) {
                    if (strcmp(tiles[index].status_color, "ok") == 0) bg = 0x1E6B3A;
                    else if (strcmp(tiles[index].status_color, "warn") == 0) bg = 0x8A6A12;
                    else if (strcmp(tiles[index].status_color, "alarm") == 0) bg = 0x8A2424;
                }
                lv_obj_set_style_bg_color(tiles[index].card, lv_color_hex(bg), 0);
            }
            tiles[index].applying = false;
        }
    }
}

void homedisplay_ui_refresh_camera(const char *tile_id, const char *file)
{
    if (!tile_id || !tile_id[0] || !file || !file[0]) return;
    if (!storage_image_ready_limit(file, 400 * 1024)) return;
    char path[96];
    snprintf(path, sizeof(path), "S:/%s", file);
    if (!ui_client_lock()) return;
    for (int index = 0; index < tile_count; index++) {
        if (strcmp(tiles[index].tile_id, tile_id) == 0 && tiles[index].camera_img) {
            lv_img_set_src(tiles[index].camera_img, path);
            break;
        }
    }
    lvgl_port_unlock();
}

void homedisplay_ui_refresh_image(const char *tile_id, const char *file)
{
    if (!tile_id || !tile_id[0] || !file || !file[0]) return;
    if (!storage_image_ready_limit(file, 400 * 1024)) return;
    char path[96];
    snprintf(path, sizeof(path), "S:/%s", file);
    if (!ui_client_lock()) return;
    for (int index = 0; index < tile_count; index++) {
        if (strcmp(tiles[index].tile_id, tile_id) == 0 && tiles[index].image_img) {
            lv_img_set_src(tiles[index].image_img, path);
            break;
        }
    }
    lvgl_port_unlock();
}

void homedisplay_ui_set_online(bool value, int rssi)
{
    wifi_rssi = rssi;
    if (online && !value) offline_since = esp_timer_get_time();
    online = value;
    if (!grid) return;
    if (!ui_client_lock()) return;
    lv_obj_set_style_opa(grid, value ? LV_OPA_COVER : LV_OPA_70, 0);
    lvgl_port_unlock();
}

void homedisplay_ui_test_pattern(bool on)
{
    if (!test_layer) return;
    if (!ui_client_lock()) return;
    if (on) {
        lv_obj_clear_flag(test_layer, LV_OBJ_FLAG_HIDDEN);
        lv_obj_move_foreground(test_layer);
    } else {
        lv_obj_add_flag(test_layer, LV_OBJ_FLAG_HIDDEN);
    }
    lvgl_port_unlock();
}

uint32_t homedisplay_ui_frames(void)
{
    return frame_counter;
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

bool homedisplay_ui_page_visible(void)
{
    return layout_root != NULL && !idle_active && backlight_on && !boot_screen_active;
}

bool homedisplay_ui_take_refresh(void)
{
    bool pending = refresh_now;
    refresh_now = false;
    return pending;
}
