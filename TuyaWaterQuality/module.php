<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/TuyaLocalClient.php';
require_once __DIR__ . '/libs/TuyaWaterQualityMapping.php';

class TuyaWaterQuality extends IPSModuleStrict
{
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 4;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_INVALID_CONFIG = 201;
    private const IS_UNREACHABLE = 202;

    private const UPDATE_INTERVAL_DEFAULT_SEC = 60;
    private const UPDATE_INTERVAL_MIN_SEC = 15;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('LocalKey', '');
        $this->RegisterPropertyString('ProtocolVersion', '3.3');
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_INTERVAL_DEFAULT_SEC);
        $this->RegisterPropertyString('DpMapping', TuyaWaterQualityMapping::DEFAULT_JSON);

        $this->ensureProfiles();
        $this->registerVariables();

        $this->RegisterTimer('Update', 0, 'TWQT_UpdateValues($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->ensureProfiles();
        $this->registerVariables();
        $this->ensureModuleVersionVariable();
        $this->syncModuleVersionVariable();
        $this->configureTimer();
        $this->updateInstanceStatus();
        $this->SetSummary($this->buildSummary());
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

    public function Refresh(): void
    {
        $this->UpdateValues();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        parent::RequestAction($Ident, $Value);
    }

    public function UpdateValues(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', 'Modul inaktiv');

            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        $localKey = trim($this->ReadPropertyString('LocalKey'));

        if ($host === '' || $deviceId === '' || $localKey === '') {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', 'Host, Device ID oder Local Key fehlt');
            $this->SetStatus(self::IS_INVALID_CONFIG);

            return;
        }

        $client = new TuyaLocalClient(
            $deviceId,
            $localKey,
            $this->ReadPropertyString('ProtocolVersion')
        );

        $result = $client->fetchStatus($host);
        if (!$result['ok']) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', $result['error']);
            $this->SetStatus(self::IS_UNREACHABLE);

            return;
        }

        $mapping = TuyaWaterQualityMapping::parse($this->ReadPropertyString('DpMapping'));
        $values = TuyaWaterQualityMapping::apply($result['dps'], $mapping);

        if ($values['ph'] !== null) {
            $this->SetValue('MeasuredPh', $values['ph']);
        }
        if ($values['orp'] !== null) {
            $this->SetValue('MeasuredOrp', (int) round($values['orp']));
        }
        if ($values['ec'] !== null) {
            $this->SetValue('MeasuredEc', $values['ec']);
        }
        if ($values['tds'] !== null) {
            $this->SetValue('MeasuredTds', $values['tds']);
        }
        if ($values['temperature'] !== null) {
            $this->SetValue('MeasuredWaterTemp', $values['temperature']);
        }

        $this->SetValue('Reachable', true);
        $this->SetValue('LastError', '');
        $this->SetValue('LastUpdate', time());
        $this->SetValue('RawDps', json_encode($result['dps'], JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->SetStatus(self::IS_ACTIVE);
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('TWQT.pH')) {
            IPS_CreateVariableProfile('TWQT.pH', 2);
            IPS_SetVariableProfileText('TWQT.pH', '', '');
            IPS_SetVariableProfileDigits('TWQT.pH', 2);
        }
        if (!IPS_VariableProfileExists('TWQT.mV')) {
            IPS_CreateVariableProfile('TWQT.mV', 1);
            IPS_SetVariableProfileText('TWQT.mV', '', ' mV');
        }
        if (!IPS_VariableProfileExists('TWQT.uScm')) {
            IPS_CreateVariableProfile('TWQT.uScm', 2);
            IPS_SetVariableProfileText('TWQT.uScm', '', ' µS/cm');
        }
        if (!IPS_VariableProfileExists('TWQT.ppm')) {
            IPS_CreateVariableProfile('TWQT.ppm', 2);
            IPS_SetVariableProfileText('TWQT.ppm', '', ' ppm');
        }
    }

    private function registerVariables(): void
    {
        $pos = 0;
        $textPres = '~TextBox';

        $this->RegisterVariableFloat('MeasuredPh', 'pH gemessen', 'TWQT.pH', $pos++);
        $this->RegisterVariableInteger('MeasuredOrp', 'ORP gemessen', 'TWQT.mV', $pos++);
        $this->RegisterVariableFloat('MeasuredEc', 'EC gemessen', 'TWQT.uScm', $pos++);
        $this->RegisterVariableFloat('MeasuredTds', 'TDS gemessen', 'TWQT.ppm', $pos++);
        $this->RegisterVariableFloat('MeasuredWaterTemp', 'Wassertemperatur gemessen', '~Temperature', $pos++);

        $this->RegisterVariableBoolean('Reachable', 'Erreichbar', '~Switch', $pos++);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', $pos++);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', $textPres, $pos++);
        $this->RegisterVariableString('RawDps', 'Roh-DPS (Debug)', $textPres, $pos++);

        $this->DisableAction('Reachable');
        $this->DisableAction('LastUpdate');
        $this->DisableAction('LastError');
        $this->DisableAction('RawDps');
    }

    private function variableExists(string $ident): bool
    {
        $vid = @IPS_GetVariableIDByName($ident, $this->InstanceID);

        return is_int($vid) && $vid > 0;
    }

    private function ensureModuleVersionVariable(): void
    {
        if ($this->variableExists('ModuleVersion')) {
            return;
        }

        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '~TextBox', 999);
        $this->DisableAction('ModuleVersion');
    }

    private function syncModuleVersionVariable(): void
    {
        if (!$this->variableExists('ModuleVersion')) {
            return;
        }

        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
    }

    private function configureTimer(): void
    {
        $interval = max(
            self::UPDATE_INTERVAL_MIN_SEC,
            (int) $this->ReadPropertyInteger('UpdateIntervalSeconds')
        );

        if ($this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Update', $interval * 1000);
        } else {
            $this->SetTimerInterval('Update', 0);
        }
    }

    private function updateInstanceStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        $localKey = trim($this->ReadPropertyString('LocalKey'));

        if ($host === '' || $deviceId === '' || $localKey === '') {
            $this->SetStatus(self::IS_INVALID_CONFIG);

            return;
        }

        $this->SetStatus(self::IS_ACTIVE);
    }

    private function buildSummary(): string
    {
        $host = trim($this->ReadPropertyString('Host'));

        return $host !== '' ? $host : 'Yieryi Sensor';
    }
}
