<?php

declare(strict_types=1);

class DRUKamin extends IPSModule
{
    private const MODBUS_GATEWAY_MODULE_ID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const MODBUS_GATEWAY_DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    private const STATUS_REGISTER = 40203;
    private const COMMAND_REGISTER = 40200;
    private const WAVE_INTERVAL_REGISTER = 40420;
    private const WAVE_STAGE_COUNT = 20;

    public function Create()
    {
        parent::Create();

        $this->RequireParent(self::MODBUS_GATEWAY_MODULE_ID);

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

        if ($this->ReadParentConnectionID() !== 0) {
            $this->SetTimerInterval('PollStatus', 60000);
            try {
                $this->PollStatus();
            } catch (Throwable $error) {
                $this->LogError('Statusabfrage nach ApplyChanges fehlgeschlagen', $error);
            }
        } else {
            $this->SetTimerInterval('PollStatus', 0);
            IPS_LogMessage('DRU Kamin', 'Kein Modbus-Gateway verbunden.');
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'PollStatus') {
            try {
                $this->PollStatus();
            } catch (Throwable $error) {
                $this->LogError('Statusabfrage fehlgeschlagen', $error);
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
                    $this->SetOperationMode((bool) $Value ? 'wave' : 'manual');
                    break;
                case 'TemperatureControl':
                    $this->RequireFeature('EnableTemperatureControl', 'Temperaturregelung');
                    $this->SetOperationMode((bool) $Value ? 'temperature' : 'manual');
                    break;
                case 'OperationMode':
                    $this->SetOperationMode((string) $Value);
                    break;
                case 'FlameHeight':
                    $this->RequireFeature('EnableFireplace', 'Kamin');
                    $this->SetFlameHeight((int) $Value);
                    break;
                case 'TemperatureSetpoint':
                    $this->RequireFeature('EnableTemperatureControl', 'Temperaturregelung');
                    $this->SetTemperatureSetpoint((float) $Value);
                    break;
                case 'SaveWaveSettings':
                    $this->RequireFeature('EnableWave', 'Wave');
                    $this->SaveWaveSettings((string) $Value);
                    break;
                default:
                    throw new Exception('Ungueltiger Ident: ' . $Ident);
            }

            $this->PollStatus();
        } catch (Throwable $error) {
            $this->LogError('Aktion ' . $Ident . ' fehlgeschlagen', $error);
            try {
                $this->PollStatus();
            } catch (Throwable $statusError) {
                $this->LogError('Statuswiederherstellung nach Aktion fehlgeschlagen', $statusError);
            }
        }
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
            'actions' => []
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
            'statusAvailable' => $this->ReadAttributeInteger('StatusUpdatedAt') > 0
                && (time() - $this->ReadAttributeInteger('StatusUpdatedAt')) <= 120
                && $this->ReadParentConnectionID() !== 0,
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

    private function ReadParentConnectionID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function PollStatus(): void
    {
        if ($this->ReadParentConnectionID() === 0) {
            throw new Exception('Kein Modbus-Gateway verbunden.');
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
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

        if ($this->ReadPropertyBoolean('EnableTemperatureControl')) {
            $roomTemperature = $this->ReadRegister(40207) / 10;
            $roomTemperatureId = $this->FindVariableId('RoomTemperature');
            if ($roomTemperatureId !== 0) {
                SetValue($roomTemperatureId, $roomTemperature);
            }

            $setpointId = $this->FindVariableId('TemperatureSetpoint');
            if ($setpointId !== 0) {
                SetValue($setpointId, $this->ReadRegister(40250) / 10);
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
        if ($turnOn) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if ((($status >> 13) & 0b11) === 0b10) {
                throw new Exception('Im Temperaturmodus wird der Brenner vom Kamin geregelt.');
            }
        }

        if (!$turnOn) {
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if (($status & (1 << 3)) !== 0) {
                $this->WriteRegister(self::COMMAND_REGISTER, 4);
                $this->WaitForStatusBit(3, false, 'Der zweite Brenner konnte nicht ausgeschaltet werden.');
            }
            if (($status & (1 << 9)) !== 0) {
                $this->WriteRegister(self::COMMAND_REGISTER, 7);
            }
            if ((($status >> 13) & 0b11) === 0b10) {
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
        $status = $this->WaitForIgnitionAllowed($status);
        if (($status & 1) !== 0) {
            throw new Exception('Der Kamin meldet einen Fehler. Die Zuendung wurde abgebrochen.');
        }
        if (($status & (1 << 2)) !== 0) {
            return;
        }

        try {
            if (($status & (1 << 1)) === 0) {
                $this->WriteRegister(self::COMMAND_REGISTER, 100);
                $this->WaitForStatusBit(1, true, 'Die Zuendung der Pilotflamme ist fehlgeschlagen.');
            }
            $status = $this->ReadRegister(self::STATUS_REGISTER);
            if (($status & 1) !== 0) {
                throw new Exception('Der Kamin meldet einen Fehler. Die Zuendung wurde abgebrochen.');
            }
            if (($status & (1 << 15)) !== 0) {
                throw new Exception('Der Kamin meldet, dass die Zuendung derzeit nicht erlaubt ist.');
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

    private function SetOperationMode(string $mode): void
    {
        if (!in_array($mode, ['manual', 'temperature', 'wave'], true)) {
            throw new Exception('Ungueltiger Betriebsmodus: ' . $mode);
        }

        if (
            ($mode === 'temperature' && !$this->ReadPropertyBoolean('EnableTemperatureControl'))
            || ($mode === 'wave' && !$this->ReadPropertyBoolean('EnableWave'))
        ) {
            throw new Exception('Der ausgewaehlte Betriebsmodus ist nicht aktiviert.');
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if (($status & 1) !== 0) {
            throw new Exception('Der Kamin meldet einen Fehler. Der Betriebsmodus wurde nicht geaendert.');
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
            throw new Exception('Der Betriebsmodus kann erst nach dem Zuenden des Hauptbrenners gewechselt werden.');
        }

        if ($mode !== 'wave' && $waveActive) {
            $this->WriteRegister(self::COMMAND_REGISTER, 7);
            $this->WaitForStatusBit(9, false, 'Wave konnte nicht beendet werden.');
        }

        if ($mode !== 'temperature' && $temperatureState === 0b10) {
            $this->WriteRegister(self::COMMAND_REGISTER, 8);
            $this->WaitForTemperatureControl(false);
        }

        if ($mode === 'temperature') {
            if ($temperatureState === 0b00) {
                throw new Exception('Der Kamin meldet, dass Temperaturregelung nicht verfuegbar ist.');
            }
            if ($temperatureState === 0b11) {
                throw new Exception('Der Kamin meldet einen Fehler der Temperaturregelung.');
            }
            if ($temperatureState !== 0b10) {
                $setpointId = $this->FindVariableId('TemperatureSetpoint');
                if ($setpointId !== 0) {
                    $setpoint = (float) GetValue($setpointId);
                    $this->WriteRegister(40250, (int) round($setpoint * 10));
                }
                $this->WriteRegister(self::COMMAND_REGISTER, 106);
                $this->WaitForTemperatureControl(true);
            }
        } elseif ($mode === 'wave' && !$waveActive) {
            $this->WriteRegister(self::COMMAND_REGISTER, 105);
            $this->WaitForStatusBit(9, true, 'Wave konnte nicht aktiviert werden.');
        }
    }

    private function WaitForTemperatureControl(bool $expected): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $state = ($this->ReadRegister(self::STATUS_REGISTER) >> 13) & 0b11;
            if (($state === 0b10) === $expected) {
                return;
            }

            if ($state === 0b11) {
                throw new Exception('Der Kamin meldet einen Fehler der Temperaturregelung.');
            }

            if ($attempt < 9) {
                IPS_Sleep(2000);
            }
        }

        throw new Exception(
            $expected
                ? 'Die Temperaturregelung wurde vom Kamin nicht aktiviert.'
                : 'Die Temperaturregelung wurde vom Kamin nicht beendet.'
        );
    }

    private function SaveWaveSettings(string $json): void
    {
        $settings = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings) || !isset($settings['interval'], $settings['stages'])) {
            throw new Exception('Die Wave-Einstellungen sind unvollstaendig.');
        }

        $interval = filter_var($settings['interval'], FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 5 || $interval > 60) {
            throw new Exception('Das Wave-Intervall muss zwischen 5 und 60 Sekunden liegen.');
        }
        if (!is_array($settings['stages']) || count($settings['stages']) !== self::WAVE_STAGE_COUNT) {
            throw new Exception('Das Wave-Muster muss genau 20 Flammenstufen enthalten.');
        }

        $percentages = [];
        $stageValues = [];
        foreach (array_values($settings['stages']) as $percentage) {
            if (!is_numeric($percentage) || (float) $percentage < 0 || (float) $percentage > 100) {
                throw new Exception('Jede Wave-Stufe muss zwischen 0 und 100 Prozent liegen.');
            }
            $percentage = (int) round((float) $percentage);
            $stage = (int) round($percentage * 14 / 100) + 1;
            $stageValues[] = $stage;
            $percentages[] = (int) round(($stage - 1) * 100 / 14);
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if (($status & (1 << 2)) === 0 || ($status & (1 << 9)) === 0) {
            throw new Exception('Wave-Einstellungen koennen nur bei eingeschaltetem Kamin im Wave-Modus gespeichert werden.');
        }

        $registers = [(int) $interval];
        for ($index = 0; $index < self::WAVE_STAGE_COUNT; $index += 2) {
            $registers[] = ($stageValues[$index + 1] << 8) | $stageValues[$index];
        }
        $this->WriteMultipleRegisters(self::WAVE_INTERVAL_REGISTER, $registers);

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
            throw new Exception('Die Flammenhoehe muss zwischen 0 und 100 Prozent liegen.');
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        $isOn = ($status & (1 << 2)) !== 0;
        $this->RecordMainBurnerState($isOn);
        if (!$isOn) {
            throw new Exception('Die Flammenhoehe kann erst nach erfolgreicher Zuendung gesetzt werden.');
        }
        if (($status & (1 << 9)) !== 0) {
            throw new Exception('Die Flammenhoehe kann im Wave-Modus nicht manuell gesetzt werden.');
        }
        if ((($status >> 13) & 0b11) === 0b10) {
            throw new Exception('Die Flammenhoehe kann bei aktiver Temperaturregelung nicht manuell gesetzt werden.');
        }
        if (($status & (1 << 10)) !== 0) {
            throw new Exception('Der Kamin meldet, dass die Flammenhoehe momentan nicht veraendert werden kann.');
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

    private function WaitForIgnitionAllowed(int $status): int
    {
        for ($attempt = 0; ($status & (1 << 15)) !== 0; $attempt++) {
            if (($status & 1) !== 0) {
                throw new Exception('Der Kamin meldet einen Fehler. Die Zuendung wurde abgebrochen.');
            }
            if ($attempt >= 60) {
                throw new Exception('Der Kamin hat die Zuendung innerhalb von fuenf Minuten nicht freigegeben.');
            }

            IPS_Sleep(5000);
            $status = $this->ReadRegister(self::STATUS_REGISTER);
        }

        return $status;
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
            throw new Exception('Keine gueltige Antwort auf Modbus-Leseregister ' . $register . '.');
        }

        if (strlen($response) < 4) {
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
            'Address' => $register,
            'Quantity' => 1,
            'Data' => bin2hex(pack('n', $value))
        ], JSON_THROW_ON_ERROR));

        if ($response === false) {
            throw new Exception('Keine gueltige Antwort auf Modbus-Schreibregister ' . $register . '.');
        }
    }

    private function WriteMultipleRegisters(int $register, array $values): void
    {
        if (count($values) === 0 || count($values) > 123) {
            throw new Exception('Ungueltige Anzahl von Modbus-Registern fuer einen Sammelschreibvorgang.');
        }

        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::MODBUS_GATEWAY_DATA_ID,
            'Function' => 16,
            'Address' => $register,
            'Quantity' => count($values),
            'Data' => bin2hex(pack('n*', ...$values))
        ], JSON_THROW_ON_ERROR));

        if ($response === false) {
            throw new Exception('Die Wave-Einstellungen konnten nicht an das Modbus-Gateway gesendet werden.');
        }
    }

    private function RequireFeature(string $property, string $featureName): void
    {
        if (!$this->ReadPropertyBoolean($property)) {
            throw new Exception('Die Funktion "' . $featureName . '" ist in der Konfiguration deaktiviert.');
        }
    }

    private function CanSetFlameHeight(): bool
    {
        $statusUpdatedAt = $this->ReadAttributeInteger('StatusUpdatedAt');
        $status = $this->ReadAttributeInteger('StatusRegister');
        $now = time();

        return $statusUpdatedAt > 0
            && $now - $statusUpdatedAt <= 120
            && ($status & 1) === 0
            && ($status & (1 << 2)) !== 0
            && ($status & (1 << 9)) === 0
            && (($status >> 13) & 0b11) !== 0b10
            && ($status & (1 << 10)) === 0
            && $now - $this->ReadAttributeInteger('LastIgnitionAt') >= 10
            && $now - $this->ReadAttributeInteger('LastFlameWrite') >= 10;
    }

    private function LogError(string $context, Throwable $error): void
    {
        $message = $context . ': ' . $error->getMessage();
        $this->SendDebug('Fehler', $message, 0);
        IPS_LogMessage('DRU Kamin', $message);
    }
}
