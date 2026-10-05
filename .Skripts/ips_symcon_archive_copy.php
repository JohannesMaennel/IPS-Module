<?php

declare(strict_types=1);

/*
 * IP-Symcon Skript:
 * Kopiert Archivwerte einer Variable fuer einen Zeitraum auf eine Zielvariable.
 */

$sourceVariableId = 12345; // ID der Quellvariable eintragen
$targetVariableId = 67890; // ID der Zielvariable eintragen
$startTime = strtotime('2026-01-01 00:00:00'); // Beginn des Zeitraums
$endTime = time(); // Ende des Zeitraums

if (!IPS_VariableExists($sourceVariableId)) {
    throw new InvalidArgumentException(sprintf('Quellvariable mit ID %d wurde nicht gefunden.', $sourceVariableId));
}

if (!IPS_VariableExists($targetVariableId)) {
    throw new InvalidArgumentException(sprintf('Zielvariable mit ID %d wurde nicht gefunden.', $targetVariableId));
}

if ($sourceVariableId === $targetVariableId) {
    throw new InvalidArgumentException('Quell- und Zielvariable muessen unterschiedlich sein.');
}

if ($startTime === false || $startTime > $endTime) {
    throw new InvalidArgumentException('Der Zeitraum ist ungueltig.');
}

$sourceVariable = IPS_GetVariable($sourceVariableId);
$targetVariable = IPS_GetVariable($targetVariableId);
if ($sourceVariable['VariableType'] !== $targetVariable['VariableType']) {
    throw new InvalidArgumentException('Quell- und Zielvariable muessen denselben Datentyp haben.');
}

$archiveIds = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
if (count($archiveIds) === 0) {
    throw new RuntimeException('Kein Archiv-Control in IP-Symcon gefunden.');
}

$archiveId = $archiveIds[0];
$loggedValues = AC_GetLoggedValues($archiveId, $sourceVariableId, $startTime, $endTime, 0);
if (!is_array($loggedValues)) {
    throw new RuntimeException('Archivwerte konnten nicht geladen werden.');
}

$valuesToCopy = [];
foreach ($loggedValues as $entry) {
    if (!array_key_exists('TimeStamp', $entry) || !array_key_exists('Value', $entry)) {
        throw new RuntimeException('Ein Archivwert enthaelt keinen Zeitstempel oder Wert.');
    }

    $valuesToCopy[] = [
        'TimeStamp' => $entry['TimeStamp'],
        'Value' => $entry['Value'],
    ];
}

if (count($valuesToCopy) > 0 && !AC_AddLoggedValues($archiveId, $targetVariableId, $valuesToCopy)) {
    throw new RuntimeException('Archivwerte konnten nicht auf die Zielvariable kopiert werden.');
}

if (count($valuesToCopy) > 0) {
    AC_ReAggregateVariable($archiveId, $targetVariableId);
}

echo sprintf(
    "Fertig. %d Archivwert(e) von Variable %d auf Variable %d kopiert (%s bis %s).\n",
    count($valuesToCopy),
    $sourceVariableId,
    $targetVariableId,
    date('Y-m-d H:i:s', $startTime),
    date('Y-m-d H:i:s', $endTime)
);