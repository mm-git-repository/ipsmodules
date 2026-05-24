<?php

declare(strict_types=1);

class PIXOOEnergyViewer extends IPSModuleStrict
{
    private const LIBRARY_ID = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';
    /** SemVer — bei funktionalen Änderungen anheben; parallel library.json pflegen */
    private const MODULE_VERSION = '1.1';
    /** Build-Zähler — bei jedem Deploy +1; parallel library.json pflegen */
    private const MODULE_BUILD = 19;

    private const PIXEL_SIZE = 64;
    private const LEFT_PAD = 2;
    private const FONT_LABEL = 26;
    private const FONT_VALUE = 2;
    /**
     * Divoom ItemList: Font 0 / sehr kleine Höhen werden oft gar nicht gezeichnet.
     * Font 26 = gleiche kleine Label-Schrift wie „VERBRAUCH“ (standalone/sma_pixoo_display.py).
     */
    /** Textfeld ab X; SMARD-Eck mit align=3 und voller Breite rechtsbündig */
    private const DATETIME_X = 0;

    /** SMARD-Zeitstempel auf dem Pixoo: PHP date()-Format (fest, kein Formularfeld mehr) */
    private const SMARD_TIME_PHP_FORMAT = 'H:i';

    /** 1 = links, 2 = mitte, 3 = rechts — fest für SMARD-Eck */
    private const SMARD_CORNER_TEXT_ALIGN = 3;

    /** Zeile des Labels „NETZ“ (und optional SMARD-Uhrzeit rechts in derselben Zeile) */
    private const NETZ_LABEL_Y = 46;

    /** |Netz| unter diesem Wert (W) gilt auf dem Pixoo als „0“ → gelb */
    private const NET_ZERO_EPSILON_W = 0.5;

    private const ONE_HOUR_MS = 3600000;
    private const SMARD_FETCH_MS = 900000;

    /** Kurz-Debounce: identischer Pixoo-Inhalt nicht zweimal innerhalb 1 s (z. B. SMARD direkt nach Update-Timer) */
    private const PIXOO_SYNC_DEBOUNCE_SEC = 1.0;

    /**
     * Prüft, ob die Instanz noch lebt (Werte-Timer, Puffer). Symcon-Modul-Timer laufen im selben Worker —
     * blockiert ein HTTP-Aufruf den Worker, feuern keine anderen Timer mehr (wirkt nach ~24 h wie „Totstellung“).
     */
    private const HEALTH_WATCHDOG_MS = 180000;

    /** HTTP-Timeout Pixoo (Sekunden); zu hoch → Timer-Zyklen stapeln sich bei Ausfall */
    private const PIXOO_HTTP_TIMEOUT_SEC = 4.0;

    /** TCP-Verbindungsaufbau Pixoo (Sekunden); verhindert endloses Hängen bei „schwarzem Loch“-Sockets */
    private const PIXOO_HTTP_CONNECT_TIMEOUT_SEC = 2.0;

    /** HTTP-Timeout SMARD (Sekunden); mehrere Requests hintereinander → Gesamtblockade begrenzen */
    private const SMARD_HTTP_TIMEOUT_SEC = 8.0;

    /** TCP-Verbindungsaufbau SMARD (Sekunden) */
    private const SMARD_HTTP_CONNECT_TIMEOUT_SEC = 4.0;

    /** Max. SMARD-Chunk-GETs pro Lauf (jeder blockiert den Modul-Worker); zu wenig → kein Preis in der Ecke */
    private const SMARD_MAX_CHUNK_REQUESTS = 5;

    /** Nach so vielen Pixoo-Fehlern schweres Init (GIF) pausieren; leichter PixooSync läuft weiter */
    private const PIXOO_MAX_CONSECUTIVE_FAILS = 3;
    private const PIXOO_HEAVY_HTTP_COOLDOWN_SEC = 300;
    /** Kurze Pause nur für schwere Init-Pfade nach Fehlserie */
    private const PIXOO_LIGHT_HTTP_COOLDOWN_SEC = 60;

    /** cURL: Transfer abbrechen, wenn zu lange keine Bytes ankommen (hängende Leseposition trotz CONNECT) */
    private const HTTP_LOW_SPEED_BYTES_PER_SEC = 1;
    private const HTTP_LOW_SPEED_TIME_SEC = 5;

    /** SMARD Marktpreis Day-Ahead DE/LU, EUR/MWh → Anzeige als Zahl in € (= Wert / 1000) */
    private const SMARD_FILTER_ID = 4169;
    private const SMARD_REGION = 'DE';
    private const SMARD_TIME_TEXT_ID = 18;
    private const SMARD_TEXT_ID = 20;

    /** Pro Wechselrichter (Summe seiner Strings): Leistung strikt darüber → Anteil dieses WR = 0 (Messfehler) */
    private const GENERATION_INVALID_ABOVE_W = 12000.0;

    /** IPS_VARIABLEMESSAGE + VM_UPDATE — Variable geändert (Symcon Messages-Doku) */
    private const VM_UPDATE_MESSAGE = 10603;

    /** Mindest-Timerintervall Werte + Pixoo (Sekunden) */
    private const UPDATE_INTERVAL_MIN_SEC = 5;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('PixooIp', '172.18.1.167');
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', self::UPDATE_INTERVAL_MIN_SEC);
        $this->RegisterPropertyInteger('DefaultBrightness', 80);
        $this->RegisterPropertyBoolean('PixooNightBrightnessUse', false);
        $this->RegisterPropertyInteger('PixooNightBrightness', 25);
        $this->RegisterPropertyInteger('PixooNightHourFrom', 22);
        $this->RegisterPropertyInteger('PixooNightHourTo', 6);
        $this->RegisterPropertyBoolean('PixooHourlyReinit', true);

        $this->RegisterPropertyInteger('HmRealPowerPlusVar', 0);
        $this->RegisterPropertyInteger('HmRealPowerMinusVar', 0);

        $this->RegisterPropertyInteger('Wr1String1Var', 0);
        $this->RegisterPropertyInteger('Wr1String2Var', 0);
        $this->RegisterPropertyInteger('Wr2String1Var', 0);
        $this->RegisterPropertyInteger('Wr2String2Var', 0);

        $this->RegisterPropertyBoolean('PixooShowSmardPrice', true);
        $this->RegisterPropertyBoolean('PixooSmardShowUnit', true);
        $this->RegisterPropertyBoolean('PixooSmardShowTime', false);

        if (!IPS_VariableProfileExists('SMAPX.Watt')) {
            IPS_CreateVariableProfile('SMAPX.Watt', 2);
            IPS_SetVariableProfileText('SMAPX.Watt', '', ' W');
        }
        if (!IPS_VariableProfileExists('SMAPX.EurKWh')) {
            IPS_CreateVariableProfile('SMAPX.EurKWh', 2);
            IPS_SetVariableProfileText('SMAPX.EurKWh', '', ' €');
        }
        if (!IPS_VariableProfileExists('SMAPX.Text')) {
            IPS_CreateVariableProfile('SMAPX.Text', 3);
        }

        $usePresArray = false;
        if (function_exists('IPS_GetKernelVersion')) {
            $kv = IPS_GetKernelVersion();
            $usePresArray = is_string($kv) && $kv !== '' && version_compare($kv, '8.0', '>=');
        }
        if ($usePresArray) {
            $wattPres = ['PROFILE' => 'SMAPX.Watt'];
            $eurPres = ['PROFILE' => 'SMAPX.EurKWh'];
            $textPres = ['PROFILE' => 'SMAPX.Text'];
        } else {
            $wattPres = 'SMAPX.Watt';
            $eurPres = 'SMAPX.EurKWh';
            $textPres = 'SMAPX.Text';
        }
        $this->RegisterVariableFloat('Consumption', 'Verbrauch', $wattPres, 0);
        $this->RegisterVariableFloat('Generation', 'Erzeugung', $wattPres, 1);
        $this->RegisterVariableFloat('Net', 'Netz', $wattPres, 2);
        $this->RegisterVariableFloat('SmardSpotCt', 'SMARD Spot (€)', $eurPres, 3);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', $textPres, 4);

        // SMAPX_*: Symcon erzeugt globale Funktionen aus public-Methoden (scripts/__generated.inc.php).
        $this->RegisterTimer('Update', 0, 'SMAPX_UpdateValues($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PixooSync', 0, 'SMAPX_SyncPixoo($_IPS[\'TARGET\']);');
        $this->RegisterTimer('HourlyReinit', 0, 'SMAPX_ReinitDisplay($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SmardFetch', 0, 'SMAPX_UpdateSmardPrice($_IPS[\'TARGET\']);');
        $this->RegisterTimer('HealthWatchdog', 0, 'SMAPX_HealthWatchdog($_IPS[\'TARGET\']);');
    }

    /** @return list<string> */
    private static function obsoleteConfigurationKeys(): array
    {
        return [
            'PixooShowDateTime',
            'PixooDateTimeTwoLines',
            'PixooDateTimeFormatDate',
            'PixooDateTimeFormatTime',
            'PixooDateTimeFormatCombined',
            'PixooDateTimeFont',
            'PixooDateTimeTextHeight',
            'PixooDateTimeAlign',
        ];
    }

    /**
     * Alte Instanzen: fehlende Keys in der persistierten Konfiguration → IPSModuleStrict:
     * ReadProperty* wirft „Property not found“, Instanz / Konfigurationsformular lädt nicht.
     * Alle RegisterProperty-*-Defaults aus Create() hier spiegeln (nur ergänzen, nie überschreiben).
     *
     * @return array<string, bool|int|string>
     */
    private function migrateDefaultConfiguration(): array
    {
        return [
            'Active' => true,
            'PixooIp' => '172.18.1.167',
            'UpdateIntervalSeconds' => self::UPDATE_INTERVAL_MIN_SEC,
            'DefaultBrightness' => 80,
            'PixooNightBrightnessUse' => false,
            'PixooNightBrightness' => 25,
            'PixooNightHourFrom' => 22,
            'PixooNightHourTo' => 6,
            'PixooHourlyReinit' => true,
            'HmRealPowerPlusVar' => 0,
            'HmRealPowerMinusVar' => 0,
            'Wr1String1Var' => 0,
            'Wr1String2Var' => 0,
            'Wr2String1Var' => 0,
            'Wr2String2Var' => 0,
            'PixooShowSmardPrice' => true,
            'PixooSmardShowUnit' => true,
            'PixooSmardShowTime' => false,
        ];
    }

    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        $data = json_decode($JSONData, true);
        if (!is_array($data)) {
            return $JSONData;
        }
        if (!isset($data['configuration']) || !is_array($data['configuration'])) {
            $data['configuration'] = [];
        }
        foreach ($this->migrateDefaultConfiguration() as $key => $default) {
            if (!array_key_exists($key, $data['configuration'])) {
                $data['configuration'][$key] = $default;
                continue;
            }
            $v = $data['configuration'][$key];
            if ($v === null) {
                $data['configuration'][$key] = $default;
                continue;
            }
            if (is_bool($default)) {
                $data['configuration'][$key] = self::migrateCoerceBool($v);
            } elseif (is_int($default)) {
                $data['configuration'][$key] = self::migrateCoerceInt($v);
            } else {
                $data['configuration'][$key] = is_string($v) ? $v : (string) $v;
            }
        }
        if (array_key_exists('UpdateIntervalSeconds', $data['configuration'])) {
            $u = self::migrateCoerceInt($data['configuration']['UpdateIntervalSeconds']);
            $data['configuration']['UpdateIntervalSeconds'] = max(self::UPDATE_INTERVAL_MIN_SEC, $u);
        }
        foreach (self::obsoleteConfigurationKeys() as $k) {
            unset($data['configuration'][$k]);
        }

        $out = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($out === false || $out === '') {
            return $JSONData;
        }

        return $out;
    }

    /** @param mixed $v */
    private static function migrateCoerceBool($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_float($v)) {
            return ((int) $v) !== 0;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));

            return in_array($s, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /** @param mixed $v */
    private static function migrateCoerceInt($v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) round($v);
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (int) round((float) $v);
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }

        return 0;
    }

    public function ApplyChanges(): void
    {
        $creating = (IPS_GetInstance($this->InstanceID)['InstanceStatus'] ?? 0) === 100;
        parent::ApplyChanges();
        if (!$creating) {
            $this->ensureTimerDefinitions();
        }
        $this->ensureModuleVersionVariable();
        $this->applyModuleVersionInfo();

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->stopAllTimers();
            $this->clearMessageSubscriptions();
            return;
        }

        $configIssues = $this->collectConfigIssues();
        if ($configIssues !== []) {
            $this->SetStatus(201);
            $this->stopAllTimers();
            $this->clearMessageSubscriptions();
            foreach ($configIssues as $line) {
                $this->SendDebug('Konfiguration', $line, 0);
            }
            return;
        }

        $this->SetStatus(102);
        $this->SetBuffer('PixooInited', '0');
        $this->startActiveTimers();
        $this->syncMessageSubscriptions();
    }

    public function GetConfigurationForm(): string
    {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'form.json';
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return parent::GetConfigurationForm();
        }
        $form = json_decode($raw, true);
        if (!is_array($form)) {
            return parent::GetConfigurationForm();
        }
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }
        array_unshift($form['elements'], [
            'type' => 'Label',
            'caption' => 'Modulversion: ' . $this->formatModuleVersionLabel(),
        ]);
        $out = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($out === false || $out === '') {
            return parent::GetConfigurationForm();
        }
        return $out;
    }

    private function applyModuleVersionInfo(): void
    {
        $label = $this->formatModuleVersionLabel();
        $prevLabel = $this->GetBuffer('LastAppliedModuleVersionLabel');
        $this->SetValue('ModuleVersion', $label);
        $this->lockModuleVersionVariable();
        $build = $this->resolveModuleBuild();
        $this->SetBuffer('LastAppliedModuleBuild', (string) $build);
        if ($prevLabel !== $label) {
            $this->SetBuffer('LastAppliedModuleVersionLabel', $label);
            $this->SendDebug('Modul', 'Version ' . $label . ' angewendet', 0);
            $this->ReloadForm();
        }
    }

    private function formatModuleVersionLabel(): string
    {
        $version = self::MODULE_VERSION;
        $build = self::MODULE_BUILD;
        if (IPS_LibraryExists(self::LIBRARY_ID)) {
            $lib = IPS_GetLibrary(self::LIBRARY_ID);
            if (isset($lib['Build']) && is_numeric($lib['Build'])) {
                $build = max($build, (int) $lib['Build']);
            }
            if (isset($lib['Version'])) {
                if (is_string($lib['Version']) && $lib['Version'] !== '') {
                    $version = $lib['Version'];
                } elseif (is_int($lib['Version'])) {
                    $major = ($lib['Version'] >> 8) & 0xFF;
                    $minor = $lib['Version'] & 0xFF;
                    $version = $major . '.' . $minor;
                }
            }
        }
        return $version . ' (Build ' . $build . ')';
    }

    private function resolveModuleBuild(): int
    {
        $build = self::MODULE_BUILD;
        if (IPS_LibraryExists(self::LIBRARY_ID)) {
            $lib = IPS_GetLibrary(self::LIBRARY_ID);
            if (isset($lib['Build']) && is_numeric($lib['Build'])) {
                $build = max($build, (int) $lib['Build']);
            }
        }
        return $build;
    }

    /** Bestehende Instanzen: Variable nachziehen, wenn Modul aktualisiert wurde. */
    private function ensureModuleVersionVariable(): void
    {
        $vid = @IPS_GetVariableIDByName('ModuleVersion', $this->InstanceID);
        if (is_int($vid) && $vid > 0) {
            return;
        }
        if (!IPS_VariableProfileExists('SMAPX.Text')) {
            IPS_CreateVariableProfile('SMAPX.Text', 3);
        }
        $usePresArray = false;
        if (function_exists('IPS_GetKernelVersion')) {
            $kv = IPS_GetKernelVersion();
            $usePresArray = is_string($kv) && $kv !== '' && version_compare($kv, '8.0', '>=');
        }
        $textPres = $usePresArray ? ['PROFILE' => 'SMAPX.Text'] : 'SMAPX.Text';
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', $textPres, 4);
    }

    /** Modulversion nur anzeigen, nicht manuell editierbar. */
    private function lockModuleVersionVariable(): void
    {
        $vid = @IPS_GetVariableIDByName('ModuleVersion', $this->InstanceID);
        if (!is_int($vid) || $vid <= 0) {
            return;
        }
        if (function_exists('IPS_SetVariableAction')) {
            IPS_SetVariableAction($vid, false);
        }
    }

    /** Bestehende Instanzen: fehlende Timer nach Modul-Update registrieren (Create() legt sie bei Neuanlage an). */
    private function ensureTimerDefinitions(): void
    {
        $timers = [
            'Update' => 'SMAPX_UpdateValues($_IPS[\'TARGET\']);',
            'PixooSync' => 'SMAPX_SyncPixoo($_IPS[\'TARGET\']);',
            'HourlyReinit' => 'SMAPX_ReinitDisplay($_IPS[\'TARGET\']);',
            'SmardFetch' => 'SMAPX_UpdateSmardPrice($_IPS[\'TARGET\']);',
            'HealthWatchdog' => 'SMAPX_HealthWatchdog($_IPS[\'TARGET\']);',
        ];
        foreach ($timers as $name => $script) {
            $this->registerTimerIfMissing($name, $script);
        }
    }

    /** RegisterTimer nur wenn der Ident noch nicht existiert (kein „Timer already exists“). */
    private function registerTimerIfMissing(string $ident, string $script): void
    {
        if ($this->timerIdentExists($ident)) {
            return;
        }
        $this->RegisterTimer($ident, 0, $script);
    }

    private function timerIdentExists(string $ident): bool
    {
        $missing = false;
        set_error_handler(static function (int $errno, string $errstr) use (&$missing): bool {
            unset($errno);
            if (stripos($errstr, 'not registered') !== false) {
                $missing = true;

                return true;
            }

            return false;
        });
        try {
            $this->GetTimerInterval($ident);
        } catch (\Throwable $e) {
            $missing = true;
        }
        restore_error_handler();

        return !$missing;
    }

    private function stopAllTimers(): void
    {
        $this->SetTimerInterval('Update', 0);
        $this->SetTimerInterval('PixooSync', 0);
        $this->SetTimerInterval('HourlyReinit', 0);
        $this->SetTimerInterval('SmardFetch', 0);
        $this->SetTimerInterval('HealthWatchdog', 0);
    }

    /** Timer-Intervalle gemäß Konfiguration setzen (auch vom Watchdog bei Ausfall). */
    private function startActiveTimers(): void
    {
        $sec = $this->getUpdateIntervalSec();
        $this->SetTimerInterval('Update', $sec * 1000);
        /* Pixoo läuft im selben Zyklus wie UpdateValues() — kein zweiter Timer (Worker-Stau) */
        $this->SetTimerInterval('PixooSync', 0);
        $pixooIp = trim($this->ReadPropertyString('PixooIp'));
        $this->SendDebug(
            'Timer',
            'Aktualisierung alle ' . $sec . ' s (Werte'
            . ($pixooIp !== '' ? ' + Pixoo im Update-Timer' : ', Pixoo aus')
            . ', UpdateIntervalSeconds=' . $this->ReadPropertyInteger('UpdateIntervalSeconds') . ')',
            0
        );
        if ($this->ReadPropertyBoolean('PixooHourlyReinit')) {
            $this->SetTimerInterval('HourlyReinit', self::ONE_HOUR_MS);
        } else {
            $this->SetTimerInterval('HourlyReinit', 0);
        }
        if ($this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            $this->SetTimerInterval('SmardFetch', self::SMARD_FETCH_MS);
        } else {
            $this->SetTimerInterval('SmardFetch', 0);
        }
        $this->SetTimerInterval('HealthWatchdog', self::HEALTH_WATCHDOG_MS);
    }

    /**
     * Erkennt „eingefrorene“ Instanzen: keine Werteaktualisierung oder Update-Timer aus.
     * Setzt Timer neu und erzwingt einen Refresh-Zyklus (ohne Instanz-Neustart).
     */
    public function HealthWatchdog(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        if ($this->collectConfigIssues() !== []) {
            return;
        }

        $intervalSec = $this->getUpdateIntervalSec();
        $staleSec = max(120, $intervalSec * 4);
        $lastRaw = $this->GetBuffer('LastValuesAt');
        $last = is_numeric($lastRaw) ? (int) $lastRaw : 0;
        $now = time();
        if ($last <= 0) {
            $valuesStale = true;
        } elseif ($last > $now) {
            /* z. B. Uhrenkorrektur / Winterzeit: „LastValuesAt“ liegt in der Zukunft — nicht als frisch werten */
            $valuesStale = true;
        } else {
            $valuesStale = ($now - $last) > $staleSec;
        }
        $updateMs = $this->GetTimerInterval('Update');
        $timerOff = $updateMs <= 0;

        if (!$valuesStale && !$timerOff) {
            return;
        }

        $reason = $timerOff
            ? 'Update-Timer aus (Intervall 0)'
            : ('keine Werte seit ' . ($last > 0 ? (string) ($now - $last) : '?') . ' s (Schwelle ' . $staleSec . ' s)');
        $this->SendDebug('Watchdog', 'Wiederherstellung: ' . $reason, 0);

        $this->SetBuffer('PixooFailCount', '0');
        $this->SetBuffer('PixooHeavyPausedUntil', '');
        $this->SetBuffer('PixooLightPausedUntil', '');
        $this->startActiveTimers();
        $this->syncMessageSubscriptions();
        try {
            $this->runUpdateCycle(false);
        } catch (\Throwable $e) {
            $this->SendDebug('Watchdog', 'Recovery: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Symcon sendet VM_UPDATE bei Änderung der Quellvariablen — zweiter Pfad neben dem Update-Timer,
     * falls der Timer-Worker durch HTTP blockiert war oder Uhren-/Timer-Effekte auftreten.
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        unset($TimeStamp, $Data);
        if ($Message !== self::VM_UPDATE_MESSAGE) {
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        if ($this->collectConfigIssues() !== []) {
            return;
        }
        $watched = $this->getWatchedVariableIds();
        if (!in_array($SenderID, $watched, true)) {
            return;
        }
        try {
            $this->runUpdateCycle(true);
        } catch (\Throwable $e) {
            $this->SendDebug('MessageSink', $e->getMessage(), 0);
        }
    }

    /** @return list<int> */
    private function getWatchedVariableIds(): array
    {
        $ids = [];
        foreach ($this->getVariableSlotDefinitions() as $def) {
            $id = $this->ReadPropertyInteger($def['property']);
            if ($id > 0 && IPS_VariableExists($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function clearMessageSubscriptions(): void
    {
        $list = $this->GetMessageList();
        if (!is_array($list)) {
            return;
        }
        foreach ($list as $senderID => $messages) {
            if (!is_array($messages)) {
                continue;
            }
            $sid = (int) $senderID;
            foreach ($messages as $message) {
                $this->UnregisterMessage($sid, (int) $message);
            }
        }
    }

    private function syncMessageSubscriptions(): void
    {
        $this->clearMessageSubscriptions();
        if (!$this->ReadPropertyBoolean('Active') || $this->collectConfigIssues() !== []) {
            return;
        }
        foreach ($this->getWatchedVariableIds() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE_MESSAGE);
        }
    }

    /** Viertelstunden-Day-Ahead-Preis (www.smard.de), für Pixoo-Anzeige */
    public function UpdateSmardPrice(): void
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            return;
        }
        if (!$this->requireCurlExtension('SMARD')) {
            return;
        }
        try {
            $row = $this->fetchSmardSpotRow();
            if ($row === null) {
                $this->SendDebug('SMARD', 'Kein gültiger Preis (API/Zeitreihe) — letzter Wert bleibt.', 0);
                return;
            }
            $this->applySmardSpotRow($row);
            $this->SyncPixoo();
        } catch (\Throwable $e) {
            $this->SendDebug('SMARD', 'UpdateSmardPrice: ' . $e->getMessage(), 0);
        }
    }

    /**
     * SMARD manuell laden, Pixoo aktualisieren, Kurzinfo für Formular-Popup (echo).
     * @return string mehrsilig mit Preis, Datum und Uhrzeit der gelieferten Viertelstunde
     */
    public function SmardLoadActionFeedback(): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return 'Modul inaktiv.';
        }
        if (!$this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            return 'SMARD-Preis auf Pixoo ist deaktiviert (Eigenschaft „SMARD-Preis auf Pixoo anzeigen“).';
        }
        if (!$this->requireCurlExtension('SMARD')) {
            return 'SMARD-Abfrage nicht möglich: PHP-Erweiterung cURL fehlt auf dem Symcon-Server.';
        }
        $row = $this->fetchSmardSpotRow();
        if ($row === null) {
            $this->SendDebug('SMARD', 'Kein gültiger Preis (API/Zeitreihe).', 0);
            $this->UpdateValues();
            return "Kein gültiger SMARD-Preis (API/Zeitreihe).\nBitte später erneut versuchen.";
        }
        $this->applySmardSpotRow($row);
        $this->Refresh();
        $sec = intdiv($row['tsMs'], 1000);
        $dateStr = date('d.m.Y', $sec);
        $timeStr = date('H:i', $sec);
        $eurKwh = $row['eurMwh'] / 1000.0;
        $priceStr = number_format($eurKwh, 3, ',', '') . ' €';
        return "SMARD Day-Ahead (Viertelstunde)\nPreis: {$priceStr}\nDatum: {$dateStr}\nUhrzeit: {$timeStr}\n(Ortszeit PHP-Server)";
    }

    /** @param array{eurMwh: float, tsMs: int} $row */
    private function applySmardSpotRow(array $row): void
    {
        $this->SetBuffer('SmardEurPerMwh', (string) $row['eurMwh']);
        $this->SetBuffer('SmardSpotTsMs', (string) $row['tsMs']);
        $this->SetValue('SmardSpotCt', $row['eurMwh'] / 1000.0);
    }

    /** Update-Timer: Werte + Pixoo in einem Worker-Lauf (Intervall = UpdateIntervalSeconds). */
    public function UpdateValues(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        try {
            $this->runUpdateCycle(false);
        } catch (\Throwable $e) {
            $this->SendDebug('UpdateValues', 'Ausnahme: ' . $e->getMessage(), 0);
        }
    }

    /** Manuell / SMARD / Watchdog: nur Pixoo (Werte unverändert). */
    public function SyncPixoo(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        try {
            $this->syncPixooDisplay($this->getCachedEnergyValuesForPixoo(), true, false);
        } catch (\Throwable $e) {
            $this->SendDebug('SyncPixoo', 'Ausnahme: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Manuell / Formular: Werte + Pixoo (ohne Intervall-Sperre).
     * Update-Timer nutzt runUpdateCycle(); PixooSync-Timer ist deaktiviert (Build 18+).
     */
    public function Refresh(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        try {
            $this->runUpdateCycle(false);
        } catch (\Throwable $e) {
            $this->SendDebug('Refresh', 'Ausnahme: ' . $e->getMessage(), 0);
        }
    }

    private function getUpdateIntervalSec(): int
    {
        return max(self::UPDATE_INTERVAL_MIN_SEC, $this->ReadPropertyInteger('UpdateIntervalSeconds'));
    }

    /** MessageSink: gleiches Intervall wie UpdateIntervalSeconds; Timer: immer ausführen. */
    private function runUpdateCycle(bool $respectIntervalGate): void
    {
        if ($respectIntervalGate && $this->isUpdateCycleTooSoon()) {
            return;
        }
        $this->markUpdateCycleNow();

        $values = $this->updateEnergyValues();
        if ($values === null) {
            return;
        }
        if (trim($this->ReadPropertyString('PixooIp')) === '') {
            return;
        }
        $this->syncPixooDisplay($values, true, false);
    }

    private function isUpdateCycleTooSoon(): bool
    {
        $lastRaw = $this->GetBuffer('LastUpdateCycleAt');
        $last = is_numeric($lastRaw) ? (float) $lastRaw : 0.0;
        if ($last <= 0.0) {
            return false;
        }

        return (microtime(true) - $last) < (float) $this->getUpdateIntervalSec();
    }

    private function markUpdateCycleNow(): void
    {
        $this->SetBuffer('LastUpdateCycleAt', (string) microtime(true));
    }

    /**
     * @param array{consumption: float, generation: float, net: float} $values
     */
    private function getPixooDisplayStateHash(array $values): string
    {
        $parts = [
            (string) (int) round($values['consumption']),
            (string) (int) round($values['generation']),
            (string) (int) round($values['net']),
            (string) $this->getEffectivePixooBrightness(),
        ];
        if ($this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            $smard = $this->getSmardEurPerMwhForDisplay();
            $parts[] = $smard !== null ? (string) round($smard, 2) : '';
        }
        return sha1(implode('|', $parts));
    }

    /**
     * @param array{consumption: float, generation: float, net: float} $values
     */
    private function shouldSkipDuplicatePixooSync(array $values): bool
    {
        if ($this->getPixooDisplayStateHash($values) !== $this->GetBuffer('LastPixooDisplayHash')) {
            return false;
        }
        $lastRaw = $this->GetBuffer('LastPixooSyncAt');
        $last = is_numeric($lastRaw) ? (float) $lastRaw : 0.0;

        return $last > 0.0 && (microtime(true) - $last) < self::PIXOO_SYNC_DEBOUNCE_SEC;
    }

    /**
     * @param array{consumption: float, generation: float, net: float} $values
     */
    private function markPixooSyncCompleted(array $values): void
    {
        $this->SetBuffer('LastPixooDisplayHash', $this->getPixooDisplayStateHash($values));
        $this->SetBuffer('LastPixooSyncAt', (string) microtime(true));
    }

    /**
     * @return array{consumption: float, generation: float, net: float}
     */
    private function getCachedEnergyValuesForPixoo(): array
    {
        return [
            'consumption' => (float) $this->GetValue('Consumption'),
            'generation' => (float) $this->GetValue('Generation'),
            'net' => (float) $this->GetValue('Net'),
        ];
    }

    /** EUR/MWh für Pixoo: Puffer, sonst Modulvariable SmardSpotCt (€/kWh). */
    private function getSmardEurPerMwhForDisplay(): ?float
    {
        $buf = trim($this->GetBuffer('SmardEurPerMwh'));
        if ($buf !== '' && is_numeric($buf)) {
            return (float) $buf;
        }
        $ct = $this->GetValue('SmardSpotCt');
        if (is_int($ct) || is_float($ct)) {
            return (float) $ct * 1000.0;
        }
        if (is_string($ct) && is_numeric(trim(str_replace(',', '.', $ct)))) {
            return (float) str_replace(',', '.', trim($ct)) * 1000.0;
        }
        return null;
    }

    /**
     * Liest Quellvariablen und schreibt Modul-Variablen. Läuft ohne Netzwerk.
     *
     * @return array{consumption: float, generation: float, net: float}|null
     */
    private function updateEnergyValues(): ?array
    {
        if (!$this->ParseConfig()) {
            return null;
        }

        $buyW = $this->readWattFromVariable($this->ReadPropertyInteger('HmRealPowerPlusVar'));
        $sellW = $this->readWattFromVariable($this->ReadPropertyInteger('HmRealPowerMinusVar'));
        if ($buyW === null || $sellW === null) {
            $this->SendDebug('Netz', 'Real Power +/− kurz unlesbar — Netz-Leistung aus letztem Modulwert „Netz“.', 0);
            $netW = (float) $this->GetValue('Net');
        } else {
            $netW = $buyW - $sellW;
        }

        $wr1Pairs = [
            $this->ReadPropertyInteger('Wr1String1Var'),
            $this->ReadPropertyInteger('Wr1String2Var'),
        ];
        $wr2Pairs = [
            $this->ReadPropertyInteger('Wr2String1Var'),
            $this->ReadPropertyInteger('Wr2String2Var'),
        ];

        $wr1W = 0.0;
        foreach ($wr1Pairs as $vid) {
            $p = $this->readWattFromVariable($vid);
            if ($p === null) {
                $this->SendDebug('WR', 'WR1 Variable ID ' . $vid . ': kein Zahlwert — für diesen Zyklus 0 W.', 0);
                $p = 0.0;
            }
            $wr1W += max(0.0, $p);
        }
        if ($wr1W > self::GENERATION_INVALID_ABOVE_W) {
            $wr1W = 0.0;
        }

        $wr2W = 0.0;
        foreach ($wr2Pairs as $vid) {
            $p = $this->readWattFromVariable($vid);
            if ($p === null) {
                $this->SendDebug('WR', 'WR2 Variable ID ' . $vid . ': kein Zahlwert — für diesen Zyklus 0 W.', 0);
                $p = 0.0;
            }
            $wr2W += max(0.0, $p);
        }
        if ($wr2W > self::GENERATION_INVALID_ABOVE_W) {
            $wr2W = 0.0;
        }

        $generation = $wr1W + $wr2W;
        $consumption = $generation + $netW;

        $this->SetValue('Consumption', $consumption);
        $this->SetValue('Generation', $generation);
        $this->SetValue('Net', $netW);
        $this->SetBuffer('LastValuesAt', (string) time());

        return [
            'consumption' => $consumption,
            'generation' => $generation,
            'net' => $netW,
        ];
    }

    /**
     * @param array{consumption: float, generation: float, net: float} $values
     * @param bool $lightSync leichter Pfad (PixooSync): ItemList ohne schweres GIF
     * @param bool $heavyInit schweres Init mit Hintergrund-GIF (nur manuelles Reinit)
     */
    private function syncPixooDisplay(array $values, bool $lightSync = false, bool $heavyInit = false): void
    {
        $pixooIp = trim($this->ReadPropertyString('PixooIp'));
        if ($pixooIp === '') {
            return;
        }
        if ($lightSync && !$heavyInit && $this->shouldSkipDuplicatePixooSync($values)) {
            return;
        }
        if ($this->shouldSkipPixooHttp($lightSync && !$heavyInit)) {
            return;
        }
        if (!$this->requireCurlExtension('Pixoo')) {
            return;
        }

        try {
            if ($heavyInit) {
                $this->initPixooDisplayHeavy($pixooIp, $values);
                $this->recordPixooHttpSuccess(false);
                $this->markPixooSyncCompleted($values);
                return;
            }

            if ($this->GetBuffer('PixooInited') !== '1') {
                $this->initPixooDisplayLight($pixooIp, $values);
                $this->recordPixooHttpSuccess($lightSync);
                $this->markPixooSyncCompleted($values);
                return;
            }

            $eff = $this->getEffectivePixooBrightness();
            if ($this->GetBuffer('PixooLastBrightness') !== (string) $eff) {
                $this->PixooSetBrightness($pixooIp, $eff);
                $this->SetBuffer('PixooLastBrightness', (string) $eff);
            }

            $this->PixooSendItemList($pixooIp, $values['consumption'], $values['generation'], $values['net']);
            $this->recordPixooHttpSuccess($lightSync);
            $this->markPixooSyncCompleted($values);
        } catch (\Throwable $e) {
            $this->recordPixooHttpFailure($lightSync && !$heavyInit);
            $this->SendDebug('Pixoo', 'syncPixooDisplay: ' . $e->getMessage(), 0);
        }
    }

    private function shouldSkipPixooHeavyHttp(): bool
    {
        return $this->shouldSkipPixooHttpByBuffer('PixooHeavyPausedUntil');
    }

    private function shouldSkipPixooHttp(bool $lightSync): bool
    {
        if ($lightSync) {
            return $this->shouldSkipPixooHttpByBuffer('PixooLightPausedUntil');
        }
        return $this->shouldSkipPixooHeavyHttp()
            || $this->shouldSkipPixooHttpByBuffer('PixooLightPausedUntil');
    }

    private function shouldSkipPixooHttpByBuffer(string $bufferKey): bool
    {
        $untilRaw = $this->GetBuffer($bufferKey);
        $until = is_numeric($untilRaw) ? (int) $untilRaw : 0;
        if ($until > time()) {
            return true;
        }
        if ($until > 0 && $until <= time()) {
            $this->SetBuffer($bufferKey, '');
        }
        return false;
    }

    private function recordPixooHttpSuccess(bool $lightSync): void
    {
        $this->SetBuffer('PixooFailCount', '0');
        if ($lightSync) {
            $this->SetBuffer('PixooLightPausedUntil', '');
        } else {
            $this->SetBuffer('PixooHeavyPausedUntil', '');
            $this->SetBuffer('PixooLightPausedUntil', '');
        }
    }

    private function recordPixooHttpFailure(bool $lightSync): void
    {
        $n = (int) $this->GetBuffer('PixooFailCount') + 1;
        $this->SetBuffer('PixooFailCount', (string) $n);
        if ($n < self::PIXOO_MAX_CONSECUTIVE_FAILS) {
            return;
        }
        $this->SetBuffer('PixooFailCount', '0');
        $cooldown = $lightSync
            ? self::PIXOO_LIGHT_HTTP_COOLDOWN_SEC
            : self::PIXOO_HEAVY_HTTP_COOLDOWN_SEC;
        $bufferKey = $lightSync ? 'PixooLightPausedUntil' : 'PixooHeavyPausedUntil';
        $pauseUntil = time() + $cooldown;
        $this->SetBuffer($bufferKey, (string) $pauseUntil);
        $this->SendDebug(
            'Pixoo',
            ($lightSync ? 'Leichter' : 'Schwerer') . ' HTTP nach ' . self::PIXOO_MAX_CONSECUTIVE_FAILS
            . ' Fehlern für ' . $cooldown . ' s pausiert (Werte laufen weiter).',
            0
        );
    }

    private function requireCurlExtension(string $context): bool
    {
        if (function_exists('curl_init')) {
            return true;
        }
        static $warned = false;
        if (!$warned) {
            $warned = true;
            $this->SendDebug(
                $context,
                'PHP-Erweiterung cURL fehlt — HTTP deaktiviert (kein file_get_contents-Fallback wegen Hänger-Risiko).',
                0
            );
        }
        return false;
    }

    /** Stündlicher Timer: leichte Auffrischung (Helligkeit + ItemList, kein GIF). */
    public function ReinitDisplay(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        try {
            $values = $this->updateEnergyValues();
            if ($values === null) {
                $values = $this->getCachedEnergyValuesForPixoo();
            }
            $this->SetBuffer('PixooHeavyPausedUntil', '');
            $this->SetBuffer('PixooLightPausedUntil', '');
            $this->syncPixooDisplay($values, true, false);
        } catch (\Throwable $e) {
            $this->recordPixooHttpFailure(true);
            $this->SendDebug('Pixoo', 'ReinitDisplay: ' . $e->getMessage(), 0);
        }
    }

    /** Manuell / Formular: schweres Init mit Hintergrund-GIF. */
    public function HeavyReinitDisplay(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        try {
            $values = $this->updateEnergyValues();
            if ($values === null) {
                $values = $this->getCachedEnergyValuesForPixoo();
            }
            $this->SetBuffer('PixooInited', '0');
            $this->SetBuffer('PixooHeavyPausedUntil', '');
            $this->SetBuffer('PixooLightPausedUntil', '');
            $this->syncPixooDisplay($values, false, true);
        } catch (\Throwable $e) {
            $this->recordPixooHttpFailure(false);
            $this->SendDebug('Pixoo', 'HeavyReinitDisplay: ' . $e->getMessage(), 0);
        }
    }

    private function ParseConfig(): bool
    {
        return $this->collectConfigIssues() === [];
    }

    private function smardHttpGet(string $url): ?string
    {
        return $this->httpGetWithTimeouts(
            $url,
            self::SMARD_HTTP_CONNECT_TIMEOUT_SEC,
            self::SMARD_HTTP_TIMEOUT_SEC,
            true,
            false
        );
    }

    /**
     * Aktueller Viertelstunden-Day-Ahead-Preis DE/LU (SMARD) inkl. Beginn der Viertelstunde (Unix ms).
     * @return array{eurMwh: float, tsMs: int}|null
     */
    private function fetchSmardSpotRow(): ?array
    {
        $base = 'https://www.smard.de/app/chart_data/' . self::SMARD_FILTER_ID . '/' . self::SMARD_REGION;
        $indexRaw = $this->smardHttpGet($base . '/index_quarterhour.json');
        if ($indexRaw === null) {
            return null;
        }
        $index = json_decode($indexRaw, true);
        if (!is_array($index) || !isset($index['timestamps']) || !is_array($index['timestamps'])) {
            return null;
        }
        $timestamps = $index['timestamps'];
        if ($timestamps === []) {
            return null;
        }
        $nowMs = (int) round(microtime(true) * 1000);
        /* Wenige sequentielle HTTP-Requests — jeder blockiert den Modul-Worker (Symcon: ein Worker pro Instanz) */
        $try = array_slice($timestamps, -self::SMARD_MAX_CHUNK_REQUESTS);
        for ($ti = count($try) - 1; $ti >= 0; $ti--) {
            $chunkTs = (int) $try[$ti];
            $url = $base . '/' . self::SMARD_FILTER_ID . '_' . self::SMARD_REGION . '_quarterhour_' . $chunkTs . '.json';
            $seriesRaw = $this->smardHttpGet($url);
            if ($seriesRaw === null) {
                continue;
            }
            $data = json_decode($seriesRaw, true);
            if (!is_array($data) || !isset($data['series']) || !is_array($data['series'])) {
                continue;
            }
            $series = $data['series'];
            $best = null;
            $bestTsMs = 0;
            for ($i = count($series) - 1; $i >= 0; $i--) {
                $pair = $series[$i];
                if (!is_array($pair) || count($pair) < 2) {
                    continue;
                }
                $tMs = (int) $pair[0];
                $val = $pair[1];
                if ($tMs > $nowMs) {
                    continue;
                }
                if ($val === null || !is_numeric($val)) {
                    continue;
                }
                $best = (float) $val;
                $bestTsMs = $tMs;
                break;
            }
            if ($best !== null) {
                return ['eurMwh' => $best, 'tsMs' => $bestTsMs];
            }
        }
        return null;
    }

    private function smardPriceColorHex(float $eurPerMwh): string
    {
        if ($eurPerMwh > 0.0) {
            return $this->RgbHex(50, 220, 50);
        }
        return $this->RgbHex(255, 50, 50);
    }

    /**
     * @return list<array{property:string,label:string}>
     */
    private function getVariableSlotDefinitions(): array
    {
        return [
            ['property' => 'HmRealPowerPlusVar', 'label' => 'HM Real Power + (Netzbezug)'],
            ['property' => 'HmRealPowerMinusVar', 'label' => 'HM Real Power − (Einspeisung)'],
            ['property' => 'Wr1String1Var', 'label' => 'Wechselrichter 1, Variable 1'],
            ['property' => 'Wr1String2Var', 'label' => 'Wechselrichter 1, Variable 2'],
            ['property' => 'Wr2String1Var', 'label' => 'Wechselrichter 2, Variable 1'],
            ['property' => 'Wr2String2Var', 'label' => 'Wechselrichter 2, Variable 2'],
        ];
    }

    /** @return list<string> leer = Konfiguration in Ordnung */
    private function collectConfigIssues(): array
    {
        $issues = [];
        foreach ($this->getVariableSlotDefinitions() as $def) {
            $prop = $def['property'];
            $label = $def['label'];
            $id = $this->ReadPropertyInteger($prop);

            if ($id <= 0) {
                $issues[] = "{$label}: keine Variable gewählt (Property \"{$prop}\" = 0).";
                continue;
            }
            if (!IPS_ObjectExists($id)) {
                $issues[] = "{$label}: ObjectID {$id} existiert nicht.";
                continue;
            }
            if (!IPS_VariableExists($id)) {
                $ot = (int) (IPS_GetObject($id)['ObjectType'] ?? -1);
                $issues[] = "{$label}: ObjectID {$id} ist keine Variable (ObjectType {$ot}).";
                continue;
            }
            if (!$this->isAllowedWattVariable($id)) {
                $vt = $this->getVariableTypeCode($id);
                $issues[] = "{$label}: ObjectID {$id} hat ungültigen Typ "
                    . ($vt === null ? '?' : (string) $vt) . ' (' . $this->variableTypeLabel($vt) . ') — erlaubt: Integer, Float oder String.';
            }
        }
        return $issues;
    }

    private function getVariableTypeCode(int $variableId): ?int
    {
        $v = @IPS_GetVariable($variableId);
        if (!is_array($v)) {
            return null;
        }
        return (int) ($v['VariableType'] ?? -1);
    }

    private function variableTypeLabel(?int $variableType): string
    {
        return match ($variableType) {
            0 => 'Boolean',
            1 => 'Integer',
            2 => 'Float',
            3 => 'String',
            default => 'unbekannt',
        };
    }

    /** Integer, Float oder String (mit Zahl im Text) */
    private function isAllowedWattVariable(int $variableId): bool
    {
        $t = $this->getVariableTypeCode($variableId);
        return $t === 1 || $t === 2 || $t === 3;
    }

    private function readWattFromVariable(int $variableId): ?float
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $raw = GetValue($variableId);
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        return $this->parseLocaleFloat((string) $raw);
    }

    private function parseLocaleFloat(string $s): ?float
    {
        $s = trim(str_replace(["\xc2\xa0", ' '], '', $s));
        $s = str_replace(',', '.', $s);
        if ($s === '' || $s === '-') {
            return 0.0;
        }
        if (!is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    /**
     * @param array{consumption: float, generation: float, net: float} $values
     */
    private function initPixooDisplayLight(string $ip, array $values): void
    {
        $eff = $this->getEffectivePixooBrightness();
        if ($this->PixooPostRaw($ip, [
            'Command' => 'Channel/SetBrightness',
            'Brightness' => max(0, min(100, $eff)),
        ]) === null) {
            throw new \RuntimeException('SetBrightness fehlgeschlagen');
        }
        $this->SetBuffer('PixooLastBrightness', (string) $eff);
        $this->PixooSendItemList($ip, $values['consumption'], $values['generation'], $values['net']);
        $this->SetBuffer('PixooInited', '1');
    }

    /**
     * @param array{consumption: float, generation: float, net: float} $values
     */
    private function initPixooDisplayHeavy(string $ip, array $values): void
    {
        $eff = $this->getEffectivePixooBrightness();
        if ($this->PixooPostRaw($ip, [
            'Command' => 'Channel/SetBrightness',
            'Brightness' => max(0, min(100, $eff)),
        ]) === null) {
            throw new \RuntimeException('SetBrightness fehlgeschlagen');
        }
        $this->SetBuffer('PixooLastBrightness', (string) $eff);
        $this->PixooSendBackground($ip);
        $this->PixooSendItemList($ip, $values['consumption'], $values['generation'], $values['net']);
        $this->SetBuffer('PixooInited', '1');
    }

    private function PixooSetBrightness(string $ip, int $brightness): void
    {
        $b = max(0, min(100, $brightness));
        if ($this->PixooPostRaw($ip, [
            'Command' => 'Channel/SetBrightness',
            'Brightness' => $b,
        ]) === null) {
            throw new \RuntimeException('SetBrightness fehlgeschlagen');
        }
    }

    /** Tageshelligkeit oder Nachthelligkeit je nach Uhrzeit (Symcon-Serverzeit). */
    private function getEffectivePixooBrightness(): int
    {
        $day = max(0, min(100, $this->ReadPropertyInteger('DefaultBrightness')));
        if (!$this->ReadPropertyBoolean('PixooNightBrightnessUse')) {
            return $day;
        }
        $night = max(0, min(100, $this->ReadPropertyInteger('PixooNightBrightness')));
        return $this->isPixooNightTime() ? $night : $day;
    }

    /**
     * Nachtfenster: von PixooNightHourFrom (inkl.) bis PixooNightHourTo (exkl.).
     * Über Mitternacht: z. B. 22–6 bedeutet 22,23,0,…,5.
     * Gleiche Stunden = kein Nachtfenster (immer Tageshelligkeit).
     */
    private function isPixooNightTime(): bool
    {
        $from = max(0, min(23, $this->ReadPropertyInteger('PixooNightHourFrom')));
        $to = max(0, min(23, $this->ReadPropertyInteger('PixooNightHourTo')));
        if ($from === $to) {
            return false;
        }
        $h = (int) date('G');
        if ($from < $to) {
            return $h >= $from && $h < $to;
        }
        return $h >= $from || $h < $to;
    }

    private function PixooSendItemList(string $ip, float $consumption, float $generation, float $netW): void
    {
        if ($this->PixooPostRaw($ip, [
            'Command' => 'Draw/SendHttpItemList',
            'ItemList' => $this->BuildItems($consumption, $generation, $netW),
        ]) === null) {
            throw new \RuntimeException('SendHttpItemList fehlgeschlagen');
        }
    }

    private function PixooSendBackground(string $ip): void
    {
        $picId = $this->PixooGetPicId($ip);
        $frameLen = self::PIXEL_SIZE * self::PIXEL_SIZE * 3;
        $frame = str_repeat("\x00", $frameLen);
        if ($this->PixooPostRaw($ip, [
            'Command' => 'Draw/SendHttpGif',
            'PicNum' => 1,
            'PicWidth' => self::PIXEL_SIZE,
            'PicOffset' => 0,
            'PicID' => $picId,
            'PicSpeed' => 1000,
            'PicData' => base64_encode($frame),
        ]) === null) {
            throw new \RuntimeException('SendHttpGif fehlgeschlagen');
        }
    }

    private function PixooGetPicId(string $ip): int
    {
        $resp = $this->PixooPostRaw($ip, ['Command' => 'Draw/GetHttpGifId']);
        if ($resp === null) {
            return 1;
        }
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['PicId'])) {
            return (int) $j['PicId'];
        }
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeTextItem(
        int $textId,
        string $text,
        int $x,
        int $y,
        string $color,
        int $font,
        int $height,
        int $textWidth = self::PIXEL_SIZE,
        int $align = 1,
        int $dir = 0,
        int $speed = 100
    ): array {
        return [
            'TextId' => $textId,
            'type' => 22,
            'x' => $x,
            'y' => $y,
            'dir' => $dir,
            'font' => $font,
            'TextWidth' => $textWidth,
            'Textheight' => $height,
            'TextString' => $text,
            'speed' => $speed,
            'color' => $color,
            'update_time' => 0,
            'align' => $align,
        ];
    }

    private function safePhpDateFormat(string $format): string
    {
        $format = trim($format);
        if ($format === '') {
            return '--';
        }
        $out = @date($format);
        if ($out === false || $out === '') {
            return '--';
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function BuildItems(float $consumption, float $generation, float $netW): array
    {
        $cLabel = $this->RgbHex(140, 140, 140);
        $cWhite = $this->RgbHex(255, 255, 255);
        $cNet = $this->NetGridColorHex($netW);

        $items = [
            $this->makeTextItem(1, 'VERBRAUCH', self::LEFT_PAD, 2, $cLabel, self::FONT_LABEL, 7),
            $this->makeTextItem(2, sprintf('%.0f W', $consumption), self::LEFT_PAD, 8, $cWhite, self::FONT_VALUE, 16),
            $this->makeTextItem(3, 'ERZEUGUNG', self::LEFT_PAD, 24, $cLabel, self::FONT_LABEL, 7),
            $this->makeTextItem(4, sprintf('%.0f W', $generation), self::LEFT_PAD, 30, $cWhite, self::FONT_VALUE, 16),
            $this->makeTextItem(5, 'NETZ', self::LEFT_PAD, self::NETZ_LABEL_Y, $cLabel, self::FONT_LABEL, 7),
            $this->makeTextItem(6, sprintf('%.0f W', abs($netW)), self::LEFT_PAD, 52, $cNet, self::FONT_VALUE, 16),
        ];

        if ($this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            $tw = self::PIXEL_SIZE - self::DATETIME_X;
            $ax = self::DATETIME_X;
            $al = self::SMARD_CORNER_TEXT_ALIGN;
            $dDir = 1;
            $dSpeed = 0;

            $priceFont = self::FONT_VALUE;
            $priceH = 16;
            $priceY = 52;

            if ($this->ReadPropertyBoolean('PixooSmardShowTime')) {
                $timeStr = $this->safePhpDateFormat(self::SMARD_TIME_PHP_FORMAT);
                // Gleiche Font-ID wie Verbrauch/Erzeugung/Netz (FONT_VALUE), damit Ziffern (z. B. „0“) nicht wie bei FONT_LABEL 26 dicker wirken
                $items[] = $this->makeTextItem(
                    self::SMARD_TIME_TEXT_ID,
                    $timeStr,
                    $ax,
                    self::NETZ_LABEL_Y,
                    $cLabel,
                    self::FONT_VALUE,
                    10,
                    $tw,
                    $al,
                    $dDir,
                    $dSpeed
                );
            }

            $eurMwh = $this->getSmardEurPerMwhForDisplay();
            if ($eurMwh !== null) {
                $num = number_format($eurMwh / 1000.0, 2, ',', '');
                $txt = $this->ReadPropertyBoolean('PixooSmardShowUnit') ? ($num . '€') : $num;
                $col = $this->smardPriceColorHex($eurMwh);
            } else {
                $txt = '--';
                $col = $this->RgbHex(160, 160, 160);
            }
            $items[] = $this->makeTextItem(
                self::SMARD_TEXT_ID,
                $txt,
                $ax,
                $priceY,
                $col,
                $priceFont,
                $priceH,
                $tw,
                $al,
                $dDir,
                $dSpeed
            );
        }

        return $items;
    }

    /** Netzbezug → rot, Einspeisung → grün, nahe 0 → gelb */
    private function NetGridColorHex(float $netW): string
    {
        if (abs($netW) < self::NET_ZERO_EPSILON_W) {
            return $this->RgbHex(255, 220, 0);
        }
        if ($netW > 0.0) {
            return $this->RgbHex(255, 50, 50);
        }
        return $this->RgbHex(50, 220, 50);
    }

    private function RgbHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /** @param array<string,mixed> $payload */
    private function PixooPostRaw(string $ip, array $payload): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $url = 'http://' . $ip . ':80/post';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }

        return $this->httpPostJsonWithTimeouts(
            $url,
            $json,
            self::PIXOO_HTTP_CONNECT_TIMEOUT_SEC,
            self::PIXOO_HTTP_TIMEOUT_SEC,
            true
        );
    }

    /**
     * GET mit getrenntem Verbindungs- und Gesamt-Timeout (cURL), damit TCP nicht endlos blockiert.
     *
     * @param bool $abortSlowTransfer CURLOPT_LOW_SPEED_* (für große SMARD-JSON oft zu aggressiv → optional aus)
     */
    private function httpGetWithTimeouts(
        string $url,
        float $connectTimeoutSec,
        float $totalTimeoutSec,
        bool $verifySsl,
        bool $abortSlowTransfer = true
    ): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $connectInt = max(1, (int) ceil($connectTimeoutSec));
        $totalInt = max($connectInt, (int) ceil($totalTimeoutSec));
        $opts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: PIXOOEnergyViewer/IP-Symcon',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectInt,
            CURLOPT_TIMEOUT => $totalInt,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ];
        $opts += $this->curlNoSignalOpts();
        $opts += $this->curlTimeoutMsOpts($connectTimeoutSec, $totalTimeoutSec);
        if ($abortSlowTransfer) {
            $opts += $this->curlLowSpeedOpts();
        }
        if (\defined('CURLOPT_IPRESOLVE') && \defined('CURL_IPRESOLVE_V4')) {
            $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            if ($err !== '') {
                $this->SendDebug('HTTP', 'GET ' . $url . ' — cURL: ' . $err, 0);
            }
            return null;
        }
        if ($code >= 400) {
            $this->SendDebug('HTTP', 'GET ' . $url . ' — HTTP ' . $code, 0);
            return null;
        }
        return $raw;
    }

    /**
     * POST JSON mit getrenntem Verbindungs- und Gesamt-Timeout (cURL).
     *
     * @param bool $pixooPost frische TCP-Verbindung, kein Keep-Alive (Pixoo)
     */
    private function httpPostJsonWithTimeouts(
        string $url,
        string $json,
        float $connectTimeoutSec,
        float $totalTimeoutSec,
        bool $pixooPost = false
    ): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $connectInt = max(1, (int) ceil($connectTimeoutSec));
        $totalInt = max($connectInt, (int) ceil($totalTimeoutSec));
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectInt,
            CURLOPT_TIMEOUT => $totalInt,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
        ];
        $opts += $this->curlNoSignalOpts();
        $opts += $this->curlTimeoutMsOpts($connectTimeoutSec, $totalTimeoutSec);
        $opts += $this->curlLowSpeedOpts();
        if ($pixooPost) {
            if (\defined('CURLOPT_FRESH_CONNECT')) {
                $opts[CURLOPT_FRESH_CONNECT] = true;
            }
            if (\defined('CURLOPT_FORBID_REUSE')) {
                $opts[CURLOPT_FORBID_REUSE] = true;
            }
            if (\defined('CURLOPT_IPRESOLVE') && \defined('CURL_IPRESOLVE_V4')) {
                $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            if ($err !== '') {
                $this->SendDebug('Pixoo', 'POST ' . $url . ' — cURL: ' . $err, 0);
            }
            return null;
        }
        if ($code >= 400) {
            $this->SendDebug('Pixoo', 'POST ' . $url . ' — HTTP ' . $code, 0);
            return null;
        }
        return $raw;
    }

    /**
     * @return array<int, mixed>
     */
    private function curlTimeoutMsOpts(float $connectTimeoutSec, float $totalTimeoutSec): array
    {
        $opts = [];
        if (\defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            $opts[CURLOPT_CONNECTTIMEOUT_MS] = max(1, (int) round($connectTimeoutSec * 1000));
        }
        if (\defined('CURLOPT_TIMEOUT_MS')) {
            $opts[CURLOPT_TIMEOUT_MS] = max(1, (int) round($totalTimeoutSec * 1000));
        }
        return $opts;
    }

    /**
     * @return array<int, mixed>
     */
    private function curlNoSignalOpts(): array
    {
        $opts = [];
        if (\defined('CURLOPT_NOSIGNAL')) {
            $opts[CURLOPT_NOSIGNAL] = true;
        }
        return $opts;
    }

    /**
     * @return array<int, mixed>
     */
    private function curlLowSpeedOpts(): array
    {
        return [
            CURLOPT_LOW_SPEED_LIMIT => self::HTTP_LOW_SPEED_BYTES_PER_SEC,
            CURLOPT_LOW_SPEED_TIME => self::HTTP_LOW_SPEED_TIME_SEC,
        ];
    }
}
