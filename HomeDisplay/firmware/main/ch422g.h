#pragma once

#include "driver/i2c_master.h"
#include "esp_err.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef struct ch422g_dev_t *ch422g_handle_t;

esp_err_t ch422g_new_i2c_bus(i2c_master_bus_handle_t bus, ch422g_handle_t *out);
esp_err_t ch422g_init_panel_io(ch422g_handle_t dev);
esp_err_t ch422g_set_pin(ch422g_handle_t dev, int pin, bool level);
void ch422g_del(ch422g_handle_t dev);

#ifdef __cplusplus
}
#endif
