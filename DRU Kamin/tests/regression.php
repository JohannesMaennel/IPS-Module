<?php

declare(strict_types=1);

// Run without a Symcon kernel: php "DRU Kamin/tests/regression.php"
$objects = [];
$variableValues = [];
$profiles = [];
$modules = [];
$logs = [];
$nextId = 1000;

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
    public int $rawStatus = 0;
    public array $statusQueue = [];
    public array $properties = [];
    public array $attributes = [];
    public array $timers = [];
    public array $messages = [];
    public array $requests = [];
    public array $parentCalls = [];
    public int $instanceStatus = 105;

    public function __construct() {
        $this->InstanceID = ++$GLOBALS['nextId'];
        $GLOBALS['modules'][$this->InstanceID] = $this;
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
    protected function SetTimerInterval(string $name, int $interval): void { $this->timers[$name] = $interval; }
    protected function SetVisualizationType(int $type): void {}
    protected function SetStatus(int $status): void { $this->instanceStatus = $status; }
    protected function HasActiveParent(): bool { return $this->connection !== 0 && $this->parentActive; }
    protected function EnableAction(string $ident): void {}
    protected function SendDebug(string $title, string $data, int $format): void {}
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
        if ($request['Function'] === 3) {
            if ($this->readFails) {
                return false;
            }
            if ($this->malformedResponse) {
                return "\x83\x02\x00\x00";
            }
            $value = 200;
            if ($request['Address'] === 40203) {
                $value = count($this->statusQueue) > 0 ? array_shift($this->statusQueue) : $this->rawStatus;
            }
            return pack('CCn', 3, 2, $value);
        }
        if ($this->writeFails) {
            return false;
        }
        return pack('Cnn', $request['Function'], $request['Address'], hexdec($request['Data']));
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
        static fn (array $request): int => hexdec($request['Data']),
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

echo 'All DRU regression checks passed.' . PHP_EOL;
