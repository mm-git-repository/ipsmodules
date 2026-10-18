<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/CatalogStore.php';

class HomeDisplay extends IPSModuleStrict
{
    private const MODULE_ID = '{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}';
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 2;
    private const HOOK = '/hook/homedisplay';
    private const CONTRACT = 1;
    private const FIRMWARE_VERSION = '0.1.0';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyBoolean('ServeAdapter', false);
        $this->RegisterPropertyString('AdapterHost', '');
        $this->RegisterPropertyString('ClaimCode', '');
        $this->RegisterPropertyString('DeviceToken', '');
        $this->RegisterPropertyString('LayoutId', 'layout-start');
        $this->RegisterPropertyString('ConfigId', 'config-standard');
        $this->RegisterPropertyInteger('IdleMode', 0);
        $this->RegisterPropertyBoolean('NightDim', true);
        $this->RegisterPropertyInteger('DayBrightness', 70);
        $this->RegisterPropertyInteger('NightBrightness', 15);
        $this->RegisterPropertyInteger('NightFrom', 22);
        $this->RegisterPropertyInteger('NightTo', 6);
        $this->RegisterPropertyInteger('StateIntervalSec', 3);
        $this->RegisterPropertyInteger('IdleStateIntervalSec', 15);
        $this->RegisterPropertyInteger('MaintenanceFrom', 3);
        $this->RegisterPropertyInteger('MaintenanceTo', 5);

        $this->RegisterVariableString('PairingState', 'Kopplung', '', 1);
        $this->RegisterVariableString('AdapterUrl', 'Adapter-URL', '', 2);
        $this->RegisterVariableString('PanelStatus', 'Panelstatus', '', 3);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 4);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetValue('ModuleVersion', $this->versionLabel());
        $catalog = HomeDisplayCatalogStore::load();
        $this->updateFormOptions($catalog);
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->unregisterAdapterHook();
            return;
        }
        if ($this->ReadPropertyBoolean('ServeAdapter')) {
            try {
                $this->registerAdapterHook();
                $this->SetValue('AdapterUrl', $this->adapterBaseUrl());
            } catch (Throwable $e) {
                $this->SetStatus(201);
                $this->SendDebug('Adapter', $e->getMessage(), 0);
                return;
            }
        } else {
            $this->unregisterAdapterHook();
            $this->SetValue('AdapterUrl', '');
        }
        $this->refreshPairingLabel();
        $this->SetStatus(102);
        $this->subscribeLayoutVariables();
    }

    public function GetConfigurationForm(): string
    {
        $raw = (string) @file_get_contents(__DIR__ . '/form.json');
        $form = json_decode($raw, true);
        if (!is_array($form) || !is_array($form['elements'] ?? null)) {
            return $raw !== '' ? $raw : '{"elements":[]}';
        }
        $catalog = HomeDisplayCatalogStore::load();
        foreach ($form['elements'] as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['name'] ?? '') === 'LayoutId') {
                $element['options'] = $this->optionsFrom($catalog['layouts']);
            }
            if (($element['name'] ?? '') === 'ConfigId') {
                $element['options'] = $this->optionsFrom($catalog['configs']);
            }
        }
        unset($element);
        $encoded = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $raw : $encoded;
    }

    public function Pair(): string
    {
        $code = strtoupper(trim($this->ReadPropertyString('ClaimCode')));
        if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
            return 'Kopplungscode muss sechs Buchstaben oder Ziffern haben.';
        }
        foreach ($this->siblingIds() as $id) {
            if ($id === $this->InstanceID) {
                continue;
            }
            $other = strtoupper(trim((string) IPS_GetProperty($id, 'ClaimCode')));
            $token = (string) IPS_GetProperty($id, 'DeviceToken');
            if ($other === $code && $token !== '') {
                return 'Dieser Code ist bereits einem anderen Panel zugeordnet.';
            }
        }
        if ($this->ReadPropertyString('DeviceToken') === '') {
            IPS_SetProperty($this->InstanceID, 'DeviceToken', bin2hex(random_bytes(16)));
        }
        IPS_ApplyChanges($this->InstanceID);
        $this->refreshPairingLabel();
        return 'Code übernommen. Das Panel erhält den Token beim nächsten Abruf.';
    }

    public function Unpair(): string
    {
        IPS_SetProperty($this->InstanceID, 'DeviceToken', '');
        IPS_SetProperty($this->InstanceID, 'ClaimCode', '');
        IPS_ApplyChanges($this->InstanceID);
        $this->SetValue('PanelStatus', 'Nicht gekoppelt');
        return 'Kopplung gelöst.';
    }

    public function GetService(): string
    {
        return $this->encode($this->serviceInfo());
    }

    public function ListCatalog(): string
    {
        return $this->encode($this->catalogSummary());
    }

    public function ListVariables(): string
    {
        $rows = [];
        foreach (IPS_GetObjectList() as $id) {
            $object = @IPS_GetObject((int) $id);
            if (!is_array($object) || (int) ($object['ObjectType'] ?? -1) !== 2) {
                continue;
            }
            $variable = @IPS_GetVariable((int) $id);
            if (!is_array($variable)) {
                continue;
            }
            $rows[] = [
                'id' => (int) $id,
                'name' => (string) ($object['ObjectName'] ?? ''),
                'type' => (int) ($variable['VariableType'] ?? -1),
                'action' => (int) ($variable['VariableAction'] ?? 0) > 0 || (int) ($variable['VariableCustomAction'] ?? 0) > 0,
            ];
            if (count($rows) >= 2000) {
                break;
            }
        }
        return $this->encode(['variables' => $rows]);
    }

    public function SaveLayout(string $Json): string
    {
        return $this->saveIncoming('layouts', $Json, false);
    }

    public function PublishLayout(string $Json): string
    {
        return $this->saveIncoming('layouts', $Json, true);
    }

    public function SaveConfig(string $Json): string
    {
        return $this->saveIncoming('configs', $Json, false);
    }

    public function PublishConfig(string $Json): string
    {
        return $this->saveIncoming('configs', $Json, true);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $json = is_string($Value) ? $Value : $this->encode(is_array($Value) ? $Value : []);
        switch ($Ident) {
            case 'GetService':
                $this->GetService();
                return;
            case 'ListCatalog':
                $this->ListCatalog();
                return;
            case 'SaveLayout':
                $this->SaveLayout($json);
                return;
            case 'PublishLayout':
                $this->PublishLayout($json);
                return;
            case 'SaveConfig':
                $this->SaveConfig($json);
                return;
            case 'PublishConfig':
                $this->PublishConfig($json);
                return;
            default:
                $this->SendDebug('Editor', 'Unbekannte Aktion ' . $Ident, 0);
        }
    }

    private function saveIncoming(string $bucket, string $json, bool $publish): string
    {
        try {
            $payload = json_decode($json, true);
            $this->storeCatalogItem($bucket, is_array($payload) ? $payload : [], $publish);
            return '{"ok":true}';
        } catch (Throwable $e) {
            $this->SendDebug('Editor', $e->getMessage(), 0);
            return $this->encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{"ok":false}' : $json;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        unset($TimeStamp, $SenderID, $Message, $Data);
        $runtime = $this->runtime();
        $key = (string) $this->InstanceID;
        $runtime[$key]['seq'] = (int) ($runtime[$key]['seq'] ?? 0) + 1;
        $this->saveRuntime($runtime);
    }

    public function ProcessHookData(): void
    {
        try {
            $route = $this->hookRoute();
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method === 'POST' && $route === '/v1/hello') {
                $this->json($this->hello(self::body()));
                return;
            }
            $device = $this->deviceFromToken();
            if ($device === null) {
                http_response_code(401);
                $this->json(['error' => 'token']);
                return;
            }
            if ($method === 'GET' && $route === '/v1/manifest') {
                $this->json($this->manifest($device));
                return;
            }
            if ($method === 'GET' && $route === '/v1/config') {
                $this->json($this->deviceConfig($device));
                return;
            }
            if ($method === 'GET' && $route === '/v1/layout') {
                $this->json($this->deviceLayout($device));
                return;
            }
            if ($method === 'GET' && $route === '/v1/state') {
                $this->json($this->deviceState($device));
                return;
            }
            if ($method === 'GET' && $route === '/v1/firmware') {
                $this->sendFirmware();
                return;
            }
            if ($method === 'POST' && $route === '/v1/actions') {
                $this->json($this->performAction($device, self::body()));
                return;
            }
            if ($method === 'POST' && $route === '/v1/health') {
                $this->rememberHealth($device, self::body());
                $this->json(['ok' => true]);
                return;
            }
            http_response_code(404);
            $this->json(['error' => 'route']);
        } catch (Throwable $e) {
            http_response_code(400);
            $this->SendDebug('Hook', $e->getMessage(), 0);
            $this->json(['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function updateFormOptions(array $catalog): void
    {
        $this->UpdateFormField('LayoutId', 'options', json_encode($this->optionsFrom($catalog['layouts'])));
        $this->UpdateFormField('ConfigId', 'options', json_encode($this->optionsFrom($catalog['configs'])));
    }

    /** @param array<int, mixed> $items @return list<array{caption: string, value: string}> */
    private function optionsFrom(array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['published'])) {
                continue;
            }
            $options[] = [
                'caption' => (string) ($item['name'] ?? $item['id']) . ' (v' . (int) ($item['version'] ?? 0) . ')',
                'value' => (string) $item['id'],
            ];
        }
        return $options;
    }

    /** @param array<string, mixed> $item */
    private function storeCatalogItem(string $bucket, array $item, bool $publish): void
    {
        if ($bucket === 'layouts') {
            HomeDisplayCatalogStore::validateLayout($item);
        } elseif (trim((string) ($item['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Konfiguration ohne Namen.');
        }
        $catalog = HomeDisplayCatalogStore::load();
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            $id = $bucket === 'layouts' ? 'layout-' : 'config-';
            $id .= bin2hex(random_bytes(4));
        }
        $existing = HomeDisplayCatalogStore::find($catalog, $bucket, $id);
        $expected = (int) ($item['baseVersion'] ?? 0);
        $current = (int) ($existing['version'] ?? 0);
        if ($existing !== null && $expected !== $current) {
            throw new RuntimeException('Der Katalogeintrag wurde inzwischen veröffentlicht.');
        }
        $item['id'] = $id;
        $item['version'] = $publish ? $current + 1 : $current;
        $item['published'] = $publish ? true : (bool) ($existing['published'] ?? false);
        unset($item['baseVersion']);
        $replaced = false;
        foreach ($catalog[$bucket] as $index => $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                $catalog[$bucket][$index] = $item;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $catalog[$bucket][] = $item;
        }
        HomeDisplayCatalogStore::save($catalog);
    }

    /** @return array<string, mixed> */
    private function catalogSummary(): array
    {
        $catalog = HomeDisplayCatalogStore::load();
        return [
            'layouts' => array_values(array_map(static function ($item) {
                return is_array($item) ? $item : [];
            }, $catalog['layouts'])),
            'configs' => array_values(array_map(static function ($item) {
                return is_array($item) ? $item : [];
            }, $catalog['configs'])),
            'service' => $this->serviceInfo(),
        ];
    }

    /** @return array<string, mixed> */
    private function serviceInfo(): array
    {
        $adapter = $this->adapterInstanceId();
        return [
            'instanceId' => $adapter,
            'baseUrl' => $adapter > 0 ? $this->adapterBaseUrl() : '',
            'mdns' => 'homedisplay.local',
            'contract' => self::CONTRACT,
        ];
    }

    private function adapterInstanceId(): int
    {
        foreach ($this->siblingIds() as $id) {
            if (IPS_GetProperty($id, 'ServeAdapter')) {
                return $id;
            }
        }
        return 0;
    }

    /** @return list<int> */
    private function siblingIds(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::MODULE_ID);
        return is_array($ids) ? array_map('intval', $ids) : [$this->InstanceID];
    }

    private function adapterBaseUrl(): string
    {
        $host = trim($this->ReadPropertyString('AdapterHost'));
        if ($host === '') {
            $host = gethostbyname(gethostname());
        }
        $https = false;
        if (function_exists('IPS_GetOption')) {
            $ssl = @IPS_GetOption('WebServer::SSL');
            $https = $ssl === true || $ssl === 1 || $ssl === '1';
        }
        return ($https ? 'https://' : 'http://') . $host . ':3777' . self::HOOK;
    }

    private function registerAdapterHook(): void
    {
        if (method_exists($this, 'RegisterHook')) {
            $this->RegisterHook(self::HOOK);
            return;
        }
        $this->updateWebHookControl(true);
    }

    private function unregisterAdapterHook(): void
    {
        if (method_exists($this, 'UnregisterHook')) {
            @$this->UnregisterHook(self::HOOK);
            return;
        }
        $this->updateWebHookControl(false);
    }

    private function updateWebHookControl(bool $register): void
    {
        $ids = @IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (!is_array($ids) || $ids === []) {
            if ($register) {
                throw new RuntimeException('WebHook Control nicht gefunden.');
            }
            return;
        }
        $controlId = (int) $ids[0];
        $hooks = json_decode((string) IPS_GetProperty($controlId, 'Hooks'), true);
        $hooks = is_array($hooks) ? $hooks : [];
        $kept = [];
        $present = false;
        foreach ($hooks as $hook) {
            if (!is_array($hook) || ($hook['Hook'] ?? '') !== self::HOOK) {
                $kept[] = $hook;
                continue;
            }
            if ($register && (int) ($hook['TargetID'] ?? 0) === $this->InstanceID) {
                $present = true;
                $kept[] = $hook;
            } elseif ((int) ($hook['TargetID'] ?? 0) !== $this->InstanceID) {
                $kept[] = $hook;
            }
        }
        if ($register && !$present) {
            $kept[] = ['Hook' => self::HOOK, 'TargetID' => $this->InstanceID];
        }
        if ($kept === $hooks) {
            return;
        }
        IPS_SetProperty($controlId, 'Hooks', json_encode($kept));
        IPS_ApplyChanges($controlId);
    }

    private function hookRoute(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        $pos = strpos($path, self::HOOK);
        if ($pos === false) {
            return $path;
        }
        $route = substr($path, $pos + strlen(self::HOOK));
        return $route === '' ? '/' : $route;
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function hello(array $body): array
    {
        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
            throw new InvalidArgumentException('Kopplungscode ungültig.');
        }
        foreach ($this->siblingIds() as $id) {
            if (!IPS_GetProperty($id, 'Active')) {
                continue;
            }
            $claim = strtoupper(trim((string) IPS_GetProperty($id, 'ClaimCode')));
            $token = (string) IPS_GetProperty($id, 'DeviceToken');
            if ($claim === $code && $token !== '') {
                return [
                    'status' => 'paired',
                    'token' => $token,
                    'deviceId' => (string) $id,
                    'baseUrl' => $this->adapterBaseUrl(),
                ];
            }
        }
        return ['status' => 'pending'];
    }

    /** @return array{id: int, token: string}|null */
    private function deviceFromToken(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/Bearer\s+([a-f0-9]{32})/i', $header, $match)) {
            return null;
        }
        $token = strtolower($match[1]);
        foreach ($this->siblingIds() as $id) {
            if (strtolower((string) IPS_GetProperty($id, 'DeviceToken')) === $token && IPS_GetProperty($id, 'Active')) {
                return ['id' => $id, 'token' => $token];
            }
        }
        return null;
    }

    /** @param array{id: int, token: string} $device @return array<string, mixed> */
    private function manifest(array $device): array
    {
        $config = $this->assigned($device['id'], 'configs', 'ConfigId');
        $layout = $this->assigned($device['id'], 'layouts', 'LayoutId');
        $firmware = $this->firmwareDescriptor();
        return [
            'contract' => self::CONTRACT,
            'now' => time(),
            'firmware' => [
                'version' => $firmware['version'],
                'sha256' => $firmware['sha256'],
                'url' => '/v1/firmware',
                'available' => $firmware['available'],
            ],
            'config' => [
                'version' => (int) ($config['version'] ?? 0),
                'sha256' => $this->hash($this->configDocument($device['id'], $config)),
            ],
            'layout' => [
                'version' => (int) ($layout['version'] ?? 0),
                'sha256' => $layout === null ? '' : $this->hash(HomeDisplayCatalogStore::publicLayout($layout)),
                'minFirmware' => (string) ($layout['minFirmware'] ?? '0.1.0'),
            ],
        ];
    }

    /** @param array{id: int, token: string} $device @return array<string, mixed> */
    private function deviceConfig(array $device): array
    {
        $config = $this->assigned($device['id'], 'configs', 'ConfigId');
        return $this->configDocument($device['id'], $config);
    }

    /** @param array<string, mixed>|null $config @return array<string, mixed> */
    private function configDocument(int $instanceId, ?array $config): array
    {
        $idle = (int) IPS_GetProperty($instanceId, 'IdleMode');
        $document = [
            'contract' => self::CONTRACT,
            'id' => (string) ($config['id'] ?? IPS_GetProperty($instanceId, 'ConfigId')),
            'version' => (int) ($config['version'] ?? 1),
            'idleMode' => $idle,
            'nightDim' => (bool) IPS_GetProperty($instanceId, 'NightDim'),
            'dayBrightness' => (int) IPS_GetProperty($instanceId, 'DayBrightness'),
            'nightBrightness' => (int) IPS_GetProperty($instanceId, 'NightBrightness'),
            'nightFrom' => (int) IPS_GetProperty($instanceId, 'NightFrom'),
            'nightTo' => (int) IPS_GetProperty($instanceId, 'NightTo'),
            'stateIntervalSec' => max(2, (int) IPS_GetProperty($instanceId, 'StateIntervalSec')),
            'idleStateIntervalSec' => max(5, (int) IPS_GetProperty($instanceId, 'IdleStateIntervalSec')),
            'maintenanceFrom' => (int) IPS_GetProperty($instanceId, 'MaintenanceFrom'),
            'maintenanceTo' => (int) IPS_GetProperty($instanceId, 'MaintenanceTo'),
        ];
        if (is_array($config)) {
            foreach (['idleMode', 'nightDim', 'dayBrightness', 'nightBrightness', 'nightFrom', 'nightTo', 'stateIntervalSec', 'idleStateIntervalSec', 'maintenanceFrom', 'maintenanceTo'] as $key) {
                if (array_key_exists($key, $config)) {
                    $document[$key] = $config[$key];
                }
            }
        }
        return $document;
    }

    /** @param array{id: int, token: string} $device @return array<string, mixed> */
    private function deviceLayout(array $device): array
    {
        $layout = $this->assigned($device['id'], 'layouts', 'LayoutId');
        if ($layout === null || empty($layout['published'])) {
            return ['contract' => self::CONTRACT, 'id' => '', 'version' => 0, 'columns' => 2, 'pages' => []];
        }
        $public = HomeDisplayCatalogStore::publicLayout($layout);
        $public['contract'] = self::CONTRACT;
        return $public;
    }

    /** @param array{id: int, token: string} $device @return array<string, mixed> */
    private function deviceState(array $device): array
    {
        $layout = $this->assigned($device['id'], 'layouts', 'LayoutId');
        $runtime = $this->runtime();
        $pending = $runtime[(string) $device['id']]['pending'] ?? [];
        $pending = is_array($pending) ? $pending : [];
        $tiles = [];
        $pages = is_array($layout) ? ($layout['pages'] ?? []) : [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (!is_array($tile)) {
                    continue;
                }
                $tileId = (string) $tile['tileId'];
                $variableId = (int) ($tile['variableId'] ?? 0);
                $text = '—';
                $quality = 'empty';
                if ($variableId > 0 && IPS_VariableExists($variableId)) {
                    $text = $this->formatVariable($variableId, $tile);
                    $quality = 'ok';
                }
                $tiles[] = [
                    'tileId' => $tileId,
                    'text' => $text,
                    'quality' => $quality,
                    'pending' => isset($pending[$tileId]),
                ];
            }
        }
        return [
            'contract' => self::CONTRACT,
            'seq' => (int) ($runtime[(string) $device['id']]['seq'] ?? 0),
            'now' => time(),
            'tiles' => $tiles,
        ];
    }

    /** @param array<string, mixed> $tile */
    private function formatVariable(int $variableId, array $tile): string
    {
        $raw = GetValue($variableId);
        $decimals = max(0, (int) ($tile['decimals'] ?? 0));
        if (is_bool($raw)) {
            $text = $raw ? 'Ein' : 'Aus';
        } elseif (is_int($raw) || is_float($raw)) {
            $text = number_format((float) $raw, $decimals, ',', '');
        } else {
            $text = trim((string) $raw);
        }
        $unit = trim((string) ($tile['unit'] ?? ''));
        return $unit === '' ? $text : $text . ' ' . $unit;
    }

    /** @param array{id: int, token: string} $device @param array<string, mixed> $body @return array<string, mixed> */
    private function performAction(array $device, array $body): array
    {
        $requestId = trim((string) ($body['requestId'] ?? ''));
        $tileId = trim((string) ($body['tileId'] ?? ''));
        $actionId = trim((string) ($body['actionId'] ?? ''));
        if ($requestId === '' || $tileId === '' || $actionId === '') {
            throw new InvalidArgumentException('Aktion unvollständig.');
        }
        $runtime = $this->runtime();
        $key = (string) $device['id'];
        $seen = $runtime[$key]['requests'] ?? [];
        $seen = is_array($seen) ? $seen : [];
        if (in_array($requestId, $seen, true)) {
            return ['ok' => true, 'duplicate' => true];
        }
        $tile = $this->findTile($device['id'], $tileId);
        if ($tile === null || (string) ($tile['actionId'] ?? '') !== $actionId) {
            throw new InvalidArgumentException('Aktion nicht freigegeben.');
        }
        $variableId = (int) ($tile['variableId'] ?? 0);
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            throw new InvalidArgumentException('Keine Zielvariable.');
        }
        $variable = IPS_GetVariable($variableId);
        if ((int) ($variable['VariableAction'] ?? 0) <= 0 && (int) ($variable['VariableCustomAction'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Variable ist nicht schaltbar.');
        }
        $value = $body['value'] ?? null;
        if (is_bool(GetValue($variableId)) && !is_bool($value)) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        RequestAction($variableId, $value);
        $seen[] = $requestId;
        $runtime[$key]['requests'] = array_slice($seen, -50);
        $pending = is_array($runtime[$key]['pending'] ?? null) ? $runtime[$key]['pending'] : [];
        $pending[$tileId] = $requestId;
        $runtime[$key]['pending'] = $pending;
        $runtime[$key]['seq'] = (int) ($runtime[$key]['seq'] ?? 0) + 1;
        $this->saveRuntime($runtime);
        return ['ok' => true];
    }

    /** @return array<string, mixed>|null */
    private function findTile(int $instanceId, string $tileId): ?array
    {
        $layout = $this->assigned($instanceId, 'layouts', 'LayoutId');
        if (!is_array($layout)) {
            return null;
        }
        foreach ($layout['pages'] ?? [] as $page) {
            foreach ($page['tiles'] ?? [] as $tile) {
                if (is_array($tile) && (string) ($tile['tileId'] ?? '') === $tileId) {
                    return $tile;
                }
            }
        }
        return null;
    }

    /** @param array{id: int, token: string} $device @param array<string, mixed> $body */
    private function rememberHealth(array $device, array $body): void
    {
        $version = (string) ($body['firmware'] ?? '');
        $text = 'Firmware ' . $version;
        if (!empty($body['updateError'])) {
            $text .= ', Update: ' . (string) $body['updateError'];
        }
        $runtime = $this->runtime();
        $runtime[(string) $device['id']]['pending'] = [];
        $this->saveRuntime($runtime);
        $statusId = @IPS_GetObjectIDByIdent('PanelStatus', $device['id']);
        if (is_int($statusId) && $statusId > 0) {
            SetValue($statusId, $text);
        }
    }

    /** @return array<string, mixed> */
    private function runtime(): array
    {
        $path = dirname(HomeDisplayCatalogStore::path()) . DIRECTORY_SEPARATOR . 'runtime.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $runtime */
    private function saveRuntime(array $runtime): void
    {
        $path = dirname(HomeDisplayCatalogStore::path()) . DIRECTORY_SEPARATOR . 'runtime.json';
        $json = json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($path, $json, LOCK_EX);
        }
    }

    /** @return array<string, mixed>|null */
    private function assigned(int $instanceId, string $bucket, string $property): ?array
    {
        $catalog = HomeDisplayCatalogStore::load();
        $id = (string) IPS_GetProperty($instanceId, $property);
        $item = HomeDisplayCatalogStore::find($catalog, $bucket, $id);
        if ($item === null || empty($item['published'])) {
            return null;
        }
        return $item;
    }

    /** @param mixed $value */
    private function hash($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json === false ? '' : $json);
    }

    /** @return array{version: string, sha256: string, available: bool, path: string} */
    private function firmwareDescriptor(): array
    {
        $versionFile = __DIR__ . '/firmware/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : self::FIRMWARE_VERSION;
        $path = __DIR__ . '/firmware/build/homedisplay.bin';
        $available = is_file($path);
        return [
            'version' => $version !== '' ? $version : self::FIRMWARE_VERSION,
            'sha256' => $available ? hash_file('sha256', $path) : '',
            'available' => $available,
            'path' => $path,
        ];
    }

    private function sendFirmware(): void
    {
        $firmware = $this->firmwareDescriptor();
        if (!$firmware['available']) {
            http_response_code(404);
            $this->json(['error' => 'Firmwareabbild fehlt. Zuerst lokal bauen.']);
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) filesize($firmware['path']));
        readfile($firmware['path']);
    }

    private function refreshPairingLabel(): void
    {
        $token = $this->ReadPropertyString('DeviceToken');
        $code = strtoupper(trim($this->ReadPropertyString('ClaimCode')));
        if ($token !== '' && $code !== '') {
            $this->SetValue('PairingState', 'Wartet auf Panel ' . $code);
            return;
        }
        $this->SetValue('PairingState', 'Code vom Panel eintragen');
    }

    private function subscribeLayoutVariables(): void
    {
        $layout = $this->assigned($this->InstanceID, 'layouts', 'LayoutId');
        foreach ($layout['pages'] ?? [] as $page) {
            foreach ($page['tiles'] ?? [] as $tile) {
                $variableId = (int) ($tile['variableId'] ?? 0);
                if ($variableId > 0 && IPS_VariableExists($variableId)) {
                    $this->RegisterMessage($variableId, 10603);
                }
            }
        }
    }

    private function versionLabel(): string
    {
        $version = self::MODULE_VERSION;
        $build = self::MODULE_BUILD;
        if (IPS_LibraryExists(self::LIBRARY_ID)) {
            $library = IPS_GetLibrary(self::LIBRARY_ID);
            if (isset($library['Version']) && is_string($library['Version']) && $library['Version'] !== '') {
                $version = $library['Version'];
            }
            if (isset($library['Build']) && is_numeric($library['Build'])) {
                $build = max($build, (int) $library['Build']);
            }
        }
        return $version . ' (Build ' . $build . ')';
    }
}
