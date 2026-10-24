#pragma once

#include <stdbool.h>
#include <stdint.h>

/* Konfiguration über USB-Serial/JTAG (ESP32-S3) bzw. UART: Boot-Fenster „m“+Enter. */
void homedisplay_serial_console_init(void);
void homedisplay_serial_boot_window(uint32_t ms);
void homedisplay_serial_poll(void);
bool homedisplay_serial_run_menu(bool allow_cancel);
