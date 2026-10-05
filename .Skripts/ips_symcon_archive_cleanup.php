<?php

declare(strict_types=1);

/*
 * IP-Symcon Skript:
 * Loescht alle Archivwerte einer Variable, deren Wert oberhalb der definierten
 * Schwelle liegt, und stoesst anschliessend die Neuaggregation an.
 */

$variableId = 12345;      // ID der Archiv-Variable eintragen
$threshold  = 150.0;     // Werte groesser als diese Schwelle werden geloescht
$startTime  = 0;          // 0 = ab Beginn des Archivs
$endTime    = time();     // Bis jetzt

$archiveIds = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
if (count($archiveIds) === 0) {
    throw new RuntimeException('Kein Archiv-Control in IP-Symcon gefunden.');
}

$archiveId = $archiveIds[0];

if (!IPS_VariableExists($variableId)) {
    throw new InvalidArgumentException(sprintf('Variable mit ID %d wurde nicht gefunden.', $variableId));
}

$variable = IPS_GetVariable($variableId);
if (!in_array($variable['VariableType'], [1, 2], true)) {
    throw new InvalidArgumentException('Die Variable muss numerisch sein (Integer oder Float).');
}

$values = AC_GetLoggedValues($archiveId, $variableId, $startTime, $endTime, 0);

if ($values === false) {
    throw new RuntimeException('Archivwerte konnten nicht geladen werden.');
}

$deletedCount = 0;

foreach ($values as $entry) {
    if (!array_key_exists('Value', $entry) || !array_key_exists('TimeStamp', $entry)) {
        continue;
    }

    if ($entry['Value'] > $threshold) {
        AC_DeleteVariableData($archiveId, $variableId, $entry['TimeStamp'], $entry['TimeStamp']);
        $deletedCount++;
    }
}

AC_ReAggregateVariable($archiveId, $variableId);

echo sprintf(
    "Fertig. %d Archivwert(e) fuer Variable %d oberhalb von %s wurden geloescht. Reaggregation angestossen.\n",
    $deletedCount,
    $variableId,
    (string) $threshold
);
