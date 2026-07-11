<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/TuyaLocalClient.php';
require_once __DIR__ . '/libs/TuyaWaterQualityMapping.php';
require_once __DIR__ . '/libs/TuyaCloudSharing.php';
require_once __DIR__ . '/libs/TuyaUdpDiscovery.php';
require_once __DIR__ . '/libs/TuyaQrImage.php';

class TuyaWaterQuality extends IPSModuleStrict
{
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 20;

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

    private const DATA_SOURCE_LAN = 'lan';
    private const DATA_SOURCE_CLOUD = 'cloud';
    private const DATA_SOURCE_LAN_THEN_CLOUD = 'lan_then_cloud';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('DataSource', self::DATA_SOURCE_LAN_THEN_CLOUD);
        $this->RegisterPropertyBoolean('KeepCloudSession', true);
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
            if (($element['name'] ?? '') === 'LocalKey') {
                $form['elements'][$idx]['value'] = $this->ReadPropertyString('LocalKey');
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
        $dataSource = $this->normalizeDataSource($this->ReadPropertyString('DataSource'));
        $source = trim((string) $this->GetValue('LastDataSource'));
        $reachable = (bool) $this->GetValue('Reachable');
        $error = trim((string) $this->GetValue('LastError'));

        if (!$reachable) {
            return "Aktualisierung fehlgeschlagen\n"
                . 'Datenquelle: ' . $this->dataSourceLabel($dataSource) . "\n"
                . 'Host: ' . ($host !== '' ? $host : '(leer)') . "\n"
                . 'Device ID: ' . ($deviceId !== '' ? $deviceId : '(leer)') . "\n"
                . 'Protokoll: ' . ($proto !== '' ? $proto : '3.3') . "\n"
                . 'Fehler: ' . ($error !== '' ? $error : 'unbekannt') . "\n\n"
                . "Tipps:\n"
                . "- Bei LAN-Problemen: Datenquelle „LAN, sonst Cloud“ oder „Nur Cloud“\n"
                . "- Cloud-Session: QR-Login erneut, KeepCloudSession aktiv lassen\n"
                . "- Host per „LAN-Scan (IP)“ prüfen oder tinytuya-Test vom Symcon-Server\n"
                . "- Details: Instanz → Zahnrad → Debug aktivieren → Meldungen";
        }

        $lines = [
            'Aktualisierung OK',
            'Daten via ' . ($source !== '' ? $source : 'LAN'),
            'Host: ' . ($host !== '' ? $host : '(Cloud)'),
            'Erreichbar: ' . ($reachable ? 'ja' : 'nein'),
        ];

        if ($source === 'Cloud' && $dataSource === self::DATA_SOURCE_LAN_THEN_CLOUD) {
            $lines[1] = 'Daten via Cloud (LAN nicht erreichbar)';
        }

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
        $cloudReportedHost = $host;
        if ($host !== '' && !$this->isPrivateLanHost($host)) {
            $host = '';
        }
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

        if (!$this->ReadPropertyBoolean('KeepCloudSession')) {
            $this->CloudLogout();
        } else {
            $this->setCloudStatus('Gerät übernommen — Cloud-Session bleibt für Fallback aktiv');
        }

        $this->UpdateValues();

        $hostHint = $host !== '' ? $host : '(IP fehlt — LAN-Scan klicken)';
        if ($cloudReportedHost !== '' && $host === '') {
            $hostHint = 'Cloud-IP ' . $cloudReportedHost . ' ist keine LAN-Adresse — bitte 172.18.x.x manuell eintragen';
        }
        $reachable = $this->GetValue('Reachable') ? 'ja' : 'nein';
        $lastError = trim((string) $this->GetValue('LastError'));

        $message = sprintf(
            "Gerät gespeichert: %s\nDevice ID: %s\nLocal Key: %s\nHost: %s\nErreichbar: %s",
            $name,
            $deviceId,
            $localKey,
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

        $hintIp = trim($this->ReadPropertyString('Host'));
        $found = TuyaUdpDiscovery::scan(5, $hintIp !== '' ? $hintIp : null);

        if ($found === []) {
            return $this->buildLanDiscoverFallbackMessage($hintIp, $targetDeviceId);
        }

        $lines = [];
        $matchedIp = '';
        $matchedVersion = '';
        foreach ($found as $entry) {
            $line = sprintf('%s — %s (v%s)', $entry['ip'], $entry['id'], $entry['version']);
            $lines[] = $line;
            if ($targetDeviceId !== '' && $entry['id'] === $targetDeviceId) {
                $matchedIp = $entry['ip'];
                $matchedVersion = (string) $entry['version'];
            }
        }

        $applied = [];
        if ($matchedIp !== '' && $this->setInstanceProperty('Host', $matchedIp)) {
            $applied[] = 'Host=' . $matchedIp;
        }
        $normalizedVersion = $this->normalizeDiscoveredProtocol($matchedVersion);
        if ($normalizedVersion !== null && $this->setInstanceProperty('ProtocolVersion', $normalizedVersion)) {
            $applied[] = 'Protokoll=' . $normalizedVersion;
        }
        if ($applied !== []) {
            $this->persistInstanceConfiguration();
            array_unshift($lines, 'Übernommen: ' . implode(', ', $applied));
        }

        return "Gefundene Geräte:\n" . implode("\n", $lines);
    }

    private function buildLanDiscoverFallbackMessage(string $hintIp, string $targetDeviceId): string
    {
        $lines = [
            'Kein Tuya-Gerät per UDP-Broadcast gefunden.',
            '',
            'Ping zum Gerät kann trotzdem funktionieren — UDP-Scan und Ping sind verschiedene Protokolle.',
        ];

        if ($hintIp !== '') {
            $tcpOpen = TuyaUdpDiscovery::tcpPortOpen($hintIp, 6668, 3);
            $lines[] = '';
            $lines[] = 'Konfigurierte IP ' . $hintIp . ':';
            $lines[] = $tcpOpen
                ? 'TCP Port 6668 (Tuya LAN): erreichbar → IP sehr wahrscheinlich korrekt'
                : 'TCP Port 6668 (Tuya LAN): nicht erreichbar';
        }

        $lines[] = '';
        $lines[] = 'Typische Gründe für fehlenden UDP-Scan:';
        $lines[] = '- IP-Symcon und Sensor in verschiedenen VLANs/Subnetzen';
        $lines[] = '- Firewall blockiert UDP 6666, 6667, 7000';
        $lines[] = '- Gerät sendet nur auf Anfrage (Port 7000) — erneut versuchen';
        $lines[] = '- Keine Tuya-Antwort auf Port 6668 trotz offenem TCP → ggf. Datenquelle „LAN, sonst Cloud“ wählen';
        $lines[] = '';
        $lines[] = 'Hinweis: Bei YINMIK/szjcy-Sensoren fehlen oft App-Einstellungen für LAN — das ist normal.';
        $lines[] = '';
        $lines[] = '→ Host manuell eintragen (z. B. ' . ($hintIp !== '' ? $hintIp : '172.18.x.x') . '), Protokoll 3.3 wählen';
        if ($targetDeviceId !== '') {
            $lines[] = '→ Device ID: ' . $targetDeviceId;
        }
        $lines[] = '→ Alternativ: python -m tinytuya scan (vom gleichen Netz wie der Sensor)';

        return implode("\n", $lines);
    }

    public function UpdateValues(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Reachable', false);
            $this->SetValue('LastError', 'Modul inaktiv');
            $this->SetValue('LastDataSource', '');

            return;
        }

        $dataSource = $this->normalizeDataSource($this->ReadPropertyString('DataSource'));
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        $mapping = TuyaWaterQualityMapping::parse($this->ReadPropertyString('DpMapping'));

        if ($deviceId === '') {
            $this->setUpdateFailure('Device ID fehlt', self::IS_INVALID_CONFIG);

            return;
        }

        $lanResult = null;
        if ($dataSource === self::DATA_SOURCE_LAN || $dataSource === self::DATA_SOURCE_LAN_THEN_CLOUD) {
            $lanResult = $this->fetchLanStatus($deviceId, $mapping);
            if ($lanResult['ok']) {
                $this->applyMeasurementResult($lanResult['dps'], $mapping, 'LAN', true);

                return;
            }
        }

        if ($dataSource === self::DATA_SOURCE_CLOUD || $dataSource === self::DATA_SOURCE_LAN_THEN_CLOUD) {
            if ($lanResult !== null && !($lanResult['ok'] ?? false)) {
                $this->debugLocal('Update', 'LAN fehlgeschlagen — starte Cloud-Fallback');
            }

            $cloudResult = $this->fetchCloudStatus($deviceId);
            if ($cloudResult['ok']) {
                $this->applyMeasurementResult($cloudResult['dps'], $mapping, 'Cloud', true);

                return;
            }

            if ($dataSource === self::DATA_SOURCE_CLOUD) {
                $this->setUpdateFailure($cloudResult['error'], self::IS_UNREACHABLE);

                return;
            }

            $lanError = is_array($lanResult) ? trim((string) ($lanResult['error'] ?? '')) : 'LAN nicht versucht';
            $cloudError = trim((string) ($cloudResult['error'] ?? ''));
            $this->setUpdateFailure(
                'LAN: ' . ($lanError !== '' ? $lanError : 'fehlgeschlagen')
                . ' | Cloud: ' . ($cloudError !== '' ? $cloudError : 'fehlgeschlagen'),
                self::IS_UNREACHABLE,
            );

            return;
        }

        $this->setUpdateFailure(
            is_array($lanResult) ? (string) ($lanResult['error'] ?? 'LAN-Abfrage fehlgeschlagen') : 'LAN-Abfrage fehlgeschlagen',
            self::IS_UNREACHABLE,
        );
    }

    /**
     * @param array<string, array{dp: int, scale: float}> $mapping
     * @return array{ok: bool, dps: array<string|int, mixed>, error: string}
     */
    private function fetchLanStatus(string $deviceId, array $mapping): array
    {
        $host = trim($this->ReadPropertyString('Host'));
        $localKey = trim($this->ReadPropertyString('LocalKey'));

        if ($host === '' || $localKey === '') {
            return ['ok' => false, 'dps' => [], 'error' => 'Host oder Local Key fehlt'];
        }

        if (!$this->isPrivateLanHost($host)) {
            return [
                'ok' => false,
                'dps' => [],
                'error' => 'Host ' . $host . ' ist keine lokale IP (Tuya-Cloud-WAN) — LAN übersprungen',
            ];
        }

        $this->debugLocal('Update', sprintf(
            'LAN-Abfrage host=%s devId=%s proto=%s keyLen=%d',
            $host,
            $deviceId,
            $this->ReadPropertyString('ProtocolVersion'),
            strlen($localKey),
        ));

        $dpKeys = $this->extractDpQueryKeys($mapping);

        $client = new TuyaLocalClient(
            $deviceId,
            $localKey,
            $this->ReadPropertyString('ProtocolVersion'),
            function (string $message): void {
                $this->debugLocal('LAN', $message);
            },
            $dpKeys,
            null
        );

        return $client->fetchStatus($host, true);
    }

    /**
     * @return array{ok: bool, dps: array<string|int, mixed>, online: bool, error: string}
     */
    private function fetchCloudStatus(string $deviceId): array
    {
        $session = $this->loadCloudSession();
        if ($session === null) {
            return [
                'ok' => false,
                'dps' => [],
                'online' => false,
                'error' => 'Keine Cloud-Session — QR-Login erneut durchführen (KeepCloudSession aktiv lassen)',
            ];
        }

        $this->debugLocal('Update', 'Cloud-Abfrage devId=' . $deviceId);

        $sharing = new TuyaCloudSharing();
        $result = $sharing->fetchDeviceStatus($session, $deviceId);
        $this->saveCloudSession($session);

        if (!$result['ok']) {
            $this->debugLocal('Update', 'Cloud detail fehlgeschlagen: ' . $result['error']);
            $cached = $this->fetchCloudStatusFromCache($deviceId);
            if ($cached['ok']) {
                $this->debugLocal('Update', 'Cloud-Fallback aus Geräteliste OK');

                return $cached;
            }
        }

        return $result;
    }

    /**
     * @return array{ok: bool, dps: array<string|int, mixed>, online: bool, error: string}
     */
    private function fetchCloudStatusFromCache(string $deviceId): array
    {
        foreach ($this->loadCloudDevices() as $device) {
            if (($device['id'] ?? '') !== $deviceId) {
                continue;
            }

            $dps = TuyaCloudSharing::statusListToDps($device['status'] ?? []);
            if ($dps === []) {
                break;
            }

            return [
                'ok' => true,
                'dps' => $dps,
                'online' => (bool) ($device['online'] ?? false),
                'error' => '',
            ];
        }

        return ['ok' => false, 'dps' => [], 'online' => false, 'error' => 'Kein Status in Cloud-Geräteliste'];
    }

    private function isPrivateLanHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * @param array<string|int, mixed> $dps
     * @param array<string, array{dp: int, scale: float}> $mapping
     */
    private function applyMeasurementResult(array $dps, array $mapping, string $sourceLabel, bool $reachable): void
    {
        $values = TuyaWaterQualityMapping::apply($dps, $mapping);
        $this->debugLocal('Update', sprintf(
            'Mapping (%s) angewendet: %s',
            $sourceLabel,
            json_encode($values, JSON_UNESCAPED_UNICODE),
        ));

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

        $this->SetValue('Reachable', $reachable);
        $this->SetValue('LastDataSource', $sourceLabel);
        $this->SetValue('LastError', '');
        $this->SetValue('LastUpdate', time());
        $this->SetValue('RawDps', json_encode($dps, JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->SetStatus(self::IS_ACTIVE);
        $this->debugLocal('Update', sprintf('Erfolg via %s, DPS=%s', $sourceLabel, json_encode($dps, JSON_UNESCAPED_UNICODE) ?: '{}'));
    }

    private function setUpdateFailure(string $error, int $status): void
    {
        $this->SetValue('Reachable', false);
        $this->SetValue('LastDataSource', '');
        $this->SetValue('LastError', $error);
        $this->SetStatus($status);
        $this->debugLocal('Update', 'Fehler: ' . $error);
    }

    private function normalizeDataSource(string $source): string
    {
        $source = strtolower(trim($source));
        if (in_array($source, [self::DATA_SOURCE_LAN, self::DATA_SOURCE_CLOUD, self::DATA_SOURCE_LAN_THEN_CLOUD], true)) {
            return $source;
        }

        return self::DATA_SOURCE_LAN_THEN_CLOUD;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function saveCloudSession(array $session): void
    {
        $encoded = json_encode($session, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $this->SetBuffer(self::BUF_SESSION, $encoded);
        }
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
        if ($host !== '' && $this->isPrivateLanHost($host)) {
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
     * @return list<array{id: string, ip: string, version: string}>
     */
    private function udpDiscoverDevices(int $timeoutSec, ?string $hintIp = null): array
    {
        $hint = trim((string) $hintIp);
        if ($hint === '') {
            $hint = trim($this->ReadPropertyString('Host'));
        }

        return TuyaUdpDiscovery::scan($timeoutSec, $hint !== '' ? $hint : null);
    }

    private function discoverProtocolForDevice(string $deviceId): ?string
    {
        if ($deviceId === '') {
            return null;
        }

        foreach ($this->udpDiscoverDevices(3, $deviceId !== '' ? trim($this->ReadPropertyString('Host')) : null) as $entry) {
            if (($entry['id'] ?? '') === $deviceId) {
                $version = $this->normalizeDiscoveredProtocol((string) ($entry['version'] ?? ''));

                return $version;
            }
        }

        return null;
    }

    private function normalizeDiscoveredProtocol(string $version): ?string
    {
        $version = trim($version);
        if ($version === '' || $version === '?') {
            return null;
        }

        if (preg_match('/(3\.[2345])/', $version, $matches)) {
            return $matches[1];
        }

        return null;
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
        $this->RegisterVariableString('LastDataSource', 'Datenquelle (letzte Abfrage)', $textPres, $pos++);
        $this->RegisterVariableString('RawDps', 'Roh-DPS (Debug)', $textPres, $pos++);

        $this->DisableAction('Reachable');
        $this->DisableAction('LastUpdate');
        $this->DisableAction('LastError');
        $this->DisableAction('LastDataSource');
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

        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            $this->SetStatus(self::IS_INVALID_CONFIG);

            return;
        }

        $dataSource = $this->normalizeDataSource($this->ReadPropertyString('DataSource'));
        if ($dataSource === self::DATA_SOURCE_CLOUD) {
            if ($this->loadCloudSession() === null) {
                $this->SetStatus(self::IS_INVALID_CONFIG);

                return;
            }

            $this->SetStatus(self::IS_ACTIVE);

            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        $localKey = trim($this->ReadPropertyString('LocalKey'));

        if ($host === '' || $localKey === '') {
            $this->SetStatus(self::IS_INVALID_CONFIG);

            return;
        }

        $this->SetStatus(self::IS_ACTIVE);
    }

    private function dataSourceLabel(string $dataSource): string
    {
        return match ($dataSource) {
            self::DATA_SOURCE_LAN => 'Nur LAN',
            self::DATA_SOURCE_CLOUD => 'Nur Cloud',
            default => 'LAN, sonst Cloud',
        };
    }

    private function buildSummary(): string
    {
        $host = trim($this->ReadPropertyString('Host'));

        return $host !== '' ? $host : 'Yieryi Sensor';
    }
}
