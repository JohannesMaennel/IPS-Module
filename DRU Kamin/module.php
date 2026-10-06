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
        $this->RegisterAttributeInteger('ResetPendingUntil', 0);
        $this->RegisterAttributeInteger('WaveInterval', 10);
        $this->RegisterAttributeInteger('WaveUpdatedAt', 0);
        $this->RegisterAttributeInteger('WaveSyncAttemptAt', 0);
        $this->RegisterAttributeInteger('OffTimerDuration', 3600);
        $this->RegisterAttributeInteger('OffTimerDeadline', 0);
        $this->RegisterAttributeInteger('OffTimerRemaining', 0);
        $this->RegisterAttributeInteger('OffTimerNextAttempt', 0);
        $this->RegisterAttributeString('OffTimerState', 'idle');
        $this->RegisterAttributeString('OffTimerError', '');
        $this->RegisterAttributeString('OperationJob', '');
        $this->RegisterAttributeString('ActionError', '');
        $this->RegisterAttributeString('WaveResult', 'idle');
        $this->RegisterAttributeString('WaveRequestId', '');
        $this->RegisterAttributeInteger('WaveReloadPending', 0);
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
        $this->RegisterTimer('OffTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "OffTimerTick", true);');
        $this->RegisterTimer('Operation', 0, 'IPS_RequestAction($_IPS["TARGET"], "OperationTick", true);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $lock = 'DRUKamin.Actions.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            $this->LogError('Konfiguration', 'Geräteauftrag läuft; Änderungen bitte erneut übernehmen.');
            $this->UpdateVisualization();
            return;
        }
        try {
            $this->RegisterProfiles();
            $this->SynchronizeVariables();
            if ($this->ReadAttributeString('OperationJob') !== '') {
                $this->WriteAttributeString('OperationJob', '');
                $this->LogError('Aktion', 'Offener Auftrag beim Wiederanlauf abgebrochen; kein automatisches erneutes Zünden.');
            }
            $this->SetTimerInterval('Operation', 0);
            $this->WriteAttributeInteger('WaveUpdatedAt', 0);
            $this->WriteAttributeInteger('WaveSyncAttemptAt', 0);
            $this->SetTimerInterval('PollStatus', 5000);
            $this->ScheduleOffTimer();
            $this->HandleAction('PollStatus', true);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? 0) === KR_READY) {
            $this->RequestAction('KernelReady', true);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $lock = 'DRUKamin.Actions.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, $Ident === 'PollStatus' || $Ident === 'OperationTick' ? 0 : 1000)) {
            if ($Ident === 'PollStatus' || $Ident === 'OperationTick') {
                $this->SendDebug('Polling', $Ident . ' übersprungen: Modbus-Schritt läuft; Statusalter='
                    . (time() - $this->ReadAttributeInteger('StatusUpdatedAt')) . ' s.', 0);
                return;
            }
            $this->LogError(str_starts_with($Ident, 'OffTimer') ? 'Austimer' : 'Aktion ' . $Ident,
                'Eine andere Kaminaktion läuft; bitte erneut versuchen.');
            $this->UpdateVisualization();
            return;
        }
        try {
            $this->HandleAction($Ident, $Value);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function HandleAction($Ident, $Value): void
    {
        $this->actionFailed = false;

        if ($Ident === 'OperationTick') {
            $this->ProcessOperation();
            $this->UpdateVisualization();
            return;
        }

        if ($Ident === 'OffTimerAction' || $Ident === 'OffTimerTick' || $Ident === 'KernelReady') {
            try {
                if ($Ident === 'KernelReady' && $this->ReadAttributeString('OperationJob') !== '') {
                    $this->WriteAttributeString('OperationJob', '');
                    $this->SetTimerInterval('Operation', 0);
                    $this->LogError('Aktion', 'Offener Geräteauftrag nach Kernel-Wiederanlauf verworfen; bereits gesendete Befehle bleiben wirksam.');
                    $this->actionFailed = false;
                }
                if ($Ident === 'OffTimerTick' || $Ident === 'KernelReady') {
                    $this->ProcessOffTimer();
                } else {
                    $this->ChangeOffTimer((string) $Value);
                }
            } catch (Throwable $error) {
                $this->LogError('Austimer', $error);
            } finally {
                $this->ScheduleOffTimer();
                $this->UpdateVisualization();
            }
            return;
        }

        if ($Ident === 'PollStatus') {
            $started = microtime(true);
            $this->RefreshStatus();
            $this->SendDebug('Polling', sprintf('Zyklus %.1f ms, Statusalter=%d s',
                (microtime(true) - $started) * 1000, time() - $this->ReadAttributeInteger('StatusUpdatedAt')), 0);
            return;
        }

        $isOff = $Ident === 'Fireplace' && !(bool) $Value;
        if ($isOff && $this->ReadAttributeString('OperationJob') !== '') {
            $this->LogError('Aktion', 'Offener Auftrag durch AUS abgebrochen; bereits bestätigte Writes bleiben am Gerät.');
            $this->WriteAttributeString('OperationJob', '');
            $this->SetTimerInterval('Operation', 0);
            $this->WriteAttributeInteger('WaveReloadPending', $this->ReadPropertyBoolean('EnableWave') ? 1 : 0);
        } elseif ($this->ReadAttributeString('OperationJob') !== '') {
            $job = $this->ReadOperation();
            if (($job['name'] ?? '') === 'WaveReadback') {
                $this->WriteAttributeString('OperationJob', '');
                $this->WriteAttributeInteger('WaveReloadPending', 1);
                $this->SetTimerInterval('Operation', 0);
            } else {
                $this->LogError('Aktion', 'Ein Geräteauftrag wartet bereits auf Rückmeldung.');
                $this->UpdateVisualization();
                return;
            }
        }
        $this->WriteAttributeString('ActionError', '');
        $this->actionFailed = false;

        if (!$this->HasActiveParent()) {
            $this->LogError('Aktion ' . $Ident, 'Modbus-Gateway oder I/O ist nicht aktiv.');
            $this->RefreshStatus();
            return;
        }

        try {
            switch ($Ident) {
                case 'ResetFireplace':
                    $this->ResetFireplace();
                    break;
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
                    $request = json_decode((string) $Value, true);
                    $requestId = is_array($request) && is_string($request['requestId'] ?? null)
                        ? $request['requestId'] : '';
                    if (is_array($request) && isset($request['requestId'])
                        && (!is_string($request['requestId']) || strlen($requestId) > 128)) {
                        $this->LogError('Wave-Einstellungen', 'Ungültige Auftragskennung.');
                        $this->WriteAttributeString('WaveResult', 'failed');
                        break;
                    }
                    $this->WriteAttributeString('WaveRequestId', $requestId);
                    $this->WriteAttributeString('WaveResult', 'pending');
                    $this->RequireFeature('EnableWave', 'Wave');
                    if (!$this->actionFailed) {
                        $this->SaveWaveSettings((string) $Value);
                    }
                    if ($this->actionFailed && $this->ReadAttributeString('OperationJob') === '') {
                        $this->WriteAttributeString('WaveResult', 'failed');
                    }
                    break;
                case 'ReloadWaveSettings':
                    $this->RequireFeature('EnableWave', 'Wave');
                    if (!$this->actionFailed) {
                        $this->SynchronizeWaveSettings();
                    }
                    break;
                default:
                    $this->LogError('RequestAction', 'Ungueltiger Ident: ' . $Ident);
                    break;
            }

        } catch (Throwable $error) {
            $this->LogError('Aktion ' . $Ident . ' fehlgeschlagen', $error);
        }
        $this->UpdateVisualization();
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
        $presets = file_get_contents(__DIR__ . '/UI/wave-presets.json');

        if (!is_string($html) || !is_string($css) || !is_string($js) || !is_string($presets)) {
            $this->LogError('Visualisierung', 'Die HTML-SDK-Dateien im UI-Ordner konnten nicht geladen werden.');
            return '';
        }
        try {
            $presetData = json_decode($presets, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($presetData) || count($presetData) !== 3) {
                $this->LogError('Visualisierung', 'Die Wave-Vorlagen müssen drei Profile enthalten.');
                return '';
            }
            $ids = [];
            foreach ($presetData as $preset) {
                if (!is_array($preset) || !isset($preset['id'], $preset['name'], $preset['interval'], $preset['stages'])
                    || !is_string($preset['id']) || !is_string($preset['name'])
                    || in_array($preset['id'], $ids, true)
                    || !is_int($preset['interval']) || $preset['interval'] < 5 || $preset['interval'] > 60
                    || !is_array($preset['stages']) || count($preset['stages']) !== self::WAVE_STAGE_COUNT) {
                    $this->LogError('Wave-Vorlagen', 'Eine Wave-Vorlage hat ein ungültiges Format.');
                    return '';
                }
                foreach ($preset['stages'] as $stage) {
                    if (!is_int($stage) || $stage < 0 || $stage > 100) {
                        $this->LogError('Wave-Vorlagen', 'Vorlagenstufen müssen Ganzzahlen zwischen 0 und 100 sein.');
                        return '';
                    }
                }
                $ids[] = $preset['id'];
            }
            $presets = json_encode($presetData, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (Throwable $error) {
            $this->LogError('Wave-Vorlagen', $error);
            return '';
        }

        return str_replace(
            ['{{CSS}}', '{{JS}}', '{{DATA}}', '{{PRESETS}}'],
            [$css, $js, json_encode($this->GetVisualizationData(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $presets],
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
            'resetPending' => $this->ReadAttributeInteger('ResetPendingUntil') > time(),
            'actionPending' => $this->ReadAttributeString('OperationJob') !== ''
                && ($this->ReadOperation()['name'] ?? '') !== 'WaveReadback',
            'pendingAction' => $this->ReadOperation()['name'] ?? '',
            'actionError' => $this->ReadAttributeString('ActionError'),
            'waveResult' => $this->ReadAttributeString('WaveResult'),
            'waveRequestId' => $this->ReadAttributeString('WaveRequestId'),
            'canSetFlameHeight' => $this->CanSetFlameHeight(),
            'offTimer' => [
                'state' => $this->ReadAttributeString('OffTimerState'),
                'duration' => $this->ReadAttributeInteger('OffTimerDuration'),
                'deadline' => $this->ReadAttributeInteger('OffTimerDeadline'),
                'remaining' => $this->ReadAttributeInteger('OffTimerRemaining'),
                'serverTime' => time(),
                'error' => $this->ReadAttributeString('OffTimerError')
            ],
            'features' => [
                'temperatureControl' => $this->ReadPropertyBoolean('EnableTemperatureControl'),
                'wave' => $this->ReadPropertyBoolean('EnableWave')
            ],
            'waveSettings' => [
                'available' => $this->ReadPropertyBoolean('EnableWave')
                    && $this->ReadAttributeInteger('WaveUpdatedAt') > 0
                    && $this->HasActiveParent(),
                'interval' => $this->ReadAttributeInteger('WaveInterval'),
                'stages' => $this->ReadCachedWavePattern()
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

    private function ChangeOffTimer(string $json): void
    {
        $input = json_decode($json, true);
        if (!is_array($input) || !isset($input['action']) || !is_string($input['action'])) {
            $this->LogError('Austimer', 'Ungültiger Timerauftrag.');
            return;
        }
        $action = $input['action'];
        if ($action === 'pause' || $action === 'delete') {
            $job = $this->ReadOperation();
            if (($job['timerOrigin'] ?? false) === true) {
                $this->WriteAttributeString('OperationJob', '');
                $this->SetTimerInterval('Operation', 0);
                $this->SendDebug('Austimer', 'Weitere Abschaltschritte abgebrochen; bereits gesendete Befehle bleiben wirksam.', 0);
            }
            $error = $this->ReadAttributeString('ActionError');
            if (str_starts_with($error, 'Austimer') || str_starts_with($error, 'Shutdown:')) {
                $this->WriteAttributeString('ActionError', '');
            }
        }
        if ($action === 'delete') {
            $this->ClearOffTimer();
            return;
        }
        if ($action === 'pause') {
            $state = $this->ReadAttributeString('OffTimerState');
            if ($state === 'running' || $state === 'stopping') {
                $this->WriteAttributeInteger('OffTimerRemaining', max(0, $this->ReadAttributeInteger('OffTimerDeadline') - time()));
                $this->WriteAttributeInteger('OffTimerDeadline', 0);
                $this->WriteAttributeString('OffTimerState', 'paused');
                $this->WriteAttributeString('OffTimerError', '');
            }
            return;
        }
        if ($action !== 'start' && $action !== 'reset') {
            $this->LogError('Austimer', 'Unbekannte Timeraktion: ' . $action);
            return;
        }
        if (($this->ReadOperation()['name'] ?? '') === 'Shutdown') {
            $this->LogError('Austimer', 'Eine Abschaltung läuft bereits; zuerst pausieren oder ihren Abschluss abwarten.');
            return;
        }
        $duration = $input['duration'] ?? null;
        if (!is_int($duration) || $duration < 60 || $duration > 86400 || $duration % 60 !== 0) {
            $this->LogError('Austimer', 'Dauer muss zwischen 1 Minute und 24 Stunden in ganzen Minuten liegen.');
            return;
        }
        if (!$this->ReadPropertyBoolean('EnableFireplace')) {
            $this->LogError('Austimer', 'Die Kaminfunktion ist deaktiviert.');
            return;
        }
        if (!$this->HasActiveParent()) {
            $this->LogError('Austimer', 'Start/Reset benötigt eine aktive Modbus-Verbindung.');
            return;
        }
        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }
        if (($status & 1) !== 0 || (($status & (4 | 512)) === 0 && (($status >> 13) & 3) !== 2)) {
            $this->LogError('Austimer', 'Start/Reset benötigt einen fehlerfreien eingeschalteten Kamin oder aktive Automatik.');
            return;
        }
        $state = $this->ReadAttributeString('OffTimerState');
        if ($action === 'start' && $state === 'running') {
            return;
        }
        $remaining = $action === 'start' && $state === 'paused'
            && $duration === $this->ReadAttributeInteger('OffTimerDuration')
            ? $this->ReadAttributeInteger('OffTimerRemaining') : $duration;
        $this->WriteAttributeInteger('OffTimerDuration', $duration);
        $this->WriteAttributeInteger('OffTimerRemaining', $remaining);
        $this->WriteAttributeInteger('OffTimerDeadline', time() + $remaining);
        $this->WriteAttributeInteger('OffTimerNextAttempt', 0);
        $this->WriteAttributeString('OffTimerError', '');
        $this->WriteAttributeString('OffTimerState', 'running');
    }

    private function ClearOffTimer(): void
    {
        $error = $this->ReadAttributeString('ActionError');
        if (str_starts_with($error, 'Austimer') || str_starts_with($error, 'Shutdown:')) {
            $this->WriteAttributeString('ActionError', '');
        }
        $this->WriteAttributeString('OffTimerState', 'idle');
        $this->WriteAttributeString('OffTimerError', '');
        $this->WriteAttributeInteger('OffTimerDeadline', 0);
        $this->WriteAttributeInteger('OffTimerRemaining', 0);
        $this->WriteAttributeInteger('OffTimerNextAttempt', 0);
        $this->SetTimerInterval('OffTimer', 0);
    }

    private function ScheduleOffTimer(): void
    {
        $state = $this->ReadAttributeString('OffTimerState');
        $next = $state === 'running' ? $this->ReadAttributeInteger('OffTimerDeadline')
            : ($state === 'stopping' ? $this->ReadAttributeInteger('OffTimerNextAttempt') : 0);
        $interval = $next > 0 ? max(1, ($next - time()) * 1000) : 0;
        if ($interval > 0 && IPS_GetKernelRunlevel() !== KR_READY) {
            $interval = 30000;
        }
        $this->SetTimerInterval('OffTimer', $interval);
    }

    private function ProcessOffTimer(): void
    {
        $state = $this->ReadAttributeString('OffTimerState');
        if (($state !== 'running' && $state !== 'stopping')
            || ($state === 'running' && time() < $this->ReadAttributeInteger('OffTimerDeadline'))
            || ($state === 'stopping' && time() < $this->ReadAttributeInteger('OffTimerNextAttempt'))) {
            return;
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->SetTimerInterval('OffTimer', 30000);
            return;
        }
        $job = $this->ReadOperation();
        if (($job['name'] ?? '') === 'Shutdown') {
            $this->WriteAttributeInteger('OffTimerNextAttempt', time() + 30);
            return;
        }
        if ($job !== []) {
            $this->LogError('Austimer', 'Offener Geräteauftrag durch Timerablauf abgebrochen; bereits gesendete Befehle bleiben wirksam.');
            $this->WriteAttributeString('OperationJob', '');
            $this->SetTimerInterval('Operation', 0);
            $this->actionFailed = false;
        }
        $this->WriteAttributeString('OffTimerState', 'stopping');
        $this->WriteAttributeInteger('OffTimerRemaining', 0);
        $this->WriteAttributeInteger('OffTimerNextAttempt', time() + 30);
        $this->WriteAttributeString('OffTimerError', '');
        try {
            if (!$this->HasActiveParent()) {
                $this->LogError('Austimer', 'Verbindung fehlt; erneuter Abschaltversuch in 30 Sekunden.');
            } else {
                $this->SetMainBurner(false);
            }
        } catch (Throwable $error) {
            $this->LogError('Austimer-Abschaltung', $error);
        }
        if ($this->ReadAttributeString('OffTimerState') === 'stopping') {
            $this->WriteAttributeInteger('OffTimerNextAttempt', time() + 30);
        }
        $this->UpdateVisualization();
    }

    private function ReadOperation(): array
    {
        $json = $this->ReadAttributeString('OperationJob');
        return $json === '' ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function WriteOperation(array $job, int $interval = 100): void
    {
        $this->WriteAttributeString('OperationJob', json_encode($job, JSON_THROW_ON_ERROR));
        $this->SetTimerInterval('Operation', $interval);
    }

    private function CommandSteps(int $command, string $condition): array
    {
        $write = ['type' => 'write', 'register' => self::COMMAND_REGISTER, 'value' => $command];
        if ($command === 105 || $command === 106) {
            $write['guard'] = $command === 105 ? 'modeWave' : 'modeTemperature';
        }
        return [
            $write,
            ['type' => 'expect', 'condition' => $condition]
        ];
    }

    private function StartOperation(string $name, array $steps, array $extra = []): void
    {
        if ($this->ReadAttributeString('OperationJob') !== '') {
            $this->LogError('Aktion', 'Ein Geräteauftrag wartet bereits auf Rückmeldung.');
            return;
        }
        $this->WriteOperation(array_merge(['name' => $name, 'steps' => $steps, 'index' => 0, 'error' => '', 'readback' => []], $extra));
        $this->ProcessOperation();
    }

    private function StatusMatches(string $condition, int $status): bool
    {
        return match ($condition) {
            'igniteAllowed' => ($status & (1 | 32768)) === 0,
            'mainOn' => ($status & 4) !== 0,
            'mainOff' => ($status & 4) === 0,
            'secondOn' => ($status & 8) !== 0,
            'secondOff' => ($status & 8) === 0,
            'waveOn' => ($status & 512) !== 0,
            'waveOff' => ($status & 512) === 0,
            'tempOn' => (($status >> 13) & 3) === 2,
            'tempOff' => (($status >> 13) & 3) !== 2,
            'lightOn' => ($status & 256) !== 0,
            'lightOff' => ($status & 256) === 0,
            'boostOn' => ($status & 128) !== 0,
            'boostOff' => ($status & 128) === 0,
            'shutdown' => ($status & (4 | 8 | 512)) === 0 && (($status >> 13) & 3) !== 2,
            default => throw new RuntimeException('Unbekannte Bestätigungsbedingung: ' . $condition)
        };
    }

    private function WaveReadSteps(): array
    {
        return array_map(static fn (int $register): array => ['type' => 'readWave', 'register' => $register], range(40420, 40430));
    }

    private function FinishOperation(array $job): void
    {
        $this->WriteAttributeString('OperationJob', '');
        $this->SetTimerInterval('Operation', 0);
        if ($job['name'] === 'WaveSave') {
            $this->WriteAttributeString('WaveResult', $job['error'] === '' ? 'applied' : 'failed');
        }
        if ($job['name'] === 'WaveReadback' && $job['error'] !== '') {
            $this->WriteAttributeInteger('WaveUpdatedAt', 0);
        }
        if ($job['name'] === 'Ignition' && $job['error'] === '') {
            $this->LogIgnitionStep('Zündablauf durch Statusbit 2 bestätigt.');
        }
        if ($job['name'] === 'Shutdown') {
            if ($job['error'] === '') {
                $this->ClearOffTimer();
            } elseif ($this->ReadAttributeString('OffTimerState') === 'stopping') {
                $this->WriteAttributeString('OffTimerError', $job['error']);
                $this->WriteAttributeInteger('OffTimerNextAttempt', time() + 30);
                $this->ScheduleOffTimer();
            }
        }
        if ($job['error'] !== '') {
            $this->WriteAttributeString('ActionError', $job['error']);
        }
        if ($this->ReadAttributeInteger('WaveReloadPending') === 1 && $this->HasActiveParent()) {
            $this->WriteAttributeInteger('WaveReloadPending', 0);
            $this->SynchronizeWaveSettings();
        }
    }

    private function ProcessOperation(): void
    {
        $job = $this->ReadOperation();
        if ($job === []) {
            $this->SetTimerInterval('Operation', 0);
            return;
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->SetTimerInterval('Operation', 30000);
            return;
        }
        try {
            if (!$this->HasActiveParent()) {
                throw new RuntimeException('Modbus-Gateway oder I/O ist nicht aktiv.');
            }
            $step = $job['steps'][$job['index']] ?? null;
            if ($step === null) {
                $this->FinishOperation($job);
                return;
            }
            $status = $this->ReadAttributeInteger('StatusRegister');
            if ($step['type'] === 'expect' || time() - $this->ReadAttributeInteger('StatusUpdatedAt') >= 5
                || ($step['type'] === 'write' && isset($step['guard']))) {
                $status = $this->ReadRegister(self::STATUS_REGISTER);
                if ($this->actionFailed) {
                    $this->WriteAttributeInteger('StatusUpdatedAt', 0);
                    $this->SetStatus(201);
                    throw new RuntimeException('Keine gültige Statusantwort.');
                }
            }
            if ($step['type'] === 'expect') {
                $deadline = $step['deadline'] ?? time() + ($step['condition'] === 'igniteAllowed' ? 300 : 18);
                $job['steps'][$job['index']]['deadline'] = $deadline;
                if (($job['name'] === 'Ignition' || $job['name'] === 'SecondBurner') && ($status & 1) !== 0) {
                    throw new RuntimeException('Kaminfehler verhindert die Zündung.');
                }
                if (($step['condition'] === 'tempOn' || $step['condition'] === 'tempOff') && (($status >> 13) & 3) === 3) {
                    throw new RuntimeException('Der Kamin meldet einen Fehler der Temperaturregelung.');
                }
                if (!$this->StatusMatches($step['condition'], $status)) {
                    if (time() >= $deadline) {
                        throw new RuntimeException('Geräterückmeldung nicht bestätigt: ' . $step['condition']);
                    }
                    $this->WriteOperation($job, 2000);
                    return;
                }
            } elseif ($step['type'] === 'write') {
                if (isset($step['guard'])) {
                    $allowed = match ($step['guard']) {
                        'ignite' => ($status & (1 | 32768)) === 0 && (($status >> 13) & 3) !== 2,
                        'second' => ($status & 5) === 4,
                        'wave' => ($status & (1 | 4 | 512)) === (4 | 512) && (($status >> 13) & 3) !== 2,
                        'modeWave' => ($status & 5) === 4 && (($status >> 13) & 3) !== 2,
                        'modeTemperature' => ($status & (1 | 4 | 512)) === 4 && (($status >> 13) & 3) === 1,
                        default => false
                    };
                    if (!$allowed) {
                        throw new RuntimeException('Gerätestatus erlaubt den nächsten Schreibschritt nicht.');
                    }
                    if ($step['guard'] === 'ignite' && ($status & 4) !== 0) {
                        $job['index']++;
                        $this->WriteOperation($job);
                        return;
                    }
                }
                if (!$this->WriteRegister($step['register'], $step['value'])) {
                    throw new RuntimeException('Schreibschritt bei Register ' . $step['register'] . ' abgelehnt; '
                        . $job['index'] . ' Schritte bereits bestätigt.');
                }
            } elseif ($step['type'] === 'readWave') {
                $value = $this->ReadRegister($step['register']);
                if ($this->actionFailed) {
                    throw new RuntimeException('Wave-Readback bei Register ' . $step['register'] . ' fehlgeschlagen.');
                }
                $this->SendDebug('Wave Lesen', sprintf('Register %d=%d (0x%04x)', $step['register'], $value, $value), 0);
                if ($step['register'] === 40420) {
                    if ($value < 5 || $value > 60) {
                        throw new RuntimeException('Ungültiges Wave-Intervall: ' . $value);
                    }
                    $job['readback']['interval'] = $value;
                    $job['readback']['stages'] = [];
                } else {
                    foreach ([$value & 255, ($value >> 8) & 255] as $stage) {
                        if ($stage < 1 || $stage > 15) {
                            throw new RuntimeException(sprintf('Ungültige Wave-Stufe bei Register %d: 0x%04x', $step['register'], $value));
                        }
                        $job['readback']['stages'][] = (int) round(($stage - 1) * 100 / 14);
                    }
                }
                if ($step['register'] === 40430) {
                    $readback = $job['readback'];
                    $this->WriteAttributeInteger('WaveInterval', $readback['interval']);
                    $this->WriteAttributeString('WavePattern', json_encode($readback['stages'], JSON_THROW_ON_ERROR));
                    $this->WriteAttributeInteger('WaveUpdatedAt', time());
                    if (isset($job['expected']) && $readback !== $job['expected']) {
                        throw new RuntimeException('Wave-Readback entspricht nicht dem übertragenen Muster.');
                    }
                }
            }
            $job['index']++;
            if ($job['index'] >= count($job['steps'])) {
                $this->FinishOperation($job);
            } else {
                $this->WriteOperation($job);
            }
        } catch (Throwable $error) {
            $this->LogError($job['name'], $error);
            $job['error'] = $this->ReadAttributeString('ActionError');
            if ($job['name'] === 'WaveSave' && ($step['type'] ?? '') === 'write' && $this->HasActiveParent()) {
                $job['steps'] = $this->WaveReadSteps();
                $job['index'] = 0;
                unset($job['expected']);
                $this->WriteOperation($job);
            } else {
                $this->FinishOperation($job);
            }
        }
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
            $this->WriteAttributeInteger('WaveUpdatedAt', 0);
            $this->WriteAttributeInteger('WaveSyncAttemptAt', 0);
            $this->WriteAttributeInteger('StatusUpdatedAt', 0);
            $this->SetStatus(104);
            $this->LogError('Statusabfrage', 'Modbus-Gateway oder I/O nicht verbunden oder nicht aktiv.');
            return;
        }

        $previousStatus = $this->ReadAttributeInteger('StatusRegister');
        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            $this->WriteAttributeInteger('StatusUpdatedAt', 0);
            $this->SetStatus(201);
            return;
        }
        if ($this->ReadPropertyBoolean('EnableWave') && (
            time() - $this->ReadAttributeInteger('WaveSyncAttemptAt') >= 60
            || (($status & (1 << 9)) !== 0 && ($previousStatus & (1 << 9)) === 0)
        )) {
            $this->SynchronizeWaveSettings();
        }

        if ($this->ReadPropertyBoolean('EnableTemperatureControl')) {
            $roomTemperatureRaw = $this->ReadRegister(40207);
            if ($this->actionFailed) {
                return;
            }
            $this->SetNumericValueIfPresent('RoomTemperature', $roomTemperatureRaw / 10);
            $setpointRaw = $this->ReadRegister(40250);
            if (!$this->actionFailed) {
                $this->SetNumericValueIfPresent('TemperatureSetpoint', $setpointRaw / 10);
            }
        }
    }

    private function RecordStatus(int $status): void
    {
        $this->WriteAttributeInteger('StatusRegister', $status);
        $this->WriteAttributeInteger('StatusUpdatedAt', time());
        $resetPendingUntil = $this->ReadAttributeInteger('ResetPendingUntil');
        if ($resetPendingUntil > 0 && (($status & 1) === 0 || time() >= $resetPendingUntil)) {
            $this->WriteAttributeInteger('ResetPendingUntil', 0);
            if (($status & 1) !== 0) {
                $previousFailure = $this->actionFailed;
                $this->LogError('Kamin-Reset', 'Nach 20 Sekunden ist das Fehlerbit weiterhin gesetzt; erneuter Reset nur bei Gerätefreigabe.');
                $this->actionFailed = $previousFailure;
            } else {
                $this->SendDebug('Kamin-Reset', 'Fehlerbit gelöscht; Reset bestätigt.', 0);
            }
        }
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

    }

    private function SetNumericValueIfPresent(string $ident, float $value): void
    {
        $id = $this->FindVariableId($ident);
        if ($id !== 0) {
            SetValue($id, $value);
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
            $flameId = $this->FindVariableId('FlameHeight');
            if ($flameId !== 0) {
                SetValue($flameId, 100);
            }
        } elseif (!$isOn) {
            $this->WriteAttributeInteger('LastIgnitionAt', 0);
        }
        SetValue($variableId, $isOn);
    }

    private function ResetFireplace(): void
    {
        if ($this->ReadAttributeInteger('ResetPendingUntil') > time()) {
            $this->LogError('Kamin-Reset', 'Ein Reset wartet bereits auf Rückmeldung.');
            return;
        }
        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }
        if (($status & 1) === 0) {
            $this->LogError('Kamin-Reset', 'Kein Kaminfehler vorhanden; Reset wurde nicht gesendet.');
            return;
        }
        if (($status & (1 << 6)) === 0) {
            $this->LogError('Kamin-Reset', 'Das Gerät erlaubt derzeit keinen Reset durch den Benutzer.');
            return;
        }

        $this->WriteAttributeInteger('ResetPendingUntil', time() + 20);
        $this->UpdateVisualization();
        if (!$this->WriteRegister(self::COMMAND_REGISTER, 1000)) {
            $this->WriteAttributeInteger('ResetPendingUntil', 0);
            return;
        }
        $this->SendDebug('Kamin-Reset', 'Kommando 1000 bestätigt; warte zyklisch auf gelöschtes Fehlerbit.', 0);
    }

    private function SetMainBurner(bool $turnOn): void
    {
        if ($turnOn) {
            $this->IgniteMainBurner();
            return;
        }
        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }
        $steps = [];
        if (($status & (1 << 9)) !== 0) {
            $steps = array_merge($steps, $this->CommandSteps(7, 'waveOff'));
        }
        if ((($status >> 13) & 0b11) === 0b10) {
            $steps = array_merge($steps, $this->CommandSteps(8, 'tempOff'));
        }
        $this->StartOperation('Shutdown', array_merge($steps, $this->CommandSteps(3, 'shutdown')),
            ['timerOrigin' => $this->ReadAttributeString('OffTimerState') === 'stopping']);
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
        $this->StartOperation('Ignition', $this->IgnitionSteps());
    }

    private function IgnitionSteps(): array
    {
        return [
            ['type' => 'expect', 'condition' => 'igniteAllowed'],
            ['type' => 'write', 'register' => self::COMMAND_REGISTER, 'value' => 101, 'guard' => 'ignite'],
            ['type' => 'expect', 'condition' => 'mainOn']
        ];
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
                $steps = $this->IgnitionSteps();
            } else {
                $steps = [];
            }
            $steps[] = ['type' => 'write', 'register' => self::COMMAND_REGISTER, 'value' => 102, 'guard' => 'second'];
            $steps[] = ['type' => 'expect', 'condition' => 'secondOn'];
            $this->StartOperation('SecondBurner', $steps);
            return;
        }

        $this->StartOperation('SecondBurner', $this->CommandSteps(4, 'secondOff'));
    }

    private function SetAccessory(bool $turnOn, int $onCommand, int $offCommand, string $ident): void
    {
        $condition = [
            'Light' => 'light',
            'BoostFan' => 'boost',
            'Wave' => 'wave'
        ][$ident];
        $this->StartOperation($ident, $this->CommandSteps($turnOn ? $onCommand : $offCommand, $condition . ($turnOn ? 'On' : 'Off')));
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

        $steps = [];
        if ($mode !== 'wave' && $waveActive) {
            $steps = array_merge($steps, $this->CommandSteps(7, 'waveOff'));
        }

        if ($mode !== 'temperature' && $temperatureState === 0b10) {
            $steps = array_merge($steps, $this->CommandSteps(8, 'tempOff'));
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
                    $steps[] = ['type' => 'write', 'register' => 40250, 'value' => (int) round($setpoint * 10)];
                }
                $steps = array_merge($steps, $this->CommandSteps(106, 'tempOn'));
            }
        } elseif ($mode === 'wave' && !$waveActive) {
            $steps = array_merge($steps, $this->CommandSteps(105, 'waveOn'));
        }
        if ($mode === 'wave' && !$this->actionFailed) {
            $this->WriteAttributeInteger('WaveSyncAttemptAt', 0);
            $this->WriteAttributeInteger('WaveReloadPending', 1);
        }
        if ($steps !== []) {
            $this->StartOperation('OperationMode', $steps);
        } elseif ($mode === 'wave') {
            $this->WriteAttributeInteger('WaveReloadPending', 0);
            $this->SynchronizeWaveSettings();
        }
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

        $stageValues = [];
        foreach (array_values($settings['stages']) as $percentage) {
            if (!is_numeric($percentage) || (float) $percentage < 0 || (float) $percentage > 100) {
                $this->LogError('Wave-Einstellungen', 'Jede Wave-Stufe muss zwischen 0 und 100 Prozent liegen.');
                return;
            }
            $percentage = (int) round((float) $percentage);
            $stage = (int) round($percentage * 14 / 100) + 1;
            $stageValues[] = $stage;
        }

        $status = $this->ReadRegister(self::STATUS_REGISTER);
        if ($this->actionFailed) {
            return;
        }
        if (($status & (1 | 4 | 512)) !== (4 | 512) || (($status >> 13) & 3) === 2) {
            $this->LogError('Wave-Einstellungen', 'Speichern ist nur bei eingeschaltetem Kamin im Wave-Modus möglich.');
            return;
        }

        $registers = [(int) $interval];
        for ($index = 0; $index < self::WAVE_STAGE_COUNT; $index += 2) {
            $registers[] = ($stageValues[$index + 1] << 8) | $stageValues[$index];
        }
        $steps = [];
        foreach ($registers as $index => $value) {
            $steps[] = ['type' => 'write', 'register' => self::WAVE_INTERVAL_REGISTER + $index, 'value' => $value, 'guard' => 'wave'];
        }
        $this->WriteAttributeString('WaveResult', 'pending');
        $this->WriteAttributeInteger('WaveUpdatedAt', 0);
        $this->WriteAttributeInteger('WaveSyncAttemptAt', time());
        $this->StartOperation('WaveSave', array_merge($steps, $this->WaveReadSteps()), [
            'expected' => ['interval' => (int) $interval, 'stages' => array_map(static fn (int $stage): int => (int) round(($stage - 1) * 100 / 14), $stageValues)]
        ]);
    }

    private function SynchronizeWaveSettings(): void
    {
        if ($this->ReadAttributeString('OperationJob') !== '') {
            $this->WriteAttributeInteger('WaveReloadPending', 1);
            return;
        }
        $this->WriteAttributeInteger('WaveSyncAttemptAt', time());
        $previousFailure = $this->actionFailed;
        $this->actionFailed = false;
        try {
            $this->StartOperation('WaveReadback', $this->WaveReadSteps());
        } finally {
            $this->actionFailed = $previousFailure || $this->actionFailed;
        }
    }

    private function ReadCachedWavePattern(): array
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
        if ($register === self::STATUS_REGISTER) {
            $this->WriteAttributeInteger('StatusUpdatedAt', 0);
        }
        // DRU expects the documented register number as-is, not a 40001-relative offset.
        $response = $this->SendModbusRequest(3, $register, 1, '', 'Modbus-Lesen');
        if ($this->actionFailed) {
            if ($register === self::STATUS_REGISTER) {
                $this->SetStatus(201);
            }
            return -1;
        }

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

        $result = (int) $value['value'];
        if ($register === self::STATUS_REGISTER) {
            $this->RecordStatus($result);
            $error = $this->ReadAttributeString('ActionError');
            if (str_starts_with($error, 'Modbus-Lesen:') || str_starts_with($error, 'Statusabfrage')) {
                $this->WriteAttributeString('ActionError', '');
            }
        }
        return $result;
    }

    private function WriteRegister(int $register, int $value): bool
    {
        if ($register < 0 || $register > 65535 || $value < 0 || $value > 65535) {
            $this->LogError('Modbus-Schreiben', 'Ungültige Registeradresse oder ungültiger Wert: ' . $register . ', ' . $value . '.');
            return false;
        }

        $data = pack('n', $value);
        $this->SendDebug('Modbus TX', sprintf('FC6 Register=%d Wert=%d Daten=%s', $register, $value, bin2hex($data)), 0);
        $response = $this->SendModbusRequest(6, $register, 1, $data, 'Modbus-Schreiben');
        if ($this->actionFailed) {
            return false;
        }

        return $this->ValidateWriteResponse($response, pack('Cnn', 6, $register, $value), 'Modbus-Schreiben');
    }

    private function WriteMultipleRegisters(int $register, array $values): void
    {
        $quantity = count($values);
        if ($quantity === 0 || $quantity > 123 || $register < 0 || $register + $quantity - 1 > 65535) {
            $this->LogError('Modbus-Sammelschreiben', 'Ungültiger Registerbereich: ' . $register . ', Anzahl ' . $quantity . '.');
            return;
        }
        foreach ($values as $value) {
            if (!is_int($value) || $value < 0 || $value > 65535) {
                $this->LogError('Modbus-Sammelschreiben', 'Registerwerte müssen Ganzzahlen zwischen 0 und 65535 sein.');
                return;
            }
        }

        $data = pack('n*', ...$values);
        $this->SendDebug('Modbus Schreibfolge', sprintf('FC6 Startregister=%d Anzahl=%d Daten=%s', $register, $quantity, bin2hex($data)), 0);
        foreach (array_values($values) as $index => $value) {
            if (!$this->WriteRegister($register + $index, $value)) {
                $this->LogError(
                    'Modbus-Sammelschreiben',
                    sprintf(
                        'Schreibfolge bei Register %d abgebrochen; %d von %d Registern bereits bestätigt. Das Gerät kann teilweise geänderte Wave-Einstellungen enthalten; Einstellungen wurden nicht als gespeichert übernommen.',
                        $register + $index,
                        $index,
                        $quantity
                    )
                );
                return;
            }
            $this->SendDebug('Modbus Schreibfolge', sprintf('Register %d bestätigt (%d/%d)', $register + $index, $index + 1, $quantity), 0);
        }
    }

    private function SendModbusRequest(int $function, int $register, int $quantity, string $data, string $context): mixed
    {
        $details = sprintf('FC%d Register=%d Anzahl=%d Daten=%s', $function, $register, $quantity, bin2hex($data));
        $json = json_encode([
            'DataID' => self::MODBUS_GATEWAY_DATA_ID,
            'Function' => $function,
            'Address' => $register,
            'Quantity' => $quantity,
            // The gateway expects bytes transported as Latin-1 characters in JSON, not hex text.
            'Data' => mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1')
        ], JSON_THROW_ON_ERROR);

        $warnings = [];
        set_error_handler(
            static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            },
            E_WARNING | E_USER_WARNING
        );
        $error = null;
        $response = false;
        $started = microtime(true);
        try {
            $response = $this->SendDataToParent($json);
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            restore_error_handler();
            $this->SendDebug('Modbus Laufzeit', sprintf('%s Dauer=%.1f ms', $details, (microtime(true) - $started) * 1000), 0);
        }

        foreach ($warnings as $warning) {
            $this->LogError($context, $details . ': Gateway-Warnung: ' . $warning);
        }
        if ($error !== null) {
            $this->LogError($context, $details . ': ' . $error->getMessage());
        }
        if ($warnings !== [] || $error !== null) {
            $this->SendDebug('Modbus RX', $this->FormatModbusResponse($response), 0);
            return false;
        }

        return $response;
    }

    private function ValidateWriteResponse(mixed $response, string $expected, string $context): bool
    {
        $actual = $this->FormatModbusResponse($response);
        $this->SendDebug('Modbus RX', $actual, 0);
        if (is_string($response) && strlen($response) === 2 && ord($response[0]) === (ord($expected[0]) | 0x80)) {
            $this->LogError($context, sprintf('Modbus-Exception %d; Antwort=%s, erwartet=%s.', ord($response[1]), $actual, bin2hex($expected)));
            return false;
        }
        if ($response !== $expected) {
            $this->LogError($context, 'Ungültige Schreibbestätigung: Antwort=' . $actual . ', erwartet=' . bin2hex($expected) . '.');
            return false;
        }

        return true;
    }

    private function FormatModbusResponse(mixed $response): string
    {
        if (is_bool($response)) {
            return $response ? 'bool(true)' : 'bool(false)';
        }
        return is_string($response) ? bin2hex($response) : get_debug_type($response);
    }

    private function LogError(string $context, Throwable|string $error): void
    {
        $this->actionFailed = true;
        $detail = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $message = $context . ': ' . $detail;
        $this->WriteAttributeString('ActionError', $message);
        if ($this->ReadAttributeString('OffTimerState') === 'stopping' || str_starts_with($context, 'Austimer')) {
            $this->WriteAttributeString('OffTimerError', $message);
        }
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
