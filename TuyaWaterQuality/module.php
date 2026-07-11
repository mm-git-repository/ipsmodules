<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/TuyaLocalClient.php';
require_once __DIR__ . '/libs/TuyaWaterQualityMapping.php';
require_once __DIR__ . '/libs/TuyaCloudSharing.php';
require_once __DIR__ . '/libs/TuyaQrImage.php';

class TuyaWaterQuality extends IPSModuleStrict
{
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 12;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;
    private const IS_INVALID_CONFIG = 201;
    private const IS_UNREACHABLE = 202;

    private const UPDATE_INTERVAL_DEFAULT_SEC = 60;
    private const UPDATE_INTERVAL_MIN_SEC = 15;

    private const BUF_QR_TOKEN = 'CloudQrToken';
    private const BUF_QR_IMAGE = 'CloudQrImageDataUri';
    private const BUF_PENDING_CLOUD = 'PendingCloudApply';
    private const BUF_SESSION = 'CloudSession';
    private const BUF_DEVICES = 'CloudDevices';
    private const BUF_STATUS = 'CloudCouplingStatus';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('CloudUserCode', '');
        $this->RegisterPropertyString('CloudSelectedDevice', '');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('LocalKey', '');
        $this->RegisterPropertyString('ProtocolVersion', '3.3');
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_INTERVAL_DEFAULT_SEC);
        $this->RegisterPropertyString('DpMapping', TuyaWaterQualityMapping::DEFAULT_JSON);

        $this->ensureProfiles();
        $this->registerVariables();

        $this->RegisterTimer('Update', 0, 'TWQT_UpdateValues($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        $this->applyPendingCloudCoupling();

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

        $form = $this->injectCloudFormElements($form);

        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $encoded = json_encode($form, $flags);
        if (!is_string($encoded)) {
            $this->SendDebug('Form', 'GetConfigurationForm: json_encode fehlgeschlagen', 0);

            return '{}';
        }

        return $encoded;
    }

    public function Refresh(): void
    {
        $this->UpdateValues();
    }

    /**
     * Formular-Feedback nach manuellem Update (echo).
     */
    public function RefreshFeedback(): string
    {
        $this->UpdateValues();

        $host = trim($this->ReadPropertyString('Host'));
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        $proto = trim($this->ReadPropertyString('ProtocolVersion'));
        $reachable = (bool) $this->GetValue('Reachable');
        $error = trim((string) $this->GetValue('LastError'));

        if (!$reachable) {
            return "Aktualisierung fehlgeschlagen\n"
                . 'Host: ' . ($host !== '' ? $host : '(leer)') . "\n"
                . 'Device ID: ' . ($deviceId !== '' ? $deviceId : '(leer)') . "\n"
                . 'Protokoll: ' . ($proto !== '' ? $proto : '3.3') . "\n"
                . 'Fehler: ' . ($error !== '' ? $error : 'unbekannt') . "\n\n"
                . "Tipps:\n"
                . "- Host per „LAN-Scan (IP)“ prüfen\n"
                . "- Protokollversion 3.3 / 3.4 / 3.5 testen\n"
                . "- Details: Instanz → Zahnrad → Debug aktivieren → Meldungen";
        }

        $lines = [
            'Aktualisierung OK',
            'Host: ' . $host,
            'Erreichbar: ja',
        ];

        $tds = $this->GetValue('MeasuredTds');
        $temp = $this->GetValue('MeasuredWaterTemp');
        if ($tds !== null && $tds !== 0.0) {
            $lines[] = 'TDS: ' . $tds . ' ppm';
        }
        if ($temp !== null && $temp !== 0.0) {
            $lines[] = 'Temperatur: ' . $temp . ' °C';
        }

        $raw = trim((string) $this->GetValue('RawDps'));
        if ($raw !== '' && $raw !== '{}') {
            $lines[] = 'DPS: ' . $raw;
        }

        return implode("\n", $lines);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        parent::RequestAction($Ident, $Value);
    }

    public function CloudShowQr(): void
    {
        if (!extension_loaded('openssl')) {
            echo 'OpenSSL-PHP-Erweiterung fehlt (für Geräteliste nach Login nötig).';

            return;
        }

        $userCode = trim($this->ReadPropertyString('CloudUserCode'));
        if ($userCode === '') {
            echo 'Bitte zuerst den User Code eintragen und Übernehmen klicken.';

            return;
        }

        $sharing = new TuyaCloudSharing();
        $result = $sharing->requestQrToken($userCode);
        if (!$result['ok']) {
            $this->setCloudStatus('QR-Fehler: ' . $result['error']);
            echo $result['error'];

            return;
        }

        $this->SetBuffer(self::BUF_QR_TOKEN, $result['token']);
        $this->setCloudStatus('QR bereit — in Tuya Smart scannen, danach „Auf Anmeldung warten“');

        $payload = TuyaCloudSharing::QR_LOGIN_PREFIX . $result['token'];
        $dataUri = TuyaQrImage::fetchPngDataUri($payload);
        if ($dataUri !== null) {
            $this->SetBuffer(self::BUF_QR_IMAGE, $dataUri);
        } else {
            $this->SetBuffer(self::BUF_QR_IMAGE, '');
        }

        // IPS öffnet https-URLs aus Button-echo im Browser (kein HTML im Dialog).
        echo TuyaQrImage::chartUrl($payload);
    }

    public function CloudPollLogin(): string
    {
        if (!extension_loaded('openssl')) {
            return 'OpenSSL-PHP-Erweiterung fehlt.';
        }

        $userCode = trim($this->ReadPropertyString('CloudUserCode'));
        $qrToken = trim((string) $this->GetBuffer(self::BUF_QR_TOKEN));

        if ($userCode === '') {
            return 'User Code fehlt — eintragen und Übernehmen.';
        }
        if ($qrToken === '') {
            return 'Kein QR-Token — zuerst „QR-Code anzeigen“ klicken.';
        }

        $sharing = new TuyaCloudSharing();
        $maxAttempts = 30;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $login = $sharing->pollLoginResult($qrToken, $userCode);
            if ($login['ok']) {
                $this->SetBuffer(self::BUF_SESSION, json_encode($login['session'], JSON_UNESCAPED_UNICODE) ?: '{}');
                $this->setCloudStatus('Cloud verbunden — lade Geräteliste …');

                $devices = $sharing->fetchDevices($login['session']);
                if (!$devices['ok']) {
                    $this->setCloudStatus('Login OK, Geräte: ' . $devices['error']);

                    return 'Angemeldet, aber Geräteliste fehlgeschlagen: ' . $devices['error'];
                }

                $this->storeCloudDevices($devices['devices']);
                $count = count($devices['devices']);
                $this->setCloudStatus(sprintf('Cloud verbunden — %d Gerät(e) gefunden', $count));

                return sprintf(
                    "Anmeldung erfolgreich.\n%d Gerät(e) geladen.\nGerät wählen → „Gerät übernehmen“ → Übernehmen.",
                    $count,
                );
            }

            if ($attempt < $maxAttempts - 1) {
                sleep(2);
            }
        }

        $this->setCloudStatus('Warte auf QR-Scan in Tuya Smart …');

        return 'Noch nicht angemeldet — QR in Tuya Smart scannen und erneut „Auf Anmeldung warten“ klicken.';
    }

    public function CloudFetchDevices(): string
    {
        $session = $this->loadCloudSession();
        if ($session === null) {
            return 'Keine Cloud-Session — zuerst QR-Login abschließen.';
        }

        $sharing = new TuyaCloudSharing();
        $devices = $sharing->fetchDevices($session);
        if (!$devices['ok']) {
            $this->setCloudStatus('Geräteliste: ' . $devices['error']);

            return $devices['error'];
        }

        $this->storeCloudDevices($devices['devices']);
        $count = count($devices['devices']);
        $this->setCloudStatus(sprintf('Cloud verbunden — %d Gerät(e) gefunden', $count));

        return sprintf('%d Gerät(e) geladen.', $count);
    }

    public function CloudApplyDevice(): string
    {
        $selectedId = trim($this->ReadPropertyString('CloudSelectedDevice'));
        if ($selectedId === '') {
            return 'Kein Gerät ausgewählt.';
        }

        $devices = $this->loadCloudDevices();
        $device = null;
        foreach ($devices as $entry) {
            if (($entry['id'] ?? '') === $selectedId) {
                $device = $entry;
                break;
            }
        }

        if ($device === null) {
            return 'Ausgewähltes Gerät nicht in Cloud-Liste — erneut „Auf Anmeldung warten“.';
        }

        $deviceId = (string) ($device['id'] ?? '');
        $localKey = (string) ($device['local_key'] ?? '');
        $host = trim((string) ($device['ip'] ?? ''));
        $productId = (string) ($device['product_id'] ?? '');
        $category = (string) ($device['category'] ?? '');
        $name = (string) ($device['name'] ?? $deviceId);

        if ($deviceId === '' || $localKey === '') {
            return 'Gerät ohne Device ID oder Local Key.';
        }

        $mapping = TuyaWaterQualityMapping::presetForProductId($productId);
        if ($mapping === TuyaWaterQualityMapping::DEFAULT_JSON && $category !== '') {
            $mapping = TuyaWaterQualityMapping::presetForCategory($category);
        }

        if (!$this->setInstanceProperty('DeviceId', $deviceId)
            || !$this->setInstanceProperty('DpMapping', $mapping)) {
            return 'Eigenschaften konnten nicht gesetzt werden.';
        }

        if ($host !== '') {
            $this->setInstanceProperty('Host', $host);
        }

        $this->storePendingCloudApply([
            'DeviceId' => $deviceId,
            'LocalKey' => $localKey,
            'Host' => $host,
            'DpMapping' => $mapping,
        ]);
        $this->applyPendingCloudCoupling();
        $this->persistInstanceConfiguration();
        $this->CloudLogout();
        $this->UpdateValues();

        $hostHint = $host !== '' ? $host : '(IP fehlt — LAN-Scan klicken)';
        $reachable = $this->GetValue('Reachable') ? 'ja' : 'nein';
        $lastError = trim((string) $this->GetValue('LastError'));

        $message = sprintf(
            "Gerät gespeichert: %s\nDevice ID: %s\nHost: %s\nErreichbar: %s",
            $name,
            $deviceId,
            $hostHint,
            $reachable,
        );
        if ($lastError !== '') {
            $message .= "\nHinweis: " . $lastError;
        }
        if ($host === '') {
            $message .= "\n\n→ „LAN-Scan (IP)“ ausführen, falls nötig.";
        }

        return $message;
    }

    public function CloudLogout(): string
    {
        $this->SetBuffer(self::BUF_QR_TOKEN, '');
        $this->SetBuffer(self::BUF_QR_IMAGE, '');
        $this->SetBuffer(self::BUF_SESSION, '');
        $this->SetBuffer(self::BUF_DEVICES, '');
        $this->setCloudStatus('Cloud-Session beendet');

        return 'Cloud-Session gelöscht (Local Key bleibt in den Feldern bis Übernehmen).';
    }

    public function LanDiscover(): string
    {
        if (!function_exists('socket_create')) {
            return 'PHP socket-Erweiterung fehlt.';
        }

        $targetDeviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($targetDeviceId === '') {
            $selected = trim($this->ReadPropertyString('CloudSelectedDevice'));
            if ($selected !== '') {
                $targetDeviceId = $selected;
            }
        }

        $found = $this->udpDiscoverDevices(3);
        if ($found === []) {
            return "Kein Tuya-Gerät per UDP gefunden.\nAlternativ: python -m tinytuya scan\noder feste IP im Router setzen.";
        }

        $lines = [];
        $matchedIp = '';
        foreach ($found as $entry) {
            $line = sprintf('%s — %s (v%s)', $entry['ip'], $entry['id'], $entry['version']);
            $lines[] = $line;
            if ($targetDeviceId !== '' && $entry['id'] === $targetDeviceId) {
                $matchedIp = $entry['ip'];
            }
        }

        if ($matchedIp !== '' && $this->setInstanceProperty('Host', $matchedIp)) {
            array_unshift($lines, 'Host gesetzt: ' . $matchedIp . ' (→ Übernehmen)');
        }

        return "Gefundene Geräte:\n" . implode("\n", $lines);
    }

    public function UpdateValues(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', 'Modul inaktiv');

            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        $localKey = trim($this->ReadPropertyString('LocalKey'));

        if ($host === '' || $deviceId === '' || $localKey === '') {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', 'Host, Device ID oder Local Key fehlt');
            $this->SetStatus(self::IS_INVALID_CONFIG);
            $this->debugLocal('Update', 'Abbruch: unvollständige Konfiguration (host/devId/key)');

            return;
        }

        $this->debugLocal('Update', sprintf(
            'Abfrage starten host=%s devId=%s proto=%s keyLen=%d',
            $host,
            $deviceId,
            $this->ReadPropertyString('ProtocolVersion'),
            strlen($localKey),
        ));

        $mapping = TuyaWaterQualityMapping::parse($this->ReadPropertyString('DpMapping'));
        $dpKeys = $this->extractDpQueryKeys($mapping);

        $client = new TuyaLocalClient(
            $deviceId,
            $localKey,
            $this->ReadPropertyString('ProtocolVersion'),
            function (string $message): void {
                $this->debugLocal('LAN', $message);
            },
            $dpKeys
        );
        $result = $client->fetchStatus($host);
        if (!$result['ok']) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', $result['error']);
            $this->SetStatus(self::IS_UNREACHABLE);
            $this->debugLocal('Update', 'Fehler: ' . $result['error']);

            return;
        }

        $values = TuyaWaterQualityMapping::apply($result['dps'], $mapping);
        $this->debugLocal('Update', 'Mapping angewendet: ' . json_encode($values, JSON_UNESCAPED_UNICODE));

        if ($values['ph'] !== null) {
            $this->SetValue('MeasuredPh', $values['ph']);
        }
        if ($values['orp'] !== null) {
            $this->SetValue('MeasuredOrp', (int) round($values['orp']));
        }
        if ($values['ec'] !== null) {
            $this->SetValue('MeasuredEc', $values['ec']);
        }
        if ($values['tds'] !== null) {
            $this->SetValue('MeasuredTds', $values['tds']);
        }
        if ($values['temperature'] !== null) {
            $this->SetValue('MeasuredWaterTemp', $values['temperature']);
        }

        $this->SetValue('Reachable', true);
        $this->SetValue('LastError', '');
        $this->SetValue('LastUpdate', time());
        $this->SetValue('RawDps', json_encode($result['dps'], JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->SetStatus(self::IS_ACTIVE);
        $this->debugLocal('Update', 'Erfolg, DPS=' . (json_encode($result['dps'], JSON_UNESCAPED_UNICODE) ?: '{}'));
    }

    private function debugLocal(string $category, string $message): void
    {
        $this->SendDebug($category, $message, 0);
    }

    /**
     * @param array<string, array{dp: int, scale: float}> $mapping
     * @return list<int>
     */
    private function extractDpQueryKeys(array $mapping): array
    {
        $keys = [];
        foreach ($mapping as $cfg) {
            $dp = (int) ($cfg['dp'] ?? 0);
            if ($dp > 0) {
                $keys[] = $dp;
            }
        }

        if ($keys === []) {
            return [1, 2];
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function injectCloudFormElements(array $form): array
    {
        $status = trim((string) $this->GetBuffer(self::BUF_STATUS));
        if ($status === '') {
            $status = 'Status: nicht verbunden';
        }

        $options = [
            ['label' => '(zuerst QR-Login und Geräteliste laden)', 'value' => ''],
        ];
        foreach ($this->loadCloudDevices() as $device) {
            $id = (string) ($device['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = (string) ($device['name'] ?? $id);
            $ip = trim((string) ($device['ip'] ?? ''));
            $label = $ip !== '' ? $name . ' (' . $ip . ')' : $name;
            $options[] = ['label' => $label, 'value' => $id];
        }

        $qrToken = trim((string) $this->GetBuffer(self::BUF_QR_TOKEN));

        foreach ($form['elements'] ?? [] as $idx => $element) {
            if (($element['type'] ?? '') !== 'ExpansionPanel') {
                continue;
            }
            if (($element['caption'] ?? '') !== 'Tuya-Kopplung (einmalig)') {
                continue;
            }

            $items = [];

            foreach ($element['items'] ?? [] as $item) {
                if (($item['name'] ?? '') === 'CloudQrImage') {
                    continue;
                }

                if (($item['name'] ?? '') === 'CloudCouplingStatus') {
                    $caption = $status;
                    if ($qrToken !== '') {
                        $caption .= ' — QR aktiv (Button „QR-Code anzeigen“)';
                    }
                    $item['caption'] = $caption;
                }
                if (($item['name'] ?? '') === 'CloudSelectedDevice') {
                    $item['options'] = $options;
                }

                $items[] = $item;
            }

            $form['elements'][$idx]['items'] = $items;
        }

        return $form;
    }

    private function setCloudStatus(string $status): void
    {
        $this->SetBuffer(self::BUF_STATUS, $status);
    }

    /**
     * @param list<array<string, mixed>> $devices
     */
    private function storeCloudDevices(array $devices): void
    {
        $encoded = json_encode($devices, JSON_UNESCAPED_UNICODE);
        $this->SetBuffer(self::BUF_DEVICES, is_string($encoded) ? $encoded : '[]');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCloudDevices(): array
    {
        $raw = (string) $this->GetBuffer(self::BUF_DEVICES);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCloudSession(): ?array
    {
        $raw = (string) $this->GetBuffer(self::BUF_SESSION);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function setInstanceProperty(string $name, string $value): bool
    {
        if (!function_exists('IPS_SetProperty')) {
            return false;
        }

        return (bool) IPS_SetProperty($this->InstanceID, $name, $value);
    }

    /**
     * @param array{DeviceId: string, LocalKey: string, Host: string, DpMapping: string} $data
     */
    private function storePendingCloudApply(array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->SetBuffer(self::BUF_PENDING_CLOUD, is_string($encoded) ? $encoded : '');
        $this->setCloudStatus('Gerät bereit — bitte „Übernehmen“ klicken (Local Key wird gespeichert)');
    }

    private function applyPendingCloudCoupling(): void
    {
        $raw = trim((string) $this->GetBuffer(self::BUF_PENDING_CLOUD));
        if ($raw === '') {
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->SetBuffer(self::BUF_PENDING_CLOUD, '');

            return;
        }

        $deviceId = trim((string) ($data['DeviceId'] ?? ''));
        $localKey = trim((string) ($data['LocalKey'] ?? ''));
        if ($deviceId === '' || $localKey === '') {
            return;
        }

        $this->setInstanceProperty('DeviceId', $deviceId);
        $this->setInstanceProperty('LocalKey', $localKey);
        $this->setInstanceProperty('DpMapping', trim((string) ($data['DpMapping'] ?? TuyaWaterQualityMapping::DEFAULT_JSON)));

        $host = trim((string) ($data['Host'] ?? ''));
        if ($host !== '') {
            $this->setInstanceProperty('Host', $host);
        }

        $this->SetBuffer(self::BUF_PENDING_CLOUD, '');
        $this->setCloudStatus('Gerät übernommen — Konfiguration gespeichert');
    }

    private function persistInstanceConfiguration(): void
    {
        if (function_exists('IPS_ApplyChanges')) {
            IPS_ApplyChanges($this->InstanceID);

            return;
        }

        $this->ApplyChanges();
    }

    /**
     * Vereinfachter Tuya-UDP-Discovery (Broadcast Port 6666).
     *
     * @return list<array{id: string, ip: string, version: string}>
     */
    private function udpDiscoverDevices(int $timeoutSec): array
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return [];
        }

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeoutSec, 'usec' => 0]);

        $payload = json_encode([
            'from' => 'app',
            'id' => 'scan',
            'method' => 'discovery',
            'params' => [],
            't' => (int) (microtime(true) * 1000),
            'version' => '1.0',
        ], JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            socket_close($socket);

            return [];
        }

        @socket_sendto($socket, $payload, strlen($payload), 0, '255.255.255.255', 6666);
        @socket_sendto($socket, $payload, strlen($payload), 0, '255.255.255.255', 6667);

        $found = [];
        $deadline = time() + $timeoutSec;
        while (time() < $deadline) {
            $buf = '';
            $from = '';
            $port = 0;
            $bytes = @socket_recvfrom($socket, $buf, 4096, 0, $from, $port);
            if ($bytes === false || $bytes <= 0) {
                continue;
            }

            $decoded = json_decode($buf, true);
            if (!is_array($decoded)) {
                continue;
            }

            $id = (string) ($decoded['gwId'] ?? $decoded['devId'] ?? $decoded['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ip = (string) ($decoded['ip'] ?? $from);
            $version = (string) ($decoded['version'] ?? $decoded['ver'] ?? '?');
            $found[$id] = ['id' => $id, 'ip' => $ip, 'version' => $version];
        }

        socket_close($socket);

        return array_values($found);
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('TWQT.pH')) {
            IPS_CreateVariableProfile('TWQT.pH', 2);
            IPS_SetVariableProfileText('TWQT.pH', '', '');
            IPS_SetVariableProfileDigits('TWQT.pH', 2);
        }
        if (!IPS_VariableProfileExists('TWQT.mV')) {
            IPS_CreateVariableProfile('TWQT.mV', 1);
            IPS_SetVariableProfileText('TWQT.mV', '', ' mV');
        }
        if (!IPS_VariableProfileExists('TWQT.uScm')) {
            IPS_CreateVariableProfile('TWQT.uScm', 2);
            IPS_SetVariableProfileText('TWQT.uScm', '', ' µS/cm');
        }
        if (!IPS_VariableProfileExists('TWQT.ppm')) {
            IPS_CreateVariableProfile('TWQT.ppm', 2);
            IPS_SetVariableProfileText('TWQT.ppm', '', ' ppm');
        }
    }

    private function registerVariables(): void
    {
        $pos = 0;
        $textPres = '~TextBox';

        $this->RegisterVariableFloat('MeasuredPh', 'pH gemessen', 'TWQT.pH', $pos++);
        $this->RegisterVariableInteger('MeasuredOrp', 'ORP gemessen', 'TWQT.mV', $pos++);
        $this->RegisterVariableFloat('MeasuredEc', 'EC gemessen', 'TWQT.uScm', $pos++);
        $this->RegisterVariableFloat('MeasuredTds', 'TDS gemessen', 'TWQT.ppm', $pos++);
        $this->RegisterVariableFloat('MeasuredWaterTemp', 'Wassertemperatur gemessen', '~Temperature', $pos++);

        $this->RegisterVariableBoolean('Reachable', 'Erreichbar', '~Switch', $pos++);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', $pos++);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', $textPres, $pos++);
        $this->RegisterVariableString('RawDps', 'Roh-DPS (Debug)', $textPres, $pos++);

        $this->DisableAction('Reachable');
        $this->DisableAction('LastUpdate');
        $this->DisableAction('LastError');
        $this->DisableAction('RawDps');
    }

    private function variableExists(string $ident): bool
    {
        $vid = @IPS_GetVariableIDByName($ident, $this->InstanceID);

        return is_int($vid) && $vid > 0;
    }

    private function ensureModuleVersionVariable(): void
    {
        if ($this->variableExists('ModuleVersion')) {
            return;
        }

        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '~TextBox', 999);
        $this->DisableAction('ModuleVersion');
    }

    private function syncModuleVersionVariable(): void
    {
        if (!$this->variableExists('ModuleVersion')) {
            return;
        }

        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
    }

    private function configureTimer(): void
    {
        $interval = max(
            self::UPDATE_INTERVAL_MIN_SEC,
            (int) $this->ReadPropertyInteger('UpdateIntervalSeconds')
        );

        if ($this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Update', $interval * 1000);
        } else {
            $this->SetTimerInterval('Update', 0);
        }
    }

    private function updateInstanceStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::IS_INACTIVE);

            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        $localKey = trim($this->ReadPropertyString('LocalKey'));

        if ($host === '' || $deviceId === '' || $localKey === '') {
            $this->SetStatus(self::IS_INVALID_CONFIG);

            return;
        }

        $this->SetStatus(self::IS_ACTIVE);
    }

    private function buildSummary(): string
    {
        $host = trim($this->ReadPropertyString('Host'));

        return $host !== '' ? $host : 'Yieryi Sensor';
    }
}
