# Markisensteuerung

[![IP-Symcon ab 8.2](https://img.shields.io/badge/IP--Symcon-ab_8.2-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
![Modul-Version 1.4](https://img.shields.io/badge/Modul--Version-1.4-informational.svg)
[![Tests](https://github.com/cfaf2002/Markisensteuerung_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Markisensteuerung_Symcon/actions/workflows/tests.yml)
![Sprachen: Deutsch, Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777bb4.svg?logo=php&logoColor=white)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Darstellungen statt Profile](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
![Zwei Kacheln](https://img.shields.io/badge/Kacheln-Steuerung_%2B_Einstellungen-orange.svg)
![Sicherheit zuerst](https://img.shields.io/badge/Sicherheit-Wind_%7C_B%C3%B6en--Trend_%7C_Regen_%7C_Frost_%7C_Sensorausfall-red.svg)
[![DWD-Unwetterwarnung](https://img.shields.io/badge/DWD-Unwetterwarnung-darkred.svg)](https://github.com/Wilkware/IPSymconWeatherWarning)
![Abendmodus](https://img.shields.io/badge/Abendmodus-Halten_%2B_Terrassent%C3%BCr-6366f1.svg)
![Urlaub](https://img.shields.io/badge/Urlaub-Hausschalter-0f766e.svg)
![Umstieg](https://img.shields.io/badge/Umstieg-Skript_per_Knopfdruck_%C3%BCbernehmen-blue.svg)
![Simulation](https://img.shields.io/badge/Simulation-Testbetrieb_ohne_Fahrt-yellow.svg)
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
9. [Simulation (Testbetrieb)](#9-simulation-testbetrieb)
10. [Umstieg vom bisherigen Skript](#10-umstieg-vom-bisherigen-skript)
11. [Sicherheit und Geschwindigkeit](#11-sicherheit-und-geschwindigkeit)
12. [Entwicklung und Tests](#12-entwicklung-und-tests)
13. [Changelog](#13-changelog)
14. [Lizenz](#14-lizenz)

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
- **Simulation (Testbetrieb):** Das Modul entscheidet wie gewohnt, bewegt die Markise aber nicht. Sensorwerte und Uhrzeit lassen sich vorgeben, jede Entscheidung landet im Protokoll. Der echte Wind- und Regenschutz bleibt dabei auf Wunsch aktiv
- **Halten (Abendmodus):** Ein Schalter hält die Markise, wie sie ist, etwa um abends draußen zu sitzen. Nur Sicherheit fährt dann noch ein. Halten endet von selbst, wenn die Terrassentür geschlossen wird, die Karenz abgelaufen ist oder spätestens am nächsten Morgen
- **Urlaub:** Der Urlaubsschalter des Hauses (z. B. eine KNX-Variable) hält die Markise eingefahren, solange Urlaub ist. Die Sicherheit gilt weiter, nach dem Urlaub übernimmt die Automatik wieder
- **DWD-Unwetterwarnung:** Mit dem Modul „Unwetterwarnung“ von Wilkware fährt die Markise bei amtlichen Warnungen ab einer einstellbaren Stufe ein, meist schon bevor das Gewitter da ist. Die Warnstufe wird automatisch gefunden. Hitze- und UV-Warnungen zählen nicht, und fällt die Warnquelle aus, wird nichts blockiert
- **Böen-Trend:** Steigen die Böen schnell an, fährt die Markise vorsorglich ein, bevor der Böenalarm erreicht ist
- **Lux-Mittelwert und Schaltlimit:** Die Helligkeit wird über einige Minuten gemittelt, und die Sonnenautomatik fährt höchstens X-mal pro Stunde. Bei Aprilwetter bleibt die Markise ruhig
- **Standort:** aus dem Location-Modul oder eigene Koordinaten in der Instanz, per Taste aus dem Location-Modul übernehmbar
- **Übernahme aus dem bisherigen Skript:** Variablen-IDs, Grenzwerte und Wochentage per Knopfdruck einlesen. Das Skript wird nur gelesen, nie ausgeführt
- **Zweite Kachel „Markisen Einstellungen“:** Grenzwerte, Verzögerungen, Wochentage, Tag/Nacht, Zeitfenster, Anwesenheitsschalter und Karenz übersichtlich anzeigen und mit Plus/Minus, Schaltern und Wochentags-Tasten ändern. Auf Wunsch nur zur Anzeige
- **Eigene Kachel** im Symcon-Design mit gezeichneter Markise, Wetter, Begründung, Countdown, Sensorliste und Tasten
- Deutsch und Englisch nach Symcon-Konvention: englische Texte im Modul, deutsche Übersetzung in `locale.json`
- Automatische Tests mit GitHub-Workflow

## 2. Voraussetzungen und Technik

- IP-Symcon ab Version 8.2, empfohlen 9.0
- Aktorvariablen mit Aktion (z. B. vom Somfy-, Shelly-, Homematic- oder KNX-Modul)
- Optional: Sensoren für Helligkeit, Außentemperatur, Wind, Böen, Regen und eine Anwesenheitsvariable
- Für Tag/Nacht und Sonnenrichtung den Standort unter **Kern Instanzen → Location** oder eigene Koordinaten in der Instanz
- Optional für Unwetterwarnungen das Modul [Unwetterwarnung](https://github.com/Wilkware/IPSymconWeatherWarning) von Wilkware mit eingeschalteter „Indikatorvariable für aktive Warnungen“
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

Für die zweite Kachel zusätzlich eine Instanz **Markisen Einstellungen** anlegen und darin die Markisensteuerung auswählen (siehe [Einstellungs-Kachel](#einstellungs-kachel-markisen-einstellungen)).

## 4. So entscheidet das Modul

Bei jeder Sensoränderung und zusätzlich einmal pro Minute geht das Modul diese Liste von oben nach unten durch. Die erste zutreffende Regel entscheidet.

| Rang | Regel | Ergebnis |
|---|---|---|
| 1 | Windalarm, Böenalarm, Böen steigen schnell, Unwetterwarnung, Windsensor ohne Werte, Regen, Frost, Helligkeitssensor eingefroren, Wind- oder Regensperre läuft | einfahren (auch bei Automatik aus, wenn eingestellt) |
| 2 | Automatik aus | nichts tun |
| 3 | Handbetrieb-Pause läuft | nichts tun |
| 3a | Urlaub | einfahren |
| 3b | Halten (Abendmodus) ist an | nichts tun |
| 4 | Heute nicht freigegeben | einfahren |
| 5 | Nacht (Sonne unter der eingestellten Höhe) | einfahren |
| 6 | Außerhalb des Zeitfensters | einfahren |
| 7 | Sonnenautomatik: hell genug (Mittelwert), warm genug, Wind unter der Grenze, Sonne auf der Markise, und das für die Ausfahrverzögerung, Schaltlimit nicht erreicht | ausfahren |
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

Helligkeit (lx), Außentemperatur (°C), Windgeschwindigkeit und Böen jeweils mit Einheit (Bft, km/h, m/s), Regen (an oder Wert über 0), Anwesenheit (an = jemand zu Hause). Alle optional. Die Terrassentür wird unter „Halten (Abendmodus)“ eingestellt.

### Sicherheit

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Windalarm ab | 6 Bft | In der Einheit, die unter „Sensoren“ für den Windsensor gewählt ist (Bft, km/h oder m/s). Das Formular zeigt sie direkt am Feld an |
| Böenalarm ab | 28 km/h | In der Einheit des Böensensors |
| Nach dem letzten Windalarm eingefahren lassen | 15 min | Die Sperre beginnt mit jedem neuen Alarmwert von vorn |
| Nach Regen eingefahren lassen | 10 min | |
| Frostschutz | an, ≤ 3 °C | Bei Frost wird eingefahren und auch von Hand nicht ausgefahren |
| Windsensor liefert keine Werte | 120 min | Meldet weder Wind- noch Böensensor in dieser Zeit einen Wert, wird eingefahren (0 = aus) |
| Helligkeitssensor eingefroren | 30 min, 1 lx | Ändert sich die Helligkeit tagsüber so lange um weniger als den Mindestwert, gilt der Sensor als eingefroren (0 = aus). Nachts wird nicht geprüft |
| Böen-Trend | an, 12 in 15 min, ab 70 % | Steigen die Böen im Zeitfenster um mindestens den Anstieg und liegen schon beim eingestellten Anteil des Böenalarms, wird vorsorglich eingefahren, mit Windsperre. Der Anstieg gilt in der Einheit des Böensensors |
| Unwetterwarnung | aus | Fährt bei Warnstufe ab „Einfahren ab Stufe“ (Standard 2 = markantes Wetter) ein, solange die Warnung gilt. Warnstufe leer = automatisch die erste Instanz des Moduls „Unwetterwarnung“ nehmen; „Warnstufe suchen“ trägt sie ins Feld ein. Stufen ab 10 (Hitze, UV) zählen nicht |
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
| Helligkeit mitteln über | 10 min | Gleitender Mittelwert für Aus- und Einfahren; kurze Wolken oder Sonnenlücken lösen nichts aus (0 = aus). Der eingefrorene Sensor wird mit dem echten Wert geprüft |
| Höchstens Sonnen-Fahrten pro Stunde | 4 | Danach bleibt die Markise, wie sie ist, bis die Stunde um ist. Sicherheit, Nacht, Wochentag und Abwesenheit sind nie begrenzt (0 = unbegrenzt) |
| Einfahren über Wind | 4 | Normale Windgrenze ohne Alarm und ohne Sperre |
| Nur wenn die Sonne auf die Markise scheint | aus | Richtung von/bis (Azimut, 0° = Nord, 90° = Ost, 180° = Süd, 270° = West; über Nord wie 300° bis 60° geht auch) und Mindesthöhe. Unten im Formular steht der aktuelle Sonnenstand zum Einstellen |

### Standort

Breiten- und Längengrad für Tag/Nacht und Sonnenrichtung. Stehen beide auf 0, nimmt das Modul den Standort aus **Kern Instanzen → Location**. Die Taste „Aus dem Location-Modul übernehmen“ trägt diesen Standort in die Felder ein; danach lässt er sich frei anpassen. Unter den Feldern steht, welcher Standort gerade verwendet wird.

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
| Urlaubsschalter | – | Optional, Boolean. An = Urlaub; mit „Invertiert“ gilt aus = Urlaub. Im Urlaub fährt die Markise ein und nicht automatisch aus, Halten endet. Unter dem Feld steht der aktuelle Wert und seine Deutung |
| Bedienung von außen erkennen | an | Änderungen an den Aktorvariablen, die nicht vom Modul kommen, zählen als Handbetrieb. Bei Tastervariablen zählt nur das Auslösen, bei Positionen nur eine echte Änderung |

### Halten (Abendmodus)

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Schalter „Halten (Abendmodus)“ anbieten | an | Legt die Variable „Halten“ an und zeigt die Taste in der Kachel |
| Terrassentür | – | Optional. Wird die Tür geschlossen (Wechsel von offen auf zu), endet Halten. Wer Halten bei schon geschlossener Tür einschaltet, beendet es erst mit dem nächsten Schließen |
| Wert für „geschlossen“ | 0 | Der Wert, den die Türvariable bei geschlossener Tür hat. Unter den Feldern steht der aktuelle Wert und wie das Modul ihn deutet. Am einfachsten: Tür schließen und **Tür ist jetzt zu** klicken, dann wird der aktuelle Wert übernommen |

Halten endet außerdem, wenn die Abwesenheits-Karenz abgelaufen ist, und spätestens am nächsten Morgen, sobald es hell ist.

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

Die Kachel zeigt ein kleines Garten-Panorama: Haus mit Fenster und Terrassentür, Terrasse mit Tisch, Stühlen und Blumentopf, darüber die Markise, die in der eingestellten Fahrzeit aus- und einfährt und einen Schatten wirft. Der Himmel folgt Wetter und Tageszeit: blauer Himmel mit leuchtender Sonne, ziehenden Schönwetterwolken und Vögeln, Abendrot, bewölkt mit Sonne dahinter, Nacht mit Mond, Sternen und erleuchtetem Fenster, dunkle Regenwolken mit Regen, Sturm mit Windböen und fliegenden Blättern, Unwetter mit Blitz, Frost mit Schneeflocken. Ist die Terrassentür offen, steht sie auch im Bild offen. Die Sonne erscheint, sobald es tagsüber hell genug ist, nicht erst beim Ausfahren. Breite Kacheln zeigen das ganze Panorama, schmale den Ausschnitt vom Haus bis zur Markise.

- **Kopf:** Status mit Farbpunkt (grün = Sonnenschutz, blau = Info, grau = Pause/Nacht, rot pulsierend = Sicherheit) und Schalter für die Automatik
- **Begründung** der letzten Entscheidung
- **Countdown** für Wind-/Regensperre, Handbetrieb-Pause oder Karenz, mit „Automatik fortsetzen“, „Karenz neu starten“ und „Karenz beenden“
- **Sensorliste** mit Wert, Grenze und Ampelpunkt (ab ca. 260 × 340 Pixel). Bei Helligkeit und Temperatur heißt grün „reicht zum Ausfahren“, rot „so niedrig, dass eingefahren wird“ und grau „dazwischen, die Markise bleibt, wie sie ist“ (Hysterese)
- **Tasten** Einfahren, Stopp (nur wenn vorhanden), Ausfahren und Halten (Mond-Symbol, leuchtet wenn aktiv). Bei Sicherheitsalarm ist Ausfahren gesperrt
- **Sensorliste** zusätzlich mit Unwetter-Warnstufe und Terrassentür, bei der Helligkeit auch der Mittelwert, wenn er vom aktuellen Wert abweicht
- Kleine Kacheln zeigen nur Status und Tasten

Farbschemas: **Symcon-Design** übernimmt Schrift- und Akzentfarbe der gewählten Visualisierung (das Markisentuch ist in der Akzentfarbe gestreift), **Dunkel** und **Hell** sind feste Schemas. Ist die Kachel nicht zu sehen, ruhen Animationen und Countdown. Die Systemeinstellung „Bewegung reduzieren“ wird beachtet.

### Einstellungs-Kachel (Markisen Einstellungen)

Eine eigene Instanz vom Typ **Markisen Einstellungen** liefert eine zweite Kachel für die Grundwerte. So bleibt die Steuer-Kachel übersichtlich, und die Werte lassen sich trotzdem bequem pflegen.

- **Sonnenautomatik:** Ausfahren ab, Einfahren unter, Mindesttemperatur, Windgrenze, Ausfahr- und Einfahrverzögerung
- **Sicherheit:** Windalarm, Böenalarm und, mit Unwetterwarnung, „Einfahren ab Stufe“
- **Zeiten:** Wochentage als Tasten Mo–So, Tag/Nacht-Prüfung, Zeitfenster mit Uhrzeiten
- **Anwesenheit:** Urlaub wird mit angezeigt, lässt sich hier aber nicht schalten, weil der Schalter dem ganzen Haus gehört. Schalter für die eingestellte Anwesenheitsvariable (Beschriftung = Name der Variable) und Karenz. Der Schalter bedient die Variable über ihre Aktion wie ein Taster in der Visualisierung; ohne Aktion wird er nur angezeigt. In der Simulation schaltet er die Simulationsvariable. Änderungen von außen erscheinen sofort

Bei breiter Kachel stehen links Sonnenautomatik und Zeiten, rechts Sicherheit und Anwesenheit.

Die Kachel ist modern gestaltet: jede Gruppe als Karte mit Symbol, Zahlen in runden Plus/Minus-Pillen, Wochentage als runde Tasten, große Anwesenheitsanzeige mit grünem Punkt. Die Uhrzeiten des Zeitfensters sind abgeblendet, solange das Zeitfenster aus ist. Farben kommen aus dem gewählten Symcon-Design.

Zahlen ändern sich mit Plus und Minus, gedrückt halten zählt schnell weiter. Mehrere schnelle Klicks werden gesammelt und erst nach einer kurzen Pause gespeichert, damit die Markisensteuerung nicht bei jedem Klick neu übernimmt. Wind und Böen nutzen die Einheit des jeweiligen Sensors. Ist die Kachel breit genug, stehen die Gruppen in zwei Spalten; bei wenig Höhe lässt sie sich scrollen.

Die Werte gehören weiterhin der Markisensteuerung. Die Einstellungs-Instanz speichert davon nichts selbst, sondern ruft `MARKISE_SetParameter` auf. Änderungen im Formular der Markisensteuerung erscheinen sofort auch in der Kachel. Setzt man die Einfahrgrenze über die Ausfahrgrenze, zieht die Ausfahrgrenze mit (und umgekehrt), damit die Instanz gültig bleibt.

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Markisensteuerung | – | Die Instanz, deren Werte angezeigt werden |
| Werte in der Kachel änderbar | an | Aus = nur anzeigen. Dann lehnt auch die Instanz jede Änderung ab |
| Farbschema | Symcon-Design | wie bei der Steuer-Kachel |

## 7. Variablen und Darstellungen

| Ident | Name | Typ | Darstellung | Bedingung |
|---|---|---|---|---|
| Automatic | Automatik | Boolean | Schalter, bedienbar | immer |
| Hold | Halten (Abendmodus) | Boolean | Schalter, bedienbar | Halten angeboten |
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

**Status:** 0 Automatik aus, 1 Wartet auf Sonne, 2 Sonnenschutz aktiv, 3 Handbetrieb, 4 Abwesend – Karenz, 5 Abwesend, 6 Tag nicht freigegeben, 7 Nacht, 8 Außerhalb des Zeitfensters, 9 Windalarm, 10 Regen, 11 Frost, 12 Sensorfehler, 13 Halten (Abendmodus), 14 Unwetterwarnung, 15 Urlaub

Änderungen an den Einstellvariablen landen direkt in den Eigenschaften der Instanz. Es gibt also nur eine Stelle, an der ein Grenzwert steht.

## 8. PHP-Befehle

```php
MARKISE_Evaluate(int $InstanzID): bool      // alle Bedingungen sofort neu bewerten
MARKISE_Extend(int $InstanzID): bool        // ausfahren (zählt als Handbetrieb, bei Alarm abgelehnt)
MARKISE_Retract(int $InstanzID): bool       // einfahren (zählt als Handbetrieb)
MARKISE_Stop(int $InstanzID): bool          // anhalten, sofern eine Stopp-Variable eingestellt ist
MARKISE_SetAutomatic(int $InstanzID, bool $Aktiv): void
MARKISE_SetHold(int $InstanzID, bool $Aktiv): void   // Halten (Abendmodus) ein/aus
MARKISE_GetParameters(int $InstanzID): string         // Grundwerte als JSON (für die Einstellungs-Kachel)
MARKISE_SetParameter(int $InstanzID, string $Name, mixed $Wert): bool   // einen Grundwert ändern, z. B. ('LuxOn', 25000)
MARKISE_EndManualPause(int $InstanzID): void
MARKISE_RestartGrace(int $InstanzID): void  // Abwesenheits-Karenz neu starten
MARKISE_EndGrace(int $InstanzID): void      // Abwesenheits-Karenz sofort beenden
```

## 9. Simulation (Testbetrieb)

Zum Ausprobieren der Grenzwerte, ohne dass die Markise ständig fährt. Einschalten unter **Simulation (Testbetrieb)** in der Instanz.

**Was passiert:**

- Das Modul entscheidet genau wie im echten Betrieb, schickt aber keine Befehle an die Markise. „Zustand“, „Markise“ und die Kachel zeigen die gedachte Fahrt.
- Die Kachel trägt ein gelbes Band „Simulation – die Markise wird nicht bewegt“, die „Letzte Entscheidung“ beginnt mit „Simulation:“.
- Für jeden eingestellten Sensor gibt es eine Simulationsvariable, gestartet mit dem aktuellen echten Wert. Die Entscheidung nutzt dann nur noch diese Werte.
- Auch Tasten in Kachel und Visualisierung, `MARKISE_Extend` und Co. fahren nur gedacht. Die Handbetrieb-Pause läuft wie echt.
- Push-Nachrichten für gedachte Fahrten und gedachte Alarme gehen nicht raus.
- Die Erkennung „Bedienung von außen“ ruht, weil der Zustand nur gedacht ist.

**Simulationsvariablen:**

| Ident | Name | Darstellung |
|---|---|---|
| SimLux | Simulation – Helligkeit | Schieberegler 0–120.000 lx |
| SimTemp | Simulation – Temperatur | Schieberegler −10 bis 40 °C |
| SimWind | Simulation – Wind | Schieberegler in der Einheit des Windsensors |
| SimGust | Simulation – Böen | Schieberegler in der Einheit des Böensensors |
| SimRain | Simulation – Regen | Schalter |
| SimPresence | Simulation – jemand zu Hause | Schalter |
| SimDoor | Simulation – Terrassentür offen | Schalter (nur mit Terrassentür) |
| SimWarning | Simulation – Unwetter-Warnstufe | Schieberegler 0–4 (nur mit Unwetterwarnung) |
| SimVacation | Simulation – Urlaub | Schalter (nur mit Urlaubsschalter), an = Urlaub |
| SimTime | Simulation – Uhrzeit (HH:MM, leer = jetzt) | Werteingabe, z. B. `21:30` für den Abend oder `17:00` für die Sonnenrichtung |
| SimLog | Simulation – Protokoll | die letzten 15 Entscheidungen, neueste oben, z. B. „08:49:11 Würde senden: Ausfahren“ |

Die vorgegebene Uhrzeit gilt für Sonnenstand, Tag/Nacht, Zeitfenster und Wochentag. Verzögerungen, Sperren und Karenz laufen mit der echten Uhr.

**Einstellungen:**

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Simulation an | aus | |
| Verzögerungen und Sperren überspringen | aus | Ausfahr-/Einfahrverzögerung, Wind- und Regensperre sind dann 0, Lux-Mittelwert und Schaltlimit ruhen – das Modul reagiert sofort auf jede Änderung |
| Echter Wind- und Regenschutz bleibt aktiv | an | Die echten Wind-, Böen- und Regensensoren sowie die echte Unwetterwarnung werden weiter überwacht. Bei echtem Alarm fährt die echte Markise wirklich ein (ein Befehl pro Alarm, auf Wunsch wiederholt, mit Push-Nachricht) und das Protokoll vermerkt es |
| Echte Sensorwerte übernehmen | – | Setzt alle Simulationsvariablen auf die aktuellen echten Werte und die Uhrzeit auf „jetzt“ |
| Sperren, Pause und Verzögerungen zurücksetzen | – | Für den nächsten Versuch ohne Warten |

**Beim Ein- und Ausschalten** werden alle Laufzeitdaten zurückgesetzt (gedachter Zustand, Sperren, Pause, Verzögerungen, Karenz). So rutscht nichts Simuliertes in den echten Betrieb. Nach dem Ausschalten ist der Zustand unbekannt: Der erste Durchlauf schickt den Befehl, der zu den echten Werten passt. Die Simulationsvariablen werden entfernt.

## 10. Umstieg vom bisherigen Skript

### Per Knopfdruck übernehmen

Unten in der Instanz unter **Übernahme aus dem bisherigen Markisenskript** das alte Skript auswählen und **Einstellungen übernehmen** klicken. Das Modul liest daraus

- die Variablen-IDs für Helligkeit, Temperatur, Wind (Bft), Böen (km/h), Regen, Anwesenheit, Terrassentür sowie Aus- und Einfahren,
- die aktuellen Werte der Vorgabe-Variablen (Lux-Grenze, Mindesttemperatur, maximale Windstärke, Böenalarm, Tag/Nacht-Prüfung, Wochentage),
- Karenz, Sperrzeiten, Verzögerungen, Mittelwert, Schaltlimit, Böen-Trend, Sonnenrichtung, Standort und Unwetterwarnung.

Verstanden werden beide Fassungen: das ursprüngliche Skript (`$helligkeit = GetValueFloat(…)`, Ein- und Ausfahren werden an der Anzahl der `RequestAction`-Aufrufe erkannt) und die überarbeitete Fassung mit Konstanten (`const ID_HELLIGKEIT = …`). Auskommentierte Zeilen zählen nicht.

Die Werte landen nur im Formular. Erst **Änderungen übernehmen** speichert sie, bis dahin lässt sich alles prüfen und ändern. Danach die Ereignisse des alten Skripts deaktivieren.

### Zuordnung

So werden die alten Variablen zugeordnet:

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
| Halten-Schalter (Abendmodus), Terrassentür (59460, 0 = geschlossen) | Halten (Abendmodus) mit Terrassentür |
| DWD-Warnstufe, Einfahren ab Stufe 2 | Unwetterwarnung |
| Lux-Mittel 10 min, Schaltlimit 4/h, Böen-Trend 12 km/h in 15 min ab 70 % | gleichnamige Einstellungen, gleiche Standardwerte |
| Sonnenstand 135° bis 300°, ab 10°, Standort | Sonnenrichtung und Standort |
| Skript-Timer, ausgelöst durch Ereignisse | entfällt: Das Modul hört selbst auf die Sensoren |

Was sich gegenüber dem Skript verbessert:

- Befehle werden nur gesendet, wenn sich etwas ändert. Das Skript schickte bei jedem Lauf erneut „Einfahren“ an den Funkmotor.
- Ausfahrschwelle und Einfahrschwelle sind getrennt, dazu Verzögerungen. Durchziehende Wolken lassen die Markise nicht mehr pendeln.
- Nach einem Windalarm bleibt die Markise gesperrt, statt in der nächsten Windpause wieder auszufahren.
- Der eingefrorene Helligkeitssensor wird nur tagsüber geprüft. Nachts ist die Helligkeit zu Recht konstant.
- Fällt der Windsensor aus, wird eingefahren. Das Skript hätte mit dem letzten Wert weitergearbeitet.
- Frost, Zeitfenster, Sonnenrichtung, Handbetrieb-Erkennung und Push-Nachrichten sind dazugekommen.

Nach dem Umstieg das alte Skript und seine Ereignisse deaktivieren, damit nicht zwei Steuerungen gleichzeitig arbeiten.

## 11. Sicherheit und Geschwindigkeit

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
- Die Übernahme aus dem Skript liest den Skripttext nur und wertet ihn mit regulären Ausdrücken aus; er wird nie ausgeführt. Skripte über 256 KB werden abgelehnt, jede gefundene ID wird auf Existenz geprüft, und gespeichert wird erst nach Bestätigung im Formular.
- Die Einstellungs-Kachel kann nur die Grundwerte aus einer festen Liste ändern, nie Aktor- oder Sensor-IDs. Jeder Wert wird in der Markisensteuerung auf Typ und Bereich geprüft; die Einstellung „nur anzeigen“ sperrt Änderungen auch serverseitig, nicht nur in der Kachel.
- Fehlt die Warnstufe der Unwetterwarnung, blockiert das nichts. So kann ein ausgefallener Warndienst die Markise nicht dauerhaft einfahren lassen. Der Windschutz über die eigenen Sensoren bleibt davon unberührt.

**Geschwindigkeit**

- Ereignisgesteuert: Das Modul reagiert sofort auf Sensoränderungen (`VM_UPDATE`); der Minutentimer übernimmt nur zeitabhängige Dinge wie Verzögerungen, Sperren und Karenz.
- Die Entscheidung ist reine Rechnung ohne Netzwerk und dauert Bruchteile einer Millisekunde. Der Sonnenstand wird höchstens einmal pro Minute berechnet.
- Lux-Mittel, Böen-Trend und Schaltlimit speichern höchstens einen Eintrag pro Minute und höchstens 120 Einträge. Die Verläufe wachsen also im Dauerbetrieb nicht.
- Die Warnstufe wird nur beim Übernehmen der Einstellungen gesucht, nicht bei jedem Durchlauf.
- Variablen werden nur geschrieben, wenn sich ihr Wert ändert. Die Kachel bekommt nur dann Daten, wenn sich etwas Sichtbares geändert hat; die Helligkeit wird dafür auf 100 lx gerundet.
- Die Kachel lädt keine externen Dateien, zeichnet mit SVG und CSS und hält Animationen und Countdown an, solange sie nicht zu sehen ist.

## 12. Entwicklung und Tests

| Pfad | Inhalt |
|---|---|
| `Markisensteuerung/` | Modul: Entscheidung, Variablen, Formular und Kachel (`tile.html`) |
| `MarkisenEinstellungen/` | Zweites Modul: Einstellungs-Kachel |
| `libs/MarkiseParameterTrait.php` | Grundwerte für die Einstellungs-Kachel (Liste, Prüfung, Speichern) |
| `libs/MarkiseSunTrait.php` | Sonnenstand aus dem Symcon-Standort |
| `libs/MarkiseActuatorTrait.php` | Ansteuerung und Erkennung von Handbetrieb |
| `libs/MarkiseTileTrait.php` | Kacheldaten |
| `libs/MarkiseNotifyTrait.php` | Push-Nachrichten |
| `libs/MarkiseSimulationTrait.php` | Simulation (Testbetrieb) |
| `libs/MarkiseExtrasTrait.php` | Halten, Terrassentür, Unwetterwarnung, Böen-Trend, Lux-Mittel, Schaltlimit |
| `libs/MarkiseImportTrait.php` | Übernahme aus dem bisherigen Skript |
| `*/locale.json` | deutsche Übersetzung (Symcon-Format, Schlüssel `de`) |
| `tests/` | Testumgebung ohne Symcon und Testsuite; `tests/fixtures/` enthält eine Testkopie des überarbeiteten Skripts |

```
php tests/run.php
php tests/stubs.php <Pfad zu SymconStubs>
```

Die Testsuite bildet die Symcon-Basisklasse nach, simuliert Sensoren, Aktoren und eine Uhr und prüft unter anderem Ausfahren ohne Befehlsflut, Verzögerung und Hysterese, Wind-, Böen-, Regen- und Frostschutz mit Sperren, Sensorausfall, eingefrorene Helligkeit (nur tagsüber), Handbetrieb und Erkennung der Fernbedienung, Wochentage, Nacht, Zeitfenster über Mitternacht, Anwesenheit mit Karenz, Sonnenrichtung und Sonnenstand, alle drei Arten der Ansteuerung, Wiederholung fehlgeschlagener Befehle, Benachrichtigungen, Einstellungen aus der Visualisierung, abgelehnte Aktionen, die Simulation (keine echten Befehle, vorgegebene Werte und Uhrzeit, übersprungene Verzögerungen, echter Windschutz, sauberer Wechsel zurück in den echten Betrieb), die Kachel (inklusive Schutz vor eingeschleustem HTML und sparsamer Updates), das Formular, die Vollständigkeit der Übersetzung sowie Halten mit Terrassentür, Karenz und neuem Tag, Unwetterwarnung (Suche, Stufen, Hitze, fehlende Quelle), Böen-Trend, Lux-Mittel, Schaltlimit, eigenen Standort und die Übernahme aus beiden Skriptfassungen. Mit `DEBUG=1` werden die Debug-Ausgaben angezeigt.

`tests/stubs.php` lädt die Bibliothek zusätzlich mit den offiziellen [Symcon-Stubs](https://github.com/symcon/SymconStubs), legt eine Instanz an, verbindet Aktor- und Sensorvariablen und prüft, dass der Ausfahrbefehl über die Aktion ankommt.

GitHub Actions (`.github/workflows/tests.yml`) prüft bei jedem Push mit PHP 8.3 und 8.5 die Syntax, alle JSON-Dateien, die Testsuite und den Ladetest.

## 13. Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.4 | 19 | 05.10.2026 | Fehler behoben: Mit „Animationen reduzieren“ sprang das Markisentuch sofort auf ganz aus/ganz ein, während der Schatten in der Fahrzeit lief. Aus- und Einfahren laufen jetzt immer gemeinsam in der Fahrzeit; reduziert wird nur die Deko |
| 1.4 | 18 | 05.10.2026 | Markise fährt in der Kachel gleichmäßig genau in der eingestellten Fahrzeit; wird die Kachel während einer Fahrt geöffnet, geht die Animation an der richtigen Stelle weiter |
| 1.4 | 17 | 05.10.2026 | Kachel-Szene zeigt immer die volle Höhe (Sonne und Hausdach werden bei flachen Kacheln nicht mehr abgeschnitten), Himmel und Wiese reichen bis zum Rand; auf dem Handy passen alle vier Tasten (Stopp und Halten als Symbol); Einstellungs-Kachel mit kompakten Pillen und umbrechenden Beschriftungen |
| 1.4 | 16 | 05.10.2026 | Steuer-Kachel mit Garten-Panorama: Himmel nach Wetter und Tageszeit, Sonne mit Glühen, ziehende Wolken, Vögel, Mond und Sterne, Regen, Wind mit Blättern, Blitz, Schnee, Haus mit Fenster und Terrassentür (offen/zu), Gartenmöbel, Schatten der Markise; Größe passt sich der Kachel an |
| 1.4 | 15 | 05.10.2026 | Urlaubsschalter des Hauses: im Urlaub bleibt die Markise eingefahren (Sicherheit gilt weiter), Anzeige in beiden Kacheln, Simulation |
| 1.3 | 14 | 05.10.2026 | Einstellungs-Kachel im neuen Design: Karten mit Symbolen, Pillen-Stepper mit Gedrückthalten, runde Wochentags-Tasten, große Anwesenheitsanzeige, abgeblendetes Zeitfenster |
| 1.3 | 13 | 05.10.2026 | Anwesenheitsvariable, die unter die Instanz verschoben wurde, lässt sich wieder schalten (Kachel, Objektbaum, Visualisierung); Einstellungs-Kachel scrollt nur unterhalb des Titels |
| 1.3 | 12 | 05.10.2026 | Einstellungs-Kachel lädt beim Öffnen immer frische Werte (nach einem Update fehlten sonst Anwesenheit und Wochentage, bis sich ein Wert änderte) |
| 1.3 | 11 | 05.10.2026 | Einstellungs-Kachel: Gruppe „Anwesenheit“ mit Schalter für die Anwesenheitsvariable und Karenz, zweispaltiges Layout ohne Lücke |
| 1.3 | 10 | 05.10.2026 | Zweite Kachel „Markisen Einstellungen“ für Grundwerte, Wochentage und Zeiten; neue Befehle `MARKISE_GetParameters` und `MARKISE_SetParameter` |
| 1.2 | 9 | 05.10.2026 | Kachel: Ampel bei Helligkeit und Temperatur berücksichtigt die Hysterese (grau statt rot zwischen Ein- und Ausfahrgrenze) |
| 1.2 | 8 | 05.10.2026 | Terrassentür: aktueller Wert und Deutung im Formular und in der Kachel, Taste „Tür ist jetzt zu“ übernimmt den Wert für „geschlossen“ |
| 1.2 | 7 | 05.10.2026 | Halten (Abendmodus) mit Terrassentür, DWD-Unwetterwarnung, Böen-Trend, Lux-Mittelwert, Schaltlimit, eigener Standort mit Übernahme aus dem Location-Modul, Übernahme der Einstellungen aus dem bisherigen Skript, Simulation für Tür und Warnstufe |
| 1.1 | 6 | 05.10.2026 | Kachel lässt oben Platz für Titel und Symbole der Kachel-Visualisierung; bei inaktiver Instanz nur noch Hinweis ohne Sensorliste |
| 1.1 | 5 | 05.10.2026 | Simulation (Testbetrieb) mit Simulationsvariablen, vorgebbarer Uhrzeit, Protokoll und weiter aktivem echtem Wind- und Regenschutz |
| 1.0 | 4 | 04.10.2026 | Einheit (Bft, km/h, m/s) wird direkt an den Wind- und Böengrenzen angezeigt |
| 1.0 | 3 | 04.10.2026 | Nur noch ein Eintrag „Markisensteuerung“ beim Hinzufügen einer Instanz (keine Aliase mehr) |
| 1.0 | 2 | 04.10.2026 | Schalter „Instanz aktiv“, Hersteller eingetragen |
| 1.0 | 1 | 04.10.2026 | Erste Version als Modul, abgelöst vom bisherigen Markisen-Skript |

## 14. Lizenz

Dieses Modul steht unter der **MIT-Lizenz** (siehe Datei [`LICENSE`](LICENSE)).

Das Modul darf jeder kostenlos nutzen, verändern und weitergeben, auch kommerziell. Bedingung ist nur, dass der Copyright-Hinweis und der Lizenztext in Kopien erhalten bleiben. Eine Gewährleistung gibt es nicht.

Jede Code-Datei trägt einen Lizenzkopf mit `SPDX-License-Identifier: MIT`. Wer das Modul weitergibt oder Teile davon übernimmt, behält diesen Kopf und die Datei `LICENSE` bei.

**Hinweis:** Die Markisensteuerung ersetzt keinen vom Hersteller vorgesehenen Windwächter. Wer eine teure Markise schützen will, sollte zusätzlich einen direkt am Motor wirkenden Wind- oder Vibrationssensor verwenden. Somfy ist eine Marke der Somfy SAS; dieses Modul steht in keiner Verbindung zu Somfy.
