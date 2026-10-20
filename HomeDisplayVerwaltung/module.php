<?php

declare(strict_types=1);

class HomeDisplayVerwaltung extends IPSModuleStrict
{
    private const PANEL_MODULE = '{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}';
    private const HOOK = '/hook/homedisplay-admin';
    private const SERVICE_HOOK = '/hook/homedisplay';
    private const WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const IM_DELETE = 10102;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('AdminPassword', '');
        $this->RegisterPropertyString('ServiceHost', '');
        $this->RegisterVariableString('AdminUrl', 'Verwaltungsseite', '', 1);
        $this->RegisterVariableString('ServiceUrl', 'Dienstadresse', '', 2);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->RegisterMessage(0, self::IM_DELETE);
        $this->SetValue('AdminUrl', $this->pageUrl());
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->updateWebHookControl(false);
            $this->SetValue('ServiceUrl', '');
            return;
        }
        try {
            $this->updateWebHookControl(true);
            $this->syncServiceHook();
        } catch (Throwable $e) {
            $this->SetStatus(201);
            $this->SendDebug('Verwaltung', $e->getMessage(), 0);
            return;
        }
        $this->publishServiceUrl();
        $this->SetStatus($this->ReadPropertyString('AdminPassword') === '' ? 202 : 102);
    }

    /** Schreibt die Dienstadresse in die eigene Variable und in die Variable „Dienstadresse“ jeder aktiven Anzeige. */
    private function publishServiceUrl(): string
    {
        $url = $this->serviceInstance() > 0 ? $this->serviceUrl() : '';
        $this->SetValue('ServiceUrl', $url);
        if (function_exists('HDISP_SetAdapterUrl')) {
            foreach ($this->panelIds() as $id) {
                try {
                    HDISP_SetAdapterUrl($id, $this->isActivePanel($id) ? $url : '');
                } catch (Throwable $e) {
                    $this->SendDebug('Dienst', $e->getMessage(), 0);
                }
            }
        }
        return $url;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        unset($TimeStamp, $SenderID, $Data);
        if ($Message !== self::IM_DELETE || !$this->ReadPropertyBoolean('Active')) {
            return;
        }
        try {
            $this->syncServiceHook();
        } catch (Throwable $e) {
            $this->SendDebug('Dienst', $e->getMessage(), 0);
        }
    }

    public function GetConfigurationForm(): string
    {
        $raw = (string) @file_get_contents(__DIR__ . '/form.json');
        $form = json_decode($raw, true);
        if (!is_array($form) || !is_array($form['elements'] ?? null)) {
            return $raw !== '' ? $raw : '{"elements":[]}';
        }
        foreach ($form['elements'] as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['name'] ?? '') === 'AdminUrlLabel') {
                $element['caption'] = 'Adresse der Verwaltungsseite (Editor): ' . $this->pageUrl();
            }
            if (($element['name'] ?? '') === 'AdminUrlNote') {
                if ($this->ReadPropertyString('AdminPassword') === '') {
                    $element['caption'] = 'Zuerst ein Passwort eintragen und die Instanz übernehmen. Ohne Passwort antwortet die Seite nicht.';
                } else {
                    $element['caption'] = 'Diese Adresse im Browser öffnen (nicht die Dienstadresse unten). Beim Aufruf erscheint die Anmeldung mit dem Passwort von oben.';
                }
            }
            if (($element['name'] ?? '') === 'ServiceUrlLabel') {
                $element['caption'] = $this->serviceLabel();
            }
            if (($element['name'] ?? '') === 'ServiceUrlNote') {
                $element['caption'] = 'Nur für gekoppelte Anzeigen. Wird die Dienstadresse im Browser geöffnet, erscheint {\"error\":\"token\"} – das ist erwartet.';
            }
        }
        unset($element);
        $encoded = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $raw : $encoded;
    }

    /** Richtet den Dienst für die Anzeigen ein und liefert seine Adresse. */
    public function SyncService(): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return '';
        }
        $this->syncServiceHook();
        return $this->publishServiceUrl();
    }

    public function GetServiceUrl(): string
    {
        return $this->serviceInstance() > 0 ? $this->serviceUrl() : '';
    }

    public function GetServiceInstance(): int
    {
        return $this->serviceInstance();
    }

    /** Adresse der Verwaltungsseite im Browser (Editor), inklusive abschließendem Schrägstrich. */
    public function GetAdminPageUrl(): string
    {
        return $this->pageUrl();
    }

    /** Zieht den Dienst von einer Anzeige ab, die gelöscht oder deaktiviert wird. */
    public function MoveService(int $ExcludeInstance): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return '';
        }
        $this->syncServiceHook($ExcludeInstance);
        $url = $this->publishServiceUrl();
        return $this->serviceInstance($ExcludeInstance) > 0 ? $url : '';
    }

    private function serviceLabel(): string
    {
        $instance = $this->serviceInstance();
        if ($instance === 0) {
            return 'Dienst: keine aktive Hausanzeige-Instanz vorhanden. Zuerst eine Anzeige anlegen.';
        }
        return 'Dienst: ' . $this->serviceUrl() . ' (läuft über „' . IPS_GetName($instance) . '“, ID ' . $instance . ')';
    }

    /** Die Anzeige-Instanz, die den Dienst beantwortet: die bestehende Zuordnung bleibt, sonst die erste aktive Anzeige. */
    private function serviceInstance(int $exclude = 0): int
    {
        $current = $this->serviceHookTarget();
        if ($current > 0 && $current !== $exclude && $this->isActivePanel($current)) {
            return $current;
        }
        foreach ($this->panelIds() as $id) {
            if ($id !== $exclude && $this->isActivePanel($id)) {
                return $id;
            }
        }
        return 0;
    }

    private function isActivePanel(int $id): bool
    {
        if (!IPS_InstanceExists($id)) {
            return false;
        }
        $instance = IPS_GetInstance($id);
        if (($instance['ModuleInfo']['ModuleID'] ?? '') !== self::PANEL_MODULE) {
            return false;
        }
        return (bool) IPS_GetProperty($id, 'Active');
    }

    /** @return list<int> */
    private function panelIds(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::PANEL_MODULE);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        sort($ids);
        return $ids;
    }

    private function serviceHookTarget(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        $hooks = json_decode((string) IPS_GetProperty((int) $ids[0], 'Hooks'), true);
        foreach (is_array($hooks) ? $hooks : [] as $hook) {
            if (is_array($hook) && ($hook['Hook'] ?? '') === self::SERVICE_HOOK) {
                return (int) ($hook['TargetID'] ?? 0);
            }
        }
        return 0;
    }

    /** Trägt /hook/homedisplay im WebHook Control auf die Dienst-Instanz ein oder entfernt ihn ohne Anzeige. */
    private function syncServiceHook(int $exclude = 0): void
    {
        $ids = @IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
        if (!is_array($ids) || $ids === []) {
            throw new RuntimeException('WebHook Control nicht gefunden.');
        }
        $controlId = (int) $ids[0];
        $target = $this->serviceInstance($exclude);
        $hooks = json_decode((string) IPS_GetProperty($controlId, 'Hooks'), true);
        $hooks = is_array($hooks) ? $hooks : [];
        $kept = [];
        foreach ($hooks as $hook) {
            if (is_array($hook) && ($hook['Hook'] ?? '') === self::SERVICE_HOOK) {
                continue;
            }
            $kept[] = $hook;
        }
        if ($target > 0) {
            $kept[] = ['Hook' => self::SERVICE_HOOK, 'TargetID' => $target];
        }
        if ($kept === $hooks) {
            return;
        }
        IPS_SetProperty($controlId, 'Hooks', json_encode($kept));
        IPS_ApplyChanges($controlId);
    }

    private function serviceUrl(): string
    {
        return $this->baseUrl() . self::SERVICE_HOOK;
    }

    private function baseUrl(): string
    {
        $host = trim($this->serviceHost());
        if ($host === '') {
            $host = gethostbyname(gethostname());
        }
        $https = false;
        $port = 3777;
        if (function_exists('IPS_GetOption')) {
            $ssl = @IPS_GetOption('WebServer::SSL');
            $https = $ssl === true || $ssl === 1 || $ssl === '1';
            $configured = @IPS_GetOption('WebServer::Port');
            if (is_numeric($configured) && (int) $configured > 0) {
                $port = (int) $configured;
            }
        }
        return ($https ? 'https://' : 'http://') . $host . ':' . $port;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $instance = $this->serviceInstance();
        $panels = [];
        foreach ($this->panelIds() as $id) {
            $panels[] = [
                'id' => $id,
                'name' => IPS_GetName($id),
                'active' => (bool) $this->panelProperty($id, 'Active', false),
                'paired' => (string) $this->panelProperty($id, 'DeviceToken', '') !== '',
                'layoutId' => (string) $this->panelProperty($id, 'LayoutId', ''),
                'pairing' => $this->variableText($id, 'PairingState'),
                'status' => $this->variableText($id, 'PanelStatus'),
                'serves' => $id === $instance,
                'displayProfile' => (string) $this->panelProperty($id, 'DisplayProfile', 'waveshare-5b-1024x600'),
            ];
        }
        return [
            'ok' => true,
            'serviceHost' => $this->serviceHost(),
            'serviceUrl' => $instance > 0 ? $this->serviceUrl() : '',
            'serviceInstance' => $instance,
            'serviceInstanceName' => $instance > 0 ? IPS_GetName($instance) : '',
            'adminUrl' => $this->pageUrl(),
            'panels' => $panels,
        ];
    }

    private function serviceHost(): string
    {
        try {
            return trim($this->ReadPropertyString('ServiceHost'));
        } catch (Throwable $e) {
            return '';
        }
    }

    /** @param mixed $default */
    private function panelProperty(int $id, string $name, $default)
    {
        try {
            return IPS_GetProperty($id, $name);
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function panelOrFail(int $id): int
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            throw new InvalidArgumentException('Anzeige nicht gefunden.');
        }
        $instance = IPS_GetInstance($id);
        if (($instance['ModuleInfo']['ModuleID'] ?? '') !== self::PANEL_MODULE) {
            throw new InvalidArgumentException('Anzeige nicht gefunden.');
        }
        return $id;
    }

    private function variableText(int $instanceId, string $ident): string
    {
        $variableId = @IPS_GetObjectIDByIdent($ident, $instanceId);
        if (!is_int($variableId) || $variableId <= 0) {
            return '';
        }
        return (string) GetValue($variableId);
    }

    public function ProcessHookData(): void
    {
        ob_start();
        try {
            if ($this->ReadPropertyString('AdminPassword') === '') {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Bitte in der Instanz ein Passwort setzen und übernehmen.';
                return;
            }
            if (!$this->authorized()) {
                header('WWW-Authenticate: Basic realm="Hausanzeige Verwaltung"');
                http_response_code(401);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Anmeldung erforderlich.';
                return;
            }
            $route = $this->hookRoute();
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method === 'GET' && ($route === '/' || $route === '')) {
                $this->sendFile('index.html', 'text/html; charset=utf-8');
                return;
            }
            if ($method === 'GET' && $route === '/app.css') {
                $this->sendFile('app.css', 'text/css; charset=utf-8');
                return;
            }
            if ($method === 'GET' && $route === '/app.js') {
                $this->sendFile('app.js', 'text/javascript; charset=utf-8');
                return;
            }
            if ($method === 'GET' && str_starts_with($route, '/images/')) {
                $this->sendImage(substr($route, strlen('/images/')));
                return;
            }
            if ($method === 'GET' && $route === '/probe-image') {
                $this->sendProbeImage();
                return;
            }
            if ($method === 'POST' && $route === '/api') {
                $this->json($this->api($this->body()));
                return;
            }
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Nicht gefunden.';
        } catch (Throwable $e) {
            http_response_code(400);
            $this->SendDebug('Verwaltung', $e->getMessage(), 0);
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function api(array $body): array
    {
        $method = (string) ($body['method'] ?? '');
        if ($method === 'settings') {
            try {
                return ['ok' => true, 'result' => $this->settings()];
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        if ($method === 'saveSettings') {
            $host = trim((string) ($body['serviceHost'] ?? ''));
            if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
                return ['ok' => false, 'error' => 'Die Dienstadresse darf nur Hostname oder IP enthalten, ohne http:// und ohne Port.'];
            }
            try {
                if ($host !== $this->serviceHost()) {
                    IPS_SetProperty($this->InstanceID, 'ServiceHost', $host);
                    IPS_ApplyChanges($this->InstanceID);
                } else {
                    $this->syncServiceHook();
                    $this->publishServiceUrl();
                }
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'Dienstadresse konnte nicht gespeichert werden. Instanz „Hausanzeige Verwaltung“ neu anlegen. ' . $e->getMessage()];
            }
            try {
                return ['ok' => true, 'result' => $this->settings()];
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        if ($method === 'inspect') {
            $id = (int) ($body['id'] ?? 0);
            try {
                $this->panelOrFail($id);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            if (!function_exists('HDISP_Inspect')) {
                return ['ok' => false, 'error' => 'HomeDisplay ist nicht geladen.'];
            }
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_Inspect($id))];
        }
        if ($method === 'previewLayout') {
            $id = (int) ($body['id'] ?? 0);
            try {
                $this->panelOrFail($id);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            $layout = $body['layout'] ?? null;
            if (!is_array($layout)) {
                return ['ok' => false, 'error' => 'Ansicht fehlt.'];
            }
            if (!function_exists('HDISP_PreviewLayoutState')) {
                return ['ok' => false, 'error' => 'HomeDisplay ist nicht geladen (Bibliothek aktualisieren).'];
            }
            $json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return ['ok' => false, 'error' => 'Ansicht konnte nicht gelesen werden.'];
            }
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_PreviewLayoutState($id, $json))];
        }
        if ($method === 'simulateAction') {
            $id = (int) ($body['id'] ?? 0);
            try {
                $this->panelOrFail($id);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            if (!function_exists('HDISP_SimulateAction')) {
                return ['ok' => false, 'error' => 'HomeDisplay ist nicht geladen.'];
            }
            $value = isset($body['value']) && $body['value'] !== '' && $body['value'] !== null ? (string) $body['value'] : '';
            $tileJson = '';
            if (isset($body['tile']) && is_array($body['tile'])) {
                $encoded = json_encode($body['tile'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    $tileJson = $encoded;
                }
            }
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_SimulateAction($id, (string) ($body['tileId'] ?? ''), (string) ($body['actionId'] ?? ''), $value, $tileJson))];
        }
        $panel = $this->panelId();
        if ($panel === 0) {
            return ['ok' => false, 'error' => 'Keine HomeDisplay-Instanz gefunden.'];
        }
        if (!function_exists('HDISP_ListCatalog')) {
            return ['ok' => false, 'error' => 'HomeDisplay ist nicht geladen.'];
        }
        if ($method === 'catalog') {
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_ListCatalog($panel))];
        }
        if ($method === 'variables') {
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_ListVariables($panel))];
        }
        if ($method === 'template') {
            $profile = (string) ($body['displayProfile'] ?? '');
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_LayoutTemplate($panel, (string) ($body['name'] ?? 'Übersicht'), $profile))];
        }
        if ($method === 'probeImage') {
            $id = (int) ($body['id'] ?? 0);
            try {
                $this->panelOrFail($id);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            if (!function_exists('HDISP_ProbeTileImage')) {
                return ['ok' => false, 'error' => 'HomeDisplay ist nicht geladen.'];
            }
            $tileJson = '';
            if (isset($body['tile']) && is_array($body['tile'])) {
                $encoded = json_encode($body['tile'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    $tileJson = $encoded;
                }
            }
            $mode = !empty($body['meta']) ? 'meta' : '';
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_ProbeTileImage($id, (string) ($body['tileId'] ?? ''), $tileJson, $mode))];
        }
        if ($method === 'images') {
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_ListImages($panel))];
        }
        if ($method === 'uploadImage') {
            $name = (string) ($body['name'] ?? '');
            $data = (string) ($body['data'] ?? '');
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_SaveImage($panel, $name, $data))];
        }
        if ($method === 'deleteImage') {
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_DeleteImage($panel, (string) ($body['id'] ?? '')))];
        }
        if ($method === 'saveConfig' || $method === 'publishConfig') {
            $config = $body['config'] ?? null;
            if (!is_array($config)) {
                return ['ok' => false, 'error' => 'Konfiguration fehlt.'];
            }
            $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return ['ok' => false, 'error' => 'Konfiguration konnte nicht gelesen werden.'];
            }
            $raw = $method === 'publishConfig' ? HDISP_PublishConfig($panel, $json) : HDISP_SaveConfig($panel, $json);
            return ['ok' => true, 'result' => $this->decodeCall($raw)];
        }
        if ($method === 'save' || $method === 'publish') {
            $layout = $body['layout'] ?? null;
            if (!is_array($layout)) {
                return ['ok' => false, 'error' => 'Ansicht fehlt.'];
            }
            $json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return ['ok' => false, 'error' => 'Ansicht konnte nicht gelesen werden.'];
            }
            $raw = $method === 'publish' ? HDISP_PublishLayout($panel, $json) : HDISP_SaveLayout($panel, $json);
            return ['ok' => true, 'result' => $this->decodeCall($raw)];
        }
        return ['ok' => false, 'error' => 'Unbekannte Aktion.'];
    }

    /** @return array<string, mixed> */
    private function decodeCall(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Antwort ungültig.'];
    }

    private function panelId(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::PANEL_MODULE);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        return (int) $ids[0];
    }

    private function authorized(): bool
    {
        $expected = (string) $this->ReadPropertyString('AdminPassword');
        $given = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
        if ($given === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Basic\s+([A-Za-z0-9+\/=]+)/', $_SERVER['HTTP_AUTHORIZATION'], $match)) {
                $decoded = base64_decode($match[1], true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    $given = substr($decoded, strpos($decoded, ':') + 1);
                }
            }
        }
        return $expected !== '' && hash_equals($expected, $given);
    }

    private function pageUrl(): string
    {
        return $this->baseUrl() . self::HOOK . '/';
    }

    private function sendProbeImage(): void
    {
        $panel = (int) ($_GET['panel'] ?? 0);
        $tileId = trim((string) ($_GET['tile'] ?? ''));
        if ($tileId === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $tileId)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Kachel fehlt.';
            return;
        }
        try {
            $this->panelOrFail($panel);
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            return;
        }
        if (!function_exists('HDISP_ProbeTileImage')) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'HomeDisplay nicht geladen.';
            return;
        }
        $result = $this->decodeCall(HDISP_ProbeTileImage($panel, $tileId, '', 'stream'));
        if (empty($result['ok'])) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-HomeDisplay-Error: ' . (string) ($result['error'] ?? 'unknown'));
            if (!empty($result['detail'])) {
                header('X-HomeDisplay-Detail: ' . substr((string) $result['detail'], 0, 200));
            }
            echo 'Snapshot fehlt.';
            return;
        }
        $path = $this->probeCacheFile($panel, $tileId);
        if (!is_file($path)) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-HomeDisplay-Error: camera_fetch');
            header('X-HomeDisplay-Detail: Bild-Cache fehlt nach Abruf');
            echo 'Snapshot fehlt.';
            return;
        }
        header('Content-Type: ' . (string) ($result['mime'] ?? 'image/jpeg'));
        header('Cache-Control: no-store');
        readfile($path);
    }

    private function probeCacheFile(int $panel, string $tileId): string
    {
        $kernel = rtrim(IPS_GetKernelDir(), '\\/');
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $tileId) ?? '';
        if ($safe === '') {
            $safe = 'tile';
        }
        return $kernel . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'HomeDisplay'
            . DIRECTORY_SEPARATOR . 'probe-cache' . DIRECTORY_SEPARATOR . $panel . '-' . $safe . '.bin';
    }

    private function sendImage(string $id): void
    {
        $id = preg_replace('/\.[a-z]+$/', '', $id) ?? $id;
        if (!preg_match('/^[a-f0-9]{16}$/', $id) || !function_exists('HDISP_GetImage')) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bild fehlt.';
            return;
        }
        $panel = $this->panelId();
        $result = $panel > 0 ? $this->decodeCall(HDISP_GetImage($panel, $id)) : [];
        if (empty($result['ok'])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bild fehlt.';
            return;
        }
        $binary = base64_decode((string) ($result['data'] ?? ''), true);
        header('Content-Type: ' . (string) ($result['mime'] ?? 'image/png'));
        header('Cache-Control: max-age=3600');
        echo is_string($binary) ? $binary : '';
    }

    private function sendFile(string $name, string $type): void
    {
        $path = __DIR__ . '/web/' . $name;
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Datei fehlt.';
            return;
        }
        header('Content-Type: ' . $type);
        header('Cache-Control: no-store');
        readfile($path);
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
        return ($route === '' || $route === '/') ? '/' : $route;
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): void
    {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $encoded = json_encode($payload, $flags);
        echo is_string($encoded) ? $encoded : '{"ok":false,"error":"Antwort konnte nicht kodiert werden."}';
    }

    private function updateWebHookControl(bool $register): void
    {
        $ids = @IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
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
            $owner = (int) ($hook['TargetID'] ?? 0);
            if ($register && $owner !== $this->InstanceID && IPS_InstanceExists($owner)) {
                throw new RuntimeException('Die Verwaltungsseite läuft bereits auf einer anderen Instanz.');
            }
            if ($register && $owner === $this->InstanceID) {
                $present = true;
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
}
