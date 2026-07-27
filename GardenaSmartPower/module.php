<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartDevices.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartSchedules.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartChildTrait.php';

class GardenaSmartPower extends IPSModuleStrict
{
    use GardenaSmartChildTrait;

    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 3;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('GatewayInstanceID', 0);
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('ModelNumber', '');
        $this->RegisterPropertyInteger('Generation', 1);
        $this->RegisterPropertyInteger('ValveCount', 0);
        $this->RegisterPropertyInteger('DefaultDurationSec', 86400);

        $this->RegisterVariableBoolean('Online', 'Online', '', 1);
        $this->RegisterVariableBoolean('OutputOn', 'Ausgang ein', '', 10);
        $this->RegisterVariableInteger('PowerTimer', 'Power-Timer (s)', '', 11);
        $this->RegisterVariableString('DeviceSchedules', 'Geräte-Zeitpläne', '', 20);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 999);
        $this->EnableAction('OutputOn');

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
        if (!is_array($data)) {
            return;
        }
        $online = GardenaSmartDevices::fieldValue($data['connection_status']['0']['online'] ?? null);
        if (is_bool($online)) {
            $this->SetValue('Online', $online);
        }
        $timer = GardenaSmartDevices::fieldValue($data['lemonbeat']['0']['power_timer'] ?? null);
        if (is_numeric($timer)) {
            $this->SetValue('PowerTimer', (int) $timer);
            $this->SetValue('OutputOn', ((int) $timer) !== 0);
        }
        $lines = GardenaSmartDevices::formatDeviceSchedules($data);
        $raw = $data['lemonbeat']['0']['sun_schedule_config']['vo'] ?? null;
        if (is_string($raw) && $raw !== '' && $lines === []) {
            $lines[] = 'sun_schedule_config vorhanden (' . strlen($raw) . ' bytes, nur Gardena-App)';
        }
        $this->SetValue('DeviceSchedules', $lines === [] ? '(keine)' : implode("\n", $lines));
    }

    public function GetVisualizationTile(): string
    {
        return $this->buildVisualizationHtml(__DIR__, 'GSPWR', [
            'kind' => 'power',
            'name' => IPS_GetName($this->InstanceID),
            'online' => (bool) $this->GetValue('Online'),
            'outputOn' => (bool) $this->GetValue('OutputOn'),
            'deviceSchedules' => (string) $this->GetValue('DeviceSchedules'),
            'scheduleWritable' => false,
            'scheduleHint' => 'Gen1 Power: Zeitpläne nur in der Gardena-App bearbeitbar (Binärformat). IPS zeigt den aktuellen Stand.',
            'instanceId' => $this->InstanceID,
        ]);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'OutputOn' || $Ident === 'SetPower') {
            $this->SetPower((bool) $Value);

            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    public function SetPower(bool $on): string
    {
        $duration = $this->ReadPropertyInteger('DefaultDurationSec');
        if ($duration <= 0) {
            $duration = 86400;
        }
        $result = $this->sendCommandToGateway([
            'action' => $on ? 'powerOn' : 'powerOff',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
            'duration' => $duration,
        ]);
        if (empty($result['ok'])) {
            return 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt');
        }
        $this->SetValue('OutputOn', $on);

        return 'OK';
    }

    public function ClearDeviceSchedules(): string
    {
        $result = $this->sendCommandToGateway([
            'action' => 'clearSunScheduleGen1',
            'deviceId' => $this->ReadPropertyString('DeviceId'),
        ]);
        if (empty($result['ok'])) {
            return 'Fehler: ' . (string) ($result['error'] ?? 'unbekannt');
        }
        $this->SetValue('DeviceSchedules', '(keine)');
        $this->notifyGatewaySchedules();

        return 'OK — Sonnen-Zeitplan am Gerät gelöscht';
    }
}
