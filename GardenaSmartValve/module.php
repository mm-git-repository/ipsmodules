<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartDevices.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartSchedules.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartChildTrait.php';

class GardenaSmartValve extends IPSModuleStrict
{
    use GardenaSmartChildTrait;

    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 6;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('GatewayInstanceID', 0);
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('ModelNumber', '');
        $this->RegisterPropertyInteger('Generation', 2);
        $this->RegisterPropertyInteger('ValveCount', 2);
        $this->RegisterPropertyInteger('DefaultDurationSec', 1800);
        $this->RegisterPropertyString('DeviceScheduleRules', '[]');

        $this->RegisterAttributeString('DeviceAppSchedules', '');
        $this->RegisterAttributeString('ScheduleLastSavedBy', '');
        $this->RegisterAttributeString('ScheduleLastSavedAt', '');

        $this->ensureProfiles();
        $this->RegisterVariableBoolean('Online', 'Online', '', 1);
        $this->RegisterVariableInteger('Battery', 'Batterie', 'GSVAL.Battery', 2);
        $this->RegisterVariableFloat('Temperature', 'Temperatur', 'GSVAL.Temp', 3);
        $this->RegisterVariableBoolean('ValveAOpen', 'Ventil A offen', '', 10);
        $this->RegisterVariableBoolean('ValveBOpen', 'Ventil B offen', '', 11);
        $this->RegisterVariableString('ValveAName', 'Ventil A Name', '', 12);
        $this->RegisterVariableString('ValveBName', 'Ventil B Name', '', 13);
        $this->RegisterVariableString('DeviceSchedules', 'Geräte-Zeitpläne', '', 20);
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
        $generation = $this->ReadPropertyInteger('Generation');
        $rules = $this->loadDeviceScheduleRules();
        $initial = [
            'name' => IPS_GetName($this->InstanceID),
            'online' => (bool) $this->GetValue('Online'),
            'battery' => (int) $this->GetValue('Battery'),
            'temperature' => (float) $this->GetValue('Temperature'),
            'valveCount' => $valveCount,
            'valveA' => [
                'name' => (string) $this->GetValue('ValveAName'),
                'open' => (bool) $this->GetValue('ValveAOpen'),
            ],
            'valveB' => [
                'name' => (string) $this->GetValue('ValveBName'),
                'open' => (bool) $this->GetValue('ValveBOpen'),
            ],
            'scheduleRules' => $rules,
            'scheduleWritable' => GardenaSmartSchedules::supportsDeviceScheduleWrite($generation, 'valve'),
            'scheduleMaxSlots' => GardenaSmartSchedules::GEN2_MAX_SLOTS,
            'scheduleLastSavedBy' => $this->ReadAttributeString('ScheduleLastSavedBy'),
            'scheduleLastSavedAt' => $this->ReadAttributeString('ScheduleLastSavedAt'),
            'defaultDuration' => $this->ReadPropertyInteger('DefaultDurationSec'),
            'instanceId' => $this->InstanceID,
        ];

        return $this->buildVisualizationHtml(__DIR__, 'GSVAL', $initial);
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
        parent::RequestAction($Ident, $Value);
    }

    public function StartValve(int $valveId, int $durationSec = 0): string
    {
        if ($durationSec <= 0) {
            $durationSec = $this->ReadPropertyInteger('DefaultDurationSec');
        }
        $result = $this->sendCommandToGateway([
            'action' => 'startValve',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'generation' => $this->ReadPropertyInteger('Generation'),
            'valveId' => $valveId,
            'duration' => $durationSec,
        ]);
        if (empty($result['ok'])) {
            return 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt');
        }
        $this->SetValue($valveId === 0 ? 'ValveAOpen' : 'ValveBOpen', true);

        return 'OK';
    }

    public function StopValve(int $valveId): string
    {
        $result = $this->sendCommandToGateway([
            'action' => 'stopValve',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'generation' => $this->ReadPropertyInteger('Generation'),
            'valveId' => $valveId,
        ]);
        if (empty($result['ok'])) {
            return 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt');
        }
        $this->SetValue($valveId === 0 ? 'ValveAOpen' : 'ValveBOpen', false);

        return 'OK';
    }

    /**
     * Form-Button: Liste zuerst übernehmen, dann vom Property ans Gerät schreiben.
     */
    public function PushDeviceSchedules(): string
    {
        return $this->SaveDeviceSchedules($this->ReadPropertyString('DeviceScheduleRules'));
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
        $this->saveDeviceScheduleRules($rules);
        $result = $this->sendCommandToGateway([
            'action' => 'writeSchedulesGen2',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'rules' => $rules,
        ]);
        if (empty($result['ok'])) {
            return 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt');
        }
        $this->WriteAttributeString('ScheduleLastSavedBy', 'IPS');
        $this->WriteAttributeString('ScheduleLastSavedAt', date('c'));
        $lines = $this->deviceScheduleLines($rules);
        $text = $lines === [] ? '(keine)' : implode("\n", $lines);
        $this->SetValue('DeviceSchedules', $text);
        $this->WriteAttributeString('DeviceAppSchedules', $text);
        $this->notifyGatewaySchedules();

        return 'OK — Zeitpläne am Gerät gespeichert (' . count($rules) . '/' . GardenaSmartSchedules::GEN2_MAX_SLOTS . ')';
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
            $this->SetValue('ValveAOpen', ((int) $t1) > 0);
        }
        if (is_numeric($t2)) {
            $this->SetValue('ValveBOpen', ((int) $t2) > 0);
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
    }
}
