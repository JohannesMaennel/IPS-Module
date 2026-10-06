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

- Der HTML-Startbutton ist eine graue SVG-Flamme mit Schloss und Haltering.
  Zum Entriegeln drei Sekunden halten (Touch/Maus oder Leertaste/Enter),
  loslassen und danach separat antippen. Ein kurzer Druck startet nichts.
  Fokus-/Sichtbarkeitsverlust, Fehler oder ungueltiger Status sperren erneut.
  Die Freigabe betrifft die UI; bestehende IPS-Variablenaktionen bleiben
  unveraendert.
- Beim Start faerbt sich die Flamme ueber etwa zehn Sekunden von unten nach
  oben. Ohne bestaetigtes Hauptbrennerbit bleibt die Fuellung unter 100 Prozent.
  Erst Bit 2 zeigt die kleine farbige **AUS**-Flamme oben links und die
  zentrale Regelung. Ohne Bestaetigung endet die UI-Wartephase nach
  30 Sekunden wieder verriegelt; das ist keine automatische Abschaltung.
  Licht und Boost bleiben unabhaengig bedienbar. Im autonomen Temperaturmodus
  bleiben Modus/Solltemperatur auch bei ausgeschaltetem Brenner erreichbar.
- Hauptbrenner starten: Kommando 101 an Register 40200; erst Statusbit 2
  bestaetigt den eingeschalteten Hauptbrenner. Kommando 100 wird nicht verwendet.
- Hauptbrenner ausschalten: Kommando 3. Die gekoppelte Reaktion des
  Zweitbrenners erfolgt im Kamin; das Modul sendet dabei kein separates
  Zweitbrenner-Kommando.
- Zweitbrenner, Licht und Boost-Luefter werden nur dann als Variablen und
  UI-Aktionen angeboten, wenn die jeweilige Installationsoption aktiviert ist.
- Licht und Boost-Luefter benoetigen keine Hauptbrenner-Zuendung.
- Bei Fault erscheint **Kamin zuruecksetzen**. Er ist nur mit aktuellem Status
  und gesetzter Benutzer-Resetfreigabe bedienbar. Register 40203 verwendet
  Bit 0 fuer Fault und Bit 6 fuer die Freigabe (Positionen 1 und 7).
  Der Backend-Aufruf `ResetFireplace` prueft den Status erneut und sendet
  Kommando 1000 an 40200. Waehrend der Rueckmeldung sind weitere Resets gesperrt.
  Polling entfernt den Button erst bei geloeschtem Fault. Bleibt Fault nach
  20 Sekunden bestehen, wird dies protokolliert und der Button entsprechend
  der aktuellen Freigabe wieder bedienbar. Kein automatischer Reset oder
  automatisches erneutes Zuenden.

Fehler und Zuendschritte stehen im Symcon-Log. Der Instanz-Debug zeigt
Statusrueckmeldungen und fuer FC6-Schreibbefehle auch TX-Daten und die
hexadezimale Gateway-Antwort. Ein Zuend-Timeout ist kein Erfolgsnachweis:
Die tatsaechliche Reaktion des Kamins muss am Geraet geprueft werden.

FC6 sendet binaere Big-Endian-Registerwerte, fuer den JSON-Transport
mit `mb_convert_encoding()` von ISO-8859-1 nach UTF-8 kodiert, keinen Hextext.
Hex wird nur im Debug angezeigt. FC6 muss Funktionscode, Adresse und Wert
exakt bestaetigen. Abweichungen und Modbus-Exceptions werden protokolliert und
brechen die abhaengige Aktion ab. Fuer Register 40200 und Kommando 101
lautet die erwartete FC6-Antwort `069d080065`. `069d083030` bestaetigt dagegen
den falschen Wert 0x3030 (ASCII "00") und wird nicht als Erfolg akzeptiert.

**Welle speichern** schreibt das Intervall in 40420 und die zehn gepackten
Stufenregister 40421-40430 einzeln mit FC6. Das reale DRU-Geraet mit Unit-ID 2
hat einen korrekt formatierten FC16-Auftrag fuer diese elf Register mit
`90 03` (ILLEGAL_DATA_VALUE) abgelehnt. Deshalb wird kein FC16-Auftrag gesendet.
Die elf Schreibtelegramme erfolgen nur beim expliziten Speichern, nicht beim
Bewegen der Regler. Jeder Wert muss bestaetigt werden; beim ersten Fehler
stoppt die Folge ohne Wiederholung. Bereits bestaetigte Register koennen
dann am Geraet geaendert sein; der Teilfortschritt wird protokolliert.
Nach dem Schreibversuch werden die realen Register zurueckgelesen, auch
nach Teilfehlern. Nur ein vollstaendiger, gueltiger Lesestand ersetzt die
lokalen IPS-Daten. Das Schreiben von 40420 wurde am realen Geraet bestaetigt;
40421 wurde auch mit FC6 und gueltigen gepackten Stufen mit
ILLEGAL_DATA_VALUE abgelehnt. Die Ursache dieser Geraeteablehnung ist offen;
Bytefolge oder Registeradressen werden nicht spekulativ geaendert.

Bei aktivierter Installationsoption Wave liest das Modul 40420-40430 bei
**Aenderungen uebernehmen**, bei Aktivierung/Anwahl von Wave, bei einer
extern erkannten Wave-Aktivierung und zyklisch alle 60 Sekunden.
Die Statusabfrage bleibt bei fuenf Sekunden. Pro Register werden zwei
Stufen gelesen: zuerst LSB, dann MSB, Werte 1-15 werden auf 0-100 Prozent
umgerechnet. Der Debug **Wave Lesen** zeigt die Rohwerte und beide Bytes.
Bei unvollstaendigen oder ungueltigen Lesedaten zeigt die UI einen Hinweis
statt eines editierbaren lokalen Ersatzmusters. Unveraenderte Lesedaten
setzen noch nicht gespeicherte Reglerbewegungen nicht zurueck.

Gateway-Warnungen (z.B. `ILLEGAL_DATA_VALUE`, Modbus-Exception 03) werden nur
waehrend des synchronen `SendDataToParent()`-Aufrufs abgefangen und mit
Funktionscode, Registeradresse, Registeranzahl und Datenhex protokolliert.
Der vorherige PHP-Fehlerhandler wird auch nach Exceptions wiederhergestellt.
Eine Warnung bedeutet einen fehlgeschlagenen Auftrag; angeforderte
Wave-Einstellungen werden nicht als gespeichert ausgegeben. Ein anschliessender
erfolgreicher Readback synchronisiert stattdessen den realen Geraetestand.
Es erfolgt kein automatischer
Wiederholungsversuch oder Wechsel zu anderen Schreibbefehlen.
Bei einer Ablehnung die zugehoerigen Eintraege **Modbus TX**, **Modbus RX**
und **Fehler** pruefen. Ohne diese Daten laesst sich nicht unterscheiden,
ob Paketformat, Werte oder ein Geraetezustand die Ablehnung verursachen.

## Regressionstests

### Symbolfamilie und Wave-Vorlagen

Die rahmenlosen SVG-Buttons verwenden dieselbe Flammenform. Im Betrieb steht
die Hauptbrenner-Flamme ohne AUS-Text und der optionale Zweitbrenner als
Mini-Flamme mit der Ziffer 2 neben Licht/Boost in der oberen Statusleiste.
Aktive Brenner nutzen denselben dezenten Hintergrund wie andere Schalter.
Licht nutzt eine Gluehbirne, Boost einen Ventilator,
Reset einen Ruecksetz-Pfeil mit Fehlerindikator. Die obere Statuszeile enthaelt
Isttemperatur, Licht/Boost als Symbolschalter und eine einzelne Modusauswahl
in festen Bereichen: Schalter links, Temperaturen mittig, Modus rechts.
Bei kleinen Kacheln stehen die Temperaturen zentriert in einer eigenen,
hoehenreservierten Kopfzeilen-Reihe. Die Sollanzeige verschiebt keine Buttons.
Rechts neben dem Modus-Dropdown bleiben 32 Pixel fuer die IPS-Maximieren-Aktion
frei. Die produktive Darstellung fuellt die Kachelhoehe; Kopf- und Fusszeile
bleiben sichtbar, nur der Mittelteil scrollt. Die Offline-Vorschau simuliert
dafuer eine begrenzte Kachelhoehe (260-500 Pixel je nach Fensterhoehe).
Die Modusauswahl erscheint
als Dropdown mit Hand-, Thermometer- und Wellensymbol.
Das ausgewaehlte Modussymbol ist ein einfarbiges SVG innerhalb des Dropdowns,
kein plattformabhaengiges Emoji. Der Dropdown-Pfeil steht links; die native
Optionsliste zeigt Hand, Thermometer und Welle neben den Modusnamen.
Unicode-Symbole verwenden die Textdarstellungs-Vorgabe; die Darstellung
innerhalb der nativen Optionsliste bleibt vom mobilen Betriebssystem abhaengig.
Bei bestaetigter Temperaturregelung zeigt sie zusaetzlich Aktivsymbol und Solltemperatur,
auch wenn die Regelung den Brenner ausschaltet. Status-/Fehlermeldungen stehen
in der Fusszeile. Leistung und Solltemperatur sind horizontal mit
kleiner/grosser Flamme. Schalter behalten ARIA-Beschriftungen und Tooltips.
Die Leistung ist die letzte Vorgabe, kein gemessener Istwert; bei erkannter
neuer Zuendung wird sie auf 100 Prozent gesetzt, ohne Zusatz-Schreibbefehl.

Das Layout ist fuer kleine IPS-Kacheln verdichtet: moderne Systemschrift
(unter Windows Segoe UI), kurze Abstaende und kleinere Symbole.
Schriften werden nicht extern geladen. Schaltflaechen behalten mindestens
44 Pixel Hoehe; der Drei-Sekunden-Halteablauf bleibt unveraendert.
Beide horizontalen Regler zeigen Beschriftung und Wert mittig.
Die Wave-Ansicht zeigt Profilwahl und Stift ohne zusaetzliche sichtbare Labels.
Der Stift klappt Intervall und 20 vertikale Regler mit Abstand zur Profilinfo
unterhalb auf. Die Diskette steht neben dem Stift und ist nur bei geoeffnetem
Bearbeitungsmodus und gueltigem Geraetestatus bedienbar.
Profilwechsel laden JSON-Vorlagen bzw. den aktuellen
Geraetestand; unveraenderte Heartbeats erhalten den Entwurf. Nur die Diskette
sendet einen Schreibauftrag und klappt die Details wieder zu. Das Zuklappen
bestaetigt keinen Schreiberfolg; Fehler werden weiterhin in der Fusszeile
gemeldet, und der Backend-Readback zeigt den tatsaechlichen Geraetestand.
Es wird kein Popup verwendet:
Das HTML-SDK dokumentiert keine native Popup-Oeffnungsfunktion, und das
Popup-Modul ist laut Symcon-Dokumentation nicht in der Kachelvisualisierung
verfuegbar. Es werden keine Popup-Variablen oder Navigationsskripte angelegt.

`UI/wave-presets.json` enthaelt drei Profile mit je zwei Wellen ueber 20 Stufen:
50-100 Prozent / 10 Sekunden, 20-60 Prozent / 20 Sekunden und
10-100 Prozent / 10 Sekunden. Die Auswahl aendert nur den Editor.
Erst **Wave-Muster speichern** schreibt auf das Geraet. Initial wird immer
das gelesene Geraeteprofil gezeigt. Die Auswahl **Aktuelles Profil (Kamin)**
verwirft den Entwurf und fordert einen neuen Readback an; ein Fehler ergibt kein Ersatzprofil.
Ein abweichender Geraetestand ersetzt den Entwurf; unveraenderte Heartbeats nicht.
Die quantisierte Umsetzung auf 1-15 Geraetestufen bleibt unveraendert.

Die Vorschau nutzt die aus JSON generierte `UI/preview-presets.js`, damit sie
auch per Doppelklick ohne Fetch/Webserver funktioniert. Nach Aenderungen an
der JSON-Datei einmal `php ".\DRU Kamin\tests\build-preview-presets.php"`
ausfuehren. Das produktive Tile liest die JSON-Datei direkt.

### Lokale UI-Vorschau ohne Kamin

`UI/preview.html` per Doppelklick im Browser oeffnen. Kein Webserver, PHP oder
IP-Symcon erforderlich. Die Seite laedt die produktiven `app.js` und `main.css`,
ersetzt `requestAction` aber ausschliesslich durch lokale Simulationen in
`preview.js`. Die Vorschauseite wird nicht vom produktiven Tile geladen.

Oben lassen sich Aus, Betrieb, Wave, Temperaturregelung, Fault, gesperrter
Reset, gesperrte Zuendung und Verbindungsverlust auswaehlen. Im Aus-Zustand
drei Sekunden halten, loslassen, separat klicken: Nach zehn Sekunden wird
der Brennerstatus simuliert. Die Checkboxen erlauben fehlgeschlagene Zuendung
und einen weiterhin bestehenden Fehler nach Reset. Das Aktionsprotokoll
zeigt alle simulierten Aufrufe. Browser neu laden setzt die Vorschau zurueck.
Die Simulation prueft Darstellung und Bedienung, nicht Modbus oder Hardware.

Ohne laufenden Symcon-Kernel:

```powershell
php -n ".\DRU Kamin\tests\regression.php"
```

Der Test simuliert die IPS-Schnittstellen, einschliesslich einer ausschliesslich
String-basierten `UpdateVisualizationValue()`-Signatur. Er prueft Lifecycle,
Timer, externe Statusaenderungen, Verbindungsverlust und Wiederherstellung,
Optionen und Brennerbefehle sowie binaere FC6-Daten, deren
Schreibbestaetigungen und den Abbruch teilweise geschriebener Wave-Folgen.
PHP benoetigt die in Symcon vorhandene Erweiterung
`mbstring`; bei einer portablen CLI diese ebenfalls aktivieren.
Er ersetzt keinen Hardwaretest.

Die Browserregression in `tests/ui-regression.js` wird nach `UI/app.js`
in einer Testseite mit `druData`, `controls`, `connectionStatus` und
`temperature` geladen. `await window.runDRUUiRegression()` prueft den
JSON-Nachrichtenweg, widerspruechliche Variablenwerte, Statusbits,
Bedienfreigaben und das Verfallen veralteter Nachrichten.
