<?php

declare(strict_types=1);

/*
 * IP-Symcon Aktionsskript fuer den Drucksensor.
 *
 * Idee:
 * - Im WebFront bearbeitet der Benutzer eine Variable.
 * - Diese Variable hat dieses Skript als "Benutzerdefinierte Aktion".
 * - Das Skript nimmt $_IPS['VARIABLE'] und $_IPS['VALUE'],
 *   baut daraus ein JSON und schreibt dieses per RequestAction
 *   auf eine eigene MQTT-Command-Variable fuer `drucksensor/cmd`.
 *
 * Empfohlene Variablen-Idents in Symcon:
 * - pressure_calibration_voltage_1
 * - pressure_calibration_bar_1
 * - pressure_calibration_voltage_2
 * - pressure_calibration_bar_2
 * - gas_counter_offset_m3
 * - gas_pulses_per_m3
 * - water_pulses_per_m3
 * - water_counter_offset_m3
 *
 * Wichtig:
 * - Nicht die automatisch erzeugten State-Variablen unter `drucksensor/state`
 *   als Aktion verwenden. Diese sind Ist-Werte und in Symcon read-only.
 * - Stattdessen eigene Frontend-/Soll-Variablen anlegen und dort dieses
 *   Skript als "Benutzerdefinierte Aktion" hinterlegen.
 * - Zusaetzlich eine schreibbare String-Variable/Command-Variable fuer
 *   den Topic `drucksensor/cmd` verwenden, auf die RequestAction ausgefuehrt wird.
 */

const DRUCKSENSOR_CMD_VARIABLE_ID = 12345;

/**
 * Erlaubte Frontend-Variablen und deren Grenzen.
 */
const DRUCKSENSOR_ALLOWED_FIELDS = [
    'pressure_calibration_voltage_1' => ['min' => 0.0, 'decimals' => 4],
    'pressure_calibration_bar_1'     => ['min' => 0.0, 'decimals' => 4],
    'pressure_calibration_voltage_2' => ['min' => 0.0, 'decimals' => 4],
    'pressure_calibration_bar_2'     => ['min' => 0.0, 'decimals' => 4],
    'gas_counter_offset_m3'          => ['min' => 0.0, 'decimals' => 4],
    'gas_pulses_per_m3'              => ['min' => 1.0, 'decimals' => 4],
    'water_pulses_per_m3'            => ['min' => 1.0, 'decimals' => 4],
    'water_counter_offset_m3'        => ['min' => 0.0, 'decimals' => 4],
];

if (!isset($_IPS['VARIABLE'], $_IPS['VALUE'])) {
    throw new RuntimeException('Das Skript ist fuer die Ausfuehrung als Aktionsskript gedacht.');
}

$variableID = (int) $_IPS['VARIABLE'];
$incomingValue = $_IPS['VALUE'];
$ident = IPS_GetObject($variableID)['ObjectIdent'] ?? '';

if (!isset(DRUCKSENSOR_ALLOWED_FIELDS[$ident])) {
    throw new InvalidArgumentException('Variable mit Ident `' . $ident . '` ist fuer den Drucksensor nicht freigegeben.');
}

$config = DRUCKSENSOR_ALLOWED_FIELDS[$ident];
$value = round(max((float) $config['min'], (float) $incomingValue), (int) $config['decimals']);

$payload = json_encode([$ident => $value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($payload === false) {
    throw new RuntimeException('JSON-Payload konnte nicht erstellt werden.');
}

if (!function_exists('RequestAction')) {
    throw new RuntimeException('RequestAction() ist in dieser Umgebung nicht verfuegbar.');
}

RequestAction(DRUCKSENSOR_CMD_VARIABLE_ID, $payload);

/*
 * Einrichtung in IP-Symcon:
 *
 * 1. Fuer jeden schaltbaren Wert eine eigene Float-Variable anlegen.
 * 2. Als Ident exakt den MQTT-Key verwenden, z. B. `water_counter_offset_m3`.
 * 3. In der Variable dieses Skript als "Benutzerdefinierte Aktion" setzen.
 * 4. Eine separate schreibbare String-Variable fuer `drucksensor/cmd` anlegen
 *    und deren Variablen-ID oben in `DRUCKSENSOR_CMD_VARIABLE_ID` eintragen.
 * 5. Diese String-Variable muss beim Schreiben den JSON-String auf MQTT
 *    Richtung `drucksensor/cmd` publizieren.
 * 6. Im WebFront aendert der Benutzer nur die Soll-Variablen.
 *
 * Optional:
 * - Wenn mehrere Werte gemeinsam gesendet werden sollen, kann ein zweites Skript
 *   aus einer Kategorie alle Variablenwerte lesen und gesammelt als JSON senden.
 */
