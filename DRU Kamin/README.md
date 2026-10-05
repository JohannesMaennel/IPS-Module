# DRU Kamin

## Verbindung und Status

Das Modul verwendet die native IPS-Parent-Anbindung mit `ConnectParent()`.
Ein vorhandenes kompatibles Modbus-Gateway kann wiederverwendet werden.
`RequireParent()` wird nicht verwendet, da es einen neuen Parent erzwingt.
Gateway und I/O werden nicht durch einen eigenen Konfigurationsassistenten
angelegt. Bereits vorhandene Verbindungen bleiben unveraendert.

Nach einem Modulupdate die Instanz einmal mit **Aenderungen uebernehmen**
aktualisieren und die HTML-Kachel neu laden. Zuvor versehentlich erzeugte
Gateways und I/O-Instanzen werden nicht automatisch geloescht: Die vorhandene
DRU-Instanz gegebenenfalls wieder mit dem gewuenschten Gateway verbinden.

Der Timer liest Register 40203 alle fuenf Sekunden, auch nach einem
Verbindungsverlust. Status 102 bedeutet erfolgreiche Modbus-Kommunikation,
104 eine nicht aktive Parent-Kette und 201 einen fehlgeschlagenen Statusabruf.
Eine erfolgreiche spaetere Abfrage stellt den aktiven Zustand wieder her.

Die HTML-SDK-Nachrichten werden als JSON-String gesendet und in `handleMessage()`
decodiert. Die Anzeige der Schalter folgt den Bits aus 40203, nicht einem
optimistisch gesetzten Variablenwert. Ohne gueltige Rueckmeldung erscheint
**Unbekannt**; die Bedienung wird gesperrt. Bleiben Nachrichten aus, verfaellt
der Status nach maximal 15 Sekunden.

## Bedienung

- Hauptbrenner starten: Kommando 101 an Register 40200; erst Statusbit 2
  bestaetigt den eingeschalteten Hauptbrenner. Kommando 100 wird nicht verwendet.
- Hauptbrenner ausschalten: Kommando 3. Die gekoppelte Reaktion des
  Zweitbrenners erfolgt im Kamin; das Modul sendet dabei kein separates
  Zweitbrenner-Kommando.
- Zweitbrenner, Licht und Boost-Luefter werden nur dann als Variablen und
  UI-Aktionen angeboten, wenn die jeweilige Installationsoption aktiviert ist.
- Licht und Boost-Luefter benoetigen keine Hauptbrenner-Zuendung.

Fehler und Zuendschritte stehen im Symcon-Log. Der Instanz-Debug zeigt
Statusrueckmeldungen und fuer FC6-Schreibbefehle auch TX-Daten und die
hexadezimale Gateway-Antwort. Ein Zuend-Timeout ist kein Erfolgsnachweis:
Die tatsaechliche Reaktion des Kamins muss am Geraet geprueft werden.

FC6 und FC16 senden binaere Big-Endian-Registerwerte, fuer den JSON-Transport
mit `mb_convert_encoding()` von ISO-8859-1 nach UTF-8 kodiert, keinen Hextext.
Hex wird nur im Debug angezeigt. FC6 muss Funktionscode, Adresse und Wert
exakt bestaetigen; FC16 muss Funktionscode, Startadresse und Registeranzahl
bestaetigen. Abweichungen und Modbus-Exceptions werden protokolliert und
brechen die abhaengige Aktion ab. Fuer Register 40200 und Kommando 101
lautet die erwartete FC6-Antwort `069d080065`. `069d083030` bestaetigt dagegen
den falschen Wert 0x3030 (ASCII "00") und wird nicht als Erfolg akzeptiert.

## Regressionstests

Ohne laufenden Symcon-Kernel:

```powershell
php -n ".\DRU Kamin\tests\regression.php"
```

Der Test simuliert die IPS-Schnittstellen, einschliesslich einer ausschliesslich
String-basierten `UpdateVisualizationValue()`-Signatur. Er prueft Lifecycle,
Timer, externe Statusaenderungen, Verbindungsverlust und Wiederherstellung,
Optionen und Brennerbefehle sowie binaere FC6-/FC16-Daten und deren
Schreibbestaetigungen. PHP benoetigt die in Symcon vorhandene Erweiterung
`mbstring`; bei einer portablen CLI diese ebenfalls aktivieren.
Er ersetzt keinen Hardwaretest.

Die Browserregression in `tests/ui-regression.js` wird nach `UI/app.js`
in einer Testseite mit `druData`, `controls`, `connectionStatus` und
`temperature` geladen. `await window.runDRUUiRegression()` prueft den
JSON-Nachrichtenweg, widerspruechliche Variablenwerte, Statusbits,
Bedienfreigaben und das Verfallen veralteter Nachrichten.
