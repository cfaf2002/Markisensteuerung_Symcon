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

test('Ohne Aktor: Status 104, keine Aktion', function (): void {
    $m = markise(['ExtendVariableID' => 0]);
    check($m->status === 104, 'Status 104 (ist ' . $m->status . ')');
    check(Sym::$actions === [], 'keine Aktion');
    check($m->timers['Tick']['ms'] === 0, 'Timer aus');
    $tile = json_decode($m->attr('TileData'), true);
    check(($tile['error'] ?? '') !== '', 'Kachel zeigt Hinweis');
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

// =====================================================================

echo PHP_EOL . $passed . ' Prüfungen bestanden, ' . count($failed) . ' fehlgeschlagen.' . PHP_EOL;
foreach ($failed as $f) {
    echo '  ✗ ' . $f . PHP_EOL;
}
exit(count($failed) === 0 ? 0 : 1);
