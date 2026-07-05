<?php

declare(strict_types=1);

require_once __DIR__ . '/WifiWhirlEntities.php';
require_once __DIR__ . '/libs/WifiWhirlHttpClient.php';
require_once __DIR__ . '/libs/WifiWhirlAutomation.php';
require_once __DIR__ . '/libs/WifiWhirlRuleEditor.php';

class WifiWhirl extends IPSModuleStrict
{
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 8;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_INVALID_HOST = 201;
    private const IS_UNREACHABLE = 202;

    private const UPDATE_INTERVAL_DEFAULT_SEC = 30;
    private const UPDATE_INTERVAL_MIN_SEC = 15;

    private const AUTOMATION_INTERVAL_DEFAULT_SEC = 60;
    private const AUTOMATION_INTERVAL_MIN_SEC = 60;

    private const PV_THRESHOLD_DEFAULT_W = 2500;
    private const PV_ON_DELAY_DEFAULT_SEC = 300;
    private const PV_OFF_DELAY_DEFAULT_SEC = 180;
    private const PV_HYSTERESIS_DEFAULT_W = 200;

    private const MANUAL_OVERRIDE_IDENTS = ['Pump', 'Heater', 'Power', 'TargetTemperature'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $entityMapCache = null;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_INTERVAL_DEFAULT_SEC);

        $this->RegisterPropertyBoolean('AutomationEnabled', false);
        $this->RegisterPropertyInteger('AutomationIntervalSec', self::AUTOMATION_INTERVAL_DEFAULT_SEC);
        $this->RegisterPropertyString('AutomationPumpRules', '[]');
        $this->RegisterPropertyString('AutomationHeaterRules', '[]');
        $this->RegisterPropertyInteger('PvSurplusVar', 0);
        $this->RegisterPropertyInteger('PvThresholdW', self::PV_THRESHOLD_DEFAULT_W);
        $this->RegisterPropertyInteger('PvOnDelaySec', self::PV_ON_DELAY_DEFAULT_SEC);
        $this->RegisterPropertyInteger('PvOffDelaySec', self::PV_OFF_DELAY_DEFAULT_SEC);
        $this->RegisterPropertyInteger('PvHysteresisW', self::PV_HYSTERESIS_DEFAULT_W);

        $this->ensureProfiles();
        $this->registerAllVariables();

        $this->RegisterTimer('Update', 0, 'WWHL_UpdateValues($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Automation', 0, 'WWHL_RunAutomation($_IPS[\'TARGET\']);');

        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
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

        $this->migrateAutomationRules($data['configuration']);

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, bool|int|string> */
    private function migrateDefaultConfiguration(): array
    {
        return [
            'Active' => true,
            'Host' => '',
            'UpdateIntervalSeconds' => self::UPDATE_INTERVAL_DEFAULT_SEC,
            'AutomationEnabled' => false,
            'AutomationIntervalSec' => self::AUTOMATION_INTERVAL_DEFAULT_SEC,
            'AutomationPumpRules' => '[]',
            'AutomationHeaterRules' => '[]',
            'PvSurplusVar' => 0,
            'PvThresholdW' => self::PV_THRESHOLD_DEFAULT_W,
            'PvOnDelaySec' => self::PV_ON_DELAY_DEFAULT_SEC,
            'PvOffDelaySec' => self::PV_OFF_DELAY_DEFAULT_SEC,
            'PvHysteresisW' => self::PV_HYSTERESIS_DEFAULT_W,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function loadAutomationRules(): array
    {
        $pump = WifiWhirlAutomation::parsePumpRules($this->ReadPropertyString('AutomationPumpRules'));
        $heater = WifiWhirlAutomation::parseHeaterRules($this->ReadPropertyString('AutomationHeaterRules'));

        return WifiWhirlAutomation::mergeRuleLists($pump, $heater);
    }

    /** @param array<string, mixed> $configuration */
    private function migrateAutomationRules(array &$configuration): void
    {
        if (!isset($configuration['AutomationRules'])) {
            return;
        }

        $legacyRaw = $configuration['AutomationRules'];
        if ($this->isEmptyRuleList($configuration['AutomationPumpRules'] ?? '[]')
            && $this->isEmptyRuleList($configuration['AutomationHeaterRules'] ?? '[]')
        ) {
            $legacy = is_string($legacyRaw) ? json_decode($legacyRaw, true) : $legacyRaw;
            if (!is_array($legacy)) {
                $legacy = [];
            }

            $pumpRows = [];
            $heaterRows = [];
            foreach ($legacy as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $type = strtolower(trim((string) ($row['type'] ?? '')));
                unset($row['type']);
                if ($type === WifiWhirlAutomation::TYPE_PUMP) {
                    $pumpRows[] = $row;
                } elseif ($type === WifiWhirlAutomation::TYPE_HEATER) {
                    $heaterRows[] = $row;
                }
            }

            $configuration['AutomationPumpRules'] = json_encode($pumpRows, JSON_UNESCAPED_UNICODE);
            $configuration['AutomationHeaterRules'] = json_encode($heaterRows, JSON_UNESCAPED_UNICODE);
        }

        unset($configuration['AutomationRules']);
    }

    private function isEmptyRuleList(mixed $raw): bool
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            return true;
        }

        return !is_array($decoded) || $decoded === [];
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->ensureProfiles();
        $this->registerAllVariables();
        $this->ensureModuleVersionVariable();
        $this->syncModuleVersionVariable();
        $this->configureTimer();
        $this->configureAutomationTimer();
        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
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

    public function GetVisualizationTile(): string
    {
        $htmlPath = __DIR__ . '/web/automation-tile.html';
        $cssPath = __DIR__ . '/web/automation-tile.css';
        $jsPath = __DIR__ . '/web/automation-tile.js';

        $html = @file_get_contents($htmlPath);
        $css = @file_get_contents($cssPath);
        $js = @file_get_contents($jsPath);
        if (!is_string($html) || !is_string($css) || !is_string($js)) {
            return '<div>Automatisierungs-Editor nicht verfügbar</div>';
        }

        return str_replace(
            ['{{INLINE_CSS}}', '{{INLINE_JS}}'],
            [$css, $js],
            $html,
        );
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Reachable') {
            return;
        }

        if ($Ident === 'AutomationEditor') {
            $this->handleAutomationEditorRequest($Value);

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

        if ($this->ReadPropertyBoolean('AutomationEnabled') && in_array($Ident, self::MANUAL_OVERRIDE_IDENTS, true)) {
            $this->applyManualOverride($Ident);
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

    public function RunAutomation(): void
    {
        $this->clearExpiredManualOverrides(time());

        $surplusW = $this->readPvSurplusW();
        $this->SetValue('AutomationPvSurplus', $surplusW);

        if (!$this->ReadPropertyBoolean('AutomationEnabled')) {
            $this->SetValue('AutomationStatus', 'Automatisierung deaktiviert');
            $this->SetValue('AutomationPvGateOpen', false);
            $this->SetValue('AutomationPumpDesired', false);
            $this->SetValue('AutomationHeaterDesired', false);

            return;
        }

        if (!$this->ReadPropertyBoolean('Active') || trim($this->ReadPropertyString('Host')) === '') {
            $this->SetValue('AutomationStatus', 'Automatisierung: Modul inaktiv oder Host fehlt');

            return;
        }

        $rules = $this->loadAutomationRules();
        $now = new DateTimeImmutable('now');
        $nowUnix = (int) $now->getTimestamp();
        $pvVarId = (int) $this->ReadPropertyInteger('PvSurplusVar');

        $pvConfig = [
            'thresholdW' => (float) max(0, (int) $this->ReadPropertyInteger('PvThresholdW')),
            'onDelaySec' => max(0, (int) $this->ReadPropertyInteger('PvOnDelaySec')),
            'offDelaySec' => max(0, (int) $this->ReadPropertyInteger('PvOffDelaySec')),
            'hysteresisW' => (float) max(0, (int) $this->ReadPropertyInteger('PvHysteresisW')),
        ];

        $pvState = [
            'gateOpen' => $this->getBufferBool('AutoPvGateOpen'),
            'aboveSince' => $this->getBufferInt('AutoPvAboveSince'),
            'belowSince' => $this->getBufferInt('AutoPvBelowSince'),
        ];

        $result = WifiWhirlAutomation::evaluate(
            $rules,
            $surplusW,
            $now,
            $pvConfig,
            $pvState,
            $pvVarId > 0,
        );

        $this->SetBuffer('AutoPvGateOpen', $result['pvState']['gateOpen'] ? '1' : '0');
        $this->SetBuffer('AutoPvAboveSince', (string) $result['pvState']['aboveSince']);
        $this->SetBuffer('AutoPvBelowSince', (string) $result['pvState']['belowSince']);

        $this->SetValue('AutomationPvGateOpen', $result['pvGateOpen']);
        $this->SetValue('AutomationPumpDesired', $result['pump']);
        $this->SetValue('AutomationHeaterDesired', $result['heater']);

        $status = $result['status'];
        $pumpOverride = $this->isManualOverrideActive('pump', $nowUnix);
        $heaterOverride = $this->isManualOverrideActive('heater', $nowUnix);
        if ($pumpOverride) {
            $status .= ' | Pumpe manuell pausiert bis ' . date('H:i', (int) $this->GetBuffer('AutoOverridePumpUntil'));
        }
        if ($heaterOverride) {
            $status .= ' | Heizung manuell pausiert bis ' . date('H:i', (int) $this->GetBuffer('AutoOverrideHeaterUntil'));
        }
        $this->SetValue('AutomationStatus', $status);

        $wantPump = $result['pump'];
        $wantHeater = $result['heater'];
        $targetTemp = $result['targetTemp'];

        if ($heaterOverride) {
            $wantHeater = null;
        }
        if ($pumpOverride && ($wantHeater === null || $wantHeater === false)) {
            $wantPump = null;
        }

        if (!$this->applyAutomationCommands($wantPump, $wantHeater, $targetTemp)) {
            $this->LogMessage('Automatisierung: Steuerbefehl fehlgeschlagen', KL_ERROR);

            return;
        }

        IPS_Sleep(250);
        $this->UpdateValues();
    }

    public function ClearAutomationOverride(): string
    {
        $this->SetBuffer('AutoOverridePumpUntil', '0');
        $this->SetBuffer('AutoOverrideHeaterUntil', '0');
        $this->RunAutomation();

        return 'Manuelle Pause aufgehoben';
    }

    private function handleAutomationEditorRequest(mixed $value): void
    {
        $payload = $this->decodeAutomationEditorPayload($value);

        if ($payload === null) {
            $this->pushEditorVisualization([
                'message' => 'Ungültige Anfrage',
                'messageOk' => false,
            ]);

            return;
        }

        $cmd = strtolower(trim((string) ($payload['cmd'] ?? '')));
        match ($cmd) {
            'load' => $this->pushEditorVisualization($this->buildEditorPayload()),
            'save' => $this->saveAutomationFromEditor($payload),
            'setenabled' => $this->setAutomationEnabledFromEditor($payload),
            default => $this->pushEditorVisualization([
                'message' => 'Unbekannter Befehl',
                'messageOk' => false,
            ]),
        };
    }

    /** @return array<string, mixed>|null */
    private function decodeAutomationEditorPayload(mixed $value): ?array
    {
        if (is_string($value)) {
            $payload = json_decode($value, true);
        } elseif (is_array($value)) {
            $payload = $value;
        } elseif (is_object($value)) {
            $payload = json_decode(json_encode($value), true);
        } else {
            $payload = null;
        }

        if (!is_array($payload)) {
            return null;
        }

        foreach (['pump', 'heater'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }
            if (is_string($payload[$key])) {
                $decoded = json_decode($payload[$key], true);
                $payload[$key] = is_array($decoded) ? $decoded : [];
            } elseif (is_object($payload[$key])) {
                $payload[$key] = json_decode(json_encode($payload[$key]), true) ?? [];
            }
            if (!is_array($payload[$key])) {
                $payload[$key] = [];
                continue;
            }
            $payload[$key] = array_values(array_map(static function (mixed $row): array {
                if (is_array($row)) {
                    return $row;
                }
                if (is_object($row)) {
                    return json_decode(json_encode($row), true) ?? [];
                }

                return [];
            }, $payload[$key]));
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>>|null $pumpPropertyRows
     * @param list<array<string, mixed>>|null $heaterPropertyRows
     * @return array<string, mixed>
     */
    private function buildEditorPayload(
        ?string $message = null,
        bool $messageOk = true,
        ?bool $enabled = null,
        ?array $pumpPropertyRows = null,
        ?array $heaterPropertyRows = null,
    ): array {
        return [
            'enabled' => $enabled ?? $this->ReadPropertyBoolean('AutomationEnabled'),
            'status' => $this->getAutomationStatusSafe(),
            'pumpRules' => WifiWhirlRuleEditor::editorRowsFromProperty(
                $pumpPropertyRows ?? $this->ReadPropertyString('AutomationPumpRules'),
                false,
            ),
            'heaterRules' => WifiWhirlRuleEditor::editorRowsFromProperty(
                $heaterPropertyRows ?? $this->ReadPropertyString('AutomationHeaterRules'),
                true,
            ),
            'message' => $message ?? '',
            'messageOk' => $messageOk,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function saveAutomationFromEditor(array $payload): void
    {
        if (!function_exists('IPS_SetProperty')) {
            $this->pushEditorVisualization([
                'message' => 'Speichern nicht verfügbar',
                'messageOk' => false,
            ]);

            return;
        }

        $pumpEditor = is_array($payload['pump'] ?? null) ? $payload['pump'] : [];
        $heaterEditor = is_array($payload['heater'] ?? null) ? $payload['heater'] : [];

        $pumpRows = $this->validatedPropertyRowsFromEditor($pumpEditor, false);
        $heaterRows = $this->validatedPropertyRowsFromEditor($heaterEditor, true);
        $enabled = WifiWhirlAutomation::toBool($payload['enabled'] ?? false);

        IPS_SetProperty($this->InstanceID, 'AutomationEnabled', $enabled);
        IPS_SetProperty(
            $this->InstanceID,
            'AutomationPumpRules',
            json_encode($pumpRows, JSON_UNESCAPED_UNICODE),
        );
        IPS_SetProperty(
            $this->InstanceID,
            'AutomationHeaterRules',
            json_encode($heaterRows, JSON_UNESCAPED_UNICODE),
        );

        // IPS_SetProperty schreibt nur in die DB — Property-Cache für ReadProperty aktualisieren.
        parent::ApplyChanges();

        $this->configureAutomationTimer();
        $this->RunAutomation();

        $this->pushEditorVisualization($this->buildEditorPayload(
            'Gespeichert',
            true,
            $enabled,
            $pumpRows,
            $heaterRows,
        ));
    }

    /** @param array<string, mixed> $payload */
    private function setAutomationEnabledFromEditor(array $payload): void
    {
        if (!function_exists('IPS_SetProperty')) {
            return;
        }

        IPS_SetProperty($this->InstanceID, 'AutomationEnabled', WifiWhirlAutomation::toBool($payload['enabled'] ?? false));
        parent::ApplyChanges();
        $this->configureAutomationTimer();
        $this->RunAutomation();
        $this->pushEditorVisualization($this->buildEditorPayload());
    }

    /**
     * @param list<array<string, mixed>> $editorRows
     * @return list<array<string, mixed>>
     */
    private function validatedPropertyRowsFromEditor(array $editorRows, bool $heater): array
    {
        $valid = [];
        foreach ($editorRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $propertyRows = WifiWhirlRuleEditor::propertyRowsFromEditor([$row], $heater);
            if ($propertyRows === []) {
                continue;
            }
            $parsed = $heater
                ? WifiWhirlAutomation::parseHeaterRules($propertyRows)
                : WifiWhirlAutomation::parsePumpRules($propertyRows);
            if ($parsed === []) {
                continue;
            }
            $valid[] = $propertyRows[0];
        }

        return $valid;
    }

    /** @param array<string, mixed> $data */
    private function pushEditorVisualization(array $data): void
    {
        if (!method_exists($this, 'UpdateVisualizationValue')) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }

        $this->UpdateVisualizationValue($json);
    }

    private function getAutomationStatusSafe(): string
    {
        try {
            return (string) $this->GetValue('AutomationStatus');
        } catch (Throwable) {
            return '';
        }
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
            } else {
                $this->DisableAction($ident);
            }

            ++$pos;
        }

        $textPres = $pres['WWHL.Text'] ?? 'WWHL.Text';
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', $textPres, $pos);
        ++$pos;

        $switchPres = $pres['~Switch'] ?? '~Switch';
        $this->RegisterVariableString('AutomationStatus', 'Automatisierung Status', $textPres, $pos);
        ++$pos;
        $this->RegisterVariableFloat('AutomationPvSurplus', 'Automatisierung PV-Überschuss', $pres['WWHL.W'] ?? 'WWHL.W', $pos);
        ++$pos;
        $this->RegisterVariableBoolean('AutomationPvGateOpen', 'Automatisierung PV-Freigabe', $switchPres, $pos);
        ++$pos;
        $this->RegisterVariableBoolean('AutomationPumpDesired', 'Automatisierung Pumpe Soll', $switchPres, $pos);
        ++$pos;
        $this->RegisterVariableBoolean('AutomationHeaterDesired', 'Automatisierung Heizung Soll', $switchPres, $pos);
        $this->DisableAction('AutomationStatus');
        $this->DisableAction('AutomationPvSurplus');
        $this->DisableAction('AutomationPvGateOpen');
        $this->DisableAction('AutomationPumpDesired');
        $this->DisableAction('AutomationHeaterDesired');
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

    private function configureAutomationTimer(): void
    {
        $interval = max(self::AUTOMATION_INTERVAL_MIN_SEC, (int) $this->ReadPropertyInteger('AutomationIntervalSec'));
        if (
            $this->ReadPropertyBoolean('Active')
            && $this->ReadPropertyBoolean('AutomationEnabled')
            && trim($this->ReadPropertyString('Host')) !== ''
        ) {
            $this->SetTimerInterval('Automation', $interval * 1000);
        } else {
            $this->SetTimerInterval('Automation', 0);
        }
    }

    private function applyManualOverride(string $ident): void
    {
        $rules = $this->loadAutomationRules();
        $now = new DateTimeImmutable('now');
        $nowUnix = (int) $now->getTimestamp();

        if ($ident === 'Power') {
            $pumpEnd = WifiWhirlAutomation::activeWindowEndUnix($rules, WifiWhirlAutomation::TYPE_PUMP, $now);
            $heaterEnd = WifiWhirlAutomation::activeWindowEndUnix($rules, WifiWhirlAutomation::TYPE_HEATER, $now);
            if ($pumpEnd !== null && $pumpEnd > $nowUnix) {
                $this->SetBuffer('AutoOverridePumpUntil', (string) $pumpEnd);
            }
            if ($heaterEnd !== null && $heaterEnd > $nowUnix) {
                $this->SetBuffer('AutoOverrideHeaterUntil', (string) $heaterEnd);
            }

            return;
        }

        if ($ident === 'Pump') {
            $end = WifiWhirlAutomation::activeWindowEndUnix($rules, WifiWhirlAutomation::TYPE_PUMP, $now);
            if ($end !== null && $end > $nowUnix) {
                $this->SetBuffer('AutoOverridePumpUntil', (string) $end);
            }

            return;
        }

        if ($ident === 'Heater' || $ident === 'TargetTemperature') {
            $end = WifiWhirlAutomation::activeWindowEndUnix($rules, WifiWhirlAutomation::TYPE_HEATER, $now);
            if ($end !== null && $end > $nowUnix) {
                $this->SetBuffer('AutoOverrideHeaterUntil', (string) $end);
            }
        }
    }

    private function clearExpiredManualOverrides(int $now): void
    {
        if ($this->getBufferInt('AutoOverridePumpUntil') > 0 && $this->getBufferInt('AutoOverridePumpUntil') <= $now) {
            $this->SetBuffer('AutoOverridePumpUntil', '0');
        }
        if ($this->getBufferInt('AutoOverrideHeaterUntil') > 0 && $this->getBufferInt('AutoOverrideHeaterUntil') <= $now) {
            $this->SetBuffer('AutoOverrideHeaterUntil', '0');
        }
    }

    private function isManualOverrideActive(string $channel, int $now): bool
    {
        $buffer = $channel === 'heater' ? 'AutoOverrideHeaterUntil' : 'AutoOverridePumpUntil';
        $until = $this->getBufferInt($buffer);

        return $until > $now;
    }

    /**
     * @param bool|null $wantPump null = keine Änderung
     * @param bool|null $wantHeater null = keine Änderung
     */
    private function applyAutomationCommands(?bool $wantPump, ?bool $wantHeater, int $targetTemp): bool
    {
        if ($wantPump === null && $wantHeater === null) {
            return true;
        }

        $lastHeater = $this->getBufferBool('AutoLastHeater');
        $lastPump = $this->getBufferBool('AutoLastPump');

        if ($wantHeater === true) {
            if ($this->getBufferInt('AutoLastTargetTemp') !== $targetTemp) {
                if (!$this->sendCommandPayload(['CMD' => 0, 'VALUE' => $targetTemp])) {
                    return false;
                }
                $this->SetBuffer('AutoLastTargetTemp', (string) $targetTemp);
            }
            if (!$lastHeater) {
                if (!$this->sendCommandPayload(['CMD' => 3, 'VALUE' => true])) {
                    return false;
                }
                $this->SetBuffer('AutoLastHeater', '1');
                $this->SetBuffer('AutoLastPump', '1');
            }

            return true;
        }

        $wasHeater = $lastHeater;
        if ($wantHeater === false && $lastHeater) {
            if (!$this->sendCommandPayload(['CMD' => 3, 'VALUE' => false])) {
                return false;
            }
            $this->SetBuffer('AutoLastHeater', '0');
            $lastHeater = false;
        }

        if ($wantPump === null) {
            return true;
        }

        if ($wantPump) {
            if (!$lastPump || $wasHeater) {
                if (!$this->sendCommandPayload(['CMD' => 4, 'VALUE' => true])) {
                    return false;
                }
                $this->SetBuffer('AutoLastPump', '1');
            }
        } elseif ($lastPump && !$lastHeater) {
            if (!$this->sendCommandPayload(['CMD' => 4, 'VALUE' => false])) {
                return false;
            }
            $this->SetBuffer('AutoLastPump', '0');
        } elseif ($wasHeater && !$wantPump) {
            $this->SetBuffer('AutoLastPump', '0');
        }

        return true;
    }

    private function readPvSurplusW(): float
    {
        $variableId = (int) $this->ReadPropertyInteger('PvSurplusVar');
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return 0.0;
        }

        $value = GetValue($variableId);
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        return 0.0;
    }

    private function getBufferInt(string $name): int
    {
        $raw = $this->GetBuffer($name);
        if ($raw === false || $raw === null || $raw === '') {
            return 0;
        }

        return (int) $raw;
    }

    private function getBufferBool(string $name): bool
    {
        return $this->getBufferInt($name) !== 0;
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
