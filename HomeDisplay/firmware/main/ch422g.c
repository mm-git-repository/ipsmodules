#include "ch422g.h"

#include <stdlib.h>

#include "esp_check.h"

#define CH422G_REG_WR_SET (0x48 >> 1)
#define CH422G_REG_WR_OC (0x46 >> 1)
#define CH422G_REG_WR_IO (0x70 >> 1)

struct ch422g_dev_t {
    i2c_master_bus_handle_t bus;
    uint8_t wr_set;
    uint8_t wr_oc;
    uint8_t wr_io;
};

static esp_err_t ch422g_tx(i2c_master_bus_handle_t bus, uint8_t addr, const uint8_t *data, size_t len)
{
    i2c_device_config_t cfg = {
        .dev_addr_length = I2C_ADDR_BIT_LEN_7,
        .device_address = addr,
        .scl_speed_hz = 400000,
    };
    i2c_master_dev_handle_t dev = NULL;
    ESP_RETURN_ON_ERROR(i2c_master_bus_add_device(bus, &cfg, &dev), "ch422g", "add dev");
    esp_err_t err = i2c_master_transmit(dev, data, len, 50);
    i2c_master_bus_rm_device(dev);
    return err;
}

static esp_err_t ch422g_write_output(ch422g_handle_t dev)
{
    ESP_RETURN_ON_ERROR(ch422g_tx(dev->bus, CH422G_REG_WR_OC, &dev->wr_oc, 1), "ch422g", "wr_oc");
    ESP_RETURN_ON_ERROR(ch422g_tx(dev->bus, CH422G_REG_WR_IO, &dev->wr_io, 1), "ch422g", "wr_io");
    return ESP_OK;
}

static esp_err_t ch422g_write_set(ch422g_handle_t dev)
{
    return ch422g_tx(dev->bus, CH422G_REG_WR_SET, &dev->wr_set, 1);
}

esp_err_t ch422g_new_i2c_bus(i2c_master_bus_handle_t bus, ch422g_handle_t *out)
{
    ESP_RETURN_ON_FALSE(bus && out, ESP_ERR_INVALID_ARG, "ch422g", "args");
    ch422g_handle_t dev = calloc(1, sizeof(struct ch422g_dev_t));
    ESP_RETURN_ON_FALSE(dev, ESP_ERR_NO_MEM, "ch422g", "malloc");
    dev->bus = bus;
    dev->wr_set = 0x01;
    dev->wr_oc = 0x0F;
    dev->wr_io = 0xFF;
    *out = dev;
    return ESP_OK;
}

esp_err_t ch422g_init_panel_io(ch422g_handle_t dev)
{
    ESP_RETURN_ON_FALSE(dev, ESP_ERR_INVALID_ARG, "ch422g", "dev");
    dev->wr_set |= 0x01;
    ESP_RETURN_ON_ERROR(ch422g_write_set(dev), "ch422g", "set");
    ESP_RETURN_ON_ERROR(ch422g_write_output(dev), "ch422g", "out");
    return ESP_OK;
}

esp_err_t ch422g_set_pin(ch422g_handle_t dev, int pin, bool level)
{
    ESP_RETURN_ON_FALSE(dev && pin >= 0 && pin < 12, ESP_ERR_INVALID_ARG, "ch422g", "pin");
    if (pin < 8) {
        if (level) {
            dev->wr_io |= (uint8_t)(1U << pin);
        } else {
            dev->wr_io &= (uint8_t) ~(1U << pin);
        }
    } else {
        int bit = pin - 8;
        if (level) {
            dev->wr_oc |= (uint8_t)(1U << bit);
        } else {
            dev->wr_oc &= (uint8_t) ~(1U << bit);
        }
    }
    return ch422g_write_output(dev);
}

void ch422g_del(ch422g_handle_t dev)
{
    free(dev);
}
