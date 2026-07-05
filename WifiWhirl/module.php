<?php

declare(strict_types=1);

require_once __DIR__ . '/WifiWhirlEntities.php';
require_once __DIR__ . '/libs/WifiWhirlHttpClient.php';

class WifiWhirl extends IPSModuleStrict
{
    private const LIBRARY_ID = '{C4D8A1E2-9F3B-4A5C-D6E7-8F9012345678}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 1;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_INVALID_HOST = 201;
    private const IS_UNREACHABLE = 202;

    private const UPDATE_INTERVAL_DEFAULT_SEC = 30;
    private const UPDATE_INTERVAL_MIN_SEC = 15;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $entityMapCache = null;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_INTERVAL_DEFAULT_SEC);

        $this->ensureProfiles();
        $this->registerAllVariables();

        $this->RegisterTimer('Update', 0, 'WWHL_UpdateValues($_IPS[\'TARGET\']);');
    }

    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        $data = json_decode($JSONData, true);
        if (!is_array($data) || !isset($data['configuration']) || !is_array($data['configuration'])) {
            return $JSONData;
        }

        foreach ($this->migrateDefaultConfiguration() as $key => $default) {
            if (!array_key_exists($key, $data['configuration'])) {
                $data['configuration'][$key] = $default;
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, bool|int|string> */
    private function migrateDefaultConfiguration(): array
    {
        return [
            'Active' => true,
            'Host' => '',
            'UpdateIntervalSeconds' => self::UPDATE_INTERVAL_DEFAULT_SEC,
        ];
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->ensureProfiles();
        $this->registerAllVariables();
        $this->ensureModuleVersionVariable();
        $this->syncModuleVersionVariable();
        $this->configureTimer();
        $this->updateInstanceStatus();
        $this->SetSummary($this->buildSummary());
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Reachable') {
            return;
        }

        $map = $this->entityMap();
        $payload = WifiWhirlEntities::commandPayload($Ident, $Value, $map);
        if ($payload === null) {
            $this->SendDebug(__FUNCTION__, 'Kein Kommando für Ident: ' . $Ident, 0);

            return;
        }

        if (!$this->sendCommandPayload($payload)) {
            $this->LogMessage('Steuerbefehl fehlgeschlagen: ' . $Ident, KL_ERROR);

            return;
        }

        if (isset($map[$Ident]) && $map[$Ident]['action']) {
            $this->SetValue($Ident, $Value);
        }

        IPS_Sleep(250);
        $this->UpdateValues();
    }

    public function UpdateValues(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Reachable', false);
            $this->updateInstanceStatus();

            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetValue('Reachable', false);
            $this->updateInstanceStatus();

            return;
        }

        $client = new WifiWhirlHttpClient($host);
        $payload = $client->getPollData();
        $usedFallback = false;

        if ($payload === null) {
            $payload = $client->getFallbackData();
            $usedFallback = $payload !== null;
        }

        if ($payload === null) {
            $this->SetValue('Reachable', false);
            $this->SendDebug(__FUNCTION__, 'WifiWhirl nicht erreichbar: ' . $host, 0);
            $this->updateInstanceStatus();

            return;
        }

        $this->applyPayload($payload);
        $this->SetValue('Reachable', true);
        if ($usedFallback) {
            $this->SendDebug(__FUNCTION__, 'Fallback /getstates/ + /gettemps/ verwendet', 0);
        }
        $this->updateInstanceStatus();
    }

    public function Refresh(): void
    {
        $this->UpdateValues();
    }

    public function ResetChlorineTimer(): string
    {
        return $this->runButtonCommand(WifiWhirlEntities::buttonCommand(9), 'Timer Chlor zurückgesetzt');
    }

    public function ResetFilterTimer(): string
    {
        return $this->runButtonCommand(WifiWhirlEntities::buttonCommand(10), 'Timer Filterwechsel zurückgesetzt');
    }

    public function ResetFilterCleanTimer(): string
    {
        return $this->runButtonCommand(WifiWhirlEntities::buttonCommand(24), 'Timer Filterreinigung zurückgesetzt');
    }

    public function ResetWaterChangeTimer(): string
    {
        return $this->runButtonCommand(WifiWhirlEntities::buttonCommand(25), 'Timer Wasserwechsel zurückgesetzt');
    }

    public function RestartModule(): string
    {
        return $this->runButtonCommand(WifiWhirlEntities::buttonCommand(6), 'WifiWhirl-Neustart gesendet');
    }

    private function runButtonCommand(array $payload, string $okMessage): string
    {
        if (!$this->sendCommandPayload($payload)) {
            return 'Fehler: Befehl konnte nicht gesendet werden';
        }

        IPS_Sleep(500);
        $this->UpdateValues();

        return $okMessage;
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(array $payload): void
    {
        $states = is_array($payload['states'] ?? null) ? $payload['states'] : [];
        $times = is_array($payload['times'] ?? null) ? $payload['times'] : [];
        $other = is_array($payload['other'] ?? null) ? $payload['other'] : [];
        $map = $this->entityMap();

        foreach ($map as $ident => $def) {
            if ($ident === 'Reachable') {
                continue;
            }

            $value = WifiWhirlEntities::resolveValue($def, $states, $times, $other);
            if ($value === null) {
                continue;
            }

            $this->SetValue($ident, $value);
        }
    }

    /** @param array<string, mixed> $payload */
    private function sendCommandPayload(array $payload): bool
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return false;
        }

        return (new WifiWhirlHttpClient($host))->sendCommand($payload);
    }

    private function registerAllVariables(): void
    {
        $pos = 0;
        $pres = $this->presentationProfiles();

        foreach (WifiWhirlEntities::definitions() as $def) {
            $profile = $pres[$def['profile']] ?? $def['profile'];
            $ident = $def['ident'];
            $name = $def['name'];

            match ($def['ipsType']) {
                0 => $this->RegisterVariableBoolean($ident, $name, $profile, $pos),
                1 => $this->RegisterVariableInteger($ident, $name, $profile, $pos),
                2 => $this->RegisterVariableFloat($ident, $name, $profile, $pos),
                default => $this->RegisterVariableString($ident, $name, $profile, $pos),
            };

            if ($def['action']) {
                $this->EnableAction($ident);
            }

            ++$pos;
        }

        $textPres = $pres['WWHL.Text'] ?? 'WWHL.Text';
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', $textPres, $pos);
    }

    private function ensureModuleVersionVariable(): void
    {
        $vid = @IPS_GetVariableIDByName('Modulversion', $this->InstanceID);
        if (is_int($vid) && $vid > 0) {
            return;
        }

        $pres = $this->presentationProfiles();
        $textPres = $pres['WWHL.Text'] ?? 'WWHL.Text';
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', $textPres, 999);
    }

    private function syncModuleVersionVariable(): void
    {
        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
    }

    private function configureTimer(): void
    {
        $interval = max(self::UPDATE_INTERVAL_MIN_SEC, (int) $this->ReadPropertyInteger('UpdateIntervalSeconds'));
        if ($this->ReadPropertyBoolean('Active') && trim($this->ReadPropertyString('Host')) !== '') {
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

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(self::IS_INVALID_HOST);

            return;
        }

        try {
            if (!$this->GetValue('Reachable')) {
                $this->SetStatus(self::IS_UNREACHABLE);

                return;
            }
        } catch (Throwable) {
            // Variable noch nicht angelegt
        }

        $this->SetStatus(self::IS_ACTIVE);
    }

    private function buildSummary(): string
    {
        $host = trim($this->ReadPropertyString('Host'));

        return $host !== '' ? $host : 'Host fehlt';
    }

    /** @return array<string, array<string, mixed>> */
    private function entityMap(): array
    {
        if ($this->entityMapCache === null) {
            $this->entityMapCache = WifiWhirlEntities::definitionMap();
        }

        return $this->entityMapCache;
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('WWHL.kWh')) {
            IPS_CreateVariableProfile('WWHL.kWh', 2);
            IPS_SetVariableProfileText('WWHL.kWh', '', ' kWh');
        }
        if (!IPS_VariableProfileExists('WWHL.W')) {
            IPS_CreateVariableProfile('WWHL.W', 2);
            IPS_SetVariableProfileText('WWHL.W', '', ' W');
        }
        if (!IPS_VariableProfileExists('WWHL.hours')) {
            IPS_CreateVariableProfile('WWHL.hours', 2);
            IPS_SetVariableProfileText('WWHL.hours', '', ' h');
        }
        if (!IPS_VariableProfileExists('WWHL.days')) {
            IPS_CreateVariableProfile('WWHL.days', 1);
            IPS_SetVariableProfileText('WWHL.days', '', ' Tage');
        }
        if (!IPS_VariableProfileExists('WWHL.dBm')) {
            IPS_CreateVariableProfile('WWHL.dBm', 1);
            IPS_SetVariableProfileText('WWHL.dBm', '', ' dBm');
        }
        if (!IPS_VariableProfileExists('WWHL.pH')) {
            IPS_CreateVariableProfile('WWHL.pH', 2);
            IPS_SetVariableProfileText('WWHL.pH', '', '');
            IPS_SetVariableProfileDigits('WWHL.pH', 1);
        }
        if (!IPS_VariableProfileExists('WWHL.mgL')) {
            IPS_CreateVariableProfile('WWHL.mgL', 2);
            IPS_SetVariableProfileText('WWHL.mgL', '', ' mg/L');
            IPS_SetVariableProfileDigits('WWHL.mgL', 1);
        }
        if (!IPS_VariableProfileExists('WWHL.Brightness')) {
            IPS_CreateVariableProfile('WWHL.Brightness', 1);
            IPS_SetVariableProfileText('WWHL.Brightness', '', '');
            IPS_SetVariableProfileValues('WWHL.Brightness', 0, 8, 1);
        }
        if (!IPS_VariableProfileExists('WWHL.Text')) {
            IPS_CreateVariableProfile('WWHL.Text', 3);
        }
    }

    /** @return array<string, string|array<string, string>> */
    private function presentationProfiles(): array
    {
        $usePresArray = false;
        if (function_exists('IPS_GetKernelVersion')) {
            $kv = IPS_GetKernelVersion();
            $usePresArray = is_string($kv) && $kv !== '' && version_compare($kv, '8.0', '>=');
        }

        if ($usePresArray) {
            return [
                '~Switch' => ['PROFILE' => '~Switch'],
                '~Temperature' => ['PROFILE' => '~Temperature'],
                '~UnixTimestamp' => ['PROFILE' => '~UnixTimestamp'],
                'WWHL.kWh' => ['PROFILE' => 'WWHL.kWh'],
                'WWHL.W' => ['PROFILE' => 'WWHL.W'],
                'WWHL.hours' => ['PROFILE' => 'WWHL.hours'],
                'WWHL.days' => ['PROFILE' => 'WWHL.days'],
                'WWHL.dBm' => ['PROFILE' => 'WWHL.dBm'],
                'WWHL.pH' => ['PROFILE' => 'WWHL.pH'],
                'WWHL.mgL' => ['PROFILE' => 'WWHL.mgL'],
                'WWHL.Brightness' => ['PROFILE' => 'WWHL.Brightness'],
                'WWHL.Text' => ['PROFILE' => 'WWHL.Text'],
            ];
        }

        return [
            '~Switch' => '~Switch',
            '~Temperature' => '~Temperature',
            '~UnixTimestamp' => '~UnixTimestamp',
            'WWHL.kWh' => 'WWHL.kWh',
            'WWHL.W' => 'WWHL.W',
            'WWHL.hours' => 'WWHL.hours',
            'WWHL.days' => 'WWHL.days',
            'WWHL.dBm' => 'WWHL.dBm',
            'WWHL.pH' => 'WWHL.pH',
            'WWHL.mgL' => 'WWHL.mgL',
            'WWHL.Brightness' => 'WWHL.Brightness',
            'WWHL.Text' => 'WWHL.Text',
        ];
    }
}
