<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartDevices.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartChildTrait.php';

class GardenaSmartPower extends IPSModuleStrict
{
    use GardenaSmartChildTrait;

    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 1;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('ModelNumber', '');
        $this->RegisterPropertyInteger('Generation', 1);
        $this->RegisterPropertyInteger('ValveCount', 0);
        $this->RegisterPropertyInteger('DefaultDurationSec', 86400);
        $this->RegisterPropertyBoolean('ScheduleEnabled', false);
        $this->RegisterPropertyString('ScheduleRules', '[]');
        $this->RegisterAttributeString('LastDesired', '');

        $this->RegisterVariableBoolean('Online', 'Online', '', 1);
        $this->RegisterVariableBoolean('OutputOn', 'Ausgang ein', '', 10);
        $this->RegisterVariableInteger('PowerTimer', 'Power-Timer (s)', '', 11);
        $this->RegisterVariableString('DeviceSchedules', 'Geräte-App-Zeitpläne', '', 20);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 999);
        $this->EnableAction('OutputOn');

        $this->RegisterTimer('Schedule', 0, 'GSPWR_RunSchedule($_IPS[\'TARGET\']);');
        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
        $this->SetStatus($this->GetParentOrZero() > 0 ? 102 : 104);
        $this->SetTimerInterval('Schedule', $this->ReadPropertyBoolean('ScheduleEnabled') ? 60000 : 0);
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

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'connect',
            'moduleIDs' => [GardenaSmartGuids::GATEWAY],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function GetVisualizationTile(): string
    {
        return $this->buildVisualizationHtml(__DIR__, 'GSPWR', [
            'kind' => 'power',
            'name' => IPS_GetName($this->InstanceID),
            'online' => (bool) $this->GetValue('Online'),
            'outputOn' => (bool) $this->GetValue('OutputOn'),
            'deviceSchedules' => (string) $this->GetValue('DeviceSchedules'),
            'ipsSchedules' => $this->activeScheduleLines($this->loadScheduleRules()),
            'instanceId' => $this->InstanceID,
        ]);
    }

    public function ReceiveData(string $JSONString): string
    {
        $buffer = $this->parseReceiveBuffer($JSONString);
        if ($buffer === null || ($buffer['type'] ?? '') !== 'deviceState') {
            return '';
        }
        if (($buffer['deviceId'] ?? '') !== $this->ReadPropertyString('DeviceId')) {
            return '';
        }
        $data = $buffer['data'] ?? null;
        if (!is_array($data)) {
            return '';
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
            $lines[] = 'sun_schedule_config vorhanden (' . strlen($raw) . ' bytes b64)';
        }
        $this->SetValue('DeviceSchedules', $lines === [] ? '(keine)' : implode("\n", $lines));

        return '';
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

    public function RunSchedule(): void
    {
        if (!$this->ReadPropertyBoolean('ScheduleEnabled')) {
            return;
        }
        $want = false;
        foreach ($this->loadScheduleRules() as $rule) {
            if (is_array($rule) && $this->isNowInRule($rule, time())) {
                $want = true;
                break;
            }
        }
        $last = $this->ReadAttributeString('LastDesired');
        $prev = $last === '' ? null : ($last === '1');
        if ($prev === $want) {
            return;
        }
        $this->SetPower($want);
        $this->WriteAttributeString('LastDesired', $want ? '1' : '0');
    }
}
