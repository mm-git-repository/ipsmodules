#pragma once

#include <stdbool.h>
#include <stdint.h>

/* Konfiguration über UART (115200): Boot-Fenster „m“+Enter, danach Menü jederzeit mit „m“+Enter. */
void homedisplay_serial_boot_window(uint32_t ms);
void homedisplay_serial_poll(void);
bool homedisplay_serial_run_menu(bool allow_cancel);
