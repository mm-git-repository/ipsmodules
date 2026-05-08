<?php

declare(strict_types=1);

class PIXOOEnergyViewer extends IPSModuleStrict
{
    private const PIXEL_SIZE = 64;
    private const LEFT_PAD = 2;
    private const FONT_LABEL = 26;
    private const FONT_VALUE = 2;
    /**
     * Divoom ItemList: Font 0 / sehr kleine Höhen werden oft gar nicht gezeichnet.
     * Font 26 = gleiche kleine Label-Schrift wie „VERBRAUCH“ (standalone/sma_pixoo_display.py).
     */
    private const FONT_DATETIME = 26;
    private const DATETIME_TEXT_HEIGHT = 7;
    private const DATETIME_Y_LINE1 = 52;
    private const DATETIME_Y_LINE2 = 59;
    private const DATETIME_Y_SINGLE = 55;
    /** Textfeld ab X; mit align=3 und voller Breite rechtsbündig in der Zeile */
    private const DATETIME_X = 0;

    /** Zeile des Labels „NETZ“ (und optional SMARD-Uhrzeit rechts in derselben Zeile) */
    private const NETZ_LABEL_Y = 46;

    /** |Netz| unter diesem Wert (W) gilt auf dem Pixoo als „0“ → gelb */
    private const NET_ZERO_EPSILON_W = 0.5;

    private const ONE_HOUR_MS = 3600000;
    private const SMARD_FETCH_MS = 900000;

    /** SMARD Marktpreis Day-Ahead DE/LU, EUR/MWh → Anzeige €/kWh = Wert / 1000 */
    private const SMARD_FILTER_ID = 4169;
    private const SMARD_REGION = 'DE';
    private const SMARD_TIME_TEXT_ID = 18;
    private const SMARD_TEXT_ID = 20;

    /** Pro Wechselrichter (Summe seiner Strings): Leistung strikt darüber → Anteil dieses WR = 0 (Messfehler) */
    private const GENERATION_INVALID_ABOVE_W = 12000.0;

    /** IPS_GetKernelRunlevel() wenn der Kernel vollständig gestartet ist (siehe Symcon-Doku) */
    private const KERNEL_RUNLEVEL_READY = 10103;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('PixooIp', '172.18.1.167');
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', 5);
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

        $this->RegisterPropertyBoolean('PixooShowDateTime', false);
        $this->RegisterPropertyBoolean('PixooDateTimeTwoLines', true);
        $this->RegisterPropertyString('PixooDateTimeFormatDate', 'j.n.');
        $this->RegisterPropertyString('PixooDateTimeFormatTime', 'H:i');
        $this->RegisterPropertyString('PixooDateTimeFormatCombined', 'j.n. H:i');
        $this->RegisterPropertyInteger('PixooDateTimeFont', self::FONT_DATETIME);
        $this->RegisterPropertyInteger('PixooDateTimeTextHeight', self::DATETIME_TEXT_HEIGHT);
        /** 1 = links, 2 = mitte, 3 = rechts (Uhr: Standard 3) */
        $this->RegisterPropertyInteger('PixooDateTimeAlign', 3);

        $this->RegisterPropertyBoolean('PixooShowSmardPrice', true);
        $this->RegisterPropertyBoolean('PixooSmardShowUnit', true);
        $this->RegisterPropertyBoolean('PixooSmardShowTime', false);

        if (!IPS_VariableProfileExists('SMAPX.Watt')) {
            IPS_CreateVariableProfile('SMAPX.Watt', 2);
            IPS_SetVariableProfileText('SMAPX.Watt', '', ' W');
        }
        if (!IPS_VariableProfileExists('SMAPX.EurKWh')) {
            IPS_CreateVariableProfile('SMAPX.EurKWh', 2);
            IPS_SetVariableProfileText('SMAPX.EurKWh', '', ' €/kWh');
        }

        $kv = IPS_GetKernelVersion();
        $usePresArray = $kv !== false && $kv !== '' && version_compare((string) $kv, '8.0', '>=');
        if ($usePresArray) {
            $wattPres = ['PROFILE' => 'SMAPX.Watt'];
            $eurPres = ['PROFILE' => 'SMAPX.EurKWh'];
        } else {
            $wattPres = 'SMAPX.Watt';
            $eurPres = 'SMAPX.EurKWh';
        }
        $this->RegisterVariableFloat('Consumption', 'Verbrauch', $wattPres, 0);
        $this->RegisterVariableFloat('Generation', 'Erzeugung', $wattPres, 1);
        $this->RegisterVariableFloat('Net', 'Netz', $wattPres, 2);
        $this->RegisterVariableFloat('SmardSpotCt', 'SMARD Spot (€/kWh)', $eurPres, 3);

        // SMAPX_*: Symcon erzeugt globale Funktionen aus public-Methoden (scripts/__generated.inc.php).
        $this->RegisterTimer('Update', 0, 'SMAPX_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer('HourlyReinit', 0, 'SMAPX_ReinitDisplay($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SmardFetch', 0, 'SMAPX_UpdateSmardPrice($_IPS[\'TARGET\']);');
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
            'UpdateIntervalSeconds' => 5,
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
            'PixooShowDateTime' => false,
            'PixooDateTimeTwoLines' => true,
            'PixooDateTimeFormatDate' => 'j.n.',
            'PixooDateTimeFormatTime' => 'H:i',
            'PixooDateTimeFormatCombined' => 'j.n. H:i',
            'PixooDateTimeFont' => self::FONT_DATETIME,
            'PixooDateTimeTextHeight' => self::DATETIME_TEXT_HEIGHT,
            'PixooDateTimeAlign' => 3,
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
            $data = [];
        }
        if (!isset($data['configuration']) || !is_array($data['configuration'])) {
            $data['configuration'] = [];
        }
        foreach ($this->migrateDefaultConfiguration() as $key => $default) {
            if (!array_key_exists($key, $data['configuration'])) {
                $data['configuration'][$key] = $default;
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('Update', 0);
            $this->SetTimerInterval('HourlyReinit', 0);
            $this->SetTimerInterval('SmardFetch', 0);
            return;
        }

        $configIssues = $this->collectConfigIssues();
        if ($configIssues !== []) {
            $this->SetStatus(201);
            $this->SetTimerInterval('Update', 0);
            $this->SetTimerInterval('HourlyReinit', 0);
            $this->SetTimerInterval('SmardFetch', 0);
            foreach ($configIssues as $line) {
                $this->SendDebug('Konfiguration', $line, 0);
            }
            return;
        }

        $this->SetStatus(102);
        $this->SetBuffer('PixooInited', '0');
        $sec = max(1, $this->ReadPropertyInteger('UpdateIntervalSeconds'));
        $this->SetTimerInterval('Update', $sec * 1000);
        if ($this->ReadPropertyBoolean('PixooHourlyReinit')) {
            $this->SetTimerInterval('HourlyReinit', self::ONE_HOUR_MS);
        } else {
            $this->SetTimerInterval('HourlyReinit', 0);
        }
        if ($this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            $this->SetTimerInterval('SmardFetch', self::SMARD_FETCH_MS);
            if (IPS_GetKernelRunlevel() === self::KERNEL_RUNLEVEL_READY) {
                $this->UpdateSmardPrice();
            }
        } else {
            $this->SetTimerInterval('SmardFetch', 0);
        }
    }

    /** Viertelstunden-Day-Ahead-Preis (www.smard.de), für Pixoo-Anzeige */
    public function UpdateSmardPrice(): void
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            return;
        }
        $row = $this->fetchSmardSpotRow();
        if ($row === null) {
            $this->applySmardSpotRow(null);
            $this->SendDebug('SMARD', 'Kein gültiger Preis (API/Zeitreihe).', 0);
            return;
        }
        $this->applySmardSpotRow($row);
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
        $row = $this->fetchSmardSpotRow();
        if ($row === null) {
            $this->applySmardSpotRow(null);
            $this->SendDebug('SMARD', 'Kein gültiger Preis (API/Zeitreihe).', 0);
            $this->Refresh();
            return "Kein gültiger SMARD-Preis (API/Zeitreihe).\nBitte später erneut versuchen.";
        }
        $this->applySmardSpotRow($row);
        $this->Refresh();
        $sec = intdiv($row['tsMs'], 1000);
        $dateStr = date('d.m.Y', $sec);
        $timeStr = date('H:i', $sec);
        $eurKwh = $row['eurMwh'] / 1000.0;
        $priceStr = number_format($eurKwh, 3, ',', '') . ' €/kWh';
        return "SMARD Day-Ahead (Viertelstunde)\nPreis: {$priceStr}\nDatum: {$dateStr}\nUhrzeit: {$timeStr}\n(Ortszeit PHP-Server)";
    }

    /** @param array{eurMwh: float, tsMs: int}|null $row */
    private function applySmardSpotRow(?array $row): void
    {
        if ($row === null) {
            $this->SetBuffer('SmardEurPerMwh', '');
            $this->SetBuffer('SmardSpotTsMs', '');
            return;
        }
        $this->SetBuffer('SmardEurPerMwh', (string) $row['eurMwh']);
        $this->SetBuffer('SmardSpotTsMs', (string) $row['tsMs']);
        $this->SetValue('SmardSpotCt', $row['eurMwh'] / 1000.0);
    }

    public function Refresh(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }

        if (!$this->ParseConfig()) {
            return;
        }

        $buyW = $this->readWattFromVariable($this->ReadPropertyInteger('HmRealPowerPlusVar'));
        $sellW = $this->readWattFromVariable($this->ReadPropertyInteger('HmRealPowerMinusVar'));
        if ($buyW === null || $sellW === null) {
            $this->SendDebug('Netz', 'Real Power +/− konnten nicht gelesen werden.', 0);
            return;
        }

        $netW = $buyW - $sellW;

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
                $this->SendDebug('WR', 'Wechselrichter-Variable ungültig: ID ' . $vid, 0);
                return;
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
                $this->SendDebug('WR', 'Wechselrichter-Variable ungültig: ID ' . $vid, 0);
                return;
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

        $pixooIp = $this->ReadPropertyString('PixooIp');
        if ($pixooIp === '') {
            return;
        }

        if ($this->ReadPropertyBoolean('PixooShowSmardPrice') && trim($this->GetBuffer('SmardEurPerMwh')) === '') {
            $this->UpdateSmardPrice();
        }

        if ($this->GetBuffer('PixooInited') !== '1') {
            $this->InitPixooDisplay($pixooIp);
        } else {
            $eff = $this->getEffectivePixooBrightness();
            if ($this->GetBuffer('PixooLastBrightness') !== (string) $eff) {
                $this->PixooSetBrightness($pixooIp, $eff);
                $this->SetBuffer('PixooLastBrightness', (string) $eff);
            }
        }

        $this->PixooSendItemList($pixooIp, $consumption, $generation, $netW);
    }

    public function ReinitDisplay(): void
    {
        $this->SetBuffer('PixooInited', '0');
        $pixooIp = $this->ReadPropertyString('PixooIp');
        if ($pixooIp !== '') {
            $this->InitPixooDisplay($pixooIp);
            $this->SetValue('Consumption', $this->GetValue('Consumption'));
            $this->PixooSendItemList(
                $pixooIp,
                (float) $this->GetValue('Consumption'),
                (float) $this->GetValue('Generation'),
                (float) $this->GetValue('Net')
            );
        }
    }

    private function ParseConfig(): bool
    {
        return $this->collectConfigIssues() === [];
    }

    private function smardStreamContext()
    {
        return stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 25.0,
                'header' => "User-Agent: PIXOOEnergyViewer/IP-Symcon\r\nAccept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
    }

    private function smardHttpGet(string $url): ?string
    {
        $raw = @file_get_contents($url, false, $this->smardStreamContext());
        if ($raw === false || $raw === '') {
            return null;
        }
        return $raw;
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
        $try = array_slice($timestamps, -6);
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
     * @return array{y1:int,y2:int,ys:int,smard:int}
     */
    private function cornerLayoutYs(): array
    {
        $smard = $this->ReadPropertyBoolean('PixooShowSmardPrice');
        if ($smard) {
            return ['y1' => 52, 'y2' => 59, 'ys' => 55, 'smard' => 52];
        }
        return ['y1' => 52, 'y2' => 59, 'ys' => 55, 'smard' => 58];
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

    private function InitPixooDisplay(string $ip): void
    {
        $eff = $this->getEffectivePixooBrightness();
        $this->PixooSetBrightness($ip, $eff);
        $this->SetBuffer('PixooLastBrightness', (string) $eff);
        $this->PixooSendBackground($ip);
        $this->PixooPost($ip, [
            'Command' => 'Draw/SendHttpItemList',
            'ItemList' => $this->BuildItems(0.0, 0.0, 0.0),
        ]);
        $this->SetBuffer('PixooInited', '1');
    }

    private function PixooSetBrightness(string $ip, int $brightness): void
    {
        $b = max(0, min(100, $brightness));
        $this->PixooPost($ip, [
            'Command' => 'Channel/SetBrightness',
            'Brightness' => $b,
        ]);
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
        $this->PixooPost($ip, [
            'Command' => 'Draw/SendHttpItemList',
            'ItemList' => $this->BuildItems($consumption, $generation, $netW),
        ]);
    }

    private function PixooSendBackground(string $ip): void
    {
        $picId = $this->PixooGetPicId($ip);
        $frameLen = self::PIXEL_SIZE * self::PIXEL_SIZE * 3;
        $frame = str_repeat("\x00", $frameLen);
        $this->PixooPost($ip, [
            'Command' => 'Draw/SendHttpGif',
            'PicNum' => 1,
            'PicWidth' => self::PIXEL_SIZE,
            'PicOffset' => 0,
            'PicID' => $picId,
            'PicSpeed' => 1000,
            'PicData' => base64_encode($frame),
        ]);
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

        $ly = $this->cornerLayoutYs();

        // Datum/Uhrzeit im Eck nur ohne SMARD-Preis (sonst SMARD-Eck inkl. optionaler Uhrzeit)
        if ($this->ReadPropertyBoolean('PixooShowDateTime') && !$this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            $cClock = $this->RgbHex(200, 200, 200);
            $font = max(0, min(32, $this->ReadPropertyInteger('PixooDateTimeFont')));
            $th = max(4, min(16, $this->ReadPropertyInteger('PixooDateTimeTextHeight')));
            $tw = self::PIXEL_SIZE - self::DATETIME_X;
            $ax = self::DATETIME_X;
            $rawAl = $this->ReadPropertyInteger('PixooDateTimeAlign');
            $al = ($rawAl >= 1 && $rawAl <= 3) ? $rawAl : 3;
            // Wie standalone/pixoo_text.py bei statischem Text: sonst scrollt/kippt die Uhr aus dem sichtbaren Bereich
            $dDir = 1;
            $dSpeed = 0;

            if ($this->ReadPropertyBoolean('PixooDateTimeTwoLines')) {
                $d = $this->safePhpDateFormat($this->ReadPropertyString('PixooDateTimeFormatDate'));
                $t = $this->safePhpDateFormat($this->ReadPropertyString('PixooDateTimeFormatTime'));
                $items[] = $this->makeTextItem(7, $d, $ax, $ly['y1'], $cClock, $font, $th, $tw, $al, $dDir, $dSpeed);
                $items[] = $this->makeTextItem(8, $t, $ax, $ly['y2'], $cClock, $font, $th, $tw, $al, $dDir, $dSpeed);
            } else {
                $line = $this->safePhpDateFormat($this->ReadPropertyString('PixooDateTimeFormatCombined'));
                $items[] = $this->makeTextItem(7, $line, $ax, $ly['ys'], $cClock, $font, $th, $tw, $al, $dDir, $dSpeed);
            }
        }

        if ($this->ReadPropertyBoolean('PixooShowSmardPrice')) {
            $tw = self::PIXEL_SIZE - self::DATETIME_X;
            $ax = self::DATETIME_X;
            $rawAl = $this->ReadPropertyInteger('PixooDateTimeAlign');
            $al = ($rawAl >= 1 && $rawAl <= 3) ? $rawAl : 3;
            $dDir = 1;
            $dSpeed = 0;

            $priceFont = self::FONT_VALUE;
            $priceH = 16;
            $priceY = 52;

            if ($this->ReadPropertyBoolean('PixooSmardShowTime')) {
                $timeStr = $this->safePhpDateFormat($this->ReadPropertyString('PixooDateTimeFormatTime'));
                $items[] = $this->makeTextItem(
                    self::SMARD_TIME_TEXT_ID,
                    $timeStr,
                    $ax,
                    self::NETZ_LABEL_Y,
                    $cLabel,
                    self::FONT_LABEL,
                    7,
                    $tw,
                    $al,
                    $dDir,
                    $dSpeed
                );
            }

            $buf = trim($this->GetBuffer('SmardEurPerMwh'));
            if ($buf !== '' && is_numeric($buf)) {
                $eur = (float) $buf;
                $num = number_format($eur / 1000.0, 2, ',', '');
                $txt = $this->ReadPropertyBoolean('PixooSmardShowUnit') ? ($num . '€/kWh') : $num;
                $col = $this->smardPriceColorHex($eur);
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
    private function PixooPost(string $ip, array $payload): void
    {
        $this->PixooPostRaw($ip, $payload);
    }

    /** @param array<string,mixed> $payload */
    private function PixooPostRaw(string $ip, array $payload): ?string
    {
        $url = 'http://' . $ip . ':80/post';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 5.0,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            $this->SendDebug('Pixoo', 'HTTP Fehler: ' . $url, 0);
            return null;
        }
        return $result;
    }
}
