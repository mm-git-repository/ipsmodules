<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartChildTrait.php';

class GardenaSmartValveSchedule extends IPSModuleStrict
{
    use GardenaSmartChildTrait;

    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 4;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('ValveInstanceID', 0);

        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 999);

        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
        $valveId = $this->getValveInstanceId();
        $ok = $valveId > 0 && IPS_InstanceExists($valveId);
        $this->SetStatus($ok ? 102 : 104);
        if ($ok) {
            $this->SetSummary(IPS_GetName($valveId));
        } else {
            $this->SetSummary('keine Valve');
        }
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

        return json_encode($form, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function GetVisualizationTile(): string
    {
        $data = $this->loadScheduleTileData();
        if ($data === null) {
            return '<div style="padding:12px;font-family:Segoe UI,sans-serif;">Keine Valve-Instanz zugewiesen.</div>';
        }

        return $this->buildVisualizationHtml(__DIR__, 'GSVSC', $data);
    }

    private function getValveInstanceId(): int
    {
        try {
            return (int) $this->ReadPropertyInteger('ValveInstanceID');
        } catch (Throwable) {
            return 0;
        }
    }

    private function requireValveInstanceId(): int
    {
        $valveId = $this->getValveInstanceId();
        if ($valveId <= 0 || !IPS_InstanceExists($valveId)) {
            return 0;
        }
        try {
            $moduleId = (string) (IPS_GetInstance($valveId)['ModuleInfo']['ModuleID'] ?? '');
        } catch (Throwable) {
            return 0;
        }
        if ($moduleId !== GardenaSmartGuids::VALVE) {
            return 0;
        }

        return $valveId;
    }

    /** @return array<string, mixed>|null */
    private function loadScheduleTileData(): ?array
    {
        $valveId = $this->requireValveInstanceId();
        if ($valveId <= 0) {
            return null;
        }
        try {
            if (function_exists('GSVAL_GetScheduleTileData')) {
                $data = GSVAL_GetScheduleTileData($valveId);
                if (is_array($data)) {
                    $data['scheduleWritable'] = false;
                    $data['scheduleInstanceId'] = $this->InstanceID;

                    return $data;
                }
            }
        } catch (Throwable) {
            // fall through
        }

        $text = '';
        try {
            $vid = @IPS_GetObjectIDByIdent('DeviceSchedules', $valveId);
            if ($vid) {
                $text = (string) GetValue($vid);
            }
        } catch (Throwable) {
            // ignore
        }

        return [
            'name' => IPS_GetName($valveId),
            'scheduleRules' => [],
            'scheduleText' => $text,
            'scheduleLines' => $text === '' || $text === '(keine)' ? [] : preg_split('/\r\n|\r|\n/', $text) ?: [],
            'scheduleWritable' => false,
            'valveNames' => [],
            'instanceId' => $valveId,
            'scheduleInstanceId' => $this->InstanceID,
        ];
    }
}
