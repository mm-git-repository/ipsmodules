<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartDevices.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartSchedules.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartWaterUsage.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartChildTrait.php';

class GardenaSmartValve extends IPSModuleStrict
{
    use GardenaSmartChildTrait;

    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 20;
    /** Default manual start + new schedule entry duration (30 min). */
    private const DEFAULT_WATERING_DURATION_SEC = 1800;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('GatewayInstanceID', 0);
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('ModelNumber', '');
        $this->RegisterPropertyInteger('Generation', 2);
        $this->RegisterPropertyInteger('ValveCount', 2);
        // Kept for compatibility; no longer shown in form — used as default watering window
        $this->RegisterPropertyInteger('DefaultDurationSec', self::DEFAULT_WATERING_DURATION_SEC);
        $this->RegisterPropertyString('DeviceScheduleRules', '[]');

        foreach (['A', 'B'] as $side) {
            // Preset ID only in UI; Enabled/Label/Length/Pressure/LPH kept for legacy instances + gateway export cache
            $this->RegisterPropertyBoolean('Outlet' . $side . 'Enabled', false);
            $this->RegisterPropertyString('Outlet' . $side . 'Preset', '');
            $this->RegisterPropertyString('Outlet' . $side . 'Label', '');
            $this->RegisterPropertyString('Outlet' . $side . 'Length', '');
            $this->RegisterPropertyString('Outlet' . $side . 'Pressure', '');
            $this->RegisterPropertyFloat('Outlet' . $side . 'LitersPerHour', 0.0);
        }

        $this->RegisterAttributeString('DeviceAppSchedules', '');
        $this->RegisterAttributeString('ScheduleLastSavedBy', '');
        $this->RegisterAttributeString('ScheduleLastSavedAt', '');
        $this->RegisterAttributeString('UsageState', '{}');

        $this->ensureProfiles();
        $this->RegisterVariableBoolean('Online', 'Online', '', 1);
        $this->RegisterVariableInteger('Battery', 'Batterie', 'GSVAL.Battery', 2);
        $this->RegisterVariableFloat('Temperature', 'Temperatur', 'GSVAL.Temp', 3);
        $this->RegisterVariableBoolean('ValveAOpen', 'Ventil A offen', '', 10);
        $this->RegisterVariableBoolean('ValveBOpen', 'Ventil B offen', '', 11);
        $this->RegisterVariableString('ValveAName', 'Ventil A Name', '', 12);
        $this->RegisterVariableString('ValveBName', 'Ventil B Name', '', 13);
        $this->RegisterVariableString('DeviceSchedules', 'Geräte-Zeitpläne', '', 20);

        foreach (['A' => 30, 'B' => 40] as $side => $base) {
            $this->RegisterVariableFloat('Usage' . $side . 'Today', 'Verbrauch ' . $side . ' heute', 'GSVAL.Liter', $base);
            $this->RegisterVariableFloat('Usage' . $side . 'Week', 'Verbrauch ' . $side . ' Woche', 'GSVAL.Liter', $base + 1);
            $this->RegisterVariableFloat('Usage' . $side . 'Year', 'Verbrauch ' . $side . ' Jahr', 'GSVAL.Liter', $base + 2);
            $this->RegisterVariableFloat('Usage' . $side . 'Total', 'Verbrauch ' . $side . ' Gesamt', 'GSVAL.Liter', $base + 3);
            $this->RegisterVariableFloat('Usage' . $side . 'Session', 'Verbrauch ' . $side . ' Session', 'GSVAL.Liter', $base + 4);
        }

        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 999);

        $this->EnableAction('ValveAOpen');
        $this->EnableAction('ValveBOpen');

        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
        $this->SetStatus($this->getGatewayInstanceId() > 0 ? 102 : 104);
        $this->notifyGatewaySchedules();
        $this->notifyGatewayUsage();
        $this->SetSummary($this->ReadPropertyString('DeviceId'));
    }

    public function GetConfigurationForm(): string
    {
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
            if (($element['type'] ?? '') === 'Select' && in_array($element['name'] ?? '', ['OutletAPreset', 'OutletBPreset'], true)) {
                $form['elements'][$idx]['options'] = $this->presetSelectOptions();
            }
            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === 'DeviceScheduleRules') {
                [$start, $end] = $this->defaultScheduleWindow();
                foreach ($form['elements'][$idx]['columns'] ?? [] as $cIdx => $col) {
                    $name = (string) ($col['name'] ?? '');
                    if ($name === 'start') {
                        $form['elements'][$idx]['columns'][$cIdx]['add'] = $start;
                    }
                    if ($name === 'end') {
                        $form['elements'][$idx]['columns'][$cIdx]['add'] = $end;
                    }
                }
            }
        }
        if ($this->ReadPropertyInteger('ValveCount') < 2) {
            $form['elements'] = array_values(array_filter(
                $form['elements'] ?? [],
                static fn ($el) => ($el['name'] ?? '') !== 'OutletBPreset'
            ));
        }

        return json_encode($form, JSON_UNESCAPED_UNICODE);
    }

    public function ApplyDeviceState(string $deviceJson): void
    {
        $data = json_decode($deviceJson, true);
        if (is_array($data)) {
            $this->applyDeviceData($data);
        }
    }

    public function GetVisualizationTile(): string
    {
        $valveCount = max(1, $this->ReadPropertyInteger('ValveCount'));
        $valves = [];
        foreach (['A', 'B'] as $idx => $side) {
            if ($idx >= $valveCount) {
                break;
            }
            $name = trim((string) $this->GetValue('Valve' . $side . 'Name'));
            $valves[] = [
                'id' => $idx,
                'side' => $side,
                'name' => $name !== '' ? $name : ('Ventil ' . $side),
                'open' => (bool) $this->GetValue('Valve' . $side . 'Open'),
            ];
        }
        $initial = [
            'name' => IPS_GetName($this->InstanceID),
            'online' => (bool) $this->GetValue('Online'),
            'battery' => (int) $this->GetValue('Battery'),
            'temperature' => (float) $this->GetValue('Temperature'),
            'valveCount' => $valveCount,
            'valves' => $valves,
            'defaultDuration' => $this->defaultWateringDurationSec(),
            'instanceId' => $this->InstanceID,
        ];

        return $this->buildVisualizationHtml(__DIR__, 'GSVAL', $initial);
    }

    /**
     * Payload for GardenaSmartValveSchedule visualization tile.
     *
     * @return array<string, mixed>
     */
    public function GetScheduleTileData(): array
    {
        $generation = $this->ReadPropertyInteger('Generation');
        $valveCount = max(1, $this->ReadPropertyInteger('ValveCount'));
        $valveNames = [];
        foreach (['A', 'B'] as $idx => $side) {
            if ($idx >= $valveCount) {
                break;
            }
            $name = trim((string) $this->GetValue('Valve' . $side . 'Name'));
            $valveNames[] = [
                'id' => $idx,
                'side' => $side,
                'name' => $name !== '' ? $name : ('Ventil ' . $side),
            ];
        }

        return [
            'name' => IPS_GetName($this->InstanceID),
            'valveCount' => $valveCount,
            'valveNames' => $valveNames,
            'scheduleRules' => $this->loadDeviceScheduleRules(),
            'scheduleWritable' => GardenaSmartSchedules::supportsDeviceScheduleWrite($generation, 'valve'),
            'scheduleMaxSlots' => GardenaSmartSchedules::GEN2_MAX_SLOTS,
            'scheduleLastSavedBy' => $this->ReadAttributeString('ScheduleLastSavedBy'),
            'scheduleLastSavedAt' => $this->ReadAttributeString('ScheduleLastSavedAt'),
            'defaultScheduleStart' => $this->defaultScheduleWindow()[0],
            'defaultScheduleEnd' => $this->defaultScheduleWindow()[1],
            'instanceId' => $this->InstanceID,
        ];
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ValveAOpen') {
            if ((bool) $Value) {
                $this->StartValve(0, 0);
            } else {
                $this->StopValve(0);
            }

            return;
        }
        if ($Ident === 'ValveBOpen') {
            if ((bool) $Value) {
                $this->StartValve(1, 0);
            } else {
                $this->StopValve(1);
            }

            return;
        }
        if ($Ident === 'StartValve') {
            $payload = $this->decodeActionPayload($Value);
            echo $this->StartValve((int) ($payload['valveId'] ?? 0), (int) ($payload['duration'] ?? 0));

            return;
        }
        if ($Ident === 'StopValve') {
            $payload = $this->decodeActionPayload($Value);
            echo $this->StopValve((int) ($payload['valveId'] ?? 0));

            return;
        }
        if ($Ident === 'StartValveA') {
            $this->StartValve(0, (int) $Value);

            return;
        }
        if ($Ident === 'StartValveB') {
            $this->StartValve(1, (int) $Value);

            return;
        }
        if ($Ident === 'StopValveA') {
            $this->StopValve(0);

            return;
        }
        if ($Ident === 'StopValveB') {
            $this->StopValve(1);

            return;
        }
        if ($Ident === 'SaveDeviceSchedules') {
            $json = is_string($Value) ? $Value : json_encode($Value, JSON_UNESCAPED_UNICODE);
            echo $this->SaveDeviceSchedules($json ?: null);

            return;
        }
        if ($Ident === 'PullDeviceSchedules') {
            echo $this->PullDeviceSchedules();

            return;
        }
        if ($Ident === 'ResetWaterUsage') {
            echo $this->ResetWaterUsage();

            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    /** @return array<string, mixed> */
    private function decodeActionPayload(mixed $Value): array
    {
        if (is_array($Value)) {
            return $Value;
        }
        if (is_string($Value) && $Value !== '') {
            $decoded = json_decode($Value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            if (is_numeric($Value)) {
                return ['valveId' => (int) $Value];
            }
        }
        if (is_numeric($Value)) {
            return ['valveId' => (int) $Value];
        }

        return [];
    }

    public function StartValve(int $valveId, int $durationSec = 0): string
    {
        if ($durationSec <= 0) {
            $durationSec = $this->defaultWateringDurationSec();
        }
        // Optimistic local state so visualization can refresh immediately
        $this->SetValue($valveId === 0 ? 'ValveAOpen' : 'ValveBOpen', true);
        $this->trackValveOpenState($valveId, true);
        $result = $this->sendCommandToGateway([
            'action' => 'startValve',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'generation' => $this->ReadPropertyInteger('Generation'),
            'valveId' => $valveId,
            'duration' => $durationSec,
        ]);
        if (empty($result['ok'])) {
            $this->SetValue($valveId === 0 ? 'ValveAOpen' : 'ValveBOpen', false);
            $this->trackValveOpenState($valveId, false);

            return json_encode([
                'ok' => false,
                'open' => false,
                'message' => 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt'),
            ], JSON_UNESCAPED_UNICODE) ?: '{"ok":false}';
        }

        return json_encode([
            'ok' => true,
            'open' => true,
            'message' => 'OK',
        ], JSON_UNESCAPED_UNICODE) ?: '{"ok":true,"open":true}';
    }

    public function StopValve(int $valveId): string
    {
        $this->SetValue($valveId === 0 ? 'ValveAOpen' : 'ValveBOpen', false);
        $this->trackValveOpenState($valveId, false);
        $result = $this->sendCommandToGateway([
            'action' => 'stopValve',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'generation' => $this->ReadPropertyInteger('Generation'),
            'valveId' => $valveId,
        ]);
        if (empty($result['ok'])) {
            // Keep closed locally; device may still be open — next poll corrects
            return json_encode([
                'ok' => false,
                'open' => false,
                'message' => 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt'),
            ], JSON_UNESCAPED_UNICODE) ?: '{"ok":false}';
        }

        return json_encode([
            'ok' => true,
            'open' => false,
            'message' => 'OK',
        ], JSON_UNESCAPED_UNICODE) ?: '{"ok":true,"open":false}';
    }

    public function PushDeviceSchedules(): string
    {
        return $this->SaveDeviceSchedules($this->ReadPropertyString('DeviceScheduleRules'));
    }

    /**
     * Discover device schedules and overwrite local IPS editor data.
     * Returns JSON: {ok, message, rules?}
     */
    public function PullDeviceSchedules(): string
    {
        $gatewayId = $this->getGatewayInstanceId();
        $deviceId = $this->ReadPropertyString('DeviceId');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            return $this->pullSchedulesResult(false, 'Fehler: Kein Gateway zugewiesen');
        }
        if ($deviceId === '') {
            return $this->pullSchedulesResult(false, 'Fehler: DeviceId fehlt');
        }

        $raw = @GSGW_FetchDeviceData($gatewayId, $deviceId);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            return $this->pullSchedulesResult(
                false,
                'Fehler: ' . (string) ($decoded['error'] ?? 'Discover fehlgeschlagen')
            );
        }
        $data = $decoded['device'] ?? null;
        if (!is_array($data)) {
            return $this->pullSchedulesResult(false, 'Fehler: Keine Gerätedaten');
        }

        $this->applyDeviceData($data);

        $generation = $this->ReadPropertyInteger('Generation');
        $rules = [];
        if ($generation >= 2) {
            $rules = GardenaSmartSchedules::parseGen2Rules($data);
            // Explicit overwrite — also clears local rows when device has none
            $this->saveDeviceScheduleRules($rules);
            // Do NOT IPS_ApplyChanges here: breaks visualization RequestAction and can surface as cryptic errors
            $lines = $this->deviceScheduleLines($rules);
        } else {
            $lines = GardenaSmartDevices::formatDeviceSchedules($data);
        }

        $text = $lines === [] ? '(keine)' : implode("\n", $lines);
        $this->SetValue('DeviceSchedules', $text);
        $this->WriteAttributeString('DeviceAppSchedules', $text);
        $this->WriteAttributeString('ScheduleLastSavedBy', 'Gerät');
        $this->WriteAttributeString('ScheduleLastSavedAt', date('c'));
        $this->notifyGatewaySchedules();

        $message = $generation >= 2
            ? ('OK — ' . count($rules) . ' Zeitplan(e) vom Gerät nach IPS übernommen (überschrieben)')
            : 'OK — Gerätezustand aktualisiert (Gen1-Zeitpläne nur Anzeige)';

        return $this->pullSchedulesResult(true, $message, $rules);
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    private function pullSchedulesResult(bool $ok, string $message, array $rules = []): string
    {
        $payload = [
            'ok' => $ok,
            'message' => $message,
            'rules' => $rules,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"message":"JSON-Fehler","rules":[]}';
    }

    public function SaveDeviceSchedules(mixed $rulesJson = null): string
    {
        $generation = $this->ReadPropertyInteger('Generation');
        if (!GardenaSmartSchedules::supportsDeviceScheduleWrite($generation, 'valve')) {
            return 'Fehler: Geräte-Zeitpläne können für Gen1 nur in der Gardena-App bearbeitet werden';
        }
        if (is_array($rulesJson)) {
            $rules = $rulesJson;
        } elseif (is_string($rulesJson) && $rulesJson !== '') {
            $rules = json_decode($rulesJson, true);
            if (!is_array($rules)) {
                return 'Fehler: Ungültige Zeitplan-Daten';
            }
        } else {
            $rules = $this->loadDeviceScheduleRules();
        }
        $normalized = GardenaSmartSchedules::normalizeRules($rules);
        if (!$normalized['ok']) {
            return 'Fehler: ' . $normalized['error'];
        }
        $rules = $normalized['rules'];
        $intendedFp = GardenaSmartSchedules::rulesFingerprint($rules);
        $previousMaxSlot = -1;
        foreach ($this->loadDeviceScheduleRules() as $prev) {
            if (is_array($prev) && isset($prev['slot'])) {
                $previousMaxSlot = max($previousMaxSlot, (int) $prev['slot']);
            }
        }
        $this->saveDeviceScheduleRules($rules);
        $result = $this->sendCommandToGateway([
            'action' => 'writeSchedulesGen2',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'rules' => $rules,
            'previousMaxSlot' => $previousMaxSlot,
        ]);
        if (empty($result['ok'])) {
            return 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt');
        }

        // Gateway has re-discovered and pushed device state — reload local copy
        $deviceRules = $this->loadDeviceScheduleRules();
        $deviceFp = GardenaSmartSchedules::rulesFingerprint($deviceRules);
        $lines = $this->deviceScheduleLines($deviceRules !== [] ? $deviceRules : $rules);
        $text = $lines === [] ? '(keine)' : implode("\n", $lines);
        $this->SetValue('DeviceSchedules', $text);
        $this->WriteAttributeString('DeviceAppSchedules', $text);
        $this->WriteAttributeString('ScheduleLastSavedBy', 'IPS');
        $this->WriteAttributeString('ScheduleLastSavedAt', date('c'));
        $this->notifyGatewaySchedules();

        if ($deviceFp !== '' && $intendedFp !== $deviceFp) {
            return 'Warnung — geschrieben, aber Gerät meldet andere Werte (Anzeige = Gerätezustand). '
                . 'App/Cloud kann verzögert nachziehen. Erneut „Jetzt aktualisieren“ am Gateway prüfen. '
                . '(' . count($deviceRules) . '/' . GardenaSmartSchedules::GEN2_MAX_SLOTS . ' Slots)';
        }

        return 'OK — Zeitpläne am Gerät gespeichert und verifiziert ('
            . count($deviceRules !== [] ? $deviceRules : $rules)
            . '/' . GardenaSmartSchedules::GEN2_MAX_SLOTS . ')';
    }

    public function ResetWaterUsage(): string
    {
        $state = $this->loadUsageState();
        foreach (['A', 'B'] as $side) {
            $key = strtolower($side);
            $valveState = is_array($state[$key] ?? null) ? $state[$key] : GardenaSmartWaterUsage::emptyValveState();
            $state[$key] = GardenaSmartWaterUsage::resetCounters($valveState);
            $this->writeUsageVariables($side, $state[$key]);
        }
        $this->saveUsageState($state);
        $this->notifyGatewayUsage();

        return 'OK — Verbrauchszähler zurückgesetzt';
    }

    /** @return array<string, mixed> */
    public function GetUsageExport(): array
    {
        return [
            'instanceId' => $this->InstanceID,
            'name' => IPS_GetName($this->InstanceID),
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'outlets' => $this->usageSnapshotForUi()['outlets'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyDeviceData(array $data): void
    {
        $online = GardenaSmartDevices::fieldValue($data['connection_status']['0']['online'] ?? null);
        if (is_bool($online)) {
            $this->SetValue('Online', $online);
        }

        $battery = GardenaSmartDevices::fieldValue($data['device']['0']['battery_level'] ?? null);
        if ($battery === null) {
            $battery = GardenaSmartDevices::fieldValue($data['lemonbeat']['0']['battery_level'] ?? null);
        }
        if (is_numeric($battery)) {
            $this->SetValue('Battery', (int) round((float) $battery));
        }

        $temp = GardenaSmartDevices::fieldValue($data['temperature']['0']['sensor_value'] ?? null);
        if ($temp === null) {
            $temp = GardenaSmartDevices::fieldValue($data['lemonbeat']['0']['ambient_temperature'] ?? null);
        }
        if (is_numeric($temp)) {
            $this->SetValue('Temperature', (float) $temp);
        }

        $generation = $this->ReadPropertyInteger('Generation');
        if ($generation >= 2) {
            $this->applyGen2Valves($data);
            $rules = GardenaSmartSchedules::parseGen2Rules($data);
            if ($rules !== []) {
                $this->saveDeviceScheduleRules($rules);
                $lines = GardenaSmartSchedules::formatRulesLines($rules);
            } else {
                $lines = GardenaSmartDevices::formatDeviceSchedules($data);
            }
        } else {
            $this->applyGen1Valves($data);
            $lines = GardenaSmartDevices::formatDeviceSchedules($data);
        }

        $text = $lines === [] ? '(keine)' : implode("\n", $lines);
        $this->SetValue('DeviceSchedules', $text);
        $this->WriteAttributeString('DeviceAppSchedules', $text);
    }

    /** @param array<string, mixed> $data */
    private function applyGen2Valves(array $data): void
    {
        $actuators = $data['actuator'] ?? [];
        if (!is_array($actuators)) {
            return;
        }
        foreach ([0 => 'ValveA', 1 => 'ValveB'] as $id => $prefix) {
            $act = $actuators[(string) $id] ?? null;
            if (!is_array($act)) {
                continue;
            }
            $name = GardenaSmartDevices::fieldValue($act['name'] ?? null);
            if (is_string($name)) {
                $this->SetValue($prefix . 'Name', $name);
            }
            $state = GardenaSmartDevices::fieldValue($act['state'] ?? null);
            $open = is_numeric($state) ? ((int) $state !== 0) : false;
            if (isset($data['timeslot']) && is_array($data['timeslot'])) {
                $running = false;
                foreach ($data['timeslot'] as $tid => $ts) {
                    if ($tid === '_urn' || !is_array($ts)) {
                        continue;
                    }
                    $st = (int) (GardenaSmartDevices::fieldValue($ts['state'] ?? null) ?? -1);
                    $actId = (int) (GardenaSmartDevices::fieldValue($ts['actuator'] ?? null) ?? -1);
                    if ($actId === $id && $st === 5) {
                        $running = true;
                        break;
                    }
                }
                $open = $running;
            }
            $this->SetValue($prefix . 'Open', $open);
            $this->trackValveOpenState($id, $open);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyGen1Valves(array $data): void
    {
        $lb = $data['lemonbeat']['0'] ?? [];
        if (!is_array($lb)) {
            return;
        }
        $t1 = GardenaSmartDevices::fieldValue($lb['watering_timer_1'] ?? null);
        $t2 = GardenaSmartDevices::fieldValue($lb['watering_timer_2'] ?? null);
        if (is_numeric($t1)) {
            $open = ((int) $t1) > 0;
            $this->SetValue('ValveAOpen', $open);
            $this->trackValveOpenState(0, $open);
        }
        if (is_numeric($t2)) {
            $open = ((int) $t2) > 0;
            $this->SetValue('ValveBOpen', $open);
            $this->trackValveOpenState(1, $open);
        }
    }

    private function trackValveOpenState(int $valveId, bool $open): void
    {
        $side = $valveId === 0 ? 'A' : 'B';
        if ($valveId === 1 && $this->ReadPropertyInteger('ValveCount') < 2) {
            return;
        }
        $outlet = $this->resolveOutlet($side);
        $state = $this->loadUsageState();
        $key = strtolower($side);
        $valveState = is_array($state[$key] ?? null) ? $state[$key] : GardenaSmartWaterUsage::emptyValveState();
        $state[$key] = GardenaSmartWaterUsage::tick(
            $valveState,
            $open,
            (float) $outlet['litersPerHour'],
            (bool) $outlet['enabled'],
            time()
        );
        $this->saveUsageState($state);
        $this->writeUsageVariables($side, $state[$key]);
        $this->notifyGatewayUsage();
    }

    /** @param array<string, mixed> $valveState */
    private function writeUsageVariables(string $side, array $valveState): void
    {
        $this->SetValue('Usage' . $side . 'Today', (float) ($valveState['day'] ?? 0));
        $this->SetValue('Usage' . $side . 'Week', (float) ($valveState['week'] ?? 0));
        $this->SetValue('Usage' . $side . 'Year', (float) ($valveState['year'] ?? 0));
        $this->SetValue('Usage' . $side . 'Total', (float) ($valveState['total'] ?? 0));
        $this->SetValue('Usage' . $side . 'Session', (float) ($valveState['sessionLiters'] ?? 0));
    }

    /** @return array<string, mixed> */
    private function loadUsageState(): array
    {
        $decoded = json_decode($this->ReadAttributeString('UsageState'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function saveUsageState(array $state): void
    {
        $this->WriteAttributeString('UsageState', json_encode($state, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /** @return array{outlets: list<array<string, mixed>>} */
    private function usageSnapshotForUi(): array
    {
        $outlets = [];
        $count = max(1, $this->ReadPropertyInteger('ValveCount'));
        foreach (['A', 'B'] as $idx => $side) {
            if ($idx >= $count) {
                break;
            }
            $outlet = $this->resolveOutlet($side);
            $outlets[] = [
                'side' => $side,
                'enabled' => (bool) $outlet['enabled'],
                'label' => (string) $outlet['label'],
                'length' => (string) $outlet['length'],
                'pressure' => (string) $outlet['pressure'],
                'litersPerHour' => (float) $outlet['litersPerHour'],
                'presetId' => (string) $outlet['presetId'],
                'open' => (bool) $this->GetValue('Valve' . $side . 'Open'),
                'valveName' => (string) $this->GetValue('Valve' . $side . 'Name'),
                'today' => (float) $this->GetValue('Usage' . $side . 'Today'),
                'week' => (float) $this->GetValue('Usage' . $side . 'Week'),
                'year' => (float) $this->GetValue('Usage' . $side . 'Year'),
                'total' => (float) $this->GetValue('Usage' . $side . 'Total'),
                'session' => (float) $this->GetValue('Usage' . $side . 'Session'),
            ];
        }

        return ['outlets' => $outlets];
    }

    private function defaultWateringDurationSec(): int
    {
        try {
            $sec = (int) $this->ReadPropertyInteger('DefaultDurationSec');
        } catch (Throwable) {
            $sec = self::DEFAULT_WATERING_DURATION_SEC;
        }

        return max(60, $sec > 0 ? $sec : self::DEFAULT_WATERING_DURATION_SEC);
    }

    /**
     * @return array{0: string, 1: string} start, end HH:MM
     */
    private function defaultScheduleWindow(): array
    {
        $startSec = 6 * 3600; // 06:00
        $endSec = $startSec + $this->defaultWateringDurationSec();
        if ($endSec >= 86400) {
            $endSec = 86400 - 60;
        }
        $fmt = static function (int $sec): string {
            $sec = max(0, $sec % 86400);
            return sprintf('%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
        };

        return [$fmt($startSec), $fmt($endSec)];
    }

    /**
     * Resolve outlet config from selected preset (source of truth).
     *
     * @return array{enabled:bool,presetId:string,label:string,length:string,pressure:string,litersPerHour:float}
     */
    private function resolveOutlet(string $side): array
    {
        $side = strtoupper($side) === 'B' ? 'B' : 'A';
        $presetId = trim($this->ReadPropertyString('Outlet' . $side . 'Preset'));
        if ($presetId !== '' && $presetId !== 'none' && $presetId !== 'builtin:custom') {
            foreach ($this->availablePresets() as $preset) {
                if ((string) ($preset['id'] ?? '') !== $presetId) {
                    continue;
                }
                $lph = max(0.0, (float) ($preset['litersPerHour'] ?? 0));

                return [
                    'enabled' => $lph > 0,
                    'presetId' => $presetId,
                    'label' => (string) ($preset['label'] ?? ''),
                    'length' => (string) ($preset['length'] ?? ''),
                    'pressure' => (string) ($preset['pressure'] ?? ''),
                    'litersPerHour' => $lph,
                ];
            }
        }

        // Legacy: older instances with "Benutzerdefiniert" + manuelle l/h
        $legacyEnabled = $this->ReadPropertyBoolean('Outlet' . $side . 'Enabled');
        $legacyLph = max(0.0, (float) $this->ReadPropertyFloat('Outlet' . $side . 'LitersPerHour'));
        if ($presetId === 'builtin:custom' && $legacyEnabled && $legacyLph > 0) {
            return [
                'enabled' => true,
                'presetId' => $presetId,
                'label' => $this->ReadPropertyString('Outlet' . $side . 'Label'),
                'length' => $this->ReadPropertyString('Outlet' . $side . 'Length'),
                'pressure' => $this->ReadPropertyString('Outlet' . $side . 'Pressure'),
                'litersPerHour' => $legacyLph,
            ];
        }

        return [
            'enabled' => false,
            'presetId' => $presetId,
            'label' => '',
            'length' => '',
            'pressure' => '',
            'litersPerHour' => 0.0,
        ];
    }

    /** @return list<array{id:string,label:string,length:string,pressure:string,litersPerHour:float}> */
    private function availablePresets(): array
    {
        $gatewayPresets = [];
        $gatewayId = $this->getGatewayInstanceId();
        if ($gatewayId > 0 && IPS_InstanceExists($gatewayId)) {
            try {
                $raw = (string) IPS_GetProperty($gatewayId, 'FlowPresets');
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $gatewayPresets = $decoded;
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return GardenaSmartWaterUsage::mergePresets($gatewayPresets);
    }

    /** @return list<array{caption:string,value:string}> */
    private function presetSelectOptions(): array
    {
        $options = [
            ['caption' => '— Kein Verbrauch —', 'value' => ''],
        ];
        foreach ($this->availablePresets() as $preset) {
            $id = (string) ($preset['id'] ?? '');
            if ($id === '' || $id === 'builtin:custom') {
                continue;
            }
            $cap = (string) ($preset['label'] ?? $id);
            $extra = [];
            $length = trim((string) ($preset['length'] ?? ''));
            $pressure = trim((string) ($preset['pressure'] ?? ''));
            $lph = (float) ($preset['litersPerHour'] ?? 0);
            if ($length !== '') {
                $extra[] = $length;
            }
            if ($pressure !== '') {
                $extra[] = $pressure;
            }
            if ($lph > 0) {
                $extra[] = rtrim(rtrim(number_format($lph, 1, ',', ''), '0'), ',') . ' l/h';
            }
            if ($extra !== []) {
                $cap .= ' (' . implode(', ', $extra) . ')';
            }
            $options[] = [
                'caption' => $cap,
                'value' => $id,
            ];
        }

        return $options;
    }

    private function notifyGatewayUsage(): void
    {
        $gatewayId = $this->getGatewayInstanceId();
        if ($gatewayId > 0 && IPS_InstanceExists($gatewayId)) {
            @GSGW_RefreshUsageOverview($gatewayId);
        }
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('GSVAL.Battery')) {
            IPS_CreateVariableProfile('GSVAL.Battery', 1);
            IPS_SetVariableProfileText('GSVAL.Battery', '', ' %');
            IPS_SetVariableProfileValues('GSVAL.Battery', 0, 100, 1);
        }
        if (!IPS_VariableProfileExists('GSVAL.Temp')) {
            IPS_CreateVariableProfile('GSVAL.Temp', 2);
            IPS_SetVariableProfileText('GSVAL.Temp', '', ' °C');
            IPS_SetVariableProfileDigits('GSVAL.Temp', 1);
        }
        if (!IPS_VariableProfileExists('GSVAL.Liter')) {
            IPS_CreateVariableProfile('GSVAL.Liter', 2);
            IPS_SetVariableProfileDigits('GSVAL.Liter', 2);
        }
        IPS_SetVariableProfileText('GSVAL.Liter', '', ' l');
    }
}
