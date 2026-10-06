<?php

declare(strict_types=1);

// Run without a Symcon kernel: php "DRU Kamin/tests/regression.php"
$objects = [];
$variableValues = [];
$profiles = [];
$modules = [];
$logs = [];
$nextId = 1000;
define('IPS_KERNELMESSAGE', 10100);
define('KR_READY', 10103);
$kernelRunlevel = KR_READY;
$semaphoreAvailable = true;
$semaphoreDepth = 0;
function IPS_GetKernelRunlevel(): int { return $GLOBALS['kernelRunlevel']; }
function IPS_SemaphoreEnter(string $name, int $milliseconds): bool {
    if (!$GLOBALS['semaphoreAvailable']) { return false; }
    if ($GLOBALS['semaphoreDepth'] !== 0) { throw new RuntimeException('Nested action lock'); }
    $GLOBALS['semaphoreDepth']++;
    return true;
}
function IPS_SemaphoreLeave(string $name): void { $GLOBALS['semaphoreDepth']--; }

function IPS_GetChildrenIDs(int $id): array { return array_keys($GLOBALS['objects'][$id] ?? []); }
function IPS_GetObject(int $id): array {
    foreach ($GLOBALS['objects'] as $children) {
        if (isset($children[$id])) {
            return $children[$id];
        }
    }
    throw new RuntimeException('Unknown test object');
}
function IPS_GetInstance(int $id): array { return ['ConnectionID' => $GLOBALS['modules'][$id]->connection]; }
function GetValue(int $id) { return $GLOBALS['variableValues'][$id]; }
function SetValue(int $id, $value): void { $GLOBALS['variableValues'][$id] = $value; }
function IPS_DeleteVariable(int $id): void {
    foreach ($GLOBALS['objects'] as &$children) {
        unset($children[$id]);
    }
    unset($GLOBALS['variableValues'][$id]);
}
function IPS_LogMessage(string $source, string $message): void { $GLOBALS['logs'][] = [$source, $message]; }
function IPS_Sleep(int $milliseconds): void {}
function IPS_VariableProfileExists(string $name): bool { return isset($GLOBALS['profiles'][$name]); }
function IPS_CreateVariableProfile(string $name, int $type): void { $GLOBALS['profiles'][$name] = $type; }
function IPS_SetVariableProfileValues(...$arguments): void {}
function IPS_SetVariableProfileText(...$arguments): void {}
function IPS_SetVariableProfileDigits(...$arguments): void {}

class IPSModule
{
    public int $InstanceID;
    public int $connection = 42;
    public bool $parentActive = true;
    public bool $readFails = false;
    public bool $malformedResponse = false;
    public bool $writeFails = false;
    public bool $overrideWriteResponse = false;
    public mixed $writeResponse = null;
    public array $writeResponseQueue = [];
    public ?int $warningFunction = null;
    public bool $warningWithValidResponse = false;
    public bool $parentThrows = false;
    public int $rawStatus = 0;
    public array $statusQueue = [];
    public array $registerValues = [40420 => 10];
    public ?int $failedReadRegister = null;
    public array $properties = [];
    public array $attributes = [];
    public array $timers = [];
    public array $messages = [];
    public array $requests = [];
    public array $parentCalls = [];
    public array $registeredMessages = [];
    public array $debug = [];
    public int $instanceStatus = 105;

    public function __construct() {
        $this->InstanceID = ++$GLOBALS['nextId'];
        $GLOBALS['modules'][$this->InstanceID] = $this;
        foreach (range(40421, 40430) as $register) {
            $this->registerValues[$register] = 0x0808;
        }
    }
    public function Create() {}
    public function ApplyChanges() {}
    protected function ConnectParent(string $guid): void { $this->parentCalls[] = ['connect', $guid]; }
    protected function RequireParent(string $guid): void { throw new RuntimeException('RequireParent forces a new gateway'); }
    protected function RegisterPropertyBoolean(string $name, bool $value): void { $this->properties[$name] = $value; }
    protected function ReadPropertyBoolean(string $name): bool { return $this->properties[$name]; }
    protected function RegisterAttributeInteger(string $name, int $value): void { $this->attributes[$name] = $value; }
    protected function RegisterAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    protected function ReadAttributeInteger(string $name): int { return $this->attributes[$name]; }
    protected function ReadAttributeString(string $name): string { return $this->attributes[$name]; }
    protected function WriteAttributeInteger(string $name, int $value): void { $this->attributes[$name] = $value; }
    protected function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    protected function RegisterTimer(string $name, int $interval, string $script): void { $this->timers[$name] = $interval; }
    protected function RegisterMessage(int $sender, int $message): void { $this->registeredMessages[] = [$sender, $message]; }
    protected function SetTimerInterval(string $name, int $interval): void { $this->timers[$name] = $interval; }
    protected function SetVisualizationType(int $type): void {}
    protected function SetStatus(int $status): void { $this->instanceStatus = $status; }
    protected function HasActiveParent(): bool { return $this->connection !== 0 && $this->parentActive; }
    protected function EnableAction(string $ident): void {}
    protected function SendDebug(string $title, string $data, int $format): void { $this->debug[] = [$title, $data]; }
    protected function RegisterVariableBoolean(string $ident, string $name, string $profile, int $position): void { $this->variable($ident, false); }
    protected function RegisterVariableInteger(string $ident, string $name, string $profile, int $position): void { $this->variable($ident, 0); }
    protected function RegisterVariableFloat(string $ident, string $name, string $profile, int $position): void { $this->variable($ident, 0.0); }
    private function variable(string $ident, $default): void {
        foreach ($GLOBALS['objects'][$this->InstanceID] ?? [] as $object) {
            if ($object['ObjectIdent'] === $ident) {
                return;
            }
        }
        $id = ++$GLOBALS['nextId'];
        $GLOBALS['objects'][$this->InstanceID][$id] = ['ObjectType' => 2, 'ObjectIdent' => $ident];
        $GLOBALS['variableValues'][$id] = $default;
    }
    protected function UpdateVisualizationValue(string $value): bool {
        $this->messages[] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return true;
    }
    protected function SendDataToParent(string $json) {
        $request = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = $request;
        if ($this->warningFunction === $request['Function']) {
            trigger_error('ILLEGAL_DATA_VALUE', E_USER_WARNING);
            if (!$this->warningWithValidResponse) {
                return false;
            }
        }
        if ($this->parentThrows) {
            throw new RuntimeException('Gateway transport exception');
        }
        if ($request['Function'] === 3) {
            if ($this->readFails || $this->failedReadRegister === $request['Address']) {
                return false;
            }
            if ($this->malformedResponse) {
                return "\x83\x02\x00\x00";
            }
            $value = $this->registerValues[$request['Address']] ?? 200;
            if ($request['Address'] === 40203) {
                $value = count($this->statusQueue) > 0 ? array_shift($this->statusQueue) : $this->rawStatus;
            }
            return pack('CCn', 3, 2, $value);
        }
        if ($this->writeFails) {
            return false;
        }
        if ($this->writeResponseQueue !== []) {
            return array_shift($this->writeResponseQueue);
        }
        if ($this->overrideWriteResponse) {
            return $this->writeResponse;
        }
        $data = mb_convert_encoding($request['Data'], 'ISO-8859-1', 'UTF-8');
        if (strlen($data) !== $request['Quantity'] * 2) {
            throw new RuntimeException('Gateway requires two binary bytes per register, not hex text');
        }
        if ($request['Function'] === 6) {
            $this->registerValues[$request['Address']] = unpack('nvalue', $data)['value'];
            return pack('Cn', 6, $request['Address']) . $data;
        }
        return pack('Cnn', 16, $request['Address'], $request['Quantity']);
    }
}

require dirname(__DIR__) . '/module.php';

function check(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}
function payload(DRUKamin $module): array { return $module->messages[count($module->messages) - 1]; }
function commands(DRUKamin $module): array {
    return array_map(
        static fn (array $request): int => unpack('nvalue', mb_convert_encoding($request['Data'], 'ISO-8859-1', 'UTF-8'))['value'],
        array_values(array_filter($module->requests, static fn (array $request): bool => $request['Function'] === 6 && $request['Address'] === 40200))
    );
}

$module = new DRUKamin();
$module->Create();
$module->ApplyChanges();
check($module->parentCalls === [['connect', '{A5F663AB-C400-4FE5-B207-4D67CC030564}']], 'Native ConnectParent instead of forced parent creation');
check($module->instanceStatus === 102, 'Successful polling leaves creation status and activates instance');
check($module->timers['PollStatus'] === 5000, 'Polling runs every five seconds');
check(payload($module)['values']['Fireplace'] === false, 'Initial off status is delivered as JSON string');

$module->rawStatus = 12;
$module->RequestAction('PollStatus', true);
check(payload($module)['values']['Fireplace'] === true, 'Cyclic poll detects external ignition');
check(payload($module)['values']['FlameHeight'] === 100, 'Confirmed new ignition initializes manual command to maximum');
$flameId = 0;
foreach ($GLOBALS['objects'][$module->InstanceID] as $id => $object) {
    if ($object['ObjectIdent'] === 'FlameHeight') {
        $flameId = $id;
    }
}
SetValue($flameId, 40);
$writesBeforePoll = count(array_filter($module->requests, static fn (array $request): bool => $request['Function'] === 6));
$module->RequestAction('PollStatus', true);
check(payload($module)['values']['FlameHeight'] === 40, 'Repeated ON poll preserves last manual command');
check(count(array_filter($module->requests, static fn (array $request): bool => $request['Function'] === 6)) === $writesBeforePoll,
    'Ignition initialization does not write an additional register');
$module->rawStatus = 15360;
$module->RequestAction('PollStatus', true);
check(payload($module)['values']['Fireplace'] === false && (payload($module)['statusRegister'] & 4) === 0, 'Observed status 15360 reports main burner OFF');

$module->readFails = true;
$module->RequestAction('PollStatus', true);
check($module->instanceStatus === 201 && payload($module)['statusAvailable'] === false, 'Failed reads invalidate old status and notify UI');
check($module->timers['PollStatus'] === 5000, 'Polling continues after read failure');
$module->readFails = false;
$module->rawStatus = 0;
$module->RequestAction('PollStatus', true);
check($module->instanceStatus === 102 && payload($module)['statusAvailable'], 'Next poll recovers from communication failure');

$module->malformedResponse = true;
$module->RequestAction('PollStatus', true);
check(!payload($module)['statusAvailable'], 'Malformed or exception response is not decoded as burner state');
$module->malformedResponse = false;

$module->connection = 0;
$module->ApplyChanges();
check($module->instanceStatus === 104 && !payload($module)['statusAvailable'], 'Missing gateway is inactive, not not-created');
check($module->timers['PollStatus'] === 5000, 'Timer remains enabled without a parent');
$module->connection = 42;
$module->rawStatus = 4;
$module->RequestAction('PollStatus', true);
check($module->instanceStatus === 102 && payload($module)['values']['Fireplace'], 'Connecting gateway later resumes polling without ApplyChanges');

foreach (['SecondBurner', 'Light', 'BoostFan'] as $ident) {
    check(!array_key_exists($ident, payload($module)['values']), 'Uninstalled option is hidden: ' . $ident);
}
$module->properties['EnableLight'] = true;
$module->properties['EnableBoostFan'] = true;
$module->properties['EnableSecondBurner'] = true;
$module->ApplyChanges();
$module->rawStatus = 0;
$module->requests = [];
$module->RequestAction('Light', true);
$module->RequestAction('BoostFan', true);
check(commands($module) === [103, 104], 'Light and boost send independent commands without ignition');

$module->requests = [];
$module->rawStatus = 0;
$module->statusQueue = [0, 0, 0, 4, 4];
$module->RequestAction('Fireplace', true);
check(commands($module) === [101], 'Ignition sends only command 101, no pilot command or second burner command');
check(payload($module)['values']['Fireplace'], 'Main burner ON is published only after bit 2 is read');

$module->requests = [];
$module->rawStatus = 15360;
$module->RequestAction('Fireplace', true);
check(commands($module) === [101], 'Unconfirmed ignition does not issue dependent commands');
check(!payload($module)['values']['Fireplace'], 'Ignition timeout leaves main burner OFF in UI');

$module->requests = [];
$module->writeFails = true;
$module->RequestAction('Fireplace', true);
check(commands($module) === [101] && !payload($module)['values']['Fireplace'], 'Write failure cannot publish burner ON');
$module->writeFails = false;
$module->rawStatus = 12;
$module->statusQueue = [12, 0, 0];
$module->requests = [];
$module->RequestAction('Fireplace', false);
check(commands($module) === [3], 'Main burner OFF does not separately send command 4');

$writeRegister = new ReflectionMethod(DRUKamin::class, 'WriteRegister');
$writeMultiple = new ReflectionMethod(DRUKamin::class, 'WriteMultipleRegisters');
$failed = new ReflectionProperty(DRUKamin::class, 'actionFailed');
foreach ([0, 101, 0x80ff, 65535] as $value) {
    $failed->setValue($module, false);
    check($writeRegister->invoke($module, 40200, $value) && !$failed->getValue($module), 'FC6 binary roundtrip including high bytes: ' . $value);
}

$module->overrideWriteResponse = true;
foreach ([
    'observed ASCII echo' => hex2bin('069d083030'),
    'wrong address' => pack('Cnn', 6, 40201, 101),
    'wrong function' => pack('Cnn', 16, 40200, 101),
    'truncated echo' => hex2bin('069d0800'),
    'extra byte' => hex2bin('069d08006500'),
    'Modbus exception' => hex2bin('8602'),
    'empty response' => '',
    'boolean response' => true,
    'null response' => null
] as $description => $response) {
    $module->writeResponse = $response;
    $failed->setValue($module, false);
    $logCount = count($logs);
    check(!$writeRegister->invoke($module, 40200, 101) && $failed->getValue($module) && count($logs) > $logCount, 'FC6 rejects and logs ' . $description);
}
check(str_contains($logs[count($logs) - 1][1], 'erwartet=069d080065'), 'FC6 error log includes expected echo');

$module->writeResponse = hex2bin('069d083030');
$module->rawStatus = 0;
$module->requests = [];
$logCount = count($logs);
$module->RequestAction('Fireplace', true);
$actionLogs = array_slice($logs, $logCount);
check(
    commands($module) === [101]
    && !array_filter($actionLogs, static fn (array $log): bool => str_contains($log[1], 'Warte auf Hauptbrenner')),
    'Mismatched echo aborts ignition before waiting for burner status'
);

$module->overrideWriteResponse = false;
$failed->setValue($module, false);
$waveRegisters = array_merge([60], array_fill(0, 10, 0x0f01));
$module->requests = [];
$writeMultiple->invoke($module, 40420, $waveRegisters);
check(
    !$failed->getValue($module) && count($module->requests) === 11
    && array_column($module->requests, 'Function') === array_fill(0, 11, 6)
    && array_column($module->requests, 'Address') === range(40420, 40430)
    && array_column($module->requests, 'Quantity') === array_fill(0, 11, 1)
    && array_map(
        static fn (array $request): int => unpack('nvalue', mb_convert_encoding($request['Data'], 'ISO-8859-1', 'UTF-8'))['value'],
        $module->requests
    ) === $waveRegisters,
    'Wave sends eleven individually confirmed FC6 writes with exact binary values'
);
$module->overrideWriteResponse = true;
foreach ([pack('Cnn', 6, 40420, 59), pack('Cnn', 6, 40421, 60), hex2bin('8603'), false] as $response) {
    $module->writeResponse = $response;
    $failed->setValue($module, false);
    $module->requests = [];
    $writeMultiple->invoke($module, 40420, $waveRegisters);
    check($failed->getValue($module) && count($module->requests) === 1, 'Wave stops on invalid FC6 echo: ' . (is_string($response) ? bin2hex($response) : 'false'));
}
$module->overrideWriteResponse = false;
$module->writeResponseQueue = [pack('Cnn', 6, 40420, 60), pack('Cnn', 6, 40421, 0x0f01), false];
$failed->setValue($module, false);
$module->requests = [];
$writeMultiple->invoke($module, 40420, $waveRegisters);
check(
    $failed->getValue($module) && count($module->requests) === 3
    && str_contains($logs[count($logs) - 1][1], '2 von 11 Registern bereits bestätigt'),
    'Partial Wave write stops immediately and logs confirmed register count'
);
foreach ([[-1, 101], [65536, 101], [40200, -1], [40200, 65536]] as [$register, $value]) {
    $failed->setValue($module, false);
    $requestCount = count($module->requests);
    check(
        !$writeRegister->invoke($module, $register, $value) && $failed->getValue($module) && count($module->requests) === $requestCount,
        'FC6 rejects out-of-range input without sending'
    );
}
foreach ([[65535, [1, 2]], [40420, [65536]], [40420, ['1']], [40420, []], [40420, array_fill(0, 124, 1)]] as [$register, $values]) {
    $failed->setValue($module, false);
    $requestCount = count($module->requests);
    $writeMultiple->invoke($module, $register, $values);
    check($failed->getValue($module) && count($module->requests) === $requestCount, 'Write sequence validates all values before sending');
}

$warningEscapes = [];
$previousHandler = static function (int $severity, string $message) use (&$warningEscapes): bool {
    $warningEscapes[] = $message;
    return true;
};
set_error_handler($previousHandler, E_WARNING | E_USER_WARNING);
try {
    foreach ([3, 6] as $function) {
        $module->warningFunction = $function;
        $failed->setValue($module, false);
        $logCount = count($logs);
        ob_start();
        if ($function === 3) {
            $result = (new ReflectionMethod(DRUKamin::class, 'ReadRegister'))->invoke($module, 40203);
        } elseif ($function === 6) {
            $result = $writeRegister->invoke($module, 40200, 101);
        }
        $output = ob_get_clean();
        check($output === '' && $warningEscapes === [] && $failed->getValue($module), 'FC' . $function . ' gateway warning is logged, not shown in UI');
        check(count($logs) === $logCount + 1 && str_contains($logs[$logCount][1], 'ILLEGAL_DATA_VALUE') && str_contains($logs[$logCount][1], 'FC' . $function . ' Register='), 'Gateway warning preserves error and request context');
        check($module->debug[count($module->debug) - 1] === ['Modbus RX', 'bool(false)'], 'Gateway rejection shows bool(false), not just bool');
        if ($function !== 16) {
            check($result === ($function === 3 ? -1 : false), 'Gateway warning returns explicit read/write failure');
        }
        $restored = set_error_handler($previousHandler, E_WARNING | E_USER_WARNING);
        restore_error_handler();
        check($restored === $previousHandler, 'FC' . $function . ' restores previous error handler');
    }

    $module->warningFunction = 6;
    $module->rawStatus = (1 << 2) | (1 << 9);
    $module->properties['EnableWave'] = true;
    $module->RequestAction('PollStatus', true);
    $beforeInterval = $module->attributes['WaveInterval'];
    $beforePattern = $module->attributes['WavePattern'];
    $module->RequestAction('SaveWaveSettings', json_encode(['interval' => 30, 'stages' => array_fill(0, 20, 100)]));
    check(
        $module->attributes['WaveInterval'] === $beforeInterval && $module->attributes['WavePattern'] === $beforePattern,
        'Rejected Wave write does not save requested settings'
    );
    check($warningEscapes === [], 'Wave action does not leak gateway warning to outer UI handler');
    $module->warningFunction = null;
    $module->requests = [];
    $module->RequestAction('SaveWaveSettings', json_encode(['interval' => 30, 'stages' => array_fill(0, 20, 100)]));
    $writes = array_values(array_filter($module->requests, static fn (array $request): bool => $request['Function'] !== 3));
    check(
        count($writes) === 11 && array_column($writes, 'Function') === array_fill(0, 11, 6)
        && $module->attributes['WaveInterval'] === 30
        && json_decode($module->attributes['WavePattern'], true) === array_fill(0, 20, 100),
        'Wave action saves settings only after all eleven FC6 confirmations'
    );

    $module->warningFunction = 6;
    $module->warningWithValidResponse = true;
    $failed->setValue($module, false);
    check(!$writeRegister->invoke($module, 40200, 101) && $failed->getValue($module), 'Warning cannot be masked by a valid-looking write response');
    $module->warningFunction = null;
    $module->parentThrows = true;
    $failed->setValue($module, false);
    check(!$writeRegister->invoke($module, 40200, 101) && $failed->getValue($module), 'Gateway exception is logged as failed write');
    $restored = set_error_handler($previousHandler, E_WARNING | E_USER_WARNING);
    restore_error_handler();
    check($restored === $previousHandler, 'Gateway exception also restores previous error handler');
    $module->parentThrows = false;
    $failed->setValue($module, false);
    check($writeRegister->invoke($module, 40200, 101), 'Successful writes recover after gateway warning/exception');
    trigger_error('Unrelated warning outside gateway call', E_USER_WARNING);
    check($warningEscapes === ['Unrelated warning outside gateway call'], 'Warnings outside Modbus call still reach original handler');
} finally {
    restore_error_handler();
}

$syncModule = new DRUKamin();
$syncModule->Create();
$syncModule->properties['EnableWave'] = true;
$syncModule->registerValues[40420] = 17;
$syncModule->registerValues[40421] = 0x0f01;
$syncModule->ApplyChanges();
check(payload($syncModule)['waveSettings']['available']
    && payload($syncModule)['waveSettings']['interval'] === 17
    && array_slice(payload($syncModule)['waveSettings']['stages'], 0, 2) === [0, 100],
    'ApplyChanges reads actual Wave interval and decodes LSB before MSB');
$syncModule->requests = [];
$syncModule->RequestAction('PollStatus', true);
check(count($syncModule->requests) === 1, 'Five-second status poll does not repeatedly read Wave registers');
$syncModule->attributes['WaveSyncAttemptAt'] = time() - 60;
$syncModule->registerValues[40420] = 22;
$syncModule->RequestAction('PollStatus', true);
check(payload($syncModule)['waveSettings']['interval'] === 22, 'Periodic Wave sync detects external settings changes');
$syncModule->registerValues[40420] = 23;
$syncModule->rawStatus = 516;
$syncModule->RequestAction('PollStatus', true);
check(payload($syncModule)['waveSettings']['interval'] === 23, 'External Wave activation triggers immediate register synchronization');
$syncModule->registerValues[40420] = 24;
$syncModule->RequestAction('OperationMode', 'wave');
check(payload($syncModule)['waveSettings']['interval'] === 24, 'Selecting already-active Wave synchronizes device settings');
$syncModule->attributes['StatusRegister'] = 4;
$syncModule->statusQueue = [4, 516, 516];
$syncModule->registerValues[40420] = 26;
$syncModule->requests = [];
$syncModule->RequestAction('OperationMode', 'wave');
check(commands($syncModule) === [105] && payload($syncModule)['waveSettings']['interval'] === 26,
    'Wave command activation reads the device pattern after status confirmation');

$syncModule->failedReadRegister = 40425;
$syncModule->attributes['WaveSyncAttemptAt'] = 0;
$oldPattern = $syncModule->attributes['WavePattern'];
$oldInterval = $syncModule->attributes['WaveInterval'];
$syncModule->registerValues[40420] = 25;
$syncModule->RequestAction('PollStatus', true);
check(!payload($syncModule)['waveSettings']['available']
    && $syncModule->attributes['WavePattern'] === $oldPattern && $syncModule->attributes['WaveInterval'] === $oldInterval
    && payload($syncModule)['statusAvailable'],
    'Incomplete Wave read does not overwrite cache or invalidate confirmed burner state');
$syncModule->failedReadRegister = null;
$syncModule->attributes['WaveSyncAttemptAt'] = 0;
$syncModule->registerValues[40421] = 0x0800;
$syncModule->RequestAction('PollStatus', true);
check(!payload($syncModule)['waveSettings']['available'] && str_contains($logs[count($logs) - 1][1], '0x0800'),
    'Invalid raw stage values are logged and never substituted by defaults');
$syncModule->registerValues[40421] = 0x0f01;
$syncModule->attributes['WaveSyncAttemptAt'] = 0;
$syncModule->registerValues[40420] = 0;
$syncModule->RequestAction('PollStatus', true);
check(!payload($syncModule)['waveSettings']['available'], 'Invalid device interval disables Wave editor');
$syncModule->registerValues[40420] = 10;
$syncModule->attributes['WaveSyncAttemptAt'] = 0;
$syncModule->RequestAction('PollStatus', true);
check(payload($syncModule)['waveSettings']['available'], 'Wave sync recovers after valid register reads');
$failed->setValue($syncModule, true);
(new ReflectionMethod(DRUKamin::class, 'SynchronizeWaveSettings'))->invoke($syncModule);
check($failed->getValue($syncModule) && $syncModule->attributes['WaveUpdatedAt'] > 0,
    'Successful readback preserves a preceding write failure');

$syncModule->writeResponseQueue = [pack('Cnn', 6, 40420, 30), false];
// The acknowledged first write changes the device; the second is rejected.
$syncModule->registerValues[40420] = 30;
$syncModule->requests = [];
$syncModule->RequestAction('SaveWaveSettings', json_encode(['interval' => 30, 'stages' => array_fill(0, 20, 100)]));
check(payload($syncModule)['waveSettings']['interval'] === 30
    && array_slice(payload($syncModule)['waveSettings']['stages'], 0, 2) === [0, 100]
    && count(array_filter($syncModule->requests, static fn (array $request): bool => $request['Function'] === 6)) === 2,
    'Partial write reads back actual device interval and original pattern without further writes');
check(count(array_filter($syncModule->debug, static fn (array $entry): bool => $entry[0] === 'Wave Lesen')) > 0,
    'Wave readback logs raw register values for hardware diagnosis');
$syncModule->connection = 0;
$syncModule->RequestAction('PollStatus', true);
check(!payload($syncModule)['waveSettings']['available'], 'Disconnect invalidates Wave readback');
$syncModule->connection = 42;
$syncModule->registerValues[40420] = 35;
$syncModule->RequestAction('PollStatus', true);
check(payload($syncModule)['waveSettings']['available'] && payload($syncModule)['waveSettings']['interval'] === 35,
    'Reconnect reloads device Wave settings');

$resetModule = new DRUKamin();
$resetModule->Create();
$resetModule->ApplyChanges();
foreach ([0, 64, 1, 129] as $status) {
    $resetModule->rawStatus = $status;
    $resetModule->requests = [];
    $resetModule->RequestAction('ResetFireplace', true);
    check(commands($resetModule) === [], 'Reset requires Fault and bit 6, not boost bit 7: ' . $status);
}
$resetModule->rawStatus = 65;
$resetModule->requests = [];
$resetModule->RequestAction('ResetFireplace', true);
check(commands($resetModule) === [1000] && payload($resetModule)['resetPending']
    && payload($resetModule)['values']['FireplaceFault'], 'Allowed reset sends 1000 without clearing Fault optimistically');
$resetModule->requests = [];
$resetModule->RequestAction('ResetFireplace', true);
check(commands($resetModule) === [] && payload($resetModule)['resetPending'], 'Duplicate reset is blocked pending confirmation');
$resetModule->rawStatus = 0;
$resetModule->RequestAction('PollStatus', true);
check(!payload($resetModule)['resetPending'] && !payload($resetModule)['values']['FireplaceFault'], 'Confirmed Fault clearance ends reset wait');
$resetModule->rawStatus = 65;
$resetModule->RequestAction('ResetFireplace', true);
$resetModule->attributes['ResetPendingUntil'] = time() - 1;
$resetModule->RequestAction('PollStatus', true);
check(!payload($resetModule)['resetPending'] && payload($resetModule)['values']['FireplaceFault']
    && str_contains($logs[count($logs) - 1][1], 'Fehlerbit weiterhin'), 'Reset timeout preserves Fault and logs failed reset');
$resetModule->requests = [];
$resetModule->RequestAction('ResetFireplace', true);
check(commands($resetModule) === [1000], 'User can retry reset after timeout with current permission');
$resetModule->attributes['ResetPendingUntil'] = 0;
$resetModule->writeFails = true;
$resetModule->RequestAction('ResetFireplace', true);
check(!payload($resetModule)['resetPending'] && payload($resetModule)['values']['FireplaceFault'], 'Failed reset write unlocks retry without hiding Fault');
$resetModule->writeFails = false;
$resetModule->readFails = true;
$resetModule->requests = [];
$resetModule->RequestAction('ResetFireplace', true);
check(commands($resetModule) === [] && !payload($resetModule)['statusAvailable'], 'Failed fresh status read prevents reset');
$resetModule->readFails = false;
$resetModule->connection = 0;
$resetModule->requests = [];
$resetModule->RequestAction('ResetFireplace', true);
check(commands($resetModule) === [], 'Disconnected parent prevents reset');

$syncModule->registerValues[40420] = 40;
$syncModule->requests = [];
$syncModule->RequestAction('ReloadWaveSettings', true);
check(payload($syncModule)['waveSettings']['interval'] === 40
    && !array_filter($syncModule->requests, static fn (array $request): bool => $request['Function'] !== 3),
    'Reload current Wave profile only reads registers and synchronizes IPS data');
$presets = json_decode(file_get_contents(dirname(__DIR__) . '/UI/wave-presets.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array_map(null, $presets, [50, 20, 10], [100, 60, 100], [10, 20, 10]) as [$preset, $minimum, $maximum, $interval]) {
    check(count($preset['stages']) === 20 && min($preset['stages']) === $minimum
        && max($preset['stages']) === $maximum && $preset['interval'] === $interval
        && array_slice($preset['stages'], 0, 10) === array_slice($preset['stages'], 10, 10),
        'JSON preset has two cycles with exact range and interval: ' . $preset['id']);
}
check(str_contains($syncModule->GetVisualizationTile(), '"id":"even"')
    && !str_contains($syncModule->GetVisualizationTile(), '{{PRESETS}}'), 'Production tile embeds Wave preset JSON without external fetch');
$previewPresets = file_get_contents(dirname(__DIR__) . '/UI/preview-presets.js');
$previewJson = substr($previewPresets, strpos($previewPresets, '=') + 1);
check(json_decode(trim($previewJson, " \n\r;"), true, 512, JSON_THROW_ON_ERROR) === $presets,
    'Offline preview generated presets match canonical JSON');

$timer = new DRUKamin();
$timer->Create();
$timer->rawStatus = 4;
$timer->ApplyChanges();
$timerAction = static function (string $action, mixed $duration = 3600) use ($timer): void {
    $timer->RequestAction('OffTimerAction', json_encode(['action' => $action, 'duration' => $duration], JSON_THROW_ON_ERROR));
};
check(payload($timer)['offTimer']['state'] === 'idle' && payload($timer)['offTimer']['duration'] === 3600,
    'First timer selection defaults to one hour without automatic activation');
check($timer->registeredMessages === [[0, IPS_KERNELMESSAGE]], 'Timer subscribes to kernel lifecycle');
foreach ([0, 59, 61, 86460, '60', null] as $invalid) {
    $timerAction('start', $invalid);
    check($timer->attributes['OffTimerState'] === 'idle' && payload($timer)['offTimer']['error'] !== '',
        'Invalid timer duration rejected explicitly: ' . json_encode($invalid));
}
$timerAction('start', 86400);
check($timer->attributes['OffTimerDeadline'] >= time() + 86399
    && $timer->timers['OffTimer'] <= 86400000, '24-hour duration accepted and scheduled server-side');
$timerAction('delete');
$timerAction('start', 60);
check($timer->attributes['OffTimerState'] === 'running'
    && $timer->attributes['OffTimerDuration'] === 60 && commands($timer) === [],
    'One-minute start stores deadline without any ignition or other write');
$deadline = $timer->attributes['OffTimerDeadline'];
$timerAction('start', 3600);
check($timer->attributes['OffTimerDeadline'] === $deadline, 'Duplicate start from another client does not restart running countdown');
$timer->ApplyChanges();
check($timer->attributes['OffTimerDeadline'] === $deadline && $timer->attributes['OffTimerState'] === 'running',
    'ApplyChanges preserves timer deadline without extension');
$timer->attributes['OffTimerDeadline'] = time() + 42;
$timer->parentActive = false;
$timerAction('pause', null);
check($timer->attributes['OffTimerState'] === 'paused' && $timer->attributes['OffTimerRemaining'] <= 42
    && $timer->attributes['OffTimerRemaining'] >= 41 && $timer->timers['OffTimer'] === 0 && commands($timer) === [],
    'Offline pause retains rest time and does not turn off fireplace');
$timer->ApplyChanges();
check($timer->attributes['OffTimerState'] === 'paused' && $timer->timers['OffTimer'] === 0,
    'Paused timer remains paused after ApplyChanges');
$timerAction('reset', 60);
check($timer->attributes['OffTimerState'] === 'paused' && payload($timer)['offTimer']['error'] !== '',
    'Offline reset is explicitly rejected without losing pause');
$timer->parentActive = true;
$timerAction('start', 60);
check($timer->attributes['OffTimerDeadline'] <= time() + 42 && $timer->attributes['OffTimerState'] === 'running',
    'Resume uses stored rest time, not original duration');
$timerAction('reset', 60);
check($timer->attributes['OffTimerDeadline'] >= time() + 59 && $timer->attributes['OffTimerState'] === 'running',
    'Reset immediately restarts original duration');
$timerAction('delete');
check($timer->attributes['OffTimerState'] === 'idle' && $timer->attributes['OffTimerDuration'] === 60
    && commands($timer) === [], 'Delete retains selected duration without fireplace writes');
$timer->rawStatus = 0;
$timerAction('reset', 60);
check($timer->attributes['OffTimerState'] === 'idle' && commands($timer) === [], 'Timer cannot turn on an off fireplace');

foreach ([4, 516, 16388, 16384] as $initialStatus) {
    $timer->rawStatus = $initialStatus;
    $timerAction('reset', 60);
    $timer->attributes['OffTimerDeadline'] = time() - 1;
    $timer->statusQueue = [$initialStatus, 0, 0];
    $timer->rawStatus = 0;
    $timer->requests = [];
    $timer->RequestAction('OffTimerTick', true);
    $expected = ($initialStatus & 512) !== 0 ? [7, 3] : (((($initialStatus >> 13) & 3) === 2) ? [8, 3] : [3]);
    check(commands($timer) === $expected && $timer->attributes['OffTimerState'] === 'idle'
        && $timer->timers['OffTimer'] === 0, 'Expired timer confirms shutdown including automation: ' . $initialStatus);
}
$timer->rawStatus = 4;
$timerAction('reset', 60);
$timer->attributes['OffTimerDeadline'] = time() - 1;
$savedAttributes = $timer->attributes;
$restored = new DRUKamin();
$restored->Create();
$restored->attributes = $savedAttributes;
$GLOBALS['kernelRunlevel'] = 10102;
$restored->ApplyChanges();
$restored->RequestAction('OffTimerTick', true);
check($restored->timers['OffTimer'] === 30000 && commands($restored) === [],
    'Overdue timer after restart waits for kernel READY without busy looping');
$GLOBALS['kernelRunlevel'] = KR_READY;
$restored->MessageSink(time(), 0, IPS_KERNELMESSAGE, [KR_READY]);
check($restored->attributes['OffTimerState'] === 'idle' && commands($restored) === [3],
    'Kernel READY immediately executes persisted overdue shutdown');

$timer->parentActive = false;
$timer->requests = [];
$timer->RequestAction('OffTimerTick', true);
check($timer->attributes['OffTimerState'] === 'stopping' && $timer->timers['OffTimer'] === 30000
    && payload($timer)['offTimer']['error'] !== '', 'Disconnected expired timer reports failure and retries in 30 seconds');
$timerAction('pause');
check($timer->attributes['OffTimerState'] === 'paused' && $timer->timers['OffTimer'] === 0,
    'Pause cancels pending retry even without parent connection');
$timerAction('delete');
$timer->parentActive = true;
$timer->rawStatus = 516;
$timerAction('reset', 60);
$timer->attributes['OffTimerDeadline'] = time() - 1;
$timer->writeFails = true;
$timer->RequestAction('OffTimerTick', true);
check($timer->attributes['OffTimerState'] === 'stopping' && commands($timer) === [7]
    && $timer->timers['OffTimer'] === 30000, 'Failed Wave stop aborts dependent commands and keeps retry pending');
$requests = count($timer->requests);
$timer->RequestAction('OffTimerTick', true);
check(count($timer->requests) === $requests, 'Early duplicate timer callback cannot bypass retry interval');
$timer->writeFails = false;
$timer->attributes['OffTimerNextAttempt'] = time() - 1;
$timer->statusQueue = [516, 512, 512];
$timer->rawStatus = 512;
$timer->requests = [];
$timer->RequestAction('OffTimerTick', true);
check($timer->attributes['OffTimerState'] === 'stopping' && $timer->timers['OffTimer'] === 30000,
    'Main OFF cannot confirm shutdown while Wave remains active');
$timer->attributes['OffTimerNextAttempt'] = time() - 1;
$timer->statusQueue = [16384, 16384, 16384];
$timer->rawStatus = 16384;
$timer->requests = [];
$timer->RequestAction('OffTimerTick', true);
check(commands($timer) === [8, 3] && $timer->attributes['OffTimerState'] === 'stopping',
    'Main OFF cannot confirm shutdown while temperature automation remains active');
$timer->attributes['OffTimerNextAttempt'] = time() - 1;
$timer->statusQueue = [516, 8, 8];
$timer->rawStatus = 8;
$timer->requests = [];
$timer->RequestAction('OffTimerTick', true);
check(commands($timer) === [7, 3] && $timer->attributes['OffTimerState'] === 'stopping'
    && str_contains(payload($timer)['offTimer']['error'], 'nicht bestätigt'),
    'Main OFF alone is insufficient while second burner is still ON');
$timer->attributes['OffTimerNextAttempt'] = time() - 1;
$timer->rawStatus = 0;
$timer->RequestAction('OffTimerTick', true);
check($timer->attributes['OffTimerState'] === 'idle', 'Later confirmed shutdown completes retry');

$timer->rawStatus = 16900;
$timerAction('reset', 60);
$timer->attributes['OffTimerDeadline'] = time() - 1;
$timer->statusQueue = [16900, 384, 384];
$timer->rawStatus = 384;
$timer->requests = [];
$timer->RequestAction('OffTimerTick', true);
check(commands($timer) === [7, 8, 3] && $timer->attributes['OffTimerState'] === 'idle'
    && (payload($timer)['statusRegister'] & 384) === 384,
    'Shutdown stops both automations in sequence without additional light/boost commands');
$timer->rawStatus = 4;
$timer->properties['EnableFireplace'] = false;
$timerAction('start', 60);
check($timer->attributes['OffTimerState'] === 'idle' && str_contains(payload($timer)['offTimer']['error'], 'deaktiviert'),
    'Disabled fireplace configuration cannot activate timer');
$timer->properties['EnableFireplace'] = true;
$timerAction('reset', 60);
$timer->attributes['OffTimerDeadline'] = time() - 1;
$timer->parentThrows = true;
$timer->RequestAction('OffTimerTick', true);
check($timer->attributes['OffTimerState'] === 'stopping' && $timer->timers['OffTimer'] === 30000
    && $GLOBALS['semaphoreDepth'] === 0, 'Transport exception keeps retry pending and releases action lock');
$timer->parentThrows = false;
$timerAction('delete');
$requests = count($timer->requests);
$timer->RequestAction('OffTimerTick', true);
check(count($timer->requests) === $requests, 'Late callback after delete cannot send shutdown commands');
$timer->rawStatus = 16384;
$timerAction('reset', 60);
$timer->RequestAction('PollStatus', true);
check($timer->attributes['OffTimerState'] === 'running', 'Autonomous temperature-mode burner OFF does not cancel timer');
$timer->statusQueue = [16384, 0, 0];
$timer->rawStatus = 0;
$timer->RequestAction('Fireplace', false);
check($timer->attributes['OffTimerState'] === 'idle', 'Fully confirmed manual shutdown clears countdown');
check($GLOBALS['semaphoreDepth'] === 0, 'All action paths release instance semaphore');
$GLOBALS['semaphoreAvailable'] = false;
$requests = count($timer->requests);
$timer->RequestAction('OffTimerTick', true);
check(count($timer->requests) === $requests && str_contains(end($GLOBALS['logs'])[1], 'andere Kaminaktion'),
    'Contended action lock reports failure instead of overlapping Modbus commands');
$GLOBALS['semaphoreAvailable'] = true;

echo 'All DRU regression checks passed.' . PHP_EOL;
