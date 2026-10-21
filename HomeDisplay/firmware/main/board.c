#include "homedisplay.h"

#include "driver/gpio.h"
#include "driver/i2c_master.h"
#include "port/esp_io_expander.h"
#include "port/esp_io_expander_ch422g.h"
#include "esp_lcd_panel_rgb.h"
#include "esp_lcd_touch_gt911.h"
#include "esp_log.h"

#include <time.h>

static const char *TAG = "board";
static esp_io_expander_handle_t expander_handle;
static i2c_master_dev_handle_t rtc_handle;

void homedisplay_board_backlight(bool on)
{
    if (!expander_handle) {
        return;
    }
    esp_io_expander_set_level(expander_handle, IO_EXPANDER_PIN_NUM_2, on ? 1 : 0);
}

static uint8_t bcd_to_int(uint8_t value)
{
    return (uint8_t) ((value >> 4) * 10 + (value & 0x0F));
}

static uint8_t int_to_bcd(int value)
{
    return (uint8_t) (((value / 10) << 4) | (value % 10));
}

/* PCF85063 an Adresse 0x51: Register 0x04 Sekunden bis 0x0A Jahr. */
esp_err_t homedisplay_rtc_read(int64_t *unix_time)
{
    if (!rtc_handle || !unix_time) return ESP_ERR_INVALID_STATE;
    uint8_t reg = 0x04;
    uint8_t raw[7];
    esp_err_t error = i2c_master_transmit_receive(rtc_handle, &reg, 1, raw, sizeof(raw), 200);
    if (error != ESP_OK) return error;
    if (raw[0] & 0x80) return ESP_ERR_INVALID_RESPONSE;
    struct tm moment = {
        .tm_sec = bcd_to_int(raw[0] & 0x7F),
        .tm_min = bcd_to_int(raw[1] & 0x7F),
        .tm_hour = bcd_to_int(raw[2] & 0x3F),
        .tm_mday = bcd_to_int(raw[3] & 0x3F),
        .tm_mon = bcd_to_int(raw[5] & 0x1F) - 1,
        .tm_year = bcd_to_int(raw[6]) + 100,
    };
    time_t value = mktime(&moment);
    if (value < 1700000000) return ESP_ERR_INVALID_RESPONSE;
    *unix_time = (int64_t) value;
    return ESP_OK;
}

esp_err_t homedisplay_rtc_write(int64_t unix_time)
{
    if (!rtc_handle) return ESP_ERR_INVALID_STATE;
    time_t value = (time_t) unix_time;
    struct tm moment;
    gmtime_r(&value, &moment);
    uint8_t frame[8] = {
        0x04,
        int_to_bcd(moment.tm_sec),
        int_to_bcd(moment.tm_min),
        int_to_bcd(moment.tm_hour),
        int_to_bcd(moment.tm_mday),
        int_to_bcd(moment.tm_wday),
        int_to_bcd(moment.tm_mon + 1),
        int_to_bcd(moment.tm_year - 100),
    };
    return i2c_master_transmit(rtc_handle, frame, sizeof(frame), 200);
}

static const int DATA_GPIOS[16] = {
    14, 38, 18, 17, 10, 39, 0, 45, 48, 47, 21, 1, 2, 42, 41, 40,
};

esp_err_t homedisplay_board_init(esp_lcd_panel_handle_t *panel, esp_lcd_touch_handle_t *touch)
{
    i2c_master_bus_config_t bus_config = {
        .i2c_port = I2C_NUM_0,
        .sda_io_num = 8,
        .scl_io_num = 9,
        .clk_source = I2C_CLK_SRC_DEFAULT,
        .glitch_ignore_cnt = 7,
        .flags.enable_internal_pullup = true,
    };
    i2c_master_bus_handle_t bus = NULL;
    ESP_RETURN_ON_ERROR(i2c_new_master_bus(&bus_config, &bus), TAG, "I2C");

    esp_io_expander_handle_t expander = NULL;
    ESP_RETURN_ON_ERROR(esp_io_expander_new_i2c_ch422g(bus, ESP_IO_EXPANDER_I2C_CH422G_ADDRESS, &expander), TAG, "CH422G");
    expander_handle = expander;
    ESP_RETURN_ON_ERROR(esp_io_expander_set_dir(expander, IO_EXPANDER_PIN_NUM_1 | IO_EXPANDER_PIN_NUM_2 | IO_EXPANDER_PIN_NUM_3, IO_EXPANDER_OUTPUT), TAG, "CH422G Richtung");
    ESP_RETURN_ON_ERROR(esp_io_expander_set_level(expander, IO_EXPANDER_PIN_NUM_1 | IO_EXPANDER_PIN_NUM_3, 1), TAG, "Reset lösen");
    ESP_RETURN_ON_ERROR(esp_io_expander_set_level(expander, IO_EXPANDER_PIN_NUM_2, 0), TAG, "Backlight aus");

    esp_lcd_rgb_panel_config_t panel_config = {
        .clk_src = LCD_CLK_SRC_PLL160M,
        .timings = {
            .pclk_hz = 21000000,
            .h_res = HOMEDISPLAY_LCD_H_RES,
            .v_res = HOMEDISPLAY_LCD_V_RES,
            .hsync_pulse_width = 30,
            .hsync_back_porch = 145,
            .hsync_front_porch = 170,
            .vsync_pulse_width = 2,
            .vsync_back_porch = 23,
            .vsync_front_porch = 12,
            .flags.pclk_active_neg = true,
        },
        .data_width = 16,
        .bits_per_pixel = 16,
        .num_fbs = 1,
        .bounce_buffer_size_px = HOMEDISPLAY_LCD_H_RES * 10,
        .sram_trans_align = 4,
        .psram_trans_align = 64,
        .hsync_gpio_num = 46,
        .vsync_gpio_num = 3,
        .de_gpio_num = 5,
        .pclk_gpio_num = 7,
        .disp_gpio_num = GPIO_NUM_NC,
        .flags.fb_in_psram = true,
    };
    for (int index = 0; index < 16; index++) {
        panel_config.data_gpio_nums[index] = DATA_GPIOS[index];
    }
    ESP_RETURN_ON_ERROR(esp_lcd_new_rgb_panel(&panel_config, panel), TAG, "RGB");
    ESP_RETURN_ON_ERROR(esp_lcd_panel_reset(*panel), TAG, "Panel-Reset");
    ESP_RETURN_ON_ERROR(esp_lcd_panel_init(*panel), TAG, "Panel-Init");
    ESP_RETURN_ON_ERROR(esp_io_expander_set_level(expander, IO_EXPANDER_PIN_NUM_2, 1), TAG, "Backlight an");

    esp_lcd_panel_io_handle_t touch_io = NULL;
    esp_lcd_panel_io_i2c_config_t touch_io_config = ESP_LCD_TOUCH_IO_I2C_GT911_CONFIG();
    ESP_RETURN_ON_ERROR(esp_lcd_new_panel_io_i2c(bus, &touch_io_config, &touch_io), TAG, "Touch-IO");
    esp_lcd_touch_config_t touch_config = {
        .x_max = HOMEDISPLAY_LCD_H_RES,
        .y_max = HOMEDISPLAY_LCD_V_RES,
        .rst_gpio_num = GPIO_NUM_NC,
        .int_gpio_num = 4,
        .levels.reset = 0,
        .levels.interrupt = 0,
    };
    ESP_RETURN_ON_ERROR(esp_lcd_touch_new_i2c_gt911(touch_io, &touch_config, touch), TAG, "GT911");

    i2c_device_config_t rtc_config = {
        .dev_addr_length = I2C_ADDR_BIT_LEN_7,
        .device_address = 0x51,
        .scl_speed_hz = 100000,
    };
    if (i2c_master_bus_add_device(bus, &rtc_config, &rtc_handle) != ESP_OK) {
        ESP_LOGW(TAG, "RTC nicht gefunden");
        rtc_handle = NULL;
    }
    return ESP_OK;
}
