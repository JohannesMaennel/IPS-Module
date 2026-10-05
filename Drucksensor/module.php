<?php

declare(strict_types=1);

class Drucksensor extends IPSModule
{
    private const MQTT_SERVER_MODULE = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_TX_DATA_ID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const CONFIGURATION_FIELDS = [
        'PressureCalibrationVoltage1' => ['pressure_calibration_voltage_1', 'pressure_calibration_voltage_1_v'],
        'PressureCalibration1' => ['pressure_calibration_bar_1', 'pressure_calibration_bar_1'],
        'PressureCalibrationVoltage2' => ['pressure_calibration_voltage_2', 'pressure_calibration_voltage_2_v'],
        'PressureCalibration2' => ['pressure_calibration_bar_2', 'pressure_calibration_bar_2'],
        'GasCounterOffsetM3' => ['gas_counter_offset_m3', 'gas_counter_offset_m3'],
        'GasPulsesPerM3' => ['gas_pulses_per_m3', 'gas_pulses_per_m3'],
        'WaterCounterOffsetM3' => ['water_counter_offset_m3', 'water_counter_offset_m3'],
        'WaterPulsesPerM3' => ['water_pulses_per_m3', 'water_pulses_per_m3']
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('BaseTopic', 'drucksensor');
        $this->RegisterPropertyBoolean('UseCustomSendTopic', false);
        $this->RegisterPropertyString('SendTopic', 'drucksensor/cmd');

        $this->RegisterPropertyFloat('PressureCalibration1', 0.0000);
        $this->RegisterPropertyFloat('PressureCalibrationVoltage1', 0.3880);
        $this->RegisterPropertyFloat('PressureCalibration2', 5.5158);
        $this->RegisterPropertyFloat('PressureCalibrationVoltage2', 4.3880);
        $this->RegisterPropertyString('PressureUnit', 'bar');

        $this->RegisterPropertyFloat('GasCounterOffsetM3', 0.0000);
        $this->RegisterPropertyFloat('GasPulsesPerM3', 100.0);
        $this->RegisterPropertyFloat('WaterCounterOffsetM3', 0.0000);
        $this->RegisterPropertyFloat('WaterPulsesPerM3', 1000.0);

        $this->RegisterPropertyString('VariableConfig', json_encode($this->GetDefaultVariableConfig()));
        $this->RegisterPropertyBoolean('DebugEnabled', false);

        $this->RegisterAttributeString('LastState', '');
        $this->RegisterAttributeString('PendingConfiguration', '');
        $this->RegisterAttributeBoolean('SynchronizingConfiguration', false);

        $this->ConnectParent(self::MQTT_SERVER_MODULE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->CreateOrUpdateVariables();
        $this->ApplyVariableConfiguration();
        $this->RefreshFromLastState();
        $this->UpdateReceiveDataFilter();
        $this->SetSummary($this->BuildSummary());
        if (!$this->ReadAttributeBoolean('SynchronizingConfiguration')) {
            $this->SendConfiguration();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'SendConfiguration') {
            $this->SendConfiguration();
            return;
        }

        throw new Exception('Ungueltiger Ident: ' . $Ident);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);

        if (!is_array($data)) {
            return '';
        }

        $topic = (string) ($data['Topic'] ?? '');
        $payload = $data['Payload'] ?? null;
        $baseTopic = $this->GetBaseTopic();

        if ($topic === '' || $baseTopic === '') {
            return '';
        }

        if ($topic === $baseTopic . '/state') {
            $state = $this->DecodePayloadToArray($payload);

            if ($state === null) {
                $this->Debug('ReceiveData', 'State-Payload ist kein gueltiges JSON.', 0);
                return '';
            }

            $encodedState = json_encode($state);

            if ($encodedState !== false) {
                $this->WriteAttributeString('LastState', $encodedState);
            }

            $this->ProcessState($state);
            $this->SynchronizeConfiguration($state);
            return '';
        }

        if ($topic === $baseTopic . '/status') {
            $this->UpdateOnlineStatus($payload === 'online');
            if ($payload === 'online' && $this->ReadAttributeString('PendingConfiguration') !== '') {
                $this->SendConfiguration();
            }
            return '';
        }

        return '';
    }

    public function GetConfigurationForm()
    {
        $elements = [];
        $actions = [];

        $elements[] = [
            'type' => 'Label',
            'caption' => 'Allgemein'
        ];

        $elements[] = [
            'type' => 'ValidationTextBox',
            'name' => 'BaseTopic',
            'caption' => 'Basictopic'
        ];

        $elements[] = [
            'type' => 'Label',
            'caption' => 'Parent verbunden: ' . ($this->CanSendToParent() ? 'Ja' : 'Nein')
        ];

        $elements[] = [
            'type' => 'CheckBox',
            'name' => 'UseCustomSendTopic',
            'caption' => 'Eigene Sendetopic verwenden?'
        ];

        $elements[] = [
            'type' => 'ValidationTextBox',
            'name' => 'SendTopic',
            'caption' => 'Sendetopic',
            'visible' => $this->ReadPropertyBoolean('UseCustomSendTopic')
        ];

        $elements[] = [
            'type' => 'CheckBox',
            'name' => 'DebugEnabled',
            'caption' => 'Debugging aktivieren'
        ];

        $elements[] = [
            'type' => 'Label',
            'caption' => 'Drucksensor'
        ];

        $elements[] = [
            'type' => 'RowLayout',
            'items' => [
                [
                    'type' => 'NumberSpinner',
                    'name' => 'PressureCalibration1',
                    'caption' => 'pressure_calibration_1 [bar]',
                    'digits' => 4,
                    'minimum' => 0,
                    'maximum' => 1000
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'PressureCalibrationVoltage1',
                    'caption' => 'voltage [V]',
                    'digits' => 4,
                    'minimum' => 0,
                    'maximum' => 10
                ]
            ]
        ];

        $elements[] = [
            'type' => 'RowLayout',
            'items' => [
                [
                    'type' => 'NumberSpinner',
                    'name' => 'PressureCalibration2',
                    'caption' => 'pressure_calibration_2 [bar]',
                    'digits' => 4,
                    'minimum' => 0,
                    'maximum' => 1000
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'PressureCalibrationVoltage2',
                    'caption' => 'voltage [V]',
                    'digits' => 4,
                    'minimum' => 0,
                    'maximum' => 10
                ]
            ]
        ];

        $elements[] = [
            'type' => 'Select',
            'name' => 'PressureUnit',
            'caption' => 'Einheit',
            'options' => [
                [
                    'caption' => 'bar',
                    'value' => 'bar'
                ],
                [
                    'caption' => 'PSI',
                    'value' => 'psi'
                ]
            ]
        ];

        $elements[] = [
            'type' => 'Label',
            'caption' => 'Gaszaehler'
        ];

        $elements[] = [
            'type' => 'RowLayout',
            'items' => [
                [
                    'type' => 'NumberSpinner',
                    'name' => 'GasCounterOffsetM3',
                    'caption' => 'gas_count_offset [m3]',
                    'digits' => 4,
                    'minimum' => 0,
                    'maximum' => 1000000
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'GasPulsesPerM3',
                    'caption' => 'gas_pulses_per_m3',
                    'digits' => 0,
                    'minimum' => 1,
                    'maximum' => 1000000
                ]
            ]
        ];

        $elements[] = [
            'type' => 'Label',
            'caption' => 'Wasserzaehler'
        ];

        $elements[] = [
            'type' => 'RowLayout',
            'items' => [
                [
                    'type' => 'NumberSpinner',
                    'name' => 'WaterCounterOffsetM3',
                    'caption' => 'water_count_offset [m3]',
                    'digits' => 4,
                    'minimum' => 0,
                    'maximum' => 1000000
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'WaterPulsesPerM3',
                    'caption' => 'water_pulses_per_m3',
                    'digits' => 0,
                    'minimum' => 1,
                    'maximum' => 1000000
                ]
            ]
        ];

        $elements[] = [
            'type' => 'Label',
            'caption' => 'Variablen'
        ];

        $elements[] = [
            'type' => 'List',
            'name' => 'VariableConfig',
            'caption' => 'Restliche Variablen',
            'rowCount' => count($this->GetVariableDefinitions()),
            'add' => false,
            'delete' => false,
            'columns' => [
                [
                    'caption' => 'Variable',
                    'name' => 'Key',
                    'width' => '220px',
                    'edit' => [
                        'type' => 'ValidationTextBox',
                        'enabled' => false
                    ]
                ],
                [
                    'caption' => 'Benennung',
                    'name' => 'Name',
                    'width' => '220px',
                    'edit' => [
                        'type' => 'ValidationTextBox'
                    ]
                ],
                [
                    'caption' => 'Darstellungsprofil',
                    'name' => 'Profile',
                    'width' => '200px',
                    'edit' => [
                        'type' => 'ValidationTextBox'
                    ]
                ],
                [
                    'caption' => 'Anzeigen',
                    'name' => 'Visible',
                    'width' => '90px',
                    'edit' => [
                        'type' => 'CheckBox'
                    ]
                ],
                [
                    'caption' => 'Loggen',
                    'name' => 'Logging',
                    'width' => '80px',
                    'edit' => [
                        'type' => 'CheckBox'
                    ]
                ]
            ],
            'values' => $this->GetVariableConfigForForm()
        ];

        $actions[] = [
            'type' => 'Button',
            'caption' => 'Konfiguration an ESP senden',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "SendConfiguration", true);'
        ];

        $actions[] = [
            'type' => 'Label',
            'caption' => 'Hinweis: Darstellungsprofil leer lassen, um das Standardprofil des Moduls zu verwenden.'
        ];

        return json_encode([
            'elements' => $elements,
            'actions' => $actions
        ]);
    }

    private function CreateOrUpdateVariables(): void
    {
        foreach ($this->GetVariableDefinitions() as $definition) {
            $profile = $this->GetEffectiveProfile($definition['key']);

            switch ($definition['type']) {
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean(
                        $definition['ident'],
                        $definition['defaultName'],
                        $profile,
                        $definition['position']
                    );
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger(
                        $definition['ident'],
                        $definition['defaultName'],
                        $profile,
                        $definition['position']
                    );
                    break;
                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat(
                        $definition['ident'],
                        $definition['defaultName'],
                        $profile,
                        $definition['position']
                    );
                    break;
                case VARIABLETYPE_STRING:
                    $this->RegisterVariableString(
                        $definition['ident'],
                        $definition['defaultName'],
                        $profile,
                        $definition['position']
                    );
                    break;
            }
        }
    }

    private function ApplyVariableConfiguration(): void
    {
        foreach ($this->GetVariableDefinitions() as $definition) {
            $variableID = @$this->GetIDForIdent($definition['ident']);

            if ($variableID === false) {
                continue;
            }

            $row = $this->GetVariableConfigRow($definition['key']);

            IPS_SetName($variableID, (string) $row['Name']);
            IPS_SetHidden($variableID, !(bool) $row['Visible']);
            IPS_SetVariableCustomProfile($variableID, $this->GetEffectiveProfile($definition['key']));

            $this->ApplyLoggingStatus($variableID, (bool) $row['Logging']);
        }
    }

    private function ProcessState(array $state): void
    {
        foreach ($this->GetVariableDefinitions() as $definition) {
            $key = $definition['key'];

            if (!array_key_exists($key, $state)) {
                continue;
            }

            $variableID = @$this->GetIDForIdent($definition['ident']);

            if ($variableID === false) {
                continue;
            }

            $normalizedValue = $this->NormalizeStateValue($definition, $state[$key]);
            $this->WriteTypedValue($variableID, $definition['type'], $normalizedValue);
        }
    }

    private function SynchronizeConfiguration(array $state): void
    {
        $pending = json_decode($this->ReadAttributeString('PendingConfiguration'), true);

        if (is_array($pending)) {
            foreach (self::CONFIGURATION_FIELDS as $fields) {
                [$commandKey, $stateKey] = $fields;

                if (!isset($state[$stateKey]) || !is_numeric($state[$stateKey])) {
                    return;
                }

                $expected = (float) $pending[$commandKey];
                $tolerance = max(0.00005, abs($expected) * 0.0000001);

                if (abs((float) $state[$stateKey] - $expected) > $tolerance) {
                    return;
                }
            }

            $this->WriteAttributeString('PendingConfiguration', '');
        }

        $changes = [];

        foreach (self::CONFIGURATION_FIELDS as $property => $fields) {
            $stateKey = $fields[1];

            if (!isset($state[$stateKey]) || !is_numeric($state[$stateKey])) {
                continue;
            }

            $value = round((float) $state[$stateKey], 4);
            $minimum = in_array($property, ['GasPulsesPerM3', 'WaterPulsesPerM3'], true) ? 1.0 : 0.0;

            if (!is_finite($value) || $value < $minimum || $this->ReadPropertyFloat($property) === $value) {
                continue;
            }

            $changes[$property] = $value;
        }

        if ($changes === []) {
            return;
        }

        $this->WriteAttributeBoolean('SynchronizingConfiguration', true);

        try {
            foreach ($changes as $property => $value) {
                IPS_SetProperty($this->InstanceID, $property, $value);
                $this->UpdateFormField($property, 'value', $value);
            }

            IPS_ApplyChanges($this->InstanceID);
        } finally {
            $this->WriteAttributeBoolean('SynchronizingConfiguration', false);
        }
    }

    private function NormalizeStateValue(array $definition, $value)
    {
        switch ($definition['type']) {
            case VARIABLETYPE_BOOLEAN:
                return $this->NormalizeBoolean($value);
            case VARIABLETYPE_INTEGER:
                return (int) $value;
            case VARIABLETYPE_FLOAT:
                $floatValue = (float) $value;

                if ($definition['key'] === 'pressure_bar' && $this->ReadPropertyString('PressureUnit') === 'psi') {
                    return $floatValue * 14.5037738;
                }

                return $floatValue;
            case VARIABLETYPE_STRING:
            default:
                return (string) $value;
        }
    }

    private function WriteTypedValue(int $variableID, int $type, $value): void
    {
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                SetValue($variableID, (bool) $value);
                break;
            case VARIABLETYPE_INTEGER:
                SetValue($variableID, (int) $value);
                break;
            case VARIABLETYPE_FLOAT:
                SetValue($variableID, (float) $value);
                break;
            case VARIABLETYPE_STRING:
                SetValue($variableID, (string) $value);
                break;
        }
    }

    private function UpdateOnlineStatus(bool $online): void
    {
        $definition = $this->GetVariableDefinitions()['esp_status'];
        $variableID = @$this->GetIDForIdent($definition['ident']);

        if ($variableID !== false) {
            SetValue($variableID, $online);
        }
    }

    private function SendConfiguration(): void
    {
        $baseTopic = $this->GetBaseTopic();

        if ($baseTopic === '') {
            return;
        }

        $payload = [];

        foreach (self::CONFIGURATION_FIELDS as $property => $fields) {
            $minimum = in_array($property, ['GasPulsesPerM3', 'WaterPulsesPerM3'], true) ? 1.0 : 0.0;
            $payload[$fields[0]] = max($minimum, $this->ReadPropertyFloat($property));
        }

        $jsonPayload = json_encode($payload);

        if ($jsonPayload === false) {
            $this->Debug('MQTT TX', 'Konfiguration konnte nicht in JSON umgewandelt werden.', 0);
            return;
        }

        $this->WriteAttributeString('PendingConfiguration', $jsonPayload);
        $this->PublishMQTT($this->GetSendTopic(), $jsonPayload, false);
    }

    private function PublishMQTT(string $topic, string $payload, bool $retain = false, int $qos = 0): void
    {
        if (!$this->CanSendToParent()) {
            $this->Debug('MQTT TX', 'Senden uebersprungen, da keine aktive MQTT-Parent-Instanz verbunden ist.', 0);
            return;
        }

        $data = [
            'DataID' => self::MQTT_TX_DATA_ID,
            'PacketType' => 3,
            'QualityOfService' => $qos,
            'Retain' => $retain,
            'Topic' => $topic,
            'Payload' => $payload
        ];

        $json = json_encode($data);

        if ($json === false) {
            return;
        }

        $this->Debug('MQTT TX', $json, 0);
        $this->SendDataToParent($json);
    }

    private function CanSendToParent(): bool
    {
        return $this->HasActiveParent();
    }

    private function UpdateReceiveDataFilter(): void
    {
        $baseTopic = $this->GetBaseTopic();

        if ($baseTopic === '') {
            $this->SetReceiveDataFilter('.*');
            return;
        }

        $topicPatterns = [];

        foreach (['state', 'status'] as $suffix) {
            $topic = $baseTopic . '/' . $suffix;
            $topicPatterns[] = preg_quote(json_encode($topic), '/');
            $topicPatterns[] = preg_quote(json_encode($topic, JSON_UNESCAPED_SLASHES), '/');
        }

        $pattern = '"Topic"\s*:\s*(?:' . implode('|', $topicPatterns) . ')';
        $this->SetReceiveDataFilter($pattern);
    }

    private function RefreshFromLastState(): void
    {
        $lastState = $this->ReadAttributeString('LastState');

        if ($lastState === '') {
            return;
        }

        $state = json_decode($lastState, true);

        if (!is_array($state)) {
            return;
        }

        $this->ProcessState($state);
    }

    private function DecodePayloadToArray($payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function NormalizeBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((float) $value) !== 0.0;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'online'], true);
    }

    private function GetBaseTopic(): string
    {
        return trim($this->ReadPropertyString('BaseTopic'), "/ \t\n\r\0\x0B");
    }

    private function GetSendTopic(): string
    {
        if ($this->ReadPropertyBoolean('UseCustomSendTopic')) {
            $customTopic = trim($this->ReadPropertyString('SendTopic'), "/ \t\n\r\0\x0B");

            if ($customTopic !== '') {
                return $customTopic;
            }
        }

        $baseTopic = $this->GetBaseTopic();

        if ($baseTopic === '') {
            return 'cmd';
        }

        return $baseTopic . '/cmd';
    }

    private function BuildSummary(): string
    {
        $topic = $this->GetBaseTopic();
        $unit = strtoupper($this->ReadPropertyString('PressureUnit'));

        if ($topic === '') {
            return 'Kein Topic konfiguriert';
        }

        return 'Topic: ' . $topic . ' | Druck: ' . $unit;
    }

    private function RegisterProfiles(): void
    {
        $this->EnsureFloatProfile('DRUCKSENSOR.Voltage', ' V', 4);
        $this->EnsureFloatProfile('DRUCKSENSOR.Pressure.Bar', ' bar', 4);
        $this->EnsureFloatProfile('DRUCKSENSOR.Pressure.PSI', ' psi', 4);
        $this->EnsureFloatProfile('DRUCKSENSOR.VolumeM3', ' m3', 4);
        $this->EnsureFloatProfile('DRUCKSENSOR.FlowLMin', ' l/min', 2);
        $this->EnsureFloatProfile('DRUCKSENSOR.FlowM3H', ' m3/h', 3);
        $this->EnsureFloatProfile('DRUCKSENSOR.ImpulsePerMinute', ' imp/min', 0);
    }

    private function EnsureFloatProfile(string $name, string $suffix, int $digits): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        }

        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileDigits($name, $digits);
    }

    private function ApplyLoggingStatus(int $variableID, bool $enabled): void
    {
        $archiveID = $this->GetArchiveControlID();

        if ($archiveID === 0 || !function_exists('AC_SetLoggingStatus')) {
            return;
        }

        AC_SetLoggingStatus($archiveID, $variableID, $enabled);

        if ($enabled && function_exists('AC_SetAggregationType')) {
            AC_SetAggregationType(
                $archiveID,
                $variableID,
                $this->GetAggregationTypeForVariable($variableID)
            );
        }

        IPS_ApplyChanges($archiveID);
    }

    private function GetAggregationTypeForVariable(int $variableID): int
    {
        foreach ($this->GetVariableDefinitions() as $definition) {
            $currentVariableID = @$this->GetIDForIdent($definition['ident']);

            if ($currentVariableID !== $variableID) {
                continue;
            }

            if (in_array($definition['key'], ['water_total_m3', 'gas_total_m3'], true)) {
                return 1;
            }

            break;
        }

        return 0;
    }

    private function GetArchiveControlID(): int
    {
        $archiveID = @IPS_GetInstanceIDByName('Archive', 0);

        if ($archiveID === false) {
            $archiveID = @IPS_GetInstanceIDByName('Archiv', 0);
        }

        return $archiveID === false ? 0 : $archiveID;
    }

    private function GetVariableDefinitions(): array
    {
        return [
            'esp_status' => [
                'key' => 'esp_status',
                'ident' => 'ESPStatus',
                'type' => VARIABLETYPE_BOOLEAN,
                'defaultName' => 'ESP Status',
                'position' => 10,
                'defaultView' => true,
                'defaultLog' => false
            ],
            'ip_address' => [
                'key' => 'ip_address',
                'ident' => 'IPAddress',
                'type' => VARIABLETYPE_STRING,
                'defaultName' => 'IP-Adresse',
                'position' => 20,
                'defaultView' => true,
                'defaultLog' => false
            ],
            'esp_temperature_c' => [
                'key' => 'esp_temperature_c',
                'ident' => 'ESPTemperature',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'ESP Temperatur',
                'position' => 30,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'pressure_voltage_v' => [
                'key' => 'pressure_voltage_v',
                'ident' => 'PressureVoltage',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Drucksensor Spannung',
                'position' => 40,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'pressure_bar' => [
                'key' => 'pressure_bar',
                'ident' => 'Pressure',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Druck',
                'position' => 50,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'water_pulse_rate_imp_min' => [
                'key' => 'water_pulse_rate_imp_min',
                'ident' => 'WaterPulseRate',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Wasser Impulse pro Minute',
                'position' => 60,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'water_total_m3' => [
                'key' => 'water_total_m3',
                'ident' => 'WaterTotal',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Wasserzaehler',
                'position' => 70,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'water_flow_l_min' => [
                'key' => 'water_flow_l_min',
                'ident' => 'WaterFlow',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Wasser Durchfluss',
                'position' => 80,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'gas_pulse_rate_imp_min' => [
                'key' => 'gas_pulse_rate_imp_min',
                'ident' => 'GasPulseRate',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Gas Impulse pro Minute',
                'position' => 90,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'gas_total_m3' => [
                'key' => 'gas_total_m3',
                'ident' => 'GasTotal',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Gaszaehler',
                'position' => 100,
                'defaultView' => true,
                'defaultLog' => true
            ],
            'gas_flow_m3_h' => [
                'key' => 'gas_flow_m3_h',
                'ident' => 'GasFlow',
                'type' => VARIABLETYPE_FLOAT,
                'defaultName' => 'Gas Durchfluss',
                'position' => 110,
                'defaultView' => true,
                'defaultLog' => true
            ]
        ];
    }

    private function GetDefaultVariableConfig(): array
    {
        $config = [];

        foreach ($this->GetVariableDefinitions() as $definition) {
            $config[] = [
                'Key' => $definition['key'],
                'Name' => $definition['defaultName'],
                'Profile' => '',
                'Visible' => $definition['defaultView'],
                'Logging' => $definition['defaultLog']
            ];
        }

        return $config;
    }

    private function GetVariableConfig(): array
    {
        $config = json_decode($this->ReadPropertyString('VariableConfig'), true);

        if (!is_array($config)) {
            return $this->GetDefaultVariableConfig();
        }

        return $config;
    }

    private function GetVariableConfigForForm(): array
    {
        $rows = [];

        foreach ($this->GetVariableDefinitions() as $definition) {
            $rows[] = $this->GetVariableConfigRow($definition['key']);
        }

        return $rows;
    }

    private function GetVariableConfigRow(string $key): array
    {
        $definition = $this->GetVariableDefinitions()[$key];
        $defaultRow = [
            'Key' => $definition['key'],
            'Name' => $definition['defaultName'],
            'Profile' => '',
            'Visible' => $definition['defaultView'],
            'Logging' => $definition['defaultLog']
        ];

        foreach ($this->GetVariableConfig() as $row) {
            if (($row['Key'] ?? '') !== $key) {
                continue;
            }

            return [
                'Key' => $key,
                'Name' => (string) ($row['Name'] ?? $defaultRow['Name']),
                'Profile' => (string) ($row['Profile'] ?? $defaultRow['Profile']),
                'Visible' => (bool) ($row['Visible'] ?? $defaultRow['Visible']),
                'Logging' => (bool) ($row['Logging'] ?? $defaultRow['Logging'])
            ];
        }

        return $defaultRow;
    }

    private function GetEffectiveProfile(string $key): string
    {
        $row = $this->GetVariableConfigRow($key);
        $profile = trim((string) $row['Profile']);

        if ($profile === '') {
            $profile = $this->GetDefaultProfile($key);
        }

        if ($profile !== '' && !IPS_VariableProfileExists($profile)) {
            $this->Debug('Profil', 'Profil nicht gefunden: ' . $profile, 0);
            return '';
        }

        return $profile;
    }

    private function GetDefaultProfile(string $key): string
    {
        switch ($key) {
            case 'esp_status':
                return '~Switch';
            case 'esp_temperature_c':
                return '~Temperature';
            case 'pressure_voltage_v':
                return 'DRUCKSENSOR.Voltage';
            case 'pressure_bar':
                return $this->ReadPropertyString('PressureUnit') === 'psi'
                    ? 'DRUCKSENSOR.Pressure.PSI'
                    : 'DRUCKSENSOR.Pressure.Bar';
            case 'water_pulse_rate_imp_min':
            case 'gas_pulse_rate_imp_min':
                return 'DRUCKSENSOR.ImpulsePerMinute';
            case 'water_total_m3':
            case 'gas_total_m3':
                return 'DRUCKSENSOR.VolumeM3';
            case 'water_flow_l_min':
                return 'DRUCKSENSOR.FlowLMin';
            case 'gas_flow_m3_h':
                return 'DRUCKSENSOR.FlowM3H';
            default:
                return '';
        }
    }

    private function Debug(string $title, string $message, int $format = 0): void
    {
        if ($this->ReadPropertyBoolean('DebugEnabled')) {
            $this->SendDebug($title, $message, $format);
        }
    }
}
