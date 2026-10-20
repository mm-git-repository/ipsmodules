#pragma once
#include "device_types.h"

typedef void (*HdClientRefreshFn)(void);

void hdClientBegin(HdDeviceConfig &cfg);
void hdClientSetRefreshCallback(HdClientRefreshFn fn);
void hdClientTick();
const char *hdClientPairingCode();
bool hdClientOnline();
int hdClientStateIntervalSec();
const String &hdClientBaseUrl();
const String &hdClientToken();
String hdClientGetBinary(const char *path, int *httpCode = nullptr);
bool hdClientHttpJson(const char *path, const char *method, const char *body, String &out);
void hdClientPostAction(const char *tileId, const char *actionId, int value);
void hdClientTriggerResync();
