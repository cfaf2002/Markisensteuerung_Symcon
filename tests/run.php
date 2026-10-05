<?php

declare(strict_types=1);

/**
 * Testsuite für die Markisensteuerung.
 * Aufruf: php tests/run.php   (DEBUG=1 zeigt die Debug-Ausgaben des Moduls)
 *
 * SPDX-License-Identifier: MIT
 */

require __DIR__ . '/bootstrap.php';

$passed = 0;
$failed = [];
$current = '';

function test(string $name, callable $fn): void
{
    global $current, $failed;
    $current = $name;
    Sym::reset();
    echo '• ' . $name . PHP_EOL;
    try {
        $fn();
    } catch (Throwable $e) {
        $failed[] = $name . ': ' . get_class($e) . ' – ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo '    ✗ Ausnahme: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' . PHP_EOL;
    }
}

function check(bool $condition, string $message): void
{
    global $passed, $failed, $current;
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $current . ': ' . $message;
    echo '    ✗ ' . $message . PHP_EOL;
}

function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return true;
    }
    return false;
}

// IDs der simulierten Variablen
const V_EXTEND = 101;
const V_RETRACT = 102;
const V_STOP = 103;
const V_SWITCH = 104;
const V_POSITION = 105;
const V_LUX = 201;
const V_TEMP = 202;
const V_WIND = 203;
const V_GUST = 204;
const V_RAIN = 205;
const V_PRESENCE = 206;

/** Sommertag, Mittwoch 15.07.2026, 13:00 Uhr in Berlin */
function summer(string $time = '13:00'): int
{
    return (new DateTimeImmutable('2026-07-15 ' . $time, new DateTimeZone('Europe/Berlin')))->getTimestamp();
}

/**
 * Markise mit Somfy-artigen Befehlsvariablen und allen Sensoren; schönes Wetter, jemand zu Hause.
 */
function markise(array $props = [], string $time = '13:00', array $values = []): TestMarkise
{
    Sym::$now = summer($time);
    Sym::$actions = [];
    Sym::$notifications = [];
    Sym::variable(V_EXTEND, VARIABLETYPE_BOOLEAN, false, true);
    Sym::variable(V_RETRACT, VARIABLETYPE_BOOLEAN, false, true);
    Sym::variable(V_STOP, VARIABLETYPE_BOOLEAN, false, true);
    Sym::variable(V_SWITCH, VARIABLETYPE_BOOLEAN, false, true);
    Sym::variable(V_POSITION, VARIABLETYPE_INTEGER, 0, true);
    Sym::variable(V_LUX, VARIABLETYPE_FLOAT, $values['lux'] ?? 50000.0);
    Sym::variable(V_TEMP, VARIABLETYPE_FLOAT, $values['temp'] ?? 25.0);
    Sym::variable(V_WIND, VARIABLETYPE_INTEGER, $values['wind'] ?? 2);
    Sym::variable(V_GUST, VARIABLETYPE_FLOAT, $values['gust'] ?? 10.0);
    Sym::variable(V_RAIN, VARIABLETYPE_BOOLEAN, $values['rain'] ?? false);
    Sym::variable(V_PRESENCE, VARIABLETYPE_BOOLEAN, $values['presence'] ?? true);

    Sym::$jitter = [V_LUX];
    $m = new TestMarkise(1000);
    $m->Create();
    $defaults = [
        'ExtendVariableID'      => V_EXTEND,
        'RetractVariableID'     => V_RETRACT,
        'StopVariableID'        => V_STOP,
        'BrightnessVariableID'  => V_LUX,
        'TemperatureVariableID' => V_TEMP,
        'WindVariableID'        => V_WIND,
        'GustVariableID'        => V_GUST,
        'RainVariableID'        => V_RAIN,
        'PresenceVariableID'    => V_PRESENCE,
        'DelayOn'               => 0,
        'DelayOff'              => 0,
        // eigene Tests für Mittelwert, Schaltlimit und Böen-Trend
        'LuxAverageMinutes'     => 0,
        'MaxMovesPerHour'       => 0,
        'GustTrend'             => false,
    ];
    foreach (array_merge($defaults, $props) as $k => $v) {
        $m->prop($k, $v);
    }
    $m->ApplyChanges();
    return $m;
}

/** Ausgelöste Aktionen als Liste von "ID=Wert" */
function actions(): array
{
    return array_map(static fn (array $a): string => $a[0] . '=' . json_encode($a[1]), Sym::$actions);
}

function lastAction(): string
{
    $a = actions();
    return end($a) ?: '';
}

// =====================================================================

test('Ohne Aktor: Status 200, keine Aktion', function (): void {
    $m = markise(['ExtendVariableID' => 0]);
    check($m->status === 200, 'Status 200 (ist ' . $m->status . ')');
    check(Sym::$actions === [], 'keine Aktion');
    check($m->timers['Tick']['ms'] === 0, 'Timer aus');
    $tile = json_decode($m->attr('TileData'), true);
    check(($tile['error'] ?? '') !== '', 'Kachel zeigt Hinweis');
});

test('Instanz nicht aktiv: Status 104, nichts passiert', function (): void {
    $m = markise(['Active' => false]);
    check($m->status === 104, 'Status 104 (ist ' . $m->status . ')');
    check(Sym::$actions === [], 'kein Befehl trotz Sonne');
    check($m->timers['Tick']['ms'] === 0, 'Timer aus');
    $m->sensor(V_WIND, 9);
    check(Sym::$actions === [], 'auch Sensoränderungen lösen nichts aus');
    check($m->Evaluate() === false, 'MARKISE_Evaluate meldet false');
    ob_start();
    $ok = $m->Extend();
    ob_end_clean();
    check($ok === false && Sym::$actions === [], 'auch von Hand kein Befehl');
    $tile = json_decode($m->attr('TileData'), true);
    check(($tile['errorTitle'] ?? '') === 'Inaktiv', 'Kachel zeigt „Inaktiv“');
    $m->prop('Active', true);
    $m->sensor(V_WIND, 2);
    $m->ApplyChanges();
    check($m->status === 102, 'wieder aktiv: Status 102');
    check(actions() === [V_EXTEND . '=true'], 'wieder aktiv: Automatik arbeitet');
});

test('Aktorvariable ohne Aktion: Status 201', function (): void {
    Sym::$now = summer();
    $m = markise();
    Sym::$vars[V_RETRACT]['action'] = false;
    $m->ApplyChanges();
    check($m->status === 201, 'Status 201 (ist ' . $m->status . ')');
});

test('Sensorvariable gelöscht: Status 202', function (): void {
    $m = markise();
    unset(Sym::$vars[V_TEMP]);
    $m->ApplyChanges();
    check($m->status === 202, 'Status 202');
});

test('Einfahr-Helligkeit über Ausfahr-Helligkeit: Status 203', function (): void {
    $m = markise(['LuxOn' => 20000, 'LuxOff' => 30000]);
    check($m->status === 203, 'Status 203');
});

test('Sonne: fährt aus, nur einmal (kein Dauerfeuer)', function (): void {
    $m = markise();
    check($m->status === 102, 'Status 102');
    check(actions() === [V_EXTEND . '=true'], 'genau ein Ausfahrbefehl (' . implode(', ', actions()) . ')');
    check($m->attr('LastCommand') === 'extend', 'letzter Befehl extend');
    check($m->value('State') === Markisensteuerung::STATE_EXTENDING, 'Zustand fährt aus');
    check($m->value('Status') === Markisensteuerung::ST_SUN, 'Status Sonnenschutz');
    $m->advance(10);
    check(count(Sym::$actions) === 1, 'keine weiteren Befehle nach 10 Minuten');
    $m->RequestAction('TravelDone', 0);
    check($m->value('State') === Markisensteuerung::STATE_EXTENDED, 'nach Fahrzeit ausgefahren');
    check($m->value('Automatic') === true, 'Automatik beim Anlegen an');
});

test('Ausfahrverzögerung', function (): void {
    $m = markise(['DelayOn' => 5], '13:00', ['lux' => 10000.0]);
    check(Sym::$actions === [], 'zu dunkel: nichts');
    check(str_contains((string) $m->value('Reason'), 'zu dunkel'), 'Begründung nennt „zu dunkel“: ' . $m->value('Reason'));
    $m->sensor(V_LUX, 45000.0);
    check(Sym::$actions === [], 'Sonne erkannt, wartet noch');
    check(str_contains((string) $m->value('Reason'), '5 Min'), 'Begründung nennt Wartezeit: ' . $m->value('Reason'));
    $m->advance(4);
    check(Sym::$actions === [], 'nach 4 Minuten noch nicht');
    $m->advance(1);
    check(actions() === [V_EXTEND . '=true'], 'nach 5 Minuten ausgefahren');
});

test('Wolke unterbricht die Verzögerung', function (): void {
    $m = markise(['DelayOn' => 5], '13:00', ['lux' => 10000.0]);
    $m->sensor(V_LUX, 45000.0);
    $m->advance(3);
    $m->sensor(V_LUX, 9000.0);
    $m->advance(1);
    $m->sensor(V_LUX, 45000.0);
    $m->advance(3);
    check(Sym::$actions === [], 'Verzögerung beginnt nach der Wolke neu');
    $m->advance(2);
    check(count(Sym::$actions) === 1, 'dann ausgefahren');
});

test('Hysterese und Einfahrverzögerung', function (): void {
    $m = markise(['DelayOff' => 15]);
    $m->sensor(V_LUX, 25000.0);
    $m->advance(30);
    check(count(Sym::$actions) === 1, 'zwischen Ein- und Ausschwelle bleibt ausgefahren');
    $m->sensor(V_LUX, 15000.0);
    $m->advance(14);
    check(count(Sym::$actions) === 1, 'nach 14 Minuten noch ausgefahren');
    $m->advance(1);
    check(lastAction() === V_RETRACT . '=true', 'nach 15 Minuten eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_WAITING, 'Status wartet auf Sonne');
});

test('Kälte mit Hysterese', function (): void {
    $m = markise();
    $m->sensor(V_TEMP, 17.5);
    check(count(Sym::$actions) === 1, '0,5 K unter Minimum: bleibt (Hysterese 1 K)');
    $m->sensor(V_TEMP, 16.9);
    check(lastAction() === V_RETRACT . '=true', 'unter Minimum minus Hysterese: eingefahren');
});

test('Wind über Normalgrenze: sofort einfahren, ohne Alarm', function (): void {
    $m = markise(['DelayOff' => 15]);
    $m->sensor(V_WIND, 5);
    check(lastAction() === V_RETRACT . '=true', 'sofort eingefahren');
    check($m->value('Safety') === false, 'kein Sicherheitsalarm');
    check(str_contains((string) $m->value('Reason'), 'Wind über Grenze'), 'Begründung: ' . $m->value('Reason'));
});

test('Windalarm: einfahren, Sperre, Wiederholung', function (): void {
    $m = markise();
    $m->sensor(V_WIND, 6);
    check(lastAction() === V_RETRACT . '=true', 'eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_WIND, 'Status Windalarm');
    check($m->value('Safety') === true, 'Sicherheit aktiv');
    check($m->timers['Repeat']['ms'] === 60000, 'Wiederholung nach Fahrzeit geplant');
    $m->RequestAction('RepeatRetract', 0);
    check(count(array_filter(actions(), static fn ($a) => $a === V_RETRACT . '=true')) === 2, 'Einfahrbefehl wiederholt');

    $count = count(Sym::$actions);
    $m->sensor(V_WIND, 7);
    check(count(Sym::$actions) === $count, 'weiterer Alarm: kein neuer Befehl');

    $m->sensor(V_WIND, 2);
    check(count(Sym::$actions) === $count, 'Wind weg: bleibt eingefahren (Sperre)');
    check(str_contains((string) $m->value('Reason'), 'Windsperre'), 'Begründung Windsperre: ' . $m->value('Reason'));
    $m->advance(14);
    check(count(Sym::$actions) === $count, 'nach 14 Minuten noch gesperrt');
    $m->advance(2);
    check(lastAction() === V_EXTEND . '=true', 'nach der Sperre wieder ausgefahren');
    check($m->value('Safety') === false, 'Sicherheit wieder aus');
});

test('Böenalarm', function (): void {
    $m = markise();
    $m->sensor(V_GUST, 30.0);
    check(lastAction() === V_RETRACT . '=true', 'eingefahren');
    check(str_contains((string) $m->value('Reason'), 'Böenalarm (30 km/h)'), 'Begründung: ' . $m->value('Reason'));
});

test('Regen mit Sperre', function (): void {
    $m = markise();
    $m->sensor(V_RAIN, true);
    check(lastAction() === V_RETRACT . '=true', 'eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_RAIN, 'Status Regen');
    $m->sensor(V_RAIN, false);
    $m->advance(9);
    check(lastAction() === V_RETRACT . '=true', 'Regensperre hält');
    $m->advance(2);
    check(lastAction() === V_EXTEND . '=true', 'danach wieder aus');
});

test('Frost blockiert auch das Ausfahren von Hand', function (): void {
    $m = markise([], '13:00', ['temp' => 2.0]);
    check(Sym::$actions === [V_RETRACT, true] || actions() === [V_RETRACT . '=true'], 'beim Start eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_FROST, 'Status Frost');
    ob_start();
    $ok = $m->Extend();
    $msg = (string) ob_get_clean();
    check($ok === false, 'Extend() abgelehnt');
    check(str_contains($msg, 'Frost'), 'Meldung nennt den Grund: ' . $msg);
    check(!in_array(V_EXTEND . '=true', actions(), true), 'kein Ausfahrbefehl');
});

test('Windsensor liefert keine Werte mehr', function (): void {
    $m = markise();
    Sym::$vars[V_WIND]['keepalive'] = false;
    Sym::$vars[V_GUST]['keepalive'] = false;
    $m->advance(119);
    check(count(Sym::$actions) === 1, 'nach 119 Minuten alles normal');
    $m->advance(3);
    check(lastAction() === V_RETRACT . '=true', 'nach über 120 Minuten eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_SENSOR, 'Status Sensorfehler');
});

test('Ein frischer Böensensor genügt', function (): void {
    $m = markise();
    Sym::$vars[V_WIND]['keepalive'] = false;
    $m->advance(130);
    check(count(Sym::$actions) === 1, 'Böen kommen noch: kein Sensorfehler');
});

test('Helligkeitssensor eingefroren (nur tagsüber)', function (): void {
    $m = markise();
    Sym::$jitter = [];
    $m->advance(30);
    check(count(Sym::$actions) === 1, '30 Minuten gleicher Wert: noch ok');
    $m->advance(2);
    check(lastAction() === V_RETRACT . '=true', 'danach eingefroren → eingefahren');
    check(str_contains((string) $m->value('Reason'), 'eingefroren'), 'Begründung: ' . $m->value('Reason'));
    $m->sensor(V_LUX, 48000.0);
    check(lastAction() === V_EXTEND . '=true', 'neuer Wert: Sensor wieder ok');

    $n = markise([], '23:30', ['lux' => 0.0]);
    Sym::$jitter = [];
    $n->advance(90);
    check($n->value('Status') === Markisensteuerung::ST_NIGHT, 'nachts kein Sensorfehler');
});

test('Automatik aus: keine Aktion, Sicherheit trotzdem', function (): void {
    $m = markise();
    $m->RequestAction('Automatic', false);
    check($m->value('Status') === Markisensteuerung::ST_OFF, 'Status Automatik aus');
    $m->sensor(V_LUX, 5000.0);
    $m->advance(30);
    check(count(Sym::$actions) === 1, 'kein Einfahren bei Dunkelheit');
    $m->sensor(V_WIND, 8);
    check(lastAction() === V_RETRACT . '=true', 'Windalarm fährt trotzdem ein');

    $n = markise(['SafetyAlways' => false]);
    $n->RequestAction('Automatic', false);
    $before = count(Sym::$actions);
    $n->sensor(V_WIND, 8);
    check(count(Sym::$actions) === $before, 'mit SafetyAlways = aus: keine Aktion');
});

test('Handbetrieb über das Modul pausiert die Automatik', function (): void {
    $m = markise();
    $m->Retract();
    check(lastAction() === V_RETRACT . '=true', 'eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_MANUAL, 'Status Handbetrieb');
    check($m->value('ManualUntil') === Sym::$now + 3600, 'Pause bis +60 Minuten');
    $m->advance(59);
    check(lastAction() === V_RETRACT . '=true', 'während der Pause kein Ausfahren');
    $m->advance(2);
    check(lastAction() === V_EXTEND . '=true', 'nach der Pause wieder Automatik');
});

test('Handbetrieb beenden', function (): void {
    $m = markise();
    $m->Retract();
    $m->RequestAction('EndManual', 0);
    check(lastAction() === V_EXTEND . '=true', 'Automatik sofort wieder aktiv');
});

test('Fernbedienung wird erkannt, eigene Befehle nicht', function (): void {
    $m = markise();
    // Rückmeldung auf unseren eigenen Ausfahrbefehl
    $m->sensor(V_EXTEND, true);
    check($m->attr('ManualUntil') === 0, 'eigener Befehl zählt nicht als Handbetrieb');
    Sym::$now += 10;
    $m->sensor(V_RETRACT, true);
    check($m->attr('ManualUntil') > Sym::$now, 'Einfahren von außen erkannt');
    check($m->attr('LastCommand') === 'retract', 'Zustand übernommen');
    check($m->value('State') === Markisensteuerung::STATE_RETRACTING, 'Zustand fährt ein');
    $count = count(Sym::$actions);
    $m->sensor(V_RETRACT, false);
    check(count(Sym::$actions) === $count, 'Zurücksetzen des Tasters wird ignoriert');
});

test('Von außen ausgefahren bei Wind: sofort wieder ein', function (): void {
    $m = markise([], '13:00', ['wind' => 7]);
    Sym::$now += 10;
    $m->sensor(V_EXTEND, true);
    check(lastAction() === V_RETRACT . '=true', 'Sicherheit fährt wieder ein');
});

test('Erkennung von Hand abschaltbar', function (): void {
    $m = markise(['ManualDetect' => false]);
    Sym::$now += 10;
    $m->sensor(V_RETRACT, true);
    check($m->attr('ManualUntil') === 0, 'keine Pause');
});

test('Wochentag nicht freigegeben', function (): void {
    $m = markise(['Weekday3' => false]);
    check(actions() === [V_RETRACT . '=true'], 'Zustand unbekannt: beim Start einmal eingefahren');
    $m->advance(5);
    check(count(Sym::$actions) === 1, 'danach kein Dauerfeuer');
    check($m->value('Status') === Markisensteuerung::ST_WEEKDAY, 'Status Tag nicht freigegeben');
});

test('Nacht und Zeitfenster', function (): void {
    $m = markise([], '23:00');
    check($m->value('Status') === Markisensteuerung::ST_NIGHT, 'Status Nacht');
    $m = markise(['DayCheck' => false], '23:00', ['lux' => 50000.0]);
    check($m->value('Status') !== Markisensteuerung::ST_NIGHT, 'ohne Tag/Nacht-Prüfung keine Nacht');
    $w = markise(['UseTimeWindow' => true, 'TimeFrom' => '{"hour":14,"minute":0,"second":0}', 'TimeTo' => '{"hour":18,"minute":0,"second":0}']);
    check($w->value('Status') === Markisensteuerung::ST_TIME, 'vor 14 Uhr außerhalb des Zeitfensters');
    $w->advance(61);
    check(lastAction() === V_EXTEND . '=true', 'ab 14 Uhr ausgefahren');
    $w->advance(240);
    check(lastAction() === V_RETRACT . '=true', 'ab 18 Uhr eingefahren');
});

test('Zeitfenster über Mitternacht', function (): void {
    $m = markise(['DayCheck' => false, 'UseTimeWindow' => true, 'TimeFrom' => '{"hour":22,"minute":0,"second":0}', 'TimeTo' => '{"hour":2,"minute":0,"second":0}'], '23:00');
    check($m->value('Status') !== Markisensteuerung::ST_TIME, '23 Uhr liegt im Fenster 22–2 Uhr');
});

test('Abwesenheit mit Karenz', function (): void {
    // ausgefahren, dann geht jemand
    $m = markise();
    $m->sensor(V_PRESENCE, false);
    check($m->value('Status') === Markisensteuerung::ST_GRACE, 'Status Karenz');
    check(count(Sym::$actions) === 1, 'bleibt ausgefahren');
    $m->advance(29);
    check(count(Sym::$actions) === 1, 'nach 29 Minuten noch ausgefahren');
    $m->advance(2);
    check(lastAction() === V_RETRACT . '=true', 'nach Ablauf eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_ABSENT, 'Status abwesend');
    $m->advance(30);
    check(count(Sym::$actions) === 2, 'kein Dauerfeuer');

    // eingefahren und abwesend: während der Karenz wird nicht ausgefahren
    $n = markise([], '13:00', ['presence' => false, 'lux' => 5000.0]);
    $n->sensor(V_LUX, 50000.0);
    check(Sym::$actions === [], 'kein Ausfahren während der Karenz');

    // Karenz, Bedingungen fallen weg → einfahren
    $o = markise();
    $o->sensor(V_PRESENCE, false);
    $o->sensor(V_LUX, 5000.0);
    check(lastAction() === V_RETRACT . '=true', 'Karenz, keine Sonne mehr: eingefahren');
});

test('Karenz beenden und neu starten', function (): void {
    $m = markise();
    $m->sensor(V_PRESENCE, false);
    $m->advance(20);
    $m->RequestAction('RestartGrace', 0);
    $m->advance(25);
    check(count(Sym::$actions) === 1, 'neu gestartet: noch ausgefahren');
    $m->RequestAction('EndGrace', 0);
    check(lastAction() === V_RETRACT . '=true', 'beendet: sofort eingefahren');
    $m->sensor(V_PRESENCE, true);
    check(lastAction() === V_EXTEND . '=true', 'wieder da: ausgefahren');
});

test('Sonnenrichtung', function (): void {
    $m = markise(['UseSunPosition' => true, 'AzimuthFrom' => 90.0, 'AzimuthTo' => 180.0], '18:00');
    check(Sym::$actions === [], 'Sonne im Westen: kein Ausfahren');
    check(str_contains((string) $m->value('Reason'), 'Sonne nicht auf der Markise'), 'Begründung: ' . $m->value('Reason'));
    $n = markise(['UseSunPosition' => true, 'AzimuthFrom' => 90.0, 'AzimuthTo' => 200.0], '12:00');
    check(count(Sym::$actions) === 1, 'Sonne im Süden: ausgefahren');
});

test('Sonnenstand', function (): void {
    // Berlin, Sommeranfang, 13:15 MESZ ≈ wahrer Mittag
    $ts = (new DateTimeImmutable('2026-06-21 13:15', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $s = Markisensteuerung::SunPosition($ts, 52.52, 13.40);
    check(abs($s['elevation'] - 61.0) < 1.0, 'Höhe ≈ 61° (ist ' . $s['elevation'] . ')');
    check(abs($s['azimuth'] - 180.0) < 5.0, 'Azimut ≈ 180° (ist ' . $s['azimuth'] . ')');
    $night = Markisensteuerung::SunPosition($ts + 12 * 3600, 52.52, 13.40);
    check($night['elevation'] < -10, 'nachts unter dem Horizont');
});

test('Schaltvariable, auch invertiert', function (): void {
    $m = markise(['ActuatorMode' => 1, 'SwitchVariableID' => V_SWITCH]);
    check(actions() === [V_SWITCH . '=true'], 'Ausfahren = an');
    check(!$m->has('Position'), 'keine Positionsvariable');
    $m->sensor(V_WIND, 9);
    check(lastAction() === V_SWITCH . '=false', 'Einfahren = aus');

    $n = markise(['ActuatorMode' => 1, 'SwitchVariableID' => V_SWITCH, 'SwitchInvert' => true]);
    check(lastAction() === V_SWITCH . '=false', 'invertiert: Ausfahren = aus');
});

test('Positionsvariable 0–100 und 0–1', function (): void {
    $m = markise(['ActuatorMode' => 2, 'PositionVariableID' => V_POSITION, 'PositionExtended' => 80]);
    check(lastAction() === V_POSITION . '=80', 'auf 80 % gefahren: ' . lastAction());
    check($m->value('Position') === 80, 'Positionsvariable 80');
    $m->RequestAction('Position', 40);
    check(lastAction() === V_POSITION . '=40', 'Position von Hand');
    check($m->attr('ManualUntil') > Sym::$now, 'zählt als Handbetrieb');

    Sym::reset();
    $n = markise(['ActuatorMode' => 2, 'PositionVariableID' => V_POSITION, 'PositionScale' => 1]);
    check(lastAction() === V_POSITION . '=1', 'Skala 0–1: 1 (' . lastAction() . ')');
});

test('Positionsrückmeldung: nur echte Änderungen zählen', function (): void {
    $m = markise(['ActuatorMode' => 2, 'PositionVariableID' => V_POSITION]);
    Sym::$now += 200; // Fahrzeit vorbei
    $m->sensor(V_POSITION, 100); // gleicher Wert, zyklische Meldung
    check($m->attr('ManualUntil') === 0, 'gleicher Wert: kein Handbetrieb');
    $m->sensor(V_POSITION, 10);
    check($m->attr('ManualUntil') > Sym::$now, 'Änderung von außen erkannt');
    check($m->attr('LastCommand') === 'retract', 'als eingefahren erkannt');
});

test('Stopp nur mit Stopp-Variable', function (): void {
    $m = markise(['StopVariableID' => 0]);
    ob_start();
    $ok = $m->Stop();
    ob_end_clean();
    check($ok === false, 'ohne Stopp-Variable abgelehnt');
    $n = markise();
    check($n->Stop() === true, 'mit Stopp-Variable');
    check(lastAction() === V_STOP . '=true', 'Stopp gesendet');
    check($n->value('State') === Markisensteuerung::STATE_STOPPED, 'Zustand gestoppt');
});

test('Befehl schlägt fehl: wird beim nächsten Durchlauf wiederholt', function (): void {
    Sym::$now = summer();
    Sym::$actionFails = true;
    $m = markise();
    Sym::$actionFails = true;
    $m->advance(1);
    check($m->attr('LastCommand') === '', 'nicht als gesendet gemerkt');
    Sym::$actionFails = false;
    $m->advance(1);
    check(actions() === [V_EXTEND . '=true'], 'beim nächsten Mal gesendet');
});

test('Benachrichtigung bei Sicherheit nur einmal', function (): void {
    $m = markise(['NotifySafety' => true]);
    $m->sensor(V_WIND, 7);
    $m->sensor(V_WIND, 8);
    $m->advance(5);
    check(count(Sym::$notifications) === 1, 'genau eine Nachricht (' . count(Sym::$notifications) . ')');
    check(str_contains(Sym::$notifications[0]['text'] ?? '', 'Windalarm'), 'Text nennt Windalarm');
});

test('Einstellungen in der Visualisierung', function (): void {
    $m = markise(['ShowSettings' => true]);
    check($m->has('SetLuxOn') && $m->has('SetWeekday7'), 'Variablen angelegt');
    check(isset($m->actionsEnabled['SetLuxOn']), 'bedienbar');
    check($m->value('SetLuxOn') === 30000, 'Startwert aus der Eigenschaft');
    $m->RequestAction('SetLuxOn', 40000);
    check($m->properties['LuxOn'] === 40000, 'Eigenschaft geändert');
    check($m->value('SetLuxOn') === 40000, 'Variable nachgezogen');
    $m->RequestAction('SetTempMin', 'abc');
    check($m->properties['TempMin'] === 0.0, 'Text wird zu Zahl');
    $m->RequestAction('SetWeekday3', false);
    check($m->value('Status') === Markisensteuerung::ST_WEEKDAY, 'Wochentag sofort wirksam');

    $n = markise();
    check(!$n->has('SetLuxOn'), 'ohne Schalter keine Einstellvariablen');
    check(throws(fn () => $n->RequestAction('SetLuxOn', 1)), 'ohne Schalter abgelehnt');
});

test('Ungültige Aktionen werden abgelehnt', function (): void {
    $m = markise();
    check(throws(fn () => $m->RequestAction('Control', 7)), 'Control 7');
    check(throws(fn () => $m->RequestAction('Unbekannt', 1)), 'unbekannter Ident');
    check(throws(fn () => $m->RequestAction('Position', 50)), 'Position ohne Positionsvariable');
});

test('Variablen, Referenzen und Nachrichten', function (): void {
    $m = markise();
    foreach (['Automatic', 'Control', 'State', 'Status', 'Reason', 'Safety', 'ManualUntil'] as $ident) {
        check($m->has($ident), 'Variable ' . $ident);
    }
    check(isset($m->actionsEnabled['Automatic'], $m->actionsEnabled['Control']), 'Automatik und Markise bedienbar');
    foreach ([V_LUX, V_TEMP, V_WIND, V_GUST, V_RAIN, V_PRESENCE, V_EXTEND, V_RETRACT, V_STOP] as $id) {
        check(isset($m->messages[$id][VM_UPDATE]), 'überwacht ' . $id);
        check(isset($m->references[$id]), 'Referenz ' . $id);
    }
    $m->prop('GustVariableID', 0);
    $m->ApplyChanges();
    check(!isset($m->messages[V_GUST][VM_UPDATE]), 'abgewählter Sensor wird nicht mehr überwacht');
    check(!isset($m->references[V_GUST]), 'Referenz entfernt');
    $p = json_decode(json_encode($m->variables['Control']['presentation']), true);
    check($p['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION, 'Darstellung Aufzählung');
    check(count(json_decode($p['OPTIONS'], true)) === 3, 'mit Stopp drei Tasten');
    $m->prop('ManualPauseMinutes', 0);
    $m->ApplyChanges();
    check(!$m->has('ManualUntil'), 'ohne Pause keine Variable „Handbetrieb bis“');
});

test('Kachel: Daten, Sicherheit, sparsame Updates', function (): void {
    $m = markise();
    $tile = $m->GetVisualizationTile();
    check(str_contains($tile, 'window.handleMessage'), 'Kachel-HTML');
    check(!str_contains($tile, '/*INITIAL_DATA*/'), 'Startdaten eingesetzt');
    $data = json_decode($m->attr('TileData'), true);
    check($data['status'] === Markisensteuerung::ST_SUN, 'Status in den Daten');
    check(count($data['sensors']) === 5, 'fünf Sensoren');
    check($data['canStop'] === true, 'Stopp verfügbar');

    $m->advance(1); // „ausgefahren“ → „Sonnenschutz aktiv“
    $updates = count($m->visualizationUpdates);
    $m->advance(1);
    $m->advance(1);
    check(count($m->visualizationUpdates) === $updates, 'ohne Änderung keine Updates an die Visualisierung');
    $m->sensor(V_LUX, 50020.0);
    check(count($m->visualizationUpdates) === $updates, 'kleine Helligkeitsänderung (< 100 lx) ohne Update');

    $m->setAttr('TileData', json_encode(['reason' => '</script><img src=x onerror=alert(1)>']));
    $html = $m->GetVisualizationTile();
    check(substr_count($html, '</script>') === 1, 'Werte können das Skript nicht beenden');
    check(!str_contains($html, '<img src=x'), 'kein HTML aus Daten');
    check(!str_contains((string) file_get_contents(__DIR__ . '/../Markisensteuerung/tile.html'), 'innerHTML'), 'Kachel nutzt kein innerHTML');
});

test('Formular: jedes Feld hat eine Eigenschaft, Sichtbarkeit je Ansteuerung', function (): void {
    $m = markise(['ActuatorMode' => 1, 'SwitchVariableID' => V_SWITCH]);
    $form = json_decode($m->GetConfigurationForm(), true);
    check(is_array($form), 'gültiges JSON');
    $names = [];
    $walk = function (array $els) use (&$walk, &$names): void {
        foreach ($els as $el) {
            if (isset($el['name']) && !in_array($el['type'], ['Label', 'Button'], true)) {
                $names[$el['name']] = $el;
            }
            if (isset($el['items'])) {
                $walk($el['items']);
            }
        }
    };
    $walk($form['elements']);
    foreach ($names as $name => $el) {
        check(array_key_exists($name, $m->properties), 'Eigenschaft zu Feld ' . $name);
    }
    check(($names['SwitchVariableID']['visible'] ?? null) === true, 'Schaltvariable sichtbar');
    check(($names['ExtendVariableID']['visible'] ?? null) === false, 'Befehlsvariable versteckt');
    check(count($names['WindUnit']['options']) === 3, 'Einheiten gefüllt');
    check(($names['WindAlarm']['suffix'] ?? '') === 'Bft', 'Windalarm mit Einheit Bft');
    check(($names['WindMax']['suffix'] ?? '') === 'Bft', 'Windgrenze mit Einheit Bft');
    check(($names['GustAlarm']['suffix'] ?? '') === 'km/h', 'Böenalarm mit Einheit km/h');
    $m->RequestAction('FormWindUnit', 2);
    check(in_array(['WindAlarm', 'suffix', 'm/s'], $m->formUpdates, true), 'Einheit wechselt sofort im Formular');
    $m->RequestAction('FormMode', 2);
    check(in_array(['PositionVariableID', 'visible', true], $m->formUpdates, true), 'Umschalten im Formular');
});

test('Übersetzung: alle Texte haben eine deutsche Übersetzung', function (): void {
    $de = Sym::$translations;
    $missing = [];
    $sources = array_merge(glob(__DIR__ . '/../Markisensteuerung/*.php'), glob(__DIR__ . '/../libs/*.php'));
    foreach ($sources as $file) {
        preg_match_all("/Translate\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string) file_get_contents($file), $m);
        foreach ($m[1] as $s) {
            $s = stripcslashes($s);
            if (!isset($de[$s])) {
                $missing[] = $s;
            }
        }
    }
    // Wochentage werden über eine Konstante übersetzt
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $s) {
        if (!isset($de[$s])) {
            $missing[] = $s;
        }
    }
    $form = json_decode((string) file_get_contents(__DIR__ . '/../Markisensteuerung/form.json'), true);
    array_walk_recursive($form, function ($v, $k) use ($de, &$missing): void {
        if ($k === 'caption' && is_string($v) && $v !== '' && !isset($de[$v]) && !preg_match('/^[0-9 –.]+$/', $v)) {
            $missing[] = $v;
        }
    });
    preg_match_all('/(?:data-t="|\bt\(\')([^"\']+)/', (string) file_get_contents(__DIR__ . '/../Markisensteuerung/tile.html'), $tm);
    foreach ($tm[1] as $s) {
        if (!isset($de[$s])) {
            $missing[] = $s;
        }
    }
    $missing = array_values(array_unique($missing));
    check($missing === [], 'fehlende Übersetzungen: ' . implode(' | ', $missing));
});


test('Simulation: entscheidet, bewegt aber nichts', function (): void {
    $m = markise(['SimulationMode' => true]);
    check(Sym::$actions === [], 'kein echter Befehl');
    check($m->attr('LastCommand') === 'extend', 'gedacht ausgefahren');
    check($m->value('State') === Markisensteuerung::STATE_EXTENDING, 'Zustand zeigt die gedachte Fahrt');
    check(str_starts_with((string) $m->value('Reason'), 'Simulation: '), 'Begründung mit „Simulation:“');
    check(str_contains((string) $m->value('SimLog'), 'Würde senden: Ausfahren'), 'Protokoll: ' . $m->value('SimLog'));
    check(str_contains((string) $m->value('SimLog'), 'Simulation gestartet'), 'Protokoll beginnt mit Start');
    $tile = json_decode($m->attr('TileData'), true);
    check($tile['simulation'] === true, 'Kachel zeigt Simulation');
    foreach (['SimLux', 'SimTemp', 'SimWind', 'SimGust', 'SimRain', 'SimPresence', 'SimTime', 'SimLog'] as $ident) {
        check($m->has($ident), 'Variable ' . $ident);
    }
    check($m->value('SimLux') === 50000.0, 'Startwert aus dem echten Sensor');
});

test('Simulation: Werte vorgeben', function (): void {
    $m = markise(['SimulationMode' => true, 'DelayOff' => 15]);
    $m->RequestAction('SimLux', 8000);
    check($m->attr('LastCommand') === 'extend', 'mit Einfahrverzögerung: noch ausgefahren');
    check(str_contains((string) $m->value('Reason'), 'Einfahren in 15 Min'), 'Begründung: ' . $m->value('Reason'));
    $m->advance(15);
    check($m->attr('LastCommand') === 'retract', 'nach 15 Minuten gedacht eingefahren');
    $m->RequestAction('SimWind', 7);
    check($m->value('Status') === Markisensteuerung::ST_WIND, 'simulierter Windalarm');
    check(Sym::$actions === [], 'echter Wind ist ruhig: kein echter Befehl');
    check(Sym::$vars[V_WIND]['value'] === 2, 'echter Sensor unverändert');
});

test('Simulation: Verzögerungen und Sperren überspringen', function (): void {
    $m = markise(['SimulationMode' => true, 'SimSkipDelays' => true, 'DelayOn' => 5, 'DelayOff' => 15], '13:00', ['lux' => 5000.0]);
    $m->RequestAction('SimLux', 60000);
    check($m->attr('LastCommand') === 'extend', 'sofort ausgefahren');
    $m->RequestAction('SimWind', 8);
    $m->RequestAction('SimWind', 1);
    check($m->value('Safety') === false, 'keine Windsperre');
    check($m->attr('LastCommand') === 'extend', 'sofort wieder ausgefahren');
});

test('Simulation: Sperren zurücksetzen', function (): void {
    $m = markise(['SimulationMode' => true]);
    $m->RequestAction('SimWind', 8);
    $m->RequestAction('SimWind', 1);
    check($m->value('Safety') === true, 'Windsperre läuft');
    $m->RequestAction('SimClear', 0);
    check($m->value('Safety') === false, 'Sperre gelöscht');
    check($m->attr('LastCommand') === 'extend', 'wieder ausgefahren');
});

test('Simulation: Uhrzeit vorgeben', function (): void {
    $m = markise(['SimulationMode' => true]);
    $m->RequestAction('SimTime', '23:00');
    check($m->value('Status') === Markisensteuerung::ST_NIGHT, 'simulierte Nacht');
    $m->RequestAction('SimTime', '');
    check($m->value('Status') === Markisensteuerung::ST_SUN, 'leer = echte Uhrzeit');
    check(throws(fn () => $m->RequestAction('SimTime', '25:99')), 'ungültige Uhrzeit abgelehnt');
    check(throws(fn () => $m->RequestAction('SimLux', 'hell')), 'Text statt Zahl abgelehnt');
});

test('Simulation: Anwesenheit vorgeben', function (): void {
    $m = markise(['SimulationMode' => true]);
    $m->RequestAction('SimPresence', false);
    check($m->value('Status') === Markisensteuerung::ST_GRACE, 'simulierte Abwesenheit: Karenz');
});

test('Simulation: echter Windschutz bleibt aktiv', function (): void {
    $m = markise(['SimulationMode' => true, 'NotifySafety' => true]);
    $m->sensor(V_WIND, 8);
    check(actions() === [V_RETRACT . '=true'], 'echte Markise wirklich eingefahren');
    check(str_contains((string) $m->value('SimLog'), 'Echter Windalarm (8 Bft)'), 'im Protokoll');
    check(count(Sym::$notifications) === 1, 'eine Nachricht');
    check($m->timers['Repeat']['ms'] > 0, 'Wiederholung geplant');
    $m->RequestAction('RepeatRetract', 0);
    check(count(Sym::$actions) === 2, 'Einfahrbefehl wiederholt');
    $m->sensor(V_WIND, 9);
    check(count(Sym::$actions) === 2, 'kein Dauerfeuer');
    $m->sensor(V_WIND, 2);
    check(str_contains((string) $m->value('SimLog'), 'Echter Alarm vorbei'), 'Ende im Protokoll');
    check($m->value('Status') !== Markisensteuerung::ST_WIND, 'Simulation selbst sieht keinen Wind (simulierter Wert 2)');

    $n = markise(['SimulationMode' => true, 'SimRealSafety' => false]);
    $n->sensor(V_WIND, 8);
    check(Sym::$actions === [], 'abgeschaltet: kein echter Befehl');
});

test('Simulation: Bedienung von Hand ist auch nur simuliert', function (): void {
    $m = markise(['SimulationMode' => true]);
    $m->Retract();
    check(Sym::$actions === [], 'kein echter Befehl');
    check($m->value('Status') === Markisensteuerung::ST_MANUAL, 'Handbetrieb-Pause wie echt');
    check(str_contains((string) $m->value('SimLog'), 'Würde senden: Einfahren (Hand)'), 'im Protokoll');
    Sym::$now += 10;
    $m->sensor(V_RETRACT, true);
    check($m->attr('ManualUntil') <= Sym::$now + 3600, 'echte Aktorvariable wird in der Simulation ignoriert');
});

test('Simulation beenden: Zustand wird neu abgeglichen', function (): void {
    $m = markise(['SimulationMode' => true]);
    $m->RequestAction('SimWind', 8);
    check($m->attr('WindLockUntil') > 0, 'simulierte Windsperre');
    $m->prop('SimulationMode', false);
    $m->ApplyChanges();
    check(!$m->has('SimLux') && !$m->has('SimLog'), 'Simulationsvariablen entfernt');
    check($m->attr('WindLockUntil') === 0, 'simulierte Sperre gelöscht');
    check(actions() === [V_EXTEND . '=true'], 'echter Befehl nach echten Werten');
    check(!str_starts_with((string) $m->value('Reason'), 'Simulation'), 'Begründung ohne „Simulation:“');
    $tile = json_decode($m->attr('TileData'), true);
    check($tile['simulation'] === false, 'Kachel ohne Simulation');
});

test('Simulation: Werte nur in der Simulation und nur für eingestellte Sensoren', function (): void {
    $m = markise();
    check(throws(fn () => $m->RequestAction('SimLux', 1000)), 'ohne Simulation abgelehnt');
    check(throws(fn () => $m->RequestAction('SimClear', 0)), 'Zurücksetzen ohne Simulation abgelehnt');
    $n = markise(['SimulationMode' => true, 'GustVariableID' => 0]);
    check(!$n->has('SimGust'), 'kein Böensensor: keine Simulationsvariable');
    check(throws(fn () => $n->RequestAction('SimGust', 10)), 'abgelehnt');
});

// =====================================================================
// Version 1.2: Halten, Terrassentür, Unwetterwarnung, Böen-Trend, Lux-Mittel, Schaltlimit,
// eigener Standort und Übernahme aus dem bisherigen Skript
// =====================================================================

const V_DOOR = 207;
const V_WARN = 301;
const UWW_GUID = '{DCDBF64A-F2DC-B23B-2483-69BCAFFE091A}';

test('Halten: Markise bleibt nachts draußen, Sicherheit fährt trotzdem ein', function (): void {
    $m = markise();
    check($m->has('Hold') && isset($m->actionsEnabled['Hold']), 'Schalter Halten vorhanden und bedienbar');
    check(actions() === [V_EXTEND . '=true'], 'ausgefahren');
    $m->RequestAction('Hold', true);
    check($m->value('Status') === Markisensteuerung::ST_HOLD, 'Status Halten');
    $m->sensor(V_LUX, 0.0);
    $m->advance(60);
    check(count(Sym::$actions) === 1, 'dunkel: bleibt ausgefahren');
    Sym::$now = summer('22:30');
    $m->advance(1);
    check(count(Sym::$actions) === 1, 'Nacht: bleibt ausgefahren');
    $m->sensor(V_WIND, 7);
    check(lastAction() === V_RETRACT . '=true', 'Windalarm fährt trotzdem ein');
    check($m->value('Hold') === true, 'Halten bleibt eingeschaltet');
});

test('Halten endet beim Schließen der Terrassentür', function (): void {
    Sym::variable(V_DOOR, VARIABLETYPE_INTEGER, 2);
    $m = markise(['DoorVariableID' => V_DOOR, 'DoorClosedValue' => 0]);
    $m->RequestAction('Hold', true);
    $m->sensor(V_LUX, 5000.0);
    check(count(Sym::$actions) === 1 && $m->value('Hold') === true, 'Tür offen: hält');
    $m->sensor(V_DOOR, 0);
    check($m->value('Hold') === false, 'Tür zu: Halten aus');
    check(lastAction() === V_RETRACT . '=true', 'danach entscheidet die Automatik (zu dunkel → ein)');
});

test('Terrassentür: falscher Wert für „geschlossen“ fällt auf und lässt sich per Taste korrigieren', function (): void {
    Sym::variable(V_DOOR, VARIABLETYPE_INTEGER, 0); // bei dieser Tür bedeutet 0 „offen“
    $m = markise(['DoorVariableID' => V_DOOR, 'DoorClosedValue' => 0]);
    $json = json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
    check(str_contains($json, 'Wert 0') && str_contains($json, 'gilt als geschlossen'), 'Formular zeigt Wert und Deutung');
    $tile = json_decode($m->attr('TileData'), true);
    $row = array_values(array_filter($tile['sensors'], static fn ($r) => $r['k'] === 'door'))[0];
    check($row['limit'] === 'Wert 0', 'Kachel zeigt den Wert der Variable');
    Sym::$vars[V_DOOR]['value'] = 1; // Tür zu
    ob_start();
    $m->RequestAction('DoorTakeClosed', 0);
    $out = (string) ob_get_clean();
    check(in_array(['DoorClosedValue', 'value', 1], $m->formUpdates, true), 'Wert 1 als geschlossen ins Formular: ' . $out);
});

test('Kachel: Ampel mit Hysterese', function (): void {
    $m = markise(['LuxOn' => 25000, 'LuxOff' => 15000]);
    $dot = function () use ($m): array {
        $tile = json_decode($m->attr('TileData'), true);
        return array_column($tile['sensors'], 'ok', 'k');
    };
    check($dot()['lux'] === true, '50.000 lx: grün');
    $m->sensor(V_LUX, 20000.0);
    check($dot()['lux'] === null, '20.000 lx (zwischen den Grenzen): grau');
    $m->sensor(V_LUX, 10000.0);
    check($dot()['lux'] === false, '10.000 lx: rot');
    $m->sensor(V_TEMP, 17.5);
    check($dot()['temp'] === null, '17,5 °C bei 18 °C und 1 K Hysterese: grau');
});

test('Halten bei geschlossener Tür einschalten: erst das nächste Schließen beendet es', function (): void {
    Sym::variable(V_DOOR, VARIABLETYPE_INTEGER, 0);
    $m = markise(['DoorVariableID' => V_DOOR]);
    $m->RequestAction('Hold', true);
    $m->advance(5);
    check($m->value('Hold') === true, 'bleibt an, obwohl die Tür zu ist');
    $m->sensor(V_DOOR, 2);
    $m->sensor(V_DOOR, 0);
    check($m->value('Hold') === false, 'nach Öffnen und Schließen aus');
});

test('Halten endet bei Abwesenheit und am nächsten Morgen', function (): void {
    $m = markise(['GraceMinutes' => 30]);
    $m->RequestAction('Hold', true);
    $m->sensor(V_PRESENCE, false);
    $m->advance(29);
    check($m->value('Hold') === true, 'während der Karenz an');
    $m->advance(2);
    check($m->value('Hold') === false, 'nach der Karenz aus');

    $n = markise([], '21:00');
    $n->RequestAction('Hold', true);
    Sym::$now = summer('23:59');
    $n->advance(1);
    check($n->value('Hold') === true, 'Mitternacht, dunkel: noch an');
    Sym::$now += 7 * 3600;
    $n->advance(1);
    check($n->value('Hold') === false, 'am nächsten Morgen aus');
});

test('Halten abschaltbar', function (): void {
    $m = markise(['HoldEnabled' => false]);
    check(!$m->has('Hold'), 'keine Variable');
    check(throws(fn () => $m->SetHold(true)), 'Befehl abgelehnt');
    $tile = json_decode($m->attr('TileData'), true);
    check($tile['holdEnabled'] === false, 'Kachel ohne Halten-Taste');
});

test('Unwetterwarnung: automatisch gefunden, ab Stufe 2 einfahren, Hitze ignoriert', function (): void {
    Sym::$instances[960] = ['module' => UWW_GUID, 'props' => []];
    Sym::variable(V_WARN, VARIABLETYPE_INTEGER, 0);
    Sym::$idents[960]['Level'] = V_WARN;
    $m = markise(['UseWarning' => true]);
    check($m->attr('WarningVar') === V_WARN, 'Warnstufe gefunden');
    check(isset($m->messages[V_WARN]), 'Warnstufe wird überwacht');
    check(actions() === [V_EXTEND . '=true'], 'ohne Warnung ausgefahren');
    $m->sensor(V_WARN, 1);
    check(count(Sym::$actions) === 1, 'Stufe 1: bleibt');
    $m->sensor(V_WARN, 3);
    check(lastAction() === V_RETRACT . '=true', 'Stufe 3: eingefahren');
    check($m->value('Status') === Markisensteuerung::ST_WARNING, 'Status Unwetterwarnung');
    check(str_contains((string) $m->value('Reason'), 'Stufe 3'), 'Begründung: ' . $m->value('Reason'));
    $m->sensor(V_WARN, 12);
    check($m->value('Safety') === false, 'Hitzewarnung (12) ist kein Alarm');
    $tile = json_decode($m->attr('TileData'), true);
    check(in_array('warning', array_column($tile['sensors'], 'k'), true), 'Kachel zeigt die Warnstufe');
});

test('Unwetterwarnung: fehlende Quelle blockiert nichts, Taste sucht', function (): void {
    $m = markise(['UseWarning' => true]);
    check($m->attr('WarningVar') === 0 && $m->status === 102, 'nicht gefunden, Instanz läuft');
    check(actions() === [V_EXTEND . '=true'], 'fährt trotzdem aus');
    ob_start();
    $m->RequestAction('FindWarning', 0);
    $out = (string) ob_get_clean();
    check(str_contains($out, 'Indikatorvariable'), 'Hinweis zur Indikatorvariable');
    Sym::$instances[960] = ['module' => UWW_GUID, 'props' => []];
    Sym::variable(V_WARN, VARIABLETYPE_INTEGER, 0);
    Sym::$idents[960]['Level'] = V_WARN;
    ob_start();
    $m->RequestAction('FindWarning', 0);
    ob_end_clean();
    check(in_array(['WarningVariableID', 'value', V_WARN], $m->formUpdates, true), 'Feld wird gefüllt');
});

test('Böen-Trend: schneller Anstieg fährt vorsorglich ein', function (): void {
    $m = markise(['GustTrend' => true, 'GustAlarm' => 28.0, 'GustTrendRise' => 12.0, 'GustTrendShare' => 70]);
    $m->advance(2);
    $m->sensor(V_GUST, 15.0);
    $m->advance(1);
    check(count(Sym::$actions) === 1, 'leichter Anstieg: nichts');
    $m->sensor(V_GUST, 23.0);
    check(lastAction() === V_RETRACT . '=true', 'von 10 auf 23 km/h: eingefahren');
    check(str_contains((string) $m->value('Reason'), 'steigen schnell'), 'Begründung: ' . $m->value('Reason'));
    check($m->attr('WindLockUntil') > Sym::$now, 'Windsperre gesetzt');
});

test('Lux-Mittelwert: kurze Wolke löst nichts aus', function (): void {
    $m = markise(['LuxAverageMinutes' => 10, 'DelayOff' => 0]);
    Sym::$jitter = [];
    $m->advance(10);
    $m->sensor(V_LUX, 5000.0);
    check(count(Sym::$actions) === 1, 'Wolke: Mittelwert noch hoch, bleibt');
    $m->advance(2);
    $m->sensor(V_LUX, 50000.0);
    $m->advance(10);
    check(count(Sym::$actions) === 1, 'Sonne zurück: keine Fahrt');
    $m->sensor(V_LUX, 5000.0);
    $m->advance(10);
    check(lastAction() === V_RETRACT . '=true', 'dauerhaft dunkel: eingefahren');
    check(count(json_decode($m->attr('LuxHistory'), true)) <= 11, 'Verlauf bleibt kurz');
});

test('Schaltlimit pro Stunde', function (): void {
    $m = markise(['MaxMovesPerHour' => 2, 'FreezeMinutes' => 0]);
    Sym::$jitter = [];
    $m->sensor(V_LUX, 5000.0);
    check(count(Sym::$actions) === 2, 'zweite Fahrt erlaubt');
    $m->sensor(V_LUX, 50000.0);
    check(count(Sym::$actions) === 2, 'dritte Fahrt gesperrt');
    check(str_contains((string) $m->value('Reason'), 'Schaltlimit'), 'Begründung: ' . $m->value('Reason'));
    $m->sensor(V_WIND, 7);
    check(lastAction() === V_RETRACT . '=true', 'Sicherheit ist nie begrenzt');
    Sym::$now += 2 * 3600;
    $m->sensor(V_WIND, 2);
    $m->advance(1);
    check(lastAction() === V_EXTEND . '=true', 'eine Stunde später wieder frei: ' . $m->value('Reason'));
});

test('Eigener Standort hat Vorrang, Übernahme aus dem Location-Modul', function (): void {
    $m = markise(['UseSunPosition' => true, 'AzimuthFrom' => 135.0, 'AzimuthTo' => 300.0]);
    Sym::$location = [51.48, 7.22];
    $own = markise(['Latitude' => -33.86, 'Longitude' => 151.2]);
    $form = json_decode($own->GetConfigurationForm(), true);
    $json = json_encode($form, JSON_UNESCAPED_UNICODE);
    check(str_contains($json, 'eigener Standort -33,8600'), 'Formular nennt eigenen Standort');
    ob_start();
    $own->RequestAction('LocationFromModule', 0);
    ob_end_clean();
    check(in_array(['Latitude', 'value', 51.48], $own->formUpdates, true), 'Breitengrad aus dem Location-Modul ins Feld');
    check(in_array(['Longitude', 'value', 7.22], $own->formUpdates, true), 'Längengrad ins Feld');
    $mod = markise();
    $json = json_encode(json_decode($mod->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
    check(str_contains($json, 'Location-Modul 51,4800'), 'ohne eigene Angabe: Location-Modul');
});

/** Das ursprüngliche Skript (gekürzt auf die erkannten Stellen) */
const OLD_SCRIPT = <<<'PHP'
<?php
eval(IPS_GetScriptContent(16199));
$automatik = GetValueBoolean(21845);
$wochentage = [
    1 => ["name" => "Montag",     "id" => 501],
    2 => ["name" => "Dienstag",   "id" => 502],
    3 => ["name" => "Mittwoch",   "id" => 503],
    4 => ["name" => "Donnerstag", "id" => 504],
    5 => ["name" => "Freitag",    "id" => 505],
    6 => ["name" => "Samstag",    "id" => 506],
    7 => ["name" => "Sonntag",    "id" => 507]
];
$anwesend = GetValueBoolean(206);
$helligkeit = GetValueFloat(201);
$wind       = GetValueInteger(203);
$boe        = GetValueFloat(204); // Letzte Böe in km/h
$regen      = GetValueBoolean(205);
$temp       = GetValueFloat(202);
$LUX        = GetValueInteger(510);
$TEMP       = GetValueFloat(511);
$WIND_MAX   = GetValueInteger(512);
$WIND_ALARM = 6;
$BOE_ALARM_ID  = 513;
$TAG_NACHT_PRUEFUNG_ID    = 514;
$ABWESEND_KARENZ_SEK = 30 * 60;
//$ABWESEND_KARENZ_SEK = 0;
$HELLIGKEIT_FREEZE_LIMIT_SEK = 30 * 60;
if (!$tag_aktiv) {
    RequestAction(102, true);
}
if ($wind >= $WIND_ALARM) {
    RequestAction(102, true);
}
RequestAction(101, true);
RequestAction(102, true);
PHP;

function scriptVars(): void
{
    foreach ([501 => true, 502 => true, 503 => false, 504 => true, 505 => true, 506 => true, 507 => false] as $id => $v) {
        Sym::variable($id, VARIABLETYPE_BOOLEAN, $v);
    }
    Sym::variable(510, VARIABLETYPE_INTEGER, 20000);
    Sym::variable(511, VARIABLETYPE_FLOAT, 15.0);
    Sym::variable(512, VARIABLETYPE_INTEGER, 2);
    Sym::variable(513, VARIABLETYPE_FLOAT, 25.0);
    Sym::variable(514, VARIABLETYPE_BOOLEAN, false);
}

function formValue(TestMarkise $m, string $field): mixed
{
    $value = null;
    foreach ($m->formUpdates as [$f, $param, $v]) {
        if ($f === $field && $param === 'value') {
            $value = $v;
        }
    }
    return $value;
}

test('Übernahme aus dem ursprünglichen Skript', function (): void {
    $m = markise(['ExtendVariableID' => 0, 'RetractVariableID' => 0, 'BrightnessVariableID' => 0]);
    scriptVars();
    Sym::$scripts[34486] = OLD_SCRIPT;
    ob_start();
    $m->RequestAction('ImportScript', 34486);
    $out = (string) ob_get_clean();
    check(str_contains($out, 'Einstellungen aus dem Skript übernommen'), 'Zusammenfassung: ' . $out);
    check(str_contains($out, 'Ereignisse des alten Skripts'), 'Hinweis, das alte Skript abzuschalten');
    check(formValue($m, 'ExtendVariableID') === V_EXTEND, 'Ausfahren erkannt (seltener Befehl)');
    check(formValue($m, 'RetractVariableID') === V_RETRACT, 'Einfahren erkannt (häufiger Befehl)');
    check(formValue($m, 'BrightnessVariableID') === V_LUX, 'Helligkeit');
    check(formValue($m, 'WindVariableID') === V_WIND && formValue($m, 'WindUnit') === 0, 'Wind in Bft');
    check(formValue($m, 'GustVariableID') === V_GUST && formValue($m, 'GustUnit') === 1, 'Böen in km/h');
    check(formValue($m, 'PresenceVariableID') === V_PRESENCE, 'Anwesenheit');
    check(formValue($m, 'LuxOn') === 20000 && formValue($m, 'LuxOff') === 20000, 'Luxgrenze aus der Vorgabe-Variable');
    check(formValue($m, 'TempMin') === 15.0, 'Temperatur aus der Vorgabe-Variable');
    check(formValue($m, 'WindMax') === 2.0 && formValue($m, 'WindAlarm') === 6.0, 'Windgrenzen');
    check(formValue($m, 'GustAlarm') === 25.0, 'Böenalarm aus der Variable');
    check(formValue($m, 'DayCheck') === false, 'Tag/Nacht-Prüfung aus');
    check(formValue($m, 'Weekday3') === false && formValue($m, 'Weekday1') === true && formValue($m, 'Weekday7') === false, 'Wochentage');
    check(formValue($m, 'GraceMinutes') === 30, 'Karenz 30 min (auskommentierte Zeile zählt nicht)');
    check($m->properties['ExtendVariableID'] === 0, 'nichts gespeichert, nur das Formular gefüllt');
});

test('Übernahme aus dem überarbeiteten Skript mit Konstanten', function (): void {
    $m = markise();
    scriptVars();
    Sym::variable(V_DOOR, VARIABLETYPE_INTEGER, 0);
    Sym::$scripts[34487] = (string) file_get_contents(__DIR__ . '/fixtures/Markisenskript.php');
    ob_start();
    $m->RequestAction('ImportScript', 34487);
    $out = (string) ob_get_clean();
    check(str_contains($out, 'übernommen'), 'Zusammenfassung');
    check(formValue($m, 'DoorVariableID') === V_DOOR, 'Terrassentür');
    check(formValue($m, 'LuxOn') === 23000 && formValue($m, 'LuxOff') === 17000, 'Luxgrenzen ±15 %');
    check(formValue($m, 'TempMin') === 15.5 && formValue($m, 'TempHysteresis') === 1.0, 'Temperatur mit Hysterese');
    check(formValue($m, 'WindLockMinutes') === 20 && formValue($m, 'RainLockMinutes') === 45, 'Sperrzeiten');
    check(formValue($m, 'DelayOn') === 5 && formValue($m, 'DelayOff') === 15, 'Verzögerungen');
    check(formValue($m, 'MaxMovesPerHour') === 4 && formValue($m, 'LuxAverageMinutes') === 10, 'Schaltlimit und Mittelwert');
    check(formValue($m, 'GustTrendRise') === 12.0 && formValue($m, 'GustTrendShare') === 70, 'Böen-Trend');
    check(formValue($m, 'UseSunPosition') === true && formValue($m, 'AzimuthFrom') === 135.0 && formValue($m, 'AzimuthTo') === 300.0, 'Sonnenrichtung');
    check(formValue($m, 'Latitude') === 53.22 && formValue($m, 'Longitude') === 7.8, 'Standort');
    check(formValue($m, 'UseWarning') === true && formValue($m, 'WarningVariableID') === 0, 'Unwetterwarnung automatisch');
});

test('Übernahme: Fehler werden freundlich gemeldet', function (): void {
    $m = markise();
    ob_start();
    $m->RequestAction('ImportScript', 0);
    $a = (string) ob_get_clean();
    Sym::$scripts[1] = "<?php\necho 'Hallo';";
    ob_start();
    $m->RequestAction('ImportScript', 1);
    $b = (string) ob_get_clean();
    check(str_contains($a, 'auswählen') && str_contains($b, 'nichts erkannt'), 'Hinweise: ' . $a . ' / ' . $b);
    check($m->formUpdates === [], 'nichts verändert');
});

test('Simulation: Terrassentür und Warnstufe', function (): void {
    Sym::variable(V_DOOR, VARIABLETYPE_INTEGER, 2);
    $m = markise(['SimulationMode' => true, 'DoorVariableID' => V_DOOR, 'UseWarning' => true]);
    check($m->has('SimDoor') && $m->has('SimWarning'), 'Simulationsvariablen vorhanden');
    check($m->value('SimDoor') === true, 'Tür offen übernommen');
    $m->RequestAction('Hold', true);
    $m->RequestAction('SimDoor', false);
    check($m->value('Hold') === false, 'simuliert geschlossen: Halten aus');
    $m->RequestAction('SimWarning', 3);
    check($m->value('Status') === Markisensteuerung::ST_WARNING, 'simulierte Warnung');
    check(Sym::$actions === [], 'nichts wirklich bewegt');
    $m->RequestAction('SimWarning', 9);
    check($m->value('SimWarning') === 4, 'Warnstufe begrenzt');
});

// =====================================================================
// Zweite Kachel: Markisen-Einstellungen
// =====================================================================

const MARKISE_GUID = '{7EF9655A-D369-4F11-A33B-CE8053758685}';

function einstellungen(TestMarkise $m, array $props = []): MarkisenEinstellungen
{
    Sym::$instances[$m->InstanceID] = ['module' => MARKISE_GUID, 'props' => []];
    $s = new MarkisenEinstellungen(2000);
    $s->Create();
    $s->prop('TargetInstance', $m->InstanceID);
    foreach ($props as $k => $v) {
        $s->prop($k, $v);
    }
    $s->ApplyChanges();
    return $s;
}

function kachel(MarkisenEinstellungen $s): array
{
    return json_decode($s->attr('TileData'), true);
}

function wert(array $tile, string $name): mixed
{
    foreach ($tile['groups'] as $g) {
        foreach ($g['items'] as $i) {
            if ($i['name'] === $name) {
                return $i['value'];
            }
        }
    }
    foreach ($tile['weekdays'] as $w) {
        if ($w['name'] === $name) {
            return $w['value'];
        }
    }
    return null;
}

test('Einstellungs-Kachel: zeigt die Grundwerte der Markisensteuerung', function (): void {
    $m = markise(['LuxOn' => 25000, 'LuxOff' => 15000, 'Weekday3' => false]);
    $s = einstellungen($m);
    check($s->status === 102 && $s->visualizationType === 1, 'aktiv mit Kachel');
    check(isset($s->messages[$m->InstanceID][IM_CHANGESETTINGS]) && isset($s->references[$m->InstanceID]), 'hört auf Änderungen der Markise');
    $t = kachel($s);
    check(array_column($t['groups'], 'title') === ['Sonnenautomatik', 'Sicherheit', 'Zeiten', 'Anwesenheit'], 'Gruppen übersetzt');
    check(wert($t, 'LuxOn') === 25000 && wert($t, 'LuxOff') === 15000, 'Luxgrenzen');
    check(wert($t, 'Weekday3') === false && wert($t, 'Weekday1') === true, 'Wochentage');
    check(wert($t, 'TimeFrom') === '09:00', 'Zeitfenster als HH:MM');
    check(wert($t, 'WarningMinLevel') === null, 'Warnstufe nur mit Unwetterwarnung');
    check(str_contains($s->GetVisualizationTile(), '"LuxOn"'), 'Startdaten in der Kachel');
});

test('Einstellungs-Kachel: Änderungen landen in der Markisensteuerung', function (): void {
    $m = markise(['LuxOn' => 25000, 'LuxOff' => 15000]);
    $s = einstellungen($m);
    $s->RequestAction('Set', json_encode(['name' => 'LuxOn', 'value' => 30000]));
    check($m->properties['LuxOn'] === 30000, 'Eigenschaft der Markise geändert');
    check(wert(kachel($s), 'LuxOn') === 30000, 'Kachel aktualisiert');
    $s->RequestAction('Set', json_encode(['name' => 'LuxOff', 'value' => 40000]));
    check($m->properties['LuxOff'] === 40000 && $m->properties['LuxOn'] === 40000, 'Einfahrgrenze über Ausfahrgrenze: Ausfahrgrenze zieht mit');
    check($m->status === 102, 'Markise bleibt gültig (kein Status 203)');
    $s->RequestAction('Set', json_encode(['name' => 'TempMin', 'value' => 99]));
    check($m->properties['TempMin'] === 40.0, 'auf den Bereich begrenzt');
    $s->RequestAction('Set', json_encode(['name' => 'Weekday7', 'value' => false]));
    check($m->properties['Weekday7'] === false, 'Wochentag');
    $s->RequestAction('Set', json_encode(['name' => 'TimeTo', 'value' => '21:30']));
    check(json_decode($m->properties['TimeTo'], true)['hour'] === 21, 'Uhrzeit');
    $m->prop('DelayOn', 12);
    IPS_ApplyChanges($m->InstanceID);
    check(wert(kachel($s), 'DelayOn') === 12, 'Änderung im Formular erscheint in der Kachel');
});

test('Einstellungs-Kachel: Anwesenheitsschalter', function (): void {
    $m = markise();
    Sym::$vars[V_PRESENCE]['action'] = true;
    $s = einstellungen($m);
    $t = kachel($s);
    check(array_column($t['groups'], 'key') === ['Sun protection', 'Safety', 'Times', 'Presence'], 'Gruppe Anwesenheit rechts unter Sicherheit');
    check(wert($t, 'Presence') === true && wert($t, 'GraceMinutes') === 30, 'Schalter und Karenz in der Gruppe');
    check(isset($s->messages[V_PRESENCE][VM_UPDATE]), 'Anwesenheit wird überwacht');
    $s->RequestAction('Set', json_encode(['name' => 'Presence', 'value' => false]));
    check(lastAction() === V_PRESENCE . '=false', 'schaltet die Anwesenheitsvariable über ihre Aktion');
    check(!array_key_exists('Presence', $m->properties), 'wird nicht als Eigenschaft gespeichert');
    $s->MessageSink(Sym::$now, V_PRESENCE, VM_UPDATE, [true]);
    Sym::$vars[V_PRESENCE]['value'] = true;
    $s->MessageSink(Sym::$now, V_PRESENCE, VM_UPDATE, [true]);
    check(wert(kachel($s), 'Presence') === true, 'Änderung von außen erscheint in der Kachel');

    Sym::$vars[V_PRESENCE]['action'] = false;
    $s->ApplyChanges();
    $item = array_values(array_filter(kachel($s)['groups'][3]['items'], static fn ($i) => $i['name'] === 'Presence'))[0];
    check($item['readOnly'] === true, 'ohne Aktion nur Anzeige');
    check(throws(fn () => $s->RequestAction('Set', json_encode(['name' => 'Presence', 'value' => true]))), 'Schalten ohne Aktion abgelehnt');

    $n = markise(['PresenceVariableID' => 0]);
    check(wert(kachel(einstellungen($n)), 'Presence') === null, 'ohne Anwesenheitsvariable kein Schalter');
});

test('Anwesenheitsvariable unter die Instanz verschoben: Schalter funktioniert trotzdem', function (): void {
    $m = markise();
    // Unter einer Instanz ohne eigene Aktion landet die Standardaktion bei der Instanz
    Sym::$vars[V_PRESENCE]['parent'] = $m->InstanceID;
    Sym::$vars[V_PRESENCE]['ident'] = '';
    Sym::$vars[V_PRESENCE]['action'] = true;
    $s = einstellungen($m);
    $item = array_values(array_filter(kachel($s)['groups'][3]['items'], static fn ($i) => $i['name'] === 'Presence'))[0];
    check($item['readOnly'] === false, 'Schalter bedienbar');
    $s->RequestAction('Set', json_encode(['name' => 'Presence', 'value' => false]));
    check(Sym::$vars[V_PRESENCE]['value'] === false, 'Wert direkt gesetzt');
    $m->RequestAction('', true);
    check(Sym::$vars[V_PRESENCE]['value'] === true, 'Schalten im Objektbaum (Aktion an der Instanz) setzt den Wert');
    check(throws(fn () => $m->RequestAction('Unbekannt', true)), 'andere Idents weiter abgelehnt');
});

test('Einstellungs-Kachel: ungültige Eingaben werden abgelehnt', function (): void {
    $m = markise();
    $s = einstellungen($m);
    check(throws(fn () => $s->RequestAction('Set', json_encode(['name' => 'ExtendVariableID', 'value' => 1]))), 'Aktor-ID nicht änderbar');
    check(throws(fn () => $s->RequestAction('Set', json_encode(['name' => 'LuxOn', 'value' => 'abc']))), 'Text statt Zahl');
    check(throws(fn () => $s->RequestAction('Set', json_encode(['name' => 'TimeFrom', 'value' => '25:00']))), 'ungültige Uhrzeit');
    check(throws(fn () => $s->RequestAction('Set', 'kein JSON')), 'kaputte Daten');
    check(throws(fn () => $s->RequestAction('Andere', 1)), 'unbekannter Ident');
    $ro = einstellungen($m, ['AllowChanges' => false]);
    check(throws(fn () => $ro->RequestAction('Set', json_encode(['name' => 'LuxOn', 'value' => 1000]))), 'nur Anzeige');
    check(kachel($ro)['readOnly'] === true, 'Kachel weiß, dass nur angezeigt wird');
    $none = new MarkisenEinstellungen(2001);
    $none->Create();
    $none->ApplyChanges();
    check($none->status === 200 && kachel($none)['error'] !== '', 'ohne Markise: Hinweis');
    Sym::$instances[950]['module'] = 'visu';
    $none->prop('TargetInstance', 950);
    $none->ApplyChanges();
    check($none->status === 201, 'falsche Instanz: Status 201');
});

test('Einstellungs-Kachel: Übersetzung vollständig', function (): void {
    $dir = __DIR__ . '/../MarkisenEinstellungen/';
    $de = json_decode((string) file_get_contents($dir . 'locale.json'), true)['translations']['de'];
    $missing = [];
    preg_match_all("/Translate\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string) file_get_contents($dir . 'module.php'), $m);
    foreach ($m[1] as $s) {
        if (!isset($de[stripcslashes($s)])) {
            $missing[] = $s;
        }
    }
    $form = json_decode((string) file_get_contents($dir . 'form.json'), true);
    array_walk_recursive($form, function ($v, $k) use ($de, &$missing): void {
        if ($k === 'caption' && $v !== '' && !isset($de[$v])) {
            $missing[] = $v;
        }
    });
    preg_match_all('/data-t="([^"]+)"/', (string) file_get_contents($dir . 'tile.html'), $tm);
    foreach ($tm[1] as $s) {
        if (!isset($de[$s])) {
            $missing[] = $s;
        }
    }
    check($missing === [], 'fehlend: ' . implode(' | ', $missing));
});

// =====================================================================

echo PHP_EOL . $passed . ' Prüfungen bestanden, ' . count($failed) . ' fehlgeschlagen.' . PHP_EOL;
foreach ($failed as $f) {
    echo '  ✗ ' . $f . PHP_EOL;
}
exit(count($failed) === 0 ? 0 : 1);
