#include "homedisplay.h"

#include "driver/gpio.h"
#include "driver/i2c_master.h"
#include "esp_io_expander.h"
#include "esp_io_expander_ch422g.h"
#include "esp_lcd_panel_rgb.h"
#include "esp_lcd_touch_gt911.h"
#include "esp_log.h"

static const char *TAG = "board";

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
    return ESP_OK;
}
