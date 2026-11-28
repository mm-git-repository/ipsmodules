<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/HueSwitchTypes.php';
require_once __DIR__ . '/libs/HueSwitchEvents.php';
require_once __DIR__ . '/libs/HueSwitchActions.php';

class HUESwitchControl extends IPSModuleStrict
{
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 1;

    private const IS_ACTIVE = 102;
    private const IS_INACTIVE = 104;

    private const VM_UPDATE = 10603;
    private const SHORT_WAIT_MS = 400;
    private const HOLD_GAP_MS = 300;
    private const DIAL_WAIT_MS = 30;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('SwitchType', 'tapdial');
        $this->RegisterPropertyString('SimulateButton', 'button1');
        $this->RegisterPropertyString('SimulateEvent', 'short');

        foreach (HueSwitchTypes::all() as $type => $definition) {
            foreach ($definition['buttons'] as $key => $button) {
                unset($button);
                $this->RegisterPropertyInteger($this->instanceProperty($type, $key), 0);
                $this->RegisterPropertyString($this->actionsProperty($type, $key), '[]');
                $this->RegisterTimer($this->shortTimer($type, $key), 0, $this->timerCall('RunShort', $type, $key));
            }
            foreach ($definition['dials'] as $key => $dial) {
                unset($dial);
                $this->RegisterPropertyInteger($this->instanceProperty($type, $key), 0);
                $this->RegisterPropertyString($this->targetsProperty($type, $key), '[]');
                $this->RegisterPropertyBoolean($this->invertProperty($type, $key), false);
                $this->RegisterTimer($this->dialTimer($type, $key), 0, $this->timerCall('RunDial', $type, $key));
            }
        }

        $this->registerVariables();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if ($this->GetBuffer('restoring') === '1') {
            $this->SetBuffer('restoring', '0');
        } elseif ($this->restoreBackupIfEmpty()) {
            return;
        }

        $this->registerVariables();
        $this->subscribe();
        $this->saveBackup();
        $this->updateStatus();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{}';
        }
        $type = $this->currentType();
        $elements = [];
        foreach ($form['elements'] ?? [] as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['name'] ?? '') === 'ModuleVersionInfo') {
                $element['caption'] = sprintf(
                    'Installierte Modulversion: %s (Build %d)',
                    self::MODULE_VERSION,
                    self::MODULE_BUILD
                );
            }
            if (($element['name'] ?? '') === 'SwitchType') {
                $options = [];
                foreach (HueSwitchTypes::all() as $key => $definition) {
                    $options[] = ['caption' => $definition['name'], 'value' => $key];
                }
                $element['options'] = $options;
            }
            if (($element['type'] ?? '') === 'ExpansionPanel') {
                foreach ($element['items'] ?? [] as $index => $item) {
                    if (($item['name'] ?? '') === 'SimulateButton') {
                        $element['items'][$index]['options'] = $this->simulateButtonOptions($type);
                    }
                    if (($item['name'] ?? '') === 'SimulateEvent') {
                        $element['items'][$index]['options'] = HueSwitchTypes::eventOptions();
                    }
                }
            }
            $elements[] = $element;
            if (($element['name'] ?? '') === 'SwitchType') {
                foreach ($this->buildPanels($type) as $panel) {
                    $elements[] = $panel;
                }
            }
        }
        $form['elements'] = $elements;

        $encoded = json_encode($form, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        unset($TimeStamp, $Data);
        if ($Message !== self::VM_UPDATE || $SenderID <= 0) {
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $input = $this->inputMap()[$SenderID] ?? null;
        if ($input === null) {
            return;
        }
        try {
            if ($input['kind'] === 'button') {
                $this->onButton($input, (string) GetValue($SenderID));
                return;
            }
            $this->onDialPart($input, GetValue($SenderID));
        } catch (Throwable $error) {
            $this->SendDebug('Ereignis', $error->getMessage(), 0);
        }
    }

    public function ApplySwitchType(string $type): void
    {
        foreach (HueSwitchTypes::all() as $key => $definition) {
            $visible = $key === $type;
            foreach ($definition['buttons'] as $button => $info) {
                unset($info);
                $this->UpdateFormField($key . '_' . $button, 'visible', $visible);
            }
            foreach ($definition['dials'] as $dial => $info) {
                unset($info);
                $this->UpdateFormField($key . '_' . $dial, 'visible', $visible);
            }
        }
    }

    public function Simulate(string $button, string $event): void
    {
        $type = $this->currentType();
        $key = $this->resolveButtonKey($type, $button);
        $resolved = HueSwitchEvents::fromValue($event);
        if ($key === '' || $resolved === '' || $resolved === 'ignore') {
            $this->SendDebug('Simulation', 'Unbekannte Taste oder unbekanntes Ereignis: ' . $button . ' / ' . $event, 0);

            return;
        }
        $definition = HueSwitchTypes::get($type);
        $name = (string) ($definition['buttons'][$key]['name'] ?? $key);
        $this->remember($name, $resolved);
        $this->runRows($this->actionsProperty($type, $key), $resolved);
    }

    public function RunShort(string $type, string $button): void
    {
        $this->SetTimerInterval($this->shortTimer($type, $button), 0);
        $runtime = $this->runtime();
        if (($runtime['short'][$type][$button] ?? false) !== true) {
            return;
        }
        $runtime['short'][$type][$button] = false;
        $this->saveRuntime($runtime);
        $definition = HueSwitchTypes::get($type);
        $name = (string) ($definition['buttons'][$button]['name'] ?? $button);
        $this->remember($name, 'short');
        $this->runRows($this->actionsProperty($type, $button), 'short');
    }

    public function RunDial(string $type, string $dial): void
    {
        $this->SetTimerInterval($this->dialTimer($type, $dial), 0);
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $runtime = $this->runtime();
        $slot = $runtime['dial'][$type][$dial] ?? null;
        if (!is_array($slot)) {
            return;
        }
        $now = $this->nowMs();
        if (($now - (int) ($slot['stepsAt'] ?? 0)) > 250) {
            return;
        }
        if ((int) ($slot['appliedAt'] ?? 0) >= (int) ($slot['stepsAt'] ?? 0)) {
            return;
        }
        $action = HueSwitchEvents::dialAction((string) ($slot['action'] ?? ''));
        if ($action === 'unknown') {
            $this->SendDebug('Drehrad', 'Aktion ignoriert: ' . (string) ($slot['action'] ?? ''), 0);
            $slot['appliedAt'] = $now;
            $runtime['dial'][$type][$dial] = $slot;
            $this->saveRuntime($runtime);

            return;
        }
        if ((int) ($slot['directionAt'] ?? 0) <= 0 || ($now - (int) $slot['directionAt']) > 500) {
            $this->SendDebug('Drehrad', 'Richtung fehlt zu den Schritten', 0);

            return;
        }
        $slot['appliedAt'] = $now;
        $runtime['dial'][$type][$dial] = $slot;
        $this->saveRuntime($runtime);
        $this->applyDial($type, $dial, (string) ($slot['direction'] ?? ''), (int) ($slot['steps'] ?? 0));
    }

    private function onButton(array $input, string $raw): void
    {
        $event = HueSwitchEvents::fromValue($raw);
        $type = (string) $input['type'];
        $button = (string) $input['key'];
        $name = (string) $input['name'];
        if ($event === 'ignore') {
            return;
        }
        if ($event === '') {
            $this->SendDebug('Taste', $name . ': unbekannter Wert "' . $raw . '"', 0);
            $this->remember($name, $raw);

            return;
        }
        $this->remember($name, $event);
        if ($event === 'double') {
            $runtime = $this->runtime();
            $runtime['short'][$type][$button] = false;
            $this->saveRuntime($runtime);
            $this->SetTimerInterval($this->shortTimer($type, $button), 0);
            $this->runRows($this->actionsProperty($type, $button), 'double');

            return;
        }
        if ($event === 'short') {
            $runtime = $this->runtime();
            $runtime['short'][$type][$button] = true;
            $this->saveRuntime($runtime);
            $this->SetTimerInterval($this->shortTimer($type, $button), self::SHORT_WAIT_MS);

            return;
        }
        if ($event === 'hold') {
            $runtime = $this->runtime();
            $last = (int) ($runtime['holdAt'][$type][$button] ?? 0);
            $now = $this->nowMs();
            if (!HueSwitchActions::holdAllowed($last, $now, self::HOLD_GAP_MS)) {
                return;
            }
            $runtime['holdAt'][$type][$button] = $now;
            $this->saveRuntime($runtime);
        }
        $this->runRows($this->actionsProperty($type, $button), $event);
    }

    /** @param array<string, mixed> $input */
    private function onDialPart(array $input, mixed $value): void
    {
        $type = (string) $input['type'];
        $dial = (string) $input['key'];
        $field = (string) $input['field'];
        $runtime = $this->runtime();
        $slot = is_array($runtime['dial'][$type][$dial] ?? null) ? $runtime['dial'][$type][$dial] : [];
        if ($field === 'steps') {
            $slot['steps'] = (int) $value;
            $slot['stepsAt'] = $this->nowMs();
        } elseif ($field === 'direction') {
            $slot['direction'] = (string) $value;
            $slot['directionAt'] = $this->nowMs();
        } else {
            $slot['action'] = (string) $value;
            $slot['actionAt'] = $this->nowMs();
        }
        $runtime['dial'][$type][$dial] = $slot;
        $this->saveRuntime($runtime);
        $this->SetTimerInterval($this->dialTimer($type, $dial), self::DIAL_WAIT_MS);
    }

    private function applyDial(string $type, string $dial, string $direction, int $steps): void
    {
        $invert = $this->ReadPropertyBoolean($this->invertProperty($type, $dial));
        $sign = HueSwitchActions::directionSign($direction, $invert);
        if ($sign === 0) {
            $this->SendDebug('Drehrad', 'Richtung unbekannt: "' . $direction . '"', 0);

            return;
        }
        $this->remember('Drehrad', $sign > 0 ? 'im Uhrzeigersinn' : 'gegen den Uhrzeigersinn');
        foreach ($this->readRows($this->targetsProperty($type, $dial)) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $brightness = (int) ($row['brightness'] ?? 0);
            $switch = (int) ($row['switch'] ?? 0);
            if ($brightness <= 0) {
                continue;
            }
            $percent = (float) ($row['percent'] ?? 1);
            $min = (float) ($row['minimum'] ?? 1);
            $max = (float) ($row['maximum'] ?? 100);
            $delta = HueSwitchActions::dialDelta($steps, $percent, $sign);
            if ($delta == 0.0) {
                continue;
            }
            $off = $this->targetIsOff($brightness, $switch);
            if ($off && $delta < 0) {
                continue;
            }
            $level = HueSwitchActions::shiftedLevel($this->numericValue($brightness), $delta, $min, $max);
            $this->applySequence($brightness, $switch, HueSwitchActions::brightnessSequence($off, $level, $switch > 0));
        }
    }

    private function runRows(string $property, string $event): void
    {
        foreach ($this->readRows($property) as $row) {
            if (!is_array($row) || (string) ($row['event'] ?? '') !== $event) {
                continue;
            }
            $this->runAction($row);
        }
    }

    /** @param array<string, mixed> $row */
    private function runAction(array $row): void
    {
        $target = (int) ($row['target'] ?? 0);
        $switch = (int) ($row['switch'] ?? 0);
        $action = (string) ($row['action'] ?? '');
        $value = (float) ($row['value'] ?? 0);
        if ($target <= 0) {
            $this->SendDebug('Aktion', 'Ziel fehlt', 0);

            return;
        }
        if ($action === 'on') {
            $this->writeOn($target);

            return;
        }
        if ($action === 'off') {
            $this->writeOff($target);
            if ($switch > 0) {
                $this->writeOff($switch);
            }

            return;
        }
        if ($action === 'toggle') {
            $this->writeToggle($target);

            return;
        }
        if ($action === 'set' || $action === 'up' || $action === 'down') {
            $level = $value;
            if ($action === 'up' || $action === 'down') {
                $delta = $action === 'down' ? -abs($value) : abs($value);
                $level = HueSwitchActions::shiftedLevel($this->numericValue($target), $delta, 0.0, 100.0);
            }
            $off = $this->targetIsOff($target, $switch);
            $this->applySequence($target, $switch, HueSwitchActions::brightnessSequence($off, $level, $switch > 0));

            return;
        }
        $this->SendDebug('Aktion', 'Unbekannte Aktion: ' . $action, 0);
    }

    /** @param list<array{kind: string, value: float|bool}> $steps */
    private function applySequence(int $brightnessId, int $switchId, array $steps): void
    {
        foreach ($steps as $step) {
            $id = $step['kind'] === 'switch' ? $switchId : $brightnessId;
            $this->commit($id, $step['value']);
        }
    }

    private function writeOn(int $variableId): void
    {
        if (!IPS_VariableExists($variableId)) {
            $this->SendDebug('Aktion', 'Variable fehlt: ' . $variableId, 0);

            return;
        }
        $current = GetValue($variableId);
        if (is_bool($current)) {
            $this->commit($variableId, true);

            return;
        }
        $this->commit($variableId, $this->variableMax($variableId) <= 1.5 ? 1 : 100);
    }

    private function writeOff(int $variableId): void
    {
        if (!IPS_VariableExists($variableId)) {
            return;
        }
        $current = GetValue($variableId);
        $this->commit($variableId, is_bool($current) ? false : 0);
    }

    private function writeToggle(int $variableId): void
    {
        if (!IPS_VariableExists($variableId)) {
            return;
        }
        $current = GetValue($variableId);
        if (is_bool($current)) {
            $this->commit($variableId, !$current);

            return;
        }
        if (HueSwitchActions::isOff($current)) {
            $this->writeOn($variableId);

            return;
        }
        $this->writeOff($variableId);
    }

    private function commit(int $variableId, mixed $value): void
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            $this->SendDebug('Aktion', 'Variable fehlt: ' . $variableId, 0);

            return;
        }
        $variable = IPS_GetVariable($variableId);
        $typed = $this->castForVariable($variable, $value);
        $action = (int) ($variable['VariableCustomAction'] ?? 0);
        if ($action <= 0) {
            $action = (int) ($variable['VariableAction'] ?? 0);
        }
        if ($action > 0) {
            RequestAction($variableId, $typed);

            return;
        }
        $this->SendDebug('Aktion', 'Variable ' . $variableId . ' hat keine Aktion, SetValue', 0);
        SetValue($variableId, $typed);
    }

    /** @param array<string, mixed> $variable */
    private function castForVariable(array $variable, mixed $value): mixed
    {
        $type = (int) ($variable['VariableType'] ?? 1);
        if ($type === 0) {
            return (bool) $value;
        }
        if ($type === 1) {
            return (int) round((float) $value);
        }
        if ($type === 2) {
            return (float) $value;
        }

        return (string) $value;
    }

    private function variableMax(int $variableId): float
    {
        $variable = IPS_GetVariable($variableId);
        $profile = (string) ($variable['VariableCustomProfile'] ?? '');
        if ($profile === '') {
            $profile = (string) ($variable['VariableProfile'] ?? '');
        }
        if ($profile !== '' && function_exists('IPS_GetVariableProfile')) {
            $data = IPS_GetVariableProfile($profile);
            if (is_array($data) && isset($data['MaxValue'])) {
                return (float) $data['MaxValue'];
            }
        }

        return 100.0;
    }

    private function targetIsOff(int $brightnessId, int $switchId): bool
    {
        if ($switchId > 0 && IPS_VariableExists($switchId)) {
            return HueSwitchActions::isOff(GetValue($switchId));
        }
        if ($brightnessId > 0 && IPS_VariableExists($brightnessId)) {
            return HueSwitchActions::isOff(GetValue($brightnessId));
        }

        return true;
    }

    private function numericValue(int $variableId): float
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return 0.0;
        }
        $value = GetValue($variableId);
        if (is_bool($value)) {
            return $value ? 100.0 : 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * @return array<int, array{kind: string, type: string, key: string, name: string, field: string}>
     */
    private function inputMap(): array
    {
        $type = $this->currentType();
        $definition = HueSwitchTypes::get($type);
        if ($definition === null) {
            return [];
        }
        $map = [];
        foreach ($definition['buttons'] as $key => $button) {
            $instance = (int) $this->ReadPropertyInteger($this->instanceProperty($type, $key));
            $variable = $this->findChildVariable($instance, 'Last Event');
            if ($variable > 0) {
                $map[$variable] = [
                    'kind' => 'button',
                    'type' => $type,
                    'key' => $key,
                    'name' => $button['name'],
                    'field' => 'event',
                ];
            }
        }
        foreach ($definition['dials'] as $key => $dial) {
            $instance = (int) $this->ReadPropertyInteger($this->instanceProperty($type, $key));
            foreach (['Action' => 'action', 'Direction' => 'direction', 'Steps' => 'steps'] as $child => $field) {
                $variable = $this->findChildVariable($instance, $child);
                if ($variable <= 0) {
                    continue;
                }
                $map[$variable] = [
                    'kind' => 'dial',
                    'type' => $type,
                    'key' => $key,
                    'name' => $dial['name'],
                    'field' => $field,
                ];
            }
        }

        return $map;
    }

    private function subscribe(): void
    {
        $previous = json_decode($this->GetBuffer('watched'), true);
        if (is_array($previous)) {
            foreach ($previous as $id) {
                $this->UnregisterMessage((int) $id, self::VM_UPDATE);
            }
        }
        $ids = array_keys($this->inputMap());
        foreach ($ids as $id) {
            $this->RegisterMessage((int) $id, self::VM_UPDATE);
        }
        $encoded = json_encode(array_values($ids));
        $this->SetBuffer('watched', is_string($encoded) ? $encoded : '[]');
        $this->reportMissingChildren();
    }

    private function reportMissingChildren(): void
    {
        $type = $this->currentType();
        $definition = HueSwitchTypes::get($type);
        if ($definition === null) {
            return;
        }
        foreach ($definition['buttons'] as $key => $button) {
            $instance = (int) $this->ReadPropertyInteger($this->instanceProperty($type, $key));
            if ($instance > 0 && $this->findChildVariable($instance, 'Last Event') === 0) {
                $this->SendDebug('Konfiguration', $button['name'] . ': Last Event fehlt in Instanz ' . $instance, 0);
            }
        }
        foreach ($definition['dials'] as $key => $dial) {
            $instance = (int) $this->ReadPropertyInteger($this->instanceProperty($type, $key));
            if ($instance <= 0) {
                continue;
            }
            foreach (['Action', 'Direction', 'Steps'] as $child) {
                if ($this->findChildVariable($instance, $child) === 0) {
                    $this->SendDebug('Konfiguration', $dial['name'] . ': ' . $child . ' fehlt in Instanz ' . $instance, 0);
                }
            }
        }
    }

    private function findChildVariable(int $instanceId, string $name): int
    {
        if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
            return 0;
        }
        $wanted = HueSwitchEvents::normalize($name);
        foreach (IPS_GetChildrenIDs($instanceId) as $child) {
            if (!IPS_VariableExists((int) $child)) {
                continue;
            }
            $object = IPS_GetObject((int) $child);
            $objectName = HueSwitchEvents::normalize((string) ($object['ObjectName'] ?? ''));
            $ident = HueSwitchEvents::normalize((string) ($object['ObjectIdent'] ?? ''));
            if ($objectName === $wanted || $ident === $wanted) {
                return (int) $child;
            }
        }

        return 0;
    }

    /** @return list<array<string, mixed>> */
    private function buildPanels(string $selected): array
    {
        $panels = [];
        foreach (HueSwitchTypes::all() as $type => $definition) {
            $visible = $type === $selected;
            foreach ($definition['buttons'] as $key => $button) {
                $panels[] = [
                    'type' => 'ExpansionPanel',
                    'name' => $type . '_' . $key,
                    'caption' => $button['name'],
                    'visible' => $visible,
                    'expanded' => $visible && $key === 'button1',
                    'items' => [
                        [
                            'type' => 'SelectInstance',
                            'name' => $this->instanceProperty($type, $key),
                            'caption' => 'Hue-Taste',
                        ],
                        [
                            'type' => 'Label',
                            'caption' => 'Das Modul liest die Variable Last Event dieser Instanz.',
                        ],
                        $this->actionList($this->actionsProperty($type, $key)),
                    ],
                ];
            }
            foreach ($definition['dials'] as $key => $dial) {
                $panels[] = [
                    'type' => 'ExpansionPanel',
                    'name' => $type . '_' . $key,
                    'caption' => $dial['name'],
                    'visible' => $visible,
                    'items' => [
                        [
                            'type' => 'SelectInstance',
                            'name' => $this->instanceProperty($type, $key),
                            'caption' => 'Hue-Drehrad',
                        ],
                        [
                            'type' => 'Label',
                            'caption' => 'Das Modul liest Action, Direction, Steps und Duration. 1 % je Schritt: 30 Schritte sind etwa 30 %.',
                        ],
                        [
                            'type' => 'CheckBox',
                            'name' => $this->invertProperty($type, $key),
                            'caption' => 'Drehrichtung umkehren',
                        ],
                        $this->targetList($this->targetsProperty($type, $key)),
                    ],
                ];
            }
        }

        return $panels;
    }

    /** @return array<string, mixed> */
    private function actionList(string $name): array
    {
        return [
            'type' => 'List',
            'name' => $name,
            'caption' => 'Aktionen',
            'columns' => [
                [
                    'caption' => 'Ereignis',
                    'name' => 'event',
                    'width' => '150px',
                    'add' => 'short',
                    'edit' => ['type' => 'Select', 'options' => HueSwitchTypes::eventOptions()],
                ],
                [
                    'caption' => 'Aktion',
                    'name' => 'action',
                    'width' => '170px',
                    'add' => 'set',
                    'edit' => ['type' => 'Select', 'options' => HueSwitchTypes::actionOptions()],
                ],
                [
                    'caption' => 'Ziel',
                    'name' => 'target',
                    'width' => '260px',
                    'add' => 0,
                    'edit' => ['type' => 'SelectVariable'],
                ],
                [
                    'caption' => 'Wert %',
                    'name' => 'value',
                    'width' => '90px',
                    'add' => 25,
                    'edit' => ['type' => 'NumberSpinner', 'digits' => 0, 'minimum' => 0, 'maximum' => 100],
                ],
                [
                    'caption' => 'Ein/Aus',
                    'name' => 'switch',
                    'width' => '260px',
                    'add' => 0,
                    'edit' => ['type' => 'SelectVariable'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function targetList(string $name): array
    {
        return [
            'type' => 'List',
            'name' => $name,
            'caption' => 'Ziele',
            'columns' => [
                [
                    'caption' => 'Helligkeit',
                    'name' => 'brightness',
                    'width' => '260px',
                    'add' => 0,
                    'edit' => ['type' => 'SelectVariable'],
                ],
                [
                    'caption' => 'Ein/Aus',
                    'name' => 'switch',
                    'width' => '260px',
                    'add' => 0,
                    'edit' => ['type' => 'SelectVariable'],
                ],
                [
                    'caption' => '% je Schritt',
                    'name' => 'percent',
                    'width' => '110px',
                    'add' => 1,
                    'edit' => ['type' => 'NumberSpinner', 'digits' => 1, 'minimum' => 0.1, 'maximum' => 100],
                ],
                [
                    'caption' => 'Minimum',
                    'name' => 'minimum',
                    'width' => '90px',
                    'add' => 1,
                    'edit' => ['type' => 'NumberSpinner', 'digits' => 0, 'minimum' => 0, 'maximum' => 100],
                ],
                [
                    'caption' => 'Maximum',
                    'name' => 'maximum',
                    'width' => '90px',
                    'add' => 100,
                    'edit' => ['type' => 'NumberSpinner', 'digits' => 0, 'minimum' => 0, 'maximum' => 100],
                ],
            ],
        ];
    }

    /** @return list<array{caption: string, value: string}> */
    private function simulateButtonOptions(string $type): array
    {
        $definition = HueSwitchTypes::get($type);
        if ($definition === null) {
            return [];
        }
        $options = [];
        foreach ($definition['buttons'] as $key => $button) {
            $options[] = ['caption' => $button['name'], 'value' => $key];
        }

        return $options;
    }

    private function registerVariables(): void
    {
        $this->RegisterVariableString('LastButton', 'Letzte Taste', '', 0);
        $this->RegisterVariableString('LastEvent', 'Letztes Ereignis', '', 1);
        $this->registerVersionVariable();
    }

    private function registerVersionVariable(): void
    {
        $ident = 'ModuleVersion';
        $this->RegisterVariableString($ident, 'Modulversion', '', 2);
        $this->SetValue($ident, self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
    }

    private function remember(string $button, string $event): void
    {
        $caption = HueSwitchTypes::eventCaption($event);
        $this->SetValue('LastButton', $button);
        $this->SetValue('LastEvent', $caption !== $event ? $caption : $event);
    }

    private function updateStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::IS_INACTIVE);
            $this->SetSummary('Inaktiv');

            return;
        }
        $definition = HueSwitchTypes::get($this->currentType());
        $this->SetStatus(self::IS_ACTIVE);
        $this->SetSummary($definition['name'] ?? 'Hue Schalter');
    }

    private function currentType(): string
    {
        $type = (string) $this->ReadPropertyString('SwitchType');

        return HueSwitchTypes::get($type) === null ? 'tapdial' : $type;
    }

    private function resolveButtonKey(string $type, string $button): string
    {
        $definition = HueSwitchTypes::get($type);
        if ($definition === null) {
            return '';
        }
        $raw = HueSwitchEvents::normalize($button);
        if (isset($definition['buttons'][$raw])) {
            return $raw;
        }
        if (preg_match('/(\d+)/', $button, $match) === 1) {
            $key = 'button' . $match[1];
            if (isset($definition['buttons'][$key])) {
                return $key;
            }
        }

        return '';
    }

    /** @return list<mixed> */
    private function readRows(string $property): array
    {
        $raw = $this->ReadPropertyString($property);
        $rows = json_decode($raw, true);

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed> */
    private function runtime(): array
    {
        $data = json_decode($this->GetBuffer('runtime'), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $runtime */
    private function saveRuntime(array $runtime): void
    {
        $encoded = json_encode($runtime);
        $this->SetBuffer('runtime', is_string($encoded) ? $encoded : '{}');
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function instanceProperty(string $type, string $key): string
    {
        return $type . '_' . $key . '_Instance';
    }

    private function actionsProperty(string $type, string $key): string
    {
        return $type . '_' . $key . '_Actions';
    }

    private function targetsProperty(string $type, string $key): string
    {
        return $type . '_' . $key . '_Targets';
    }

    private function invertProperty(string $type, string $key): string
    {
        return $type . '_' . $key . '_Invert';
    }

    private function shortTimer(string $type, string $key): string
    {
        return 'Short_' . $type . '_' . $key;
    }

    private function dialTimer(string $type, string $key): string
    {
        return 'Dial_' . $type . '_' . $key;
    }

    private function timerCall(string $method, string $type, string $key): string
    {
        return 'HUESW_' . $method . '($_IPS[\'TARGET\'], \'' . $type . '\', \'' . $key . '\');';
    }

    private function backupPath(): string
    {
        if (!function_exists('IPS_GetKernelDir')) {
            return '';
        }
        $dir = rtrim((string) IPS_GetKernelDir(), '\\/') . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'HUESW_' . $this->InstanceID . '.json';
    }

    /** @return array<string, mixed> */
    private function captureConfig(): array
    {
        $data = [
            'Active' => $this->ReadPropertyBoolean('Active'),
            'SwitchType' => $this->ReadPropertyString('SwitchType'),
        ];
        foreach (HueSwitchTypes::all() as $type => $definition) {
            foreach ($definition['buttons'] as $key => $button) {
                unset($button);
                $data[$this->instanceProperty($type, $key)] = (int) $this->ReadPropertyInteger($this->instanceProperty($type, $key));
                $data[$this->actionsProperty($type, $key)] = $this->ReadPropertyString($this->actionsProperty($type, $key));
            }
            foreach ($definition['dials'] as $key => $dial) {
                unset($dial);
                $data[$this->instanceProperty($type, $key)] = (int) $this->ReadPropertyInteger($this->instanceProperty($type, $key));
                $data[$this->targetsProperty($type, $key)] = $this->ReadPropertyString($this->targetsProperty($type, $key));
                $data[$this->invertProperty($type, $key)] = $this->ReadPropertyBoolean($this->invertProperty($type, $key));
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function configHasInstance(array $data): bool
    {
        foreach (HueSwitchTypes::all() as $type => $definition) {
            foreach (array_merge(array_keys($definition['buttons']), array_keys($definition['dials'])) as $key) {
                if ((int) ($data[$this->instanceProperty($type, $key)] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function saveBackup(): void
    {
        $data = $this->captureConfig();
        if (!$this->configHasInstance($data)) {
            return;
        }
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return;
        }
        $path = $this->backupPath();
        if ($path === '') {
            return;
        }
        @file_put_contents($path, $encoded);
        $this->SendDebug('Konfiguration', 'Sicherung geschrieben: ' . $path, 0);
    }

    /** @return array<string, mixed>|null */
    private function loadBackup(): ?array
    {
        $path = $this->backupPath();
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function restoreBackupIfEmpty(): bool
    {
        $current = $this->captureConfig();
        if ($this->configHasInstance($current)) {
            return false;
        }
        $backup = $this->loadBackup();
        if ($backup === null || !$this->configHasInstance($backup)) {
            return false;
        }
        $this->SetBuffer('restoring', '1');
        $known = array_keys($this->captureConfig());
        foreach ($backup as $key => $value) {
            if (!is_string($key) || !in_array($key, $known, true)) {
                continue;
            }
            IPS_SetProperty($this->InstanceID, $key, $value);
        }
        $this->SendDebug('Konfiguration', 'Leere Instanz aus der Sicherung wiederhergestellt', 0);
        IPS_ApplyChanges($this->InstanceID);

        return true;
    }
}
