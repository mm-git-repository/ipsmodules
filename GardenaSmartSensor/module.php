<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartDevices.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartChildTrait.php';

class GardenaSmartSensor extends IPSModuleStrict
{
    use GardenaSmartChildTrait;

    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 2;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('GatewayInstanceID', 0);
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('ModelNumber', '');
        $this->RegisterPropertyInteger('Generation', 1);
        $this->RegisterPropertyInteger('ValveCount', 0);

        $this->ensureProfiles();
        $this->RegisterVariableBoolean('Online', 'Online', '', 1);
        $this->RegisterVariableInteger('Battery', 'Batterie', 'GSSEN.Battery', 2);
        $this->RegisterVariableInteger('SoilMoisture', 'Bodenfeuchte', 'GSSEN.Moisture', 10);
        $this->RegisterVariableFloat('Temperature', 'Temperatur', 'GSSEN.Temp', 11);
        $this->RegisterVariableInteger('Light', 'Licht', '', 12);
        $this->RegisterVariableInteger('RfLinkQuality', 'RF-Qualität', '', 13);
        $this->RegisterVariableBoolean('FrostWarning', 'Frostwarnung', '', 14);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 999);

        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
        $this->SetStatus($this->getGatewayInstanceId() > 0 ? 102 : 104);
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
        $lb = $data['lemonbeat']['0'] ?? [];
        if (!is_array($lb)) {
            $lb = [];
        }

        $online = GardenaSmartDevices::fieldValue($data['connection_status']['0']['online'] ?? null);
        if (is_bool($online)) {
            $this->SetValue('Online', $online);
        }

        $battery = GardenaSmartDevices::fieldValue($lb['battery_level'] ?? null);
        if (is_numeric($battery)) {
            $this->SetValue('Battery', (int) round((float) $battery));
        }

        $model = $this->ReadPropertyString('ModelNumber');
        $moistureKey = $model === '19040' ? 'soil_moisture' : 'soil_humidity';
        $moisture = GardenaSmartDevices::fieldValue($lb[$moistureKey] ?? null);
        if (is_numeric($moisture)) {
            $this->SetValue('SoilMoisture', (int) $moisture);
        }

        $tempKey = $model === '19040' ? 'soil_temperature' : 'ambient_temperature';
        $temp = GardenaSmartDevices::fieldValue($lb[$tempKey] ?? null);
        if (is_numeric($temp)) {
            $this->SetValue('Temperature', (float) $temp);
        }

        $light = GardenaSmartDevices::fieldValue($lb['light'] ?? null);
        if (is_numeric($light)) {
            $this->SetValue('Light', (int) $light);
        }

        $rf = GardenaSmartDevices::fieldValue($lb['rf_link_quality'] ?? null);
        if (is_numeric($rf)) {
            $this->SetValue('RfLinkQuality', (int) $rf);
        }

        $frost = GardenaSmartDevices::fieldValue($lb['frost_warning'] ?? null);
        if (is_numeric($frost)) {
            $this->SetValue('FrostWarning', ((int) $frost) !== 0);
        }
    }

    public function GetVisualizationTile(): string
    {
        return $this->buildVisualizationHtml(__DIR__, 'GSSEN', [
            'kind' => 'sensor',
            'name' => IPS_GetName($this->InstanceID),
            'online' => (bool) $this->GetValue('Online'),
            'battery' => (int) $this->GetValue('Battery'),
            'moisture' => (int) $this->GetValue('SoilMoisture'),
            'temperature' => (float) $this->GetValue('Temperature'),
            'light' => (int) $this->GetValue('Light'),
            'frost' => (bool) $this->GetValue('FrostWarning'),
            'instanceId' => $this->InstanceID,
        ]);
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('GSSEN.Battery')) {
            IPS_CreateVariableProfile('GSSEN.Battery', 1);
            IPS_SetVariableProfileText('GSSEN.Battery', '', ' %');
            IPS_SetVariableProfileValues('GSSEN.Battery', 0, 100, 1);
        }
        if (!IPS_VariableProfileExists('GSSEN.Moisture')) {
            IPS_CreateVariableProfile('GSSEN.Moisture', 1);
            IPS_SetVariableProfileText('GSSEN.Moisture', '', ' %');
            IPS_SetVariableProfileValues('GSSEN.Moisture', 0, 100, 1);
        }
        if (!IPS_VariableProfileExists('GSSEN.Temp')) {
            IPS_CreateVariableProfile('GSSEN.Temp', 2);
            IPS_SetVariableProfileText('GSSEN.Temp', '', ' °C');
            IPS_SetVariableProfileDigits('GSSEN.Temp', 1);
        }
    }
}
