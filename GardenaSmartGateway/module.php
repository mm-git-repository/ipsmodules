<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartGuids.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartDevices.php';
require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartClient.php';

class GardenaSmartGateway extends IPSModuleStrict
{
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 1;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_INVALID = 201;
    private const IS_UNREACHABLE = 202;

    private const UPDATE_MIN_SEC = 30;
    private const UPDATE_DEFAULT_SEC = 60;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Port', 8443);
        $this->RegisterPropertyBoolean('TlsInsecure', true);
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_DEFAULT_SEC);

        $this->RegisterAttributeString('DeviceCache', '{}');
        $this->RegisterAttributeString('DeviceScheduleBlock', '');

        $this->RegisterVariableBoolean('Reachable', 'Erreichbar', '', 1);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 2);
        $this->RegisterVariableInteger('DeviceCount', 'Geräteanzahl', '', 3);
        $this->RegisterVariableString('ScheduleOverview', 'Zeitplan-Übersicht', '', 10);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 999);

        $this->RegisterTimer('Update', 0, 'GSGW_UpdateValues($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
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

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }
        $buffer = $data['Buffer'] ?? null;
        if (is_string($buffer)) {
            $decoded = json_decode($buffer, true);
            if (!is_array($decoded) && ctype_xdigit($buffer) && (strlen($buffer) % 2) === 0) {
                $decoded = json_decode((string) hex2bin($buffer), true);
            }
            $buffer = $decoded;
        }
        if (!is_array($buffer)) {
            return json_encode(['ok' => false, 'error' => 'Ungültiger Befehl'], JSON_UNESCAPED_UNICODE);
        }

        try {
            $result = $this->handleChildCommand($buffer);

            return json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $this->SetValue('LastError', $e->getMessage());

            return json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function TestConnection(): string
    {
        try {
            $client = $this->createClient();
            $result = $client->discover();
            $count = count($result['devices']);
            $this->SetValue('Reachable', true);
            $this->SetValue('LastError', '');
            $this->SetValue('DeviceCount', $count);
            $this->SetStatus(self::IS_ACTIVE);

            return 'OK — ' . $count . ' Gerät(e) gefunden';
        } catch (Throwable $e) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(self::IS_UNREACHABLE);

            return 'Fehler: ' . $e->getMessage();
        }
    }

    public function ScanAndCreateDevices(): string
    {
        try {
            $devices = $this->refreshDeviceCache();
            $created = 0;
            $updated = 0;
            $skipped = 0;
            foreach ($devices as $deviceId => $deviceData) {
                $model = GardenaSmartDevices::extractModelNumber($deviceData);
                $info = GardenaSmartDevices::info($model);
                $moduleId = GardenaSmartDevices::moduleIdForKind($info['kind']);
                if ($moduleId === null) {
                    $skipped++;
                    continue;
                }
                $existing = $this->findChildByDeviceId($deviceId);
                $name = GardenaSmartDevices::extractDisplayName($deviceData, $info['name']);
                if ($existing > 0) {
                    IPS_SetName($existing, $name);
                    IPS_SetProperty($existing, 'DeviceId', $deviceId);
                    IPS_SetProperty($existing, 'ModelNumber', $model);
                    IPS_SetProperty($existing, 'Generation', $info['generation']);
                    IPS_SetProperty($existing, 'ValveCount', $info['valves']);
                    IPS_ApplyChanges($existing);
                    $updated++;
                    continue;
                }
                $iid = IPS_CreateInstance($moduleId);
                IPS_SetName($iid, $name);
                IPS_SetParent($iid, $this->InstanceID);
                IPS_ConnectInstance($iid, $this->InstanceID);
                IPS_SetProperty($iid, 'DeviceId', $deviceId);
                IPS_SetProperty($iid, 'ModelNumber', $model);
                IPS_SetProperty($iid, 'Generation', $info['generation']);
                IPS_SetProperty($iid, 'ValveCount', $info['valves']);
                IPS_ApplyChanges($iid);
                $created++;
            }
            $this->pushStateToChildren($devices);
            $this->rebuildScheduleOverview($devices);

            return sprintf(
                'Scan OK — neu: %d, aktualisiert: %d, übersprungen: %d',
                $created,
                $updated,
                $skipped
            );
        } catch (Throwable $e) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(self::IS_UNREACHABLE);

            return 'Fehler: ' . $e->getMessage();
        }
    }

    public function UpdateValues(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Reachable', false);
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }
        if (!$this->hasValidConfig()) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', 'Host oder Passwort fehlt');
            $this->SetStatus(self::IS_INVALID);

            return;
        }
        try {
            $devices = $this->refreshDeviceCache();
            $this->pushStateToChildren($devices);
            $this->rebuildScheduleOverview($devices);
            $this->rebuildIpsScheduleOverview();
            $this->SetValue('Reachable', true);
            $this->SetValue('LastError', '');
            $this->SetValue('DeviceCount', count($devices));
            $this->SetStatus(self::IS_ACTIVE);
        } catch (Throwable $e) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(self::IS_UNREACHABLE);
        }
        $this->SetSummary($this->buildSummary());
    }

    /**
     * Called by children after IPS schedule property changes.
     */
    public function RefreshIpsScheduleOverview(): void
    {
        $this->rebuildIpsScheduleOverview();
    }

    /** @param array<string, mixed> $command */
    private function handleChildCommand(array $command): mixed
    {
        $action = (string) ($command['action'] ?? '');
        $deviceId = (string) ($command['deviceId'] ?? '');
        if ($deviceId === '') {
            throw new RuntimeException('deviceId fehlt');
        }
        $client = $this->createClient();
        $generation = (int) ($command['generation'] ?? 2);
        $valveId = (int) ($command['valveId'] ?? 0);
        $duration = (int) ($command['duration'] ?? 1800);

        $replies = match ($action) {
            'startValve' => $generation >= 2
                ? $client->startValveGen2($deviceId, $valveId, $duration)
                : $client->setWateringTimerGen1($deviceId, $valveId, $duration),
            'stopValve' => $generation >= 2
                ? $client->stopValveGen2($deviceId, $valveId)
                : $client->setWateringTimerGen1($deviceId, $valveId, 0),
            'powerOn' => $client->setPowerTimer($deviceId, $duration > 0 ? $duration : 86400),
            'powerOff' => $client->setPowerTimer($deviceId, 0),
            default => throw new RuntimeException('Unbekannte Aktion: ' . $action),
        };

        // Refresh after command
        $this->UpdateValues();

        return $replies;
    }

    /** @return array<string, array<string, mixed>> */
    private function refreshDeviceCache(): array
    {
        $client = $this->createClient();
        $result = $client->discover();
        $devices = $result['devices'];
        $this->WriteAttributeString('DeviceCache', json_encode($devices, JSON_UNESCAPED_UNICODE) ?: '{}');

        return $devices;
    }

    /** @param array<string, array<string, mixed>> $devices */
    private function pushStateToChildren(array $devices): void
    {
        foreach ($devices as $deviceId => $deviceData) {
            $payload = [
                'type' => 'deviceState',
                'deviceId' => $deviceId,
                'data' => $deviceData,
            ];
            $this->SendDataToChildren(json_encode([
                'DataID' => GardenaSmartGuids::TX_CHILDREN,
                'Buffer' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /** @param array<string, array<string, mixed>> $devices */
    private function rebuildScheduleOverview(array $devices): void
    {
        $lines = [];
        foreach ($devices as $deviceId => $data) {
            $model = GardenaSmartDevices::extractModelNumber($data);
            $info = GardenaSmartDevices::info($model);
            $name = GardenaSmartDevices::extractDisplayName($data, $info['name']);
            $sched = GardenaSmartDevices::formatDeviceSchedules($data);
            if ($sched === []) {
                $lines[] = $name . ' (' . $deviceId . '): keine Geräte-App-Zeitpläne';
                continue;
            }
            $lines[] = $name . ' — Geräte-App:';
            foreach ($sched as $line) {
                $lines[] = '  · ' . $line;
            }
        }
        $ips = trim((string) $this->GetValue('ScheduleOverview'));
        // Keep device-app section; IPS section rebuilt separately and merged.
        $deviceBlock = implode("\n", $lines);
        $this->SetValue('ScheduleOverview', $deviceBlock);
        $this->WriteAttributeString('DeviceScheduleBlock', $deviceBlock);
        $this->rebuildIpsScheduleOverview();
    }

    private function rebuildIpsScheduleOverview(): void
    {
        $deviceBlock = '';
        try {
            $deviceBlock = $this->ReadAttributeString('DeviceScheduleBlock');
        } catch (Throwable) {
            $deviceBlock = '';
        }
        $ipsLines = ['IPS-Zeitpläne (Child-Module):'];
        $any = false;
        foreach ([GardenaSmartGuids::VALVE, GardenaSmartGuids::POWER, GardenaSmartGuids::SENSOR] as $moduleId) {
            foreach (IPS_GetInstanceListByModuleID($moduleId) as $childId) {
                $conn = IPS_GetInstance($childId)['ConnectionID'] ?? 0;
                if ((int) $conn !== $this->InstanceID) {
                    continue;
                }
                $name = IPS_GetName($childId);
                try {
                    $json = (string) IPS_GetProperty($childId, 'ScheduleRules');
                } catch (Throwable) {
                    continue;
                }
                $rules = json_decode($json, true);
                if (!is_array($rules) || $rules === []) {
                    continue;
                }
                foreach ($rules as $rule) {
                    if (!is_array($rule) || empty($rule['active'])) {
                        continue;
                    }
                    $any = true;
                    $valve = isset($rule['valve']) ? (' V' . $rule['valve']) : '';
                    $ipsLines[] = sprintf(
                        '  · %s%s %s–%s %s',
                        $name,
                        $valve,
                        (string) ($rule['start'] ?? '?'),
                        (string) ($rule['end'] ?? '?'),
                        $this->daysFromRule($rule)
                    );
                }
            }
        }
        if (!$any) {
            $ipsLines[] = '  · (keine aktiven IPS-Regeln)';
        }
        $text = implode("\n", $ipsLines);
        if ($deviceBlock !== '') {
            $text .= "\n\nGeräte-App (read-only):\n" . $deviceBlock;
        }
        $this->SetValue('ScheduleOverview', $text);
    }

    /** @param array<string, mixed> $rule */
    private function daysFromRule(array $rule): string
    {
        $map = ['mo' => 'Mo', 'tu' => 'Di', 'we' => 'Mi', 'th' => 'Do', 'fr' => 'Fr', 'sa' => 'Sa', 'so' => 'So'];
        $parts = [];
        foreach ($map as $k => $label) {
            if (!empty($rule[$k])) {
                $parts[] = $label;
            }
        }

        return $parts === [] ? '' : '(' . implode(',', $parts) . ')';
    }

    private function findChildByDeviceId(string $deviceId): int
    {
        foreach ([GardenaSmartGuids::VALVE, GardenaSmartGuids::POWER, GardenaSmartGuids::SENSOR] as $moduleId) {
            foreach (IPS_GetInstanceListByModuleID($moduleId) as $childId) {
                $conn = IPS_GetInstance($childId)['ConnectionID'] ?? 0;
                if ((int) $conn !== $this->InstanceID) {
                    continue;
                }
                try {
                    if ((string) IPS_GetProperty($childId, 'DeviceId') === $deviceId) {
                        return $childId;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return 0;
    }

    private function createClient(): GardenaSmartClient
    {
        return new GardenaSmartClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyBoolean('TlsInsecure')
        );
    }

    private function hasValidConfig(): bool
    {
        return trim($this->ReadPropertyString('Host')) !== ''
            && trim($this->ReadPropertyString('Password')) !== '';
    }

    private function configureTimer(): void
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->hasValidConfig()) {
            $this->SetTimerInterval('Update', 0);

            return;
        }
        $sec = max(self::UPDATE_MIN_SEC, $this->ReadPropertyInteger('UpdateIntervalSeconds'));
        $this->SetTimerInterval('Update', $sec * 1000);
    }

    private function updateInstanceStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }
        if (!$this->hasValidConfig()) {
            $this->SetStatus(self::IS_INVALID);

            return;
        }
        $this->SetStatus(self::IS_ACTIVE);
    }

    private function buildSummary(): string
    {
        $host = trim($this->ReadPropertyString('Host'));

        return $host !== '' ? $host : 'kein Host';
    }
}
