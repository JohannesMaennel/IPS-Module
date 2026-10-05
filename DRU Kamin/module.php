<?php

declare(strict_types=1);

class DRUKamin extends IPSModule
{
    private bool $actionFailed = false;

    private const MODBUS_GATEWAY_MODULE_ID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const MODBUS_GATEWAY_DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    private const STATUS_REGISTER = 40203;
    private const COMMAND_REGISTER = 40200;
    private const WAVE_INTERVAL_REGISTER = 40420;
    private const WAVE_STAGE_COUNT = 20;

    public function Create()
    {
        parent::Create();

        $this->ConnectParent(self::MODBUS_GATEWAY_MODULE_ID);

        $this->RegisterPropertyBoolean('EnableFireplace', true);
        $this->RegisterPropertyBoolean('EnableSecondBurner', false);
        $this->RegisterPropertyBoolean('EnableLight', false);
        $this->RegisterPropertyBoolean('EnableBoostFan', false);
        $this->RegisterPropertyBoolean('EnableTemperatureControl', false);
        $this->RegisterPropertyBoolean('EnableWave', false);

        $this->RegisterAttributeInteger('LastFlameWrite', 0);
        $this->RegisterAttributeInteger('LastIgnitionAt', 0);
        $this->RegisterAttributeInteger('StatusRegister', 0);
        $this->RegisterAttributeInteger('StatusUpdatedAt', 0);
        $this->RegisterAttributeInteger('WaveInterval', 10);
        $this->RegisterAttributeString(
            'WavePattern',
            json_encode(array_fill(0, self::WAVE_STAGE_COUNT, 50), JSON_THROW_ON_ERROR)
        );

        $this->RegisterTimer(
            'PollStatus',
            0,
            'IPS_RequestAction($_IPS["TARGET"], "PollStatus", true);'
        );

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        $this->SynchronizeVariables();

        $this->SetTimerInterval('PollStatus', 5000);
        $this->RefreshStatus();
    }

    public function RequestAction($Ident, $Value)
    {
        $this->actionFailed = false;

        if ($Ident === 'PollStatus') {
            $this->RefreshStatus();
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->LogError('Aktion ' . $Ident, 'Modbus-Gateway oder I/O ist nicht aktiv.');
            $this->RefreshStatus();
            return;
        }

        try {
            switch ($Ident) {
                case 'Fireplace':
                    $this->RequireFeature('EnableFireplace', 'Kamin');
                    if (!$this->actionFailed) {
                        $this->SetMainBurner((bool) $Value);
                    }
                    break;
                case 'SecondBurner':
                    $this->RequireFeature('EnableSecondBurner', 'Zweiter Brenner');
                    if (!$this->actionFailed) {
                        $this->SetSecondBurner((bool) $Value);
                    }
                    break;
                case 'Light':
                    $this->RequireFeature('EnableLight', 'Licht');
                    if (!$this->actionFailed) {
                        $this->SetAccessory((bool) $Value, 103, 5, 'Light');
                    }
                    break;
                case 'BoostFan':
                    $this->RequireFeature('EnableBoostFan', 'Boost-Lüfter');
                    if (!$this->actionFailed) {
                        $this->SetAccessory((bool) $Value, 104, 6, 'BoostFan');
                    }
                    break;
                case 'Wave':
                    $this->RequireFeature('EnableWave', 'Wave');
                    if (!$this->actionFailed) {
                        $this->SetOperationMode((bool) $Value ? 'wave' : 'manual');
                    }
                    break;
                case 'TemperatureControl':
                    $this->RequireFeature('EnableTemperatureControl', 'Temperaturregelung');
                    if (!$this->actionFailed) {
                        $this->SetOperationMode((bool) $Value ? 'temperature' : 'manual');
                    }
                    break;
                case 'OperationMode':
                    $this->SetOperationMode((string) $Value);
                    break;
                case 'FlameHeight':
                    $this->RequireFeature('EnableFireplace', 'Kamin');
                    if (!$this->actionFailed) {
                        $this->SetFlameHeight((int) $Value);
                    }
                    break;
                case 'TemperatureSetpoint':
                    $this->RequireFeature('EnableTemperatureControl', 'Temperaturregelung');
                    if (!$this->actionFailed) {
                        $this->SetTemperatureSetpoint((float) $Value);
                    }
                    break;
                case 'SaveWaveSettings':
                    $this->RequireFeature('EnableWave', 'Wave');
                    if (!$this->actionFailed) {
                        $this->SaveWaveSettings((string) $Value);
                    }
                    break;
                default:
                    $this->LogError('RequestAction', 'Ungueltiger Ident: ' . $Ident);
                    break;
            }

        } catch (Throwable $error) {
            $this->LogError('Aktion ' . $Ident . ' fehlgeschlagen', $error);
        }
        $this->RefreshStatus();
    }

    public function GetConfigurationForm()
    {
        $elements = [
            [
                'type' => 'Label',
                'caption' => 'Funktionen'
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
                'caption' => 'Wave'
            ],
            [
                'type' => 'Label',
                'caption' => 'Wave und Temperaturregelung werden als unterschiedliche Betriebsarten verwendet.'
            ]
        ];

        return json_encode([
            'elements' => $elements,
            'actions' => [],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Modbus-Kommunikation aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Modbus-Gateway oder I/O nicht aktiv'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Modbus-Status konnte nicht gelesen werden']
            ]
        ], JSON_THROW_ON_ERROR);
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/UI/main.html');
        $css = file_get_contents(__DIR__ . '/UI/main.css');
        $js = file_get_contents(__DIR__ . '/UI/app.js');

        if (!is_string($html) || !is_string($css) || !is_string($js)) {
            $this->LogError('Visualisierung', 'Die HTML-SDK-Dateien im UI-Ordner konnten nicht geladen werden.');
            return '';
        }

        return str_replace(
            ['{{CSS}}', '{{JS}}', '{{DATA}}'],
            [$css, $js, json_encode($this->GetVisualizationData(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)],
            $html
        );
    }

    private function GetVisualizationData(): array
    {
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

        return [
            'values' => $values,
            'statusAvailable' => $this->ReadAttributeInteger('StatusUpdatedAt') > 0
                && (time() - $this->ReadAttributeInteger('StatusUpdatedAt')) < 15
                && $this->HasActiveParent(),
            'statusValidForMs' => max(0, 15 - (time() - $this->ReadAttributeInteger('StatusUpdatedAt'))) * 1000,
            'statusRegister' => $this->ReadAttributeInteger('StatusRegister'),
            'canSetFlameHeight' => $this->CanSetFlameHeight(),
            'features' => [
                'temperatureControl' => $this->ReadPropertyBoolean('EnableTemperatureControl'),
                'wave' => $this->ReadPropertyBoolean('EnableWave')
            ],
            'waveSettings' => [
                'interval' => $this->ReadAttributeInteger('WaveInterval'),
                'stages' => $this->ReadWavePattern()
            ]
        ];
    }

    private function UpdateVisualization(): void
    {
        try {
            if (!$this->UpdateVisualizationValue(json_encode($this->GetVisualizationData(), JSON_THROW_ON_ERROR))) {
                $this->LogError('Visualisierungsupdate', 'Statusnachricht konnte nicht gesendet werden.');
            }
        } catch (Throwable $error) {
            $this->LogError('Visualisierungsupdate fehlgeschlagen', $error);
        }
    }

    private function RefreshStatus(): void
    {
        try {
            $this->PollStatus();
        } catch (Throwable $error) {
            $this->WriteAttributeInteger('StatusUpdatedAt', 0);
            $this->SetStatus(201);
            $this->LogError('Statusabfrage fehlgeschlagen', $error);
        }
        $this->UpdateVisualization();
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
            $this->ReadPropertyBoolean('EnableWave'),
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

        $this->DeleteVariableIfPresent('CommunicationError');
    }

    private function SyncBooleanVariable(string $ident, string $name, bool $enabled, int $position): void
    {
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

    private function ReadParentConnectionID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function PollStatus(): void
    {
        $this->actionFailed = false;
        if (!$this->HasActiveParent()) {
            $this->WriteAttributeInteger('StatusUpdatedAt', 0);
            $this->SetStatus(104);
            $this->LogError('Statusabfrage', 'Modbus-Gateway oder I/O nicht verbunden oder nicht aktiv.');
            return;
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            $this->WriteAttributeInteger('StatusUpdatedAt', 0);
            $this->SetStatus(201);
            return;
        }
        $this->WriteAttributeInteger('StatusRegister', $status);
        $this->WriteAttributeInteger('StatusUpdatedAt', time());
        $this->RecordMainBurnerState(($status & (1 << 2)) !== 0);
        $this->SetBooleanValueIfPresent('FireplaceFault', ($status & 1) !== 0);
        $this->SetBooleanValueIfPresent('SecondBurner', ($status & (1 << 3)) !== 0);
        $this->SetBooleanValueIfPresent('BoostFan', ($status & (1 << 7)) !== 0);
        $this->SetBooleanValueIfPresent('Light', ($status & (1 << 8)) !== 0);
        $this->SetBooleanValueIfPresent('Wave', ($status & (1 << 9)) !== 0);
        $this->SetBooleanValueIfPresent(
            'TemperatureControl',
            (($status >> 13) & 0b11) === 0b10
        );
        $this->SetStatus(102);
        $this->UpdateVisualization();

        if ($this->ReadPropertyBoolean('EnableTemperatureControl')) {
            $roomTemperatureRaw = $this->ReadRegister(40207);
            if ($this->actionFailed) {
                return;
            }
            $roomTemperature = $roomTemperatureRaw / 10;
            $roomTemperatureId = $this->FindVariableId('RoomTemperature');
            if ($roomTemperatureId !== 0) {
                SetValue($roomTemperatureId, $roomTemperature);
            }

            $setpointId = $this->FindVariableId('TemperatureSetpoint');
            if ($setpointId !== 0) {
                $setpointRaw = $this->ReadRegister(40250);
                if ($this->actionFailed) {
                    return;
                }
                SetValue($setpointId, $setpointRaw / 10);
            }
        }

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
        $this->LogIgnitionStep('SetMainBurner gestartet; Sollzustand=' . ($turnOn ? 'EIN' : 'AUS'));
        if ($turnOn) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if ($this->actionFailed) {
                return;
            }
            $this->LogIgnitionStep('Status vor Zündung gelesen', $status);
            $this->IgniteMainBurner();
            return;
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }

        $this->LogIgnitionStep('Ausschaltstatus gelesen', $status);
        if (($status & (1 << 9)) !== 0) {
            $this->LogIgnitionStep('Wave aktiv; sende Kommando 7 zum Beenden');
            $this->WriteRegister(self::COMMAND_REGISTER, 7);
            if ($this->actionFailed) {
                return;
            }
        }
        if ((($status >> 13) & 0b11) === 0b10) {
            $this->LogIgnitionStep('Temperaturregelung aktiv; sende Kommando 8 zum Beenden');
            $this->WriteRegister(self::COMMAND_REGISTER, 8);
            if ($this->actionFailed) {
                return;
            }
            $this->SetBooleanValueIfPresent('TemperatureControl', false);
        }

        $this->LogIgnitionStep('Sende Kommando 3 zum Ausschalten des Hauptbrenners');
        $this->WriteRegister(self::COMMAND_REGISTER, 3);
        if (!$this->actionFailed) {
            $this->WaitForStatusBit(2, false, 'Der Hauptbrenner konnte nicht ausgeschaltet werden.');
        }
    }

    private function IgniteMainBurner(): void
    {
        $this->LogIgnitionStep('Zündablauf gestartet');
        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            $this->LogIgnitionStep('Abbruch: Statusregister konnte nicht gelesen werden');
            return;
        }
        $this->LogIgnitionStep('Erststatus gelesen', $status);
        if (($status & 1) !== 0) {
            $this->LogError('Zündablauf', 'Kaminfehlerbit ist gesetzt; Zündung wird abgebrochen.');
            return;
        }

        if (($status & (1 << 2)) !== 0) {
            $this->LogIgnitionStep('Hauptbrenner ist laut Statusregister bereits eingeschaltet', $status);
            return;
        }
        $this->LogIgnitionStep('Prüfe Zündfreigabe (Statusbit 15)', $status);
        $status = $this->WaitForIgnitionAllowed($status);
        if ($this->actionFailed) {
            $this->LogIgnitionStep('Abbruch beim Warten auf Zündfreigabe');
            return;
        }
        $this->LogIgnitionStep('Zündfreigabe erteilt', $status);
        if (($status & 1) !== 0) {
            $this->LogError('Zündablauf', 'Kaminfehlerbit ist nach der Freigabe gesetzt.');
            return;
        }
        if (($status & (1 << 2)) !== 0) {
            $this->LogIgnitionStep('Hauptbrenner wurde während der Freigabe bereits eingeschaltet', $status);
            return;
        }

        $this->LogIgnitionStep('Sende Kommando 101 zum Starten des Hauptbrenners');
        $this->WriteRegister(self::COMMAND_REGISTER, 101);
        if ($this->actionFailed) {
            $this->LogIgnitionStep('Abbruch: Kommando 101 konnte nicht gesendet werden');
            return;
        }
        $this->LogIgnitionStep('Warte auf Hauptbrenner-Rückmeldung (Statusbit 2); Pilotkommando 100 wird nicht verwendet');
        if (!$this->WaitForStatusBit(2, true, 'Hauptbrenner konnte nicht gezündet werden.')) {
            $this->LogIgnitionStep('Zeitüberschreitung: keine Bestätigung des Hauptbrenners');
            return;
        }

        $this->WriteAttributeInteger('LastIgnitionAt', time());
        $this->LogIgnitionStep('Zündablauf erfolgreich abgeschlossen');
    }

    private function SetSecondBurner(bool $turnOn): void
    {
        if ($turnOn) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if ($this->actionFailed) {
                return;
            }
            if (($status & 1) !== 0) {
                $this->LogError('Zweiter Brenner', 'Kaminfehlerbit gesetzt; zweiter Brenner bleibt ausgeschaltet.');
                return;
            }
            if (($status & (1 << 2)) === 0) {
                $this->IgniteMainBurner();
                if ($this->actionFailed) {
                    return;
                }
            }
            $this->WriteRegister(self::COMMAND_REGISTER, 102);
            if (!$this->actionFailed) {
                $this->WaitForStatusBit(3, true, 'Der zweite Brenner konnte nicht eingeschaltet werden.');
            }
            return;
        }

        $this->WriteRegister(self::COMMAND_REGISTER, 4);
        if (!$this->actionFailed) {
            $this->WaitForStatusBit(3, false, 'Der zweite Brenner konnte nicht ausgeschaltet werden.');
        }
    }

    private function SetAccessory(bool $turnOn, int $onCommand, int $offCommand, string $ident): void
    {
        $this->WriteRegister(self::COMMAND_REGISTER, $turnOn ? $onCommand : $offCommand);
        if ($this->actionFailed) {
            return;
        }
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

    private function SetOperationMode(string $mode): void
    {
        if (!in_array($mode, ['manual', 'temperature', 'wave'], true)) {
            $this->LogError('Betriebsart', 'Ungueltiger Betriebsmodus: ' . $mode);
            return;
        }

        if (
            ($mode === 'temperature' && !$this->ReadPropertyBoolean('EnableTemperatureControl'))
            || ($mode === 'wave' && !$this->ReadPropertyBoolean('EnableWave'))
        ) {
            $this->LogError('Betriebsart', 'Der ausgewaehlte Betriebsmodus ist nicht aktiviert.');
            return;
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }
        if (($status & 1) !== 0) {
            $this->LogError('Betriebsart', 'Kaminfehlerbit gesetzt; Betriebsmodus wurde nicht geändert.');
            return;
        }

        $temperatureState = ($status >> 13) & 0b11;
        $waveActive = ($status & (1 << 9)) !== 0;
        if (($status & (1 << 2)) === 0 && $mode !== 'manual') {
            if (
                ($mode === 'temperature' && $temperatureState === 0b10)
                || ($mode === 'wave' && $waveActive)
            ) {
                return;
            }
            $this->LogError('Betriebsart', 'Moduswechsel erst nach Zündung des Hauptbrenners möglich.');
            return;
        }

        if ($mode !== 'wave' && $waveActive) {
            $this->WriteRegister(self::COMMAND_REGISTER, 7);
            if ($this->actionFailed || !$this->WaitForStatusBit(9, false, 'Wave konnte nicht beendet werden.')) {
                return;
            }
        }

        if ($mode !== 'temperature' && $temperatureState === 0b10) {
            $this->WriteRegister(self::COMMAND_REGISTER, 8);
            if ($this->actionFailed || !$this->WaitForTemperatureControl(false)) {
                return;
            }
        }

        if ($mode === 'temperature') {
            if ($temperatureState === 0b00) {
                $this->LogError('Betriebsart', 'Der Kamin meldet, dass Temperaturregelung nicht verfügbar ist.');
                return;
            }
            if ($temperatureState === 0b11) {
                $this->LogError('Betriebsart', 'Der Kamin meldet einen Fehler der Temperaturregelung.');
                return;
            }
            if ($temperatureState !== 0b10) {
                $setpointId = $this->FindVariableId('TemperatureSetpoint');
                if ($setpointId !== 0) {
                    $setpoint = (float) GetValue($setpointId);
                    $this->WriteRegister(40250, (int) round($setpoint * 10));
                    if ($this->actionFailed) {
                        return;
                    }
                }
                $this->WriteRegister(self::COMMAND_REGISTER, 106);
                if ($this->actionFailed || !$this->WaitForTemperatureControl(true)) {
                    return;
                }
            }
        } elseif ($mode === 'wave' && !$waveActive) {
            $this->WriteRegister(self::COMMAND_REGISTER, 105);
            if (!$this->actionFailed) {
                $this->WaitForStatusBit(9, true, 'Wave konnte nicht aktiviert werden.');
            }
        }
    }

    private function WaitForTemperatureControl(bool $expected): bool
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if ($this->actionFailed) {
                return false;
            }
            $state = ($status >> 13) & 0b11;
            if (($state === 0b10) === $expected) {
                return true;
            }

            if ($state === 0b11) {
                $this->LogError('Temperaturregelung', 'Der Kamin meldet einen Fehler der Temperaturregelung.');
                return false;
            }

            if ($attempt < 9) {
                IPS_Sleep(2000);
            }
        }

        $this->LogError(
            'Temperaturregelung',
            $expected
                ? 'Die Temperaturregelung wurde vom Kamin nicht aktiviert.'
                : 'Die Temperaturregelung wurde vom Kamin nicht beendet.'
        );
        return false;
    }

    private function SaveWaveSettings(string $json): void
    {
        $settings = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->LogError('Wave-Einstellungen', 'JSON konnte nicht gelesen werden: ' . json_last_error_msg());
            return;
        }
        if (!is_array($settings) || !isset($settings['interval'], $settings['stages'])) {
            $this->LogError('Wave-Einstellungen', 'Die Wave-Einstellungen sind unvollständig.');
            return;
        }

        $interval = filter_var($settings['interval'], FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 5 || $interval > 60) {
            $this->LogError('Wave-Einstellungen', 'Das Wave-Intervall muss zwischen 5 und 60 Sekunden liegen.');
            return;
        }
        if (!is_array($settings['stages']) || count($settings['stages']) !== self::WAVE_STAGE_COUNT) {
            $this->LogError('Wave-Einstellungen', 'Das Wave-Muster muss genau 20 Flammenstufen enthalten.');
            return;
        }

        $percentages = [];
        $stageValues = [];
        foreach (array_values($settings['stages']) as $percentage) {
            if (!is_numeric($percentage) || (float) $percentage < 0 || (float) $percentage > 100) {
                $this->LogError('Wave-Einstellungen', 'Jede Wave-Stufe muss zwischen 0 und 100 Prozent liegen.');
                return;
            }
            $percentage = (int) round((float) $percentage);
            $stage = (int) round($percentage * 14 / 100) + 1;
            $stageValues[] = $stage;
            $percentages[] = (int) round(($stage - 1) * 100 / 14);
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }
        if (($status & (1 << 2)) === 0 || ($status & (1 << 9)) === 0) {
            $this->LogError('Wave-Einstellungen', 'Speichern ist nur bei eingeschaltetem Kamin im Wave-Modus möglich.');
            return;
        }

        $registers = [(int) $interval];
        for ($index = 0; $index < self::WAVE_STAGE_COUNT; $index += 2) {
            $registers[] = ($stageValues[$index + 1] << 8) | $stageValues[$index];
        }
        $this->WriteMultipleRegisters(self::WAVE_INTERVAL_REGISTER, $registers);
        if ($this->actionFailed) {
            return;
        }

        $this->WriteAttributeInteger('WaveInterval', (int) $interval);
        $this->WriteAttributeString('WavePattern', json_encode($percentages, JSON_THROW_ON_ERROR));
    }

    private function ReadWavePattern(): array
    {
        $pattern = json_decode($this->ReadAttributeString('WavePattern'), true);
        if (!is_array($pattern) || count($pattern) !== self::WAVE_STAGE_COUNT) {
            return array_fill(0, self::WAVE_STAGE_COUNT, 50);
        }

        return array_values(array_map('intval', $pattern));
    }

    private function SetFlameHeight(int $height): void
    {
        if ($height < 0 || $height > 100) {
            $this->LogError('Flammenhöhe', 'Die Flammenhöhe muss zwischen 0 und 100 Prozent liegen.');
            return;
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }
        $isOn = ($status & (1 << 2)) !== 0;
        $this->RecordMainBurnerState($isOn);
        if (!$isOn) {
            $this->LogError('Flammenhöhe', 'Kann erst nach erfolgreicher Zündung gesetzt werden.');
            return;
        }
        if (($status & (1 << 9)) !== 0) {
            $this->LogError('Flammenhöhe', 'Im Wave-Modus kann die Flammenhöhe nicht manuell gesetzt werden.');
            return;
        }
        if ((($status >> 13) & 0b11) === 0b10) {
            $this->LogError('Flammenhöhe', 'Bei aktiver Temperaturregelung kann die Flammenhöhe nicht manuell gesetzt werden.');
            return;
        }
        if (($status & (1 << 10)) !== 0) {
            $this->LogError('Flammenhöhe', 'Der Kamin sperrt derzeit Änderungen der Flammenhöhe.');
            return;
        }

        $now = time();
        if ($now - $this->ReadAttributeInteger('LastIgnitionAt') < 10) {
            $this->LogError('Flammenhöhe', 'Nach der Zündung muss vor der Änderung 10 Sekunden gewartet werden.');
            return;
        }
        if ($now - $this->ReadAttributeInteger('LastFlameWrite') < 10) {
            $this->LogError('Flammenhöhe', 'Die Flammenhöhe darf nur einmal innerhalb von 10 Sekunden geschrieben werden.');
            return;
        }

        $this->WriteRegister(40201, $height);
        if ($this->actionFailed) {
            return;
        }
        $this->WriteAttributeInteger('LastFlameWrite', $now);
        $variableId = $this->FindVariableId('FlameHeight');
        if ($variableId !== 0) {
            SetValue($variableId, $height);
        }
    }

    private function SetTemperatureSetpoint(float $temperature): void
    {
        if ($temperature < 5 || $temperature > 35 || abs(($temperature * 2) - round($temperature * 2)) > 0.001) {
            $this->LogError('Solltemperatur', 'Die Solltemperatur muss zwischen 5 und 35 °C in 0,5-°C-Schritten liegen.');
            return;
        }
        $this->WriteRegister(40250, (int) round($temperature * 10));
        if ($this->actionFailed) {
            return;
        }
        $variableId = $this->FindVariableId('TemperatureSetpoint');
        if ($variableId !== 0) {
            SetValue($variableId, $temperature);
        }
    }

    private function WaitForStatusBit(int $bit, bool $expected, string $errorMessage): bool
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if ($this->actionFailed) {
                return false;
            }
            $this->SendDebug(
                'Statusrückmeldung',
                sprintf('Warte auf Bit %d=%d, Versuch %d/10; Status 40203=%d', $bit, $expected ? 1 : 0, $attempt + 1, $status),
                0
            );
            if ((($status & (1 << $bit)) !== 0) === $expected) {
                return true;
            }

            if ($attempt < 9) {
                IPS_Sleep(2000);
            }
        }

        $this->LogError('Statusrückmeldung', $errorMessage);
        return false;
    }

    private function WaitForIgnitionAllowed(int $status): int
    {
        for ($attempt = 0; ($status & (1 << 15)) !== 0; $attempt++) {
            $this->SendDebug(
                'Zündfreigabe',
                sprintf('Freigabe fehlt, Versuch %d/60; Status 40203=%d', $attempt + 1, $status),
                0
            );
            if (($status & 1) !== 0) {
                $this->LogError('Zündfreigabe', 'Kaminfehlerbit gesetzt; Zündung wird abgebrochen.');
                return -1;
            }
            if ($attempt >= 60) {
                $this->LogError('Zündfreigabe', 'Der Kamin hat die Zündung innerhalb von fünf Minuten nicht freigegeben.');
                return -1;
            }

            IPS_Sleep(5000);
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if ($this->actionFailed) {
                return -1;
            }
        }

        return $status;
    }

    private function RequireFeature(string $property, string $featureName): void
    {
        if (!$this->ReadPropertyBoolean($property)) {
            $this->LogError('Funktionsprüfung', 'Die Funktion "' . $featureName . '" ist in der Konfiguration deaktiviert.');
        }
    }

    private function CanSetFlameHeight(): bool
    {
        $statusUpdatedAt = $this->ReadAttributeInteger('StatusUpdatedAt');
        $status = $this->ReadAttributeInteger('StatusRegister');
        $now = time();

        return $statusUpdatedAt > 0
            && $now - $statusUpdatedAt < 15
            && $this->HasActiveParent()
            && ($status & 1) === 0
            && ($status & (1 << 2)) !== 0
            && ($status & (1 << 9)) === 0
            && (($status >> 13) & 0b11) !== 0b10
            && ($status & (1 << 10)) === 0
            && $now - $this->ReadAttributeInteger('LastIgnitionAt') >= 10
            && $now - $this->ReadAttributeInteger('LastFlameWrite') >= 10;
    }

    private function ReadRegister(int $register): int
    {
        // DRU expects the documented register number as-is, not a 40001-relative offset.
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::MODBUS_GATEWAY_DATA_ID,
            'Function' => 3,
            'Address' => $register,
            'Quantity' => 1,
            'Data' => ''
        ], JSON_THROW_ON_ERROR));

        if (!is_string($response) || $response === '') {
            $this->LogError('Modbus-Lesen', 'Keine gültige Antwort für Register ' . $register . '.');
            return -1;
        }

        if (strlen($response) < 4) {
            $this->LogError('Modbus-Lesen', 'Unerwartetes Antwortformat für Register ' . $register . '.');
            return -1;
        }

        if (ord($response[0]) !== 3 || ord($response[1]) !== 2 || strlen($response) !== 4) {
            $this->LogError('Modbus-Lesen', 'Ungültige FC3-Antwort für Register ' . $register . ': ' . bin2hex($response));
            return -1;
        }
        $value = unpack('nvalue', substr($response, 2, 2));
        if (!is_array($value) || !isset($value['value'])) {
            $this->LogError('Modbus-Lesen', 'Der Wert von Register ' . $register . ' ist ungültig.');
            return -1;
        }

        return (int) $value['value'];
    }

    private function WriteRegister(int $register, int $value): bool
    {
        $this->SendDebug('Modbus TX', sprintf('FC6 Register=%d Wert=%d Daten=%s', $register, $value, bin2hex(pack('n', $value))), 0);
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::MODBUS_GATEWAY_DATA_ID,
            'Function' => 6,
            'Address' => $register,
            'Quantity' => 1,
            'Data' => bin2hex(pack('n', $value))
        ], JSON_THROW_ON_ERROR));

        $this->SendDebug('Modbus RX', is_string($response) ? bin2hex($response) : get_debug_type($response), 0);
        if ($response === false || $response === null || $response === '') {
            $this->LogError('Modbus-Schreiben', 'Keine gültige Antwort für Register ' . $register . ', Wert ' . $value . '.');
            return false;
        }

        return true;
    }

    private function WriteMultipleRegisters(int $register, array $values): void
    {
        if (count($values) === 0 || count($values) > 123) {
            $this->LogError('Modbus-Sammelschreiben', 'Ungültige Anzahl Register: ' . count($values) . '.');
            return;
        }

        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::MODBUS_GATEWAY_DATA_ID,
            'Function' => 16,
            'Address' => $register,
            'Quantity' => count($values),
            'Data' => bin2hex(pack('n*', ...$values))
        ], JSON_THROW_ON_ERROR));

        if ($response === false) {
            $this->LogError('Modbus-Sammelschreiben', 'Wave-Einstellungen konnten nicht übertragen werden.');
        }
    }

    private function LogError(string $context, Throwable|string $error): void
    {
        $this->actionFailed = true;
        $detail = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $message = $context . ': ' . $detail;
        $this->SendDebug('Fehler', $message, 0);
        IPS_LogMessage('DRU Kamin', $message);
    }

    private function LogIgnitionStep(string $message, ?int $status = null): void
    {
        if ($status !== null) {
            $message .= sprintf(
                ' (40203=%d, Bit 0 Fehler=%d, Bit 1 Pilot=%d, Bit 2 Hauptbrenner=%d, Bit 15 Sperre=%d)',
                $status,
                ($status & 1) !== 0 ? 1 : 0,
                ($status & (1 << 1)) !== 0 ? 1 : 0,
                ($status & (1 << 2)) !== 0 ? 1 : 0,
                ($status & (1 << 15)) !== 0 ? 1 : 0
            );
        }
        $this->SendDebug('Zündablauf', $message, 0);
        IPS_LogMessage('DRU Kamin Zündablauf', $message);
    }

    private function AbortIgnition(string $reason): void
    {
        $this->LogIgnitionStep('Sicherheitsabbruch: ' . $reason . '; sende Kommando 3');
        if ($this->WriteRegister(self::COMMAND_REGISTER, 3)) {
            $this->LogIgnitionStep('Kommando 3 für Zündabbruch gesendet');
        }
    }
}
