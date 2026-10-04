# Markisensteuerung

[![IP-Symcon ab 8.2](https://img.shields.io/badge/IP--Symcon-ab_8.2-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
![Modul-Version 1.0](https://img.shields.io/badge/Modul--Version-1.0-informational.svg)
[![Tests](https://github.com/cfaf2002/Markisensteuerung_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Markisensteuerung_Symcon/actions/workflows/tests.yml)
![Sprachen: Deutsch, Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777bb4.svg?logo=php&logoColor=white)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Darstellungen statt Profile](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
![Sicherheit zuerst](https://img.shields.io/badge/Sicherheit-Wind_%7C_Regen_%7C_Frost_%7C_Sensorausfall-red.svg)
![Ohne Cloud](https://img.shields.io/badge/Cloud-nicht_n%C3%B6tig-brightgreen.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)

IP-Symcon-Modul für die automatische Steuerung einer Markise nach Sonne, Wind, Böen, Regen, Temperatur und Anwesenheit. Es ersetzt das bisherige Skript samt Debug-Kachel durch eine Instanz mit eigener Kachel. Die Markise wird über vorhandene Symcon-Variablen angesteuert, zum Beispiel die Ausfahren-/Einfahren-Variablen eines Somfy-Gateways.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [So entscheidet das Modul](#4-so-entscheidet-das-modul)
5. [Einstellungen](#5-einstellungen)
6. [Kachel](#6-kachel)
7. [Variablen und Darstellungen](#7-variablen-und-darstellungen)
8. [PHP-Befehle](#8-php-befehle)
9. [Umstieg vom bisherigen Skript](#9-umstieg-vom-bisherigen-skript)
10. [Sicherheit und Geschwindigkeit](#10-sicherheit-und-geschwindigkeit)
11. [Entwicklung und Tests](#11-entwicklung-und-tests)
12. [Changelog](#12-changelog)
13. [Lizenz](#13-lizenz)

## 1. Funktionsumfang

- **Drei Arten der Ansteuerung:** Befehlsvariablen für Ausfahren, Einfahren und optional Stopp (z. B. Somfy RTS über ein Gateway), eine Schaltvariable oder eine Positionsvariable (0–100 oder 0–1)
- **Sicherheit vor allem anderen:** Windalarm, Böenalarm, Regen, Frost, ausgefallener Windsensor und eingefrorener Helligkeitssensor fahren die Markise sofort ein, auf Wunsch auch bei ausgeschalteter Automatik
- **Wind- und Regensperre:** Nach einem Alarm bleibt die Markise einstellbare Minuten eingefahren, statt bei der nächsten Windpause wieder auszufahren
- **Wiederholung des Einfahrbefehls** bei Alarm nach der Fahrzeit, für Funkmotoren ohne Rückmeldung
- **Sonnenautomatik mit Hysterese und Verzögerung:** Ausfahren ab X lx nach Y Minuten, Einfahren unter Z lx nach W Minuten. Kein Flattern bei durchziehenden Wolken
- **Mindesttemperatur** mit Hysterese, **Windgrenze** für den normalen Betrieb (unterhalb des Alarms)
- **Sonnenrichtung:** Auf Wunsch nur ausfahren, wenn die Sonne wirklich auf die Markise scheint (Azimut-Bereich und Mindesthöhe). Der Sonnenstand wird lokal aus dem Symcon-Standort berechnet
- **Tag/Nacht-Prüfung** über die Sonnenhöhe, abschaltbar
- **Wochentage** und optional ein **Zeitfenster** (auch über Mitternacht)
- **Anwesenheit mit Karenz:** Nach dem Verlassen wird während der Karenz nicht ausgefahren, bei schlechteren Bedingungen eingefahren und nach Ablauf eingefahren. Karenz neu starten oder sofort beenden per Kachel oder Befehl
- **Handbetrieb:** Bedienung über Kachel, Variable, Fernbedienung oder andere Skripte pausiert die Automatik für einstellbare Minuten. Fernbedienung und andere Skripte werden an den Aktorvariablen erkannt; eigene Befehle zählen nicht
- **Keine Befehlsflut:** Befehle gehen nur bei einer Änderung raus, nicht bei jedem Durchlauf
- **Begründung in Worten** für jede Entscheidung, z. B. „Windalarm (7 Bft) → Markise eingefahren“
- **Push-Nachricht** bei Sicherheitsalarm (einmal pro Alarm) und optional bei jeder automatischen Fahrt
- **Grenzwerte und Wochentage in der Visualisierung einstellbar**, auf Wunsch
- **Eigene Kachel** im Symcon-Design mit gezeichneter Markise, Wetter, Begründung, Countdown, Sensorliste und Tasten
- Deutsch und Englisch nach Symcon-Konvention: englische Texte im Modul, deutsche Übersetzung in `locale.json`
- Automatische Tests mit GitHub-Workflow

## 2. Voraussetzungen und Technik

- IP-Symcon ab Version 8.2, empfohlen 9.0
- Aktorvariablen mit Aktion (z. B. vom Somfy-, Shelly-, Homematic- oder KNX-Modul)
- Optional: Sensoren für Helligkeit, Außentemperatur, Wind, Böen, Regen und eine Anwesenheitsvariable
- Für Tag/Nacht und Sonnenrichtung den Standort unter **Kern Instanzen → Location**
- Für Push-Nachrichten ein gültiges Symcon-Abo und registrierte Geräte

Das Modul nutzt die aktuelle Symcon-Technik:

| Technik | Ab Symcon | Wofür |
|---|---|---|
| Basisklasse `IPSModuleStrict` | 8.1 | Strenge Typen in allen Modulfunktionen, robust unter PHP 8.5 (Symcon 9.0) |
| Darstellungen statt Variablenprofilen | 8.0 | Schalter (Automatik), Aufzählung mit Tasten (Markise), Schieberegler (Position, Grenzwerte), Wertanzeige mit Symbolen und Farben (Status, Sicherheit) |
| HTML-SDK-Kachel | 7.1 | Eigene Kachel mit Live-Aktualisierung über `UpdateVisualizationValue` und Bedienung über `requestAction` |
| Design der Visualisierung | 9.0 | Farbschema „Symcon-Design“ übernimmt Schrift- und Akzentfarbe des gewählten Designs, hell wie dunkel |

Dazu: Timer rufen über `IPS_RequestAction` interne Aktionen auf (keine zusätzlichen öffentlichen Befehle), bei den Aktorvariablen lassen sich nur Variablen mit Aktion auswählen (`requiredAction`), und alle verwendeten Variablen sind als Referenz der Instanz eingetragen.

## 3. Installation

Im Objektbaum unter **Kern Instanzen → Modules** das Repository hinzufügen:

```
https://github.com/cfaf2002/Markisensteuerung_Symcon
```

Danach eine Instanz **Markisensteuerung** anlegen, Ansteuerung und Sensoren auswählen, fertig. Die Automatik ist nach dem Anlegen eingeschaltet.

## 4. So entscheidet das Modul

Bei jeder Sensoränderung und zusätzlich einmal pro Minute geht das Modul diese Liste von oben nach unten durch. Die erste zutreffende Regel entscheidet.

| Rang | Regel | Ergebnis |
|---|---|---|
| 1 | Windalarm, Böenalarm, Windsensor ohne Werte, Regen, Frost, Helligkeitssensor eingefroren, Wind- oder Regensperre läuft | einfahren (auch bei Automatik aus, wenn eingestellt) |
| 2 | Automatik aus | nichts tun |
| 3 | Handbetrieb-Pause läuft | nichts tun |
| 4 | Heute nicht freigegeben | einfahren |
| 5 | Nacht (Sonne unter der eingestellten Höhe) | einfahren |
| 6 | Außerhalb des Zeitfensters | einfahren |
| 7 | Sonnenautomatik: hell genug, warm genug, Wind unter der Grenze, Sonne auf der Markise, und das für die Ausfahrverzögerung | ausfahren |
|   | zu dunkel, zu kalt oder Sonne weg, und das für die Einfahrverzögerung | einfahren |
|   | Wind über der normalen Grenze | sofort einfahren |
| 8 | Abwesend: während der Karenz nicht ausfahren, nach der Karenz | einfahren |

Fehlt ein Sensor, wird die zugehörige Bedingung nicht geprüft. Ohne Windsensor gibt es also keinen Windschutz.

## 5. Einstellungen

### Instanz aktiv

Ganz oben in der Instanz. Ausgeschaltet macht die Instanz gar nichts mehr: keine Befehle, auch keine Sicherheitsfahrten, keine Timer. Die Kachel zeigt „Inaktiv“. Variablen und Einstellungen bleiben erhalten. Zum Abschalten der Sonnenautomatik bei laufendem Windschutz ist stattdessen die Variable „Automatik“ gedacht.

### Ansteuerung

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Ansteuerung über | Befehlsvariablen | **Befehlsvariablen:** je eine Variable für Ausfahren und Einfahren, optional Stopp; ausgelöst mit `RequestAction(ID, true)`. **Schaltvariable:** an = ausgefahren, auf Wunsch invertiert. **Positionsvariable:** Prozentwert für ausgefahren und eingefahren, Wertebereich 0–100 oder 0–1 |
| Fahrzeit | 60 s | Dauer einer vollen Fahrt. Für die Anzeige „fährt aus/ein“ und für die Wiederholung des Einfahrbefehls |
| Einfahrbefehl wiederholen | an | Bei Sicherheitsalarm wird der Einfahrbefehl nach der Fahrzeit noch einmal gesendet. Wichtig für Funkmotoren ohne Rückmeldung (Somfy RTS), bei denen ein Befehl verloren gehen kann |

### Sensoren

Helligkeit (lx), Außentemperatur (°C), Windgeschwindigkeit und Böen jeweils mit Einheit (Bft, km/h, m/s), Regen (an oder Wert über 0), Anwesenheit (an = jemand zu Hause). Alle optional.

### Sicherheit

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Windalarm ab | 6 | In der Einheit des Windsensors |
| Böenalarm ab | 28 | In der Einheit des Böensensors |
| Nach dem letzten Windalarm eingefahren lassen | 15 min | Die Sperre beginnt mit jedem neuen Alarmwert von vorn |
| Nach Regen eingefahren lassen | 10 min | |
| Frostschutz | an, ≤ 3 °C | Bei Frost wird eingefahren und auch von Hand nicht ausgefahren |
| Windsensor liefert keine Werte | 120 min | Meldet weder Wind- noch Böensensor in dieser Zeit einen Wert, wird eingefahren (0 = aus) |
| Helligkeitssensor eingefroren | 30 min, 1 lx | Ändert sich die Helligkeit tagsüber so lange um weniger als den Mindestwert, gilt der Sensor als eingefroren (0 = aus). Nachts wird nicht geprüft |
| Sicherheit auch bei ausgeschalteter Automatik | an | |

### Sonnenautomatik

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Ausfahren ab | 30.000 lx | |
| Einfahren unter | 20.000 lx | Dazwischen bleibt alles, wie es ist (Hysterese) |
| Ausfahren nach Sonne seit | 5 min | |
| Einfahren nach keiner Sonne seit | 15 min | |
| Mindesttemperatur | 18 °C | Eingefahren wird erst unter Mindesttemperatur minus Hysterese |
| Temperatur-Hysterese | 1 K | |
| Einfahren über Wind | 4 | Normale Windgrenze ohne Alarm und ohne Sperre |
| Nur wenn die Sonne auf die Markise scheint | aus | Richtung von/bis (Azimut, 0° = Nord, 90° = Ost, 180° = Süd, 270° = West; über Nord wie 300° bis 60° geht auch) und Mindesthöhe. Unten im Formular steht der aktuelle Sonnenstand zum Einstellen |

### Zeiten

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Freigegebene Wochentage | alle | An anderen Tagen wird eingefahren |
| Tag/Nacht-Prüfung | an | Nachts wird eingefahren |
| Tag ab Sonnenhöhe | 0° | 0° entspricht Sonnenauf- und -untergang |
| Nur in einem Zeitfenster | aus | z. B. 09:00 bis 20:00 Uhr |

### Anwesenheit und Handbetrieb

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Karenz nach dem Verlassen | 30 min | |
| Automatik nach Handbetrieb pausieren | 60 min | 0 = keine Pause |
| Bedienung von außen erkennen | an | Änderungen an den Aktorvariablen, die nicht vom Modul kommen, zählen als Handbetrieb. Bei Tastervariablen zählt nur das Auslösen, bei Positionen nur eine echte Änderung |

### Benachrichtigungen und Anzeige

Push-Nachricht bei Sicherheitsalarm, bei jeder automatischen Fahrt, Ziel-Visualisierung (leer = erste Kachel-Visualisierung), Testnachricht. Eigene Kachel, Farbschema (Symcon-Design, Dunkel, Hell), Animationen reduzieren, Grenzwerte und Wochentage in der Visualisierung, Sonnenstand als Variablen.

### Status

| Code | Bedeutung |
|---|---|
| 102 | Aktiv |
| 104 | Instanz ist inaktiv (Schalter „Instanz aktiv“ aus) |
| 200 | Bitte Aktorvariablen auswählen |
| 201 | Eine Aktorvariable fehlt oder hat keine Aktion |
| 202 | Eine Sensorvariable existiert nicht |
| 203 | Helligkeit zum Einfahren ist höher als zum Ausfahren |

## 6. Kachel

Die Kachel zeigt die Markise an einer Hauswand, aufgerollt oder ausgefahren, und bewegt sie in der eingestellten Fahrzeit. Darüber das Wetter, das gerade entscheidet: Sonne, Wolke, Wind, Regen, Frost oder Mond.

- **Kopf:** Status mit Farbpunkt (grün = Sonnenschutz, blau = Info, grau = Pause/Nacht, rot pulsierend = Sicherheit) und Schalter für die Automatik
- **Begründung** der letzten Entscheidung
- **Countdown** für Wind-/Regensperre, Handbetrieb-Pause oder Karenz, mit „Automatik fortsetzen“, „Karenz neu starten“ und „Karenz beenden“
- **Sensorliste** mit Wert, Grenze und Ampelpunkt (ab ca. 260 × 340 Pixel)
- **Tasten** Einfahren, Stopp (nur wenn vorhanden), Ausfahren. Bei Sicherheitsalarm ist Ausfahren gesperrt
- Kleine Kacheln zeigen nur Status und Tasten

Farbschemas: **Symcon-Design** übernimmt Schrift- und Akzentfarbe der gewählten Visualisierung (das Markisentuch ist in der Akzentfarbe gestreift), **Dunkel** und **Hell** sind feste Schemas. Ist die Kachel nicht zu sehen, ruhen Animationen und Countdown. Die Systemeinstellung „Bewegung reduzieren“ wird beachtet.

## 7. Variablen und Darstellungen

| Ident | Name | Typ | Darstellung | Bedingung |
|---|---|---|---|---|
| Automatic | Automatik | Boolean | Schalter, bedienbar | immer |
| Control | Markise | Integer | Aufzählung mit Tasten: 0 Einfahren, 1 Stopp, 2 Ausfahren | immer |
| Position | Position | Integer | Schieberegler 0–100 %, bedienbar | Positionsvariable |
| State | Zustand | Integer | 0 eingefahren, 1 fährt ein, 2 fährt aus, 3 ausgefahren, 4 gestoppt | immer |
| Status | Status | Integer | Aufzählung mit Symbolen und Farben (siehe unten) | immer |
| Reason | Letzte Entscheidung | String | Wertanzeige | immer |
| Safety | Sicherheit | Boolean | OK / Alarm | immer |
| ManualUntil | Handbetrieb bis | Integer | ~UnixTimestamp | Pause > 0 |
| SunAzimuth, SunElevation | Sonnenazimut, Sonnenhöhe | Float | Wertanzeige in ° | Sonnenstand als Variablen |
| SetLuxOn, SetTempMin, SetWindMax, SetWindAlarm, SetGustAlarm | Grenzwerte | Integer/Float | Schieberegler | Einstellungen in der Visualisierung |
| SetDayCheck, SetWeekday1 … SetWeekday7 | Tag/Nacht-Prüfung, Montag … Sonntag | Boolean | Schalter | Einstellungen in der Visualisierung |

**Status:** 0 Automatik aus, 1 Wartet auf Sonne, 2 Sonnenschutz aktiv, 3 Handbetrieb, 4 Abwesend – Karenz, 5 Abwesend, 6 Tag nicht freigegeben, 7 Nacht, 8 Außerhalb des Zeitfensters, 9 Windalarm, 10 Regen, 11 Frost, 12 Sensorfehler

Änderungen an den Einstellvariablen landen direkt in den Eigenschaften der Instanz. Es gibt also nur eine Stelle, an der ein Grenzwert steht.

## 8. PHP-Befehle

```php
MARKISE_Evaluate(int $InstanzID): bool      // alle Bedingungen sofort neu bewerten
MARKISE_Extend(int $InstanzID): bool        // ausfahren (zählt als Handbetrieb, bei Alarm abgelehnt)
MARKISE_Retract(int $InstanzID): bool       // einfahren (zählt als Handbetrieb)
MARKISE_Stop(int $InstanzID): bool          // anhalten, sofern eine Stopp-Variable eingestellt ist
MARKISE_SetAutomatic(int $InstanzID, bool $Aktiv): void
MARKISE_EndManualPause(int $InstanzID): void
MARKISE_RestartGrace(int $InstanzID): void  // Abwesenheits-Karenz neu starten
MARKISE_EndGrace(int $InstanzID): void      // Abwesenheits-Karenz sofort beenden
```

## 9. Umstieg vom bisherigen Skript

Das Modul übernimmt die Logik des bisherigen Markisen-Skripts samt Debug-Kachel. So werden die alten Variablen zugeordnet:

| Bisher im Skript | Im Modul |
|---|---|
| `RequestAction(23729, true)` (Ausfahren) | Variable zum Ausfahren |
| `RequestAction(13556, true)` (Einfahren) | Variable zum Einfahren |
| Automatik (21845) | Variable „Automatik“ der Instanz |
| Wochentag-Schalter Mo–So | Freigegebene Wochentage (oder als Variablen in der Visualisierung) |
| Anwesenheit (23174) | Anwesenheit |
| Helligkeit (42781), Wind in Bft (24849), letzte Böe in km/h (14952), Regen (21798), Temperatur (12793) | Sensoren, Einheit Wind = Bft, Böen = km/h |
| Lux-Grenze (40421), Temp-Untergrenze (12680), WindMax (24065), Böen-Alarm (25064, Fallback 28) | Ausfahren ab, Mindesttemperatur, Einfahren über Wind, Böenalarm ab |
| `$WIND_ALARM = 6` | Windalarm ab 6 |
| Tag/Nacht-Prüfung (53620), Auf-/Untergang | Tag/Nacht-Prüfung über die Sonnenhöhe; kein Auf-/Untergangs-Ereignis nötig |
| Karenz 30 Minuten, „Karenz zurücksetzen“ (55965), „Karenz beenden“ (46238) | Karenz nach dem Verlassen; „Karenz neu starten“ und „Karenz beenden“ in der Kachel oder per Befehl |
| Helligkeit eingefroren nach 30 Min., Mindeständerung 1 lx | Helligkeitssensor eingefroren, gleiche Standardwerte |
| Debug-HTML-Variable (25999) | Kachel der Instanz und Variable „Letzte Entscheidung“ |
| Skript-Timer, ausgelöst durch Ereignisse | entfällt: Das Modul hört selbst auf die Sensoren |

Was sich gegenüber dem Skript verbessert:

- Befehle werden nur gesendet, wenn sich etwas ändert. Das Skript schickte bei jedem Lauf erneut „Einfahren“ an den Funkmotor.
- Ausfahrschwelle und Einfahrschwelle sind getrennt, dazu Verzögerungen. Durchziehende Wolken lassen die Markise nicht mehr pendeln.
- Nach einem Windalarm bleibt die Markise gesperrt, statt in der nächsten Windpause wieder auszufahren.
- Der eingefrorene Helligkeitssensor wird nur tagsüber geprüft. Nachts ist die Helligkeit zu Recht konstant.
- Fällt der Windsensor aus, wird eingefahren. Das Skript hätte mit dem letzten Wert weitergearbeitet.
- Frost, Zeitfenster, Sonnenrichtung, Handbetrieb-Erkennung und Push-Nachrichten sind dazugekommen.

Nach dem Umstieg das alte Skript und seine Ereignisse deaktivieren, damit nicht zwei Steuerungen gleichzeitig arbeiten.

## 10. Sicherheit und Geschwindigkeit

**Sicherheit der Markise**

- Sicherheitsregeln stehen vor allen anderen Regeln und gelten auf Wunsch auch bei ausgeschalteter Automatik und während einer Handbetrieb-Pause.
- Beim Eintritt in einen Alarm wird immer eingefahren, auch wenn der letzte Befehl schon „einfahren“ war (die Markise könnte per Fernbedienung ausgefahren worden sein). Optional wird der Befehl nach der Fahrzeit wiederholt.
- Wird bei Alarm von außen ausgefahren, fährt das Modul sofort wieder ein. Ausfahren von Hand wird bei Alarm abgelehnt, mit Begründung.
- Ausfall des Windsensors und eingefrorener Helligkeitssensor gelten als Sensorfehler und fahren ein.

**Sicherheit des Moduls**

- Die Kachel setzt alle Werte per `textContent`, nie als HTML. Die Startdaten werden mit `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` eingebettet, damit kein Wert das Skript der Kachel beenden kann. Die Tests prüfen das.
- `RequestAction` nimmt nur bekannte Idents an und prüft Werte und Bereiche. Unbekannte Idents und Werte werden mit einer Ausnahme abgelehnt.
- Einstellungen aus der Visualisierung gehen nur, wenn der Schalter dafür an ist, und werden vor dem Speichern begrenzt.
- Aktorvariablen werden vor jedem Befehl auf Existenz und Aktion geprüft. Fehlschläge werden protokolliert und beim nächsten Durchlauf erneut versucht.
- Kein `eval`, keine Internetverbindung, keine Zugangsdaten. Die Kommunikation der Kachel ist durch das Passwort der Visualisierung geschützt.
- Ein Semaphor verhindert, dass Timer und Sensoränderungen gleichzeitig entscheiden und doppelte Befehle schicken.

**Geschwindigkeit**

- Ereignisgesteuert: Das Modul reagiert sofort auf Sensoränderungen (`VM_UPDATE`); der Minutentimer übernimmt nur zeitabhängige Dinge wie Verzögerungen, Sperren und Karenz.
- Die Entscheidung ist reine Rechnung ohne Netzwerk und dauert Bruchteile einer Millisekunde. Der Sonnenstand wird höchstens einmal pro Minute berechnet.
- Variablen werden nur geschrieben, wenn sich ihr Wert ändert. Die Kachel bekommt nur dann Daten, wenn sich etwas Sichtbares geändert hat; die Helligkeit wird dafür auf 100 lx gerundet.
- Die Kachel lädt keine externen Dateien, zeichnet mit SVG und CSS und hält Animationen und Countdown an, solange sie nicht zu sehen ist.

## 11. Entwicklung und Tests

| Pfad | Inhalt |
|---|---|
| `Markisensteuerung/` | Modul: Entscheidung, Variablen, Formular und Kachel (`tile.html`) |
| `libs/MarkiseSunTrait.php` | Sonnenstand aus dem Symcon-Standort |
| `libs/MarkiseActuatorTrait.php` | Ansteuerung und Erkennung von Handbetrieb |
| `libs/MarkiseTileTrait.php` | Kacheldaten |
| `libs/MarkiseNotifyTrait.php` | Push-Nachrichten |
| `*/locale.json` | deutsche Übersetzung (Symcon-Format, Schlüssel `de`) |
| `tests/` | Testumgebung ohne Symcon und Testsuite |

```
php tests/run.php
php tests/stubs.php <Pfad zu SymconStubs>
```

Die Testsuite bildet die Symcon-Basisklasse nach, simuliert Sensoren, Aktoren und eine Uhr und prüft unter anderem Ausfahren ohne Befehlsflut, Verzögerung und Hysterese, Wind-, Böen-, Regen- und Frostschutz mit Sperren, Sensorausfall, eingefrorene Helligkeit (nur tagsüber), Handbetrieb und Erkennung der Fernbedienung, Wochentage, Nacht, Zeitfenster über Mitternacht, Anwesenheit mit Karenz, Sonnenrichtung und Sonnenstand, alle drei Arten der Ansteuerung, Wiederholung fehlgeschlagener Befehle, Benachrichtigungen, Einstellungen aus der Visualisierung, abgelehnte Aktionen, die Kachel (inklusive Schutz vor eingeschleustem HTML und sparsamer Updates), das Formular und die Vollständigkeit der Übersetzung. Mit `DEBUG=1` werden die Debug-Ausgaben angezeigt.

`tests/stubs.php` lädt die Bibliothek zusätzlich mit den offiziellen [Symcon-Stubs](https://github.com/symcon/SymconStubs), legt eine Instanz an, verbindet Aktor- und Sensorvariablen und prüft, dass der Ausfahrbefehl über die Aktion ankommt.

GitHub Actions (`.github/workflows/tests.yml`) prüft bei jedem Push mit PHP 8.3 und 8.5 die Syntax, alle JSON-Dateien, die Testsuite und den Ladetest.

## 12. Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.0 | 2 | 04.10.2026 | Schalter „Instanz aktiv“, Hersteller eingetragen |
| 1.0 | 1 | 04.10.2026 | Erste Version als Modul, abgelöst vom bisherigen Markisen-Skript |

## 13. Lizenz

Dieses Modul steht unter der **MIT-Lizenz** (siehe Datei [`LICENSE`](LICENSE)).

Das Modul darf jeder kostenlos nutzen, verändern und weitergeben, auch kommerziell. Bedingung ist nur, dass der Copyright-Hinweis und der Lizenztext in Kopien erhalten bleiben. Eine Gewährleistung gibt es nicht.

Jede Code-Datei trägt einen Lizenzkopf mit `SPDX-License-Identifier: MIT`. Wer das Modul weitergibt oder Teile davon übernimmt, behält diesen Kopf und die Datei `LICENSE` bei.

**Hinweis:** Die Markisensteuerung ersetzt keinen vom Hersteller vorgesehenen Windwächter. Wer eine teure Markise schützen will, sollte zusätzlich einen direkt am Motor wirkenden Wind- oder Vibrationssensor verwenden. Somfy ist eine Marke der Somfy SAS; dieses Modul steht in keiner Verbindung zu Somfy.
