<?php

declare(strict_types=1);

class HomeDisplayVerwaltung extends IPSModuleStrict
{
    private const PANEL_MODULE = '{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}';
    private const HOOK = '/hook/homedisplay-admin';
    private const WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('AdminPassword', '');
        $this->RegisterVariableString('AdminUrl', 'Verwaltungsseite', '', 1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $url = $this->pageUrl();
        $this->SetValue('AdminUrl', $url);
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->updateWebHookControl(false);
            return;
        }
        try {
            $this->updateWebHookControl(true);
        } catch (Throwable $e) {
            $this->SetStatus(201);
            $this->SendDebug('Verwaltung', $e->getMessage(), 0);
            return;
        }
        $this->SetStatus($this->ReadPropertyString('AdminPassword') === '' ? 202 : 102);
    }

    public function GetConfigurationForm(): string
    {
        $raw = (string) @file_get_contents(__DIR__ . '/form.json');
        $form = json_decode($raw, true);
        if (!is_array($form) || !is_array($form['elements'] ?? null)) {
            return $raw !== '' ? $raw : '{"elements":[]}';
        }
        foreach ($form['elements'] as &$element) {
            if (is_array($element) && ($element['name'] ?? '') === 'AdminUrlLabel') {
                $element['caption'] = 'Adresse: ' . $this->pageUrl();
            }
        }
        unset($element);
        $encoded = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $raw : $encoded;
    }

    public function ProcessHookData(): void
    {
        try {
            if ($this->ReadPropertyString('AdminPassword') === '') {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Bitte in der Instanz ein Passwort setzen und übernehmen.';
                return;
            }
            if (!$this->authorized()) {
                header('WWW-Authenticate: Basic realm="HomeDisplay Verwaltung"');
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
        $panel = $this->panelId();
        if ($panel === 0) {
            return ['ok' => false, 'error' => 'Keine HomeDisplay-Instanz gefunden.'];
        }
        if (!function_exists('HDISP_ListCatalog')) {
            return ['ok' => false, 'error' => 'HomeDisplay ist nicht geladen.'];
        }
        $method = (string) ($body['method'] ?? '');
        if ($method === 'catalog') {
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_ListCatalog($panel))];
        }
        if ($method === 'variables') {
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_ListVariables($panel))];
        }
        if ($method === 'template') {
            return ['ok' => true, 'result' => $this->decodeCall(HDISP_LayoutTemplate($panel, (string) ($body['name'] ?? 'Dashboard')))];
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
        $host = gethostbyname(gethostname());
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
        return ($https ? 'https://' : 'http://') . $host . ':' . $port . self::HOOK . '/';
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
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
