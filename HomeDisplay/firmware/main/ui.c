#include "homedisplay.h"
#include "camjpeg.h"
#include "esp_heap_caps.h"
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
#include "miniz.h"
#include "src/extra/libs/png/lodepng.h"

#include <math.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

static const char *TAG_UI = "ui";

#define TILE_MAX 48
#define FLOW_LINES 6
#define FLOW_PTS 12
#define FLOW_SLOT_MAX 4
#define FLOW_CONS_MAX 6

/* Energiefluss wie die Energieverteilung in Home Assistant: Kreise für PV, Netz, Haus und Batterie,
 * Linien dazwischen mit wandernden Punkten. Linien: PV→Haus, PV→Netz, PV→Batterie, Netz→Haus, Netz→Batterie, Batterie→Haus. */
typedef struct flow_slot {
    lv_obj_t *card;
    lv_point_t pts[FLOW_LINES][FLOW_PTS];
    uint8_t npts[FLOW_LINES];
    lv_obj_t *line[FLOW_LINES];
    lv_obj_t *dot[FLOW_LINES];
    int8_t pct[FLOW_LINES];
    uint16_t phase[FLOW_LINES];
    lv_obj_t *pv_val;
    lv_obj_t *grid_in;
    lv_obj_t *grid_out;
    lv_obj_t *bat_in;
    lv_obj_t *bat_out;
    lv_obj_t *home_val;
    lv_obj_t *soc_val;
    lv_obj_t *home_arc[3];
    lv_obj_t *cons_val[FLOW_CONS_MAX];
    int cons_count;
} flow_slot_t;

static flow_slot_t flow_pool[FLOW_SLOT_MAX];
static int flow_slot_count;
static lv_timer_t *flow_timer;
#define HEADER_H 68
#define HEADER_LEFT 72
#define HEADER_RIGHT 160
#define TAB_H 56
#define TAB_MIN_W 80

/* true nur wenn show_page vom Client-Task kommt – dann LVGL-Lock zwischen Kacheln freigeben. */
static bool show_page_may_yield;
#define CHART_SLOT_MAX 8
#define CHART_POINT_MAX 56
#define UI_CLIENT_LOCK_MS 2000
#define VALUE_RULE_MAX 12
#define VALUE_RULE_POOL 96

typedef struct {
    float from;
    float to;
    bool has_from;
    bool has_to;
    char icon[24];
    bool after;
    uint32_t icon_color;
    uint32_t text_color;
    uint32_t bg_color;
    bool has_icon_color;
    bool has_text_color;
    bool has_bg_color;
    char text[16];
    char align[8];
    char image_id[20];
    char image_file[28];
} value_rule_t;
static lv_point_t chart_point_pool[CHART_SLOT_MAX][CHART_POINT_MAX];
static lv_point_t chart_yest_pool[CHART_SLOT_MAX][CHART_POINT_MAX];
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
    int pin;
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
    int camera_dy;
    lv_obj_t *image_img;
    bool image_backdrop;
    uint8_t *image_px;
    size_t image_px_cap;
    lv_img_dsc_t image_dsc;
    lv_obj_t *chart_line;
    lv_obj_t *chart_note;
    int chart_logged;
    lv_point_t *chart_points;
    lv_point_t *chart_yest;
    int chart_count;
    int chart_yest_count;
    int chart_w;
    int chart_h;
    int chart_pad_l;
    int chart_pad_b;
    int chart_plot_w;
    int chart_plot_h;
    bool chart_area;
    bool chart_axes;
    bool chart_grid;
    bool chart_show_value;
    uint32_t chart_color;
    char chart_period[8];
    char chart_unit[16];
    int chart_decimals;
    lv_obj_t *chart_y_label[3];
    lv_obj_t *chart_x_label[3];
    lv_obj_t *icon_label;
    lv_obj_t *title_icon;
    bool icon_large;
    char icon_name[24];
    char icon_mode[12];
    char icon_on[24];
    char icon_off[24];
    uint32_t icon_off_ink;
    uint32_t icon_on_ink;
    bool own_icon_ink;
    int battery_pad;
    uint32_t base_bg;
    uint32_t on_bg;
    bool own_bg;
    bool bg_clear;
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
    int value_rule_first;
    int value_rule_count;
    char rule_image[28];
    bool have_number;
    float live_number;
    bool holding;
    int held_value;
    int64_t held_until;
    int64_t last_tap_at;
    bool text_bold;
    bool text_italic;
    bool text_underline;
    bool value_row;
    bool show_title_row;
    lv_obj_t *title_row;
    lv_obj_t *value_box;
    char row_align[8];
    char icon_place[8];
    char title_icon_name[24];
    lv_obj_t *title_label;
    lv_obj_t *age_label;
    lv_obj_t *arc;
    lv_obj_t *rows[6];
    lv_obj_t *dots[6];
    flow_slot_t *flow;
    int title_px;
    int text_px;
    bool title_bold;
    bool title_italic;
    bool own_title;
    uint32_t title_color;
    bool show_age;
    bool schedule;
    uint32_t schedule_stamp;
    int gauge_min;
    int gauge_max;
} tile_view_t;

static void chart_draw_event(lv_event_t *event);
static void build_energy_flow(tile_view_t *view, cJSON *tile, int card_w, int card_h, bool show_title);
static void paint_energy_flow(tile_view_t *view, cJSON *flow);
static void layout_chart_head(tile_view_t *view);

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
static uint32_t title_ink(const tile_view_t *tile)
{
    if (color_mono) return 0xFFFFFF;
    if (tile && tile->own_title) return tile->title_color;
    return text_fg();
}

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
        /* Gedankenstrich und Halbgeviertstrich fehlen in der Display-Schrift und würden als Kästchen erscheinen. */
        if (p[0] == 0xE2 && p[1] == 0x80 && (p[2] == 0x93 || p[2] == 0x94)) {
            if (o + 1 < dst_len) dst[o++] = '-';
            p += 3;
            continue;
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
static lv_obj_t *clock_hit;
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
static lv_obj_t *clock_layer;
static lv_obj_t *clock_label;
static lv_obj_t *test_layer;
static lv_obj_t *test_label;
static lv_obj_t *system_label;
static cJSON *layout_root;
static tile_view_t tiles[TILE_MAX];
static int tile_count;
/* Regeln liegen einmal für alle Kacheln, nicht zwölfmal in jeder Kachel. */
static value_rule_t value_rule_pool[VALUE_RULE_POOL];
static int value_rule_used;

/* Kamerabilder liegen im Speicher, nicht im Flash. Zwei RGB565-Flächen je Kachel:
 * eine wird gezeigt, die andere wird dekodiert. Das letzte Bild bleibt beim Seitenwechsel erhalten. */
#define CAMERA_SLOTS 3
#define CAMERA_MAX_PX (1024 * 600)
typedef struct {
    char tile_id[40];
    uint16_t *buf[2];
    size_t cap_px[2];
    lv_img_dsc_t dsc[2];
    int shown;
} camera_slot_t;
static camera_slot_t camera_slots[CAMERA_SLOTS];
static int page_index;
static char persisted_page_id[24];
static bool persisted_page_loaded;

static void ensure_persisted_page(void)
{
    if (persisted_page_loaded) return;
    persisted_page_loaded = true;
    homedisplay_nvs_get("page", persisted_page_id, sizeof(persisted_page_id));
}

static void store_page_id(const char *id)
{
    if (!id) id = "";
    if (strcmp(persisted_page_id, id) == 0) return;
    snprintf(persisted_page_id, sizeof(persisted_page_id), "%s", id);
    homedisplay_nvs_set("page", persisted_page_id);
}
static int columns = 2;
static int idle_mode;
static char idle_clock_style[16] = "digital";
static bool idle_active;
static lv_obj_t *dim_popup;
static lv_obj_t *dim_slider;
static lv_obj_t *dim_title_label;
static tile_view_t *dim_tile;
static char layout_pin1[9];
static char layout_pin2[9];
static lv_obj_t *pin_popup;
static lv_obj_t *pin_title_label;
static lv_obj_t *pin_dots_label;
static lv_obj_t *pin_status_label;
static char pin_entry[9];
static int pin_len;
static tile_view_t *pin_tile;
static int pin_kind;
static int pin_value;
static int pin_revert;
static bool pin_painted;
static char pin_open_for[40];
static int pin_fails;
static int64_t pin_lock_until;
static int64_t pin_deadline;
static tile_view_t *knob_drag_tile;
static int knob_drag_from;
enum { PIN_ACT_SEND = 1, PIN_ACT_DIM = 2 };
static lv_obj_t *analog_clock;
static lv_obj_t *analog_hour;
static lv_obj_t *analog_min;
static lv_point_t analog_hour_pts[2];
static lv_point_t analog_min_pts[2];
static lv_point_t analog_tick_pts[12][2];
/* Voller letzter Stand inkl. Verlaufsdaten (oft über 8 KB). Wechsel nur unter der LVGL-Sperre. */
static char *last_state_json;
static volatile bool refresh_now;
static volatile bool backlight_on = true;
static int day_brightness = 70;
static int night_brightness = 15;
static int night_from = 22;
static int night_to = 6;
static bool night_dim = true;
static bool locked;
static bool online = true;
static int wifi_rssi;
static int64_t offline_since;
static int64_t touched_at;
static bool force_idle;
/* Derselbe Finger, der die Uhrzeit trifft, darf die Ruheanzeige nicht sofort wieder schließen. */
static int64_t idle_guard_until;
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
    lv_obj_set_style_text_font(boot_title, &montserrat_bold_48, 0);
    lv_label_set_text(boot_title, "Hausanzeige startet");

    boot_code = lv_label_create(card);
    lv_obj_set_width(boot_code, 840);
    lv_obj_set_style_text_align(boot_code, LV_TEXT_ALIGN_CENTER, 0);
    lv_obj_set_style_text_color(boot_code, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(boot_code, &montserrat_bold_48, 0);
    lv_label_set_text(boot_code, "");
    lv_obj_add_flag(boot_code, LV_OBJ_FLAG_HIDDEN);

    boot_detail = lv_label_create(card);
    lv_obj_set_width(boot_detail, 840);
    lv_label_set_long_mode(boot_detail, LV_LABEL_LONG_WRAP);
    lv_obj_set_style_text_align(boot_detail, LV_TEXT_ALIGN_CENTER, 0);
    lv_obj_set_style_text_color(boot_detail, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(boot_detail, &montserrat_bold_36, 0);
    lv_obj_set_style_text_line_space(boot_detail, 10, 0);
    lv_label_set_text(boot_detail, "");

    boot_footer = lv_label_create(boot_layer);
    lv_obj_set_style_text_color(boot_footer, lv_color_hex(0xAAAAAA), 0);
    lv_obj_set_style_text_font(boot_footer, &montserrat_bold_28, 0);
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
    {"circulation", 0xF058F}, {"circulationOff", 0xF0F93},
    {"valve", 0xF1066}, {"alarm", 0xF078F}, {"home", 0xF02DC}, {"clock", 0xF0150}, {"info", 0xF02FD},
    {"humidity", 0xF058E}, {"sun", 0xF0599}, {"moon", 0xF0594}, {"water", 0xF058C}, {"heat", 0xF0438},
    {"cool", 0xF0717}, {"fan", 0xF0210}, {"door", 0xF081A}, {"lock", 0xF033E}, {"plug", 0xF06A5},
    {"batteryIcon", 0xF0079}, {"batteryEmpty", 0xF008E}, {"battery20", 0xF007B}, {"battery40", 0xF007D},
    {"battery60", 0xF007F}, {"battery80", 0xF0081}, {"batteryFull", 0xF0079},
    {"boiler", 0xF0F92}, {"solarModules", 0xF0D9C},
    {"wifi", 0xF05A9}, {"car", 0xF010B}, {"motion", 0xF0D91}, {"window", 0xF05AE},
    {"garage", 0xF06D9}, {"smoke", 0xF0392}, {"thermostat", 0xF0393}, {"solar", 0xF0A72}, {"camera", 0xF07AE},
    {"tv", 0xF0502}, {"speaker", 0xF04C3}, {"washing", 0xF072A}, {"bed", 0xF02E3}, {"bell", 0xF009A},
    {"leaf", 0xF032A}, {"fire", 0xF0238},
    {"kitchen", 0xF04DE}, {"pool", 0xF0606}, {"hall", 0xF181E}, {"kids", 0xF18FB},
    {"bubbles", 0xF0CD1}, {"bubbleChart", 0xF05E3}, {"waves", 0xF078D},
    {"shutterOpen", 0xF111E}, {"valveOpen", 0xF1068}, {"valveClosed", 0xF1067},
    {"pumpOff", 0xF1B22}, {"doorOpen", 0xF081C}, {"doorClosed", 0xF081B},
    {"garageOpen", 0xF06DA}, {"lockOpen", 0xF033F}, {"fanOff", 0xF081D},
    {"plugOff", 0xF06A6}, {"bellOff", 0xF009B}, {"wifiOff", 0xF05AA},
    {"speakerOff", 0xF04C4}, {"tvOff", 0xF083B}, {"waterOff", 0xF058D},
    {"coolOff", 0xF14E3}, {"fireOff", 0xF1722}, {"motionOff", 0xF1435},
    {"smokeOff", 0xF1809}, {"windowOpen", 0xF05B1}, {"blindsOpen", 0xF1011},
    {"blindsClosed", 0xF00AC}, {"bathtub", 0xF1818}, {"armchair", 0xF156E},
    {"floorLamp", 0xF08DD}, {"book", 0xF00BA}, {"power", 0xF0425},
    {"desk", 0xF1239}, {"hammerWrench", 0xF188D},
    {"check", 0xF012C}, {"close", 0xF0156}, {"transmissionTower", 0xF0D3E},
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

static uint32_t icon_state_ink(const tile_view_t *tile, bool on)
{
    if (tile && tile->own_icon_ink) return on ? tile->icon_on_ink : tile->icon_off_ink;
    return icon_ink(tile);
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
    if (tile->icon_label && shown) {
        paint_icon_label(tile->icon_label, shown, on, tile->icon_large, icon_state_ink(tile, on));
    }
    if (tile->title_icon && tile->title_icon_name[0] && strcmp(tile->icon_mode, "toggle") != 0) {
        show_icon_label(tile->title_icon, tile, tile->title_icon_name, on, false);
    }
}

static const lv_font_t *font_for(int px, bool bold, bool italic);

/* Symbol direkt in der Zeile. Ein Zoom-Slot schneidet die Glyphe in LVGL 8 ab. */
static lv_obj_t *make_icon_label(lv_obj_t *parent, tile_view_t *view, const char *icon, lv_obj_t **label_out, bool large)
{
    lv_obj_t *label = lv_label_create(parent);
    if (label_out) *label_out = label;
    show_icon_label(label, view, icon, false, large);
    lv_obj_set_style_flex_grow(label, 0, 0);
    lv_obj_set_style_min_width(label, large ? 48 : 28, 0);
    lv_obj_clear_flag(label, LV_OBJ_FLAG_CLICKABLE);
    return label;
}

static lv_coord_t text_width_capped(const char *text, const lv_font_t *font, lv_coord_t max_w)
{
    lv_point_t size;
    lv_txt_get_size(&size, text && text[0] ? text : " ", font, 0, 0, LV_COORD_MAX, LV_TEXT_FLAG_NONE);
    lv_coord_t width = size.x + 2;
    if (width > max_w) width = max_w;
    if (width < 8) width = 8;
    return width;
}

/* Erste passende Zeile gewinnt; ohne Von und Bis gilt eine Zeile immer, auch ohne Messwert. */
static const value_rule_t *value_rule_match(const tile_view_t *view)
{
    if (!view) return NULL;
    for (int i = 0; i < view->value_rule_count; i++) {
        int slot = view->value_rule_first + i;
        if (slot < 0 || slot >= value_rule_used) break;
        const value_rule_t *rule = &value_rule_pool[slot];
        if (!rule->has_from && !rule->has_to) return rule;
        if (!view->have_number) continue;
        if (rule->has_from && view->live_number < rule->from - 0.0001f) continue;
        if (rule->has_to && view->live_number > rule->to + 0.0001f) continue;
        return rule;
    }
    return NULL;
}

static uint32_t value_text_ink(const tile_view_t *view, const value_rule_t *rule)
{
    if (rule && rule->has_text_color) return rule->text_color;
    return view->own_text ? view->text_color : 0xF4F7FB;
}

static bool apply_value_rule_icon(tile_view_t *view, lv_coord_t text_h)
{
    if (!view || !view->icon_label || !view->value_row) return false;
    const value_rule_t *rule = value_rule_match(view);
    const char *icon = NULL;
    bool after = false;
    uint32_t ink = icon_ink(view);
    if (view->value_rule_count > 0) {
        if (rule && rule->icon[0]) {
            icon = rule->icon;
            after = rule->after;
            ink = rule->has_icon_color ? rule->icon_color : value_text_ink(view, rule);
        }
    } else if (view->icon_name[0] && strcmp(view->icon_place, "none") != 0) {
        icon = view->icon_name;
        after = strcmp(view->icon_place, "after") == 0;
    }
    if (!icon) {
        lv_obj_add_flag(view->icon_label, LV_OBJ_FLAG_HIDDEN);
        return false;
    }
    if (after) lv_obj_move_foreground(view->icon_label);
    else lv_obj_move_background(view->icon_label);
    lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_flex_grow(view->icon_label, 0, 0);
    paint_icon_label(view->icon_label, icon, false, text_h > 36, ink);
    return true;
}

static void place_cover_image(tile_view_t *view, const char *file, int box_w, int box_h);
static bool show_icon_fit(tile_view_t *view, const char *icon, int box_w, int box_h, uint32_t ink);

static void apply_image_switch(tile_view_t *view, const value_rule_t *rule)
{
    bool picture = rule && rule->image_file[0];
    if (view->image_img) {
        if (picture) {
            lv_obj_clear_flag(view->image_img, LV_OBJ_FLAG_HIDDEN);
            int box_w = view->card ? lv_obj_get_width(view->card) : 0;
            int box_h = view->card ? lv_obj_get_height(view->card) : 0;
            if (box_w < 16) box_w = 120;
            if (box_h < 16) box_h = 80;
            bool file_changed = strcmp(view->rule_image, rule->image_file) != 0;
            bool size_changed = lv_obj_get_width(view->image_img) != box_w || lv_obj_get_height(view->image_img) != box_h;
            if (file_changed || size_changed) {
                snprintf(view->rule_image, sizeof(view->rule_image), "%s", rule->image_file);
                place_cover_image(view, rule->image_file, box_w, box_h);
            }
            if (rule->has_icon_color) {
                lv_obj_set_style_img_recolor(view->image_img, lv_color_hex(rule->icon_color), 0);
                lv_obj_set_style_img_recolor_opa(view->image_img, LV_OPA_50, 0);
            } else {
                lv_obj_set_style_img_recolor_opa(view->image_img, LV_OPA_TRANSP, 0);
            }
        } else {
            lv_obj_add_flag(view->image_img, LV_OBJ_FLAG_HIDDEN);
            view->rule_image[0] = 0;
        }
    }
    if (view->icon_label) lv_obj_add_flag(view->icon_label, LV_OBJ_FLAG_HIDDEN);
    if (!picture && rule && rule->icon[0] && view->image_img) {
        uint32_t ink = rule->has_icon_color ? rule->icon_color : icon_ink(view);
        int box_w = view->card ? lv_obj_get_content_width(view->card) : 0;
        int box_h = view->card ? lv_obj_get_content_height(view->card) : 0;
        if (box_w < 16) box_w = 120;
        if (box_h < 16) box_h = 80;
        view->rule_image[0] = 0;
        lv_obj_clear_flag(view->image_img, LV_OBJ_FLAG_HIDDEN);
        lv_obj_set_style_img_recolor_opa(view->image_img, LV_OPA_TRANSP, 0);
        if (!show_icon_fit(view, rule->icon, box_w, box_h, ink) && view->icon_label) {
            lv_obj_add_flag(view->image_img, LV_OBJ_FLAG_HIDDEN);
            lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_HIDDEN);
            paint_icon_label(view->icon_label, rule->icon, false, true, ink);
        }
    }
}

/* Schalter und Bildschalter: die erste passende Zeile setzt Symbol, Text und Farbe. */
static void apply_action_rules(tile_view_t *view, int number)
{
    if (!view || view->value_rule_count <= 0) return;
    view->have_number = true;
    view->live_number = (float) number;
    const value_rule_t *rule = value_rule_match(view);
    if (strcmp(view->control, "image") == 0) {
        apply_image_switch(view, rule);
        return;
    }
    if (view->icon_label) {
        if (rule && rule->icon[0]) {
            uint32_t ink = rule->has_icon_color ? rule->icon_color : icon_ink(view);
            lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_HIDDEN);
            paint_icon_label(view->icon_label, rule->icon, false, true, ink);
        } else {
            lv_obj_add_flag(view->icon_label, LV_OBJ_FLAG_HIDDEN);
        }
    }
    if (view->label) {
        if (rule && rule->text[0]) {
            lv_obj_clear_flag(view->label, LV_OBJ_FLAG_HIDDEN);
            label_set_folded(view->label, rule->text);
            if (rule->has_text_color) lv_obj_set_style_text_color(view->label, lv_color_hex(rule->text_color), 0);
        } else if (!view->show_state_text) {
            lv_obj_add_flag(view->label, LV_OBJ_FLAG_HIDDEN);
        }
    }
    if (view->card && rule && rule->has_bg_color) {
        lv_obj_set_style_bg_color(view->card, lv_color_hex(rule->bg_color), 0);
        lv_obj_set_style_bg_opa(view->card, LV_OPA_COVER, 0);
    } else if (view->card && !view->bg_clear) {
        lv_obj_set_style_bg_color(view->card, lv_color_hex(view->base_bg), 0);
    }
}

/* Überschrift als Icon-vor-Text-Zeile. Zahl und Symbol darunter vertikal mittig. */
static void layout_value_row(tile_view_t *view)
{
    if (!view || !view->value_row || !view->label || !view->card) return;
    lv_obj_update_layout(view->card);
    lv_coord_t inner_w = lv_obj_get_content_width(view->card);
    lv_coord_t inner_h = lv_obj_get_content_height(view->card);
    if (inner_w < 24) inner_w = 24;
    if (inner_h < 16) inner_h = 16;

    const lv_font_t *font = font_for(view->text_px ? view->text_px : 28, view->text_bold, view->text_italic);
    lv_obj_set_style_text_font(view->label, font, 0);
    lv_coord_t text_h = lv_font_get_line_height(font);
    const lv_font_t *title_font = font_for(view->title_px ? view->title_px : 28, view->title_bold, view->title_italic);
    lv_coord_t title_h = 0;
    if (view->show_title_row && view->title_row) {
        title_h = lv_font_get_line_height(title_font);
        lv_obj_set_size(view->title_row, inner_w, title_h);
        lv_obj_align(view->title_row, LV_ALIGN_TOP_LEFT, 0, 0);
        lv_flex_align_t title_main = LV_FLEX_ALIGN_START;
        if (strcmp(view->row_align, "center") == 0) title_main = LV_FLEX_ALIGN_CENTER;
        else if (strcmp(view->row_align, "right") == 0) title_main = LV_FLEX_ALIGN_END;
        lv_obj_set_flex_align(view->title_row, title_main, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
        lv_obj_set_style_text_color(view->title_row, lv_color_hex(title_ink(view)), 0);
        if (view->title_icon && view->title_icon_name[0]) {
            show_icon_label(view->title_icon, view, view->title_icon_name, false, false);
            lv_obj_set_style_text_color(view->title_icon, lv_color_hex(title_ink(view)), 0);
            lv_obj_set_style_flex_grow(view->title_icon, 0, 0);
        }
        if (view->title_label) {
            lv_obj_set_style_text_font(view->title_label, title_font, 0);
            lv_obj_set_style_text_color(view->title_label, lv_color_hex(title_ink(view)), 0);
            lv_coord_t icon_w = (view->title_icon && view->title_icon_name[0]) ? 28 + 6 : 0;
            lv_coord_t max_text = inner_w - icon_w;
            if (max_text < 8) max_text = 8;
            const char *title_text = lv_label_get_text(view->title_label);
            lv_obj_set_width(view->title_label, text_width_capped(title_text, title_font, max_text));
            lv_obj_set_style_text_align(view->title_label, LV_TEXT_ALIGN_LEFT, 0);
            lv_label_set_long_mode(view->title_label, LV_LABEL_LONG_DOT);
        }
        lv_obj_move_foreground(view->title_row);
    }

    const value_rule_t *value_rule = value_rule_match(view);
    if (value_rule && value_rule->text[0] && view->label) label_set_folded(view->label, value_rule->text);
    bool want_icon = apply_value_rule_icon(view, text_h);
    if (view->value_rule_count > 0) {
        lv_obj_set_style_text_color(view->label, lv_color_hex(value_text_ink(view, value_rule)), 0);
    }
    const char *value_align = (value_rule && value_rule->align[0]) ? value_rule->align : "center";
    lv_flex_align_t value_cross = LV_FLEX_ALIGN_CENTER;
    lv_text_align_t value_text_align = LV_TEXT_ALIGN_CENTER;
    if (strcmp(value_align, "left") == 0) {
        value_cross = LV_FLEX_ALIGN_START;
        value_text_align = LV_TEXT_ALIGN_LEFT;
    } else if (strcmp(value_align, "right") == 0) {
        value_cross = LV_FLEX_ALIGN_END;
        value_text_align = LV_TEXT_ALIGN_RIGHT;
    }

    lv_coord_t icon_w = want_icon ? text_h + 6 : 0;
    lv_coord_t max_value = inner_w - icon_w;
    if (max_value < 8) max_value = 8;
    /* Nur kürzen, wenn der Text die freie Zeile wirklich überragt. Sonst bleibt die gemessene Breite. */
    const char *value_text = lv_label_get_text(view->label);
    lv_point_t value_size;
    lv_txt_get_size(&value_size, value_text && value_text[0] ? value_text : " ", font, 0, 0, LV_COORD_MAX, LV_TEXT_FLAG_NONE);
    lv_coord_t need = value_size.x + 2;
    if (need < 8) need = 8;
    if (need <= max_value) {
        lv_obj_set_width(view->label, LV_SIZE_CONTENT);
        lv_label_set_long_mode(view->label, LV_LABEL_LONG_CLIP);
    } else {
        lv_obj_set_width(view->label, max_value);
        lv_label_set_long_mode(view->label, LV_LABEL_LONG_DOT);
    }
    lv_obj_set_style_text_align(view->label, value_text_align, 0);
    lv_obj_t *value_line = lv_obj_get_parent(view->label);
    if (value_line && value_line != view->card && value_line != view->value_box) {
        lv_obj_set_width(value_line, inner_w);
        lv_obj_set_flex_align(value_line, value_cross, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    }

    if (view->value_box) {
        lv_coord_t box_h = inner_h - title_h;
        if (box_h < text_h) box_h = text_h;
        lv_obj_set_size(view->value_box, inner_w, box_h);
        lv_obj_set_flex_align(view->value_box, LV_FLEX_ALIGN_CENTER, value_cross, value_cross);
        lv_obj_align(view->value_box, LV_ALIGN_TOP_LEFT, 0, title_h);
    }
    if (view->age_label) {
        lv_obj_set_width(view->age_label, inner_w);
        lv_obj_set_style_text_font(view->age_label, &lv_font_montserrat_16, 0);
        lv_obj_set_style_text_color(view->age_label, lv_color_hex(text_fg()), 0);
        lv_obj_set_style_text_align(view->age_label, LV_TEXT_ALIGN_CENTER, 0);
    }
}

/* Überschrift und aktueller Wert teilen sich die obere Zeile. Symbol sitzt am Text. */
static void layout_chart_head(tile_view_t *view)
{
    if (!view || !view->card) return;
    lv_obj_update_layout(view->card);
    lv_coord_t inner = lv_obj_get_content_width(view->card);
    if (inner < 16) inner = 16;
    lv_coord_t value_w = 0;
    if (view->chart_show_value && view->label) {
        const lv_font_t *font = font_for(view->text_px ? view->text_px : 20, view->text_bold, view->text_italic);
        value_w = text_width_capped(lv_label_get_text(view->label), font, inner / 2) + 8;
        lv_obj_set_width(view->label, value_w);
        lv_obj_set_style_text_align(view->label, LV_TEXT_ALIGN_RIGHT, 0);
        lv_obj_align(view->label, LV_ALIGN_TOP_RIGHT, 0, 0);
    }
    if (view->title_row) {
        lv_coord_t row_w = inner - value_w;
        if (row_w < 8) row_w = 8;
        const lv_font_t *title_font = font_for(view->title_px ? view->title_px : 20, view->title_bold, view->title_italic);
        lv_coord_t title_h = lv_font_get_line_height(title_font);
        lv_obj_set_size(view->title_row, row_w, title_h);
        lv_obj_align(view->title_row, LV_ALIGN_TOP_LEFT, 0, 0);
        lv_flex_align_t main = LV_FLEX_ALIGN_START;
        if (strcmp(view->row_align, "center") == 0) main = LV_FLEX_ALIGN_CENTER;
        else if (strcmp(view->row_align, "right") == 0) main = LV_FLEX_ALIGN_END;
        lv_obj_set_flex_align(view->title_row, main, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
        if (view->title_label) {
            lv_obj_set_style_text_font(view->title_label, title_font, 0);
            lv_obj_set_style_text_color(view->title_label, lv_color_hex(title_ink(view)), 0);
            lv_coord_t max_text = row_w - (view->title_icon ? 32 : 0);
            if (max_text < 8) max_text = 8;
            lv_obj_set_width(view->title_label, LV_SIZE_CONTENT);
            lv_obj_set_style_max_width(view->title_label, max_text, 0);
            lv_label_set_long_mode(view->title_label, LV_LABEL_LONG_DOT);
        }
        lv_obj_move_foreground(view->title_row);
    }
    if (view->label) lv_obj_move_foreground(view->label);
}

static void hold_target(tile_view_t *tile, int value)
{
    if (!tile) return;
    tile->holding = true;
    tile->held_value = value;
    tile->held_until = esp_timer_get_time() + 5000000LL;
}

static int snap_font_px(int px)
{
    static const int steps[] = {16, 20, 24, 28, 32, 36, 40, 48};
    int best = 16;
    int dist = 1000;
    for (size_t i = 0; i < sizeof(steps) / sizeof(steps[0]); i++) {
        int delta = px - steps[i];
        if (delta < 0) delta = -delta;
        if (delta < dist) {
            dist = delta;
            best = steps[i];
        }
    }
    return best;
}

static const lv_font_t *font_for(int px, bool bold, bool italic)
{
    px = snap_font_px(px);
    if (bold && italic) {
        if (px == 16) return &montserrat_bolditalic_16;
        if (px == 24) return &montserrat_bolditalic_24;
        if (px == 32) return &montserrat_bolditalic_32;
        if (px == 40) return &montserrat_bolditalic_40;
        if (px >= 48) return &montserrat_bolditalic_48;
        if (px >= 36) return &montserrat_bolditalic_36;
        if (px >= 28) return &montserrat_bolditalic_28;
        return &montserrat_bolditalic_20;
    }
    if (bold) {
        if (px == 16) return &montserrat_bold_16;
        if (px == 24) return &montserrat_bold_24;
        if (px == 32) return &montserrat_bold_32;
        if (px == 40) return &montserrat_bold_40;
        if (px >= 48) return &montserrat_bold_48;
        if (px >= 36) return &montserrat_bold_36;
        if (px >= 28) return &montserrat_bold_28;
        return &montserrat_bold_20;
    }
    if (italic) {
        if (px == 16) return &montserrat_italic_16;
        if (px == 24) return &montserrat_italic_24;
        if (px == 32) return &montserrat_italic_32;
        if (px == 40) return &montserrat_italic_40;
        if (px >= 48) return &montserrat_italic_48;
        if (px >= 36) return &montserrat_italic_36;
        if (px >= 28) return &montserrat_italic_28;
        return &montserrat_italic_20;
    }
    if (px == 16) return &lv_font_montserrat_16;
    if (px == 24) return &lv_font_montserrat_24;
    if (px == 32) return &lv_font_montserrat_32;
    if (px == 40) return &lv_font_montserrat_40;
    if (px >= 48) return &lv_font_montserrat_48;
    if (px >= 36) return &lv_font_montserrat_36;
    if (px >= 28) return &lv_font_montserrat_28;
    return &lv_font_montserrat_20;
}

static void reveal_idle(void);

static void note_touch(void)
{
    if (esp_timer_get_time() < idle_guard_until) {
        touch_counter++;
        return;
    }
    touched_at = esp_timer_get_time();
    touch_counter++;
    force_idle = false;
}

static void reveal_idle_soon(lv_timer_t *timer)
{
    (void) timer;
    reveal_idle();
}

/* Tippen auf die Uhrzeit rechts oben schaltet die Ruheanzeige sofort ein.
 * Erst nach dem aktuellen Tipp: dieselbe Berührung würde die Uhr sonst im selben Ablauf wieder schließen. */
static void show_idle_now(lv_event_t *event)
{
    (void) event;
    force_idle = true;
    idle_guard_until = esp_timer_get_time() + 800000;
    touch_counter++;
    ESP_LOGI(TAG_UI, "Uhrzeit berührt, Ruheanzeige Modus %d Stil %s", idle_mode, idle_clock_style);
    lv_timer_t *timer = lv_timer_create(reveal_idle_soon, 50, NULL);
    lv_timer_set_repeat_count(timer, 1);
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
    if (strcmp(tile->control, "switch") == 0 || strcmp(tile->control, "image") == 0) return value == tile->value_on;
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
static void paint_switch_value(tile_view_t *tile, int value);
static bool pin_is_locked(void);
static bool pin_take(tile_view_t *tile, int kind, int value, int revert, bool painted);

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
    /* Eine gesetzte PIN ersetzt „Nochmal tippen“. */
    if (tile->pin == 0 && tile->confirm && (strcmp(confirm_tile, tile->tile_id) != 0 || now - confirm_at > 4000000)) {
        snprintf(confirm_tile, sizeof(confirm_tile), "%s", tile->tile_id);
        confirm_at = now;
        lv_label_set_text(tile->label, "Nochmal tippen");
        return;
    }
    confirm_tile[0] = '\0';
    if (tile->pin != 0 && pin_is_locked()) {
        if (tile->label) lv_label_set_text(tile->label, "Gesperrt");
        return;
    }
    /* Dimmer: Popup; reine Schalter: Zielzustand (Toggle), nicht den Ist-Wert. */
    if (strcmp(tile->control, "dimSwitch") == 0 || strcmp(tile->control, "slider") == 0
        || strcmp(tile->control, "combo") == 0) {
        if (strcmp(tile->dim_tap, "on") != 0 && strcmp(tile->dim_tap, "off") != 0) {
            if (pin_take(tile, PIN_ACT_DIM, 0, tile->value, false)) return;
            open_dim_popup(tile);
        }
        return;
    }
    int previous = tile->value;
    int next = toggle_target(tile);
    paint_switch_value(tile, next);
    if (pin_take(tile, PIN_ACT_SEND, next, previous, true)) return;
    hold_target(tile, next);
    action_callback(tile->tile_id, tile->action_id, next);
}

static void show_page(int index);
static void apply_state_body(cJSON *root);
static void send_level(tile_view_t *tile, int value);
static void close_pin_popup(bool revert);
static void close_dim_popup(void)
{
    if (dim_popup) {
        lv_obj_add_flag(dim_popup, LV_OBJ_FLAG_HIDDEN);
    }
    dim_tile = NULL;
    pin_open_for[0] = '\0';
}

static void dim_slider_event(lv_event_t *event)
{
    if (lv_event_get_code(event) != LV_EVENT_RELEASED || !dim_tile) return;
    note_touch();
    int value = (int) lv_slider_get_value(dim_slider);
    bool passed = pin_open_for[0] && strcmp(pin_open_for, dim_tile->tile_id) == 0;
    if (!passed && pin_take(dim_tile, PIN_ACT_SEND, value, dim_tile->value, false)) return;
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
        if (clock_hit) lv_obj_add_flag(clock_hit, LV_OBJ_FLAG_HIDDEN);
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
        if (clock_hit) {
            lv_obj_clear_flag(clock_hit, LV_OBJ_FLAG_HIDDEN);
            lv_obj_move_foreground(clock_hit);
        }
        if (page_bar && layout_root && !cJSON_IsFalse(cJSON_GetObjectItem(layout_root, "showTabs"))) {
            cJSON *pages = cJSON_GetObjectItem(layout_root, "pages");
            if (cJSON_GetArraySize(pages) > 1) lv_obj_clear_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
        }
    }
}

static void dismiss_idle(lv_event_t *event)
{
    (void) event;
    if (esp_timer_get_time() < idle_guard_until) return;
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

static void home_event(lv_event_t *event)
{
    (void) event;
    note_touch();
    if (locked) return;
    homedisplay_client_reload_layout();
}

static void gesture_event(lv_event_t *event)
{
    (void) event;
    if (force_idle) return;
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

static int read_px(cJSON *tile, const char *key, const char *legacy, int fallback)
{
    cJSON *item = cJSON_GetObjectItem(tile, key);
    if (cJSON_IsNumber(item) && item->valueint > 0) return snap_font_px(item->valueint);
    const char *name = legacy ? cJSON_GetStringValue(cJSON_GetObjectItem(tile, legacy)) : NULL;
    if (name && strcmp(name, "small") == 0) return 20;
    if (name && strcmp(name, "normal") == 0) return 28;
    if (name && strcmp(name, "large") == 0) return 36;
    if (name && strcmp(name, "xlarge") == 0) return 48;
    return snap_font_px(fallback);
}

static void load_type(tile_view_t *view, cJSON *tile, const char *kind)
{
    int title_fallback = (kind && strcmp(kind, "nav") == 0) ? 28 : 20;
    int text_fallback = (kind && (strcmp(kind, "chart") == 0 || strcmp(kind, "label") == 0 || strcmp(kind, "energyflow") == 0)) ? 20 : 28;
    view->title_px = read_px(tile, "titlePx", NULL, title_fallback);
    view->text_px = read_px(tile, "textPx", "textSize", text_fallback);
    view->title_bold = cJSON_IsTrue(cJSON_GetObjectItem(tile, "titleBold"));
    view->title_italic = cJSON_IsTrue(cJSON_GetObjectItem(tile, "titleItalic"));
    view->text_bold = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textBold"));
    view->text_italic = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textItalic"));
    view->text_underline = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textUnderline"));
    view->show_age = cJSON_IsTrue(cJSON_GetObjectItem(tile, "showAge"));
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

static void paint_switch_value(tile_view_t *tile, int value)
{
    if (!tile) return;
    tile->value = value;
    bool on = value_is_on(tile, value);
    if (tile->power) set_power_checked(tile, on);
    if (tile->track) place_level(tile, value);
    if (control_is_dimmer(tile->control)) {
        refresh_icons(tile, on);
        paint_dim_label(tile);
        return;
    }
    if (tile->value_rule_count > 0) {
        apply_action_rules(tile, value);
        return;
    }
    refresh_icons(tile, on);
    if (tile->card && !tile->bg_clear) {
        lv_obj_set_style_bg_color(tile->card, lv_color_hex(on ? tile_on_color(tile) : tile->base_bg), 0);
    }
    if (tile->label && tile->show_state_text) {
        label_set_folded(tile->label, on ? "Ein" : "Aus");
    }
}

static const char *pin_secret(const tile_view_t *tile)
{
    if (!tile) return "";
    if (tile->pin == 1) return layout_pin1;
    if (tile->pin == 2) return layout_pin2;
    return "";
}

static bool pin_is_locked(void)
{
    return esp_timer_get_time() < pin_lock_until;
}

static void pin_show_dots(void)
{
    char dots[9];
    int count = pin_len;
    if (count < 0) count = 0;
    if (count > 8) count = 8;
    for (int i = 0; i < count; i++) dots[i] = '*';
    dots[count] = '\0';
    if (pin_dots_label) lv_label_set_text(pin_dots_label, count ? dots : "-");
}

static void close_pin_popup(bool revert)
{
    tile_view_t *tile = pin_tile;
    int revert_value = pin_revert;
    bool painted = pin_painted;
    pin_tile = NULL;
    pin_kind = 0;
    pin_painted = false;
    pin_len = 0;
    pin_entry[0] = '\0';
    if (pin_popup) lv_obj_add_flag(pin_popup, LV_OBJ_FLAG_HIDDEN);
    if (revert && painted && tile) paint_switch_value(tile, revert_value);
}

static void pin_accept(void)
{
    tile_view_t *tile = pin_tile;
    int kind = pin_kind;
    int value = pin_value;
    pin_fails = 0;
    close_pin_popup(false);
    if (!tile || !tile->has_action) return;
    if (kind == PIN_ACT_DIM) {
        snprintf(pin_open_for, sizeof(pin_open_for), "%s", tile->tile_id);
        open_dim_popup(tile);
        return;
    }
    send_level(tile, value);
    if (dim_tile == tile) close_dim_popup();
}

static void pin_fail(void)
{
    pin_len = 0;
    pin_entry[0] = '\0';
    pin_show_dots();
    if (pin_status_label) lv_label_set_text(pin_status_label, "Falsche PIN");
    pin_fails++;
    if (pin_fails >= 3) {
        tile_view_t *tile = pin_tile;
        pin_fails = 0;
        pin_lock_until = esp_timer_get_time() + 30000000LL;
        close_pin_popup(true);
        if (tile && tile->label) lv_label_set_text(tile->label, "Gesperrt");
    }
}

static void pin_push_digit(char digit)
{
    const char *secret = pin_secret(pin_tile);
    if (!secret[0] || pin_len >= 8) return;
    pin_deadline = esp_timer_get_time() + 20000000LL;
    if (pin_status_label) lv_label_set_text(pin_status_label, "");
    pin_entry[pin_len++] = digit;
    pin_entry[pin_len] = '\0';
    pin_show_dots();
    if (pin_len < (int) strlen(secret)) return;
    if (strcmp(pin_entry, secret) == 0) pin_accept();
    else pin_fail();
}

static void pin_matrix_event(lv_event_t *event)
{
    if (lv_event_get_code(event) != LV_EVENT_VALUE_CHANGED) return;
    note_touch();
    if (!pin_tile || pin_is_locked()) return;
    lv_obj_t *matrix = lv_event_get_target(event);
    uint16_t id = lv_btnmatrix_get_selected_btn(matrix);
    if (id == LV_BTNMATRIX_BTN_NONE) return;
    const char *text = lv_btnmatrix_get_btn_text(matrix, id);
    if (!text || !text[0]) return;
    if (text[0] == 'C' && text[1] == '\0') {
        pin_deadline = esp_timer_get_time() + 20000000LL;
        if (pin_len > 0) pin_len--;
        pin_entry[pin_len] = '\0';
        pin_show_dots();
        if (pin_status_label) lv_label_set_text(pin_status_label, "");
        return;
    }
    if (text[0] >= '0' && text[0] <= '9' && text[1] == '\0') pin_push_digit(text[0]);
}

static void pin_cancel_event(lv_event_t *event)
{
    (void) event;
    note_touch();
    close_pin_popup(true);
}

static void pin_tick(lv_timer_t *timer)
{
    (void) timer;
    if (!pin_popup || lv_obj_has_flag(pin_popup, LV_OBJ_FLAG_HIDDEN) || !pin_tile) return;
    if (esp_timer_get_time() >= pin_deadline) close_pin_popup(true);
}

static bool pin_take(tile_view_t *tile, int kind, int value, int revert, bool painted)
{
    const char *secret = pin_secret(tile);
    if (!tile || !secret[0]) return false;
    if (pin_is_locked()) {
        if (painted) paint_switch_value(tile, revert);
        if (tile->label) lv_label_set_text(tile->label, "Gesperrt");
        return true;
    }
    if (pin_popup && !lv_obj_has_flag(pin_popup, LV_OBJ_FLAG_HIDDEN)) {
        if (pin_tile == tile) return true;
        if (painted) paint_switch_value(tile, revert);
        return true;
    }
    if (!pin_popup) return false;
    pin_tile = tile;
    pin_kind = kind;
    pin_value = value;
    pin_revert = revert;
    pin_painted = painted;
    pin_len = 0;
    pin_entry[0] = '\0';
    pin_deadline = esp_timer_get_time() + 20000000LL;
    pin_show_dots();
    if (pin_status_label) lv_label_set_text(pin_status_label, "");
    if (pin_title_label) label_set_folded(pin_title_label, tile->title[0] ? tile->title : "PIN");
    lv_obj_clear_flag(pin_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_move_foreground(pin_popup);
    return true;
}

/* Nur der Griff zählt. Tippen auf die Leiste ändert nichts. */
static void knob_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    tile_view_t *tile = lv_event_get_user_data(event);
    if (!tile || !tile->track) return;
    if (tile->pin != 0 && pin_is_locked()) {
        if (tile->label) lv_label_set_text(tile->label, "Gesperrt");
        return;
    }
    lv_event_code_t code = lv_event_get_code(event);
    if (code == LV_EVENT_CLICKED || code == LV_EVENT_PRESSING) {
        if (knob_drag_tile != tile) {
            knob_drag_tile = tile;
            knob_drag_from = tile->value;
        }
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
        if (code == LV_EVENT_CLICKED) {
            int from = knob_drag_from;
            knob_drag_tile = NULL;
            if (!pin_take(tile, PIN_ACT_SEND, tile->value, from, true)) send_level(tile, tile->value);
        }
        return;
    }
    if (lv_event_get_code(event) == LV_EVENT_RELEASED) {
        int from = knob_drag_tile == tile ? knob_drag_from : tile->value;
        knob_drag_tile = NULL;
        if (!pin_take(tile, PIN_ACT_SEND, tile->value, from, true)) send_level(tile, tile->value);
    }
}

static void power_event(lv_event_t *event)
{
    note_touch();
    if (locked) return;
    tile_view_t *tile = lv_event_get_user_data(event);
    if (!tile || tile->applying || !tile->power) return;
    int previous = tile->value;
    if (tile->pin != 0 && pin_is_locked()) {
        set_power_checked(tile, value_is_on(tile, previous));
        if (tile->label) lv_label_set_text(tile->label, "Gesperrt");
        return;
    }
    int value = toggle_target(tile);
    tile->applying = true;
    set_power_checked(tile, value_is_on(tile, value));
    place_level(tile, value);
    tile->applying = false;
    if (pin_take(tile, PIN_ACT_SEND, value, previous, true)) return;
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

static bool jpeg_sof_size(const char *file, int *width, int *height)
{
    if (!file || !file[0] || !width || !height) return false;
    char path[96];
    snprintf(path, sizeof(path), "/storage/%s", file);
    FILE *fp = fopen(path, "rb");
    if (!fp) return false;
    unsigned char buf[8];
    if (fread(buf, 1, 2, fp) != 2 || buf[0] != 0xFF || buf[1] != 0xD8) {
        fclose(fp);
        return false;
    }
    bool ok = false;
    for (int guard = 0; guard < 64; guard++) {
        int byte = fgetc(fp);
        while (byte == 0xFF) byte = fgetc(fp);
        if (byte == EOF) break;
        int marker = byte;
        if (marker == 0xD9 || marker == 0xDA) break;
        if (fread(buf, 1, 2, fp) != 2) break;
        int len = (buf[0] << 8) | buf[1];
        if (len < 2) break;
        bool sof = (marker >= 0xC0 && marker <= 0xC3) || (marker >= 0xC5 && marker <= 0xC7)
            || (marker >= 0xC9 && marker <= 0xCB) || (marker >= 0xCD && marker <= 0xCF);
        if (sof) {
            bool progressive = marker == 0xC2 || marker == 0xC6 || marker == 0xCA || marker == 0xCE;
            if (!progressive && len >= 7 && fread(buf, 1, 5, fp) == 5) {
                *height = (buf[1] << 8) | buf[2];
                *width = (buf[3] << 8) | buf[4];
                ok = *width > 0 && *height > 0;
            }
            break;
        }
        if (fseek(fp, len - 2, SEEK_CUR) != 0) break;
    }
    fclose(fp);
    return ok;
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
        ESP_LOGW(TAG_UI, "Bild fehlt im Speicher: %s", file);
        return false;
    }
    if (max_bytes > 0 && (size_t) info.st_size > max_bytes) {
        ESP_LOGW(TAG_UI, "Bild übersprungen (zu groß): %s (%ld Byte)", file, (long) info.st_size);
        return false;
    }
    return true;
}

static bool png_ihdr_size(const char *file, int *width, int *height)
{
    if (!file || !file[0] || !width || !height) return false;
    char path[96];
    snprintf(path, sizeof(path), "/storage/%s", file);
    FILE *fp = fopen(path, "rb");
    if (!fp) return false;
    unsigned char hdr[24];
    size_t got = fread(hdr, 1, sizeof(hdr), fp);
    fclose(fp);
    if (got < sizeof(hdr)) return false;
    if (hdr[0] != 0x89 || hdr[1] != 'P' || hdr[2] != 'N' || hdr[3] != 'G') return false;
    *width = (int) ((hdr[16] << 24) | (hdr[17] << 16) | (hdr[18] << 8) | hdr[19]);
    *height = (int) ((hdr[20] << 24) | (hdr[21] << 16) | (hdr[22] << 8) | hdr[23]);
    return *width > 0 && *height > 0;
}

/* Volle Displayfläche in RGB565, mit der Reserve aus dem Plan. */
static bool raster_fits_display(const char *file, size_t max_bytes, int *width, int *height)
{
    if (!storage_image_ready_limit(file, max_bytes)) return false;
    int w = 0;
    int h = 0;
    bool jpeg = jpeg_sof_size(file, &w, &h);
    bool png = !jpeg && png_ihdr_size(file, &w, &h);
    if (!jpeg && !png) {
        ESP_LOGW(TAG_UI, "Bild verworfen (kein Baseline-JPEG oder PNG): %s", file);
        return false;
    }
    if ((long) w * (long) h * 2L > 1024L * 600L * 2L) {
        ESP_LOGW(TAG_UI, "Bild verworfen (zu groß %dx%d): %s", w, h, file);
        return false;
    }
    if (width) *width = w;
    if (height) *height = h;
    return true;
}

static void contain_size(int src_w, int src_h, int box_w, int box_h, int *out_w, int *out_h)
{
    if (src_w < 1) src_w = 1;
    if (src_h < 1) src_h = 1;
    if (box_w < 1) box_w = 1;
    if (box_h < 1) box_h = 1;
    if ((long) src_w * box_h <= (long) src_h * box_w) {
        *out_h = box_h;
        *out_w = (int) ((long) src_w * box_h / src_h);
    } else {
        *out_w = box_w;
        *out_h = (int) ((long) src_h * box_w / src_w);
    }
    if (*out_w < 1) *out_w = 1;
    if (*out_h < 1) *out_h = 1;
}

static bool image_bytes_fit(tile_view_t *view, size_t bytes)
{
    if (view->image_px && view->image_px_cap >= bytes) return true;
    uint8_t *grown = heap_caps_malloc(bytes, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (!grown) return false;
    if (view->image_px) heap_caps_free(view->image_px);
    view->image_px = grown;
    view->image_px_cap = bytes;
    return true;
}

static void put_color_px(uint8_t *dst, lv_color_t color)
{
    dst[0] = (uint8_t) (color.full & 0xFF);
    dst[1] = (uint8_t) ((color.full >> 8) & 0xFF);
}

static void put_alpha_px(uint8_t *dst, lv_color_t color, uint8_t alpha)
{
    put_color_px(dst, color);
    dst[2] = alpha;
}

static void publish_fit_image(tile_view_t *view, int out_w, int out_h, bool alpha);

/* PNG mit Alpha randlos auf die Kachel. Leerer Alphakanal (Fotos aus der Bildbibliothek) bleibt deckend.
 * Wirklich durchsichtige Pixel lassen die Kachel durch. Der volle Puffer wird danach freigegeben. */
static bool publish_png_lodepng(tile_view_t *view, const uint8_t *data, size_t length, int box_w, int box_h,
                                int *full_w, int *full_h)
{
    unsigned char *rgba = NULL;
    unsigned w = 0;
    unsigned h = 0;
    unsigned error = lodepng_decode32(&rgba, &w, &h, data, length);
    if (error || !rgba || w == 0 || h == 0) {
        if (rgba) lv_mem_free(rgba);
        ESP_LOGW(TAG_UI, "PNG-Fehler %u", error);
        return false;
    }
    ui_wdt_kick();
    bool any_visible = false;
    bool any_clear = false;
    int min_x = (int) w;
    int min_y = (int) h;
    int max_x = -1;
    int max_y = -1;
    for (int y = 0; y < (int) h; y++) {
        const unsigned char *line = rgba + (size_t) y * (size_t) w * 4u;
        for (int x = 0; x < (int) w; x++) {
            uint8_t alpha = line[(size_t) x * 4u + 3u];
            if (alpha > 8) {
                any_visible = true;
                if (x < min_x) min_x = x;
                if (y < min_y) min_y = y;
                if (x > max_x) max_x = x;
                if (y > max_y) max_y = y;
            }
            if (alpha < 247) any_clear = true;
        }
        if ((y & 31) == 0) ui_wdt_kick();
    }
    bool force_opaque = !any_visible;
    /* Durchsichtige Bilder ganz zeigen, so groß wie möglich, ohne etwas abzuschneiden. */
    bool contain = any_visible && any_clear;
    if (box_w < 1) box_w = (int) w;
    if (box_h < 1) box_h = (int) h;
    int crop_x = 0;
    int crop_y = 0;
    int crop_w = (int) w;
    int crop_h = (int) h;
    int out_w = box_w;
    int out_h = box_h;
    if (contain) {
        crop_x = min_x;
        crop_y = min_y;
        crop_w = max_x - min_x + 1;
        crop_h = max_y - min_y + 1;
        contain_size(crop_w, crop_h, box_w, box_h, &out_w, &out_h);
    } else if ((long) w * box_h > (long) h * box_w) {
        crop_h = (int) h;
        crop_w = (int) ((long) h * box_w / box_h);
        crop_x = ((int) w - crop_w) / 2;
    } else {
        crop_w = (int) w;
        crop_h = (int) ((long) w * box_h / box_w);
        crop_y = ((int) h - crop_h) / 2;
    }
    if (crop_w < 1) crop_w = 1;
    if (crop_h < 1) crop_h = 1;
    /* Ohne gesetzte Hintergrundfarbe bleibt das Alpha stehen. Eine gewählte Farbe wird deckend daruntergelegt. */
    bool keep_alpha = !force_opaque && (view->image_backdrop || !view->own_bg || view->bg_clear);
    size_t bpp = keep_alpha ? 3u : 2u;
    if (!image_bytes_fit(view, (size_t) out_w * (size_t) out_h * bpp)) {
        lv_mem_free(rgba);
        ESP_LOGW(TAG_UI, "PNG verworfen (kein Speicher für %dx%d)", out_w, out_h);
        return false;
    }
    lv_color_t flat = lv_color_hex(view->base_bg);
    for (int row = 0; row < out_h; row++) {
        int sy = crop_y + (int) ((long) row * crop_h / out_h);
        if (sy >= (int) h) sy = (int) h - 1;
        const unsigned char *line = rgba + (size_t) sy * (size_t) w * 4u;
        for (int col = 0; col < out_w; col++) {
            int sx = crop_x + (int) ((long) col * crop_w / out_w);
            if (sx >= (int) w) sx = (int) w - 1;
            const unsigned char *p = line + (size_t) sx * 4u;
            uint8_t alpha = force_opaque ? 255 : p[3];
            lv_color_t ink = lv_color_make(p[0], p[1], p[2]);
            uint8_t *dst = view->image_px + ((size_t) row * (size_t) out_w + (size_t) col) * bpp;
            if (keep_alpha) put_alpha_px(dst, ink, alpha);
            else put_color_px(dst, lv_color_mix(ink, flat, alpha));
        }
        if ((row & 31) == 0) ui_wdt_kick();
    }
    lv_mem_free(rgba);
    publish_fit_image(view, out_w, out_h, keep_alpha);
    *full_w = (int) w;
    *full_h = (int) h;
    if (force_opaque) ESP_LOGI(TAG_UI, "PNG ohne nutzbare Transparenz, deckend gezeichnet");
    return true;
}

static uint32_t png_u32(const uint8_t *p)
{
    return ((uint32_t) p[0] << 24) | ((uint32_t) p[1] << 16) | ((uint32_t) p[2] << 8) | (uint32_t) p[3];
}

static uint8_t png_paeth(int a, int b, int c)
{
    int guess = a + b - c;
    int pa = guess > a ? guess - a : a - guess;
    int pb = guess > b ? guess - b : b - guess;
    int pc = guess > c ? guess - c : c - guess;
    if (pa <= pb && pa <= pc) return (uint8_t) a;
    if (pb <= pc) return (uint8_t) b;
    return (uint8_t) c;
}

static bool png_unfilter(uint8_t *row, const uint8_t *prev, int count, int bpp)
{
    uint8_t filter = row[0];
    uint8_t *raw = row + 1;
    if (filter > 4) return false;
    if (filter == 0) return true;
    for (int i = 0; i < count; i++) {
        int left = i >= bpp ? raw[i - bpp] : 0;
        int up = prev[i];
        int upper_left = i >= bpp ? prev[i - bpp] : 0;
        if (filter == 1) raw[i] = (uint8_t) (raw[i] + left);
        else if (filter == 2) raw[i] = (uint8_t) (raw[i] + up);
        else if (filter == 3) raw[i] = (uint8_t) (raw[i] + (left + up) / 2);
        else raw[i] = (uint8_t) (raw[i] + png_paeth(left, up, upper_left));
    }
    return true;
}

typedef void (*png_row_fn)(void *user, int y, const uint8_t *px);

/* Entpackt IDAT zeilenweise. Es bleibt nur die aktuelle Zeile im Speicher, nicht das ganze Bild. */
static bool png_for_each_row(const uint8_t *idat, size_t idat_len, int width, int height, int bpp,
                             png_row_fn fn, void *user)
{
    int count = width * bpp;
    int row_bytes = count + 1;
    if (width < 1 || height < 1 || bpp < 1 || idat_len < 2 || count < 1) return false;
    tinfl_decompressor *infl = heap_caps_malloc(sizeof(*infl), MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    uint8_t *dict = heap_caps_malloc(TINFL_LZ_DICT_SIZE, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    uint8_t *row = heap_caps_malloc((size_t) row_bytes, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    uint8_t *prev = heap_caps_calloc((size_t) count, 1, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (!infl || !dict || !row || !prev) {
        heap_caps_free(infl);
        heap_caps_free(dict);
        heap_caps_free(row);
        heap_caps_free(prev);
        return false;
    }
    memset(infl, 0, sizeof(*infl));
    tinfl_init(infl);
    size_t in_ofs = 0;
    size_t dict_ofs = 0;
    size_t fill = 0;
    int y = 0;
    bool ok = true;
    while (ok && y < height) {
        if (in_ofs >= idat_len) {
            ok = false;
            break;
        }
        size_t in_size = idat_len - in_ofs;
        size_t out_size = TINFL_LZ_DICT_SIZE - dict_ofs;
        tinfl_status status = tinfl_decompress(infl, idat + in_ofs, &in_size, dict, dict + dict_ofs, &out_size,
                                                TINFL_FLAG_PARSE_ZLIB_HEADER);
        if (in_size == 0 && out_size == 0) {
            ok = false;
            break;
        }
        in_ofs += in_size;
        size_t produced = 0;
        while (produced < out_size && y < height) {
            size_t need = (size_t) row_bytes - fill;
            size_t take = out_size - produced < need ? out_size - produced : need;
            memcpy(row + fill, dict + dict_ofs + produced, take);
            fill += take;
            produced += take;
            if (fill == (size_t) row_bytes) {
                if (!png_unfilter(row, prev, count, bpp)) {
                    ok = false;
                    break;
                }
                fn(user, y, row + 1);
                memcpy(prev, row + 1, (size_t) count);
                fill = 0;
                y++;
                if ((y & 15) == 0) ui_wdt_kick();
            }
        }
        dict_ofs = (dict_ofs + out_size) & (TINFL_LZ_DICT_SIZE - 1);
        if (!ok) break;
        if (status == TINFL_STATUS_DONE) break;
        if (status < TINFL_STATUS_DONE) {
            ok = false;
            break;
        }
    }
    bool complete = ok && y == height;
    heap_caps_free(infl);
    heap_caps_free(dict);
    heap_caps_free(row);
    heap_caps_free(prev);
    return complete;
}

typedef struct {
    int w;
    int h;
    int bpp;
    uint8_t *idat;
    size_t idat_len;
    bool owned;
} png_plain_t;

static bool png_take_idat(const uint8_t *data, size_t length, png_plain_t *out)
{
    memset(out, 0, sizeof(*out));
    static const uint8_t sig[8] = {137, 80, 78, 71, 13, 10, 26, 10};
    if (length < 8 || memcmp(data, sig, 8) != 0) return false;
    size_t off = 8;
    int parts = 0;
    size_t bytes = 0;
    bool ihdr = false;
    while (off + 12 <= length) {
        uint32_t chunk = png_u32(data + off);
        if (chunk > length - off - 12) return false;
        const uint8_t *type = data + off + 4;
        const uint8_t *body = data + off + 8;
        if (memcmp(type, "IHDR", 4) == 0) {
            if (chunk < 13) return false;
            out->w = (int) png_u32(body);
            out->h = (int) png_u32(body + 4);
            if (body[8] != 8 || body[12] != 0) return false;
            if (body[9] == 0) out->bpp = 1;
            else if (body[9] == 2) out->bpp = 3;
            else if (body[9] == 4) out->bpp = 2;
            else if (body[9] == 6) out->bpp = 4;
            else return false;
            if (out->w < 1 || out->h < 1 || out->w > 8192 || out->h > 8192) return false;
            ihdr = true;
        } else if (memcmp(type, "IDAT", 4) == 0) {
            parts++;
            bytes += chunk;
        } else if (memcmp(type, "IEND", 4) == 0) {
            break;
        }
        off += 12u + chunk;
    }
    if (!ihdr || bytes == 0) return false;
    if (parts == 1) {
        off = 8;
        while (off + 12 <= length) {
            uint32_t chunk = png_u32(data + off);
            if (chunk > length - off - 12) return false;
            if (memcmp(data + off + 4, "IDAT", 4) == 0) {
                out->idat = (uint8_t *) (data + off + 8);
                out->idat_len = chunk;
                return true;
            }
            off += 12u + chunk;
        }
        return false;
    }
    uint8_t *joined = heap_caps_malloc(bytes, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (!joined) return false;
    size_t filled = 0;
    off = 8;
    while (off + 12 <= length && filled < bytes) {
        uint32_t chunk = png_u32(data + off);
        if (chunk > length - off - 12) break;
        if (memcmp(data + off + 4, "IDAT", 4) == 0) {
            memcpy(joined + filled, data + off + 8, chunk);
            filled += chunk;
        }
        off += 12u + chunk;
    }
    out->idat = joined;
    out->idat_len = filled;
    out->owned = true;
    return filled == bytes;
}

typedef struct {
    int w;
    int bpp;
    bool any_visible;
    bool any_clear;
    int min_x;
    int min_y;
    int max_x;
    int max_y;
} png_alpha_scan_t;

static void png_scan_alpha(void *user, int y, const uint8_t *px)
{
    png_alpha_scan_t *scan = user;
    for (int x = 0; x < scan->w; x++) {
        uint8_t alpha = 255;
        if (scan->bpp == 4) alpha = px[(size_t) x * 4u + 3u];
        else if (scan->bpp == 2) alpha = px[(size_t) x * 2u + 1u];
        if (alpha > 8) {
            scan->any_visible = true;
            if (x < scan->min_x) scan->min_x = x;
            if (y < scan->min_y) scan->min_y = y;
            if (x > scan->max_x) scan->max_x = x;
            if (y > scan->max_y) scan->max_y = y;
        }
        if (alpha < 247) scan->any_clear = true;
    }
}

typedef struct {
    tile_view_t *view;
    int src_w;
    int src_bpp;
    int crop_x;
    int crop_y;
    int crop_w;
    int crop_h;
    int out_w;
    int out_h;
    bool keep_alpha;
    bool force_opaque;
    lv_color_t flat;
    size_t dst_bpp;
    int next_row;
} png_paint_t;

static void png_paint_row(void *user, int y, const uint8_t *px)
{
    png_paint_t *paint = user;
    while (paint->next_row < paint->out_h) {
        int sy = paint->crop_y + (int) ((long) paint->next_row * paint->crop_h / paint->out_h);
        if (sy > y) return;
        if (sy < y) {
            paint->next_row++;
            continue;
        }
        int row = paint->next_row++;
        for (int col = 0; col < paint->out_w; col++) {
            int sx = paint->crop_x + (int) ((long) col * paint->crop_w / paint->out_w);
            if (sx < 0) sx = 0;
            if (sx >= paint->src_w) sx = paint->src_w - 1;
            const uint8_t *p = px + (size_t) sx * (size_t) paint->src_bpp;
            uint8_t red;
            uint8_t green;
            uint8_t blue;
            uint8_t alpha;
            if (paint->src_bpp == 4) {
                red = p[0];
                green = p[1];
                blue = p[2];
                alpha = p[3];
            } else if (paint->src_bpp == 3) {
                red = p[0];
                green = p[1];
                blue = p[2];
                alpha = 255;
            } else if (paint->src_bpp == 2) {
                red = green = blue = p[0];
                alpha = p[1];
            } else {
                red = green = blue = p[0];
                alpha = 255;
            }
            if (paint->force_opaque) alpha = 255;
            lv_color_t ink = lv_color_make(red, green, blue);
            uint8_t *dst = paint->view->image_px + ((size_t) row * (size_t) paint->out_w + (size_t) col) * paint->dst_bpp;
            if (paint->keep_alpha) put_alpha_px(dst, ink, alpha);
            else put_color_px(dst, lv_color_mix(ink, paint->flat, alpha));
        }
    }
}

static void png_plain_free(png_plain_t *plain)
{
    if (plain->owned) heap_caps_free(plain->idat);
    plain->idat = NULL;
    plain->owned = false;
}

/* Große PNG werden zeilenweise entpackt. Der volle RGBA-Puffer passt sonst nicht in den Speicher. */
static bool publish_png_cover(tile_view_t *view, const uint8_t *data, size_t length, int box_w, int box_h,
                              int *full_w, int *full_h)
{
    png_plain_t plain;
    if (!png_take_idat(data, length, &plain)) return publish_png_lodepng(view, data, length, box_w, box_h, full_w, full_h);
    png_alpha_scan_t scan = {
        .w = plain.w,
        .bpp = plain.bpp,
        .min_x = plain.w,
        .min_y = plain.h,
        .max_x = -1,
        .max_y = -1,
    };
    if (!png_for_each_row(plain.idat, plain.idat_len, plain.w, plain.h, plain.bpp, png_scan_alpha, &scan)) {
        png_plain_free(&plain);
        ESP_LOGW(TAG_UI, "PNG zeilenweise nicht lesbar");
        return publish_png_lodepng(view, data, length, box_w, box_h, full_w, full_h);
    }
    bool force_opaque = !scan.any_visible;
    bool contain = scan.any_visible && scan.any_clear;
    if (box_w < 1) box_w = plain.w;
    if (box_h < 1) box_h = plain.h;
    int crop_x = 0;
    int crop_y = 0;
    int crop_w = plain.w;
    int crop_h = plain.h;
    int out_w = box_w;
    int out_h = box_h;
    if (contain) {
        crop_x = scan.min_x;
        crop_y = scan.min_y;
        crop_w = scan.max_x - scan.min_x + 1;
        crop_h = scan.max_y - scan.min_y + 1;
        contain_size(crop_w, crop_h, box_w, box_h, &out_w, &out_h);
    } else if ((long) plain.w * box_h > (long) plain.h * box_w) {
        crop_h = plain.h;
        crop_w = (int) ((long) plain.h * box_w / box_h);
        crop_x = (plain.w - crop_w) / 2;
    } else {
        crop_w = plain.w;
        crop_h = (int) ((long) plain.w * box_h / box_w);
        crop_y = (plain.h - crop_h) / 2;
    }
    if (crop_w < 1) crop_w = 1;
    if (crop_h < 1) crop_h = 1;
    bool keep_alpha = !force_opaque && (view->image_backdrop || !view->own_bg || view->bg_clear);
    size_t dst_bpp = keep_alpha ? 3u : 2u;
    if (!image_bytes_fit(view, (size_t) out_w * (size_t) out_h * dst_bpp)) {
        png_plain_free(&plain);
        ESP_LOGW(TAG_UI, "PNG verworfen (kein Speicher für %dx%d)", out_w, out_h);
        return false;
    }
    png_paint_t paint = {
        .view = view,
        .src_w = plain.w,
        .src_bpp = plain.bpp,
        .crop_x = crop_x,
        .crop_y = crop_y,
        .crop_w = crop_w,
        .crop_h = crop_h,
        .out_w = out_w,
        .out_h = out_h,
        .keep_alpha = keep_alpha,
        .force_opaque = force_opaque,
        .flat = lv_color_hex(view->base_bg),
        .dst_bpp = dst_bpp,
        .next_row = 0,
    };
    bool painted = png_for_each_row(plain.idat, plain.idat_len, plain.w, plain.h, plain.bpp, png_paint_row, &paint);
    png_plain_free(&plain);
    if (!painted || paint.next_row != out_h) {
        ESP_LOGW(TAG_UI, "PNG nicht vollständig gezeichnet (%dx%d)", plain.w, plain.h);
        return false;
    }
    publish_fit_image(view, out_w, out_h, keep_alpha);
    *full_w = plain.w;
    *full_h = plain.h;
    if (force_opaque) ESP_LOGI(TAG_UI, "PNG ohne nutzbare Transparenz, deckend gezeichnet");
    return true;
}

static void publish_fit_image(tile_view_t *view, int out_w, int out_h, bool alpha)
{
    lv_img_dsc_t *dsc = &view->image_dsc;
    memset(dsc, 0, sizeof(*dsc));
    dsc->header.always_zero = 0;
    dsc->header.cf = alpha ? LV_IMG_CF_TRUE_COLOR_ALPHA : LV_IMG_CF_TRUE_COLOR;
    dsc->header.w = (uint32_t) out_w;
    dsc->header.h = (uint32_t) out_h;
    dsc->data_size = (uint32_t) ((size_t) out_w * (size_t) out_h * (alpha ? 3u : 2u));
    dsc->data = view->image_px;
    lv_img_cache_invalidate_src(dsc);
    lv_img_set_src(view->image_img, dsc);
    lv_img_set_zoom(view->image_img, LV_IMG_ZOOM_NONE);
    lv_obj_set_size(view->image_img, out_w, out_h);
    lv_obj_center(view->image_img);
}

/* Bildkacheln dekodieren JPEG und PNG einmal und füllen die Kachel randlos. Überschuss wird beschnitten.
 * LVGL würde sonst ein großes Bild bei jedem Neuzeichnen dekodieren und zoomen; das bremst Anzeige und Touch. */
static bool show_image_fit(tile_view_t *view, const char *file, int box_w, int box_h)
{
    char path[96];
    snprintf(path, sizeof(path), "/storage/%s", file);
    struct stat info;
    if (stat(path, &info) != 0 || info.st_size < 8 || (size_t) info.st_size > 2u * 1024u * 1024u) return false;
    FILE *fp = fopen(path, "rb");
    if (!fp) return false;
    unsigned char magic[4] = {0};
    if (fread(magic, 1, 4, fp) != 4) {
        fclose(fp);
        return false;
    }
    bool is_jpeg = magic[0] == 0xFF && magic[1] == 0xD8;
    bool is_png = magic[0] == 0x89 && magic[1] == 'P' && magic[2] == 'N' && magic[3] == 'G';
    if (!is_jpeg && !is_png) {
        fclose(fp);
        return false;
    }
    uint8_t *data = heap_caps_malloc((size_t) info.st_size, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (!data) {
        fclose(fp);
        ESP_LOGW(TAG_UI, "Bild %s: kein Speicher zum Lesen (%ld Byte)", file, (long) info.st_size);
        return true;
    }
    rewind(fp);
    size_t got = fread(data, 1, (size_t) info.st_size, fp);
    fclose(fp);
    int full_w = 0;
    int full_h = 0;
    int width = 0;
    int height = 0;
    uint16_t *decoded = NULL;
    if (is_png) {
        ui_wdt_kick();
        bool ok = publish_png_cover(view, data, got, box_w, box_h, &full_w, &full_h);
        heap_caps_free(data);
        ui_wdt_kick();
        if (!ok) {
            ESP_LOGW(TAG_UI, "Bild %s verworfen (PNG nicht lesbar oder kein Speicher)", file);
            return true;
        }
        ESP_LOGI(TAG_UI, "Bild %s auf Kachel %dx%d skaliert (Quelle %dx%d)", file, box_w, box_h, full_w, full_h);
        return true;
    } else {
        int scale = 0;
        int result = homedisplay_jpeg_probe(data, got, SIZE_MAX, &full_w, &full_h, &scale);
        if (result != 0) {
            heap_caps_free(data);
            ESP_LOGW(TAG_UI, "Bild %s verworfen (JPEG-Fehler %d, nur Baseline-JPEG)", file, result);
            return true;
        }
        int want_w = box_w < 1 ? full_w : box_w;
        int want_h = box_h < 1 ? full_h : box_h;
        scale = 0;
        while (scale < 3 && (((full_w - 1) >> (scale + 1)) + 1) >= want_w && (((full_h - 1) >> (scale + 1)) + 1) >= want_h) scale++;
        while (scale < 3 && (size_t) (((full_w - 1) >> scale) + 1) * (size_t) (((full_h - 1) >> scale) + 1) > CAMERA_MAX_PX * 2) scale++;
        width = ((full_w - 1) >> scale) + 1;
        height = ((full_h - 1) >> scale) + 1;
        decoded = heap_caps_malloc((size_t) width * (size_t) height * sizeof(uint16_t), MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
        if (!decoded) {
            heap_caps_free(data);
            ESP_LOGW(TAG_UI, "Bild %s verworfen (kein Speicher für %dx%d)", file, width, height);
            return true;
        }
        ui_wdt_kick();
        result = homedisplay_jpeg_decode(data, got, scale, decoded, width, height);
        heap_caps_free(data);
        ui_wdt_kick();
        if (result != 0) {
            heap_caps_free(decoded);
            ESP_LOGW(TAG_UI, "Bild %s verworfen (Dekodierfehler %d)", file, result);
            return true;
        }
    }
    if (box_w < 1) box_w = width;
    if (box_h < 1) box_h = height;
    int crop_w;
    int crop_h;
    if ((long) width * box_h > (long) height * box_w) {
        crop_h = height;
        crop_w = (int) ((long) height * box_w / box_h);
    } else {
        crop_w = width;
        crop_h = (int) ((long) width * box_h / box_w);
    }
    if (crop_w < 1) crop_w = 1;
    if (crop_h < 1) crop_h = 1;
    if (crop_w > width) crop_w = width;
    if (crop_h > height) crop_h = height;
    int crop_x = (width - crop_w) / 2;
    int crop_y = (height - crop_h) / 2;
    int out_w = box_w;
    int out_h = box_h;
    if (!image_bytes_fit(view, (size_t) out_w * (size_t) out_h * 2u)) {
        heap_caps_free(decoded);
        ESP_LOGW(TAG_UI, "Bild %s verworfen (kein Speicher für %dx%d)", file, out_w, out_h);
        return true;
    }
    uint16_t *dst_px = (uint16_t *) view->image_px;
    for (int row = 0; row < out_h; row++) {
        int sy = crop_y + (int) ((long) row * crop_h / out_h);
        if (sy >= height) sy = height - 1;
        const uint16_t *src = decoded + (size_t) sy * (size_t) width;
        uint16_t *dst = dst_px + (size_t) row * (size_t) out_w;
        for (int col = 0; col < out_w; col++) {
            int sx = crop_x + (int) ((long) col * crop_w / out_w);
            if (sx >= width) sx = width - 1;
            dst[col] = src[sx];
        }
    }
    heap_caps_free(decoded);
    publish_fit_image(view, out_w, out_h, false);
    ESP_LOGI(TAG_UI, "Bild %s auf Kachel %dx%d skaliert (Quelle %dx%d)", file, out_w, out_h, full_w, full_h);
    return true;
}

static uint8_t glyph_alpha_at(const lv_font_glyph_dsc_t *glyph, const uint8_t *bitmap, int x, int y)
{
    if (!bitmap || x < 0 || y < 0 || x >= glyph->box_w || y >= glyph->box_h) return 0;
    int stride = (glyph->box_w * glyph->bpp + 7) / 8;
    const uint8_t byte = bitmap[(size_t) y * (size_t) stride + (size_t) ((x * glyph->bpp) / 8)];
    if (glyph->bpp == 4) {
        uint8_t nibble = (x & 1) ? (byte & 0x0F) : (byte >> 4);
        return (uint8_t) (nibble * 17);
    }
    if (glyph->bpp == 8) return byte;
    if (glyph->bpp == 1) return (byte & (uint8_t) (0x80 >> (x & 7))) ? 255 : 0;
    return 0;
}

/* Glyphe in einen Puffer zeichnen und auf die Kachel skalieren. Zoom an der Schrift schneidet sie ab. */
static bool show_icon_fit(tile_view_t *view, const char *icon, int box_w, int box_h, uint32_t ink)
{
    lv_font_glyph_dsc_t glyph;
    uint32_t letter = icon_code(icon, false);
    if (!lv_font_get_glyph_dsc(&mdi_48, &glyph, letter, 0) || glyph.box_w < 1 || glyph.box_h < 1) return false;
    const uint8_t *bitmap = lv_font_get_glyph_bitmap(&mdi_48, letter);
    if (!bitmap) return false;
    int out_w = 1;
    int out_h = 1;
    contain_size(glyph.box_w, glyph.box_h, box_w, box_h, &out_w, &out_h);
    bool keep_alpha = view->image_backdrop || !view->own_bg || view->bg_clear;
    size_t bpp = keep_alpha ? 3u : 2u;
    if (!image_bytes_fit(view, (size_t) out_w * (size_t) out_h * bpp)) return false;
    lv_color_t color = lv_color_hex(ink);
    lv_color_t flat = lv_color_hex(view->base_bg);
    for (int y = 0; y < out_h; y++) {
        int sy = (int) ((long) y * glyph.box_h / out_h);
        if (sy >= glyph.box_h) sy = glyph.box_h - 1;
        for (int x = 0; x < out_w; x++) {
            int sx = (int) ((long) x * glyph.box_w / out_w);
            if (sx >= glyph.box_w) sx = glyph.box_w - 1;
            uint8_t alpha = glyph_alpha_at(&glyph, bitmap, sx, sy);
            uint8_t *dst = view->image_px + ((size_t) y * (size_t) out_w + (size_t) x) * bpp;
            if (keep_alpha) put_alpha_px(dst, color, alpha);
            else put_color_px(dst, lv_color_mix(color, flat, alpha));
        }
    }
    publish_fit_image(view, out_w, out_h, keep_alpha);
    return true;
}

static void place_cover_image(tile_view_t *view, const char *file, int box_w, int box_h)
{
    if (!view || !view->image_img || !file || !file[0]) return;
    if (show_image_fit(view, file, box_w, box_h)) return;
    int width = 0;
    int height = 0;
    if (!raster_fits_display(file, 2u * 1024u * 1024u, &width, &height)) return;
    lv_obj_t *img = view->image_img;
    char path[96];
    snprintf(path, sizeof(path), "S:/%s", file);
    lv_img_cache_invalidate_src(path);
    lv_img_set_src(img, path);
    if (width > 0 && height > 0 && box_w > 0 && box_h > 0) {
        int zoom_w = (box_w * 256) / width;
        int zoom_h = (box_h * 256) / height;
        int zoom = zoom_w > zoom_h ? zoom_w : zoom_h;
        if (zoom < 1) zoom = 1;
        if (zoom > 4096) zoom = 4096;
        lv_img_set_pivot(img, width / 2, height / 2);
        lv_img_set_zoom(img, zoom);
    }
    if (box_w > 0 && box_h > 0) lv_obj_set_size(img, box_w, box_h);
    lv_obj_center(img);
}
static camera_slot_t *camera_slot_find(const char *tile_id)
{
    if (!tile_id || !tile_id[0]) return NULL;
    for (int index = 0; index < CAMERA_SLOTS; index++) {
        if (camera_slots[index].tile_id[0] && strcmp(camera_slots[index].tile_id, tile_id) == 0) return &camera_slots[index];
    }
    return NULL;
}

static bool camera_tile_present(const char *tile_id)
{
    for (int index = 0; index < tile_count; index++) {
        if (tiles[index].camera_img && strcmp(tiles[index].tile_id, tile_id) == 0) return true;
    }
    return false;
}

static void camera_slot_release(camera_slot_t *slot)
{
    for (int side = 0; side < 2; side++) {
        if (slot->buf[side]) heap_caps_free(slot->buf[side]);
    }
    memset(slot, 0, sizeof(*slot));
    slot->shown = -1;
}

/* Nur der Client-Task legt Plätze an oder gibt sie frei. Ein Platz einer Kachel, die nicht auf der Seite liegt, darf weichen. */
static camera_slot_t *camera_slot_get(const char *tile_id)
{
    camera_slot_t *found = camera_slot_find(tile_id);
    if (found) return found;
    camera_slot_t *spare = NULL;
    camera_slot_t *evict = NULL;
    for (int index = 0; index < CAMERA_SLOTS; index++) {
        camera_slot_t *slot = &camera_slots[index];
        if (!slot->tile_id[0]) {
            if (!spare) spare = slot;
        } else if (!evict && !camera_tile_present(slot->tile_id)) {
            evict = slot;
        }
    }
    camera_slot_t *slot = spare ? spare : evict;
    if (!slot) return NULL;
    camera_slot_release(slot);
    snprintf(slot->tile_id, sizeof(slot->tile_id), "%s", tile_id);
    return slot;
}

static void camera_show_frame(tile_view_t *view, lv_img_dsc_t *dsc)
{
    lv_img_cache_invalidate_src(dsc);
    lv_img_set_src(view->camera_img, dsc);
    lv_obj_set_size(view->camera_img, dsc->header.w, dsc->header.h);
    lv_obj_align(view->camera_img, LV_ALIGN_BOTTOM_MID, 0, view->camera_dy);
}

/* Beim Aufbau der Seite sofort das letzte Bild zeigen, bevor das nächste geladen ist. */
static void camera_attach(tile_view_t *view)
{
    if (!view || !view->camera_img) return;
    camera_slot_t *slot = camera_slot_find(view->tile_id);
    if (!slot || slot->shown < 0 || !slot->buf[slot->shown]) return;
    camera_show_frame(view, &slot->dsc[slot->shown]);
}

static bool tile_rects_overlap(cJSON *a, cJSON *b)
{
    int ac = json_int(a, "col", 0);
    int ar = json_int(a, "row", 0);
    int aw = json_int(a, "colSpan", 1);
    int ah = json_int(a, "rowSpan", 1);
    int bc = json_int(b, "col", 0);
    int br = json_int(b, "row", 0);
    int bw = json_int(b, "colSpan", 1);
    int bh = json_int(b, "rowSpan", 1);
    if (aw < 1) aw = 1;
    if (ah < 1) ah = 1;
    if (bw < 1) bw = 1;
    if (bh < 1) bh = 1;
    return ac < bc + bw && bc < ac + aw && ar < br + bh && br < ar + ah;
}

static int col_x(int col);
static int row_y(int row);
static int span_w(int col, int span);
static int span_h(int row, int span);

static bool tile_snaps(cJSON *tile)
{
    return !cJSON_IsFalse(cJSON_GetObjectItem(tile, "snapToGrid"));
}

static void card_box(cJSON *tile, int col, int row, int col_span, int row_span,
                     int inset_l, int inset_r, int inset_t, int inset_b,
                     int *x, int *y, int *w, int *h)
{
    int width = span_w(col, col_span) - 8 - inset_l - inset_r;
    int height = span_h(row, row_span) - 8 - inset_t - inset_b;
    if (width < 16) width = 16;
    if (height < 16) height = 16;
    int left;
    int top;
    if (!tile_snaps(tile)) {
        left = json_int(tile, "x", 0);
        top = json_int(tile, "y", 0);
        int limit_x = HOMEDISPLAY_LCD_H_RES - width;
        int limit_y = (HOMEDISPLAY_LCD_V_RES - HEADER_H) - height;
        if (limit_x < 0) limit_x = 0;
        if (limit_y < 0) limit_y = 0;
        if (left < 0) left = 0;
        if (top < 0) top = 0;
        if (left > limit_x) left = limit_x;
        if (top > limit_y) top = limit_y;
    } else {
        left = col_x(col) + 4 + inset_l;
        top = row_y(row) + 4 + inset_t;
    }
    *x = left;
    *y = top;
    *w = width;
    *h = height;
}

static bool group_edges_touch(cJSON *a, cJSON *b)
{
    if (!tile_snaps(a) || !tile_snaps(b)) return false;
    const char *ga = cJSON_GetStringValue(cJSON_GetObjectItem(a, "group"));
    const char *gb = cJSON_GetStringValue(cJSON_GetObjectItem(b, "group"));
    if (!ga || !gb || !ga[0] || strcmp(ga, gb) != 0) return false;
    int ac = json_int(a, "col", 0);
    int ar = json_int(a, "row", 0);
    int aw = json_int(a, "colSpan", 1);
    int ah = json_int(a, "rowSpan", 1);
    int bc = json_int(b, "col", 0);
    int br = json_int(b, "row", 0);
    int bw = json_int(b, "colSpan", 1);
    int bh = json_int(b, "rowSpan", 1);
    if (aw < 1) aw = 1;
    if (ah < 1) ah = 1;
    if (bw < 1) bw = 1;
    if (bh < 1) bh = 1;
    bool side = (ac + aw == bc || bc + bw == ac) && ar < br + bh && br < ar + ah;
    bool end = (ar + ah == br || br + bh == ar) && ac < bc + bw && bc < ac + aw;
    return side || end;
}

static int group_root(int *parent, int index)
{
    while (parent[index] != index) index = parent[index];
    return index;
}

static void group_insets(cJSON *page, cJSON *tile, int *left, int *right, int *top, int *bottom)
{
    *left = *right = *top = *bottom = 0;
    if (!tile_snaps(tile)) return;
    const char *group = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "group"));
    if (!group || !group[0] || !page) return;
    cJSON *items[24];
    int count = 0;
    cJSON *item = NULL;
    cJSON_ArrayForEach(item, cJSON_GetObjectItem(page, "tiles")) {
        const char *name = cJSON_GetStringValue(cJSON_GetObjectItem(item, "group"));
        if (name && strcmp(name, group) == 0 && tile_snaps(item) && count < 24) items[count++] = item;
    }
    int parent[24];
    for (int index = 0; index < count; index++) parent[index] = index;
    for (int i = 0; i < count; i++) {
        for (int j = i + 1; j < count; j++) {
            if (!group_edges_touch(items[i], items[j])) continue;
            parent[group_root(parent, j)] = group_root(parent, i);
        }
    }
    int self = -1;
    for (int index = 0; index < count; index++) if (items[index] == tile) self = index;
    if (self < 0) return;
    int root = group_root(parent, self);
    int ac = json_int(tile, "col", 0);
    int ar = json_int(tile, "row", 0);
    int aw = json_int(tile, "colSpan", 1);
    int ah = json_int(tile, "rowSpan", 1);
    if (aw < 1) aw = 1;
    if (ah < 1) ah = 1;
    bool open_l = true, open_r = true, open_t = true, open_b = true;
    for (int index = 0; index < count; index++) {
        if (group_root(parent, index) != root || items[index] == tile) continue;
        int bc = json_int(items[index], "col", 0);
        int br = json_int(items[index], "row", 0);
        int bw = json_int(items[index], "colSpan", 1);
        int bh = json_int(items[index], "rowSpan", 1);
        if (bw < 1) bw = 1;
        if (bh < 1) bh = 1;
        if (bc + bw == ac && br < ar + ah && ar < br + bh) open_l = false;
        if (ac + aw == bc && br < ar + ah && ar < br + bh) open_r = false;
        if (br + bh == ar && bc < ac + aw && ac < bc + bw) open_t = false;
        if (ar + ah == br && bc < ac + aw && ac < bc + bw) open_b = false;
    }
    if (open_l) *left = 8;
    if (open_r) *right = 8;
    if (open_t) *top = 8;
    if (open_b) *bottom = 8;
}

static void add_group_frame(int x, int y, int w, int h, lv_border_side_t sides, bool round_frame)
{
    if (!grid || w < 8 || h < 8) return;
    lv_obj_t *frame = lv_obj_create(grid);
    lv_obj_set_pos(frame, x, y);
    lv_obj_set_size(frame, w, h);
    style_flat(frame);
    lv_obj_set_style_bg_opa(frame, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(frame, 2, 0);
    lv_obj_set_style_border_side(frame, sides, 0);
    lv_obj_set_style_border_color(frame, lv_color_hex(color_mono ? 0xFFFFFF : theme_accent), 0);
    lv_obj_set_style_radius(frame, round_frame ? 8 : 0, 0);
    lv_obj_clear_flag(frame, LV_OBJ_FLAG_CLICKABLE);
}

/* Spalten- und Zeilenkanten der aktuellen Seite in Pixeln. Kante i ist die Summe der Anteile davor. */
#define GRID_COLS_MAX 8
#define GRID_ROWS_MAX 6
#define GRID_EDGE_MAX (GRID_COLS_MAX > GRID_ROWS_MAX ? GRID_COLS_MAX : GRID_ROWS_MAX)
static int col_edge[GRID_COLS_MAX + 1];
static int row_edge[GRID_ROWS_MAX + 1];
static int col_count = 1;
static int row_count = 1;

static int edge_at(const int *edges, int count, int index)
{
    if (index < 0) index = 0;
    if (index > count) index = count;
    return edges[index];
}

static int col_x(int col) { return edge_at(col_edge, col_count, col); }
static int row_y(int row) { return edge_at(row_edge, row_count, row); }
static int span_w(int col, int span) { return edge_at(col_edge, col_count, col + span) - edge_at(col_edge, col_count, col); }
static int span_h(int row, int span) { return edge_at(row_edge, row_count, row + span) - edge_at(row_edge, row_count, row); }

/* Anteile 1 bis 3 aus einer Liste lesen; fehlt sie oder passt die Länge nicht, sind alle gleich. */
static void build_edges(int *edges, int count, int length, cJSON *weights)
{
    int share[GRID_EDGE_MAX];
    int total = 0;
    bool valid = cJSON_IsArray(weights) && cJSON_GetArraySize(weights) == count;
    for (int i = 0; i < count; i++) {
        int weight = 1;
        if (valid) {
            cJSON *item = cJSON_GetArrayItem(weights, i);
            if (cJSON_IsNumber(item) && item->valueint >= 1 && item->valueint <= 3) weight = item->valueint;
        }
        share[i] = weight;
        total += weight;
    }
    int sum = 0;
    edges[0] = 0;
    for (int i = 0; i < count; i++) {
        sum += share[i];
        edges[i + 1] = (int) ((int64_t) length * sum / total);
    }
}

static void draw_group_frames(cJSON *page)
{
    cJSON *items[24];
    int count = 0;
    cJSON *item = NULL;
    cJSON_ArrayForEach(item, page ? cJSON_GetObjectItem(page, "tiles") : NULL) {
        const char *name = cJSON_GetStringValue(cJSON_GetObjectItem(item, "group"));
        if (name && name[0] && tile_snaps(item) && count < 24) items[count++] = item;
    }
    int parent[24];
    for (int index = 0; index < count; index++) parent[index] = index;
    for (int i = 0; i < count; i++) {
        for (int j = i + 1; j < count; j++) {
            if (!group_edges_touch(items[i], items[j])) continue;
            parent[group_root(parent, j)] = group_root(parent, i);
        }
    }
    bool drawn[24] = {0};
    for (int index = 0; index < count; index++) {
        int root = group_root(parent, index);
        if (drawn[root]) continue;
        drawn[root] = true;
        int cells[24][2];
        int cell_count = 0;
        int min_c = 99, min_r = 99, max_c = 0, max_r = 0;
        for (int member = 0; member < count && cell_count < 24; member++) {
            if (group_root(parent, member) != root) continue;
            int col = json_int(items[member], "col", 0);
            int row = json_int(items[member], "row", 0);
            int span_c = json_int(items[member], "colSpan", 1);
            int span_r = json_int(items[member], "rowSpan", 1);
            if (span_c < 1) span_c = 1;
            if (span_r < 1) span_r = 1;
            for (int y = row; y < row + span_r && cell_count < 24; y++) {
                for (int x = col; x < col + span_c && cell_count < 24; x++) {
                    cells[cell_count][0] = x;
                    cells[cell_count][1] = y;
                    if (x < min_c) min_c = x;
                    if (y < min_r) min_r = y;
                    if (x > max_c) max_c = x;
                    if (y > max_r) max_r = y;
                    cell_count++;
                }
            }
        }
        if (cell_count <= 0) continue;
        bool rectangular = cell_count == (max_c - min_c + 1) * (max_r - min_r + 1);
        if (rectangular) {
            add_group_frame(col_x(min_c) + 4, row_y(min_r) + 4,
                span_w(min_c, max_c - min_c + 1) - 8, span_h(min_r, max_r - min_r + 1) - 8,
                LV_BORDER_SIDE_FULL, true);
            continue;
        }
        for (int cell = 0; cell < cell_count; cell++) {
            int x = cells[cell][0];
            int y = cells[cell][1];
            lv_border_side_t sides = LV_BORDER_SIDE_NONE;
            bool left = true, right = true, above = true, below = true;
            for (int other = 0; other < cell_count; other++) {
                if (cells[other][0] == x - 1 && cells[other][1] == y) left = false;
                if (cells[other][0] == x + 1 && cells[other][1] == y) right = false;
                if (cells[other][0] == x && cells[other][1] == y - 1) above = false;
                if (cells[other][0] == x && cells[other][1] == y + 1) below = false;
            }
            if (left) sides |= LV_BORDER_SIDE_LEFT;
            if (right) sides |= LV_BORDER_SIDE_RIGHT;
            if (above) sides |= LV_BORDER_SIDE_TOP;
            if (below) sides |= LV_BORDER_SIDE_BOTTOM;
            add_group_frame(col_x(x) + 4, row_y(y) + 4, span_w(x, 1) - 8, span_h(y, 1) - 8, sides, false);
        }
    }
}

static void show_page(int index)
{
    /* Schlanker Aufbau + Icons/Farben wie Editor. Keine Slider/Bilder beim Paint. */
    close_pin_popup(false);
    close_dim_popup();
    ui_wdt_kick();
    cJSON *pages = layout_root ? cJSON_GetObjectItem(layout_root, "pages") : NULL;
    int count = cJSON_GetArraySize(pages);
    if (count <= 0) return;
    if (index < 0 || index >= count) index = 0;
    page_index = index;
    cJSON *page = cJSON_GetArrayItem(pages, index);
    ensure_persisted_page();
    store_page_id(cJSON_GetStringValue(cJSON_GetObjectItem(page, "id")));
    ESP_LOGI(TAG_UI, "Zeichne Seite %d/%d (einfach)", index + 1, count);
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
    for (int index = 0; index < tile_count; index++) {
        if (tiles[index].image_px) heap_caps_free(tiles[index].image_px);
        tiles[index].image_px = NULL;
        tiles[index].image_px_cap = 0;
    }
    lv_obj_clean(grid);
    tile_count = 0;
    value_rule_used = 0;
    chart_slot_count = 0;
    flow_slot_count = 0;
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
        /* Eine Zeile, Breite gleichmäßig geteilt. Passt nicht jede Seite in die Leiste, entfallen die letzten. */
        const int bar_w = HOMEDISPLAY_LCD_H_RES - HEADER_LEFT - HEADER_RIGHT;
        int shown = count;
        while (shown > 1 && bar_w / shown < TAB_MIN_W) shown--;
        const int tab_w = bar_w / shown;
        for (int item = 0; item < shown; item++) {
            int tab_x = item * tab_w;
            cJSON *candidate = cJSON_GetArrayItem(pages, item);
            lv_obj_t *button = lv_btn_create(page_bar);
            lv_obj_set_pos(button, tab_x, 0);
            lv_obj_set_size(button, tab_w - 6, TAB_H);
            lv_obj_set_style_max_height(button, TAB_H, 0);
            lv_obj_set_style_pad_all(button, 0, 0);
            lv_obj_set_style_radius(button, 6, 0);
            style_flat(button);
            lv_obj_set_style_bg_color(button, lv_color_hex(item == index ? accent_tab() : accent_tab_bg()), 0);
            lv_obj_set_style_bg_opa(button, LV_OPA_COVER, 0);
            lv_obj_set_style_text_color(button, lv_color_hex(text_fg()), 0);
            const int button_w = tab_w - 6;
            lv_obj_t *row = lv_obj_create(button);
            lv_obj_set_size(row, button_w, TAB_H);
            style_flat(row);
            lv_obj_set_style_bg_opa(row, LV_OPA_TRANSP, 0);
            lv_obj_set_style_pad_all(row, 0, 0);
            lv_obj_set_flex_flow(row, LV_FLEX_FLOW_ROW);
            lv_obj_set_flex_align(row, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
            lv_obj_set_style_pad_column(row, 0, 0);
            lv_obj_set_style_text_color(row, lv_color_hex(text_fg()), 0);
            lv_obj_clear_flag(row, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_center(row);
            const char *tab_icon = cJSON_GetStringValue(cJSON_GetObjectItem(candidate, "icon"));
            bool has_icon = tab_icon && tab_icon[0];
            int icon_px = has_icon ? 28 : 0;
            int gaps = has_icon ? 3 : 2;
            int caption_w = button_w - icon_px - gaps * 4;
            if (caption_w < 8) caption_w = 8;
            if (has_icon) {
                lv_obj_t *symbol = lv_label_create(row);
                paint_icon_label(symbol, tab_icon, false, false, text_fg());
                lv_obj_clear_flag(symbol, LV_OBJ_FLAG_CLICKABLE);
            }
            lv_obj_t *caption = lv_label_create(row);
            const char *title = cJSON_GetStringValue(cJSON_GetObjectItem(candidate, "title"));
            label_set_folded(caption, title ? title : "");
            char folded[320];
            fold_german(folded, sizeof(folded), title ? title : "");
            bool tab_bold = cJSON_IsTrue(cJSON_GetObjectItem(layout_root, "tabBold"));
            const lv_font_t *tab_fonts_regular[] = {&lv_font_montserrat_28, &lv_font_montserrat_24, &lv_font_montserrat_20, &lv_font_montserrat_16};
            const lv_font_t *tab_fonts_bold[] = {&montserrat_bold_28, &montserrat_bold_24, &montserrat_bold_20, &montserrat_bold_16};
            const lv_font_t **tab_fonts = tab_bold ? tab_fonts_bold : tab_fonts_regular;
            const lv_font_t *tab_font = tab_fonts[3];
            lv_point_t text_size = {0, 0};
            for (int step = 0; step < 4; step++) {
                lv_txt_get_size(&text_size, folded, tab_fonts[step], 0, 0, LV_COORD_MAX, LV_TEXT_FLAG_NONE);
                tab_font = tab_fonts[step];
                if (text_size.x <= caption_w) break;
            }
            lv_label_set_long_mode(caption, LV_LABEL_LONG_DOT);
            int label_w = text_size.x < caption_w ? text_size.x : caption_w;
            if (label_w < 1) label_w = 1;
            lv_obj_set_size(caption, label_w, lv_font_get_line_height(tab_font));
            lv_obj_set_style_text_font(caption, tab_font, 0);
            lv_obj_set_style_text_color(caption, lv_color_hex(text_fg()), 0);
            lv_obj_set_style_text_align(caption, LV_TEXT_ALIGN_CENTER, 0);
            lv_obj_add_event_cb(button, page_event, LV_EVENT_CLICKED, (void *) (intptr_t) item);
        }
    } else {
        lv_obj_add_flag(page_bar, LV_OBJ_FLAG_HIDDEN);
    }
    if (home_button) lv_obj_move_foreground(home_button);
    if (info_label) lv_obj_move_foreground(info_label);
    if (clock_hit) lv_obj_move_foreground(clock_hit);
    /* Raster der Seite; ältere Veröffentlichungen tragen es noch an der Ansicht. */
    cJSON *page_cols = cJSON_GetObjectItem(page, "columns");
    cJSON *page_rows = cJSON_GetObjectItem(page, "rows");
    columns = cJSON_IsNumber(page_cols) ? page_cols->valueint : json_int(layout_root, "columns", 4);
    if (columns < 1) columns = 1;
    if (columns > GRID_COLS_MAX) columns = GRID_COLS_MAX;
    int rows = cJSON_IsNumber(page_rows) ? page_rows->valueint : json_int(layout_root, "rows", 5);
    if (rows < 1) rows = 1;
    if (rows > GRID_ROWS_MAX) rows = GRID_ROWS_MAX;
    col_count = columns;
    row_count = rows;
    build_edges(col_edge, columns, grid_w, cJSON_GetObjectItem(page, "columnWeights"));
    build_edges(row_edge, rows, grid_h, cJSON_GetObjectItem(page, "rowWeights"));
    draw_group_frames(page);
    cJSON *tile = NULL;
    for (int draw_pass = 0; draw_pass < 2; draw_pass++) {
    cJSON_ArrayForEach(tile, cJSON_GetObjectItem(page, "tiles")) {
        if (tile_count >= 24) break;
        if ((draw_pass == 0 && !tile_snaps(tile)) || (draw_pass == 1 && tile_snaps(tile))) continue;
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
            int place_x = col_x(col);
            int place_y = row_y(row);
            int place_w = span_w(col, col_span);
            int place_h = span_h(row, row_span);
            if (!tile_snaps(tile)) card_box(tile, col, row, col_span, row_span, 0, 0, 0, 0, &place_x, &place_y, &place_w, &place_h);
            lv_obj_t *card = lv_obj_create(grid);
            lv_obj_set_pos(card, place_x, place_y);
            lv_obj_set_size(card, place_w, place_h);
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
                lv_obj_set_size(line, thickness, place_h);
            } else {
                lv_obj_set_size(line, place_w, thickness);
            }
            lv_obj_align(line, LV_ALIGN_CENTER, 0, 0);
            continue;
        }
        if (show_page_may_yield) {
            lvgl_port_unlock();
            ui_wdt_kick();
            vTaskDelay(pdMS_TO_TICKS(20));
            ui_wdt_kick();
            /* Die Sperre muss zurückkommen, bevor an weiteren LVGL-Objekten gebaut wird.
             * Ungeschützt weiterzeichnen, während der Renderer gleichzeitig läuft, beschädigt
             * den Objektbaum und lässt LVGL selbst hängen. Also warten, nicht aufgeben. */
            while (!lvgl_port_lock(UI_CLIENT_LOCK_MS)) {
                ui_wdt_kick();
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
        load_type(view, tile, kind);
        view->confirm = cJSON_IsTrue(cJSON_GetObjectItem(tile, "confirm"));
        int pin_no = json_int(tile, "pin", 0);
        view->pin = (pin_no == 1 || pin_no == 2) ? pin_no : 0;
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
        view->own_bg = own_bg;
        view->bg_clear = cJSON_IsTrue(cJSON_GetObjectItem(tile, "bgTransparent"));
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
        int inset_l = 0, inset_r = 0, inset_t = 0, inset_b = 0;
        group_insets(page, tile, &inset_l, &inset_r, &inset_t, &inset_b);
        int card_x = 0;
        int card_y = 0;
        int card_w = 16;
        int card_h = 16;
        card_box(tile, col, row, col_span, row_span, inset_l, inset_r, inset_t, inset_b, &card_x, &card_y, &card_w, &card_h);
        lv_obj_set_pos(view->card, card_x, card_y);
        lv_obj_set_size(view->card, card_w, card_h);
        lv_obj_set_style_radius(view->card, 8, 0);
        lv_obj_set_style_bg_color(view->card, lv_color_hex(view->base_bg), 0);
        lv_obj_set_style_bg_opa(view->card, view->bg_clear ? LV_OPA_TRANSP : LV_OPA_COVER, 0);
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
                lv_obj_set_style_text_font(name, font_for(view->title_px, view->title_bold, view->title_italic), 0);
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
            lv_obj_set_width(label, span_w(col, col_span) - 24);
            bool label_bold = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textBold"));
            bool label_italic = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textItalic"));
            lv_obj_set_style_text_font(label, font_for(view->text_px, label_bold, label_italic), 0);
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
            const char *source = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageSource"));
            const char *file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "imageFile"));
            if (!file || !file[0]) file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "mediaFile"));
            view->image_backdrop = cJSON_IsTrue(cJSON_GetObjectItem(tile, "imageBackground"));
            lv_obj_set_style_pad_all(view->card, 0, 0);
            if (view->image_backdrop || !view->own_bg || view->bg_clear) lv_obj_set_style_bg_opa(view->card, LV_OPA_TRANSP, 0);
            view->image_img = lv_img_create(view->card);
            lv_obj_set_style_bg_opa(view->image_img, LV_OPA_TRANSP, 0);
            lv_obj_set_size(view->image_img, card_w, card_h);
            lv_obj_center(view->image_img);
            if (source && strcmp(source, "icon") == 0) {
                const char *text_color = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "textColor"));
                uint32_t ink = 0xFFFFFF;
                if (!color_mono && text_color && text_color[0] == '#') ink = parse_hex_color(text_color, 0xFFFFFF);
                show_icon_fit(view, icon, card_w, card_h, ink);
            } else if (file && file[0]) {
                place_cover_image(view, file, card_w, card_h);
            }
            if (view->image_backdrop && tile_snaps(tile)) lv_obj_move_background(view->card);
            tile_count++;
            continue;
        }
        if (strcmp(kind, "camera") == 0) {
            const char *file = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "cameraFile"));
            int img_w;
            int img_h;
            lv_obj_t *name = NULL;
            if (show_title) {
                lv_obj_set_style_pad_all(view->card, 4, 0);
                name = lv_label_create(view->card);
                label_set_folded(name, view->title[0] ? view->title : "Kamera");
                lv_obj_set_style_text_font(name, font_for(view->title_px, view->title_bold, view->title_italic), 0);
                lv_obj_align(name, LV_ALIGN_TOP_LEFT, 4, 2);
                img_w = span_w(col, col_span) - 16;
                img_h = span_h(row, row_span) - 44;
            } else {
                lv_obj_set_style_pad_all(view->card, 0, 0);
                img_w = span_w(col, col_span) - 8;
                img_h = span_h(row, row_span) - 8;
            }
            if (img_w < 16) img_w = 16;
            if (img_h < 16) img_h = 16;
            view->camera_img = lv_img_create(view->card);
            (void) file;
            view->camera_dy = show_title ? -4 : 0;
            lv_obj_set_size(view->camera_img, img_w, img_h);
            lv_obj_align(view->camera_img, LV_ALIGN_BOTTOM_MID, 0, view->camera_dy);
            camera_attach(view);
            if (name) lv_obj_move_foreground(name);
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
        const char *off_ink = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "iconOffColor"));
        const char *on_ink = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "iconOnColor"));
        if (!color_mono && off_ink && off_ink[0] == '#' && on_ink && on_ink[0] == '#') {
            view->icon_off_ink = parse_hex_color(off_ink, 0x1A1A1A);
            view->icon_on_ink = parse_hex_color(on_ink, 0xFF9F1A);
            view->own_icon_ink = true;
        }
        bool icon_toggle = strcmp(view->icon_mode, "toggle") == 0;
        view->show_icon = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "showIcon"));
        view->show_state_text = cJSON_IsTrue(cJSON_GetObjectItem(tile, "showStateText"));
        view->dim_show_switch = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "dimShowSwitch"));
        view->dim_show_bar = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "dimShowBar"));
        view->dim_show_percent = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "dimShowPercent"));
        view->dim_show_state_text = cJSON_IsTrue(cJSON_GetObjectItem(tile, "dimShowStateText"));
        if (strcmp(view->control, "image") == 0) {
            view->show_state_text = false;
            view->dim_show_percent = false;
            view->dim_show_state_text = false;
        }
        bool battery = strcmp(view->control, "battery") == 0;
        bool dim_switch = strcmp(view->control, "dimSwitch") == 0;
        bool slider = strcmp(view->control, "slider") == 0;
        bool combo = strcmp(view->control, "combo") == 0;
        bool dimmer = dim_switch || slider || combo;
        const char *action_kind = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "actionKind"));
        bool action_dim = action_kind && strcmp(action_kind, "dim") == 0 && strcmp(view->control, "setpoint") != 0;
        bool plain_value = strcmp(kind, "value") == 0 && !view->has_action && !dimmer && !battery;
        bool ghost = false;
        if (strcmp(kind, "value") == 0) {
            cJSON *other = NULL;
            cJSON_ArrayForEach(other, cJSON_GetObjectItem(page, "tiles")) {
                if (other == tile) continue;
                const char *other_kind = cJSON_GetStringValue(cJSON_GetObjectItem(other, "kind"));
                if (!other_kind || strcmp(other_kind, "image") != 0) continue;
                if (!cJSON_IsTrue(cJSON_GetObjectItem(other, "imageBackground"))) continue;
                if (tile_rects_overlap(tile, other)) {
                    ghost = true;
                    break;
                }
            }
        }
        if (ghost) lv_obj_set_style_bg_opa(view->card, LV_OPA_TRANSP, 0);
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
        const char *title_color = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "titleColor"));
        if (title_color && title_color[0] == '#') {
            view->title_color = parse_hex_color(title_color, text_fg());
            view->own_title = true;
        }
        cJSON *threshold = cJSON_GetObjectItem(tile, "threshold");
        if (threshold && cJSON_IsNumber(threshold)) {
            view->has_threshold = true;
            view->threshold = (float) threshold->valuedouble;
            const char *status = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "color"));
            snprintf(view->status_color, sizeof(view->status_color), "%s", status ? status : "neutral");
        }
        cJSON *rules = cJSON_GetObjectItem(tile, "valueRules");
        if (cJSON_IsArray(rules)) {
            cJSON *rule = NULL;
            cJSON_ArrayForEach(rule, rules) {
                if (view->value_rule_count >= VALUE_RULE_MAX || value_rule_used >= VALUE_RULE_POOL) break;
                if (!cJSON_IsObject(rule)) continue;
                if (view->value_rule_count == 0) view->value_rule_first = value_rule_used;
                value_rule_t *slot = &value_rule_pool[value_rule_used++];
                view->value_rule_count++;
                memset(slot, 0, sizeof(*slot));
                cJSON *from = cJSON_GetObjectItem(rule, "from");
                cJSON *to = cJSON_GetObjectItem(rule, "to");
                if (cJSON_IsNumber(from)) {
                    slot->from = (float) from->valuedouble;
                    slot->has_from = true;
                }
                if (cJSON_IsNumber(to)) {
                    slot->to = (float) to->valuedouble;
                    slot->has_to = true;
                }
                const char *rule_icon = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "icon"));
                if (rule_icon) snprintf(slot->icon, sizeof(slot->icon), "%s", rule_icon);
                const char *rule_place = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "place"));
                slot->after = rule_place && strcmp(rule_place, "after") == 0;
                const char *icon_color = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "iconColor"));
                if (icon_color && icon_color[0] == '#') {
                    slot->icon_color = parse_hex_color(icon_color, 0xF4F7FB);
                    slot->has_icon_color = true;
                }
                const char *text_ink = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "textColor"));
                if (text_ink && text_ink[0] == '#') {
                    slot->text_color = parse_hex_color(text_ink, 0xF4F7FB);
                    slot->has_text_color = true;
                }
                const char *bg_ink = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "bgColor"));
                if (bg_ink && bg_ink[0] == '#') {
                    slot->bg_color = parse_hex_color(bg_ink, view->base_bg);
                    slot->has_bg_color = true;
                }
                const char *rule_text = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "text"));
                if (rule_text) snprintf(slot->text, sizeof(slot->text), "%.15s", rule_text);
                const char *rule_align = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "align"));
                if (rule_align && (strcmp(rule_align, "left") == 0 || strcmp(rule_align, "center") == 0 || strcmp(rule_align, "right") == 0)) {
                    snprintf(slot->align, sizeof(slot->align), "%s", rule_align);
                }
                const char *image_id = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "imageId"));
                if (image_id) snprintf(slot->image_id, sizeof(slot->image_id), "%.19s", image_id);
                const char *image_file = cJSON_GetStringValue(cJSON_GetObjectItem(rule, "imageFile"));
                if (image_file) snprintf(slot->image_file, sizeof(slot->image_file), "%.27s", image_file);
            }
        }

        /* Titelzeile: kleines Icon + Text */
        cJSON *show_title_icon_item = cJSON_GetObjectItem(tile, "showTitleIcon");
        bool show_title_icon = cJSON_IsBool(show_title_icon_item) ? cJSON_IsTrue(show_title_icon_item) : !plain_value;
        if (ghost) show_title_icon = false;
        const char *title_icon_field = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "titleIcon"));
        const char *title_icon_src = NULL;
        /* Bei Schaltern, Szenen und Dimmern ist `icon` das Schalt-Symbol. Es steht schon groß auf der Kachel,
         * die Überschrift zeigt dort nur das eigens gewählte Symbol. */
        bool own_title_icon_only = plain_value || icon_toggle || dimmer || action_dim;
        if (own_title_icon_only) title_icon_src = (title_icon_field && title_icon_field[0]) ? title_icon_field : NULL;
        else if (title_icon_field && title_icon_field[0]) title_icon_src = title_icon_field;
        else title_icon_src = icon;
        if (show_title && plain_value) {
            if (!cJSON_IsNumber(cJSON_GetObjectItem(tile, "titlePx"))) view->title_px = 28;
            const lv_font_t *title_font = font_for(view->title_px, view->title_bold, view->title_italic);
            int title_h = lv_font_get_line_height(title_font);
            view->title_row = lv_obj_create(view->card);
            lv_obj_set_size(view->title_row, LV_PCT(100), title_h);
            style_flat(view->title_row);
            lv_obj_set_style_bg_opa(view->title_row, LV_OPA_TRANSP, 0);
            lv_obj_set_style_pad_all(view->title_row, 0, 0);
            lv_obj_set_flex_flow(view->title_row, LV_FLEX_FLOW_ROW);
            lv_obj_set_flex_align(view->title_row, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
            lv_obj_set_style_pad_column(view->title_row, 6, 0);
            lv_obj_set_style_text_color(view->title_row, lv_color_hex(title_ink(view)), 0);
            lv_obj_clear_flag(view->title_row, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_align(view->title_row, LV_ALIGN_TOP_LEFT, 0, 0);
            if (show_title_icon && title_icon_src && title_icon_src[0]) {
                make_icon_label(view->title_row, view, title_icon_src, &view->title_icon, false);
                lv_obj_set_style_text_color(view->title_icon, lv_color_hex(title_ink(view)), 0);
                snprintf(view->title_icon_name, sizeof(view->title_icon_name), "%s", title_icon_src);
            }
            lv_obj_t *name = lv_label_create(view->title_row);
            label_set_folded(name, view->title[0] ? view->title : "-");
            view->title_label = name;
            lv_obj_set_style_text_font(name, title_font, 0);
            lv_obj_set_style_text_color(name, lv_color_hex(title_ink(view)), 0);
            lv_label_set_long_mode(name, LV_LABEL_LONG_DOT);
            lv_obj_clear_flag(name, LV_OBJ_FLAG_CLICKABLE);
        } else if (show_title && strcmp(kind, "chart") != 0) {
            /* Symbol und Text in einer Zeile. Getrennte Ausrichtung hat das Symbol auf den Text gelegt. */
            const lv_font_t *title_font = font_for(view->title_px, view->title_bold, view->title_italic);
            bool has_heading_icon = show_title_icon && title_icon_src && title_icon_src[0];
            int title_h = lv_font_get_line_height(title_font);
            if (has_heading_icon && title_h < 28) title_h = 28;
            view->title_row = lv_obj_create(view->card);
            lv_obj_set_size(view->title_row, LV_PCT(100), title_h);
            style_flat(view->title_row);
            lv_obj_set_style_bg_opa(view->title_row, LV_OPA_TRANSP, 0);
            lv_obj_set_style_pad_all(view->title_row, 0, 0);
            lv_obj_set_flex_flow(view->title_row, LV_FLEX_FLOW_ROW);
            lv_flex_align_t main = LV_FLEX_ALIGN_START;
            if (strcmp(title_align, "center") == 0) main = LV_FLEX_ALIGN_CENTER;
            else if (strcmp(title_align, "right") == 0) main = LV_FLEX_ALIGN_END;
            lv_obj_set_flex_align(view->title_row, main, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
            lv_obj_set_style_pad_column(view->title_row, 6, 0);
            lv_obj_clear_flag(view->title_row, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_align(view->title_row, LV_ALIGN_TOP_LEFT, 0, 0);
            if (has_heading_icon) {
                make_icon_label(view->title_row, view, title_icon_src, &view->title_icon, false);
                lv_obj_set_style_text_color(view->title_icon, lv_color_hex(title_ink(view)), 0);
                snprintf(view->title_icon_name, sizeof(view->title_icon_name), "%s", title_icon_src);
            }
            lv_obj_t *name = lv_label_create(view->title_row);
            label_set_folded(name, view->title[0] ? view->title : "-");
            view->title_label = name;
            lv_obj_set_style_text_font(name, title_font, 0);
            lv_obj_set_style_text_color(name, lv_color_hex(title_ink(view)), 0);
            int max_text = card_w - 20 - (has_heading_icon ? 34 : 0);
            if (max_text < 8) max_text = 8;
            lv_obj_set_width(name, text_width_capped(view->title[0] ? view->title : "-", title_font, max_text));
            lv_label_set_long_mode(name, LV_LABEL_LONG_DOT);
            lv_obj_clear_flag(name, LV_OBJ_FLAG_CLICKABLE);
        }

        if (strcmp(kind, "chart") == 0) {
            lv_obj_set_style_pad_all(view->card, 4, 0);
            const char *style = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "chartStyle"));
            view->chart_area = !style || strcmp(style, "line") != 0;
            view->chart_axes = cJSON_IsTrue(cJSON_GetObjectItem(tile, "chartShowAxes"));
            view->chart_grid = cJSON_IsTrue(cJSON_GetObjectItem(tile, "chartShowGrid"));
            view->chart_show_value = !cJSON_IsFalse(cJSON_GetObjectItem(tile, "chartShowValue"));
            view->chart_color = color_mono ? 0xFFFFFF : theme_accent;
            const char *chart_color = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "chartColor"));
            if (!color_mono && chart_color && chart_color[0] == '#') {
                view->chart_color = parse_hex_color(chart_color, view->chart_color);
            }
            const char *period = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "chartPeriod"));
            snprintf(view->chart_period, sizeof(view->chart_period), "%s", period && period[0] ? period : "24h");
            const char *unit = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "unit"));
            snprintf(view->chart_unit, sizeof(view->chart_unit), "%s", unit ? unit : "");
            view->chart_decimals = json_int(tile, "decimals", 1);
            if (view->chart_decimals < 0) view->chart_decimals = 0;
            if (view->chart_decimals > 3) view->chart_decimals = 3;
            snprintf(view->row_align, sizeof(view->row_align), "%s", title_align);
            if (show_title) {
                view->title_row = lv_obj_create(view->card);
                style_flat(view->title_row);
                lv_obj_set_style_bg_opa(view->title_row, LV_OPA_TRANSP, 0);
                lv_obj_set_style_pad_all(view->title_row, 0, 0);
                lv_obj_set_flex_flow(view->title_row, LV_FLEX_FLOW_ROW);
                lv_obj_set_style_pad_column(view->title_row, 6, 0);
                lv_obj_set_style_text_color(view->title_row, lv_color_hex(title_ink(view)), 0);
                lv_obj_clear_flag(view->title_row, LV_OBJ_FLAG_CLICKABLE);
                if (show_title_icon && title_icon_src && title_icon_src[0]) {
                    make_icon_label(view->title_row, view, title_icon_src, &view->title_icon, false);
                    lv_obj_set_style_text_color(view->title_icon, lv_color_hex(title_ink(view)), 0);
                    snprintf(view->title_icon_name, sizeof(view->title_icon_name), "%s", title_icon_src);
                }
                view->title_label = lv_label_create(view->title_row);
                label_set_folded(view->title_label, view->title[0] ? view->title : "-");
                lv_obj_set_style_text_color(view->title_label, lv_color_hex(title_ink(view)), 0);
                lv_obj_clear_flag(view->title_label, LV_OBJ_FLAG_CLICKABLE);
            }
            int head = 0;
            if (show_title) head = lv_font_get_line_height(font_for(view->title_px, view->title_bold, view->title_italic));
            if (view->chart_show_value) {
                int value_h = lv_font_get_line_height(font_for(view->text_px, view->text_bold, view->text_italic));
                if (value_h > head) head = value_h;
            }
            if (head > 0) head += 4;
            int chart_w = card_w - 8;
            int chart_h = card_h - 8 - head;
            if (chart_w < 8) chart_w = 8;
            if (chart_h < 8) chart_h = 8;
            view->chart_w = chart_w;
            view->chart_h = chart_h;
            if (chart_slot_count < CHART_SLOT_MAX) {
                view->chart_points = chart_point_pool[chart_slot_count];
                view->chart_yest = chart_yest_pool[chart_slot_count];
                chart_slot_count++;
                view->chart_line = lv_obj_create(view->card);
                style_flat(view->chart_line);
                lv_obj_set_style_bg_opa(view->chart_line, LV_OPA_TRANSP, 0);
                lv_obj_set_style_pad_all(view->chart_line, 0, 0);
                lv_obj_clear_flag(view->chart_line, LV_OBJ_FLAG_CLICKABLE);
                lv_obj_set_size(view->chart_line, chart_w, chart_h);
                lv_obj_align(view->chart_line, LV_ALIGN_BOTTOM_MID, 0, 0);
                lv_obj_add_flag(view->chart_line, LV_OBJ_FLAG_HIDDEN);
                lv_obj_add_event_cb(view->chart_line, chart_draw_event, LV_EVENT_DRAW_MAIN, view);
                view->chart_logged = -1;
                for (int axis = 0; axis < 3; axis++) {
                    view->chart_y_label[axis] = lv_label_create(view->chart_line);
                    view->chart_x_label[axis] = lv_label_create(view->chart_line);
                    lv_obj_set_style_text_font(view->chart_y_label[axis], &lv_font_montserrat_16, 0);
                    lv_obj_set_style_text_font(view->chart_x_label[axis], &lv_font_montserrat_16, 0);
                    lv_obj_set_style_text_color(view->chart_y_label[axis], lv_color_hex(text_fg()), 0);
                    lv_obj_set_style_text_color(view->chart_x_label[axis], lv_color_hex(text_fg()), 0);
                    lv_obj_set_style_text_opa(view->chart_y_label[axis], LV_OPA_80, 0);
                    lv_obj_set_style_text_opa(view->chart_x_label[axis], LV_OPA_80, 0);
                    lv_obj_add_flag(view->chart_y_label[axis], LV_OBJ_FLAG_HIDDEN);
                    lv_obj_add_flag(view->chart_x_label[axis], LV_OBJ_FLAG_HIDDEN);
                    lv_obj_clear_flag(view->chart_y_label[axis], LV_OBJ_FLAG_CLICKABLE);
                    lv_obj_clear_flag(view->chart_x_label[axis], LV_OBJ_FLAG_CLICKABLE);
                }
                view->chart_note = lv_label_create(view->card);
                lv_label_set_text(view->chart_note, "Verlauf wird geladen");
                lv_obj_set_style_text_font(view->chart_note, &lv_font_montserrat_16, 0);
                lv_obj_set_style_text_color(view->chart_note, lv_color_hex(text_fg()), 0);
                lv_obj_set_style_text_opa(view->chart_note, LV_OPA_70, 0);
                lv_obj_align(view->chart_note, LV_ALIGN_CENTER, 0, head > 0 ? head / 2 : 0);
                lv_obj_clear_flag(view->chart_note, LV_OBJ_FLAG_CLICKABLE);
            }
            if (view->chart_show_value) {
                view->label = lv_label_create(view->card);
                lv_label_set_text(view->label, "-");
                lv_obj_set_style_text_font(view->label, font_for(view->text_px, view->text_bold, view->text_italic), 0);
                lv_obj_set_style_text_color(view->label, lv_color_hex(text_fg()), 0);
                lv_obj_clear_flag(view->label, LV_OBJ_FLAG_CLICKABLE);
            }
            layout_chart_head(view);
            tile_count++;
            continue;
        }

        if (battery) {
            view->battery_pad = json_int(tile, "batteryPad", 8);
            build_battery(view, view->battery_pad);
            view->label = lv_label_create(view->card);
            lv_label_set_text(view->label, "-");
            lv_obj_set_style_text_font(view->label, font_for(view->text_px, view->text_bold, view->text_italic), 0);
            lv_obj_align(view->label, LV_ALIGN_TOP_RIGHT, 0, 2);
            if (view->show_age) {
                view->age_label = lv_label_create(view->card);
                lv_label_set_text(view->age_label, "");
                lv_obj_set_style_text_font(view->age_label, &lv_font_montserrat_16, 0);
                lv_obj_align(view->age_label, LV_ALIGN_BOTTOM_MID, 0, -2);
            }
            tile_count++;
            continue;
        }

        if (strcmp(kind, "energyflow") == 0) {
            build_energy_flow(view, tile, card_w, card_h, show_title);
            tile_count++;
            continue;
        }

        if (strcmp(kind, "multi") == 0 || strcmp(kind, "contacts") == 0) {
            bool contacts = strcmp(kind, "contacts") == 0;
            cJSON *rows = cJSON_GetObjectItem(tile, "rows");
            int count = cJSON_GetArraySize(rows);
            if (count > 6) count = 6;
            int y = show_title ? 28 : 8;
            for (int row_index = 0; row_index < count; row_index++) {
                cJSON *row = cJSON_GetArrayItem(rows, row_index);
                const char *caption = cJSON_GetStringValue(cJSON_GetObjectItem(row, "title"));
                if (contacts) {
                    view->dots[row_index] = lv_obj_create(view->card);
                    lv_obj_set_size(view->dots[row_index], 14, 14);
                    lv_obj_set_pos(view->dots[row_index], 8, y + 4);
                    style_flat(view->dots[row_index]);
                    lv_obj_set_style_radius(view->dots[row_index], LV_RADIUS_CIRCLE, 0);
                    lv_obj_set_style_bg_opa(view->dots[row_index], LV_OPA_TRANSP, 0);
                    lv_obj_set_style_border_width(view->dots[row_index], 2, 0);
                    lv_obj_set_style_border_color(view->dots[row_index], lv_color_hex(0xF4F7FB), 0);
                    lv_obj_clear_flag(view->dots[row_index], LV_OBJ_FLAG_CLICKABLE);
                }
                view->rows[row_index] = lv_label_create(view->card);
                lv_label_set_text(view->rows[row_index], caption && caption[0] ? caption : "-");
                lv_obj_set_style_text_font(view->rows[row_index], font_for(view->text_px, view->text_bold, view->text_italic), 0);
                lv_obj_set_pos(view->rows[row_index], contacts ? 30 : 8, y);
                lv_obj_set_width(view->rows[row_index], span_w(col, col_span) - (contacts ? 40 : 16));
                lv_label_set_long_mode(view->rows[row_index], LV_LABEL_LONG_DOT);
                y += 28;
            }
            tile_count++;
            continue;
        }

        if (strcmp(kind, "gauge") == 0) {
            view->gauge_min = json_int(tile, "valueMin", 0);
            view->gauge_max = json_int(tile, "valueMax", 100);
            int side = card_w;
            int avail_h = card_h - (show_title ? 24 : 8);
            if (avail_h < side) side = avail_h;
            if (side < 48) side = 48;
            view->arc = lv_arc_create(view->card);
            lv_obj_set_size(view->arc, side - 8, side - 8);
            lv_arc_set_rotation(view->arc, 135);
            lv_arc_set_bg_angles(view->arc, 0, 270);
            lv_arc_set_range(view->arc, 0, 100);
            lv_arc_set_value(view->arc, 0);
            lv_obj_clear_flag(view->arc, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_set_style_bg_opa(view->arc, LV_OPA_TRANSP, LV_PART_KNOB);
            lv_obj_set_style_pad_all(view->arc, 0, LV_PART_KNOB);
            lv_obj_align(view->arc, LV_ALIGN_CENTER, 0, show_title ? 12 : 0);
            lv_obj_set_style_arc_width(view->arc, 10, LV_PART_MAIN);
            lv_obj_set_style_arc_width(view->arc, 10, LV_PART_INDICATOR);
            lv_obj_set_style_arc_color(view->arc, lv_color_hex(0x243044), LV_PART_MAIN);
            lv_obj_set_style_arc_color(view->arc, lv_color_hex(color_mono ? 0xFFFFFF : theme_accent), LV_PART_INDICATOR);
            view->label = lv_label_create(view->card);
            lv_label_set_text(view->label, "-");
            lv_obj_set_style_text_font(view->label, font_for(view->text_px, view->text_bold, view->text_italic), 0);
            lv_obj_align(view->label, LV_ALIGN_CENTER, 0, show_title ? 12 : 0);
            if (view->show_age) {
                view->age_label = lv_label_create(view->card);
                lv_label_set_text(view->age_label, "");
                lv_obj_set_style_text_font(view->age_label, &lv_font_montserrat_16, 0);
                lv_obj_align(view->age_label, LV_ALIGN_BOTTOM_MID, 0, -2);
            }
            tile_count++;
            continue;
        }

        if (strcmp(kind, "schedule") == 0) {
            view->schedule = true;
            if (show_title && view->title[0]) {
                view->title_label = lv_label_create(view->card);
                lv_label_set_text(view->title_label, view->title);
                lv_obj_set_style_text_font(view->title_label, font_for(view->title_px, view->title_bold, view->title_italic), 0);
                lv_obj_set_style_text_color(view->title_label, lv_color_hex(title_ink(view)), 0);
                lv_obj_align(view->title_label, LV_ALIGN_TOP_LEFT, 0, 0);
            }
            view->value_box = lv_obj_create(view->card);
            lv_obj_set_style_bg_opa(view->value_box, LV_OPA_TRANSP, 0);
            lv_obj_set_style_border_width(view->value_box, 0, 0);
            lv_obj_set_style_pad_all(view->value_box, 0, 0);
            lv_obj_set_style_radius(view->value_box, 0, 0);
            lv_obj_clear_flag(view->value_box, LV_OBJ_FLAG_SCROLLABLE | LV_OBJ_FLAG_CLICKABLE);
            int top = (show_title && view->title[0]) ? (view->title_px > 16 ? view->title_px + 8 : 28) : 0;
            int plot_w = card_w - 20;
            int plot_h = card_h - 20 - top;
            if (plot_w < 40) plot_w = 40;
            if (plot_h < 24) plot_h = 24;
            lv_obj_set_size(view->value_box, plot_w, plot_h);
            lv_obj_align(view->value_box, LV_ALIGN_BOTTOM_MID, 0, 0);
            tile_count++;
            continue;
        }

        if (strcmp(kind, "countdown") == 0 || strcmp(kind, "appointment") == 0) {
            view->label = lv_label_create(view->card);
            lv_label_set_text(view->label, "-");
            lv_obj_set_style_text_font(view->label, font_for(view->text_px, view->text_bold, view->text_italic), 0);
            lv_obj_set_style_text_color(view->label, lv_color_hex(view->own_text ? view->text_color : text_fg()), 0);
            lv_obj_align(view->label, LV_ALIGN_CENTER, 0, (show_title && view->title[0]) ? 10 : 0);
            view->age_label = lv_label_create(view->card);
            lv_label_set_text(view->age_label, "");
            lv_obj_set_style_text_font(view->age_label, &lv_font_montserrat_16, 0);
            lv_obj_set_style_text_color(view->age_label, lv_color_hex(text_fg()), 0);
            lv_obj_align(view->age_label, LV_ALIGN_BOTTOM_MID, 0, -4);
            tile_count++;
            continue;
        }

        view->label = lv_label_create(view->card);
        lv_label_set_text(view->label, "-");
        bool value_bold = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textBold"));
        bool value_italic = cJSON_IsTrue(cJSON_GetObjectItem(tile, "textItalic"));
        view->text_bold = value_bold || view->text_bold;
        view->text_italic = value_italic || view->text_italic;
        lv_obj_set_style_text_font(view->label, font_for(view->text_px, view->text_bold, view->text_italic), 0);
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
                /* Feste Größe, unterhalb der Titelzeile, damit er nie in die Überschrift ragt. */
                lv_obj_set_size(view->power, 56, 28);
                lv_obj_align(view->power, LV_ALIGN_LEFT_MID, 8, show_title ? 16 : 6);
            }
            if (view->dim_show_bar) {
                int width = span_w(col, col_span) - (view->dim_show_switch ? 96 : 28);
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
            bool image_switch = strcmp(view->control, "image") == 0;
            if (image_switch || view->show_icon || view->value_rule_count > 0) {
                lv_coord_t icon_y = view->show_state_text ? -6 : (show_title ? 16 : 4);
                const char *shown = icon_toggle ? view->icon_off : (icon && icon[0] ? icon : "lightOff");
                view->icon_large = true;
                view->icon_label = lv_label_create(view->card);
                paint_icon_label(view->icon_label, shown, false, true, icon_state_ink(view, false));
                lv_obj_align(view->icon_label, LV_ALIGN_CENTER, 0, icon_y);
                lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_CLICKABLE);
                if (view->value_rule_count > 0 && !view->show_icon) lv_obj_add_flag(view->icon_label, LV_OBJ_FLAG_HIDDEN);
            }
            if (image_switch) {
                view->image_img = lv_img_create(view->card);
                lv_obj_set_style_bg_opa(view->image_img, LV_OPA_TRANSP, 0);
                lv_obj_center(view->image_img);
                lv_obj_add_flag(view->image_img, LV_OBJ_FLAG_HIDDEN);
                lv_obj_clear_flag(view->image_img, LV_OBJ_FLAG_CLICKABLE);
            }
            if (view->show_state_text) {
                lv_label_set_text(view->label, "Aus");
                lv_obj_align(view->label, LV_ALIGN_BOTTOM_MID, 0, -4);
            } else {
                lv_obj_add_flag(view->label, LV_OBJ_FLAG_HIDDEN);
            }
            lv_obj_add_event_cb(view->card, tile_event, LV_EVENT_CLICKED, view);
            if (view->value_rule_count > 0) apply_action_rules(view, view->value_off);
        } else if (plain_value) {
            view->value_row = true;
            view->show_title_row = show_title;
            snprintf(view->row_align, sizeof(view->row_align), "%s", title_align);
            const char *place = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "iconPlace"));
            if (place && (strcmp(place, "before") == 0 || strcmp(place, "after") == 0 || strcmp(place, "none") == 0)) {
                snprintf(view->icon_place, sizeof(view->icon_place), "%s", place);
            } else {
                snprintf(view->icon_place, sizeof(view->icon_place), "%s", (icon && icon[0]) ? "before" : "none");
            }
            if (ghost) snprintf(view->icon_place, sizeof(view->icon_place), "%s", "none");
            if (!view->own_text) lv_obj_set_style_text_color(view->label, lv_color_hex(0xF4F7FB), 0);
            view->value_box = lv_obj_create(view->card);
            style_flat(view->value_box);
            lv_obj_set_style_bg_opa(view->value_box, LV_OPA_TRANSP, 0);
            lv_obj_set_style_pad_all(view->value_box, 0, 0);
            lv_obj_clear_flag(view->value_box, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_set_flex_flow(view->value_box, LV_FLEX_FLOW_COLUMN);
            lv_obj_set_flex_align(view->value_box, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
            lv_obj_set_style_pad_row(view->value_box, 2, 0);
            lv_obj_t *line = lv_obj_create(view->value_box);
            style_flat(line);
            lv_obj_set_size(line, LV_SIZE_CONTENT, LV_SIZE_CONTENT);
            lv_obj_set_style_bg_opa(line, LV_OPA_TRANSP, 0);
            lv_obj_set_style_pad_all(line, 0, 0);
            lv_obj_clear_flag(line, LV_OBJ_FLAG_CLICKABLE);
            lv_obj_set_flex_flow(line, LV_FLEX_FLOW_ROW);
            lv_obj_set_flex_align(line, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
            lv_obj_set_style_pad_column(line, 6, 0);
            bool icon_slot = (icon && icon[0] && strcmp(view->icon_place, "none") != 0) || view->value_rule_count > 0;
            if (icon_slot) make_icon_label(line, view, (icon && icon[0]) ? icon : "energy", &view->icon_label, false);
            lv_obj_set_parent(view->label, line);
            if (view->show_age) {
                view->age_label = lv_label_create(view->value_box);
                lv_label_set_text(view->age_label, "");
                lv_obj_set_style_text_color(view->age_label, lv_color_hex(text_fg()), 0);
                lv_obj_clear_flag(view->age_label, LV_OBJ_FLAG_CLICKABLE);
            }
            layout_value_row(view);
        } else {
            /* Reine Wertanzeige */
            if (icon && icon[0]) {
                view->icon_label = lv_label_create(view->card);
                show_icon_label(view->icon_label, view, icon, false, false);
                lv_obj_align(view->icon_label, LV_ALIGN_LEFT_MID, 0, 8);
                lv_obj_clear_flag(view->icon_label, LV_OBJ_FLAG_CLICKABLE);
            }
            if (view->show_age) {
                view->age_label = lv_label_create(view->card);
                lv_label_set_text(view->age_label, "");
                lv_obj_set_style_text_font(view->age_label, &lv_font_montserrat_16, 0);
                lv_obj_align(view->age_label, LV_ALIGN_BOTTOM_MID, 0, 0);
                lv_obj_align(view->label, LV_ALIGN_BOTTOM_LEFT, 0, -18);
            } else {
                lv_obj_align(view->label, LV_ALIGN_BOTTOM_LEFT, 0, 0);
            }
        }
        if (view->title_row) lv_obj_move_foreground(view->title_row);
        tile_count++;
    }
    }
    if (!force_idle) {
        lv_obj_add_flag(clock_layer, LV_OBJ_FLAG_HIDDEN);
        idle_active = false;
    }
    lv_obj_clear_flag(grid, LV_OBJ_FLAG_HIDDEN);
    lv_obj_move_foreground(grid);
    if (page_bar && !lv_obj_has_flag(page_bar, LV_OBJ_FLAG_HIDDEN)) lv_obj_move_foreground(page_bar);
    if (home_button) lv_obj_move_foreground(home_button);
    if (info_label) lv_obj_move_foreground(info_label);
    if (clock_hit) lv_obj_move_foreground(clock_hit);
    if (force_idle && idle_active && idle_mode != 2) set_idle_visible(true);
    if (boot_layer) lv_obj_add_flag(boot_layer, LV_OBJ_FLAG_HIDDEN);
    if (last_state_json && last_state_json[0]) {
        cJSON *saved = cJSON_Parse(last_state_json);
        if (saved) {
            apply_state_body(saved);
            cJSON_Delete(saved);
        }
    }
    refresh_now = true;
    ESP_LOGI(TAG_UI, "Seite fertig (%d Kacheln)", tile_count);
}

/* Anzeige in MEZ: fester Versatz zur UTC-Zeit, ohne Sommerzeit. Uhr und RTC bleiben UTC. */
#define DISPLAY_TZ_OFFSET_S 3600

static bool night_now(void)
{
    int64_t now = clock_base + (esp_timer_get_time() - clock_set_at) / 1000000 + DISPLAY_TZ_OFFSET_S;
    if (now < 0) now = 0;
    int hour = (int) ((now % 86400) / 3600);
    if (night_from == night_to) return true;
    if (night_from < night_to) return hour >= night_from && hour < night_to;
    return hour >= night_from || hour < night_to;
}

static void apply_backlight(void)
{
    bool idle_off = idle_mode == 2 && homedisplay_ui_busy() == false
        && (force_idle || esp_timer_get_time() - touched_at > idle_timeout_us);
    int level = (night_dim && night_now()) ? night_brightness : day_brightness;
    bool on = !idle_off && level > 0;
    if (on && !backlight_on) refresh_now = true;
    backlight_on = on;
    homedisplay_board_backlight(on);
}

/* ---------- Wortuhr ----------
 * 11 Spalten, 10 Zeilen. Die Wörter stehen wie bei einer QLOCKTWO im Buchstabenraster. */
#define WC_COLS 11
#define WC_ROWS 10
/* 11×84 und 10×50 ergeben 924×500. Auf 1024×600 bleibt rundherum genau 50 Pixel. */
#define WC_CELL_W 84
#define WC_CELL_H 50
/* Feine Korrektur des sichtbaren Blocks: nach links und nach oben. */
#define WC_NUDGE_X 16
#define WC_NUDGE_Y 16
#define WC_LIT 0xFFFFFF
#define WC_DIM 0x303030

typedef struct {
    uint8_t row;
    uint8_t col;
    uint8_t len;
} wc_word_t;

typedef struct {
    const char *rows[WC_ROWS];
    wc_word_t es, is, five, ten, twenty, quarter, three_quarter, after, before, half, oclock, ein;
    wc_word_t hour[13];
    bool three_quarter_at_45;
    bool ein_uhr;
    bool oclock_word;
} wc_layout_t;

static const wc_layout_t WC_GERMAN = {
    .rows = { "ESKISTAFÜNF", "ZEHNZWANZIG", "DREIVIERTEL", "TGNACHVORJM", "HALBQZWÖLFP",
              "ZWEINSIEBEN", "KDREIRHFÜNF", "ELFNEUNVIER", "WACHTZEHNRS", "BSECHSFMUHR" },
    .es = {0, 0, 2}, .is = {0, 3, 3}, .five = {0, 7, 4}, .ten = {1, 0, 4}, .twenty = {1, 4, 7},
    .quarter = {2, 4, 7}, .three_quarter = {2, 0, 11}, .after = {3, 2, 4}, .before = {3, 6, 3},
    .half = {4, 0, 4}, .oclock = {9, 8, 3}, .ein = {5, 2, 3},
    .hour = { [1] = {5, 2, 4}, [2] = {5, 0, 4}, [3] = {6, 1, 4}, [4] = {7, 7, 4}, [5] = {6, 7, 4}, [6] = {9, 1, 5},
              [7] = {5, 5, 6}, [8] = {8, 1, 4}, [9] = {7, 3, 4}, [10] = {8, 5, 4}, [11] = {7, 0, 3}, [12] = {4, 5, 5} },
    .three_quarter_at_45 = false,
    .ein_uhr = true,
    .oclock_word = true,
};

/* Schwäbisch: "S isch fünf noch halber drei", Dreiviertel statt Viertel vor.
 * Stunden mit -e (oinse, zwoie, dreie, ...), ohne „Uhr“ zur vollen Stunde. */
static const wc_layout_t WC_SWABIAN = {
    .rows = { "ESKISCHFÜNF", "ZEHNZWANZIG", "DREIVIERTEL", "NOCHVORELFE", "HALBERZWOIE",
              "ZWÖLFEFÜNFE", "SECHSEOINSE", "SIEBNEDREIE", "VIEREACHTEN", "NEUNEZEHNER" },
    .es = {0, 0, 2}, .is = {0, 3, 4}, .five = {0, 7, 4}, .ten = {1, 0, 4}, .twenty = {1, 4, 7},
    .quarter = {2, 4, 7}, .three_quarter = {2, 0, 11}, .after = {3, 0, 4}, .before = {3, 4, 3},
    .half = {4, 0, 6},
    .hour = { [1] = {6, 6, 5}, [2] = {4, 6, 5}, [3] = {7, 6, 5}, [4] = {8, 0, 5}, [5] = {5, 6, 5}, [6] = {6, 0, 6},
              [7] = {7, 0, 6}, [8] = {8, 5, 5}, [9] = {9, 0, 5}, [10] = {9, 5, 5}, [11] = {3, 7, 4}, [12] = {5, 0, 6} },
    .three_quarter_at_45 = true,
    .ein_uhr = false,
    .oclock_word = false,
};

enum {
    WC_FIVE = 1, WC_TEN = 2, WC_TWENTY = 4, WC_QUARTER = 8, WC_AFTER = 16, WC_BEFORE = 32, WC_HALF = 64,
};

/* Fünferblock 0 bis 11: ab Block 5 (fünf vor halb) zählt die nächste Stunde. */
static const uint8_t WC_MINUTES[12] = {
    0,
    WC_FIVE | WC_AFTER,
    WC_TEN | WC_AFTER,
    WC_QUARTER | WC_AFTER,
    WC_TWENTY | WC_AFTER,
    WC_FIVE | WC_BEFORE | WC_HALF,
    WC_HALF,
    WC_FIVE | WC_AFTER | WC_HALF,
    WC_TWENTY | WC_BEFORE,
    WC_QUARTER | WC_BEFORE,
    WC_TEN | WC_BEFORE,
    WC_FIVE | WC_BEFORE,
};

static lv_obj_t *word_clock;
static lv_obj_t *wc_cells[WC_ROWS][WC_COLS];
static const wc_layout_t *wc_layout_shown;
static uint16_t wc_lit[WC_ROWS];
static int wc_origin_x;
static int wc_origin_y;
static int wc_cell_w;
static int wc_cell_h;
static int wc_shift_x;
static int wc_shift_y;

/* Sichtbare Zeichenfläche relativ zur Beschriftung, wie LVGL den Buchstaben zeichnet. */
static bool wc_ink(const char *letter, int *x, int *y, int *w, int *h)
{
    const lv_font_t *font = &montserrat_bold_40;
    uint32_t unicode = (uint8_t) letter[0];
    if ((unicode & 0xE0) == 0xC0 && letter[1]) {
        unicode = ((unicode & 0x1F) << 6) | ((uint8_t) letter[1] & 0x3F);
    }
    lv_font_glyph_dsc_t glyph;
    if (!lv_font_get_glyph_dsc(font, &glyph, unicode, 0) || glyph.box_w == 0 || glyph.box_h == 0) return false;
    *x = glyph.ofs_x;
    *y = (font->line_height - font->base_line) - glyph.box_h - glyph.ofs_y;
    *w = glyph.box_w;
    *h = glyph.box_h;
    return true;
}

static void wc_place_letter(int row, int col, const char *letter)
{
    lv_obj_t *cell = wc_cells[row][col];
    lv_label_set_text(cell, letter);
    int ink_x = 0;
    int ink_y = 0;
    int ink_w = 0;
    int ink_h = 0;
    if (!wc_ink(letter, &ink_x, &ink_y, &ink_w, &ink_h)) return;
    int cell_x = wc_origin_x + col * wc_cell_w;
    int cell_y = wc_origin_y + row * wc_cell_h;
    lv_obj_set_pos(cell,
                   cell_x + (wc_cell_w - ink_w) / 2 - ink_x + wc_shift_x,
                   cell_y + (wc_cell_h - ink_h) / 2 - ink_y + wc_shift_y);
}

static void wc_build(void)
{
    if (!clock_layer) return;
    const lv_font_t *font = &montserrat_bold_40;
    wc_cell_w = WC_CELL_W;
    wc_cell_h = WC_CELL_H;
    int used_w = wc_cell_w * WC_COLS;
    int used_h = wc_cell_h * WC_ROWS;
    wc_origin_x = (HOMEDISPLAY_LCD_H_RES - used_w) / 2;
    wc_origin_y = (HOMEDISPLAY_LCD_V_RES - used_h) / 2;
    word_clock = lv_obj_create(clock_layer);
    lv_obj_set_size(word_clock, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES);
    lv_obj_set_pos(word_clock, 0, 0);
    style_flat(word_clock);
    lv_obj_set_style_pad_all(word_clock, 0, 0);
    lv_obj_set_style_radius(word_clock, 0, 0);
    lv_obj_set_style_bg_color(word_clock, lv_color_hex(0x000000), 0);
    lv_obj_set_style_bg_opa(word_clock, LV_OPA_COVER, 0);
    lv_obj_clear_flag(word_clock, LV_OBJ_FLAG_SCROLLABLE);
    for (int row = 0; row < WC_ROWS; row++) {
        for (int col = 0; col < WC_COLS; col++) {
            lv_obj_t *cell = lv_label_create(word_clock);
            lv_obj_set_style_text_font(cell, font, 0);
            lv_obj_set_style_text_color(cell, lv_color_hex(WC_DIM), 0);
            lv_obj_clear_flag(cell, LV_OBJ_FLAG_CLICKABLE);
            wc_cells[row][col] = cell;
        }
    }
    lv_obj_add_event_cb(word_clock, dismiss_idle, LV_EVENT_PRESSED, NULL);
    lv_obj_add_flag(word_clock, LV_OBJ_FLAG_HIDDEN);
}

/* Schreibt die Buchstaben des gewählten Rasters in die Zellen; Ü und Ö sind zwei Byte lang. */
static void wc_set_letters(const wc_layout_t *layout)
{
    char letters[WC_ROWS][WC_COLS][4];
    memset(letters, 0, sizeof(letters));
    wc_shift_x = 0;
    wc_shift_y = 0;
    for (int row = 0; row < WC_ROWS; row++) {
        const char *text = layout->rows[row];
        for (int col = 0; col < WC_COLS; col++) {
            int bytes = ((unsigned char) *text >= 0xC0) ? 2 : 1;
            if (!*text) break;
            memcpy(letters[row][col], text, (size_t) bytes);
            text += bytes;
            wc_place_letter(row, col, letters[row][col]);
            lv_obj_set_style_text_color(wc_cells[row][col], lv_color_hex(WC_DIM), 0);
        }
        wc_lit[row] = 0;
    }
    /* Der sichtbare Block, nicht die Schriftbox, kommt in die Mitte.
     * Positionen stehen erst nach dem Layout in den Objekten, darum hier selbst rechnen. */
    int min_x = HOMEDISPLAY_LCD_H_RES;
    int min_y = HOMEDISPLAY_LCD_V_RES;
    int max_x = 0;
    int max_y = 0;
    for (int row = 0; row < WC_ROWS; row++) {
        for (int col = 0; col < WC_COLS; col++) {
            int ink_x = 0;
            int ink_y = 0;
            int ink_w = 0;
            int ink_h = 0;
            if (!letters[row][col][0] || !wc_ink(letters[row][col], &ink_x, &ink_y, &ink_w, &ink_h)) continue;
            int x = wc_origin_x + col * wc_cell_w + (wc_cell_w - ink_w) / 2;
            int y = wc_origin_y + row * wc_cell_h + (wc_cell_h - ink_h) / 2;
            if (x < min_x) min_x = x;
            if (y < min_y) min_y = y;
            if (x + ink_w > max_x) max_x = x + ink_w;
            if (y + ink_h > max_y) max_y = y + ink_h;
        }
    }
    wc_shift_x = (HOMEDISPLAY_LCD_H_RES - (max_x - min_x)) / 2 - min_x - WC_NUDGE_X;
    wc_shift_y = (HOMEDISPLAY_LCD_V_RES - (max_y - min_y)) / 2 - min_y - WC_NUDGE_Y;
    ESP_LOGI(TAG_UI, "Wortuhr-Block %d..%d / %d..%d, Verschiebung %d,%d", min_x, max_x, min_y, max_y, wc_shift_x, wc_shift_y);
    if (wc_shift_x == 0 && wc_shift_y == 0) return;
    for (int row = 0; row < WC_ROWS; row++) {
        for (int col = 0; col < WC_COLS; col++) {
            if (letters[row][col][0]) wc_place_letter(row, col, letters[row][col]);
        }
    }
}

static void wc_add(uint16_t *mask, wc_word_t word)
{
    for (int index = 0; index < word.len; index++) mask[word.row] |= (uint16_t) (1u << (word.col + index));
}

static void update_word_clock(int hour, int minute, bool swabian)
{
    if (!word_clock) wc_build();
    if (!word_clock) return;
    const wc_layout_t *layout = swabian ? &WC_SWABIAN : &WC_GERMAN;
    if (wc_layout_shown != layout) {
        wc_set_letters(layout);
        wc_layout_shown = layout;
    }
    int block = minute / 5;
    int shown_hour = (hour % 12 + (block >= 5 ? 1 : 0)) % 12;
    if (shown_hour == 0) shown_hour = 12;
    uint8_t flags = WC_MINUTES[block];
    /* Dreiviertel ersetzt "Viertel vor": nur das eine durchgehende Wort leuchtet. */
    bool three_quarter = layout->three_quarter_at_45 && block == 9;
    if (three_quarter) flags = 0;
    uint16_t mask[WC_ROWS] = {0};
    wc_add(mask, layout->es);
    wc_add(mask, layout->is);
    if (flags & WC_FIVE) wc_add(mask, layout->five);
    if (flags & WC_TEN) wc_add(mask, layout->ten);
    if (flags & WC_TWENTY) wc_add(mask, layout->twenty);
    if (flags & WC_QUARTER) wc_add(mask, layout->quarter);
    if (three_quarter) wc_add(mask, layout->three_quarter);
    if (flags & WC_AFTER) wc_add(mask, layout->after);
    if (flags & WC_BEFORE) wc_add(mask, layout->before);
    if (flags & WC_HALF) wc_add(mask, layout->half);
    if (block == 0 && layout->oclock_word) wc_add(mask, layout->oclock);
    wc_add(mask, (block == 0 && shown_hour == 1 && layout->ein_uhr) ? layout->ein : layout->hour[shown_hour]);
    for (int row = 0; row < WC_ROWS; row++) {
        uint16_t changed = (uint16_t) ((mask[row] ^ wc_lit[row]) & 0x7FF);
        if (!changed) continue;
        for (int col = 0; col < WC_COLS; col++) {
            if (!(changed & (1u << col))) continue;
            lv_obj_set_style_text_color(wc_cells[row][col], lv_color_hex((mask[row] >> col) & 1 ? WC_LIT : WC_DIM), 0);
        }
        wc_lit[row] = mask[row];
    }
}

static void reveal_idle(void)
{
    if (boot_screen_active || locked || !layout_root) return;
    /* Bildschirm aus nur, wenn das so eingestellt ist. Ein Tipp auf die Uhrzeit zeigt sonst immer die Uhr,
     * auch bei „Letzte Seite“, denn dort gäbe es sonst keine sichtbare Ruheanzeige. */
    if (idle_mode == 2) {
        apply_backlight();
        return;
    }
    int64_t now = clock_base + (esp_timer_get_time() - clock_set_at) / 1000000 + DISPLAY_TZ_OFFSET_S;
    if (now < 0) now = 0;
    int seconds = (int) (now % 86400);
    int hour = seconds / 3600;
    int minute = (seconds / 60) % 60;
    if (!idle_active) set_idle_visible(true);
    bool analog = strcmp(idle_clock_style, "analog") == 0;
    bool swabian = strcmp(idle_clock_style, "swabian") == 0;
    bool word = swabian || strcmp(idle_clock_style, "word") == 0;
    if (analog_clock) {
        if (analog) lv_obj_clear_flag(analog_clock, LV_OBJ_FLAG_HIDDEN);
        else lv_obj_add_flag(analog_clock, LV_OBJ_FLAG_HIDDEN);
    }
    if (word) {
        update_word_clock(hour, minute, swabian);
        if (word_clock) lv_obj_clear_flag(word_clock, LV_OBJ_FLAG_HIDDEN);
    } else if (word_clock) {
        lv_obj_add_flag(word_clock, LV_OBJ_FLAG_HIDDEN);
    }
    if (clock_label) {
        if (analog || word) lv_obj_add_flag(clock_label, LV_OBJ_FLAG_HIDDEN);
        else {
            lv_obj_clear_flag(clock_label, LV_OBJ_FLAG_HIDDEN);
            char line[16];
            snprintf(line, sizeof(line), "%02d:%02d", hour, minute);
            lv_label_set_text(clock_label, line);
        }
    }
    if (analog) update_analog_hands(hour, minute);
}

static void clock_tick(lv_timer_t *timer)
{
    (void) timer;
    frame_counter++;
    int64_t now = clock_base + (esp_timer_get_time() - clock_set_at) / 1000000 + DISPLAY_TZ_OFFSET_S;
    if (now < 0) now = 0;
    int seconds = (int) (now % 86400);
    int hour = seconds / 3600;
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
    bool timed_out = esp_timer_get_time() - touched_at > idle_timeout_us;
    /* Nach der Wartezeit dieselbe Uhr wie beim Tipp auf die Uhrzeit. „Bildschirm aus“ bleibt dunkel. */
    if (timed_out && idle_mode != 2) force_idle = true;
    bool idle = force_idle || timed_out;
    if (idle && (idle_mode == 0 || idle_mode == 2 || force_idle)) {
        if (timed_out && !idle_active && idle_mode != 2) {
            ESP_LOGI(TAG_UI, "Ruheanzeige nach Zeit, Modus %d Stil %s", idle_mode, idle_clock_style);
        }
        reveal_idle();
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
    lv_obj_clear_flag(info_label, LV_OBJ_FLAG_CLICKABLE);
    /* Fläche über der ganzen rechten Kopfzeile, die Schrift allein ist zu schmal. */
    clock_hit = lv_obj_create(screen);
    lv_obj_set_pos(clock_hit, HOMEDISPLAY_LCD_H_RES - HEADER_RIGHT, 0);
    lv_obj_set_size(clock_hit, HEADER_RIGHT, HEADER_H);
    style_flat(clock_hit);
    lv_obj_set_style_bg_opa(clock_hit, LV_OPA_TRANSP, 0);
    lv_obj_set_style_radius(clock_hit, 0, 0);
    lv_obj_add_event_cb(clock_hit, show_idle_now, LV_EVENT_PRESSED, NULL);
    home_button = lv_btn_create(screen);
    lv_obj_set_size(home_button, TAB_H, TAB_H);
    lv_obj_set_pos(home_button, 8, (HEADER_H - TAB_H) / 2);
    lv_obj_set_style_bg_color(home_button, lv_color_hex(0x2A4F7C), 0);
    lv_obj_set_style_radius(home_button, 6, 0);
    style_flat(home_button);
    lv_obj_t *home_symbol = lv_label_create(home_button);
    paint_icon_label(home_symbol, "home", false, false, 0xF4F7FB);
    lv_obj_center(home_symbol);
    lv_obj_add_event_cb(home_button, home_event, LV_EVENT_CLICKED, NULL);
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
    lv_obj_set_style_pad_all(analog_clock, 0, 0);
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
        int length = major ? 14 : 8;
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
        int text_radius = 136 - (int) (fabs(cosine) * text_size.x / 2.0 + fabs(sine) * text_size.y / 2.0);
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
    pin_popup = lv_obj_create(screen);
    lv_obj_set_size(pin_popup, HOMEDISPLAY_LCD_H_RES, HOMEDISPLAY_LCD_V_RES);
    lv_obj_set_style_bg_color(pin_popup, lv_color_hex(0x000000), 0);
    lv_obj_set_style_bg_opa(pin_popup, LV_OPA_80, 0);
    style_flat(pin_popup);
    lv_obj_t *pin_card = lv_obj_create(pin_popup);
    lv_obj_set_size(pin_card, 480, 560);
    lv_obj_center(pin_card);
    lv_obj_set_style_bg_color(pin_card, lv_color_hex(0x1A1A1A), 0);
    lv_obj_set_style_radius(pin_card, 12, 0);
    style_flat(pin_card);
    pin_title_label = lv_label_create(pin_card);
    lv_label_set_text(pin_title_label, "PIN");
    lv_obj_set_style_text_color(pin_title_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(pin_title_label, &lv_font_montserrat_28, 0);
    lv_obj_align(pin_title_label, LV_ALIGN_TOP_MID, 0, 16);
    pin_dots_label = lv_label_create(pin_card);
    lv_label_set_text(pin_dots_label, "-");
    lv_obj_set_style_text_color(pin_dots_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_text_font(pin_dots_label, &lv_font_montserrat_48, 0);
    lv_obj_align(pin_dots_label, LV_ALIGN_TOP_MID, 0, 56);
    pin_status_label = lv_label_create(pin_card);
    lv_label_set_text(pin_status_label, "");
    lv_obj_set_style_text_color(pin_status_label, lv_color_hex(0xFFB547), 0);
    lv_obj_set_style_text_font(pin_status_label, &lv_font_montserrat_28, 0);
    lv_obj_align(pin_status_label, LV_ALIGN_TOP_MID, 0, 120);
    static const char *pin_map[] = {
        "1", "2", "3", "\n",
        "4", "5", "6", "\n",
        "7", "8", "9", "\n",
        "C", "0", ""
    };
    lv_obj_t *pin_matrix = lv_btnmatrix_create(pin_card);
    lv_btnmatrix_set_map(pin_matrix, pin_map);
    lv_obj_set_size(pin_matrix, 420, 300);
    lv_obj_align(pin_matrix, LV_ALIGN_TOP_MID, 0, 160);
    lv_obj_set_style_text_font(pin_matrix, &lv_font_montserrat_28, LV_PART_ITEMS);
    lv_btnmatrix_set_btn_ctrl_all(pin_matrix, LV_BTNMATRIX_CTRL_CLICK_TRIG | LV_BTNMATRIX_CTRL_NO_REPEAT);
    lv_obj_add_event_cb(pin_matrix, pin_matrix_event, LV_EVENT_VALUE_CHANGED, NULL);
    lv_obj_t *pin_cancel = lv_btn_create(pin_card);
    lv_obj_set_size(pin_cancel, 220, 56);
    lv_obj_align(pin_cancel, LV_ALIGN_BOTTOM_MID, 0, -16);
    lv_obj_t *pin_cancel_lbl = lv_label_create(pin_cancel);
    lv_label_set_text(pin_cancel_lbl, "Abbrechen");
    lv_obj_set_style_text_font(pin_cancel_lbl, &lv_font_montserrat_28, 0);
    lv_obj_center(pin_cancel_lbl);
    lv_obj_add_event_cb(pin_cancel, pin_cancel_event, LV_EVENT_CLICKED, NULL);
    lv_obj_add_flag(pin_popup, LV_OBJ_FLAG_HIDDEN);
    build_test_layer(screen);
    build_boot_layer(screen);
    set_boot_message("Hausanzeige startet", "Anzeige bereit");
    lvgl_port_unlock();
    touched_at = esp_timer_get_time();
    offline_since = touched_at;
    lv_timer_create(clock_tick, 1000, NULL);
    lv_timer_create(pin_tick, 500, NULL);
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
    layout_pin1[0] = '\0';
    layout_pin2[0] = '\0';
    const char *pin_texts[2] = {
        cJSON_GetStringValue(cJSON_GetObjectItem(root, "pin1")),
        cJSON_GetStringValue(cJSON_GetObjectItem(root, "pin2")),
    };
    char *pin_dst[2] = { layout_pin1, layout_pin2 };
    for (int pin_index = 0; pin_index < 2; pin_index++) {
        const char *text = pin_texts[pin_index];
        if (!text) continue;
        size_t len = strlen(text);
        bool digits = len >= 4 && len <= 8;
        for (size_t i = 0; digits && i < len; i++) {
            if (text[i] < '0' || text[i] > '9') digits = false;
        }
        if (digits) snprintf(pin_dst[pin_index], 9, "%s", text);
    }
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
    ensure_persisted_page();
    show_page(page_by_id(persisted_page_id));
    /* show_page() hält beim Rückkehren immer die Sperre – die Yield-Stelle wartet darauf,
     * statt sie ungeschützt aufzugeben. */
    hide_boot_screen();
    lvgl_port_unlock();
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
    item = cJSON_GetObjectItem(root, "nightDim");
    if (cJSON_IsBool(item)) night_dim = cJSON_IsTrue(item);
    cJSON_Delete(root);
    apply_backlight();
}

void homedisplay_ui_apply_state(const char *json)
{
    cJSON *root = cJSON_Parse(json);
    if (!root) return;
    if (cJSON_IsTrue(cJSON_GetObjectItem(root, "unchanged"))) {
        cJSON_Delete(root);
        return;
    }
    char *copy = NULL;
    if (json && json[0]) {
        size_t length = strlen(json) + 1;
        copy = heap_caps_malloc(length, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
        if (copy) memcpy(copy, json, length);
    }
    cJSON *now = cJSON_GetObjectItem(root, "now");
    if (now) homedisplay_ui_set_time((int64_t) now->valuedouble);
    if (!ui_client_lock()) {
        if (copy) heap_caps_free(copy);
        cJSON_Delete(root);
        return;
    }
    char *previous = NULL;
    if (copy) {
        previous = last_state_json;
        last_state_json = copy;
    }
    apply_state_body(root);
    lvgl_port_unlock();
    if (previous) heap_caps_free(previous);
    cJSON_Delete(root);
}

static void chart_stroke(lv_draw_ctx_t *ctx, const lv_point_t *points, int count, int ox, int oy, lv_color_t color, lv_opa_t opa, lv_coord_t width)
{
    if (!points || count < 2) return;
    lv_draw_line_dsc_t dsc;
    lv_draw_line_dsc_init(&dsc);
    dsc.color = color;
    dsc.opa = opa;
    dsc.width = width;
    dsc.round_start = 1;
    dsc.round_end = 1;
    for (int index = 1; index < count; index++) {
        lv_point_t from = { (lv_coord_t) (ox + points[index - 1].x), (lv_coord_t) (oy + points[index - 1].y) };
        lv_point_t to = { (lv_coord_t) (ox + points[index].x), (lv_coord_t) (oy + points[index].y) };
        lv_draw_line(ctx, &dsc, &from, &to);
    }
}

static void chart_fill_under(lv_draw_ctx_t *ctx, const lv_point_t *points, int count, int ox, int oy, int bottom, lv_color_t color)
{
    lv_draw_rect_dsc_t rect;
    lv_draw_rect_dsc_init(&rect);
    rect.bg_color = color;
    rect.bg_opa = LV_OPA_40;
    for (int index = 1; index < count; index++) {
        lv_coord_t x1 = (lv_coord_t) (ox + points[index - 1].x);
        lv_coord_t y1 = (lv_coord_t) (oy + points[index - 1].y);
        lv_coord_t x2 = (lv_coord_t) (ox + points[index].x);
        lv_coord_t y2 = (lv_coord_t) (oy + points[index].y);
        if (x2 <= x1) continue;
        lv_draw_mask_line_param_t mask;
        lv_draw_mask_line_points_init(&mask, x1, y1, x2, y2, LV_DRAW_MASK_LINE_SIDE_BOTTOM);
        int16_t mask_id = lv_draw_mask_add(&mask, NULL);
        if (mask_id >= 0) {
            lv_area_t area;
            area.x1 = x1;
            area.x2 = x2 - 1;
            area.y1 = LV_MIN(y1, y2);
            area.y2 = (lv_coord_t) bottom;
            lv_draw_rect(ctx, &rect, &area);
            lv_draw_mask_remove_id(mask_id);
        }
        lv_draw_mask_free_param(&mask);
    }
}

static void chart_draw_event(lv_event_t *event)
{
    tile_view_t *view = lv_event_get_user_data(event);
    if (!view || view->chart_count < 2 || !view->chart_points) return;
    lv_obj_t *obj = lv_event_get_target(event);
    lv_draw_ctx_t *ctx = lv_event_get_draw_ctx(event);
    lv_area_t coords;
    lv_obj_get_coords(obj, &coords);
    int ox = coords.x1 + view->chart_pad_l;
    int oy = coords.y1;
    int plot_w = view->chart_plot_w;
    int plot_h = view->chart_plot_h;
    if (view->chart_grid && plot_w > 2 && plot_h > 2) {
        lv_draw_line_dsc_t grid;
        lv_draw_line_dsc_init(&grid);
        grid.color = lv_color_hex(text_fg());
        grid.opa = LV_OPA_30;
        grid.width = 1;
        for (int step = 0; step < 3; step++) {
            int y = oy + (plot_h - 1) * step / 2;
            int x = ox + (plot_w - 1) * step / 2;
            lv_point_t left = { (lv_coord_t) ox, (lv_coord_t) y };
            lv_point_t right = { (lv_coord_t) (ox + plot_w - 1), (lv_coord_t) y };
            lv_point_t top = { (lv_coord_t) x, (lv_coord_t) oy };
            lv_point_t bottom = { (lv_coord_t) x, (lv_coord_t) (oy + plot_h - 1) };
            lv_draw_line(ctx, &grid, &left, &right);
            lv_draw_line(ctx, &grid, &top, &bottom);
        }
    }
    lv_color_t ink = lv_color_hex(view->chart_color ? view->chart_color : theme_accent);
    if (view->chart_yest && view->chart_yest_count >= 2) {
        chart_stroke(ctx, view->chart_yest, view->chart_yest_count, ox, oy, lv_color_hex(0x9FB2C8), LV_OPA_70, 2);
    }
    if (view->chart_area) chart_fill_under(ctx, view->chart_points, view->chart_count, ox, oy, oy + plot_h - 1, ink);
    chart_stroke(ctx, view->chart_points, view->chart_count, ox, oy, ink, LV_OPA_COVER, 3);
}

static int64_t chart_period_span(const char *period)
{
    if (period && strcmp(period, "1h") == 0) return 3600;
    if (period && strcmp(period, "6h") == 0) return 6 * 3600;
    if (period && strcmp(period, "7d") == 0) return 7 * 86400;
    return 86400;
}

static void chart_format_stamp(char *out, size_t length, int64_t unix_time, const char *period, bool relative, int64_t span, int slot)
{
    if (relative) {
        if (slot == 2) {
            snprintf(out, length, "jetzt");
            return;
        }
        int64_t back = slot == 0 ? span : span / 2;
        if (back >= 86400 && back % 86400 == 0) snprintf(out, length, "-%dd", (int) (back / 86400));
        else if (back >= 3600 && back % 3600 == 0) snprintf(out, length, "-%dh", (int) (back / 3600));
        else snprintf(out, length, "-%dm", (int) ((back + 30) / 60));
        return;
    }
    time_t stamp = (time_t) (unix_time + DISPLAY_TZ_OFFSET_S);
    struct tm broken;
    gmtime_r(&stamp, &broken);
    if (period && strcmp(period, "7d") == 0) {
        static const char *weekday[] = { "So", "Mo", "Di", "Mi", "Do", "Fr", "Sa" };
        int day = broken.tm_wday;
        if (day < 0 || day > 6) day = 0;
        snprintf(out, length, "%s %d.%d.", weekday[day], broken.tm_mday, broken.tm_mon + 1);
        return;
    }
    snprintf(out, length, "%02d:%02d", broken.tm_hour, broken.tm_min);
}

static void chart_format_number(char *out, size_t length, double value, int decimals, const char *unit)
{
    char raw[24];
    if (decimals < 0) decimals = 0;
    if (decimals > 3) decimals = 3;
    snprintf(raw, sizeof(raw), "%.*f", decimals, value);
    for (char *cursor = raw; *cursor; cursor++) {
        if (*cursor == '.') *cursor = ',';
    }
    if (unit && unit[0]) snprintf(out, length, "%s %s", raw, unit);
    else snprintf(out, length, "%s", raw);
}

static int chart_sample(cJSON *series, double *values, int cap, double *out_min, double *out_max, bool extend)
{
    int total = cJSON_IsArray(series) ? cJSON_GetArraySize(series) : 0;
    if (total < 2 || cap < 2) return 0;
    int count = total > cap ? cap : total;
    for (int index = 0; index < count; index++) {
        int source = count == total ? index : (int) ((long) index * (total - 1) / (count - 1));
        cJSON *point = cJSON_GetArrayItem(series, source);
        double value = cJSON_IsNumber(point) ? point->valuedouble : 0.0;
        values[index] = value;
        if (!extend && index == 0) {
            if (out_min) *out_min = value;
            if (out_max) *out_max = value;
        } else {
            if (out_min && value < *out_min) *out_min = value;
            if (out_max && value > *out_max) *out_max = value;
        }
    }
    return count;
}

static void chart_place_points(lv_point_t *points, const double *values, int count, int plot_w, int plot_h, double y_min, double span)
{
    if (!points || count < 2 || plot_w < 2 || plot_h < 2) return;
    for (int index = 0; index < count; index++) {
        double norm = (values[index] - y_min) / span;
        if (norm < 0.0) norm = 0.0;
        if (norm > 1.0) norm = 1.0;
        points[index].x = (lv_coord_t) ((plot_w - 1) * index / (count - 1));
        points[index].y = (lv_coord_t) (plot_h - 1 - (int) (norm * (plot_h - 1)));
    }
}

static void chart_hide_axes(tile_view_t *view)
{
    for (int index = 0; index < 3; index++) {
        if (view->chart_y_label[index]) lv_obj_add_flag(view->chart_y_label[index], LV_OBJ_FLAG_HIDDEN);
        if (view->chart_x_label[index]) lv_obj_add_flag(view->chart_x_label[index], LV_OBJ_FLAG_HIDDEN);
    }
}

static void paint_chart(tile_view_t *view, cJSON *tile)
{
    if (!view || !view->chart_line || !view->chart_points) return;
    cJSON *series = cJSON_GetObjectItem(tile, "series");
    int total = cJSON_IsArray(series) ? cJSON_GetArraySize(series) : 0;
    if (total != view->chart_logged) {
        const char *note = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "chartNote"));
        ESP_LOGI(TAG_UI, "Diagramm %s: %d Punkte%s%s", view->tile_id, total, note ? ", Grund " : "", note ? note : "");
        view->chart_logged = total;
    }
    if (total < 2) {
        view->chart_count = 0;
        lv_obj_add_flag(view->chart_line, LV_OBJ_FLAG_HIDDEN);
        chart_hide_axes(view);
        if (view->chart_note) {
            const char *note = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "chartNote"));
            const char *text = "Keine Verlaufsdaten";
            if (note && strcmp(note, "notLogged") == 0) text = "Variable wird nicht geloggt";
            else if (note && strcmp(note, "noArchive") == 0) text = "Kein Archiv gefunden";
            lv_label_set_text(view->chart_note, text);
            lv_obj_clear_flag(view->chart_note, LV_OBJ_FLAG_HIDDEN);
        }
        return;
    }
    if (view->chart_note) lv_obj_add_flag(view->chart_note, LV_OBJ_FLAG_HIDDEN);
    double values[CHART_POINT_MAX];
    double data_min = 0.0;
    double data_max = 0.0;
    int count = chart_sample(series, values, CHART_POINT_MAX, &data_min, &data_max, false);
    double yest_values[CHART_POINT_MAX];
    cJSON *yesterday = cJSON_GetObjectItem(tile, "seriesYesterday");
    int yest_count = chart_sample(yesterday, yest_values, CHART_POINT_MAX, &data_min, &data_max, true);
    double y_min = data_min;
    double y_max = data_max;
    cJSON *min_item = cJSON_GetObjectItem(tile, "seriesMin");
    cJSON *max_item = cJSON_GetObjectItem(tile, "seriesMax");
    if (cJSON_IsNumber(min_item) && cJSON_IsNumber(max_item) && max_item->valuedouble > min_item->valuedouble) {
        y_min = min_item->valuedouble;
        y_max = max_item->valuedouble;
    }
    double span = y_max - y_min;
    if (span < 0.000001) {
        y_min -= 1.0;
        y_max += 1.0;
    } else if ((data_max - data_min) > 0.000001 && (data_max - data_min) < span * 0.2) {
        y_min = data_min;
        y_max = data_max;
    }
    span = y_max - y_min;
    if (span < 0.000001) span = 1.0;
    int pad_l = view->chart_axes ? 64 : 0;
    int pad_b = view->chart_axes ? 20 : 0;
    if (pad_l > view->chart_w - 8) pad_l = view->chart_w / 3;
    if (pad_b > view->chart_h - 8) pad_b = view->chart_h / 4;
    int plot_w = view->chart_w - pad_l;
    int plot_h = view->chart_h - pad_b;
    if (plot_w < 2) plot_w = 2;
    if (plot_h < 2) plot_h = 2;
    view->chart_pad_l = pad_l;
    view->chart_pad_b = pad_b;
    view->chart_plot_w = plot_w;
    view->chart_plot_h = plot_h;
    chart_place_points(view->chart_points, values, count, plot_w, plot_h, y_min, span);
    view->chart_count = count;
    if (view->chart_yest && yest_count >= 2) {
        chart_place_points(view->chart_yest, yest_values, yest_count, plot_w, plot_h, y_min, span);
        view->chart_yest_count = yest_count;
    } else {
        view->chart_yest_count = 0;
    }
    lv_obj_set_size(view->chart_line, view->chart_w, view->chart_h);
    lv_obj_clear_flag(view->chart_line, LV_OBJ_FLAG_HIDDEN);
    if (view->chart_axes) {
        char text[40];
        double marks[3] = { y_max, (y_min + y_max) / 2.0, y_min };
        for (int index = 0; index < 3; index++) {
            chart_format_number(text, sizeof(text), marks[index], view->chart_decimals, index == 0 ? view->chart_unit : "");
            lv_label_set_text(view->chart_y_label[index], text);
            lv_obj_set_width(view->chart_y_label[index], pad_l > 4 ? pad_l - 4 : pad_l);
            lv_obj_set_style_text_align(view->chart_y_label[index], LV_TEXT_ALIGN_RIGHT, 0);
            int y = (plot_h - 1) * index / 2 - 8;
            if (y < 0) y = 0;
            lv_obj_align(view->chart_y_label[index], LV_ALIGN_TOP_LEFT, 0, y);
            lv_obj_clear_flag(view->chart_y_label[index], LV_OBJ_FLAG_HIDDEN);
        }
        int64_t now = clock_base + (esp_timer_get_time() - clock_set_at) / 1000000;
        int64_t span_s = chart_period_span(view->chart_period);
        bool relative = now < 1600000000LL;
        int64_t stamps[3] = { now - span_s, now - span_s / 2, now };
        for (int index = 0; index < 3; index++) {
            chart_format_stamp(text, sizeof(text), stamps[index], view->chart_period, relative, span_s, index);
            lv_label_set_text(view->chart_x_label[index], text);
            lv_obj_clear_flag(view->chart_x_label[index], LV_OBJ_FLAG_HIDDEN);
        }
        lv_obj_align(view->chart_x_label[0], LV_ALIGN_BOTTOM_LEFT, pad_l, 0);
        lv_obj_align(view->chart_x_label[1], LV_ALIGN_BOTTOM_MID, pad_l / 2, 0);
        lv_obj_align(view->chart_x_label[2], LV_ALIGN_BOTTOM_RIGHT, 0, 0);
    } else {
        chart_hide_axes(view);
    }
    lv_obj_invalidate(view->chart_line);
}

static uint32_t schedule_stamp_of(cJSON *schedule)
{
    uint32_t stamp = 2166136261u;
    if (!cJSON_IsObject(schedule)) return 1;
    stamp = stamp * 16777619u + (uint32_t) json_int(schedule, "now", -1);
    stamp = stamp * 16777619u + (uint32_t) json_int(schedule, "today", 0);
    cJSON *group = NULL;
    cJSON_ArrayForEach(group, cJSON_GetObjectItem(schedule, "groups")) {
        stamp = stamp * 16777619u + (uint32_t) json_int(group, "days", 0);
        cJSON *segment = NULL;
        cJSON_ArrayForEach(segment, cJSON_GetObjectItem(group, "segs")) {
            for (int part = 0; part < 3; part++) {
                cJSON *item = cJSON_GetArrayItem(segment, part);
                stamp = stamp * 16777619u + (cJSON_IsNumber(item) ? (uint32_t) item->valueint : 0);
            }
        }
    }
    return stamp ? stamp : 1;
}

static lv_obj_t *schedule_label(lv_obj_t *parent, const char *text, lv_coord_t width)
{
    lv_obj_t *label = lv_label_create(parent);
    lv_label_set_text(label, text && text[0] ? text : "");
    lv_obj_set_style_text_font(label, &lv_font_montserrat_16, 0);
    lv_obj_set_style_text_color(label, lv_color_hex(0xE7EEF6), 0);
    lv_obj_clear_flag(label, LV_OBJ_FLAG_CLICKABLE);
    if (width > 0) {
        lv_obj_set_width(label, width);
        lv_label_set_long_mode(label, LV_LABEL_LONG_DOT);
    }
    return label;
}

/* Balken je Tagesgruppe. Farben und Namen stammen aus den Wochenplan-Aktionen. */
static void paint_schedule(tile_view_t *view, cJSON *schedule)
{
    if (!view || !view->value_box) return;
    uint32_t stamp = schedule_stamp_of(schedule);
    if (stamp == view->schedule_stamp && lv_obj_get_child_cnt(view->value_box) > 0) return;
    view->schedule_stamp = stamp;
    lv_obj_update_layout(view->card);
    lv_obj_clean(view->value_box);
    cJSON *groups = cJSON_IsObject(schedule) ? cJSON_GetObjectItem(schedule, "groups") : NULL;
    int group_count = cJSON_IsArray(groups) ? cJSON_GetArraySize(groups) : 0;
    if (group_count < 1) {
        lv_obj_t *empty = schedule_label(view->value_box, "Kein Wochenplan", 0);
        lv_obj_center(empty);
        return;
    }
    if (group_count > 7) group_count = 7;
    int box_w = lv_obj_get_width(view->value_box);
    int box_h = lv_obj_get_height(view->value_box);
    if (box_w < 40) box_w = 220;
    if (box_h < 36) box_h = 80;
    int now = json_int(schedule, "now", -1);
    int today = json_int(schedule, "today", 0);
    cJSON *legend = cJSON_GetObjectItem(schedule, "legend");
    int legend_count = cJSON_IsArray(legend) ? cJSON_GetArraySize(legend) : 0;
    if (legend_count > 4) legend_count = 4;
    int legend_h = (legend_count > 0 && box_h >= 88) ? 22 : 0;
    int tick_h = box_h >= 72 ? 16 : 0;
    int body_h = box_h - legend_h - tick_h;
    if (body_h < group_count * 16) {
        tick_h = 0;
        legend_h = 0;
        body_h = box_h;
    }
    int row_h = body_h / group_count;
    if (row_h < 14) row_h = 14;
    int label_w = box_w > 300 ? 138 : (box_w > 180 ? 96 : 0);
    int bar_x = label_w > 0 ? label_w + 8 : 0;
    int bar_w = box_w - bar_x;
    if (bar_w < 24) bar_w = 24;
    int bar_h = row_h > 34 ? 16 : (row_h > 22 ? 12 : 8);
    if (tick_h > 0) {
        const int hours[] = {0, 6, 12, 18, 24};
        for (int mark = 0; mark < 5; mark++) {
            char caption[4];
            snprintf(caption, sizeof(caption), "%d", hours[mark]);
            lv_obj_t *tick = schedule_label(view->value_box, caption, 28);
            lv_obj_set_style_text_color(tick, lv_color_hex(0xC5D0DC), 0);
            int x = bar_x + (hours[mark] * bar_w) / 24 - 8;
            if (x < bar_x) x = bar_x;
            if (x > bar_x + bar_w - 20) x = bar_x + bar_w - 20;
            lv_obj_set_pos(tick, x, 0);
        }
    }
    bool marked = false;
    for (int index = 0; index < group_count; index++) {
        cJSON *group = cJSON_GetArrayItem(groups, index);
        if (!cJSON_IsObject(group)) continue;
        int y = tick_h + index * row_h;
        int bar_y = y + (row_h - bar_h) / 2;
        if (label_w > 0) {
            const char *caption = cJSON_GetStringValue(cJSON_GetObjectItem(group, "label"));
            lv_obj_t *caption_label = schedule_label(view->value_box, caption ? caption : "", label_w);
            lv_obj_set_pos(caption_label, 0, bar_y - 2);
        }
        cJSON *segments = cJSON_GetObjectItem(group, "segs");
        int segment_count = cJSON_IsArray(segments) ? cJSON_GetArraySize(segments) : 0;
        if (segment_count > 32) segment_count = 32;
        for (int segment_index = 0; segment_index < segment_count; segment_index++) {
            cJSON *segment = cJSON_GetArrayItem(segments, segment_index);
            cJSON *start_item = cJSON_GetArrayItem(segment, 0);
            cJSON *end_item = cJSON_GetArrayItem(segment, 1);
            cJSON *color_item = cJSON_GetArrayItem(segment, 2);
            if (!cJSON_IsNumber(start_item) || !cJSON_IsNumber(end_item)) continue;
            int start = start_item->valueint;
            int end = end_item->valueint;
            if (start < 0) start = 0;
            if (end > 1440) end = 1440;
            if (end <= start) continue;
            int x0 = bar_x + (start * bar_w) / 1440;
            int x1 = bar_x + (end * bar_w) / 1440;
            if (x1 <= x0) x1 = x0 + 1;
            lv_obj_t *piece = lv_obj_create(view->value_box);
            lv_obj_set_pos(piece, x0, bar_y);
            lv_obj_set_size(piece, x1 - x0, bar_h);
            lv_obj_set_style_radius(piece, 0, 0);
            lv_obj_set_style_border_width(piece, 0, 0);
            lv_obj_set_style_pad_all(piece, 0, 0);
            lv_obj_set_style_bg_opa(piece, LV_OPA_COVER, 0);
            uint32_t color = cJSON_IsNumber(color_item) ? ((uint32_t) color_item->valueint & 0xFFFFFFu) : 0x3A4556u;
            lv_obj_set_style_bg_color(piece, lv_color_hex(color), 0);
            lv_obj_clear_flag(piece, LV_OBJ_FLAG_SCROLLABLE | LV_OBJ_FLAG_CLICKABLE);
        }
        int days = json_int(group, "days", 0);
        if (!marked && now >= 0 && today > 0 && (days & today) != 0) {
            int marker_x = bar_x + (now * bar_w) / 1440;
            if (marker_x > bar_x + bar_w - 2) marker_x = bar_x + bar_w - 2;
            lv_obj_t *marker = lv_obj_create(view->value_box);
            lv_obj_set_pos(marker, marker_x, bar_y - 2);
            lv_obj_set_size(marker, 2, bar_h + 4);
            lv_obj_set_style_radius(marker, 0, 0);
            lv_obj_set_style_border_width(marker, 0, 0);
            lv_obj_set_style_pad_all(marker, 0, 0);
            lv_obj_set_style_bg_opa(marker, LV_OPA_COVER, 0);
            lv_obj_set_style_bg_color(marker, lv_color_hex(0xF4F7FB), 0);
            lv_obj_clear_flag(marker, LV_OBJ_FLAG_SCROLLABLE | LV_OBJ_FLAG_CLICKABLE);
            if (tick_h > 0) {
                lv_obj_t *now_label = schedule_label(view->value_box, "Jetzt", 46);
                int label_x = marker_x - 18;
                if (label_x < bar_x) label_x = bar_x;
                if (label_x > bar_x + bar_w - 46) label_x = bar_x + bar_w - 46;
                lv_obj_set_pos(now_label, label_x, 0);
            }
            marked = true;
        }
    }
    if (legend_h > 0) {
        int legend_y = box_h - legend_h;
        int cursor = 0;
        int slot = box_w / legend_count;
        if (slot < 70) slot = 70;
        for (int index = 0; index < legend_count && cursor < box_w - 20; index++) {
            cJSON *item = cJSON_GetArrayItem(legend, index);
            int color = json_int(item, "color", 0x3A4556);
            const char *name = cJSON_GetStringValue(cJSON_GetObjectItem(item, "name"));
            lv_obj_t *swatch = lv_obj_create(view->value_box);
            lv_obj_set_pos(swatch, cursor, legend_y + 4);
            lv_obj_set_size(swatch, 10, 10);
            lv_obj_set_style_radius(swatch, 1, 0);
            lv_obj_set_style_border_width(swatch, 0, 0);
            lv_obj_set_style_pad_all(swatch, 0, 0);
            lv_obj_set_style_bg_opa(swatch, LV_OPA_COVER, 0);
            lv_obj_set_style_bg_color(swatch, lv_color_hex((uint32_t) color & 0xFFFFFFu), 0);
            lv_obj_clear_flag(swatch, LV_OBJ_FLAG_SCROLLABLE | LV_OBJ_FLAG_CLICKABLE);
            lv_obj_t *name_label = schedule_label(view->value_box, name ? name : "", slot - 16);
            lv_obj_set_pos(name_label, cursor + 14, legend_y - 1);
            cursor += slot;
        }
    }
}

static const uint32_t FLOW_INK[FLOW_LINES] = {0xF0A030, 0xA38BE0, 0xE0709A, 0x5B8FC7, 0x5B8FC7, 0x6CC3B5};
#define FLOW_RING_PV 0xF0A030
#define FLOW_RING_GRID 0x5B8FC7
#define FLOW_RING_BAT 0xE0709A
#define FLOW_RING_HOME 0x46566B
#define FLOW_INK_EXPORT 0xA38BE0
#define FLOW_INK_IMPORT 0x8DB8EA
#define FLOW_INK_CHARGE 0xE88AAE
#define FLOW_INK_DISCHARGE 0x6CC3B5
#define FLOW_DOT 8

static void flow_push(flow_slot_t *slot, int line, int x, int y)
{
    if (slot->npts[line] >= FLOW_PTS) return;
    slot->pts[line][slot->npts[line]].x = (lv_coord_t) x;
    slot->pts[line][slot->npts[line]].y = (lv_coord_t) y;
    slot->npts[line]++;
}

/* Viertelbogen um (cx, cy); der Anfangspunkt steht schon in der Linie. */
static void flow_arc(flow_slot_t *slot, int line, int cx, int cy, int rad, int a0, int a1)
{
    for (int step = 1; step <= 4; step++) {
        float angle = (float) (a0 + (a1 - a0) * step / 4.0f) * 3.14159265f / 180.0f;
        flow_push(slot, line, cx + (int) lroundf(rad * cosf(angle)), cy + (int) lroundf(rad * sinf(angle)));
    }
}

static lv_obj_t *flow_circle(lv_obj_t *parent, int cx, int cy, int r, uint32_t ring, uint32_t fill)
{
    lv_obj_t *circle = lv_obj_create(parent);
    style_flat(circle);
    lv_obj_set_size(circle, 2 * r, 2 * r);
    lv_obj_set_pos(circle, cx - r, cy - r);
    lv_obj_set_style_radius(circle, LV_RADIUS_CIRCLE, 0);
    lv_obj_set_style_bg_color(circle, lv_color_hex(fill), 0);
    lv_obj_set_style_bg_opa(circle, LV_OPA_COVER, 0);
    lv_obj_set_style_border_width(circle, 3, 0);
    lv_obj_set_style_border_color(circle, lv_color_hex(color_mono ? 0xFFFFFF : ring), 0);
    lv_obj_set_style_border_opa(circle, LV_OPA_COVER, 0);
    lv_obj_set_style_pad_all(circle, 0, 0);
    lv_obj_set_style_pad_row(circle, 0, 0);
    lv_obj_set_flex_flow(circle, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_flex_align(circle, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_clear_flag(circle, LV_OBJ_FLAG_CLICKABLE);
    return circle;
}

static lv_obj_t *flow_text(lv_obj_t *parent, const lv_font_t *font, uint32_t ink, const char *text)
{
    lv_obj_t *label = lv_label_create(parent);
    lv_label_set_text(label, text);
    lv_obj_set_style_text_font(label, font, 0);
    lv_obj_set_style_text_color(label, lv_color_hex(color_mono ? 0xFFFFFF : ink), 0);
    lv_obj_clear_flag(label, LV_OBJ_FLAG_CLICKABLE);
    return label;
}

static void flow_icon(lv_obj_t *parent, const char *icon, bool large)
{
    lv_obj_t *label = lv_label_create(parent);
    paint_icon_label(label, icon, false, large, text_fg());
    lv_obj_clear_flag(label, LV_OBJ_FLAG_CLICKABLE);
}

static lv_obj_t *flow_caption(lv_obj_t *parent, const char *text, int x, int y, int w, lv_text_align_t align)
{
    if (w < 8) w = 8;
    lv_obj_t *label = lv_label_create(parent);
    label_set_folded(label, text);
    lv_obj_set_style_text_font(label, &lv_font_montserrat_16, 0);
    lv_obj_set_style_text_color(label, lv_color_hex(text_fg()), 0);
    lv_obj_set_style_text_opa(label, LV_OPA_70, 0);
    lv_obj_set_style_text_align(label, align, 0);
    lv_label_set_long_mode(label, LV_LABEL_LONG_DOT);
    lv_obj_set_width(label, w);
    lv_obj_set_pos(label, x, y);
    lv_obj_clear_flag(label, LV_OBJ_FLAG_CLICKABLE);
    return label;
}

static void flow_point_at(const flow_slot_t *slot, int line, float fraction, int *x, int *y)
{
    int count = slot->npts[line];
    float total = 0;
    for (int i = 1; i < count; i++) {
        float dx = (float) (slot->pts[line][i].x - slot->pts[line][i - 1].x);
        float dy = (float) (slot->pts[line][i].y - slot->pts[line][i - 1].y);
        total += sqrtf(dx * dx + dy * dy);
    }
    float target = fraction * total;
    for (int i = 1; i < count; i++) {
        float dx = (float) (slot->pts[line][i].x - slot->pts[line][i - 1].x);
        float dy = (float) (slot->pts[line][i].y - slot->pts[line][i - 1].y);
        float seg = sqrtf(dx * dx + dy * dy);
        if (target <= seg || i == count - 1) {
            float t = seg > 0 ? target / seg : 0;
            if (t > 1) t = 1;
            *x = slot->pts[line][i - 1].x + (int) lroundf(dx * t);
            *y = slot->pts[line][i - 1].y + (int) lroundf(dy * t);
            return;
        }
        target -= seg;
    }
    *x = slot->pts[line][0].x;
    *y = slot->pts[line][0].y;
}

static void flow_tick(lv_timer_t *timer)
{
    (void) timer;
    for (int index = 0; index < flow_slot_count; index++) {
        flow_slot_t *slot = &flow_pool[index];
        if (!slot->card) continue;
        for (int line = 0; line < FLOW_LINES; line++) {
            if (!slot->dot[line]) continue;
            int pct = slot->pct[line];
            if (pct == 0) continue;
            int strength = pct < 0 ? -pct : pct;
            int period_ms = 3200 - 22 * strength;
            slot->phase[line] = (uint16_t) (slot->phase[line] + (65536u * 40u) / (unsigned) period_ms);
            float fraction = slot->phase[line] / 65536.0f;
            if (pct < 0) fraction = 1.0f - fraction;
            int x = 0;
            int y = 0;
            flow_point_at(slot, line, fraction, &x, &y);
            lv_obj_set_pos(slot->dot[line], x - FLOW_DOT / 2, y - FLOW_DOT / 2);
        }
    }
}

static void build_energy_flow(tile_view_t *view, cJSON *tile, int card_w, int card_h, bool show_title)
{
    if (flow_slot_count >= FLOW_SLOT_MAX) return;
    flow_slot_t *slot = &flow_pool[flow_slot_count];
    memset(slot, 0, sizeof(*slot));
    lv_obj_clear_flag(view->card, LV_OBJ_FLAG_SCROLLABLE);
    cJSON *nodes = cJSON_GetObjectItem(tile, "flowNodes");
    bool has_pv = cJSON_IsTrue(cJSON_GetObjectItem(nodes, "pv"));
    bool grid_in = cJSON_IsTrue(cJSON_GetObjectItem(nodes, "gridIn"));
    bool grid_out = cJSON_IsTrue(cJSON_GetObjectItem(nodes, "gridOut"));
    bool bat_in = cJSON_IsTrue(cJSON_GetObjectItem(nodes, "batIn"));
    bool bat_out = cJSON_IsTrue(cJSON_GetObjectItem(nodes, "batOut"));
    bool has_soc = cJSON_IsTrue(cJSON_GetObjectItem(nodes, "soc"));
    bool has_grid = grid_in || grid_out;
    bool has_bat = bat_in || bat_out || has_soc;

    int head = show_title ? lv_font_get_line_height(font_for(view->title_px, view->title_bold, view->title_italic)) + 4 : 0;
    int w = card_w - 20;
    int h = card_h - 20 - head;
    if (w < 80 || h < 60) return;
    const int label_h = 20;
    int r;
    if (has_pv && has_bat) r = (int) (h / 4.8f);
    else if (has_pv || has_bat) r = (int) ((h - label_h) / 3.8f);
    else r = (h - label_h) / 2 - 2;
    if (r > w / 7) r = w / 7;
    if (r > 80) r = 80;
    if (r < 18) r = 18;

    int cx = w / 2;
    int ym;
    if (has_pv && has_bat) ym = head + h / 2;
    else if (has_pv) ym = head + h - r - 2;
    else if (has_bat) ym = head + label_h + r + 2;
    else ym = head + label_h + (h - label_h) / 2;
    int py = head + r + 2;
    int by = head + h - r - 2;
    int gx = r + 2;
    int hx = w - r - 2;
    int k = r * 2 / 5;
    int dk = (int) lroundf(sqrtf((float) (r * r - k * k)));
    int rc = r / 2;

    if (has_pv) {
        int top = py + dk;
        int rcl = rc;
        int bend = ym - k - rcl;
        if (bend < top) {
            rcl -= top - bend;
            if (rcl < 0) rcl = 0;
            bend = ym - k - rcl;
        }
        flow_push(slot, 0, cx + k, top);
        flow_push(slot, 0, cx + k, bend);
        if (rcl > 0) flow_arc(slot, 0, cx + k + rcl, bend, rcl, 180, 90);
        flow_push(slot, 0, hx - dk, ym - k);
        if (grid_out) {
            flow_push(slot, 1, cx - k, top);
            flow_push(slot, 1, cx - k, bend);
            if (rcl > 0) flow_arc(slot, 1, cx - k - rcl, bend, rcl, 0, 90);
            flow_push(slot, 1, gx + dk, ym - k);
        }
        if (has_bat) {
            flow_push(slot, 2, cx, py + r);
            flow_push(slot, 2, cx, by - r);
        }
    }
    if (grid_in) {
        flow_push(slot, 3, gx + r, ym);
        flow_push(slot, 3, hx - r, ym);
    }
    if (has_bat) {
        int bottom = by - dk;
        int rcl = rc;
        int bend = ym + k + rcl;
        if (bend > bottom) {
            rcl -= bend - bottom;
            if (rcl < 0) rcl = 0;
            bend = ym + k + rcl;
        }
        if (has_grid) {
            flow_push(slot, 4, gx + dk, ym + k);
            flow_push(slot, 4, cx - k - rcl, ym + k);
            if (rcl > 0) flow_arc(slot, 4, cx - k - rcl, bend, rcl, 270, 360);
            flow_push(slot, 4, cx - k, bottom);
        }
        flow_push(slot, 5, cx + k, bottom);
        flow_push(slot, 5, cx + k, bend);
        if (rcl > 0) flow_arc(slot, 5, cx + k + rcl, bend, rcl, 180, 270);
        flow_push(slot, 5, hx - dk, ym + k);
    }

    for (int line = 0; line < FLOW_LINES; line++) {
        if (slot->npts[line] < 2) continue;
        uint32_t ink = color_mono ? 0xFFFFFF : FLOW_INK[line];
        lv_obj_t *path = lv_line_create(view->card);
        lv_line_set_points(path, slot->pts[line], slot->npts[line]);
        lv_obj_set_pos(path, 0, 0);
        lv_obj_set_style_line_width(path, 2, 0);
        lv_obj_set_style_line_color(path, lv_color_hex(ink), 0);
        lv_obj_set_style_line_opa(path, LV_OPA_40, 0);
        lv_obj_set_style_line_rounded(path, true, 0);
        lv_obj_clear_flag(path, LV_OBJ_FLAG_CLICKABLE);
        slot->line[line] = path;
        lv_obj_t *dot = lv_obj_create(view->card);
        style_flat(dot);
        lv_obj_set_size(dot, FLOW_DOT, FLOW_DOT);
        lv_obj_set_style_radius(dot, LV_RADIUS_CIRCLE, 0);
        lv_obj_set_style_bg_color(dot, lv_color_hex(ink), 0);
        lv_obj_set_style_bg_opa(dot, LV_OPA_COVER, 0);
        lv_obj_set_style_pad_all(dot, 0, 0);
        lv_obj_clear_flag(dot, LV_OBJ_FLAG_CLICKABLE);
        lv_obj_add_flag(dot, LV_OBJ_FLAG_HIDDEN);
        slot->dot[line] = dot;
    }

    uint32_t fill = view->base_bg;
    const lv_font_t *value_font = r >= 50 ? &lv_font_montserrat_20 : &lv_font_montserrat_16;
    bool large_icon = r >= 64;
    bool with_icon = r >= 34;
    if (has_pv) {
        lv_obj_t *circle = flow_circle(view->card, cx, py, r, FLOW_RING_PV, fill);
        if (with_icon) flow_icon(circle, "solar", large_icon);
        slot->pv_val = flow_text(circle, value_font, text_fg(), "-");
        flow_caption(view->card, "PV", 0, py - 10, cx - r - 8, LV_TEXT_ALIGN_RIGHT);
    }
    if (has_grid) {
        lv_obj_t *circle = flow_circle(view->card, gx, ym, r, FLOW_RING_GRID, fill);
        if (with_icon) flow_icon(circle, "transmissionTower", large_icon && !(grid_in && grid_out));
        if (grid_out) slot->grid_out = flow_text(circle, value_font, FLOW_INK_EXPORT, "-");
        if (grid_in) slot->grid_in = flow_text(circle, value_font, FLOW_INK_IMPORT, "-");
        flow_caption(view->card, "Netz", gx - r - 2, ym - r - label_h, 2 * r + 4, LV_TEXT_ALIGN_CENTER);
    }
    if (has_bat) {
        lv_obj_t *circle = flow_circle(view->card, cx, by, r, FLOW_RING_BAT, fill);
        if (with_icon) flow_icon(circle, "batteryIcon", large_icon && !(bat_in && bat_out));
        if (bat_in) slot->bat_in = flow_text(circle, value_font, FLOW_INK_CHARGE, "-");
        if (bat_out) slot->bat_out = flow_text(circle, value_font, FLOW_INK_DISCHARGE, "-");
        int caption_y = has_soc ? by - 20 : by - 10;
        flow_caption(view->card, "Batterie", 0, caption_y, cx - r - 8, LV_TEXT_ALIGN_RIGHT);
        if (has_soc) slot->soc_val = flow_caption(view->card, "", 0, caption_y + 20, cx - r - 8, LV_TEXT_ALIGN_RIGHT);
    }
    lv_obj_t *home = flow_circle(view->card, hx, ym, r, FLOW_RING_HOME, fill);
    if (with_icon) flow_icon(home, "home", large_icon);
    slot->home_val = flow_text(home, value_font, text_fg(), "-");
    flow_caption(view->card, "Zuhause", hx - r - 20, ym - r - label_h, 2 * r + 22, LV_TEXT_ALIGN_CENTER);
    static const uint32_t mix_ink[3] = {FLOW_RING_PV, FLOW_INK_DISCHARGE, FLOW_RING_GRID};
    for (int part = 0; part < 3; part++) {
        lv_obj_t *arc = lv_arc_create(view->card);
        lv_obj_remove_style(arc, NULL, LV_PART_KNOB);
        lv_obj_set_size(arc, 2 * r, 2 * r);
        lv_obj_set_pos(arc, hx - r, ym - r);
        lv_obj_set_style_pad_all(arc, 0, LV_PART_MAIN);
        lv_obj_set_style_bg_opa(arc, LV_OPA_TRANSP, LV_PART_MAIN);
        lv_obj_set_style_arc_opa(arc, LV_OPA_TRANSP, LV_PART_MAIN);
        lv_obj_set_style_arc_width(arc, 4, LV_PART_INDICATOR);
        lv_obj_set_style_arc_rounded(arc, false, LV_PART_INDICATOR);
        lv_obj_set_style_arc_color(arc, lv_color_hex(color_mono ? 0xFFFFFF : mix_ink[part]), LV_PART_INDICATOR);
        lv_arc_set_bg_angles(arc, 0, 360);
        lv_arc_set_angles(arc, 270, 270);
        lv_obj_clear_flag(arc, LV_OBJ_FLAG_CLICKABLE);
        lv_obj_add_flag(arc, LV_OBJ_FLAG_HIDDEN);
        slot->home_arc[part] = arc;
    }

    /* Einzelne Verbraucher stehen unter dem Haus, sonst darüber. */
    cJSON *consumers = cJSON_GetObjectItem(tile, "flowConsumers");
    int wanted = cJSON_IsArray(consumers) ? cJSON_GetArraySize(consumers) : 0;
    int x0 = cx + r + 8;
    int col_w = w - x0;
    if (wanted > 0 && col_w >= 70) {
        int below_y = ym + r + 6;
        int below_h = head + h - below_y;
        int above_y = head;
        int above_h = ym - r - label_h - 2 - head;
        int y0 = below_h >= above_h ? below_y : above_y;
        int room = below_h >= above_h ? below_h : above_h;
        int fit = room / 20;
        if (fit > wanted) fit = wanted;
        if (fit > FLOW_CONS_MAX) fit = FLOW_CONS_MAX;
        int name_w = col_w * 55 / 100;
        for (int index = 0; index < fit; index++) {
            cJSON *item = cJSON_GetArrayItem(consumers, index);
            const char *name = cJSON_GetStringValue(cJSON_GetObjectItem(item, "title"));
            int y = y0 + index * 20;
            flow_caption(view->card, name && name[0] ? name : "Verbraucher", x0, y, name_w, LV_TEXT_ALIGN_LEFT);
            lv_obj_t *value = flow_caption(view->card, "-", x0 + name_w, y, col_w - name_w, LV_TEXT_ALIGN_RIGHT);
            lv_obj_set_style_text_opa(value, LV_OPA_COVER, 0);
            slot->cons_val[index] = value;
        }
        slot->cons_count = fit;
    }

    slot->card = view->card;
    view->flow = slot;
    flow_slot_count++;
    if (!flow_timer) flow_timer = lv_timer_create(flow_tick, 40, NULL);
}

static void flow_set(lv_obj_t *label, const char *prefix, cJSON *flow, const char *key)
{
    if (!label) return;
    const char *text = cJSON_GetStringValue(cJSON_GetObjectItem(flow, key));
    char buffer[48];
    snprintf(buffer, sizeof(buffer), "%s%s", prefix ? prefix : "", text && text[0] ? text : "-");
    lv_label_set_text(label, buffer);
}

static void paint_energy_flow(tile_view_t *view, cJSON *flow)
{
    flow_slot_t *slot = view->flow;
    if (!slot || !cJSON_IsObject(flow)) return;
    flow_set(slot->pv_val, NULL, flow, "pv");
    flow_set(slot->grid_out, LV_SYMBOL_LEFT " ", flow, "gridOut");
    flow_set(slot->grid_in, LV_SYMBOL_RIGHT " ", flow, "gridIn");
    flow_set(slot->bat_in, LV_SYMBOL_DOWN " ", flow, "batIn");
    flow_set(slot->bat_out, LV_SYMBOL_UP " ", flow, "batOut");
    flow_set(slot->home_val, NULL, flow, "home");
    if (slot->soc_val) flow_set(slot->soc_val, NULL, flow, "soc");
    cJSON *lines = cJSON_GetObjectItem(flow, "l");
    for (int line = 0; line < FLOW_LINES; line++) {
        cJSON *item = cJSON_GetArrayItem(lines, line);
        int pct = cJSON_IsNumber(item) ? item->valueint : 0;
        if (pct > 100) pct = 100;
        if (pct < -100) pct = -100;
        if (!slot->line[line]) pct = 0;
        slot->pct[line] = (int8_t) pct;
        if (slot->line[line]) lv_obj_set_style_line_opa(slot->line[line], pct ? LV_OPA_COVER : LV_OPA_40, 0);
        if (slot->dot[line]) {
            if (pct) lv_obj_clear_flag(slot->dot[line], LV_OBJ_FLAG_HIDDEN);
            else lv_obj_add_flag(slot->dot[line], LV_OBJ_FLAG_HIDDEN);
        }
    }
    cJSON *mix = cJSON_GetObjectItem(flow, "mix");
    int start = 270;
    for (int part = 0; part < 3; part++) {
        lv_obj_t *arc = slot->home_arc[part];
        if (!arc) continue;
        cJSON *item = cJSON_GetArrayItem(mix, part);
        int share = cJSON_IsNumber(item) ? item->valueint : 0;
        if (share <= 0) {
            lv_obj_add_flag(arc, LV_OBJ_FLAG_HIDDEN);
            continue;
        }
        if (share > 100) share = 100;
        int sweep = share * 360 / 100;
        if (sweep >= 360) sweep = 359;
        lv_arc_set_angles(arc, (uint16_t) (start % 360), (uint16_t) ((start + sweep) % 360));
        lv_obj_clear_flag(arc, LV_OBJ_FLAG_HIDDEN);
        start += sweep;
    }
    cJSON *consumers = cJSON_GetObjectItem(flow, "c");
    for (int index = 0; index < slot->cons_count; index++) {
        cJSON *item = cJSON_GetArrayItem(consumers, index);
        flow_set(slot->cons_val[index], NULL, item, "v");
    }
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
            if (tiles[index].schedule) {
                paint_schedule(&tiles[index], cJSON_GetObjectItem(tile, "schedule"));
                continue;
            }
            if (tiles[index].chart_line) paint_chart(&tiles[index], tile);
            int number = tiles[index].value;
            bool has_number = false;
            if (cJSON_IsNumber(value)) {
                number = (int) value->valuedouble;
                has_number = true;
            } else if (cJSON_IsBool(value)) {
                if (control_is_dimmer(tiles[index].control) || strcmp(tiles[index].control, "switch") == 0 || strcmp(tiles[index].control, "image") == 0) {
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
                bool matched = false;
                if (has_number && level) {
                    int span = tiles[index].value_max - tiles[index].value_min;
                    if (span < 1) span = 1;
                    int tolerance = span / 50;
                    if (tolerance < 1) tolerance = 1;
                    int delta = number - tiles[index].held_value;
                    if (delta < 0) delta = -delta;
                    matched = delta <= tolerance;
                } else if (has_number) {
                    matched = incoming_on == held_on;
                }
                if (esp_timer_get_time() > tiles[index].held_until || matched) {
                    tiles[index].holding = false;
                } else {
                    continue;
                }
            }
            if (tiles[index].label) label_set_folded(tiles[index].label, text ? text : "-");
            if (strcmp(tiles[index].control, "image") == 0 && tiles[index].label) {
                lv_obj_add_flag(tiles[index].label, LV_OBJ_FLAG_HIDDEN);
            }
            if (tiles[index].chart_line) layout_chart_head(&tiles[index]);
            const char *age = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "age"));
            const char *sub = cJSON_GetStringValue(cJSON_GetObjectItem(tile, "sub"));
            if (tiles[index].age_label) {
                label_set_folded(tiles[index].age_label, (sub && sub[0]) ? sub : (age ? age : ""));
            }
            cJSON *lines = cJSON_GetObjectItem(tile, "lines");
            if (tiles[index].flow) paint_energy_flow(&tiles[index], cJSON_GetObjectItem(tile, "flow"));
            if (cJSON_IsArray(lines)) {
                int line_count = cJSON_GetArraySize(lines);
                for (int row_index = 0; row_index < 6 && row_index < line_count; row_index++) {
                    cJSON *line = cJSON_GetArrayItem(lines, row_index);
                    if (tiles[index].rows[row_index] && !tiles[index].dots[row_index]) {
                        const char *caption = cJSON_GetStringValue(cJSON_GetObjectItem(line, "title"));
                        const char *value_text = cJSON_GetStringValue(cJSON_GetObjectItem(line, "text"));
                        char composed[96];
                        snprintf(composed, sizeof(composed), "%s%s%s",
                                 caption ? caption : "",
                                 (caption && caption[0] && value_text && value_text[0]) ? "  " : "",
                                 value_text ? value_text : "");
                        label_set_folded(tiles[index].rows[row_index], composed[0] ? composed : "-");
                    }
                    if (tiles[index].dots[row_index]) {
                        bool dot_on = cJSON_IsTrue(cJSON_GetObjectItem(line, "on"));
                        lv_obj_set_style_bg_opa(tiles[index].dots[row_index], dot_on ? LV_OPA_COVER : LV_OPA_TRANSP, 0);
                        lv_obj_set_style_bg_color(tiles[index].dots[row_index], lv_color_hex(0x3DDC97), 0);
                    }
                }
            }
            if (tiles[index].arc && has_number) {
                int span = tiles[index].gauge_max - tiles[index].gauge_min;
                int percent = span == 0 ? 0 : (number - tiles[index].gauge_min) * 100 / span;
                if (percent < 0) percent = 0;
                if (percent > 100) percent = 100;
                lv_arc_set_value(tiles[index].arc, percent);
            }
            apply_status_color(&tiles[index], color);
            if (!has_number) {
                if (tiles[index].value_row) layout_value_row(&tiles[index]);
                continue;
            }
            tiles[index].applying = true;
            tiles[index].value = number;
            tiles[index].have_number = true;
            tiles[index].live_number = cJSON_IsNumber(value) ? (float) value->valuedouble : (float) number;
            bool on = value_is_on(&tiles[index], number);
            if (tiles[index].battery_fill && tiles[index].battery_shell) {
                place_battery(&tiles[index], number);
            } else if (tiles[index].track) {
                if (on) tiles[index].level = number;
                place_level(&tiles[index], number);
                if (tiles[index].power) set_power_checked(&tiles[index], on);
            }
            refresh_icons(&tiles[index], on);
            if (tiles[index].value_row) layout_value_row(&tiles[index]);
            if (control_is_dimmer(tiles[index].control)) {
                paint_dim_label(&tiles[index]);
            } else if (tiles[index].value_rule_count > 0 && (strcmp(tiles[index].control, "switch") == 0 || strcmp(tiles[index].control, "image") == 0)) {
                apply_action_rules(&tiles[index], number);
            } else if (tiles[index].has_action && tiles[index].label && tiles[index].show_state_text) {
                label_set_folded(tiles[index].label, on ? "Ein" : "Aus");
            }
            if (tiles[index].value_rule_count == 0 && tiles[index].has_action && tiles[index].card && strcmp(tiles[index].control, "switch") == 0) {
                lv_obj_set_style_bg_color(tiles[index].card,
                    lv_color_hex(on ? tile_on_color(&tiles[index]) : tiles[index].base_bg), 0);
            }
            const value_rule_t *rule = tiles[index].value_row ? value_rule_match(&tiles[index]) : NULL;
            if (rule && rule->has_bg_color && tiles[index].card) {
                lv_obj_set_style_bg_color(tiles[index].card, lv_color_hex(rule->bg_color), 0);
                lv_obj_set_style_bg_opa(tiles[index].card, LV_OPA_COVER, 0);
            } else if (tiles[index].value_row && tiles[index].value_rule_count > 0 && tiles[index].card && !tiles[index].bg_clear) {
                lv_obj_set_style_bg_color(tiles[index].card, lv_color_hex(tiles[index].base_bg), 0);
            } else if (!tiles[index].bg_clear && !tiles[index].has_action && tiles[index].has_threshold && tiles[index].card) {
                uint32_t bg = tiles[index].base_bg;
                if (number >= tiles[index].threshold) {
                    if (strcmp(tiles[index].status_color, "ok") == 0) bg = 0x1E6B3A;
                    else if (strcmp(tiles[index].status_color, "warn") == 0) bg = 0x8A6A12;
                    else if (strcmp(tiles[index].status_color, "alarm") == 0) bg = 0x8A2424;
                }
                lv_obj_set_style_bg_color(tiles[index].card, lv_color_hex(bg), 0);
            }
            if (tiles[index].bg_clear && tiles[index].card && !(rule && rule->has_bg_color)) {
                lv_obj_set_style_bg_opa(tiles[index].card, LV_OPA_TRANSP, 0);
            }
            tiles[index].applying = false;
        }
    }
}

bool homedisplay_ui_camera_wanted(const char *tile_id, bool *has_frame)
{
    if (has_frame) *has_frame = false;
    if (!tile_id || !tile_id[0]) return false;
    if (!ui_client_lock()) return false;
    bool present = camera_tile_present(tile_id);
    if (present && has_frame) {
        camera_slot_t *slot = camera_slot_find(tile_id);
        *has_frame = slot && slot->shown >= 0 && slot->buf[slot->shown];
    }
    lvgl_port_unlock();
    return present;
}

/* Läuft im Client-Task. Dekodiert außerhalb der LVGL-Sperre und tauscht nur den Zeiger unter der Sperre. */
bool homedisplay_ui_show_camera_jpeg(const char *tile_id, const uint8_t *jpeg, size_t length)
{
    if (!tile_id || !tile_id[0] || !jpeg || length < 4) return false;
    int width = 0;
    int height = 0;
    int scale = 0;
    int result = homedisplay_jpeg_probe(jpeg, length, CAMERA_MAX_PX, &width, &height, &scale);
    if (result != 0) {
        ESP_LOGW(TAG_UI, "Kamera-Bild verworfen (JPEG-Fehler %d, nur Baseline-JPEG)", result);
        return false;
    }

    if (!ui_client_lock()) return false;
    camera_slot_t *slot = camera_tile_present(tile_id) ? camera_slot_get(tile_id) : NULL;
    int target = 0;
    uint16_t *dst = NULL;
    if (slot) {
        target = slot->shown == 0 ? 1 : 0;
        size_t need = (size_t) width * (size_t) height;
        if (slot->cap_px[target] < need) {
            uint16_t *grown = heap_caps_malloc(need * sizeof(uint16_t), MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
            if (grown) {
                if (slot->buf[target]) heap_caps_free(slot->buf[target]);
                slot->buf[target] = grown;
                slot->cap_px[target] = need;
            }
        }
        if (slot->cap_px[target] >= need) dst = slot->buf[target];
    }
    lvgl_port_unlock();
    if (!slot) return false;
    if (!dst) {
        ESP_LOGW(TAG_UI, "Kamera-Bild verworfen (kein Speicher für %dx%d)", width, height);
        return false;
    }

    int64_t started = esp_timer_get_time();
    result = homedisplay_jpeg_decode(jpeg, length, scale, dst, width, height);
    if (result != 0) {
        ESP_LOGW(TAG_UI, "Kamera-Bild verworfen (Dekodierfehler %d)", result);
        return false;
    }
    int decode_ms = (int) ((esp_timer_get_time() - started) / 1000);

    if (!ui_client_lock()) return false;
    lv_img_dsc_t *dsc = &slot->dsc[target];
    memset(dsc, 0, sizeof(*dsc));
    dsc->header.always_zero = 0;
    dsc->header.cf = LV_IMG_CF_TRUE_COLOR;
    dsc->header.w = (uint32_t) width;
    dsc->header.h = (uint32_t) height;
    dsc->data_size = (uint32_t) width * (uint32_t) height * sizeof(uint16_t);
    dsc->data = (const uint8_t *) dst;
    slot->shown = target;
    bool shown = false;
    for (int index = 0; index < tile_count; index++) {
        if (tiles[index].camera_img && strcmp(tiles[index].tile_id, tile_id) == 0) {
            camera_show_frame(&tiles[index], dsc);
            shown = true;
            break;
        }
    }
    lvgl_port_unlock();
    ESP_LOGI(TAG_UI, "Kamera-Bild %s: %dx%d, %d KB, Dekodieren %d ms", tile_id, width, height, (int) (length / 1024), decode_ms);
    return shown;
}

void homedisplay_ui_refresh_image(const char *tile_id, const char *file)
{
    if (!tile_id || !tile_id[0] || !file || !file[0]) return;
    if (!raster_fits_display(file, 2u * 1024u * 1024u, NULL, NULL)) return;
    if (!ui_client_lock()) return;
    for (int index = 0; index < tile_count; index++) {
        if (strcmp(tiles[index].tile_id, tile_id) == 0 && tiles[index].image_img) {
            int box_w = lv_obj_get_width(tiles[index].image_img);
            int box_h = lv_obj_get_height(tiles[index].image_img);
            place_cover_image(&tiles[index], file, box_w, box_h);
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
