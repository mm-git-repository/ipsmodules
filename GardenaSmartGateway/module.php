<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartDevices.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartClient.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartSchedules.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartWaterUsage.php';

class GardenaSmartGateway extends IPSModuleStrict
{
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 23;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_INVALID = 201;
    private const IS_UNREACHABLE = 202;

    private const UPDATE_MIN_SEC = 30;
    private const UPDATE_DEFAULT_SEC = 60;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Port', 8443);
        $this->RegisterPropertyBoolean('TlsInsecure', true);
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_DEFAULT_SEC);
        $this->RegisterPropertyString('FlowPresets', GardenaSmartWaterUsage::defaultCatalogJson());
        $this->RegisterPropertyBoolean('Debug', false);

        $this->RegisterAttributeString('DeviceCache', '{}');
        $this->RegisterAttributeString('DeviceScheduleBlock', '');
        $this->RegisterAttributeString('DebugLogBuffer', '');

        $this->RegisterVariableBoolean('Reachable', 'Erreichbar', '', 1);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 2);
        $this->RegisterVariableInteger('DeviceCount', 'Geräteanzahl', '', 3);
        $this->RegisterVariableString('ScheduleOverview', 'Zeitplan-Übersicht', '', 10);
        $this->RegisterVariableString('UsageOverview', 'Wasserverbrauch-Übersicht', '', 11);
        $this->RegisterVariableString('DebugLog', 'Debug-Log', '~TextBox', 20);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 999);

        $this->RegisterTimer('Update', 0, 'GSGW_UpdateValues($_IPS[\'TARGET\']);');

        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
        $this->ensureFlowPresetsSeeded();
        $debugVarId = @$this->GetIDForIdent('DebugLog');
        if (is_int($debugVarId) && $debugVarId > 0) {
            IPS_SetHidden($debugVarId, !$this->ReadPropertyBoolean('Debug'));
        }
        $this->configureTimer();
        $this->updateInstanceStatus();
        $this->SetSummary($this->buildSummary());
    }

    public function GetConfigurationForm(): string
    {
        $this->ensureFlowPresetsSeeded();
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{}';
        }
        foreach ($form['elements'] ?? [] as $idx => $element) {
            if (($element['type'] ?? '') === 'Label' && ($element['name'] ?? '') === 'ModuleVersionInfo') {
                $form['elements'][$idx]['caption'] = sprintf(
                    'Installierte Modulversion: %s (Build %d)',
                    self::MODULE_VERSION,
                    self::MODULE_BUILD
                );
            }
            if (($element['type'] ?? '') === 'Label' && ($element['name'] ?? '') === 'DebugLogPreview') {
                $buf = '';
                try {
                    $buf = (string) $this->ReadAttributeString('DebugLogBuffer');
                } catch (Throwable) {
                    $buf = '';
                }
                if ($buf === '') {
                    try {
                        $buf = (string) $this->GetValue('DebugLog');
                    } catch (Throwable) {
                        $buf = '';
                    }
                }
                if ($buf === '') {
                    $preview = $this->ReadPropertyBoolean('Debug')
                        ? '(noch leer — nach Aktion / „Debug-Test“ hier und in Variable Debug-Log sichtbar)'
                        : '(Debug aus — Checkbox aktivieren, Übernehmen, dann Debug-Test)';
                } else {
                    $lines = preg_split("/\r\n|\n|\r/", $buf) ?: [];
                    $preview = implode("\n", array_slice($lines, -12));
                }
                $form['elements'][$idx]['caption'] = "Debug-Vorschau:\n" . $preview;
            }
        }

        return json_encode($form, JSON_UNESCAPED_UNICODE);
    }

    public function ForwardData(string $JSONString): string
    {
        // Legacy: no dataflow parent/child — keep empty for IPSModuleStrict compatibility
        return '';
    }

    /**
     * Called by child modules (soft parent).
     */
    public function DispatchCommand(string $commandJson): string
    {
        $command = json_decode($commandJson, true);
        if (!is_array($command)) {
            return json_encode(['ok' => false, 'error' => 'Ungültiger Befehl'], JSON_UNESCAPED_UNICODE);
        }
        $this->debugLog('Command', 'action=' . (string) ($command['action'] ?? '') . ' device=' . (string) ($command['deviceId'] ?? ''));
        try {
            $result = $this->handleChildCommand($command);
            $this->debugLog('Command', 'OK action=' . (string) ($command['action'] ?? ''));

            return json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $this->SetValue('LastError', $e->getMessage());
            $this->debugLog('Command', 'ERROR: ' . $e->getMessage());

            return json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function TestConnection(): string
    {
        try {
            $client = $this->createClient();
            $result = $client->discover();
            $count = count($result['devices']);
            $this->SetValue('Reachable', true);
            $this->SetValue('LastError', '');
            $this->SetValue('DeviceCount', $count);
            $this->SetStatus(self::IS_ACTIVE);
            $this->debugLog('Connection', 'OK — ' . $count . ' device(s)');

            return 'OK — ' . $count . ' Gerät(e) gefunden';
        } catch (Throwable $e) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(self::IS_UNREACHABLE);
            $this->debugLog('Connection', 'ERROR: ' . $e->getMessage());

            return 'Fehler: ' . $e->getMessage();
        }
    }

    public function ScanAndCreateDevices(): string
    {
        try {
            $devices = $this->refreshDeviceCache();
            $created = 0;
            $updated = 0;
            $skipped = 0;
            foreach ($devices as $deviceId => $deviceData) {
                $model = GardenaSmartDevices::extractModelNumber($deviceData);
                $info = GardenaSmartDevices::info($model);
                $moduleId = GardenaSmartDevices::moduleIdForKind($info['kind']);
                if ($moduleId === null) {
                    $skipped++;
                    continue;
                }
                $existing = $this->findChildByDeviceId($deviceId);
                $name = GardenaSmartDevices::extractDisplayName($deviceData, $info['name']);
                if ($existing > 0) {
                    IPS_SetName($existing, $name);
                    IPS_SetParent($existing, $this->InstanceID);
                    IPS_SetProperty($existing, 'GatewayInstanceID', $this->InstanceID);
                    IPS_SetProperty($existing, 'DeviceId', $deviceId);
                    IPS_SetProperty($existing, 'ModelNumber', $model);
                    IPS_SetProperty($existing, 'Generation', $info['generation']);
                    IPS_SetProperty($existing, 'ValveCount', $info['valves']);
                    IPS_ApplyChanges($existing);
                    if ($moduleId === GardenaSmartGuids::VALVE && (int) ($info['generation'] ?? 1) >= 2) {
                        $this->ensureValveScheduleInstance($existing, $name);
                    }
                    $updated++;
                    continue;
                }
                $iid = IPS_CreateInstance($moduleId);
                IPS_SetName($iid, $name);
                IPS_SetParent($iid, $this->InstanceID);
                IPS_SetProperty($iid, 'GatewayInstanceID', $this->InstanceID);
                IPS_SetProperty($iid, 'DeviceId', $deviceId);
                IPS_SetProperty($iid, 'ModelNumber', $model);
                IPS_SetProperty($iid, 'Generation', $info['generation']);
                IPS_SetProperty($iid, 'ValveCount', $info['valves']);
                IPS_ApplyChanges($iid);
                if ($moduleId === GardenaSmartGuids::VALVE && (int) ($info['generation'] ?? 1) >= 2) {
                    $this->ensureValveScheduleInstance($iid, $name);
                }
                $created++;
            }
            $this->pushStateToChildren($devices);
            $this->rebuildScheduleOverview($devices);
            $this->rebuildUsageOverview();

            return sprintf(
                'Scan OK — neu: %d, aktualisiert: %d, übersprungen: %d',
                $created,
                $updated,
                $skipped
            );
        } catch (Throwable $e) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(self::IS_UNREACHABLE);

            return 'Fehler: ' . $e->getMessage();
        }
    }

    public function UpdateValues(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Reachable', false);
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }
        if (!$this->hasValidConfig()) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', 'Host oder Passwort fehlt');
            $this->SetStatus(self::IS_INVALID);

            return;
        }
        if ($this->isWssBusy()) {
            $this->debugLog('Update', 'übersprungen — WSS gerade belegt (z. B. Zeitplan-Schreiben)');

            return;
        }
        $error = $this->runDiscoverAndPush(25);
        if ($error !== null) {
            // Schedule read/write leaves websocketd briefly flaky — one soft retry
            $this->debugLog('Update', 'erster Versuch fehlgeschlagen, Retry: ' . $error);
            usleep(700000);
            if ($this->isWssBusy()) {
                $this->SetSummary($this->buildSummary());

                return;
            }
            $error = $this->runDiscoverAndPush(25);
        }
        if ($error !== null) {
            // Keep previous online state if we were reachable; only record soft error
            // Hard unreachable only when we never had a successful session recently
            $this->SetValue('LastError', $error);
            $this->debugLog('Update', 'ERROR: ' . $error);
            if (!$this->GetValue('Reachable')) {
                $this->SetStatus(self::IS_UNREACHABLE);
            } else {
                // Stay ACTIVE — transient poll failure after schedule ops is common
                $this->debugLog('Update', 'Status bleibt aktiv (vorübergehender Poll-Fehler)');
                $this->markWssBusy(20);
            }
        }
        $this->SetSummary($this->buildSummary());
    }

    /**
     * Discover + push. Returns null on success, error message on failure.
     */
    private function runDiscoverAndPush(int $busySeconds): ?string
    {
        try {
            $this->markWssBusy($busySeconds);
            $devices = $this->refreshDeviceCache();
            $this->pushStateToChildren($devices);
            $this->rebuildScheduleOverview($devices);
            $this->rebuildUsageOverview();
            $this->SetValue('Reachable', true);
            $this->SetValue('LastError', '');
            $this->SetValue('DeviceCount', count($devices));
            $this->SetStatus(self::IS_ACTIVE);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            $this->clearWssBusy();
        }
    }

    /**
     * Discover devices and return one device payload for a child pull/sync.
     * JSON: {ok:bool, error?:string, device?:object}
     */
    public function FetchDeviceData(string $deviceId): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return json_encode(['ok' => false, 'error' => 'Gateway inaktiv'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        if (!$this->hasValidConfig()) {
            return json_encode(['ok' => false, 'error' => 'Host oder Passwort fehlt'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        if ($deviceId === '') {
            return json_encode(['ok' => false, 'error' => 'deviceId fehlt'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        // Wait out cooldown after previous schedule write/read
        for ($wait = 0; $wait < 12 && $this->isWssBusy(); $wait++) {
            usleep(500000);
        }
        if ($this->isWssBusy()) {
            return json_encode(['ok' => false, 'error' => 'Gateway gerade belegt — bitte kurz warten und erneut laden'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        try {
            $this->markWssBusy(45);
            $devices = $this->refreshDeviceCache();
            if (!isset($devices[$deviceId]) || !is_array($devices[$deviceId])) {
                return json_encode(['ok' => false, 'error' => 'Gerät nicht im Discover gefunden'], JSON_UNESCAPED_UNICODE) ?: '{}';
            }
            $this->pushStateToChildren($devices);
            $this->rebuildScheduleOverview($devices);
            try {
                $this->rebuildUsageOverview();
            } catch (Throwable $e) {
                $this->debugLog('FetchDeviceData', 'Usage-Übersicht übersprungen: ' . $e->getMessage());
            }
            $this->markGatewayOnline(count($devices));

            return json_encode([
                'ok' => true,
                'device' => $devices[$deviceId],
            ], JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"error":"JSON-Fehler"}';
        } catch (Throwable $e) {
            // Do not flip instance to unreachable — schedule pull failure is operational, not offline
            $this->SetValue('LastError', $e->getMessage());
            $this->debugLog('FetchDeviceData', 'ERROR: ' . $e->getMessage());

            return json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) ?: '{}';
        } finally {
            // Cooldown so timer poll does not race a recovering websocketd
            $this->markWssBusy(15);
        }
    }

    private function markGatewayOnline(int $deviceCount): void
    {
        $this->SetValue('Reachable', true);
        $this->SetValue('LastError', '');
        $this->SetValue('DeviceCount', $deviceCount);
        $this->SetStatus(self::IS_ACTIVE);
    }

    /**
     * Called by children after device schedule save or property change.
     */
    public function RefreshScheduleOverview(): void
    {
        try {
            $raw = $this->ReadAttributeString('DeviceCache');
            $devices = json_decode($raw, true);
            if (is_array($devices)) {
                $this->rebuildScheduleOverview($devices);
            }
        } catch (Throwable) {
            // ignore
        }
    }

    public function RefreshUsageOverview(): void
    {
        $this->rebuildUsageOverview();
    }

    public function ClearDebugLog(): string
    {
        try {
            $this->WriteAttributeString('DebugLogBuffer', '');
        } catch (Throwable) {
            // ignore
        }
        try {
            $this->SetValue('DebugLog', '');
        } catch (Throwable) {
            // ignore
        }

        return 'OK — Debug-Log geleert';
    }

    public function WriteDebugTest(): string
    {
        // Persist checkbox if user forgot ApplyChanges
        if (!$this->ReadPropertyBoolean('Debug')) {
            @IPS_SetProperty($this->InstanceID, 'Debug', true);
        }
        $this->appendDebugLine('Test', 'Debug-Log OK — ' . date('Y-m-d H:i:s'));
        $debugVarId = @$this->GetIDForIdent('DebugLog');
        if (is_int($debugVarId) && $debugVarId > 0) {
            IPS_SetHidden($debugVarId, false);
        }

        return 'OK — Debug-Eintrag geschrieben (Variable Debug-Log + Formularvorschau nach Neuöffnen)';
    }

    public function ResetFlowPresetsToDefaults(): string
    {
        $json = GardenaSmartWaterUsage::defaultCatalogJson();
        @IPS_SetProperty($this->InstanceID, 'FlowPresets', $json);
        @IPS_ApplyChanges($this->InstanceID);

        return 'OK — Basis-Presets wiederhergestellt (eigene Einträge entfernt)';
    }

    public function GetVisualizationTile(): string
    {
        $payload = $this->collectUsagePayload();
        $htmlPath = __DIR__ . '/web/usage-tile.html';
        $cssPath = __DIR__ . '/web/usage-tile.css';
        $jsPath = __DIR__ . '/web/usage-tile.js';
        if (!is_file($htmlPath) || !is_file($cssPath) || !is_file($jsPath)) {
            return '<div>Verbrauchs-Kachel fehlt</div>';
        }
        $html = (string) file_get_contents($htmlPath);
        $css = (string) file_get_contents($cssPath);
        $js = (string) file_get_contents($jsPath);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        if ($json === false) {
            $json = '{}';
        }

        return str_replace(
            ['{{INLINE_CSS}}', '{{INLINE_JS}}', '{{INITIAL_JSON}}'],
            [$css, $js, $json],
            $html
        );
    }

    /** @param array<string, mixed> $command */
    private function handleChildCommand(array $command): mixed
    {
        $action = (string) ($command['action'] ?? '');
        $deviceId = (string) ($command['deviceId'] ?? '');
        if ($deviceId === '') {
            throw new RuntimeException('deviceId fehlt');
        }
        // Schedule writes keep one WSS session; valve start/stop should stay short-lived
        $busySec = match ($action) {
            'writeSchedulesGen2' => 45,
            'startValve', 'stopValve', 'powerOn', 'powerOff' => 8,
            default => 20,
        };
        $this->markWssBusy($busySec);
        try {
            $client = $this->createClient();
            $generation = (int) ($command['generation'] ?? 2);
            $valveId = (int) ($command['valveId'] ?? 0);
            $duration = (int) ($command['duration'] ?? 1800);

            $replies = match ($action) {
                'startValve' => $generation >= 2
                    ? $client->startValveGen2($deviceId, $valveId, $duration)
                    : $client->setWateringTimerGen1($deviceId, $valveId, $duration),
                'stopValve' => $generation >= 2
                    ? $client->stopValveGen2($deviceId, $valveId)
                    : $client->setWateringTimerGen1($deviceId, $valveId, 0),
                'powerOn' => $client->setPowerTimer($deviceId, $duration > 0 ? $duration : 86400),
                'powerOff' => $client->setPowerTimer($deviceId, 0),
                'writeSchedulesGen2' => $client->writeGen2Schedules(
                    $deviceId,
                    is_array($command['rules'] ?? null) ? $command['rules'] : [],
                    (int) ($command['previousMaxSlot'] ?? -1)
                ),
                'clearSchedulesGen1' => $client->clearGen1ScheduleConfig($deviceId),
                'clearSunScheduleGen1' => $client->clearGen1SunScheduleConfig($deviceId),
                default => throw new RuntimeException('Unbekannte Aktion: ' . $action),
            };

            // Refresh after command (schedule writes already took long — softer refresh)
            if ($action === 'writeSchedulesGen2') {
                $this->debugLog('Schedule', 'active writes OK — settle + verify discover');
                // Brief settle, then read back from device so IPS matches reality
                $this->markWssBusy(8);
                try {
                    $this->rebuildUsageOverview();
                } catch (Throwable) {
                    // ignore
                }
                usleep(1200000);
                try {
                    $this->markWssBusy(30);
                    $devices = $this->refreshDeviceCache();
                    $this->pushStateToChildren($devices);
                    $this->rebuildScheduleOverview($devices);
                    $this->markGatewayOnline(count($devices));
                } catch (Throwable $e) {
                    // Write itself succeeded — do not mark gateway unreachable on verify glitch
                    $this->debugLog('Schedule', 'verify discover failed: ' . $e->getMessage());
                    $this->SetValue('LastError', 'Zeitplan geschrieben, Verify-Discover: ' . $e->getMessage());
                    $this->SetStatus(self::IS_ACTIVE);
                    $this->SetValue('Reachable', true);
                }
                // Short cooldown so poll can resume soon
                $this->markWssBusy(8);
            } else {
                // start/stop/power: return immediately — full discover would delay UI/device feedback
                $this->clearWssBusy();
            }

            return $replies;
        } catch (Throwable $e) {
            $this->debugLog('Command', 'failed action=' . $action . ': ' . $e->getMessage());
            // Short cooldown — do not leave gateway "busy" for a long time after connect crash
            $this->markWssBusy($action === 'writeSchedulesGen2' ? 6 : 5);
            throw $e;
        }
    }

    private function markWssBusy(int $seconds): void
    {
        $this->SetBuffer('WssBusyUntil', (string) (time() + max(1, $seconds)));
    }

    private function clearWssBusy(): void
    {
        $this->SetBuffer('WssBusyUntil', '0');
    }

    private function isWssBusy(): bool
    {
        try {
            return (int) $this->GetBuffer('WssBusyUntil') > time();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function refreshDeviceCache(): array
    {
        $client = $this->createClient();
        $result = $client->discover();
        $devices = $result['devices'];
        $this->WriteAttributeString('DeviceCache', json_encode($devices, JSON_UNESCAPED_UNICODE) ?: '{}');

        return $devices;
    }

    /** @param array<string, array<string, mixed>> $devices */
    private function pushStateToChildren(array $devices): void
    {
        foreach ([GardenaSmartGuids::VALVE, GardenaSmartGuids::POWER, GardenaSmartGuids::SENSOR] as $moduleId) {
            foreach (IPS_GetInstanceListByModuleID($moduleId) as $childId) {
                try {
                    $gatewayId = (int) IPS_GetProperty($childId, 'GatewayInstanceID');
                    $deviceId = (string) IPS_GetProperty($childId, 'DeviceId');
                } catch (Throwable) {
                    continue;
                }
                if ($gatewayId !== $this->InstanceID || $deviceId === '' || !isset($devices[$deviceId])) {
                    continue;
                }
                $json = json_encode($devices[$deviceId], JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    continue;
                }
                if ($moduleId === GardenaSmartGuids::VALVE) {
                    @GSVAL_ApplyDeviceState($childId, $json);
                } elseif ($moduleId === GardenaSmartGuids::POWER) {
                    @GSPWR_ApplyDeviceState($childId, $json);
                } else {
                    @GSSEN_ApplyDeviceState($childId, $json);
                }
            }
        }
    }

    /** @param array<string, array<string, mixed>> $devices */
    private function rebuildScheduleOverview(array $devices): void
    {
        $lines = ['Geräte-Zeitpläne (Master am Gateway/Cloud):'];
        $any = false;
        foreach ($devices as $deviceId => $data) {
            $model = GardenaSmartDevices::extractModelNumber($data);
            $info = GardenaSmartDevices::info($model);
            $name = GardenaSmartDevices::extractDisplayName($data, $info['name']);
            $generation = (int) ($info['generation'] ?? 1);
            if ($generation >= 2 && ($info['kind'] ?? '') === 'valve') {
                $rules = GardenaSmartSchedules::parseGen2Rules($data);
                $sched = GardenaSmartSchedules::formatRulesLines($rules);
            } else {
                $sched = GardenaSmartDevices::formatDeviceSchedules($data);
            }
            if ($sched === []) {
                continue;
            }
            $any = true;
            $lines[] = $name . ' (' . $deviceId . '):';
            foreach ($sched as $line) {
                $lines[] = '  · ' . $line;
            }
        }
        if (!$any) {
            $lines[] = '  · (keine Zeitpläne am Gerät)';
        }
        $deviceBlock = implode("\n", $lines);
        $this->SetValue('ScheduleOverview', $deviceBlock);
        $this->WriteAttributeString('DeviceScheduleBlock', $deviceBlock);
    }

    private function rebuildUsageOverview(): void
    {
        $payload = $this->collectUsagePayload();
        $lines = ['Wasserverbrauch'];
        $totals = $payload['totals'];
        $lines[] = sprintf(
            'Heute %s · Woche %s · Jahr %s · Gesamt %s',
            GardenaSmartWaterUsage::formatLiters((float) ($totals['today'] ?? 0)),
            GardenaSmartWaterUsage::formatLiters((float) ($totals['week'] ?? 0)),
            GardenaSmartWaterUsage::formatLiters((float) ($totals['year'] ?? 0)),
            GardenaSmartWaterUsage::formatLiters((float) ($totals['total'] ?? 0))
        );
        foreach ($payload['devices'] as $device) {
            foreach ($device['outlets'] ?? [] as $outlet) {
                $valve = (string) ($outlet['valveName'] ?: ($outlet['label'] ?: ('Ventil ' . ($outlet['side'] ?? '?'))));
                $lines[] = sprintf(
                    '%s — heute %s · Woche %s · Jahr %s · Gesamt %s',
                    $valve,
                    GardenaSmartWaterUsage::formatLiters((float) ($outlet['today'] ?? 0)),
                    GardenaSmartWaterUsage::formatLiters((float) ($outlet['week'] ?? 0)),
                    GardenaSmartWaterUsage::formatLiters((float) ($outlet['year'] ?? 0)),
                    GardenaSmartWaterUsage::formatLiters((float) ($outlet['total'] ?? 0))
                );
            }
        }
        $this->SetValue('UsageOverview', implode("\n", $lines));
    }

    /**
     * @return array{totals: array<string,float>, devices: list<array<string,mixed>>}
     */
    private function collectUsagePayload(): array
    {
        $totals = ['today' => 0.0, 'week' => 0.0, 'year' => 0.0, 'total' => 0.0, 'session' => 0.0];
        $devices = [];
        foreach (IPS_GetInstanceListByModuleID(GardenaSmartGuids::VALVE) as $childId) {
            try {
                if ((int) IPS_GetProperty($childId, 'GatewayInstanceID') !== $this->InstanceID) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            $valveCount = 1;
            try {
                $valveCount = max(1, (int) IPS_GetProperty($childId, 'ValveCount'));
            } catch (Throwable) {
                // keep 1
            }
            $activeOutlets = [];
            // Prefer live export (resolves preset → l/h); fall back to stored properties
            $exported = null;
            try {
                if (function_exists('GSVAL_GetUsageExport')) {
                    $exported = GSVAL_GetUsageExport($childId);
                }
            } catch (Throwable) {
                $exported = null;
            }
            if (is_array($exported) && isset($exported['outlets']) && is_array($exported['outlets'])) {
                foreach ($exported['outlets'] as $outlet) {
                    if (!is_array($outlet) || empty($outlet['enabled'])) {
                        continue;
                    }
                    $activeOutlets[] = $outlet;
                    foreach (['today', 'week', 'year', 'total', 'session'] as $k) {
                        $totals[$k] = round($totals[$k] + (float) ($outlet[$k] ?? 0), 3);
                    }
                }
            } else {
                foreach (['A', 'B'] as $idx => $side) {
                    if ($idx >= $valveCount) {
                        break;
                    }
                    try {
                        $enabled = (bool) IPS_GetProperty($childId, 'Outlet' . $side . 'Enabled');
                        $lph = (float) IPS_GetProperty($childId, 'Outlet' . $side . 'LitersPerHour');
                    } catch (Throwable) {
                        continue;
                    }
                    if (!$enabled || $lph <= 0) {
                        continue;
                    }
                    $outlet = [
                        'side' => $side,
                        'enabled' => true,
                        'label' => (string) @IPS_GetProperty($childId, 'Outlet' . $side . 'Label'),
                        'length' => (string) @IPS_GetProperty($childId, 'Outlet' . $side . 'Length'),
                        'pressure' => (string) @IPS_GetProperty($childId, 'Outlet' . $side . 'Pressure'),
                        'litersPerHour' => $lph,
                        'open' => false,
                        'valveName' => '',
                        'today' => 0.0,
                        'week' => 0.0,
                        'year' => 0.0,
                        'total' => 0.0,
                        'session' => 0.0,
                    ];
                    try {
                        $openId = @IPS_GetObjectIDByIdent('Valve' . $side . 'Open', $childId);
                        if ($openId) {
                            $outlet['open'] = (bool) GetValue($openId);
                        }
                        $nameId = @IPS_GetObjectIDByIdent('Valve' . $side . 'Name', $childId);
                        if ($nameId) {
                            $outlet['valveName'] = (string) GetValue($nameId);
                        }
                        foreach (['Today' => 'today', 'Week' => 'week', 'Year' => 'year', 'Total' => 'total', 'Session' => 'session'] as $suffix => $key) {
                            $vid = @IPS_GetObjectIDByIdent('Usage' . $side . $suffix, $childId);
                            if ($vid) {
                                $outlet[$key] = (float) GetValue($vid);
                            }
                        }
                    } catch (Throwable) {
                        // ignore missing vars on old instances
                    }
                    $activeOutlets[] = $outlet;
                    foreach (['today', 'week', 'year', 'total', 'session'] as $k) {
                        $totals[$k] = round($totals[$k] + (float) ($outlet[$k] ?? 0), 3);
                    }
                }
            }
            if ($activeOutlets === []) {
                continue;
            }
            $devices[] = [
                'instanceId' => $childId,
                'name' => IPS_GetName($childId),
                'deviceId' => (string) @IPS_GetProperty($childId, 'DeviceId'),
                'outlets' => $activeOutlets,
            ];
        }

        return ['totals' => $totals, 'devices' => $devices];
    }

    private function ensureValveScheduleInstance(int $valveInstanceId, string $valveName): void
    {
        if ($valveInstanceId <= 0) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(GardenaSmartGuids::VALVE_SCHEDULE) as $schedId) {
            try {
                if ((int) IPS_GetProperty($schedId, 'ValveInstanceID') === $valveInstanceId) {
                    IPS_SetParent($schedId, $this->InstanceID);
                    IPS_SetName($schedId, $valveName . ' Zeitplan');
                    IPS_ApplyChanges($schedId);

                    return;
                }
            } catch (Throwable) {
                continue;
            }
        }
        try {
            $schedId = IPS_CreateInstance(GardenaSmartGuids::VALVE_SCHEDULE);
            IPS_SetName($schedId, $valveName . ' Zeitplan');
            IPS_SetParent($schedId, $this->InstanceID);
            IPS_SetProperty($schedId, 'ValveInstanceID', $valveInstanceId);
            IPS_ApplyChanges($schedId);
        } catch (Throwable $e) {
            $this->debugLog('Scan', 'Zeitplan-Kachel nicht angelegt: ' . $e->getMessage());
        }
    }

    private function findChildByDeviceId(string $deviceId): int
    {
        foreach ([GardenaSmartGuids::VALVE, GardenaSmartGuids::POWER, GardenaSmartGuids::SENSOR] as $moduleId) {
            foreach (IPS_GetInstanceListByModuleID($moduleId) as $childId) {
                try {
                    if ((int) IPS_GetProperty($childId, 'GatewayInstanceID') !== $this->InstanceID) {
                        continue;
                    }
                    if ((string) IPS_GetProperty($childId, 'DeviceId') === $deviceId) {
                        return $childId;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return 0;
    }

    private function ensureFlowPresetsSeeded(): void
    {
        $raw = '';
        try {
            $raw = trim((string) $this->ReadPropertyString('FlowPresets'));
        } catch (Throwable) {
            $raw = '';
        }
        $decoded = json_decode($raw !== '' ? $raw : '[]', true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $catalog = GardenaSmartWaterUsage::normalizeCatalog($decoded);
        $json = json_encode($catalog, JSON_UNESCAPED_UNICODE) ?: GardenaSmartWaterUsage::defaultCatalogJson();
        // Persist when empty or when legacy customs-only list was expanded with builtins
        if ($raw === '' || $raw === '[]' || $json !== $raw) {
            // Only auto-write when empty or when we had to prepend builtins (legacy)
            $needsWrite = ($raw === '' || $raw === '[]');
            if (!$needsWrite && is_array($decoded)) {
                $hadBuiltin = false;
                foreach ($decoded as $row) {
                    if (is_array($row) && str_starts_with(trim((string) ($row['id'] ?? '')), 'builtin:')) {
                        $hadBuiltin = true;
                        break;
                    }
                }
                $needsWrite = !$hadBuiltin && $decoded !== [];
            }
            if ($needsWrite) {
                @IPS_SetProperty($this->InstanceID, 'FlowPresets', $json);
            }
        }
    }

    private function createClient(): GardenaSmartClient
    {
        $gateway = $this;
        $logger = function (string $topic, string $message) use ($gateway): void {
            $gateway->debugLog($topic, $message);
        };

        return new GardenaSmartClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyBoolean('TlsInsecure'),
            12,
            $this->isDebugEnabled() ? $logger : null
        );
    }

    private function isDebugEnabled(): bool
    {
        try {
            if ($this->ReadPropertyBoolean('Debug')) {
                return true;
            }
        } catch (Throwable) {
            // ignore
        }
        // Fallback: unsaved form may have set property via IPS_SetProperty
        try {
            return (bool) IPS_GetProperty($this->InstanceID, 'Debug');
        } catch (Throwable) {
            return false;
        }
    }

    private function debugLog(string $topic, string $message): void
    {
        $this->SendDebug($topic, $message, 0);
        if (!$this->isDebugEnabled()) {
            return;
        }
        $this->appendDebugLine($topic, $message);
    }

    private function appendDebugLine(string $topic, string $message): void
    {
        $line = date('H:i:s') . ' [' . $topic . '] ' . $message;
        try {
            @IPS_LogMessage('GardenaSmartGateway', $line);
        } catch (Throwable) {
            // ignore
        }
        $prev = '';
        try {
            $prev = (string) $this->ReadAttributeString('DebugLogBuffer');
        } catch (Throwable) {
            $prev = '';
        }
        $text = trim($prev . "\n" . $line);
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        if (count($lines) > 200) {
            $lines = array_slice($lines, -200);
        }
        $text = implode("\n", $lines);
        if (strlen($text) > 30000) {
            $text = substr($text, -30000);
        }
        try {
            $this->WriteAttributeString('DebugLogBuffer', $text);
        } catch (Throwable) {
            // ignore
        }
        try {
            $this->SetValue('DebugLog', $text);
        } catch (Throwable $e) {
            $this->SendDebug('DebugLog', 'SetValue failed: ' . $e->getMessage(), 0);
        }
        $debugVarId = @$this->GetIDForIdent('DebugLog');
        if (is_int($debugVarId) && $debugVarId > 0) {
            @IPS_SetHidden($debugVarId, false);
        }
    }

    private function hasValidConfig(): bool
    {
        return trim($this->ReadPropertyString('Host')) !== ''
            && trim($this->ReadPropertyString('Password')) !== '';
    }

    private function configureTimer(): void
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->hasValidConfig()) {
            $this->SetTimerInterval('Update', 0);

            return;
        }
        $sec = max(self::UPDATE_MIN_SEC, $this->ReadPropertyInteger('UpdateIntervalSeconds'));
        $this->SetTimerInterval('Update', $sec * 1000);
    }

    private function updateInstanceStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }
        if (!$this->hasValidConfig()) {
            $this->SetStatus(self::IS_INVALID);

            return;
        }
        $this->SetStatus(self::IS_ACTIVE);
    }

    private function buildSummary(): string
    {
        $host = trim($this->ReadPropertyString('Host'));

        return $host !== '' ? $host : 'kein Host';
    }
}
