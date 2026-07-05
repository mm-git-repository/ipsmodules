<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/PoolControlEvaluator.php';
require_once __DIR__ . '/libs/PoolDosingAdvisor.php';

class PoolControl extends IPSModuleStrict
{
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 3;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_INVALID_CONFIG = 201;

    private const EVAL_INTERVAL_DEFAULT_SEC = 60;
    private const EVAL_INTERVAL_MIN_SEC = 30;

    private const VOLUME_DEFAULT_L = 669;
    private const PH_TARGET_MIN_DEFAULT = 7.2;
    private const PH_TARGET_MAX_DEFAULT = 7.6;
    private const PH_ALARM_MIN_DEFAULT = 7.0;
    private const PH_ALARM_MAX_DEFAULT = 7.8;
    private const ORP_TARGET_MIN_DEFAULT = 650;
    private const ORP_TARGET_MAX_DEFAULT = 750;
    private const ORP_ALARM_MIN_DEFAULT = 580;
    private const ORP_ALARM_MAX_DEFAULT = 820;

    private const PH_SYNC_MIN_DELTA = 0.1;
    private const PH_SYNC_MIN_INTERVAL_SEC = 900;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyInteger('SensorInstanceId', 0);
        $this->RegisterPropertyInteger('WifiWhirlInstanceId', 0);
        $this->RegisterPropertyInteger('EvaluationIntervalSec', self::EVAL_INTERVAL_DEFAULT_SEC);
        $this->RegisterPropertyInteger('SensorMaxAgeSec', 600);

        $this->RegisterPropertyInteger('PoolVolumeLiters', self::VOLUME_DEFAULT_L);

        $this->RegisterPropertyFloat('PhTargetMin', self::PH_TARGET_MIN_DEFAULT);
        $this->RegisterPropertyFloat('PhTargetMax', self::PH_TARGET_MAX_DEFAULT);
        $this->RegisterPropertyFloat('PhAlarmMin', self::PH_ALARM_MIN_DEFAULT);
        $this->RegisterPropertyFloat('PhAlarmMax', self::PH_ALARM_MAX_DEFAULT);
        $this->RegisterPropertyFloat('PhHysteresis', 0.05);

        $this->RegisterPropertyInteger('OrpTargetMin', self::ORP_TARGET_MIN_DEFAULT);
        $this->RegisterPropertyInteger('OrpTargetMax', self::ORP_TARGET_MAX_DEFAULT);
        $this->RegisterPropertyInteger('OrpAlarmMin', self::ORP_ALARM_MIN_DEFAULT);
        $this->RegisterPropertyInteger('OrpAlarmMax', self::ORP_ALARM_MAX_DEFAULT);
        $this->RegisterPropertyInteger('OrpHysteresis', 20);

        $this->RegisterPropertyBoolean('PhSyncEnabled', true);
        $this->RegisterPropertyBoolean('AlarmEnabled', true);
        $this->RegisterPropertyString('ChlorRecommendationMode', PoolDosingAdvisor::CHLOR_MODE_TAB);
        $this->RegisterPropertyFloat('LiquidChlorinePercent', 6.0);
        $this->RegisterPropertyFloat('LiquidChlorMlFactor', 8.0);
        $this->RegisterPropertyFloat('PhPowderGramsPer01Per1000L', 12.0);
        $this->RegisterPropertyInteger('MaxTabsPerWeek', 3);
        $this->RegisterPropertyString('DisclaimerText', 'Richtwerte — mit Teststreifen verifizieren.');

        $this->RegisterPropertyInteger('ManualAlkalinity', 0);
        $this->RegisterPropertyInteger('ManualAlkalinityDate', 0);
        $this->RegisterPropertyInteger('ManualCyanuricAcid', 0);
        $this->RegisterPropertyInteger('ManualCyanuricAcidDate', 0);
        $this->RegisterPropertyInteger('AlkalinityReminderDays', 14);
        $this->RegisterPropertyInteger('CyanuricAcidReminderDays', 42);

        $this->RegisterPropertyString('LastAlertState', '');

        $this->ensureProfiles();
        $this->registerVariables();

        $this->RegisterTimer('Evaluate', 0, 'POOL_RunEvaluation($_IPS[\'TARGET\']);');
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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'EvaluateNow') {
            $this->RunEvaluation();

            return;
        }

        parent::RequestAction($Ident, $Value);
    }

    public function RunEvaluation(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('ControlStatus', 'PoolControl inaktiv');
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }

        $sensorId = (int) $this->ReadPropertyInteger('SensorInstanceId');
        if ($sensorId <= 0 || !IPS_InstanceExists($sensorId)) {
            $this->SetValue('ControlStatus', 'Sensor-Instanz fehlt');
            $this->SetStatus(self::IS_INVALID_CONFIG);

            return;
        }

        $ph = $this->readLinkedVariable($sensorId, 'MeasuredPh');
        $orpRaw = $this->readLinkedVariable($sensorId, 'MeasuredOrp');
        $orp = $orpRaw !== null ? (int) round($orpRaw) : null;
        $reachable = (bool) $this->readLinkedVariable($sensorId, 'Reachable');
        $lastUpdate = (int) ($this->readLinkedVariable($sensorId, 'LastUpdate') ?? 0);

        $limits = [
            'phAlarmMin' => (float) $this->ReadPropertyFloat('PhAlarmMin'),
            'phAlarmMax' => (float) $this->ReadPropertyFloat('PhAlarmMax'),
            'phTargetMin' => (float) $this->ReadPropertyFloat('PhTargetMin'),
            'phTargetMax' => (float) $this->ReadPropertyFloat('PhTargetMax'),
            'orpAlarmMin' => (int) $this->ReadPropertyInteger('OrpAlarmMin'),
            'orpAlarmMax' => (int) $this->ReadPropertyInteger('OrpAlarmMax'),
            'orpTargetMin' => (int) $this->ReadPropertyInteger('OrpTargetMin'),
            'orpTargetMax' => (int) $this->ReadPropertyInteger('OrpTargetMax'),
            'phHysteresis' => (float) $this->ReadPropertyFloat('PhHysteresis'),
            'orpHysteresis' => (int) $this->ReadPropertyInteger('OrpHysteresis'),
        ];

        $result = PoolControlEvaluator::evaluate(
            [
                'ph' => $ph,
                'orp' => $orp,
                'sensorReachable' => $reachable,
                'lastUpdate' => $lastUpdate,
                'maxAgeSec' => max(60, (int) $this->ReadPropertyInteger('SensorMaxAgeSec')),
            ],
            $limits
        );

        $state = $result['state'];
        $this->SetValue('WaterQualityState', $state);
        $this->SetValue('WaterQualityStateLabel', PoolControlEvaluator::stateLabel($state));
        $this->SetValue('PhInRange', $result['phInRange']);
        $this->SetValue('OrpInRange', $result['orpInRange']);
        $this->SetValue('CurrentPh', $ph ?? 0.0);
        $this->SetValue('CurrentOrp', $orp ?? 0);
        $this->SetValue('LastEvaluation', time());

        $advice = PoolDosingAdvisor::recommend(
            [
                'volumeLiters' => (float) $this->ReadPropertyInteger('PoolVolumeLiters'),
                'chlorMode' => $this->ReadPropertyString('ChlorRecommendationMode'),
                'liquidChlorPercent' => (float) $this->ReadPropertyFloat('LiquidChlorinePercent'),
                'liquidChlorMlFactor' => (float) $this->ReadPropertyFloat('LiquidChlorMlFactor'),
                'phPowderGramsPer01Per1000L' => (float) $this->ReadPropertyFloat('PhPowderGramsPer01Per1000L'),
                'maxTabsPerWeek' => (int) $this->ReadPropertyInteger('MaxTabsPerWeek'),
                'phTargetMin' => $limits['phTargetMin'],
                'phTargetMax' => $limits['phTargetMax'],
                'orpTargetMin' => $limits['orpTargetMin'],
                'orpTargetMax' => $limits['orpTargetMax'],
                'disclaimer' => trim($this->ReadPropertyString('DisclaimerText')),
            ],
            [
                'ph' => $ph,
                'orp' => $orp,
                'waterQualityState' => $state,
            ]
        );

        $this->SetValue('DosingRecommendation', $advice['summary']);
        $encodedDetail = json_encode($advice['detail'], JSON_UNESCAPED_UNICODE);
        $this->SetValue('DosingRecommendationDetail', is_string($encodedDetail) ? $encodedDetail : '{}');

        $maintenance = $this->buildMaintenanceHint();
        $this->SetValue('MaintenanceHint', $maintenance);

        $status = PoolControlEvaluator::stateLabel($state);
        if ($maintenance !== '') {
            $status .= ' | ' . $maintenance;
        }
        $this->SetValue('ControlStatus', $status);

        if ($this->ReadPropertyBoolean('PhSyncEnabled') && $ph !== null && $state !== PoolControlEvaluator::STATE_SENSOR_OFFLINE) {
            $this->maybeSyncPhToWifiWhirl($ph);
        }

        if ($this->ReadPropertyBoolean('AlarmEnabled')) {
            $this->maybeRaiseAlarm($state);
        }

        $this->SetStatus(self::IS_ACTIVE);
    }

    private function maybeSyncPhToWifiWhirl(float $ph): void
    {
        $wwId = (int) $this->ReadPropertyInteger('WifiWhirlInstanceId');
        if ($wwId <= 0 || !IPS_InstanceExists($wwId)) {
            return;
        }

        $now = time();
        $rounded = round($ph, 2);
        $lastSyncTime = 0;
        $lastSyncValue = -1.0;
        if (@$this->GetIDByIdent('LastPhSync') !== 0) {
            $lastSyncTime = (int) $this->GetValue('LastPhSync');
        }
        if (@$this->GetIDByIdent('LastPhSyncValue') !== 0) {
            $lastSyncValue = (float) $this->GetValue('LastPhSyncValue');
        }

        if ($lastSyncTime > 0 && ($now - $lastSyncTime) < self::PH_SYNC_MIN_INTERVAL_SEC) {
            if (abs($rounded - $lastSyncValue) < self::PH_SYNC_MIN_DELTA) {
                return;
            }
        } elseif (abs($rounded - $lastSyncValue) < self::PH_SYNC_MIN_DELTA && $lastSyncValue >= 0) {
            return;
        }

        @IPS_RequestAction($wwId, 'PhValue', $rounded);
        $this->SetValue('LastPhSync', $now);
        $this->SetValue('LastPhSyncValue', $rounded);
    }

    private function maybeRaiseAlarm(string $state): void
    {
        if ($state === PoolControlEvaluator::STATE_OK) {
            $this->WritePropertyString('LastAlertState', '');

            return;
        }

        $previous = $this->ReadPropertyString('LastAlertState');
        if ($previous === $state) {
            return;
        }

        $this->WritePropertyString('LastAlertState', $state);
        $label = PoolControlEvaluator::stateLabel($state);
        IPS_LogMessage('PoolControl', 'Wasserqualitäts-Alarm: ' . $label, 0);
    }

    private function buildMaintenanceHint(): string
    {
        $hints = [];
        $now = time();

        $alkDate = (int) $this->ReadPropertyInteger('ManualAlkalinityDate');
        $alkDays = max(1, (int) $this->ReadPropertyInteger('AlkalinityReminderDays'));
        if ($alkDate <= 0 || ($now - $alkDate) > ($alkDays * 86400)) {
            $hints[] = 'Alkalität messen (Outdoor)';
        }

        $cyaDate = (int) $this->ReadPropertyInteger('ManualCyanuricAcidDate');
        $cyaDays = max(1, (int) $this->ReadPropertyInteger('CyanuricAcidReminderDays'));
        if ($cyaDate <= 0 || ($now - $cyaDate) > ($cyaDays * 86400)) {
            $hints[] = 'Cyanursäure messen (Outdoor + Bayrol-Tabs)';
        }

        return implode('; ', $hints);
    }

    private function readLinkedVariable(int $instanceId, string $ident): mixed
    {
        if (!IPS_InstanceExists($instanceId)) {
            return null;
        }

        foreach (IPS_GetChildrenIDs($instanceId) as $objectId) {
            if (IPS_GetObject($objectId)['ObjectType'] !== 2) {
                continue;
            }
            if (IPS_GetName($objectId) !== $ident) {
                continue;
            }

            return IPS_GetValue($objectId);
        }

        return null;
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('POOL.pH')) {
            IPS_CreateVariableProfile('POOL.pH', 2);
            IPS_SetVariableProfileText('POOL.pH', '', '');
            IPS_SetVariableProfileDigits('POOL.pH', 2);
        }
        if (!IPS_VariableProfileExists('POOL.mV')) {
            IPS_CreateVariableProfile('POOL.mV', 1);
            IPS_SetVariableProfileText('POOL.mV', '', ' mV');
        }
        if (!IPS_VariableProfileExists('POOL.state')) {
            IPS_CreateVariableProfile('POOL.state', 3);
            IPS_SetVariableProfileAssociation('POOL.state', 'ok', 'OK', 'Information', 0x00FF00);
            IPS_SetVariableProfileAssociation('POOL.state', 'ph_low', 'pH zu niedrig', 'Warning', 0xFF8000);
            IPS_SetVariableProfileAssociation('POOL.state', 'ph_high', 'pH zu hoch', 'Warning', 0xFF8000);
            IPS_SetVariableProfileAssociation('POOL.state', 'orp_low', 'ORP zu niedrig', 'Warning', 0xFF8000);
            IPS_SetVariableProfileAssociation('POOL.state', 'orp_high', 'ORP zu hoch', 'Warning', 0xFF8000);
            IPS_SetVariableProfileAssociation('POOL.state', 'sensor_offline', 'Sensor offline', 'Alert', 0xFF0000);
        }
    }

    private function registerVariables(): void
    {
        $pos = 0;
        $textPres = '~TextBox';
        $switchPres = '~Switch';

        $this->RegisterVariableString('ControlStatus', 'Status', $textPres, $pos++);
        $this->RegisterVariableString('WaterQualityState', 'Wasserqualität Zustand', 'POOL.state', $pos++);
        $this->RegisterVariableString('WaterQualityStateLabel', 'Wasserqualität Text', $textPres, $pos++);
        $this->RegisterVariableBoolean('PhInRange', 'pH im Soll', $switchPres, $pos++);
        $this->RegisterVariableBoolean('OrpInRange', 'ORP im Soll', $switchPres, $pos++);
        $this->RegisterVariableFloat('CurrentPh', 'Aktueller pH', 'POOL.pH', $pos++);
        $this->RegisterVariableInteger('CurrentOrp', 'Aktueller ORP', 'POOL.mV', $pos++);
        $this->RegisterVariableString('DosingRecommendation', 'Dosierungsempfehlung', $textPres, $pos++);
        $this->RegisterVariableString('DosingRecommendationDetail', 'Dosierung Detail (JSON)', $textPres, $pos++);
        $this->RegisterVariableString('MaintenanceHint', 'Wartungshinweis', $textPres, $pos++);
        $this->RegisterVariableInteger('LastEvaluation', 'Letzte Auswertung', '~UnixTimestamp', $pos++);
        $this->RegisterVariableInteger('LastPhSync', 'Letzter pH-Sync', '~UnixTimestamp', $pos++);
        $this->RegisterVariableFloat('LastPhSyncValue', 'Letzter pH-Sync Wert', 'POOL.pH', $pos++);

        foreach ([
            'ControlStatus', 'WaterQualityState', 'WaterQualityStateLabel',
            'PhInRange', 'OrpInRange', 'CurrentPh', 'CurrentOrp',
            'DosingRecommendation', 'DosingRecommendationDetail', 'MaintenanceHint',
            'LastEvaluation', 'LastPhSync', 'LastPhSyncValue',
        ] as $ident) {
            $this->DisableAction($ident);
        }
    }

    private function ensureModuleVersionVariable(): void
    {
        if (@$this->GetIDByIdent('ModuleVersion') !== 0) {
            return;
        }

        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '~TextBox', 999);
        $this->DisableAction('ModuleVersion');
    }

    private function syncModuleVersionVariable(): void
    {
        if (@$this->GetIDByIdent('ModuleVersion') === 0) {
            return;
        }

        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
    }

    private function configureTimer(): void
    {
        $interval = max(
            self::EVAL_INTERVAL_MIN_SEC,
            (int) $this->ReadPropertyInteger('EvaluationIntervalSec')
        );

        if ($this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Evaluate', $interval * 1000);
        } else {
            $this->SetTimerInterval('Evaluate', 0);
        }
    }

    private function updateInstanceStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }

        $sensorId = (int) $this->ReadPropertyInteger('SensorInstanceId');
        if ($sensorId <= 0) {
            $this->SetStatus(self::IS_INVALID_CONFIG);

            return;
        }

        $this->SetStatus(self::IS_ACTIVE);
    }

    private function buildSummary(): string
    {
        $volume = (int) $this->ReadPropertyInteger('PoolVolumeLiters');

        return sprintf('Pool %d l (Miami Outdoor)', $volume);
    }
}
