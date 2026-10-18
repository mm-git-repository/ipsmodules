<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/CatalogStore.php';
require_once __DIR__ . '/libs/PanelActionGuard.php';

class HomeDisplay extends IPSModuleStrict
{
    private const MODULE_ID = '{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}';
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 11;
    private const ADMIN_MODULE = '{A91D4C52-6E70-4B3A-8F15-3D9C7B2E64A0}';
    private const HOOK = '/hook/homedisplay';
    private const CONTRACT = 1;
    private const FIRMWARE_VERSION = '0.1.0';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
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
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('FallbackTiles', '[]');

        $this->RegisterVariableString('PairingState', 'Kopplung', '', 1);
        $this->RegisterVariableString('AdapterUrl', 'Dienstadresse', '', 2);
        $this->RegisterVariableString('PanelStatus', 'Anzeigestatus', '', 3);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 4);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if ($this->ReadPropertyString('DeviceId') === '') {
            static $assigning = false;
            if (!$assigning) {
                $assigning = true;
                IPS_SetProperty($this->InstanceID, 'DeviceId', bin2hex(random_bytes(8)));
                IPS_ApplyChanges($this->InstanceID);
                $assigning = false;
                return;
            }
        }
        $this->SetValue('ModuleVersion', $this->versionLabel());
        $catalog = HomeDisplayCatalogStore::load();
        $this->updateFormOptions($catalog);
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->SetValue('AdapterUrl', '');
            $this->syncService();
            return;
        }
        $url = $this->syncService();
        $this->SetValue('AdapterUrl', $url);
        $this->refreshPairingLabel();
        $this->SetStatus($url === '' ? 203 : 102);
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
                return 'Dieser Code ist bereits einer anderen Anzeige zugeordnet.';
            }
        }
        if ($this->ReadPropertyString('DeviceToken') === '') {
            IPS_SetProperty($this->InstanceID, 'DeviceToken', bin2hex(random_bytes(16)));
        }
        IPS_ApplyChanges($this->InstanceID);
        $this->refreshPairingLabel();
        return 'Code übernommen. Die Anzeige erhält den Schlüssel beim nächsten Abruf.';
    }

    public function Unpair(): string
    {
        IPS_SetProperty($this->InstanceID, 'DeviceToken', '');
        IPS_SetProperty($this->InstanceID, 'ClaimCode', '');
        IPS_ApplyChanges($this->InstanceID);
        $this->SetValue('PanelStatus', 'Nicht gekoppelt');
        return 'Kopplung gelöst.';
    }

    /** Manifest, Konfiguration, Ansicht und Werte dieser Anzeige, wie sie die Anzeige abruft. */
    public function Inspect(): string
    {
        $device = ['id' => $this->InstanceID, 'token' => ''];
        return $this->encode([
            'ok' => true,
            'manifest' => $this->manifest($device),
            'config' => $this->deviceConfig($device),
            'layout' => $this->deviceLayout($device),
            'state' => $this->deviceState($device),
        ]);
    }

    /** Führt eine freigegebene Kachelaktion dieser Anzeige aus. Leerer Wert schaltet um. */
    public function SimulateAction(string $TileId, string $ActionId, string $Value): string
    {
        try {
            $body = [
                'tileId' => $TileId,
                'actionId' => $ActionId,
                'requestId' => bin2hex(random_bytes(8)),
            ];
            if ($Value !== '' && is_numeric($Value)) {
                $body['value'] = str_contains($Value, '.') ? (float) $Value : (int) $Value;
            }
            return $this->encode($this->performAction(['id' => $this->InstanceID, 'token' => ''], $body));
        } catch (Throwable $e) {
            return $this->encode(['ok' => false, 'error' => $e->getMessage()]);
        }
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
        $names = [];
        foreach (IPS_GetObjectList() as $id) {
            $object = @IPS_GetObject((int) $id);
            if (!is_array($object) || (int) ($object['ObjectType'] ?? -1) !== 2) {
                continue;
            }
            $variable = @IPS_GetVariable((int) $id);
            if (!is_array($variable)) {
                continue;
            }
            $profile = (string) ($variable['VariableCustomProfile'] ?? '');
            if ($profile === '') {
                $profile = (string) ($variable['VariableProfile'] ?? '');
            }
            $rows[] = [
                'id' => (int) $id,
                'name' => (string) ($object['ObjectName'] ?? ''),
                'path' => $this->objectPath((int) ($object['ParentID'] ?? 0), $names),
                'category' => $this->categoryName((int) ($object['ParentID'] ?? 0)),
                'profile' => $profile,
                'type' => (int) ($variable['VariableType'] ?? -1),
                'action' => (int) ($variable['VariableAction'] ?? 0) > 0 || (int) ($variable['VariableCustomAction'] ?? 0) > 0,
                'actionKind' => $this->actionKind($variable),
            ];
            if (count($rows) >= 5000) {
                break;
            }
        }
        usort($rows, static function ($left, $right) {
            $byPath = strcasecmp($left['path'], $right['path']);
            return $byPath !== 0 ? $byPath : strcasecmp($left['name'], $right['name']);
        });
        return $this->encode(['variables' => $rows]);
    }

    public function ListImages(): string
    {
        return $this->encode(['images' => HomeDisplayCatalogStore::listImages()]);
    }

    public function SaveImage(string $Name, string $Base64): string
    {
        try {
            $data = $Base64;
            if (str_contains($data, ',')) {
                $data = substr($data, strpos($data, ',') + 1);
            }
            $binary = base64_decode($data, true);
            if (!is_string($binary)) {
                throw new InvalidArgumentException('Bilddaten konnten nicht gelesen werden.');
            }
            return $this->encode(['ok' => true, 'image' => HomeDisplayCatalogStore::saveImage($Name, $binary)]);
        } catch (Throwable $e) {
            return $this->encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function DeleteImage(string $Id): string
    {
        try {
            HomeDisplayCatalogStore::deleteImage($Id);
            return '{"ok":true}';
        } catch (Throwable $e) {
            return $this->encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function GetImage(string $Id): string
    {
        if (!HomeDisplayCatalogStore::imageExists($Id)) {
            return $this->encode(['ok' => false, 'error' => 'Bild nicht gefunden.']);
        }
        $path = HomeDisplayCatalogStore::imagePath($Id);
        return $this->encode([
            'ok' => true,
            'mime' => str_ends_with($path, '.jpg') ? 'image/jpeg' : 'image/png',
            'data' => base64_encode((string) file_get_contents($path)),
        ]);
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
            case 'ApplyPreset':
                $this->ApplyPreset(is_string($Value) ? $Value : '');
                return;
            case 'PublishFallback':
                $this->PublishFallback();
                return;
            case 'RestartPanel':
                $this->RestartPanel();
                return;
            case 'Resynchronize':
                $this->Resynchronize();
                return;
            case 'SendPreview':
                $this->SendPreview();
                return;
            case 'ShowTestPattern':
                $this->ShowTestPattern((bool) $Value);
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

    public function Destroy(): void
    {
        $admin = $this->adminInstanceId();
        if ($admin > 0 && function_exists('HDADM_MoveService')) {
            try {
                HDADM_MoveService($admin, $this->InstanceID);
            } catch (Throwable $e) {
                $this->SendDebug('Dienst', $e->getMessage(), 0);
            }
        }
        parent::Destroy();
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
            if ($method === 'GET' && str_starts_with($route, '/v1/images/')) {
                $this->sendImage(substr($route, strlen('/v1/images/')));
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
            HomeDisplayCatalogStore::validateLayout($item, $publish);
        } elseif ($bucket === 'configs') {
            HomeDisplayPanelSchema::validateConfig($item);
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

    /** Die Verwaltungsinstanz hält die globalen Einstellungen und richtet den Dienst ein. */
    private function adminInstanceId(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::ADMIN_MODULE);
        if (!is_array($ids)) {
            return 0;
        }
        foreach ($ids as $id) {
            if (IPS_GetProperty((int) $id, 'Active')) {
                return (int) $id;
            }
        }
        return 0;
    }

    /** Lässt die Verwaltung den Dienst einrichten und liefert dessen Adresse, leer ohne Verwaltung. */
    private function syncService(): string
    {
        $admin = $this->adminInstanceId();
        if ($admin === 0 || !function_exists('HDADM_SyncService')) {
            return '';
        }
        try {
            if (!$this->ReadPropertyBoolean('Active')) {
                return (string) HDADM_MoveService($admin, $this->InstanceID);
            }
            return (string) HDADM_SyncService($admin);
        } catch (Throwable $e) {
            $this->SendDebug('Dienst', $e->getMessage(), 0);
            return '';
        }
    }

    private function adapterInstanceId(): int
    {
        $admin = $this->adminInstanceId();
        if ($admin === 0 || !function_exists('HDADM_GetServiceInstance')) {
            return 0;
        }
        return (int) HDADM_GetServiceInstance($admin);
    }

    /** @return list<int> */
    private function siblingIds(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::MODULE_ID);
        return is_array($ids) ? array_map('intval', $ids) : [$this->InstanceID];
    }

    private function adapterBaseUrl(): string
    {
        $admin = $this->adminInstanceId();
        if ($admin === 0 || !function_exists('HDADM_GetServiceUrl')) {
            return '';
        }
        return (string) HDADM_GetServiceUrl($admin);
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
                $this->rememberDeviceReport($id, $body);
                return [
                    'status' => 'paired',
                    'token' => $token,
                    'deviceId' => (string) IPS_GetProperty($id, 'DeviceId'),
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
        $configDocument = $this->configDocument($device['id']);
        $layout = $this->assigned($device['id'], 'layouts', 'LayoutId');
        $layoutDocument = $this->deviceLayout($device);
        $firmware = $this->firmwareDescriptor();
        $runtime = $this->runtime();
        $command = $runtime[(string) $device['id']]['command'] ?? null;
        return [
            'contract' => self::CONTRACT,
            'now' => time(),
            'command' => is_array($command) ? ['id' => (string) ($command['id'] ?? ''), 'name' => (string) ($command['name'] ?? '')] : null,
            'firmware' => [
                'version' => $firmware['version'],
                'sha256' => $firmware['sha256'],
                'signature' => $firmware['signature'],
                'url' => '/v1/firmware',
                'available' => $firmware['available'],
            ],
            'config' => [
                'version' => (int) $configDocument['version'],
                'sha256' => $this->hash($configDocument),
            ],
            'layout' => [
                'version' => (int) ($layoutDocument['version'] ?? 0),
                'sha256' => $this->hash($layoutDocument),
                'minFirmware' => is_array($layout) ? (string) ($layout['minFirmware'] ?? '0.1.0') : '0.1.0',
                'held' => $this->layoutHeld($device['id'], $layout),
            ],
        ];
    }

    /** @param array{id: int, token: string} $device @return array<string, mixed> */
    private function deviceConfig(array $device): array
    {
        return $this->configDocument($device['id']);
    }

    /** Helligkeit, Ruheanzeige und Intervalle stammen nur aus der Anzeige-Instanz. @return array<string, mixed> */
    private function configDocument(int $instanceId): array
    {
        return [
            'contract' => self::CONTRACT,
            'id' => 'anzeige-' . $instanceId,
            'version' => 1,
            'idleMode' => (int) IPS_GetProperty($instanceId, 'IdleMode'),
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
    }

    /** @param array{id: int, token: string} $device @return array<string, mixed> */
    private function deviceLayout(array $device): array
    {
        $layout = $this->activeLayout($device['id']);
        if ($layout === null) {
            return ['contract' => self::CONTRACT, 'id' => '', 'version' => 0, 'columns' => 2, 'pages' => []];
        }
        $public = HomeDisplayCatalogStore::publicLayout($layout);
        $public['contract'] = self::CONTRACT;
        return $public;
    }

    /**
     * Ansicht, die das Gerät gerade zeigen soll: Vorschau (zeitlich begrenzt, auch unveröffentlicht),
     * sonst die zugewiesene veröffentlichte Ansicht, sonst die Kachelliste der Instanz.
     *
     * @return array<string, mixed>|null
     */
    private function activeLayout(int $instanceId): ?array
    {
        $runtime = $this->runtime();
        $preview = $runtime[(string) $instanceId]['preview'] ?? null;
        if (is_array($preview) && (int) ($preview['until'] ?? 0) > time()) {
            $draft = HomeDisplayCatalogStore::find(HomeDisplayCatalogStore::load(), 'layouts', (string) ($preview['layoutId'] ?? ''));
            if (is_array($draft) && $this->layoutTileCount($draft) > 0) {
                return $draft;
            }
        }
        $layout = $this->assigned($instanceId, 'layouts', 'LayoutId');
        if ($layout === null || $this->layoutTileCount($layout) === 0) {
            $fallback = $this->fallbackLayout($instanceId);
            if ($fallback !== null) {
                return $fallback;
            }
        }
        return $layout;
    }

    /** @param array{id: int, token: string} $device @return array<string, mixed> */
    private function deviceState(array $device): array
    {
        $layout = $this->activeLayout($device['id']);
        $runtime = $this->runtime();
        $seq = (int) ($runtime[(string) $device['id']]['seq'] ?? 0);
        $since = isset($_GET['since']) ? (int) $_GET['since'] : -1;
        if ($since >= 0 && $since >= $seq) {
            return [
                'contract' => self::CONTRACT,
                'seq' => $seq,
                'now' => time(),
                'unchanged' => true,
                'tiles' => [],
            ];
        }
        $pending = $runtime[(string) $device['id']]['pending'] ?? [];
        $pending = is_array($pending) ? $pending : [];
        $tiles = [];
        $pages = is_array($layout) ? ($layout['pages'] ?? []) : [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (!is_array($tile) || (string) ($tile['kind'] ?? 'value') !== 'value') {
                    continue;
                }
                $tileId = (string) $tile['tileId'];
                $variableId = (int) ($tile['variableId'] ?? 0);
                $text = '—';
                $quality = 'empty';
                $value = null;
                $color = 'neutral';
                if ($variableId > 0 && IPS_VariableExists($variableId)) {
                    $text = $this->formatVariable($variableId, $tile);
                    $quality = 'ok';
                    $raw = GetValue($variableId);
                    if (is_bool($raw) || is_int($raw) || is_float($raw)) {
                        $value = $raw;
                    }
                    if (isset($tile['threshold'], $tile['color']) && is_numeric($raw) && (float) $raw >= (float) $tile['threshold']) {
                        $color = (string) $tile['color'];
                    }
                    if (is_bool($raw) && isset($tile['color']) && !isset($tile['threshold']) && $raw) {
                        $color = (string) $tile['color'];
                    }
                }
                if (isset($pending[$tileId])) {
                    $text = 'wird ausgeführt';
                    $quality = 'pending';
                }
                $tiles[] = [
                    'tileId' => $tileId,
                    'text' => $text,
                    'value' => $value,
                    'color' => $color,
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
        $runtime = $this->runtime();
        $key = (string) $device['id'];
        $entry = is_array($runtime[$key] ?? null) ? $runtime[$key] : [];
        $seen = is_array($entry['requests'] ?? null) ? $entry['requests'] : [];
        $recent = is_array($entry['recent'] ?? null) ? $entry['recent'] : [];
        $tileId = trim((string) ($body['tileId'] ?? ''));
        $tile = $tileId === '' ? null : $this->findTile($device['id'], $tileId);
        $decision = HomeDisplayPanelActionGuard::authorize($tile, $body, $seen, $recent, time());
        if ($decision['duplicate']) {
            return ['ok' => true, 'duplicate' => true];
        }
        RequestAction($decision['variableId'], $decision['value']);
        $runtime[$key] = HomeDisplayPanelActionGuard::remember($entry, trim((string) $body['requestId']), $tileId, time());
        $this->saveRuntime($runtime);
        return ['ok' => true];
    }

    /** @return array<string, mixed>|null */
    private function findTile(int $instanceId, string $tileId): ?array
    {
        $layout = $this->activeLayout($instanceId);
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
        $this->rememberDeviceReport($device['id'], $body);
        $parts = ['Programm ' . $version];
        if (isset($body['rssi']) && (int) $body['rssi'] !== 0) {
            $parts[] = 'WLAN ' . (int) $body['rssi'] . ' dBm';
        }
        if (isset($body['uptime'])) {
            $parts[] = 'Laufzeit ' . $this->durationLabel((int) $body['uptime']);
        }
        if (!empty($body['available']) && (string) $body['available'] !== $version) {
            $parts[] = 'verfügbar ' . (string) $body['available'];
        }
        if (isset($body['progress']) && (int) $body['progress'] > 0 && (int) $body['progress'] < 100) {
            $parts[] = 'Fortschritt ' . (int) $body['progress'] . '%';
        }
        if (!empty($body['updateError'])) {
            $parts[] = 'Aktualisierung: ' . (string) $body['updateError'];
        }
        if (!empty($body['rollback'])) {
            $parts[] = 'Rollback: ' . (string) $body['rollback'];
        }
        $runtime = $this->runtime();
        $runtime[(string) $device['id']]['pending'] = [];
        $runtime[(string) $device['id']]['lastCheck'] = time();
        $command = $runtime[(string) $device['id']]['command'] ?? null;
        if (is_array($command) && (int) ($command['at'] ?? 0) < time() - 3600) {
            unset($runtime[(string) $device['id']]['command']);
        }
        $this->saveRuntime($runtime);
        $statusId = @IPS_GetObjectIDByIdent('PanelStatus', $device['id']);
        if (is_int($statusId) && $statusId > 0) {
            SetValue($statusId, implode(', ', $parts));
        }
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h';
        }
        return intdiv($seconds, 86400) . ' d ' . intdiv($seconds % 86400, 3600) . ' h';
    }

    /** Befehl für das Panel hinterlegen; das Panel holt ihn mit dem nächsten Manifest ab und führt ihn genau einmal aus. */
    private function queueCommand(string $name): string
    {
        $runtime = $this->runtime();
        $key = (string) $this->InstanceID;
        $runtime[$key]['command'] = ['id' => bin2hex(random_bytes(6)), 'name' => $name, 'at' => time()];
        $runtime[$key]['seq'] = (int) ($runtime[$key]['seq'] ?? 0) + 1;
        $this->saveRuntime($runtime);
        $lastCheck = (int) ($runtime[$key]['lastCheck'] ?? 0);
        if ($lastCheck === 0 || $lastCheck < time() - 1800) {
            return 'Befehl hinterlegt. Die Anzeige hat sich zuletzt ' . ($lastCheck === 0 ? 'noch nie' : 'vor ' . $this->durationLabel(time() - $lastCheck)) . ' gemeldet.';
        }
        return 'Befehl hinterlegt. Die Anzeige übernimmt ihn beim nächsten Abruf (spätestens in 16 Minuten).';
    }

    public function RestartPanel(): string
    {
        return $this->queueCommand('restart');
    }

    public function ShowTestPattern(bool $On): string
    {
        return $this->queueCommand($On ? 'testPattern' : 'testPatternOff');
    }

    public function Resynchronize(): string
    {
        return $this->queueCommand('resync');
    }

    /** Zeigt dem Panel 30 Minuten lang den aktuellen Entwurf der zugewiesenen Ansicht, auch wenn er noch nicht veröffentlicht ist. */
    public function SendPreview(): string
    {
        $layoutId = $this->ReadPropertyString('LayoutId');
        $draft = HomeDisplayCatalogStore::find(HomeDisplayCatalogStore::load(), 'layouts', $layoutId);
        if (!is_array($draft)) {
            return 'Für die Vorschau zuerst eine Ansicht in der Instanz wählen.';
        }
        try {
            HomeDisplayCatalogStore::validateLayout($draft, false);
        } catch (Throwable $e) {
            return 'Der Entwurf ist nicht gültig: ' . $e->getMessage();
        }
        $runtime = $this->runtime();
        $key = (string) $this->InstanceID;
        $runtime[$key]['preview'] = ['layoutId' => $layoutId, 'until' => time() + 1800];
        $this->saveRuntime($runtime);
        $this->subscribeLayoutVariables();
        return $this->queueCommand('resync') . ' Die Vorschau gilt 30 Minuten.';
    }

    public function TestConnection(): string
    {
        $runtime = $this->runtime();
        $entry = $runtime[(string) $this->InstanceID] ?? [];
        $entry = is_array($entry) ? $entry : [];
        if ($this->ReadPropertyString('DeviceToken') === '') {
            return 'Die Anzeige ist nicht gekoppelt. Kopplungscode von der Anzeige eintragen.';
        }
        $lastCheck = (int) ($entry['lastCheck'] ?? 0);
        if ($lastCheck === 0) {
            $url = (string) $this->GetValue('AdapterUrl');
            if ($url === '') {
                return 'Die Anzeige hat sich noch nicht gemeldet. Es gibt keinen Dienst: Instanz „Hausanzeige Verwaltung“ anlegen und aktivieren.';
            }
            return 'Die Anzeige hat sich noch nicht gemeldet. Dienstadresse ' . $url . ' an der Anzeige prüfen.';
        }
        $lines = [
            'Letzte Meldung vor ' . $this->durationLabel(time() - $lastCheck) . ($lastCheck > time() - 120 ? ' (verbunden)' : ' (keine aktuelle Verbindung)'),
            'Programm ' . (string) ($entry['firmware'] ?? '?'),
        ];
        if (isset($entry['rssi'])) {
            $lines[] = 'WLAN ' . (int) $entry['rssi'] . ' dBm';
        }
        if (isset($entry['heap'])) {
            $lines[] = 'Freier Speicher ' . intdiv((int) $entry['heap'], 1024) . ' KB (Minimum ' . intdiv((int) ($entry['minHeap'] ?? 0), 1024) . ' KB)';
        }
        if (isset($entry['uptime'])) {
            $lines[] = 'Laufzeit ' . $this->durationLabel((int) $entry['uptime']) . ', Reset-Grund ' . $this->resetReasonLabel((int) ($entry['resetReason'] ?? 0));
        }
        if (isset($entry['dataAge'])) {
            $lines[] = 'Datenalter an der Anzeige ' . (int) $entry['dataAge'] . ' s, Fehlversuche ' . (int) ($entry['failures'] ?? 0);
        }
        if (!empty($entry['updateError'])) {
            $lines[] = 'Aktualisierung: ' . (string) $entry['updateError'];
        }
        if (!empty($entry['rollback'])) {
            $lines[] = 'Rollback: ' . (string) $entry['rollback'];
        }
        return implode("\n", $lines);
    }

    private function resetReasonLabel(int $reason): string
    {
        $labels = [1 => 'Einschalten', 3 => 'Software', 4 => 'Panic', 5 => 'Interrupt-Watchdog', 6 => 'Task-Watchdog', 7 => 'Watchdog', 8 => 'Tiefschlaf', 9 => 'Brownout'];
        return $labels[$reason] ?? ('Code ' . $reason);
    }

    public function LayoutTemplate(string $Name): string
    {
        return $this->encode(HomeDisplayPanelSchema::dashboardLayout($Name !== '' ? $Name : 'Übersicht'));
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

    /** @return array{version: string, sha256: string, signature: string, available: bool, path: string} */
    private function firmwareDescriptor(): array
    {
        $versionFile = __DIR__ . '/firmware/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : self::FIRMWARE_VERSION;
        $path = __DIR__ . '/firmware/build/homedisplay.bin';
        $available = is_file($path);
        return [
            'version' => $version !== '' ? $version : self::FIRMWARE_VERSION,
            'sha256' => $available ? hash_file('sha256', $path) : '',
            'signature' => $available ? $this->firmwareSignature($path) : '',
            'available' => $available,
            'path' => $path,
        ];
    }

    private function sendImage(string $id): void
    {
        $id = preg_replace('/\.[a-z]+$/', '', $id) ?? $id;
        if (!HomeDisplayCatalogStore::imageExists($id)) {
            http_response_code(404);
            $this->json(['error' => 'image']);
            return;
        }
        $path = HomeDisplayCatalogStore::imagePath($id);
        header('Content-Type: ' . (str_ends_with($path, '.jpg') ? 'image/jpeg' : 'image/png'));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    }

    private function sendFirmware(): void
    {
        $firmware = $this->firmwareDescriptor();
        if (!$firmware['available']) {
            http_response_code(404);
            $this->json(['error' => 'Programmdatei fehlt. Zuerst lokal bauen.']);
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
            $this->SetValue('PairingState', 'Wartet auf Anzeige ' . $code);
            return;
        }
        $this->SetValue('PairingState', 'Code von der Anzeige eintragen');
    }

    public function ApplyPreset(string $Name): string
    {
        $rows = HomeDisplayPanelSchema::presetRows($Name);
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return 'Vorlage konnte nicht erzeugt werden.';
        }
        IPS_SetProperty($this->InstanceID, 'FallbackTiles', $json);
        IPS_ApplyChanges($this->InstanceID);
        return 'Vorlage eingetragen. Variablen in der Liste zuordnen und danach veröffentlichen.';
    }

    public function PublishFallback(): string
    {
        $rows = json_decode($this->ReadPropertyString('FallbackTiles'), true);
        if (!is_array($rows) || $rows === []) {
            return 'Die Kachelliste ist leer.';
        }
        $layout = HomeDisplayPanelSchema::layoutFromRows($rows, IPS_GetName($this->InstanceID));
        $layout['id'] = 'layout-device-' . $this->ReadPropertyString('DeviceId');
        $existing = HomeDisplayCatalogStore::find(HomeDisplayCatalogStore::load(), 'layouts', $layout['id']);
        $layout['baseVersion'] = (int) ($existing['version'] ?? 0);
        try {
            $this->storeCatalogItem('layouts', $layout, true);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        IPS_SetProperty($this->InstanceID, 'LayoutId', $layout['id']);
        IPS_ApplyChanges($this->InstanceID);
        return 'Kachelliste veröffentlicht.';
    }

    private function subscribeLayoutVariables(): void
    {
        $layout = $this->activeLayout($this->InstanceID) ?? [];
        foreach ($layout['pages'] ?? [] as $page) {
            foreach ($page['tiles'] ?? [] as $tile) {
                $variableId = (int) ($tile['variableId'] ?? 0);
                if ($variableId > 0 && IPS_VariableExists($variableId)) {
                    $this->RegisterMessage($variableId, 10603);
                }
            }
        }
    }

    /** @param array<string, mixed> $variable */
    private function actionKind(array $variable): string
    {
        $hasAction = (int) ($variable['VariableAction'] ?? 0) > 0 || (int) ($variable['VariableCustomAction'] ?? 0) > 0;
        if (!$hasAction) {
            return '';
        }
        $type = (int) ($variable['VariableType'] ?? -1);
        if ($type === 0) {
            return 'switch';
        }
        $profile = strtolower((string) ($variable['VariableProfile'] ?? $variable['VariableCustomProfile'] ?? ''));
        if (str_contains($profile, 'shutter') || str_contains($profile, 'rollo') || str_contains($profile, 'position')) {
            return 'position';
        }
        return $type === 1 || $type === 2 ? 'dim' : '';
    }

    private function categoryName(int $parentId): string
    {
        if ($parentId <= 0 || !IPS_ObjectExists($parentId)) {
            return '';
        }
        return IPS_GetName($parentId);
    }

    /** @param array<int, string> $cache */
    private function objectPath(int $parentId, array &$cache): string
    {
        if ($parentId <= 0) {
            return '';
        }
        if (isset($cache[$parentId])) {
            return $cache[$parentId];
        }
        $parts = [];
        $current = $parentId;
        $guard = 0;
        while ($current > 0 && $guard < 32 && IPS_ObjectExists($current)) {
            array_unshift($parts, IPS_GetName($current));
            $current = (int) (IPS_GetObject($current)['ParentID'] ?? 0);
            $guard++;
        }
        $cache[$parentId] = implode(' / ', $parts);
        return $cache[$parentId];
    }

    /** @param array<string, mixed>|null $layout */
    private function layoutTileCount(?array $layout): int
    {
        $count = 0;
        foreach ($layout['pages'] ?? [] as $page) {
            if (is_array($page)) {
                $count += count($page['tiles'] ?? []);
            }
        }
        return $count;
    }

    /** @param array<string, mixed>|null $layout */
    private function layoutHeld(int $instanceId, ?array $layout): bool
    {
        if (!is_array($layout)) {
            return false;
        }
        $runtime = $this->runtime();
        $installed = (string) ($runtime[(string) $instanceId]['firmware'] ?? '0.0.0');
        return version_compare($installed, (string) ($layout['minFirmware'] ?? '0.1.0'), '<');
    }

    /** @return array<string, mixed>|null */
    private function fallbackLayout(int $instanceId): ?array
    {
        $rows = json_decode((string) IPS_GetProperty($instanceId, 'FallbackTiles'), true);
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        try {
            $layout = HomeDisplayPanelSchema::layoutFromRows($rows, 'Liste');
            HomeDisplayPanelSchema::validateLayout($layout, false);
            $layout['id'] = 'fallback';
            $layout['version'] = 1;
            $layout['published'] = true;
            return $layout;
        } catch (Throwable $e) {
            $this->SendDebug('Liste', $e->getMessage(), 0);
            return null;
        }
    }

    /** @param array<string, mixed> $body */
    private function rememberDeviceReport(int $instanceId, array $body): void
    {
        if (!isset($body['firmware']) && !isset($body['code'])) {
            return;
        }
        $runtime = $this->runtime();
        $key = (string) $instanceId;
        if (isset($body['firmware'])) {
            $runtime[$key]['firmware'] = (string) $body['firmware'];
        }
        foreach (['available', 'updateError', 'rollback', 'progress', 'rssi', 'heap', 'minHeap', 'uptime', 'resetReason', 'dataAge', 'frames', 'failures'] as $field) {
            if (array_key_exists($field, $body)) {
                $runtime[$key][$field] = $body[$field];
            }
        }
        $this->saveRuntime($runtime);
    }

    private function firmwareSignature(string $path): string
    {
        $keyPath = __DIR__ . '/firmware/keys/ota-private.pem';
        if (!is_file($keyPath) || !function_exists('openssl_sign')) {
            return '';
        }
        $key = openssl_pkey_get_private((string) file_get_contents($keyPath));
        $data = file_get_contents($path);
        if ($key === false || !is_string($data)) {
            return '';
        }
        $signature = '';
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return '';
        }
        return base64_encode($signature);
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
