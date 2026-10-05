<?php

declare(strict_types=1);

class DRUKamin extends IPSModule
{
    private const MODBUS_GATEWAY_MODULE_ID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const CLIENT_SOCKET_MODULE_ID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const MODBUS_GATEWAY_DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    private const DEVICE_UNIT_ID = 2;
    private const STATUS_REGISTER = 40203;
    private const COMMAND_REGISTER = 40200;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('GatewayMode', 'existing');
        $this->RegisterPropertyInteger('GatewayInstanceID', 0);
        $this->RegisterPropertyString('InterfaceMode', 'new');
        $this->RegisterPropertyInteger('InterfaceInstanceID', 0);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitID', self::DEVICE_UNIT_ID);

        $this->RegisterPropertyBoolean('EnableFireplace', true);
        $this->RegisterPropertyBoolean('EnableSecondBurner', false);
        $this->RegisterPropertyBoolean('EnableLight', false);
        $this->RegisterPropertyBoolean('EnableBoostFan', false);
        $this->RegisterPropertyBoolean('EnableTemperatureControl', false);
        $this->RegisterPropertyBoolean('EnableWave', false);

        $this->RegisterAttributeInteger('CreatedGatewayID', 0);
        $this->RegisterAttributeInteger('CreatedInterfaceID', 0);
        $this->RegisterAttributeInteger('LastFlameWrite', 0);
        $this->RegisterAttributeInteger('LastIgnitionAt', 0);

        $this->RegisterTimer(
            'PollStatus',
            0,
            'IPS_RequestAction($_IPS["TARGET"], "PollStatus", true);'
        );

        $this->SetVisualizationType(1);
    }

    private function IsTemperatureControlActive(): bool
    {
        $variableId = $this->FindVariableId('TemperatureControl');
        return $variableId !== 0 && (bool) GetValue($variableId);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        $this->SynchronizeVariables();

        if ($this->ReadParentConnectionID() !== 0) {
            $this->SetTimerInterval('PollStatus', 60000);
        } else {
            $this->SetTimerInterval('PollStatus', 0);
            $this->SetCommunicationError('Kein Modbus-Gateway verbunden.');
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'SetupConnection') {
            $this->SetupConnection();
            return;
        }

        if ($Ident === 'PollStatus') {
            try {
                $this->PollStatus();
            } catch (Throwable $error) {
                $this->SetCommunicationError($error->getMessage());
            }
            return;
        }

        try {
            switch ($Ident) {
                case 'Fireplace':
                    $this->RequireFeature('EnableFireplace', 'Kamin');
                    $this->SetMainBurner((bool) $Value);
                    break;
                case 'SecondBurner':
                    $this->RequireFeature('EnableSecondBurner', 'Zweiter Brenner');
                    $this->SetSecondBurner((bool) $Value);
                    break;
                case 'Light':
                    $this->RequireFeature('EnableLight', 'Licht');
                    $this->SetAccessory((bool) $Value, 103, 5, 'Light');
                    break;
                case 'BoostFan':
                    $this->RequireFeature('EnableBoostFan', 'Boost-Lüfter');
                    $this->SetAccessory((bool) $Value, 104, 6, 'BoostFan');
                    break;
                case 'Wave':
                    $this->RequireFeature('EnableWave', 'Wave');
                    if ($this->ReadPropertyBoolean('EnableTemperatureControl')) {
                        throw new Exception('Wave ist bei aktivierter Temperaturregelung gesperrt.');
                    }
                    $this->SetAccessory((bool) $Value, 105, 7, 'Wave');
                    break;
                case 'TemperatureControl':
                    $this->RequireFeature('EnableTemperatureControl', 'Temperaturregelung');
                    $this->SetTemperatureControl((bool) $Value);
                    break;
                case 'FlameHeight':
                    $this->RequireFeature('EnableFireplace', 'Kamin');
                    $this->SetFlameHeight((int) $Value);
                    break;
                case 'TemperatureSetpoint':
                    $this->RequireFeature('EnableTemperatureControl', 'Temperaturregelung');
                    $this->SetTemperatureSetpoint((float) $Value);
                    break;
                default:
                    throw new Exception('Ungueltiger Ident: ' . $Ident);
            }

            $this->PollStatus();
        } catch (Throwable $error) {
            $this->SetCommunicationError($error->getMessage());
            throw $error;
        }
    }

    public function GetConfigurationForm()
    {
        $isNewGateway = $this->ReadPropertyString('GatewayMode') === 'new';
        $usesExistingInterface = $this->ReadPropertyString('InterfaceMode') === 'existing';
        $elements = [
            [
                'type' => 'Label',
                'caption' => 'Modbus-Verbindung'
            ],
            [
                'type' => 'Select',
                'name' => 'GatewayMode',
                'caption' => 'Modbus-Gateway',
                'options' => [
                    ['caption' => 'Bestehendes Gateway verwenden', 'value' => 'existing'],
                    ['caption' => 'Neues Gateway erstellen', 'value' => 'new']
                ]
            ],
            [
                'type' => 'SelectInstance',
                'name' => 'GatewayInstanceID',
                'caption' => 'Bestehendes Modbus-Gateway',
                'moduleID' => self::MODBUS_GATEWAY_MODULE_ID,
                'visible' => !$isNewGateway
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'Host',
                'caption' => 'DRU-Gateway-IP-Adresse / Hostname',
                'visible' => $isNewGateway && !$usesExistingInterface
            ],
            [
                'type' => 'NumberSpinner',
                'name' => 'Port',
                'caption' => 'TCP-Port',
                'minimum' => 1,
                'maximum' => 65535,
                'visible' => $isNewGateway && !$usesExistingInterface
            ],
            [
                'type' => 'NumberSpinner',
                'name' => 'UnitID',
                'caption' => 'Modbus Unit-ID des DRU-Gateways',
                'minimum' => 2,
                'maximum' => 255,
                'visible' => true
            ],
            [
                'type' => 'Select',
                'name' => 'InterfaceMode',
                'caption' => 'I/O-Schnittstelle',
                'options' => [
                    ['caption' => 'Neue Client-Socket-Schnittstelle erstellen', 'value' => 'new'],
                    ['caption' => 'Bestehenden Client Socket verwenden', 'value' => 'existing']
                ],
                'visible' => $isNewGateway
            ],
            [
                'type' => 'SelectInstance',
                'name' => 'InterfaceInstanceID',
                'caption' => 'Bestehender Client Socket',
                'moduleID' => self::CLIENT_SOCKET_MODULE_ID,
                'visible' => $isNewGateway && $usesExistingInterface
            ],
            [
                'type' => 'Label',
                'caption' => 'Bestehende Schnittstellen werden nicht umkonfiguriert. Bei einem neuen Gateway wird standardmaessig die Modbus Unit-ID 2 verwendet.'
            ],
            [
                'type' => 'Label',
                'caption' => 'Aktuelle Gateway-Verbindung: ' . $this->GetConnectionSummary()
            ],
            [
                'type' => 'Label',
                'caption' => 'Funktionen und Variablen'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'EnableFireplace',
                'caption' => 'Hauptbrenner / Kamin'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'EnableSecondBurner',
                'caption' => 'Zweiten Brenner'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'EnableLight',
                'caption' => 'Licht'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'EnableBoostFan',
                'caption' => 'Boost-Luefter'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'EnableTemperatureControl',
                'caption' => 'Temperaturregelung'
            ],
            [
                'type' => 'CheckBox',
                'name' => 'EnableWave',
                'caption' => 'Wave (nur ohne Temperaturregelung)',
                'visible' => !$this->ReadPropertyBoolean('EnableTemperatureControl')
            ],
            [
                'type' => 'Label',
                'caption' => 'Das Wave-Bedienelement wird ausgeblendet, sobald die Temperaturregelung aktiviert ist.'
            ]
        ];

        return json_encode([
            'elements' => $elements,
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Verbindung einrichten',
                    'onClick' => 'IPS_RequestAction($_IPS["TARGET"], "SetupConnection", true);'
                ]
            ]
        ], JSON_THROW_ON_ERROR);
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/UI/main.html');
        $css = file_get_contents(__DIR__ . '/UI/main.css');
        $js = file_get_contents(__DIR__ . '/UI/app.js');

        if (!is_string($html) || !is_string($css) || !is_string($js)) {
            throw new Exception('Die HTML-SDK-Dateien im UI-Ordner konnten nicht geladen werden.');
        }

        $featureIdents = [
            'Fireplace' => 'EnableFireplace',
            'FireplaceFault' => 'EnableFireplace',
            'SecondBurner' => 'EnableSecondBurner',
            'Light' => 'EnableLight',
            'BoostFan' => 'EnableBoostFan',
            'Wave' => 'EnableWave',
            'TemperatureControl' => 'EnableTemperatureControl',
            'FlameHeight' => 'EnableFireplace',
            'TemperatureSetpoint' => 'EnableTemperatureControl',
            'RoomTemperature' => 'EnableTemperatureControl'
        ];
        $values = [];
        foreach ($featureIdents as $ident => $property) {
            if (!$this->ReadPropertyBoolean($property) || $this->FindVariableId($ident) === 0) {
                continue;
            }
            $values[$ident] = GetValue($this->FindVariableId($ident));
        }

        $data = json_encode([
            'values' => $values,
            'communicationError' => $this->GetCommunicationError()
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return str_replace(
            ['{{CSS}}', '{{JS}}', '{{DATA}}'],
            [$css, $js, $data],
            $html
        );
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('DRU.Kamin.Prozent')) {
            IPS_CreateVariableProfile('DRU.Kamin.Prozent', 1);
            IPS_SetVariableProfileValues('DRU.Kamin.Prozent', 0, 100, 1);
            IPS_SetVariableProfileText('DRU.Kamin.Prozent', '', ' %');
        }

        if (!IPS_VariableProfileExists('DRU.Kamin.Temperatur')) {
            IPS_CreateVariableProfile('DRU.Kamin.Temperatur', 2);
            IPS_SetVariableProfileValues('DRU.Kamin.Temperatur', 5, 35, 0.5);
            IPS_SetVariableProfileDigits('DRU.Kamin.Temperatur', 1);
            IPS_SetVariableProfileText('DRU.Kamin.Temperatur', '', ' °C');
        }
    }

    private function SynchronizeVariables(): void
    {
        $this->SyncBooleanVariable(
            'Fireplace',
            'Hauptbrenner',
            $this->ReadPropertyBoolean('EnableFireplace'),
            1
        );
        $this->SyncBooleanVariable(
            'SecondBurner',
            'Zweiter Brenner',
            $this->ReadPropertyBoolean('EnableSecondBurner'),
            2
        );
        $this->SyncBooleanVariable(
            'Light',
            'Kaminlicht',
            $this->ReadPropertyBoolean('EnableLight'),
            3
        );
        $this->SyncBooleanVariable(
            'BoostFan',
            'Boost-Luefter',
            $this->ReadPropertyBoolean('EnableBoostFan'),
            4
        );
        $this->SyncBooleanVariable(
            'Wave',
            'Wave',
            $this->ReadPropertyBoolean('EnableWave')
                && !$this->ReadPropertyBoolean('EnableTemperatureControl'),
            5
        );
        $this->SyncBooleanVariable(
            'TemperatureControl',
            'Temperaturregelung',
            $this->ReadPropertyBoolean('EnableTemperatureControl'),
            6
        );

        if ($this->ReadPropertyBoolean('EnableFireplace')) {
            $this->RegisterVariableInteger('FlameHeight', 'Flammenhoehe', 'DRU.Kamin.Prozent', 7);
            $this->EnableAction('FlameHeight');
            $this->RegisterVariableBoolean('FireplaceFault', 'Kaminfehler', '', 10);
        } else {
            $this->DeleteVariableIfPresent('FlameHeight');
            $this->DeleteVariableIfPresent('FireplaceFault');
        }

        if ($this->ReadPropertyBoolean('EnableTemperatureControl')) {
            $setpointExists = $this->FindVariableId('TemperatureSetpoint') !== 0;
            $this->RegisterVariableFloat(
                'TemperatureSetpoint',
                'Solltemperatur',
                'DRU.Kamin.Temperatur',
                8
            );
            if (!$setpointExists) {
                $setpointId = $this->FindVariableId('TemperatureSetpoint');
                SetValue($setpointId, 20.0);
            }
            $this->EnableAction('TemperatureSetpoint');
            $this->RegisterVariableFloat(
                'RoomTemperature',
                'Raumtemperatur',
                'DRU.Kamin.Temperatur',
                9
            );
        } else {
            $this->DeleteVariableIfPresent('TemperatureSetpoint');
            $this->DeleteVariableIfPresent('RoomTemperature');
        }

        $this->RegisterVariableString('CommunicationError', 'Modbus-Status', '', 100);
    }

    private function SyncBooleanVariable(
        string $ident,
        string $name,
        bool $enabled,
        int $position
    ): void {
        if ($enabled) {
            $this->RegisterVariableBoolean($ident, $name, '~Switch', $position);
            $this->EnableAction($ident);
            return;
        }

        $this->DeleteVariableIfPresent($ident);
    }

    private function DeleteVariableIfPresent(string $ident): void
    {
        $variableId = $this->FindVariableId($ident);
        if ($variableId !== 0) {
            IPS_DeleteVariable($variableId);
        }
    }

    private function FindVariableId(string $ident): int
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            if (
                $object['ObjectType'] === 2
                && $object['ObjectIdent'] === $ident
            ) {
                return $childId;
            }
        }

        return 0;
    }

    private function SetupConnection(): void
    {
        $gatewayMode = $this->ReadPropertyString('GatewayMode');
        if ($gatewayMode !== 'new' && $gatewayMode !== 'existing') {
            throw new Exception('Ungueltige Auswahl fuer das Modbus-Gateway.');
        }

        if ($gatewayMode === 'new') {
            $gatewayId = $this->CreateOrReuseGateway();
        } else {
            $gatewayId = $this->ReadPropertyInteger('GatewayInstanceID');
            $this->ValidateInstanceModule(
                $gatewayId,
                self::MODBUS_GATEWAY_MODULE_ID,
                'Bitte waehlen Sie ein bestehendes Modbus-Gateway aus.'
            );
            if ((int) IPS_GetProperty($gatewayId, 'DeviceID') !== $this->ReadPropertyInteger('UnitID')) {
                throw new Exception(
                    'Die Device-ID des bestehenden Gateways muss mit der konfigurierten DRU Unit-ID uebereinstimmen.'
                );
            }
        }

        $gateway = IPS_GetInstance($gatewayId);
        if ((int) $gateway['ConnectionID'] === 0 && $gatewayMode !== 'new') {
            throw new Exception('Das ausgewaehlte Modbus-Gateway ist nicht mit einer I/O-Schnittstelle verbunden.');
        }

        if ($gatewayMode === 'new') {
            $this->ConfigureGatewayInterface($gatewayId);
        }

        if ($this->ReadParentConnectionID() !== $gatewayId) {
            IPS_ConnectInstance($this->InstanceID, $gatewayId);
        }
        if ($this->ReadParentConnectionID() !== $gatewayId) {
            throw new Exception('Das Modul konnte nicht mit dem ausgewaehlten Modbus-Gateway verbunden werden.');
        }

        IPS_SetProperty($this->InstanceID, 'GatewayInstanceID', $gatewayId);
        IPS_ApplyChanges($this->InstanceID);
    }

    private function CreateOrReuseGateway(): int
    {
        $this->ValidateNewGatewaySettings();
        $gatewayId = $this->ReadAttributeInteger('CreatedGatewayID');
        $isNew = $gatewayId === 0 || !IPS_ObjectExists($gatewayId);

        if (!$isNew) {
            $this->ValidateInstanceModule(
                $gatewayId,
                self::MODBUS_GATEWAY_MODULE_ID,
                'Das zuvor angelegte Modbus-Gateway ist nicht mehr verfuegbar.'
            );
        }

        if ($isNew) {
            $gatewayId = IPS_CreateInstance(self::MODBUS_GATEWAY_MODULE_ID);
            if ($gatewayId === 0) {
                throw new Exception('Das Modbus-Gateway konnte nicht erstellt werden.');
            }

            IPS_SetName($gatewayId, 'DRU Modbus Gateway');
            IPS_SetInfo($gatewayId, 'Automatisch durch das Modul DRU Kamin erstellt.');
            $this->WriteAttributeInteger('CreatedGatewayID', $gatewayId);

            $defaultInterfaceId = (int) IPS_GetInstance($gatewayId)['ConnectionID'];
            if ($defaultInterfaceId !== 0) {
                $this->WriteAttributeInteger('CreatedInterfaceID', $defaultInterfaceId);
            }
        }

        $this->ConfigureGatewayProperties($gatewayId);
        IPS_ApplyChanges($gatewayId);

        return $gatewayId;
    }

    private function ConfigureGatewayProperties(int $gatewayId): void
    {
        $unitId = $this->ReadPropertyInteger('UnitID');
        IPS_SetProperty($gatewayId, 'GatewayMode', 0);
        IPS_SetProperty($gatewayId, 'DeviceID', $unitId);
        IPS_SetProperty($gatewayId, 'SwapWords', 0);
    }

    private function ConfigureGatewayInterface(int $gatewayId): void
    {
        $interfaceMode = $this->ReadPropertyString('InterfaceMode');
        if ($interfaceMode !== 'new' && $interfaceMode !== 'existing') {
            throw new Exception('Ungueltige Auswahl fuer die I/O-Schnittstelle.');
        }
        $interfaceId = 0;

        if ($interfaceMode === 'existing') {
            $interfaceId = $this->ReadPropertyInteger('InterfaceInstanceID');
            $this->ValidateInstanceModule(
                $interfaceId,
                self::CLIENT_SOCKET_MODULE_ID,
                'Bitte waehlen Sie einen bestehenden Client Socket aus.'
            );
        } else {
            $interfaceId = (int) IPS_GetInstance($gatewayId)['ConnectionID'];
            if ($interfaceId === 0) {
                $interfaceId = IPS_CreateInstance(self::CLIENT_SOCKET_MODULE_ID);
                if ($interfaceId === 0) {
                    throw new Exception('Die Client-Socket-Schnittstelle konnte nicht erstellt werden.');
                }
                IPS_SetName($interfaceId, 'DRU Client Socket');
                $this->WriteAttributeInteger('CreatedInterfaceID', $interfaceId);
            }

            $this->ValidateInstanceModule(
                $interfaceId,
                self::CLIENT_SOCKET_MODULE_ID,
                'Das Modbus-Gateway besitzt keine gueltige Client-Socket-Schnittstelle.'
            );
            $host = trim($this->ReadPropertyString('Host'));
            IPS_SetProperty($interfaceId, 'Host', $host);
            IPS_SetProperty($interfaceId, 'Port', $this->ReadPropertyInteger('Port'));
            IPS_SetProperty($interfaceId, 'Open', true);
            IPS_ApplyChanges($interfaceId);
        }

        $currentInterfaceId = (int) IPS_GetInstance($gatewayId)['ConnectionID'];
        if ($currentInterfaceId !== $interfaceId) {
            if ($currentInterfaceId !== 0) {
                IPS_DisconnectInstance($gatewayId);
            }
            IPS_ConnectInstance($gatewayId, $interfaceId);
            if ((int) IPS_GetInstance($gatewayId)['ConnectionID'] !== $interfaceId) {
                throw new Exception('Das Modbus-Gateway konnte nicht mit der I/O-Schnittstelle verbunden werden.');
            }

            $ownedInterfaceId = $this->ReadAttributeInteger('CreatedInterfaceID');
            if (
                $currentInterfaceId !== 0
                && $currentInterfaceId === $ownedInterfaceId
                && $interfaceMode === 'existing'
                && !$this->IsInterfaceInUse($currentInterfaceId, $gatewayId)
            ) {
                IPS_DeleteInstance($currentInterfaceId);
                $this->WriteAttributeInteger('CreatedInterfaceID', 0);
            }
        }

        IPS_SetProperty($this->InstanceID, 'InterfaceInstanceID', $interfaceId);
        $this->WriteAttributeInteger('CreatedInterfaceID', $interfaceMode === 'new' ? $interfaceId : 0);
    }

    private function IsInterfaceInUse(int $interfaceId, int $excludingGatewayId): bool
    {
        foreach (IPS_GetInstanceListByModuleID(self::MODBUS_GATEWAY_MODULE_ID) as $gatewayId) {
            if (
                $gatewayId !== $excludingGatewayId
                && (int) IPS_GetInstance($gatewayId)['ConnectionID'] === $interfaceId
            ) {
                return true;
            }
        }

        return false;
    }

    private function ValidateInstanceModule(int $instanceId, string $moduleId, string $message): void
    {
        if ($instanceId <= 0) {
            throw new Exception($message);
        }

        $instance = IPS_GetInstance($instanceId);
        if (strtoupper($instance['ModuleInfo']['ModuleID']) !== strtoupper($moduleId)) {
            throw new Exception($message);
        }
    }

    private function ValidateNewGatewaySettings(): void
    {
        $unitId = $this->ReadPropertyInteger('UnitID');
        if ($unitId < 2 || $unitId > 255) {
            throw new Exception('Die DRU-Modbus-Unit-ID muss zwischen 2 und 255 liegen.');
        }

        if ($this->ReadPropertyString('InterfaceMode') === 'new') {
            if (trim($this->ReadPropertyString('Host')) === '') {
                throw new Exception('Bitte tragen Sie die IP-Adresse oder den Hostnamen des DRU-Gateways ein.');
            }
            $port = $this->ReadPropertyInteger('Port');
            if ($port < 1 || $port > 65535) {
                throw new Exception('Der TCP-Port muss zwischen 1 und 65535 liegen.');
            }
        }
    }

    private function ReadParentConnectionID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function GetConnectionSummary(): string
    {
        $connectionId = $this->ReadParentConnectionID();
        if ($connectionId === 0) {
            return 'nicht verbunden';
        }

        $connection = IPS_GetObject($connectionId);
        return $connection['ObjectName'] . ' (ID ' . $connectionId . ')';
    }

    private function PollStatus(): void
    {
        if ($this->ReadParentConnectionID() === 0) {
            throw new Exception('Kein Modbus-Gateway verbunden.');
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        $this->RecordMainBurnerState(($status & (1 << 2)) !== 0);
        $this->SetBooleanValueIfPresent('FireplaceFault', ($status & 1) !== 0);
        $this->SetBooleanValueIfPresent('SecondBurner', ($status & (1 << 3)) !== 0);
        $this->SetBooleanValueIfPresent('BoostFan', ($status & (1 << 7)) !== 0);
        $this->SetBooleanValueIfPresent('Light', ($status & (1 << 8)) !== 0);
        $this->SetBooleanValueIfPresent('Wave', ($status & (1 << 9)) !== 0);

        if ($this->ReadPropertyBoolean('EnableTemperatureControl')) {
            $roomTemperature = $this->ReadRegister(40204) / 10;
            $roomTemperatureId = $this->FindVariableId('RoomTemperature');
            if ($roomTemperatureId !== 0) {
                SetValue($roomTemperatureId, $roomTemperature);
            }

            $setpointId = $this->FindVariableId('TemperatureSetpoint');
            if ($setpointId !== 0) {
                SetValue($setpointId, $this->ReadRegister(40250) / 10);
            }
        }

        $this->SetCommunicationError('');
    }

    private function SetBooleanValueIfPresent(string $ident, bool $value): void
    {
        $variableId = $this->FindVariableId($ident);
        if ($variableId !== 0) {
            SetValue($variableId, $value);
        }
    }

    private function RecordMainBurnerState(bool $isOn): void
    {
        $variableId = $this->FindVariableId('Fireplace');
        if ($variableId === 0) {
            return;
        }

        $wasOn = (bool) GetValue($variableId);
        if ($isOn && !$wasOn) {
            $this->WriteAttributeInteger('LastIgnitionAt', time());
        } elseif (!$isOn) {
            $this->WriteAttributeInteger('LastIgnitionAt', 0);
        }
        SetValue($variableId, $isOn);
    }

    private function SetMainBurner(bool $turnOn): void
    {
        if (!$turnOn) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if (($status & (1 << 3)) !== 0) {
                $this->WriteRegister(self::COMMAND_REGISTER, 4);
                $this->WaitForStatusBit(3, false, 'Der zweite Brenner konnte nicht ausgeschaltet werden.');
            }
            if (($status & (1 << 9)) !== 0) {
                $this->WriteRegister(self::COMMAND_REGISTER, 7);
            }
            if ($this->IsTemperatureControlActive()) {
                $this->WriteRegister(self::COMMAND_REGISTER, 8);
                $this->SetBooleanValueIfPresent('TemperatureControl', false);
            }

            $this->WriteRegister(self::COMMAND_REGISTER, 3);
            $this->WaitForStatusBit(2, false, 'Der Hauptbrenner konnte nicht ausgeschaltet werden.');
            return;
        }

        $this->IgniteMainBurner();
    }

    private function IgniteMainBurner(): void
    {
        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if (($status & 1) !== 0) {
            throw new Exception('Der Kamin meldet einen Fehler. Die Zuendung wurde abgebrochen.');
        }
        if (($status & (1 << 2)) !== 0) {
            return;
        }
        if (($status & (1 << 15)) !== 0) {
            throw new Exception('Die Zuendung ist laut Kaminstatus derzeit nicht erlaubt.');
        }

        $oemFlags = $this->ReadRegister(40304);
        $hasStandingPilot = ($oemFlags & (1 << 2)) !== 0;
        try {
            if ($hasStandingPilot && ($status & (1 << 1)) === 0) {
                $this->WriteRegister(self::COMMAND_REGISTER, 100);
                $this->WaitForStatusBit(1, true, 'Die Zuendung der Pilotflamme ist fehlgeschlagen.');
            }
            $this->WriteRegister(self::COMMAND_REGISTER, 101);
            $this->WaitForStatusBit(2, true, 'Der Hauptbrenner konnte nicht gezuendet werden.');
        } catch (Throwable $error) {
            try {
                $this->WriteRegister(self::COMMAND_REGISTER, 3);
            } catch (Throwable $cleanupError) {
                $this->SendDebug('Sicheres Ausschalten fehlgeschlagen', $cleanupError->getMessage(), 0);
                IPS_LogMessage('DRU Kamin', 'Sicheres Ausschalten fehlgeschlagen: ' . $cleanupError->getMessage());
            }
            throw $error;
        }

        $this->WriteAttributeInteger('LastIgnitionAt', time());
    }

    private function SetSecondBurner(bool $turnOn): void
    {
        if ($turnOn) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if (($status & 1) !== 0) {
                throw new Exception('Der Kamin meldet einen Fehler. Der zweite Brenner wurde nicht eingeschaltet.');
            }
            if (($this->ReadRegister(40304) & (1 << 3)) === 0) {
                throw new Exception('Der Kamin ist laut Geraeteprofil nicht mit einem zweiten Brenner ausgestattet.');
            }
            if (($status & (1 << 2)) === 0) {
                $this->IgniteMainBurner();
            }
            $this->WriteRegister(self::COMMAND_REGISTER, 102);
            $this->WaitForStatusBit(3, true, 'Der zweite Brenner konnte nicht eingeschaltet werden.');
            return;
        }

        $this->WriteRegister(self::COMMAND_REGISTER, 4);
        $this->WaitForStatusBit(3, false, 'Der zweite Brenner konnte nicht ausgeschaltet werden.');
    }

    private function SetAccessory(bool $turnOn, int $onCommand, int $offCommand, string $ident): void
    {
        $this->WriteRegister(self::COMMAND_REGISTER, $turnOn ? $onCommand : $offCommand);
        $statusBit = [
            'Light' => 8,
            'BoostFan' => 7,
            'Wave' => 9
        ][$ident];
        $this->WaitForStatusBit(
            $statusBit,
            $turnOn,
            'Die Funktion "' . $ident . '" konnte nicht ' . ($turnOn ? 'eingeschaltet' : 'ausgeschaltet') . ' werden.'
        );
    }

    private function SetTemperatureControl(bool $turnOn): void
    {
        if ($turnOn) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if (($status & 1) !== 0) {
                throw new Exception('Der Kamin meldet einen Fehler. Die Temperaturregelung wurde nicht aktiviert.');
            }
            if (($status & (1 << 9)) !== 0) {
                $this->WriteRegister(self::COMMAND_REGISTER, 7);
                $this->WaitForStatusBit(9, false, 'Wave konnte vor der Temperaturregelung nicht beendet werden.');
            }
        }

        $this->WriteRegister(self::COMMAND_REGISTER, $turnOn ? 106 : 8);
        $this->SetTemperatureControlValue($turnOn);
    }

    private function SetTemperatureControlValue(bool $enabled): void
    {
        $variableId = $this->FindVariableId('TemperatureControl');
        if ($variableId !== 0) {
            SetValue($variableId, $enabled);
        }
    }

    private function SetFlameHeight(int $height): void
    {
        if ($height < 0 || $height > 100) {
            throw new Exception('Die Flammenhoehe muss zwischen 0 und 100 Prozent liegen.');
        }

        if ($this->ReadPropertyBoolean('EnableTemperatureControl')) {
            throw new Exception('Die Flammenhoehe kann bei aktivierter Temperaturregelung nicht gesetzt werden.');
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        $isOn = ($status & (1 << 2)) !== 0;
        $this->RecordMainBurnerState($isOn);
        if (!$isOn) {
            throw new Exception('Die Flammenhoehe kann erst nach erfolgreicher Zuendung gesetzt werden.');
        }

        $now = time();
        if ($now - $this->ReadAttributeInteger('LastIgnitionAt') < 10) {
            throw new Exception('Nach der Zuendung muss vor der ersten Flammenhoehen-Aenderung 10 Sekunden gewartet werden.');
        }
        if ($now - $this->ReadAttributeInteger('LastFlameWrite') < 10) {
            throw new Exception('Die Flammenhoehe darf nur einmal innerhalb von 10 Sekunden geschrieben werden.');
        }

        $this->WriteRegister(40201, $height);
        $this->WriteAttributeInteger('LastFlameWrite', $now);
        $variableId = $this->FindVariableId('FlameHeight');
        if ($variableId !== 0) {
            SetValue($variableId, $height);
        }
    }

    private function SetTemperatureSetpoint(float $temperature): void
    {
        if ($temperature < 5 || $temperature > 35 || abs(($temperature * 2) - round($temperature * 2)) > 0.001) {
            throw new Exception('Die Solltemperatur muss zwischen 5 und 35 °C in 0,5-°C-Schritten liegen.');
        }
        $this->WriteRegister(40250, (int) round($temperature * 10));
        $variableId = $this->FindVariableId('TemperatureSetpoint');
        if ($variableId !== 0) {
            SetValue($variableId, $temperature);
        }
    }

    private function WaitForStatusBit(int $bit, bool $expected, string $errorMessage): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if ((($status & (1 << $bit)) !== 0) === $expected) {
                return;
            }

            if ($attempt < 9) {
                IPS_Sleep(2000);
            }
        }

        throw new Exception($errorMessage);
    }

    private function ReadRegister(int $register): int
    {
        $address = $register - 40001;
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::MODBUS_GATEWAY_DATA_ID,
            'Function' => 3,
            'Address' => $address,
            'Quantity' => 1,
            'Data' => ''
        ], JSON_THROW_ON_ERROR));

        if (!is_string($response) || $response === '') {
            throw new Exception('Keine gueltige Antwort auf Modbus-Leseregister ' . $register . '.');
        }

        $function = ord($response[0]);
        if (($function & 0x80) !== 0) {
            $exceptionCode = strlen($response) > 1 ? ord($response[1]) : 0;
            throw new Exception(
                'Modbus-Fehler beim Lesen von Register ' . $register . ' (Exception ' . $exceptionCode . ').'
            );
        }
        if (strlen($response) < 4 || $function !== 3 || ord($response[1]) !== 2) {
            throw new Exception('Unerwartetes Modbus-Antwortformat fuer Register ' . $register . '.');
        }

        $value = unpack('nvalue', substr($response, 2, 2));
        if (!is_array($value) || !isset($value['value'])) {
            throw new Exception('Der Wert von Modbus-Register ' . $register . ' ist ungueltig.');
        }

        return (int) $value['value'];
    }

    private function WriteRegister(int $register, int $value): void
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::MODBUS_GATEWAY_DATA_ID,
            'Function' => 6,
            'Address' => $register - 40001,
            'Quantity' => 1,
            'Data' => bin2hex(pack('n', $value))
        ], JSON_THROW_ON_ERROR));

        if (!is_string($response) || $response === '') {
            throw new Exception('Keine gueltige Antwort auf Modbus-Schreibregister ' . $register . '.');
        }

        $function = ord($response[0]);
        if (($function & 0x80) !== 0) {
            $exceptionCode = strlen($response) > 1 ? ord($response[1]) : 0;
            throw new Exception(
                'Modbus-Fehler beim Schreiben von Register ' . $register . ' (Exception ' . $exceptionCode . ').'
            );
        }
        if ($function !== 6 || strlen($response) < 5) {
            throw new Exception('Unerwartete Modbus-Antwort auf Schreibregister ' . $register . '.');
        }
    }

    private function RequireFeature(string $property, string $featureName): void
    {
        if (!$this->ReadPropertyBoolean($property)) {
            throw new Exception('Die Funktion "' . $featureName . '" ist in der Konfiguration deaktiviert.');
        }
    }

    private function SetCommunicationError(string $message): void
    {
        $variableId = $this->FindVariableId('CommunicationError');
        if ($variableId !== 0) {
            SetValue($variableId, $message);
        }
        if ($message !== '') {
            $this->SendDebug('Modbus-Fehler', $message, 0);
            IPS_LogMessage('DRU Kamin', $message);
        }
    }

    private function GetCommunicationError(): string
    {
        $variableId = $this->FindVariableId('CommunicationError');
        return $variableId === 0 ? 'Noch nicht verbunden' : (string) GetValue($variableId);
    }
}
