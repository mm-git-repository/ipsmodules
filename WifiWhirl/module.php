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
    private const MODULE_BUILD = 20;

    private const IS_CREATING = 101;
    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_NOTCREATED = 105;
    private const IS_INVALID_HOST = 201;
    private const IS_UNREACHABLE = 202;

    private const STARTUP_GUARD_MS = 30000;
    private const STARTUP_GUARD_FAST_MS = 10000;
    private const CONFIG_VALIDATION_GRACE_SEC = 60;

    private const IPS_KERNEL_MESSAGE = 10100;
    private const KR_INIT_RUNLEVEL = 10102;
    private const KR_READY_RUNLEVEL = 10103;
    private const IPS_KERNEL_STARTED_MESSAGE = 10001;

    private bool $applyChangesInProgress = false;

    private const UPDATE_INTERVAL_DEFAULT_SEC = 30;
    private const UPDATE_INTERVAL_MIN_SEC = 15;

    private const AUTOMATION_INTERVAL_DEFAULT_SEC = 60;
    private const AUTOMATION_INTERVAL_MIN_SEC = 60;

    private const PV_THRESHOLD_DEFAULT_W = 2500;
    private const PV_ON_DELAY_DEFAULT_SEC = 300;
    private const PV_OFF_DELAY_DEFAULT_SEC = 180;
    private const PV_HYSTERESIS_DEFAULT_W = 200;

    private const MANUAL_OVERRIDE_IDENTS = ['Pump', 'Heater', 'Power', 'TargetTemperature'];

    /** @var list<string> */
    private const PERSISTENT_CONFIGURATION_KEYS = [
        'Host',
        'AutomationEnabled',
        'AutomationPumpRules',
        'AutomationHeaterRules',
        'PvSurplusVar',
    ];

    private const PERSISTENT_CONFIG_BACKUP_PREFIX = 'WWHL_';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $entityMapCache = null;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_INTERVAL_DEFAULT_SEC);

        $this->RegisterPropertyBoolean('AutomationEnabled', false);
        $this->RegisterPropertyBoolean('AutomationIdlePowerOff', true);
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
        $this->RegisterTimer('StartupGuard', self::STARTUP_GUARD_FAST_MS, 'WWHL_StartupGuardRecovery($_IPS[\'TARGET\']);');

        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }

        $this->ensureKernelLifecycleMessages();
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
            'AutomationIdlePowerOff' => true,
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
        $pump = WifiWhirlAutomation::parsePumpRules($this->readRulesPropertyRaw('AutomationPumpRules'));
        $heater = WifiWhirlAutomation::parseHeaterRules($this->readRulesPropertyRaw('AutomationHeaterRules'));

        return WifiWhirlAutomation::mergeRuleLists($pump, $heater);
    }

    private function readRulesPropertyRaw(string $name): mixed
    {
        $value = null;
        if (function_exists('IPS_GetProperty')) {
            $raw = IPS_GetProperty($this->InstanceID, $name);
            if ($raw !== false && $raw !== null && $raw !== '') {
                $value = $raw;
            }
        }
        if ($value === null) {
            $read = $this->ReadPropertyString($name);
            if ($read !== '') {
                $value = $read;
            }
        }
        if ($value !== null && !$this->isEmptyRuleList($value)) {
            return $value;
        }

        $persisted = $this->getPersistedConfigurationValues();
        if (
            $persisted !== null
            && array_key_exists($name, $persisted)
            && !$this->isEmptyRuleList($persisted[$name])
        ) {
            return $persisted[$name];
        }

        $backup = $this->loadPersistentConfigurationBackup();
        if (
            $backup !== null
            && array_key_exists($name, $backup)
            && !$this->isEmptyRuleList($backup[$name])
        ) {
            return $backup[$name];
        }

        return $value ?? '[]';
    }

    /**
     * @param list<array<string, mixed>> $pumpRows
     * @param list<array<string, mixed>> $heaterRows
     */
    private function persistAutomationConfiguration(bool $enabled, array $pumpRows, array $heaterRows): bool
    {
        if (!function_exists('IPS_SetProperty')) {
            return false;
        }

        $pumpJson = json_encode($pumpRows, JSON_UNESCAPED_UNICODE);
        $heaterJson = json_encode($heaterRows, JSON_UNESCAPED_UNICODE);
        if (!is_string($pumpJson) || !is_string($heaterJson)) {
            return false;
        }

        $ok = IPS_SetProperty($this->InstanceID, 'AutomationEnabled', $enabled)
            && $this->writeRulesPropertyValue('AutomationPumpRules', $pumpJson)
            && $this->writeRulesPropertyValue('AutomationHeaterRules', $heaterJson);

        if (!$ok) {
            return false;
        }

        if (function_exists('IPS_ApplyChanges')) {
            $ok = IPS_ApplyChanges($this->InstanceID);
            if ($ok) {
                $this->savePersistentConfigurationBackup($this->captureCurrentPersistentConfiguration());
            }

            return $ok;
        }

        $this->ApplyChanges();
        $this->savePersistentConfigurationBackup($this->captureCurrentPersistentConfiguration());

        return true;
    }

    private function writeRulesPropertyValue(string $name, string $json): bool
    {
        return (bool) IPS_SetProperty($this->InstanceID, $name, $json);
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
        if ($this->applyChangesInProgress) {
            return;
        }
        $this->applyChangesInProgress = true;
        try {
            $this->runApplyChangesBody();
        } finally {
            $this->applyChangesInProgress = false;
        }
    }

    private function runApplyChangesBody(): void
    {
        $snapshot = $this->takePersistentConfigurationSnapshot();
        $this->ensureConfigurationDefaults();
        $invokeParent = $this->shouldInvokeParentApplyChanges();
        if ($invokeParent) {
            parent::ApplyChanges();
            $restoredFromSnapshot = $this->restorePersistentConfigurationFromSnapshot($snapshot);
            if ($restoredFromSnapshot > 0) {
                parent::ApplyChanges();
                $this->SendDebug(
                    'Konfiguration',
                    $restoredFromSnapshot . ' Einstellung(en) aus Konfigurations-Snapshot wiederhergestellt.',
                    0,
                );
            }
        } else {
            $this->SendDebug(
                'Konfiguration',
                'parent::ApplyChanges() übersprungen (Backup vorhanden, keine offenen Konfig-Änderungen).',
                0,
            );
        }

        $restoredFromBackup = $this->restoreFromPersistentBackup();
        if ($restoredFromBackup > 0) {
            if ($invokeParent) {
                parent::ApplyChanges();
            }
            $this->SendDebug(
                'Konfiguration',
                $restoredFromBackup . ' Einstellung(en) aus Backup-Datei wiederhergestellt.',
                0,
            );
        }

        $this->sanitizeConfigurationProperties();

        $this->ensureProfiles();
        $this->registerAllVariables();
        $this->ensureModuleVersionVariable();
        $this->syncModuleVersionVariable();
        $this->configureTimer();
        $this->configureAutomationTimer();
        $this->handleAutomationEnabledTransition();
        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
        $this->updateInstanceStatus();
        $this->SetSummary($this->buildSummary());
        $this->savePersistentConfigurationBackup($this->captureCurrentPersistentConfiguration());
        $this->ensureKernelLifecycleMessages();
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

        $initial = json_encode($this->buildEditorPayload(), JSON_UNESCAPED_UNICODE);
        if (!is_string($initial)) {
            $initial = '{}';
        }

        return str_replace(
            ['{{INLINE_CSS}}', '{{INLINE_JS}}', '{{INITIAL_JSON}}'],
            [$css, $js, $initial],
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

        if ($Ident === 'AutomationManualPause') {
            if (WifiWhirlAutomation::toBool($Value) === false) {
                $this->clearAutomationOverrideBuffers();
                $this->RunAutomation();
            } else {
                $this->syncAutomationManualPauseVariable();
            }

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
            $this->syncAutomationManualPauseVariable();
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

        $host = $this->readHostProperty();
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

    public function StartupGuardRecovery(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        if (!$this->isIpsKernelReady()) {
            $this->armStartupGuardTimer(true);

            return;
        }
        $this->markConfigValidationGrace();
        $restored = $this->restoreFromPersistentBackup();
        if ($restored > 0) {
            $this->SendDebug(
                'Konfiguration',
                $restored . ' Einstellung(en) aus Backup wiederhergestellt (StartupGuard).',
                0,
            );
        }
        if ($this->handlePostKernelRestartIfNeeded()) {
            $this->setTimerIntervalSafe('StartupGuard', self::STARTUP_GUARD_MS);

            return;
        }
        if ($this->needsRecoveryAfterKernelRestart()) {
            $this->configureTimer();
            $this->configureAutomationTimer();
            $this->RunAutomation();
            $this->SendDebug('Start', 'Timer/Automatisierung nach IPS-Neustart reaktiviert.', 0);
        }
        $this->ensureKernelLifecycleMessages();
        $this->setTimerIntervalSafe('StartupGuard', self::STARTUP_GUARD_MS);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        unset($TimeStamp);
        if ($SenderID !== 0) {
            return;
        }
        $this->handleKernelMessage($Message, $Data);
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
            $this->SetValue('AutomationTargetTemp', 0);
            $this->syncAutomationManualPauseVariable();

            return;
        }

        if (!$this->ReadPropertyBoolean('Active') || $this->readHostProperty() === '') {
            $this->SetValue('AutomationStatus', 'Automatisierung: Modul inaktiv oder Host fehlt');
            $this->SetValue('AutomationTargetTemp', 0);
            $this->syncAutomationManualPauseVariable();

            return;
        }

        $rules = $this->loadAutomationRules();
        $now = new DateTimeImmutable('now');
        $nowUnix = (int) $now->getTimestamp();
        $pvVarId = $this->readPvSurplusVarId();

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

        $wantPump = $result['pump'];
        $wantHeater = $result['heater'];
        $targetTemp = $result['targetTemp'];
        $heaterWindowActive = $result['heaterWindowActive'];

        $this->SetValue('AutomationTargetTemp', $heaterWindowActive ? $targetTemp : 0);

        $deviceTargetTemp = $this->readDeviceTargetTemp();

        if ($heaterOverride) {
            $wantHeater = null;
            if ($heaterWindowActive) {
                $status .= sprintf(
                    ' | Heizung manuell bis %s (Zeitplan %.0f °C, Gerät %.0f °C)',
                    date('H:i', (int) $this->GetBuffer('AutoOverrideHeaterUntil')),
                    (float) $targetTemp,
                    (float) ($deviceTargetTemp >= 0 ? $deviceTargetTemp : $targetTemp),
                );
            } else {
                $status .= ' | Heizung manuell pausiert bis ' . date('H:i', (int) $this->GetBuffer('AutoOverrideHeaterUntil'));
            }
        } elseif ($heaterWindowActive && $result['heater'] && $deviceTargetTemp >= 0 && $deviceTargetTemp !== $targetTemp) {
            $status .= sprintf(' | Zieltemp. wird auf %.0f °C gesetzt (aktuell %.0f °C)', (float) $targetTemp, (float) $deviceTargetTemp);
        }

        if ($pumpOverride && ($wantHeater === null || $wantHeater === false)) {
            $wantPump = null;
        }

        if (
            $this->ReadPropertyBoolean('AutomationIdlePowerOff')
            && $wantPump === false
            && $wantHeater === false
        ) {
            $status .= ' | Gerät aus (Display)';
        }

        $this->SetValue('AutomationStatus', $status);

        if (!$this->applyAutomationCommands($wantPump, $wantHeater, $targetTemp)) {
            $this->LogMessage('Automatisierung: Steuerbefehl fehlgeschlagen', KL_ERROR);
            $this->syncAutomationManualPauseVariable();

            return;
        }

        IPS_Sleep(250);
        $this->UpdateValues();
        $this->syncAutomationManualPauseVariable();
    }

    public function ClearAutomationOverride(): string
    {
        $this->clearAutomationOverrideBuffers();
        $this->RunAutomation();
        $this->syncAutomationManualPauseVariable();

        return 'Manuelle Pause aufgehoben';
    }

    private function clearAutomationOverrideBuffers(): void
    {
        $this->SetBuffer('AutoOverridePumpUntil', '0');
        $this->SetBuffer('AutoOverrideHeaterUntil', '0');
    }

    private function prepareAutomationResume(): void
    {
        $this->clearAutomationOverrideBuffers();
        $this->SetBuffer('AutoLastPower', '0');
        $this->SetBuffer('AutoLastPump', '0');
        $this->SetBuffer('AutoLastHeater', '0');
        $this->SetBuffer('AutoLastTargetTemp', '0');
    }

    private function handleAutomationEnabledTransition(): void
    {
        $enabled = $this->ReadPropertyBoolean('AutomationEnabled');
        $raw = $this->GetBuffer('AutoWasAutomationEnabled');
        if ($raw === false || $raw === '') {
            $this->SetBuffer('AutoWasAutomationEnabled', $enabled ? '1' : '0');

            return;
        }

        $wasEnabled = $this->getBufferBool('AutoWasAutomationEnabled');
        $this->SetBuffer('AutoWasAutomationEnabled', $enabled ? '1' : '0');
        if ($enabled && !$wasEnabled) {
            $this->prepareAutomationResume();
            $this->RunAutomation();
        } elseif (!$enabled && $wasEnabled) {
            $this->RunAutomation();
        }
    }

    private function syncAutomationManualPauseVariable(): void
    {
        $now = time();
        $active = $this->isManualOverrideActive('pump', $now)
            || $this->isManualOverrideActive('heater', $now);

        try {
            $this->SetValue('AutomationManualPause', $active);
        } catch (Throwable) {
            // Variable noch nicht angelegt
        }
    }

    private function isAutomationManualPauseActive(): bool
    {
        $now = time();

        return $this->isManualOverrideActive('pump', $now)
            || $this->isManualOverrideActive('heater', $now);
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
            'clearoverride' => $this->clearAutomationOverrideFromEditor(),
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
                $pumpPropertyRows ?? $this->readRulesPropertyRaw('AutomationPumpRules'),
                false,
            ),
            'heaterRules' => WifiWhirlRuleEditor::editorRowsFromProperty(
                $heaterPropertyRows ?? $this->readRulesPropertyRaw('AutomationHeaterRules'),
                true,
            ),
            'manualPause' => $this->isAutomationManualPauseActive(),
            'pvGateOpen' => $this->getAutomationPvGateOpenSafe(),
            'pvSurplus' => $this->getAutomationPvSurplusSafe(),
            'message' => $message ?? '',
            'messageOk' => $messageOk,
        ];
    }

    private function clearAutomationOverrideFromEditor(): void
    {
        if (!$this->isAutomationManualPauseActive()) {
            $this->pushEditorVisualization($this->buildEditorPayload(
                'Keine manuelle Pause aktiv',
                true,
            ));

            return;
        }

        $this->clearAutomationOverrideBuffers();
        $this->RunAutomation();
        $this->syncAutomationManualPauseVariable();
        $this->pushEditorVisualization($this->buildEditorPayload(
            'Manuelle Pause aufgehoben',
            true,
        ));
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

        if (!$this->persistAutomationConfiguration($enabled, $pumpRows, $heaterRows)) {
            $this->pushEditorVisualization([
                'enabled' => $enabled,
                'status' => $this->getAutomationStatusSafe(),
                'pumpRules' => WifiWhirlRuleEditor::editorRowsFromProperty($pumpRows, false),
                'heaterRules' => WifiWhirlRuleEditor::editorRowsFromProperty($heaterRows, true),
                'message' => 'Speichern fehlgeschlagen',
                'messageOk' => false,
            ]);

            return;
        }

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

        $enabled = WifiWhirlAutomation::toBool($payload['enabled'] ?? false);

        IPS_SetProperty($this->InstanceID, 'AutomationEnabled', $enabled);
        if (function_exists('IPS_ApplyChanges')) {
            IPS_ApplyChanges($this->InstanceID);
        } else {
            $this->ApplyChanges();
        }
        $this->savePersistentConfigurationBackup($this->captureCurrentPersistentConfiguration());
        $this->configureAutomationTimer();
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

        try {
            $this->UpdateVisualizationValue($json);
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'UpdateVisualizationValue: ' . $e->getMessage(), 0);
        }
    }

    private function getAutomationStatusSafe(): string
    {
        try {
            return (string) $this->GetValue('AutomationStatus');
        } catch (Throwable) {
            return '';
        }
    }

    private function getAutomationPvGateOpenSafe(): bool
    {
        try {
            return (bool) $this->GetValue('AutomationPvGateOpen');
        } catch (Throwable) {
            return false;
        }
    }

    private function getAutomationPvSurplusSafe(): float
    {
        try {
            $value = $this->GetValue('AutomationPvSurplus');

            return is_numeric($value) ? (float) $value : 0.0;
        } catch (Throwable) {
            return 0.0;
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
        $host = $this->readHostProperty();
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
        ++$pos;
        $tempPres = $pres['~Temperature'] ?? '~Temperature';
        $this->RegisterVariableInteger('AutomationTargetTemp', 'Automatisierung Zieltemp. Soll', $tempPres, $pos);
        ++$pos;
        $this->RegisterVariableBoolean('AutomationManualPause', 'Automatisierung Manuelle Pause', $switchPres, $pos);
        $this->EnableAction('AutomationManualPause');
        $this->DisableAction('AutomationStatus');
        $this->DisableAction('AutomationPvSurplus');
        $this->DisableAction('AutomationPvGateOpen');
        $this->DisableAction('AutomationPumpDesired');
        $this->DisableAction('AutomationHeaterDesired');
        $this->DisableAction('AutomationTargetTemp');
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
        if ($this->ReadPropertyBoolean('Active') && $this->readHostProperty() !== '') {
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
            && $this->readHostProperty() !== ''
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

    private function readDeviceTargetTemp(): int
    {
        try {
            return (int) round((float) $this->GetValue('TargetTemperature'));
        } catch (Throwable) {
            return -1;
        }
    }

    private function shouldApplyTargetTemp(int $targetTemp): bool
    {
        if ($this->getBufferInt('AutoLastTargetTemp') !== $targetTemp) {
            return true;
        }

        $deviceTemp = $this->readDeviceTargetTemp();

        return $deviceTemp >= 0 && $deviceTemp !== $targetTemp;
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

        if ($wantHeater === true || $wantPump === true) {
            if (!$this->ensureAutomationPowerOn()) {
                return false;
            }
        }

        $lastHeater = $this->getBufferBool('AutoLastHeater');
        $lastPump = $this->getBufferBool('AutoLastPump');

        if ($wantHeater === true) {
            if ($this->shouldApplyTargetTemp($targetTemp)) {
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

        if (
            $this->ReadPropertyBoolean('AutomationIdlePowerOff')
            && $wantPump === false
            && $wantHeater === false
        ) {
            return $this->applyAutomationPowerOff();
        }

        return true;
    }

    private function ensureAutomationPowerOn(): bool
    {
        if ($this->getBufferBool('AutoLastPower')) {
            return true;
        }

        if (!$this->sendCommandPayload(['CMD' => 22, 'VALUE' => true])) {
            return false;
        }

        $this->SetBuffer('AutoLastPower', '1');

        return true;
    }

    private function applyAutomationPowerOff(): bool
    {
        if (!$this->getBufferBool('AutoLastPower')) {
            return true;
        }

        if (!$this->sendCommandPayload(['CMD' => 22, 'VALUE' => false])) {
            return false;
        }

        $this->SetBuffer('AutoLastPower', '0');
        $this->SetBuffer('AutoLastPump', '0');
        $this->SetBuffer('AutoLastHeater', '0');

        return true;
    }

    private function readPvSurplusW(): float
    {
        $variableId = $this->readPvSurplusVarId();
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

        if ($this->readHostProperty() === '') {
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
        $host = $this->readHostProperty();

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

    private function readHostProperty(): string
    {
        $host = '';
        if (function_exists('IPS_GetProperty')) {
            $raw = IPS_GetProperty($this->InstanceID, 'Host');
            if (is_string($raw)) {
                $host = trim($raw);
            }
        }
        if ($host === '') {
            $host = trim($this->ReadPropertyString('Host'));
        }
        if ($host !== '') {
            return $host;
        }

        $persisted = $this->getPersistedConfigurationValues();
        if ($persisted !== null && isset($persisted['Host']) && is_string($persisted['Host'])) {
            $host = trim($persisted['Host']);
            if ($host !== '') {
                return $host;
            }
        }

        $backup = $this->loadPersistentConfigurationBackup();
        if ($backup !== null && isset($backup['Host']) && is_string($backup['Host'])) {
            return trim($backup['Host']);
        }

        return '';
    }

    private function readPvSurplusVarId(): int
    {
        $current = 0;
        if (function_exists('IPS_GetProperty')) {
            $raw = IPS_GetProperty($this->InstanceID, 'PvSurplusVar');
            if ($raw !== false && $raw !== null && $raw !== '') {
                $current = $this->coerceVariableIdFromStoredValue($raw);
            }
        }
        if ($current <= 0) {
            $current = (int) $this->ReadPropertyInteger('PvSurplusVar');
        }
        if ($current > 0) {
            return $current;
        }

        $persisted = $this->getPersistedConfigurationValues();
        if ($persisted !== null && array_key_exists('PvSurplusVar', $persisted)) {
            $current = $this->coerceVariableIdFromStoredValue($persisted['PvSurplusVar']);
            if ($current > 0) {
                return $current;
            }
        }

        $backup = $this->loadPersistentConfigurationBackup();
        if ($backup !== null && array_key_exists('PvSurplusVar', $backup)) {
            $current = $this->coerceVariableIdFromStoredValue($backup['PvSurplusVar']);
            if ($current > 0) {
                return $current;
            }
        }

        return 0;
    }

    private function getPersistentConfigurationBackupPath(): string
    {
        if (!function_exists('IPS_GetKernelDir')) {
            return '';
        }
        $dir = rtrim((string) IPS_GetKernelDir(), "\\/") . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir . DIRECTORY_SEPARATOR . self::PERSISTENT_CONFIG_BACKUP_PREFIX . $this->InstanceID . '_config.json';
    }

    /** @param array<string, mixed> $config */
    private function savePersistentConfigurationBackup(array $config): void
    {
        $path = $this->getPersistentConfigurationBackupPath();
        if ($path === '') {
            return;
        }

        $payload = [];
        foreach (self::PERSISTENT_CONFIGURATION_KEYS as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            if ($key === 'Host' && trim((string) $config[$key]) === '') {
                continue;
            }
            if ($key === 'PvSurplusVar') {
                $id = $this->coerceVariableIdFromStoredValue($config[$key]);
                if ($id <= 0) {
                    continue;
                }
            }
            if (($key === 'AutomationPumpRules' || $key === 'AutomationHeaterRules')
                && $this->isEmptyRuleList($config[$key])
            ) {
                continue;
            }
            $payload[$key] = $config[$key];
        }

        if ($payload === []) {
            return;
        }

        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed>|null */
    private function loadPersistentConfigurationBackup(): ?array
    {
        $path = $this->getPersistentConfigurationBackupPath();
        if ($path === '' || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @return array<string, mixed> */
    private function captureCurrentPersistentConfiguration(): array
    {
        $config = [];
        foreach (self::PERSISTENT_CONFIGURATION_KEYS as $key) {
            $config[$key] = match ($key) {
                'Host' => $this->readHostProperty(),
                'AutomationEnabled' => $this->ReadPropertyBoolean('AutomationEnabled'),
                'AutomationPumpRules' => $this->readRulesPropertyRaw('AutomationPumpRules'),
                'AutomationHeaterRules' => $this->readRulesPropertyRaw('AutomationHeaterRules'),
                'PvSurplusVar' => $this->readPvSurplusVarId(),
                default => null,
            };
        }

        return $config;
    }

    /**
     * Symcon speichert Modul-Properties teils unter "configuration", teils flach im JSON-Root.
     *
     * @return array<string, mixed>|null
     */
    private function getPersistedConfigurationValues(): ?array
    {
        if (function_exists('IPS_GetConfiguration')) {
            $raw = IPS_GetConfiguration($this->InstanceID);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                if (isset($data['configuration']) && is_array($data['configuration'])) {
                    return $data['configuration'];
                }

                return $data;
            }
        }

        $fromFile = $this->loadConfigurationFromInstanceStorageFile();
        if ($fromFile !== null) {
            return $fromFile;
        }

        return $this->loadPersistentConfigurationBackup();
    }

    /** @return array<string, mixed>|null */
    private function loadConfigurationFromInstanceStorageFile(): ?array
    {
        if (!function_exists('IPS_GetKernelDir')) {
            return null;
        }

        $base = rtrim((string) IPS_GetKernelDir(), "\\/") . DIRECTORY_SEPARATOR . 'instances';
        $candidates = [
            $base . DIRECTORY_SEPARATOR . $this->InstanceID . '.ipson',
            $base . DIRECTORY_SEPARATOR . $this->InstanceID . '.json',
        ];
        foreach ($candidates as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            if (isset($data['configuration']) && is_array($data['configuration'])) {
                return $data['configuration'];
            }

            return $data;
        }

        return null;
    }

    private function ensureConfigurationDefaults(): void
    {
        if (!function_exists('IPS_SetProperty')) {
            return;
        }

        $values = $this->getPersistedConfigurationValues();
        if ($values === null) {
            return;
        }

        foreach ($this->migrateDefaultConfiguration() as $key => $default) {
            if (array_key_exists($key, $values)) {
                continue;
            }
            IPS_SetProperty($this->InstanceID, $key, $default);
        }
    }

    /** @return array<string, mixed> */
    private function takePersistentConfigurationSnapshot(): array
    {
        $values = $this->getPersistedConfigurationValues();
        if ($values === null) {
            $values = $this->loadPersistentConfigurationBackup();
        }
        if ($values === null) {
            return [];
        }

        $snapshot = [];
        foreach (self::PERSISTENT_CONFIGURATION_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $snapshot[$key] = $values[$key];
            }
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function restorePersistentConfigurationFromSnapshot(array $snapshot): int
    {
        if ($snapshot === [] || !function_exists('IPS_SetProperty')) {
            return 0;
        }

        $restored = 0;
        foreach ($snapshot as $key => $stored) {
            if (!is_string($key) || !$this->shouldRestorePropertyFromSnapshot($key, $stored)) {
                continue;
            }
            IPS_SetProperty($this->InstanceID, $key, $this->normalizePropertyValueForSet($key, $stored));
            $restored++;
        }

        return $restored;
    }

    private function sanitizeConfigurationProperties(): void
    {
        if (!function_exists('IPS_SetProperty')) {
            return;
        }
        if ($this->isInConfigValidationGrace()) {
            return;
        }
        if (!function_exists('IPS_HasChanges') || !IPS_HasChanges($this->InstanceID)) {
            return;
        }

        $pvId = (int) $this->ReadPropertyInteger('PvSurplusVar');
        if ($pvId <= 0) {
            return;
        }

        if (function_exists('IPS_VariableExists') && !IPS_VariableExists($pvId)) {
            IPS_SetProperty($this->InstanceID, 'PvSurplusVar', 0);
            $this->SendDebug(
                'Konfiguration',
                'PvSurplusVar: ungültige Variable #' . $pvId . ' entfernt.',
                0,
            );
        }
    }

    /** @param mixed $stored */
    private function normalizePropertyValueForSet(string $key, mixed $stored): mixed
    {
        if ($key === 'AutomationPumpRules' || $key === 'AutomationHeaterRules') {
            if (is_array($stored)) {
                $json = json_encode($stored, JSON_UNESCAPED_UNICODE);

                return is_string($json) ? $json : '[]';
            }

            return is_string($stored) ? $stored : '[]';
        }

        if ($key === 'PvSurplusVar') {
            return $this->coerceVariableIdFromStoredValue($stored);
        }

        return $stored;
    }

    private function isValidPvSurplusVarId(mixed $stored): bool
    {
        $id = $this->coerceVariableIdFromStoredValue($stored);
        if ($id <= 0) {
            return false;
        }

        return !function_exists('IPS_VariableExists') || IPS_VariableExists($id);
    }

    private function shouldRestorePropertyFromSnapshot(string $key, mixed $stored): bool
    {
        if ($key === 'Host') {
            $current = trim($this->ReadPropertyString('Host'));
            $storedHost = trim((string) $stored);

            return $storedHost !== '' && ($current === '' || $current !== $storedHost);
        }

        if ($key === 'PvSurplusVar') {
            $current = (int) $this->ReadPropertyInteger('PvSurplusVar');
            $storedId = $this->coerceVariableIdFromStoredValue($stored);

            return $storedId > 0 && ($current <= 0 || $current !== $storedId);
        }

        if ($key === 'AutomationPumpRules' || $key === 'AutomationHeaterRules') {
            return $this->isEmptyRuleList($this->ReadPropertyString($key))
                && !$this->isEmptyRuleList($stored);
        }

        if ($key === 'AutomationEnabled') {
            $current = $this->ReadPropertyBoolean('AutomationEnabled');
            $storedBool = (bool) $stored;

            return $current !== $storedBool;
        }

        return false;
    }

    /** @param mixed $stored */
    private function coerceVariableIdFromStoredValue($stored): int
    {
        if (is_array($stored)) {
            foreach (['variableID', 'VariableID', 'value', 'Value', 'id', 'ID'] as $subKey) {
                if (isset($stored[$subKey])) {
                    return max(0, (int) $stored[$subKey]);
                }
            }

            return 0;
        }

        return max(0, (int) $stored);
    }

    private function getInstanceStatus(): int
    {
        if (!function_exists('IPS_GetInstance')) {
            return self::IS_ACTIVE;
        }

        return (int) (IPS_GetInstance($this->InstanceID)['InstanceStatus'] ?? 0);
    }

    private function shouldInvokeParentApplyChanges(): bool
    {
        $status = $this->getInstanceStatus();
        if ($status === self::IS_CREATING || $status === self::IS_NOTCREATED) {
            return true;
        }
        if (function_exists('IPS_HasChanges') && IPS_HasChanges($this->InstanceID)) {
            return true;
        }

        return !$this->hasUsablePersistentConfigurationBackup();
    }

    private function hasUsablePersistentConfigurationBackup(): bool
    {
        $backup = $this->loadPersistentConfigurationBackup();
        if ($backup === null || $backup === []) {
            return false;
        }

        return isset($backup['Host']) && trim((string) $backup['Host']) !== '';
    }

    private function restoreFromPersistentBackup(): int
    {
        $backup = $this->loadPersistentConfigurationBackup();
        if ($backup === null || $backup === []) {
            return 0;
        }

        return $this->restorePersistentConfigurationFromSnapshot($backup);
    }

    private function markConfigValidationGrace(): void
    {
        $this->SetBuffer('ConfigGraceUntil', (string) (time() + self::CONFIG_VALIDATION_GRACE_SEC));
    }

    private function isInConfigValidationGrace(): bool
    {
        $untilRaw = $this->GetBuffer('ConfigGraceUntil');
        if (is_numeric($untilRaw) && time() < (int) $untilRaw) {
            return true;
        }
        $start = $this->getKernelStartTime();
        if ($start > 0) {
            return (time() - $start) <= self::CONFIG_VALIDATION_GRACE_SEC;
        }

        return $this->needsRecoveryAfterKernelRestart();
    }

    private function getKernelStartTime(): int
    {
        if (!function_exists('IPS_GetKernelStartTime')) {
            return 0;
        }

        return (int) IPS_GetKernelStartTime();
    }

    private function isIpsKernelReady(): bool
    {
        if (!function_exists('IPS_GetKernelRunlevel')) {
            return true;
        }

        return IPS_GetKernelRunlevel() >= $this->getKrReadyRunlevel();
    }

    private function getIpsKernelMessageId(): int
    {
        return defined('IPS_KERNELMESSAGE') ? (int) IPS_KERNELMESSAGE : self::IPS_KERNEL_MESSAGE;
    }

    private function getKrReadyRunlevel(): int
    {
        return defined('KR_READY') ? (int) KR_READY : self::KR_READY_RUNLEVEL;
    }

    private function getKrInitRunlevel(): int
    {
        return defined('KR_INIT') ? (int) KR_INIT : self::KR_INIT_RUNLEVEL;
    }

    private function ensureKernelLifecycleMessages(): void
    {
        $this->registerKernelMessageIfMissing($this->getIpsKernelMessageId());
        $this->armStartupGuardTimer();
    }

    private function registerKernelMessageIfMissing(int $messageId): void
    {
        $list = $this->GetMessageList();
        if (is_array($list) && isset($list[0]) && is_array($list[0])) {
            if (in_array($messageId, $list[0], true)) {
                return;
            }
        }
        $this->RegisterMessage(0, $messageId);
    }

    private function armStartupGuardTimer(bool $forceFast = false): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $ms = $forceFast ? self::STARTUP_GUARD_FAST_MS : self::STARTUP_GUARD_MS;
        if ($forceFast || $this->GetTimerInterval('StartupGuard') <= 0) {
            $this->setTimerIntervalSafe('StartupGuard', $ms);
        }
    }

    private function setTimerIntervalSafe(string $ident, int $intervalMs): void
    {
        try {
            $this->SetTimerInterval($ident, $intervalMs);
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, $ident . ': ' . $e->getMessage(), 0);
        }
    }

    /** @param array<int, mixed> $data */
    private function kernelRunlevelFromMessageData(array $data): ?int
    {
        if (!isset($data[0]) || !is_numeric($data[0])) {
            return null;
        }

        return (int) $data[0];
    }

    /** @param array<int, mixed> $data */
    private function isKernelReadyMessage(int $message, array $data): bool
    {
        if ($message === $this->getKrReadyRunlevel()) {
            return true;
        }
        if ($message !== $this->getIpsKernelMessageId()) {
            return false;
        }
        $runlevel = $this->kernelRunlevelFromMessageData($data);
        if ($runlevel === null) {
            return $this->isIpsKernelReady();
        }

        return $runlevel === $this->getKrReadyRunlevel();
    }

    /** @param array<int, mixed> $data */
    private function isKernelInitMessage(int $message, array $data): bool
    {
        if ($message !== $this->getIpsKernelMessageId()) {
            return false;
        }

        return $this->kernelRunlevelFromMessageData($data) === $this->getKrInitRunlevel();
    }

    /** @param array<int, mixed> $data */
    private function isKernelStartedMessage(int $message, array $data): bool
    {
        if ($message === self::IPS_KERNEL_STARTED_MESSAGE) {
            return true;
        }
        if ($message !== $this->getIpsKernelMessageId()) {
            return false;
        }

        return $this->kernelRunlevelFromMessageData($data) === self::IPS_KERNEL_STARTED_MESSAGE;
    }

    /** @param array<int, mixed> $data */
    private function handleKernelMessage(int $message, array $data): void
    {
        if ($this->isKernelInitMessage($message, $data)) {
            $this->markConfigValidationGrace();
            $this->registerKernelMessageIfMissing($this->getIpsKernelMessageId());
            $this->armStartupGuardTimer(true);

            return;
        }
        if ($this->isKernelReadyMessage($message, $data)) {
            $this->onIpsKernelReady();

            return;
        }
        if ($this->isKernelStartedMessage($message, $data)) {
            $this->StartupGuardRecovery();
        }
    }

    private function onIpsKernelReady(): void
    {
        if ($this->handlePostKernelRestartIfNeeded()) {
            return;
        }
        $this->StartupGuardRecovery();
    }

    private function handlePostKernelRestartIfNeeded(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }
        if (!$this->isIpsKernelReady()) {
            return false;
        }
        $this->markConfigValidationGrace();
        $kernelStart = $this->getKernelStartTime();
        if ($kernelStart <= 0) {
            return false;
        }
        $handledRaw = $this->GetBuffer('HandledKernelStartAt');
        $handled = is_numeric($handledRaw) ? (int) $handledRaw : 0;
        if ($handled === $kernelStart) {
            return false;
        }

        $restored = $this->restoreFromPersistentBackup();
        if ($restored > 0) {
            $this->SendDebug(
                'Konfiguration',
                $restored . ' Einstellung(en) nach IPS-Neustart aus Backup geladen.',
                0,
            );
        }
        $this->configureTimer();
        $this->configureAutomationTimer();
        $this->RunAutomation();
        $this->SetBuffer('HandledKernelStartAt', (string) $kernelStart);
        $this->SendDebug('Start', 'IPS-Neustart: Konfiguration und Automatisierung wiederhergestellt.', 0);

        return true;
    }

    private function needsRecoveryAfterKernelRestart(): bool
    {
        if (!function_exists('IPS_GetKernelStartTime')) {
            return false;
        }
        $kernelStart = $this->getKernelStartTime();
        if ($kernelStart <= 0) {
            return false;
        }
        $handledRaw = $this->GetBuffer('HandledKernelStartAt');
        $handled = is_numeric($handledRaw) ? (int) $handledRaw : 0;
        if ($handled !== $kernelStart) {
            return true;
        }
        if ($this->readHostProperty() === '') {
            return false;
        }
        if ($this->GetTimerInterval('Update') <= 0) {
            return true;
        }
        if ($this->ReadPropertyBoolean('AutomationEnabled') && $this->GetTimerInterval('Automation') <= 0) {
            return true;
        }

        return false;
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
