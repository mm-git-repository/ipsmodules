<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/CatalogStore.php';
require_once __DIR__ . '/libs/Media.php';
require_once __DIR__ . '/libs/PanelActionGuard.php';

class HomeDisplay extends IPSModuleStrict
{
    private const MODULE_ID = '{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}';
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 30;

    private string $lastFetchHint = '';
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
        $this->RegisterPropertyString('DisplayProfile', 'waveshare-5b-1024x600');
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
            if (($element['name'] ?? '') === 'DisplayProfile') {
                $options = [];
                foreach (HomeDisplayPanelSchema::DISPLAY_PROFILES as $id => $meta) {
                    $options[] = ['caption' => (string) ($meta['label'] ?? $id), 'value' => $id];
                }
                $element['options'] = $options;
            }
        }
        unset($element);
        $encoded = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $raw : $encoded;
    }

    /** Setzt die Dienstadresse. Aufruf aus der Verwaltung, weil die Variable von außen nur lesbar ist. */
    public function SetAdapterUrl(string $Url): void
    {
        $this->SetValue('AdapterUrl', $Url);
    }

    /** Setzt den Anzeigestatus. Aufruf aus einer anderen Anzeige, weil die Variable von außen nur lesbar ist. */
    public function SetPanelStatus(string $Text): void
    {
        $this->SetValue('PanelStatus', $Text);
    }

    public function Pair(): string
    {
        $code = strtoupper(trim($this->ReadPropertyString('ClaimCode')));
        if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
            return 'Kopplungscode muss sechs Buchstaben oder Ziffern haben (exakt wie auf dem Display, ohne Leerzeichen).';
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return 'Instanz ist deaktiviert – „Aktiv“ setzen und erneut übernehmen.';
        }
        $serviceUrl = $this->adapterBaseUrl();
        if ($serviceUrl === '') {
            return 'Kein Dienst unter /hook/homedisplay – zuerst Instanz „Hausanzeige Verwaltung“ anlegen, Passwort setzen und übernehmen.';
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
        IPS_SetProperty($this->InstanceID, 'ClaimCode', $code);
        if ($this->ReadPropertyString('DeviceToken') === '') {
            IPS_SetProperty($this->InstanceID, 'DeviceToken', bin2hex(random_bytes(16)));
        }
        IPS_ApplyChanges($this->InstanceID);
        $this->refreshPairingLabel();
        return 'Code übernommen. Status „Wartet auf Anzeige …“ ist normal, bis das Gerät sich meldet (alle ca. 3 s, wenn WLAN und Dienstadresse am Display stimmen). Dienst: '
            . $serviceUrl
            . ' – am Display muss dieselbe Hook-URL eingetragen sein (https://…:3777/hook/homedisplay). Nach Reset am Display erscheint ein neuer Code; dann hier erneut eintragen und übernehmen.';
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
        $active = $this->activeLayout($this->InstanceID);
        $runtime = $this->runtime();
        $preview = $runtime[(string) $this->InstanceID]['preview'] ?? null;
        $previewActive = is_array($preview) && (int) ($preview['until'] ?? 0) > time();
        return $this->encode([
            'ok' => true,
            'manifest' => $this->manifest($device),
            'config' => $this->deviceConfig($device),
            'layout' => $this->deviceLayout($device),
            'state' => $this->deviceState($device),
            'probe' => [
                'previewActive' => $previewActive,
                'layoutId' => is_array($active) ? (string) ($active['id'] ?? '') : '',
                'layoutVersion' => is_array($active) ? (int) ($active['version'] ?? 0) : 0,
            ],
        ]);
    }

    /** Live-Werte für eine beliebige Ansicht (Editor-Entwurf), unabhängig vom Display-Profil der Hardware. */
    public function PreviewLayoutState(string $LayoutJson): string
    {
        $layout = json_decode($LayoutJson, true);
        if (!is_array($layout)) {
            return $this->encode(['ok' => false, 'error' => 'Ansicht konnte nicht gelesen werden.']);
        }
        $state = $this->stateForLayout($layout, $this->InstanceID, -1);
        return $this->encode(['ok' => true, 'tiles' => $state['tiles'] ?? []]);
    }

    /** Führt eine freigegebene Kachelaktion dieser Anzeige aus. Leerer Wert schaltet um. Optional $TileJson = Kachel aus Editor/Prüfung. */
    public function SimulateAction(string $TileId, string $ActionId, string $Value = '', string $TileJson = ''): string
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
            $tileOverride = null;
            if ($TileJson !== '') {
                $decoded = json_decode($TileJson, true);
                if (is_array($decoded)) {
                    $tileOverride = $decoded;
                }
            }
            return $this->encode($this->performAction(['id' => $this->InstanceID, 'token' => ''], $body, $tileOverride));
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
            $variableId = (int) $id;
            $type = (int) ($variable['VariableType'] ?? -1);
            $mediaRole = '';
            if ($type === 1 || $type === 3) {
                $mediaRole = HomeDisplayMedia::variableMediaRole($variableId);
            }
            $rows[] = [
                'id' => $variableId,
                'name' => (string) ($object['ObjectName'] ?? ''),
                'path' => $this->objectPath((int) ($object['ParentID'] ?? 0), $names),
                'category' => $this->categoryName((int) ($object['ParentID'] ?? 0)),
                'profile' => $profile,
                'type' => $type,
                'mediaRole' => $mediaRole,
                'action' => (int) ($variable['VariableAction'] ?? 0) > 0 || (int) ($variable['VariableCustomAction'] ?? 0) > 0,
                'actionKind' => $this->actionKind($variable),
                'archived' => HomeDisplayChart::variableLogged($variableId),
            ];
            if (count($rows) >= 5000) {
                break;
            }
        }
        usort($rows, static function ($left, $right) {
            $byPath = strcasecmp($left['path'], $right['path']);
            return $byPath !== 0 ? $byPath : strcasecmp($left['name'], $right['name']);
        });
        foreach ($this->listImageMediaObjects($names) as $mediaRow) {
            $rows[] = $mediaRow;
        }
        usort($rows, static function ($left, $right) {
            $leftKind = (string) ($left['objectKind'] ?? '');
            $rightKind = (string) ($right['objectKind'] ?? '');
            if ($leftKind !== $rightKind) {
                return $leftKind === 'media' ? 1 : -1;
            }
            $byPath = strcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
            return $byPath !== 0 ? $byPath : strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
        return $this->encode(['variables' => $rows]);
    }

    /** @param array<int, string> $names @return list<array<string, mixed>> */
    private function listImageMediaObjects(array &$names): array
    {
        if (!function_exists('IPS_GetMediaListByType')) {
            return [];
        }
        $rows = [];
        foreach (IPS_GetMediaListByType(1) as $mediaId) {
            $mediaId = (int) $mediaId;
            if ($mediaId <= 0 || !HomeDisplayMedia::isImageMediaId($mediaId)) {
                continue;
            }
            if (!IPS_ObjectExists($mediaId)) {
                continue;
            }
            $object = IPS_GetObject($mediaId);
            $parentId = (int) ($object['ParentID'] ?? 0);
            $rows[] = [
                'objectKind' => 'media',
                'mediaId' => $mediaId,
                'id' => $mediaId,
                'name' => IPS_GetName($mediaId),
                'path' => $this->objectPath($parentId, $names),
                'category' => $this->categoryName($parentId),
                'profile' => 'Medienobjekt (Bild)',
                'type' => 1,
                'mediaRole' => HomeDisplayMedia::ROLE_MEDIA_OBJECT,
                'action' => false,
                'actionKind' => '',
            ];
            if (count($rows) >= 800) {
                break;
            }
        }
        return $rows;
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
            if ($method === 'GET' && $route === '/') {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo $this->serviceBrowserHint();
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
            if ($method === 'GET' && preg_match('#^/v1/camera/([A-Za-z0-9_-]{1,40})$#', $route, $match) === 1) {
                $this->sendCameraSnapshot($device, $match[1]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/v1/chart/([A-Za-z0-9_-]{1,40})$#', $route, $match) === 1) {
                $this->sendChartSnapshot($device, $match[1]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/v1/media/([A-Za-z0-9_-]{1,40})$#', $route, $match) === 1) {
                $this->sendMediaSnapshot($device, $match[1]);
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

    private function serviceBrowserHint(): string
    {
        $lines = [
            'Dies ist der API-Dienst für gekoppelte Anzeigen, nicht die Verwaltungsseite im Browser.',
            'Ohne Geräte-Schlüssel antwortet der Dienst deshalb mit {"error":"token"}.',
        ];
        $admin = $this->adminInstanceId();
        if ($admin > 0 && function_exists('HDADM_GetAdminPageUrl')) {
            $url = trim((string) HDADM_GetAdminPageUrl($admin));
            if ($url !== '') {
                $lines[] = '';
                $lines[] = 'Verwaltungsseite (Editor): ' . $url;
                $lines[] = 'Passwort: Instanz „Hausanzeige Verwaltung“ → „Passwort für die Verwaltungsseite“.';
                return implode("\n", $lines);
            }
        }
        if ($admin > 0) {
            $variableId = @IPS_GetObjectIDByIdent('AdminUrl', $admin);
            if (is_int($variableId) && $variableId > 0) {
                $url = trim((string) GetValue($variableId));
                if ($url !== '') {
                    $lines[] = '';
                    $lines[] = 'Verwaltungsseite (Editor): ' . $url;
                    $lines[] = 'Passwort: Instanz „Hausanzeige Verwaltung“ → „Passwort für die Verwaltungsseite“.';
                }
            }
        }
        $lines[] = '';
        $lines[] = 'Legen Sie die Instanz „Hausanzeige Verwaltung“ an, setzen Sie ein Passwort und übernehmen Sie die Änderungen.';
        return implode("\n", $lines);
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
                $this->markPanelHello($id, $body);
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
        $firmware = $this->firmwareDescriptor($device['id']);
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
        $document = [
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
            'displayProfile' => (string) IPS_GetProperty($instanceId, 'DisplayProfile'),
        ];
        $profileId = (string) IPS_GetProperty($instanceId, 'DisplayProfile');
        try {
            $profile = HomeDisplayPanelSchema::profile($profileId !== '' ? $profileId : HomeDisplayPanelSchema::defaultProfileId());
            $document['targetWidth'] = (int) $profile['width'];
            $document['targetHeight'] = (int) $profile['height'];
        } catch (Throwable $e) {
            $document['targetWidth'] = 1024;
            $document['targetHeight'] = 600;
        }
        return $document;
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
        $state = $this->stateForLayout(is_array($layout) ? $layout : [], $device['id'], $since);
        $state['seq'] = $seq;
        return $state;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function stateForLayout(array $layout, int $instanceId, int $since): array
    {
        $runtime = $this->runtime();
        $seq = (int) ($runtime[(string) $instanceId]['seq'] ?? 0);
        if ($since >= 0 && $since >= $seq) {
            return [
                'contract' => self::CONTRACT,
                'seq' => $seq,
                'now' => time(),
                'unchanged' => true,
                'tiles' => [],
            ];
        }
        $pending = $runtime[(string) $instanceId]['pending'] ?? [];
        $pending = is_array($pending) ? $pending : [];
        $tiles = [];
        foreach ($layout['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (!is_array($tile)) {
                    continue;
                }
                $kind = (string) ($tile['kind'] ?? 'value');
                $tileId = (string) ($tile['tileId']);
                if ($kind === 'camera') {
                    $camSeq = 0;
                    $quality = 'empty';
                    $mediaId = HomeDisplayMedia::resolveImageMediaId($tile);
                    $url = $mediaId > 0 ? '' : HomeDisplayMedia::cameraImageUrl($tile);
                    if ($mediaId > 0 || $url !== '') {
                        $interval = max(10, (int) IPS_GetProperty($instanceId, 'StateIntervalSec'));
                        $stamp = $mediaId > 0 ? (string) $mediaId : $url;
                        $camSeq = (int) (time() / $interval) ^ (int) sprintf('%u', crc32($stamp));
                        $quality = 'ok';
                    }
                    $tiles[] = [
                        'tileId' => $tileId,
                        'cameraSeq' => $camSeq,
                        'quality' => $quality,
                    ];
                    continue;
                }
                if ($kind === 'image' && (string) ($tile['imageSource'] ?? 'catalog') === 'media') {
                    $mediaSeq = 0;
                    $quality = 'empty';
                    $mediaId = HomeDisplayMedia::resolveImageMediaId($tile);
                    if ($mediaId > 0) {
                        $interval = max(10, (int) IPS_GetProperty($instanceId, 'StateIntervalSec'));
                        $mediaSeq = (int) (time() / $interval) ^ (int) sprintf('%u', crc32((string) $mediaId));
                        $quality = 'ok';
                    }
                    $tiles[] = [
                        'tileId' => $tileId,
                        'mediaSeq' => $mediaSeq,
                        'quality' => $quality,
                    ];
                    continue;
                }
                if ($kind === 'energyflow') {
                    $tiles[] = $this->energyFlowStateTile($tile, $tileId, $pending);
                    continue;
                }
                if ($kind === 'chart') {
                    $tiles[] = $this->chartStateTile($tile, $tileId, $instanceId);
                    continue;
                }
                if ($kind === 'scene') {
                    $tiles[] = [
                        'tileId' => $tileId,
                        'quality' => trim((string) ($tile['actionId'] ?? '')) !== '' ? 'ok' : 'empty',
                        'pending' => isset($pending[$tileId]),
                    ];
                    continue;
                }
                if ($kind === 'weather') {
                    $tiles[] = $this->weatherStateTile($tile, $tileId);
                    continue;
                }
                if ($kind === 'presence') {
                    $tiles[] = $this->presenceStateTile($tile, $tileId);
                    continue;
                }
                if ($kind !== 'value' && $kind !== 'display') {
                    continue;
                }
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
            'seq' => $seq,
            'now' => time(),
            'tiles' => $tiles,
        ];
    }

    /** @var array<string, array{at: int, payload: array<string, mixed>}> */
    private array $chartStateCache = [];

    /** @param array<string, mixed> $tile @return array<string, mixed> */
    private function chartStateTile(array $tile, string $tileId, int $instanceId): array
    {
        $refresh = max(0, (int) ($tile['chartRefreshSec'] ?? 0));
        if ($refresh > 0) {
            $cacheKey = $instanceId . ':' . $tileId;
            $cached = $this->chartStateCache[$cacheKey] ?? null;
            if (is_array($cached) && (time() - (int) $cached['at']) < $refresh && is_array($cached['payload'])) {
                return $cached['payload'];
            }
        }
        $period = HomeDisplayChart::normalizePeriod((string) ($tile['chartPeriod'] ?? '24h'));
        $defs = HomeDisplayChart::seriesDefinitions($tile);
        $primaryId = $defs !== [] ? (int) $defs[0]['variableId'] : (int) ($tile['variableId'] ?? 0);
        $text = '—';
        $quality = 'empty';
        $value = null;
        $seriesList = [];
        foreach ($defs as $def) {
            $variableId = (int) $def['variableId'];
            if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
                continue;
            }
            $points = HomeDisplayChart::series($variableId, $period);
            if ($points !== []) {
                $seriesList[] = $points;
            }
        }
        $series = $seriesList !== [] ? $seriesList[0] : [];
        if ($primaryId > 0 && IPS_VariableExists($primaryId)) {
            $text = $this->formatVariable($primaryId, $tile);
            $raw = GetValue($primaryId);
            if (is_bool($raw) || is_int($raw) || is_float($raw)) {
                $value = $raw;
            } elseif (is_string($raw) && is_numeric($raw)) {
                $value = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            }
            $quality = ($series !== [] && count($series) >= 2) || $value !== null ? 'ok' : 'empty';
        }
        [$min, $max] = HomeDisplayChart::yBounds($tile, $seriesList);
        $yesterday = [];
        if (!empty($tile['chartCompareYesterday']) && $primaryId > 0) {
            $yesterday = HomeDisplayChart::seriesYesterday($primaryId, $period);
        }
        $payload = [
            'tileId' => $tileId,
            'text' => $text,
            'value' => $value,
            'quality' => $quality,
            'series' => $series,
            'seriesAll' => $seriesList,
            'seriesYesterday' => $yesterday,
            'seriesMin' => $min,
            'seriesMax' => $max,
            'chartSeq' => (int) sprintf('%u', crc32(json_encode($seriesList) . '|' . $text)),
        ];
        if ($refresh > 0) {
            $this->chartStateCache[$instanceId . ':' . $tileId] = ['at' => time(), 'payload' => $payload];
        }

        return $payload;
    }

    /** @param array<string, mixed> $tile @return array<string, mixed> */
    private function weatherStateTile(array $tile, string $tileId): array
    {
        $variableId = (int) ($tile['variableId'] ?? 0);
        $text = '—';
        $forecast = '';
        $quality = 'empty';
        if ($variableId > 0 && IPS_VariableExists($variableId)) {
            $text = $this->formatVariable($variableId, $tile);
            $quality = 'ok';
        }
        $forecastId = (int) ($tile['forecastVariableId'] ?? 0);
        if ($forecastId > 0 && IPS_VariableExists($forecastId)) {
            $raw = GetValue($forecastId);
            if (is_string($raw)) {
                $forecast = $raw;
            } elseif (is_scalar($raw)) {
                $forecast = (string) $raw;
            }
        }

        return [
            'tileId' => $tileId,
            'text' => $text,
            'forecast' => $forecast,
            'quality' => $quality,
        ];
    }

    /** @param array<string, mixed> $tile @return array<string, mixed> */
    private function presenceStateTile(array $tile, string $tileId): array
    {
        $variableId = (int) ($tile['variableId'] ?? 0);
        $mode = (string) ($tile['presenceMode'] ?? 'home');
        $text = '—';
        $state = 'unknown';
        $quality = 'empty';
        if ($variableId > 0 && IPS_VariableExists($variableId)) {
            $raw = GetValue($variableId);
            $text = $this->formatVariable($variableId, $tile);
            $quality = 'ok';
            if (is_bool($raw)) {
                $state = $raw ? 'active' : 'inactive';
            } elseif (is_numeric($raw)) {
                $state = ((float) $raw) > 0 ? 'active' : 'inactive';
            } else {
                $state = trim((string) $raw) !== '' ? 'active' : 'inactive';
            }
        }

        return [
            'tileId' => $tileId,
            'text' => $text,
            'presenceState' => $state,
            'presenceMode' => $mode,
            'quality' => $quality,
        ];
    }

    /** @param array{id: int, token: string} $device */
    private function sendChartSnapshot(array $device, string $tileId): void
    {
        $layout = $this->activeLayout($device['id']);
        $tile = $this->findTileInLayout(is_array($layout) ? $layout : [], $tileId);
        if ($tile === null || (string) ($tile['kind'] ?? '') !== 'chart') {
            http_response_code(404);
            $this->json(['error' => 'chart']);
            return;
        }
        $state = $this->chartStateTile($tile, $tileId, $device['id']);
        $seriesList = is_array($state['seriesAll'] ?? null) ? $state['seriesAll'] : [];
        if ($seriesList === [] && is_array($state['series'] ?? null)) {
            $seriesList = [$state['series']];
        }
        $yesterday = is_array($state['seriesYesterday'] ?? null) ? $state['seriesYesterday'] : [];
        $w = max(80, (int) ($_GET['w'] ?? 320));
        $h = max(60, (int) ($_GET['h'] ?? 180));
        $png = HomeDisplayChartRender::png(
            $w,
            $h,
            $seriesList,
            $yesterday,
            (float) ($state['seriesMin'] ?? 0),
            (float) ($state['seriesMax'] ?? 1),
            HomeDisplayChart::normalizeStyle((string) ($tile['chartStyle'] ?? 'area'))
        );
        if ($png === '') {
            http_response_code(503);
            $this->json(['error' => 'render']);
            return;
        }
        header('Content-Type: image/png');
        header('Cache-Control: no-cache');
        echo $png;
    }

    /** @param array<string, mixed> $layout @return array<string, mixed>|null */
    private function findTileInLayout(array $layout, string $tileId): ?array
    {
        foreach ($layout['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (is_array($tile) && (string) ($tile['tileId'] ?? '') === $tileId) {
                    return $tile;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $tile @param array<string, mixed> $pending @return array<string, mixed> */
    private function energyFlowStateTile(array $tile, string $tileId, array $pending): array
    {
        $unit = trim((string) ($tile['unit'] ?? 'W'));
        $decimals = max(0, min(3, (int) ($tile['decimals'] ?? 0)));
        $readings = $this->energyFlowReadings($tile);
        $hasNumeric = false;
        foreach ($readings as $value) {
            if ($value !== null) {
                $hasNumeric = true;
                break;
            }
        }
        $quality = $hasNumeric ? 'ok' : 'empty';
        $flows = $hasNumeric
            ? HomeDisplayEnergyFlow::compute($tile, $readings, $decimals, $unit)
            : [];
        if (isset($pending[$tileId])) {
            $quality = 'pending';
        }
        return [
            'tileId' => $tileId,
            'quality' => $quality,
            'flows' => $flows,
            'pending' => isset($pending[$tileId]),
        ];
    }

    /** @param array<string, mixed> $tile @return array<string, float|null> */
    private function energyFlowReadings(array $tile): array
    {
        $read = function (int $variableId): ?float {
            if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
                return null;
            }
            $raw = GetValue($variableId);
            if (is_int($raw) || is_float($raw)) {
                return (float) $raw;
            }
            if (is_bool($raw)) {
                return $raw ? 1.0 : 0.0;
            }
            if (is_string($raw) && is_numeric(trim($raw))) {
                return (float) trim($raw);
            }
            return null;
        };
        $gridImport = $read((int) ($tile['gridImportVariableId'] ?? 0));
        $gridExport = $read((int) ($tile['gridExportVariableId'] ?? 0));
        if ($gridImport === null && $gridExport === null) {
            $gridSigned = $read((int) ($tile['gridVariableId'] ?? 0));
            if ($gridSigned !== null) {
                $gridImport = max(0.0, $gridSigned);
                $gridExport = max(0.0, -$gridSigned);
            }
        }
        $out = [
            'gridImport' => $gridImport,
            'gridExport' => $gridExport,
            'pv' => $read((int) ($tile['pvVariableId'] ?? 0)),
            'load' => HomeDisplayEnergyFlow::homeOverride($tile)
                ? $read((int) ($tile['loadVariableId'] ?? 0))
                : null,
        ];
        if (HomeDisplayEnergyFlow::mode($tile) === 'split') {
            $out['batteryCharge'] = $read((int) ($tile['batteryChargeVariableId'] ?? 0));
            $out['batteryDischarge'] = $read((int) ($tile['batteryDischargeVariableId'] ?? 0));
        } else {
            $out['battery'] = $read((int) ($tile['batteryVariableId'] ?? 0));
        }
        $socId = (int) ($tile['batterySocVariableId'] ?? 0);
        if ($socId > 0) {
            $out['soc'] = $read($socId);
        }
        return $out;
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

    /** @param array{id: int, token: string} $device @param array<string, mixed> $body @param array<string, mixed>|null $tileOverride Nur für SimulateAction (Editor/Prüfung). */
    private function performAction(array $device, array $body, ?array $tileOverride = null): array
    {
        $runtime = $this->runtime();
        $key = (string) $device['id'];
        $entry = is_array($runtime[$key] ?? null) ? $runtime[$key] : [];
        $seen = is_array($entry['requests'] ?? null) ? $entry['requests'] : [];
        $recent = is_array($entry['recent'] ?? null) ? $entry['recent'] : [];
        $tileId = trim((string) ($body['tileId'] ?? ''));
        if ($tileOverride !== null) {
            $tile = $tileOverride;
        } else {
            $tile = $tileId === '' ? null : $this->findTile($device['id'], $tileId);
        }
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
        $status = implode(', ', $parts);
        if ((int) $device['id'] === $this->InstanceID) {
            $this->SetValue('PanelStatus', $status);
        } elseif (function_exists('HDISP_SetPanelStatus')) {
            HDISP_SetPanelStatus((int) $device['id'], $status);
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

    public function LayoutTemplate(string $Name, string $DisplayProfile = ''): string
    {
        return $this->encode(HomeDisplayPanelSchema::dashboardLayout($Name !== '' ? $Name : 'Übersicht', $DisplayProfile));
    }

    /** Bildbytes für Live-Vorschau (Editor-Entwurf oder veröffentlichte Ansicht). */
    public function ProbeTileImage(string $TileId, string $TileJson = '', string $Mode = ''): string
    {
        $tile = null;
        if ($TileJson !== '') {
            $decoded = json_decode($TileJson, true);
            if (is_array($decoded)) {
                $tile = $decoded;
            }
        }
        if ($tile === null) {
            $tile = $this->findLayoutTile($this->InstanceID, $TileId);
        }
        if ($tile === null) {
            return $this->encode(['ok' => false, 'error' => 'tile']);
        }
        $payload = $this->probeTileImagePayload($tile);
        if (!empty($payload['ok']) && isset($payload['data'])) {
            $payload = $this->applyProbeImageMode($payload, $Mode, $this->InstanceID, $TileId);
        }
        return $this->encode($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function applyProbeImageMode(array $payload, string $mode, int $instanceId, string $tileId): array
    {
        if ($mode === 'meta' || $mode === 'metaOnly') {
            unset($payload['data']);
            return $payload;
        }
        if ($mode !== 'stream') {
            return $payload;
        }
        $binary = base64_decode((string) ($payload['data'] ?? ''), true);
        unset($payload['data']);
        if (!is_string($binary) || $binary === '') {
            return ['ok' => false, 'error' => 'camera_fetch', 'detail' => 'Bilddaten nach Abruf leer (IPS-Größenlimit?)'];
        }
        $path = HomeDisplayCatalogStore::probeCacheFile($instanceId, $tileId);
        if (file_put_contents($path, $binary, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'camera_fetch', 'detail' => 'Probe-Cache nicht schreibbar'];
        }
        return $payload;
    }

    /** @param array<string, mixed> $tile @return array<string, mixed> */
    private function probeTileImagePayload(array $tile): array
    {
        $kind = (string) ($tile['kind'] ?? '');
        if ($kind === 'camera') {
            $shot = $this->cameraBinaryForTile($tile);
            if (isset($shot['binary'])) {
                $binary = $shot['binary'];
                return ['ok' => true, 'mime' => $this->guessImageMime($binary), 'data' => base64_encode($binary)];
            }
            $payload = ['ok' => false, 'error' => (string) ($shot['error'] ?? 'camera_fetch')];
            if (!empty($shot['detail'])) {
                $payload['detail'] = (string) $shot['detail'];
            }
            return $payload;
        }
        if ($kind === 'image' && (string) ($tile['imageSource'] ?? 'catalog') === 'media') {
            $binary = HomeDisplayMedia::snapshotMediaBinary($tile);
            if ($binary === null || $binary === '') {
                return ['ok' => false, 'error' => 'media_fetch'];
            }
            return ['ok' => true, 'mime' => $this->guessImageMime($binary), 'data' => base64_encode($binary)];
        }
        return ['ok' => false, 'error' => 'tile'];
    }

    /** @param array<string, mixed> $tile @return array{binary?: string, error?: string, detail?: string} */
    private function cameraBinaryForTile(array $tile): array
    {
        HomeDisplayMedia::refreshCameraTile($tile);
        $binary = HomeDisplayMedia::snapshotMediaBinary($tile);
        if ($binary !== null && $binary !== '') {
            return ['binary' => $binary];
        }
        HomeDisplayMedia::refreshCameraTile($tile);
        $binary = HomeDisplayMedia::snapshotMediaBinary($tile);
        if ($binary !== null && $binary !== '') {
            return ['binary' => $binary];
        }
        $raw = HomeDisplayMedia::cameraImageUrl($tile);
        if ($raw === '') {
            $mediaId = HomeDisplayMedia::resolveImageMediaId($tile);
            if ($mediaId > 0) {
                return [
                    'error' => 'camera_fetch',
                    'detail' => 'Medienobjekt leer – ONVIF Image Grabber: Medien „Image“ wählen, Quelle „Medien“, ggf. in Symcon „Bild aktualisieren“ testen',
                ];
            }
            return ['error' => 'camera', 'detail' => 'Variable leer oder Medien-ID ungültig (ONVIF: Medienobjekt Image unter Grabber-Instanz wählen)'];
        }
        if (preg_match('#^https?://#i', $raw)) {
            $binary = $this->fetchRemoteBinary($raw);
            if ($binary !== null && $binary !== '') {
                return ['binary' => $binary];
            }
            $host = parse_url($raw, PHP_URL_HOST);
            $prefix = is_string($host) && $host !== '' ? $host . ' – ' : '';
            $detail = $prefix . ($this->lastFetchHint !== '' ? $this->lastFetchHint : 'URL von Symcon aus nicht abrufbar');
            return ['error' => 'camera_fetch', 'detail' => $detail];
        }
        $binary = HomeDisplayMedia::readBinaryFromPath($raw);
        if ($binary !== null && $binary !== '') {
            return ['binary' => $binary];
        }
        return ['error' => 'camera_fetch', 'detail' => 'Pfad/Datei aus Variable nicht lesbar: ' . substr($raw, 0, 80)];
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

    /** @return array{version: string, sha256: string, signature: string, available: bool, path: string, target: string} */
    private function firmwareDescriptor(int $instanceId = 0): array
    {
        $profile = $instanceId > 0 ? (string) IPS_GetProperty($instanceId, 'DisplayProfile') : '';
        $firebeetle = $profile !== '' && str_starts_with($profile, 'firebeetle-epd-');
        if ($firebeetle) {
            $versionFile = __DIR__ . '/firmware-firebeetle/VERSION';
            $path = __DIR__ . '/firmware-firebeetle/build/firmware.bin';
            $fallbackVersion = '0.1.0';
            $target = 'firebeetle';
        } else {
            $versionFile = __DIR__ . '/firmware/VERSION';
            $path = __DIR__ . '/firmware/build/homedisplay.bin';
            $fallbackVersion = self::FIRMWARE_VERSION;
            $target = 'waveshare';
        }
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : $fallbackVersion;
        $available = is_file($path);
        return [
            'version' => $version !== '' ? $version : $fallbackVersion,
            'sha256' => $available ? hash_file('sha256', $path) : '',
            'signature' => $available ? $this->firmwareSignature($path) : '',
            'available' => $available,
            'path' => $path,
            'target' => $target,
        ];
    }

    /** @param array{id: int, token: string} $device */
    private function sendCameraSnapshot(array $device, string $tileId): void
    {
        $tile = $this->findLayoutTile($device['id'], $tileId);
        if ($tile !== null && (string) ($tile['kind'] ?? '') === 'camera') {
            $shot = $this->cameraBinaryForTile($tile);
            if (isset($shot['binary'])) {
                header('Content-Type: ' . $this->guessImageMime($shot['binary']));
                header('Cache-Control: no-store');
                echo $shot['binary'];
                return;
            }
            http_response_code($shot['error'] === 'camera' ? 404 : 502);
            $this->json(['error' => $shot['error'] ?? 'camera_fetch']);
            return;
        }
        $url = $this->cameraUrlForTile($device['id'], $tileId);
        if ($url === '') {
            http_response_code(404);
            $this->json(['error' => 'camera']);
            return;
        }
        $binary = preg_match('#^https?://#i', $url) ? $this->fetchRemoteBinary($url) : HomeDisplayMedia::readBinaryFromPath($url);
        if ($binary === null || $binary === '') {
            http_response_code(502);
            $this->json(['error' => 'camera_fetch']);
            return;
        }
        header('Content-Type: ' . $this->guessImageMime($binary));
        header('Cache-Control: no-store');
        echo $binary;
    }

    /** @param array{id: int, token: string} $device */
    private function sendMediaSnapshot(array $device, string $tileId): void
    {
        $variableId = $this->mediaVariableForTile($device['id'], $tileId);
        if ($variableId <= 0) {
            http_response_code(404);
            $this->json(['error' => 'media']);
            return;
        }
        $tile = $this->findLayoutTile($device['id'], $tileId);
        $binary = $tile !== null ? HomeDisplayMedia::snapshotMediaBinary($tile) : null;
        if (($binary === null || $binary === '') && $variableId > 0) {
            $binary = HomeDisplayMedia::variableMediaBytes($variableId);
        }
        if ($binary === null || $binary === '') {
            http_response_code(502);
            $this->json(['error' => 'media_fetch']);
            return;
        }
        header('Content-Type: ' . $this->guessImageMime($binary));
        header('Cache-Control: no-store');
        echo $binary;
    }

    private function mediaVariableForTile(int $instanceId, string $tileId): int
    {
        $layout = $this->activeLayout($instanceId);
        if ($layout === null) {
            return 0;
        }
        foreach ($layout['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (!is_array($tile) || (string) ($tile['kind'] ?? '') !== 'image') {
                    continue;
                }
                if ((string) ($tile['imageSource'] ?? 'catalog') !== 'media') {
                    continue;
                }
                if ((string) ($tile['tileId'] ?? '') !== $tileId) {
                    continue;
                }
                return (int) ($tile['variableId'] ?? 0);
            }
        }
        return 0;
    }

    private function cameraUrlForTile(int $instanceId, string $tileId): string
    {
        $tile = $this->findLayoutTile($instanceId, $tileId);
        if ($tile === null || (string) ($tile['kind'] ?? '') !== 'camera') {
            return '';
        }
        return HomeDisplayMedia::cameraImageUrl($tile);
    }

    /** @return array<string, mixed>|null */
    private function findLayoutTile(int $instanceId, string $tileId): ?array
    {
        $layout = $this->activeLayout($instanceId);
        if ($layout === null) {
            return null;
        }
        foreach ($layout['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($page['tiles'] ?? [] as $tile) {
                if (!is_array($tile) || (string) ($tile['tileId'] ?? '') !== $tileId) {
                    continue;
                }
                return $tile;
            }
        }
        return null;
    }

    private function fetchRemoteBinary(string $url): ?string
    {
        $this->lastFetchHint = '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $this->lastFetchHint = 'Keine http(s)-Adresse';
            return null;
        }
        $sslOrder = $this->isPrivateFetchHost($url) ? [false, true] : [true, false];
        foreach ([false, true] as $lenient) {
            foreach ($sslOrder as $verifySsl) {
                $binary = $this->fetchRemoteBinaryAttempt($url, $verifySsl, $lenient);
                if ($binary !== null) {
                    return $binary;
                }
            }
        }
        if ($this->lastFetchHint === '') {
            $this->lastFetchHint = 'Antwort ist kein Bild oder HTTP-Fehler';
        }
        return null;
    }

    /** @return array{url: string, userPwd: string} */
    private function normalizeFetchUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['url' => $url, 'userPwd' => ''];
        }
        if (empty($parts['user'])) {
            return ['url' => $url, 'userPwd' => ''];
        }
        $userPwd = (string) $parts['user'];
        if (isset($parts['pass'])) {
            $userPwd .= ':' . (string) $parts['pass'];
        }
        $scheme = (string) ($parts['scheme'] ?? 'http');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';
        $clean = $scheme . '://' . (string) $parts['host'] . $port . $path . $query . $fragment;
        return ['url' => $clean, 'userPwd' => $userPwd];
    }

    private function isPrivateFetchHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        if (strcasecmp($host, 'localhost') === 0) {
            return true;
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $long = ip2long($host);
        if ($long === false) {
            return false;
        }
        $private = (
            ($long & 0xFF000000) === 0x0A000000
            || ($long & 0xFFF00000) === 0xAC100000
            || ($long & 0xFFFF0000) === 0xC0A80000
            || ($long & 0xFF000000) === 0x7F000000
        );
        return $private;
    }

    private function fetchRemoteBinaryAttempt(string $url, bool $verifySsl, bool $lenient): ?string
    {
        $normalized = $this->normalizeFetchUrl($url);
        if (function_exists('curl_init')) {
            $ch = curl_init($normalized['url']);
            if ($ch !== false) {
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => $verifySsl,
                    CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                    CURLOPT_USERAGENT => 'HomeDisplay/1.0',
                ];
                if ($normalized['userPwd'] !== '') {
                    $opts[CURLOPT_USERPWD] = $normalized['userPwd'];
                    $opts[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
                }
                curl_setopt_array($ch, $opts);
                $binary = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                $err = curl_error($ch);
                curl_close($ch);
                if (!is_string($binary) || $binary === '') {
                    if ($err !== '') {
                        $this->lastFetchHint = $err;
                    }
                    return null;
                }
                if ($code < 200 || $code >= 300) {
                    $this->lastFetchHint = 'HTTP ' . $code;
                    return null;
                }
                if ($this->acceptImageBinary($binary, $lenient) || ($contentType !== '' && preg_match('#^image/#i', $contentType) === 1)) {
                    return $binary;
                }
                $this->lastFetchHint = 'Antwort ist kein Bild (HTTP ' . $code . ')';
                return null;
            }
        }
        $headers = "User-Agent: HomeDisplay/1.0\r\n";
        if ($normalized['userPwd'] !== '') {
            $headers .= 'Authorization: Basic ' . base64_encode($normalized['userPwd']) . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => 1,
                'max_redirects' => 3,
                'header' => $headers,
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $binary = @file_get_contents($normalized['url'], false, $context);
        if (!is_string($binary) || $binary === '') {
            return null;
        }
        if ($this->acceptImageBinary($binary, $lenient)) {
            return $binary;
        }
        $this->lastFetchHint = 'Antwort ist kein Bild';
        return null;
    }

    private function acceptImageBinary(string $binary, bool $lenient): bool
    {
        if ($this->looksLikeImageBinary($binary)) {
            return true;
        }
        return $lenient && $this->looksLikeImageBinaryLenient($binary);
    }

    private function looksLikeImageBinary(string $binary): bool
    {
        if ($binary === '' || strlen($binary) < 32) {
            return false;
        }
        $head = strtolower(substr($binary, 0, 256));
        if (str_starts_with(ltrim($binary), '<') || str_contains($head, '<html') || str_contains($head, '<!doctype')) {
            return false;
        }
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return true;
        }
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return true;
        }
        if (str_starts_with($binary, 'GIF87a') || str_starts_with($binary, 'GIF89a')) {
            return true;
        }
        return str_starts_with($binary, 'RIFF') && strlen($binary) > 12 && substr($binary, 8, 4) === 'WEBP';
    }

    private function looksLikeImageBinaryLenient(string $binary): bool
    {
        if ($binary === '' || strlen($binary) < 512) {
            return false;
        }
        $head = strtolower(substr($binary, 0, 512));
        if (str_contains($head, '<html') || str_contains($head, '<!doctype') || str_contains($head, '{"')) {
            return false;
        }
        return true;
    }

    private function guessImageMime(string $binary): string
    {
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        return 'image/jpeg';
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
        $device = $this->deviceFromToken();
        $instanceId = is_array($device) ? (int) ($device['id'] ?? 0) : 0;
        $firmware = $this->firmwareDescriptor($instanceId);
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

    /** @param array<string, mixed> $body */
    private function markPanelHello(int $instanceId, array $body): void
    {
        $runtime = $this->runtime();
        $key = (string) $instanceId;
        $runtime[$key]['lastCheck'] = time();
        $this->saveRuntime($runtime);
        $label = 'Gekoppelt';
        if (isset($body['firmware']) && (string) $body['firmware'] !== '') {
            $label .= ' · Programm ' . (string) $body['firmware'];
        }
        $pairingId = @IPS_GetObjectIDByIdent('PairingState', $instanceId);
        if (is_int($pairingId) && $pairingId > 0) {
            IPS_SetValue($pairingId, $label);
        }
        if (function_exists('HDISP_SetPanelStatus')) {
            HDISP_SetPanelStatus($instanceId, $label);
        }
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
                $kind = (string) ($tile['kind'] ?? 'value');
                if ($kind === 'energyflow') {
                    foreach (HomeDisplayEnergyFlow::variablePropertyKeys($tile) as $prop) {
                        $variableId = (int) ($tile[$prop] ?? 0);
                        if ($variableId > 0 && IPS_VariableExists($variableId)) {
                            $this->RegisterMessage($variableId, 10603);
                        }
                    }
                    continue;
                }
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
