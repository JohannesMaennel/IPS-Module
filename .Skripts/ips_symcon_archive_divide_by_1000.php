<?php

declare(strict_types=1);

/*
 * IP-Symcon Skript:
 * Teilt alle Archivwerte einer numerischen Variable im Zeitraum durch 1000.
 */

$variableId = 12345; // ID der Variable eintragen
$startTime = 0; // 0 = ab Beginn des Archivs
$endTime = time(); // Bis jetzt
$divisor = 1000.0;

if (!IPS_VariableExists($variableId)) {
    throw new InvalidArgumentException(sprintf('Variable mit ID %d wurde nicht gefunden.', $variableId));
}

$variable = IPS_GetVariable($variableId);
if (!in_array($variable['VariableType'], [1, 2], true)) {
    throw new InvalidArgumentException('Die Variable muss numerisch sein (Integer oder Float).');
}

if ($startTime > $endTime) {
    throw new InvalidArgumentException('Der Zeitraum ist ungueltig.');
}

$archiveIds = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
if (count($archiveIds) === 0) {
    throw new RuntimeException('Kein Archiv-Control in IP-Symcon gefunden.');
}

$archiveId = $archiveIds[0];
$loggedValues = AC_GetLoggedValues($archiveId, $variableId, $startTime, $endTime, 0);
if (!is_array($loggedValues)) {
    throw new RuntimeException('Archivwerte konnten nicht geladen werden.');
}

$originalValues = [];
$scaledValues = [];
foreach ($loggedValues as $entry) {
    if (!array_key_exists('TimeStamp', $entry) || !array_key_exists('Value', $entry) || !is_numeric($entry['Value'])) {
        throw new RuntimeException('Ein Archivwert enthaelt keinen gueltigen Zeitstempel oder Zahlenwert.');
    }

    $originalValues[] = [
        'TimeStamp' => $entry['TimeStamp'],
        'Value' => $entry['Value'],
    ];

    $scaledValue = $entry['Value'] / $divisor;
    if ($variable['VariableType'] === 1) {
        if ($scaledValue !== (float) (int) $scaledValue) {
            throw new RuntimeException('Die Division wuerde Nachkommastellen erzeugen, die eine Integer-Variable nicht speichern kann.');
        }
        $scaledValue = (int) $scaledValue;
    }

    $scaledValues[] = [
        'TimeStamp' => $entry['TimeStamp'],
        'Value' => $scaledValue,
    ];
}

if (count($scaledValues) === 0) {
    echo sprintf("Keine Archivwerte fuer Variable %d im angegebenen Zeitraum gefunden.\n", $variableId);
    return;
}

AC_DeleteVariableData($archiveId, $variableId, $startTime, $endTime);

try {
    if (!AC_AddLoggedValues($archiveId, $variableId, $scaledValues)) {
        throw new RuntimeException('Die skalierten Archivwerte konnten nicht geschrieben werden.');
    }
} catch (Throwable $exception) {
    AC_DeleteVariableData($archiveId, $variableId, $startTime, $endTime);
    if (!AC_AddLoggedValues($archiveId, $variableId, $originalValues)) {
        throw new RuntimeException('Fehler beim Schreiben; auch die Wiederherstellung der Originalwerte ist fehlgeschlagen.', 0, $exception);
    }

    AC_ReAggregateVariable($archiveId, $variableId);
    throw new RuntimeException('Fehler beim Schreiben; die Originalwerte wurden wiederhergestellt.', 0, $exception);
}

AC_ReAggregateVariable($archiveId, $variableId);

echo sprintf(
    "Fertig. %d Archivwert(e) von Variable %d wurden durch 1000 geteilt.\n",
    count($scaledValues),
    $variableId
);