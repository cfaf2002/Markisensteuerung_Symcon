<?php
// Testkopie des überarbeiteten Markisenskripts (IDs an die Testumgebung angepasst)
eval(IPS_GetScriptContent(16199)); // stellt debugMarkise() bereit

// =====================================================================
// ⚙️ KONFIGURATION – Objekt-IDs
// =====================================================================

const ID_AUTOMATIK     = 520; // Markisenautomatik aus/Winterzeit (true = Automatik aktiv)
const ID_ANWESEND      = 206; // Anwesenheit auf der Terrasse
const ID_HELLIGKEIT    = 201; // Helligkeit in lx
const ID_WIND          = 203; // Windstärke (Bft, Integer)
const ID_BOE           = 204; // Böen der letzten 5 min in km/h
const ID_REGEN         = 205; // Regen (Boolean)
const ID_TEMP          = 202; // Außentemperatur
const ID_LUX_VORGABE   = 510; // LuxVorgabe
const ID_TEMP_VORGABE  = 511; // Temperatur Vorgabe
const ID_WIND_MAX      = 512; // Max. Windstärke zum Ausfahren (Bft)
const ID_BOE_ALARM     = 513; // Vorgabe Böen Alarm km/h
const ID_TAG_NACHT     = 514; // Tag/Nacht-Prüfung aktiv
const ID_AUFGANG       = 521; // Sonnenaufgang (Timestamp)
const ID_UNTERGANG     = 522; // Sonnenuntergang (Timestamp)
const ID_KARENZ_NEU    = 523; // Taster: Karenz erneut auf 100 %
const ID_KARENZ_ENDE   = 524; // Taster: Karenz auf 0 % setzen
const ID_EINFAHREN     = 102; // Aktion Einfahren
const ID_AUSFAHREN     = 101; // Aktion Ausfahren
const ID_TERRASSENTUER = 207;   // Terrassentür (Integer)

const TUER_WERT_GESCHLOSSEN = 0; // Wert von 59460 für „geschlossen“ – ggf. anpassen

// Kachel-Visualisierung für Push-Mitteilungen (Instanz-ID), 0 = keine Push-Mitteilungen
const ID_VISU = 0;

// DWD-Unwetterwarnung (Wilkware-Modul): ID der Variable „Warnstufe“
// 0 = automatisch suchen (erste Unwetterwarnung-Instanz), -1 = DWD nicht verwenden
const ID_DWD_WARNSTUFE = 0;
const UWW_MODUL_GUID   = '{DCDBF64A-F2DC-B23B-2483-69BCAFFE091A}';
const DWD_MIN_STUFE    = 2; // ab „markantes Wetter“ einfahren (1 = Wetterwarnung, 2 = markant, 3 = Unwetter, 4 = extrem)

const WOCHENTAGE = [
    1 => ['Montag',     501],
    2 => ['Dienstag',   502],
    3 => ['Mittwoch',   503],
    4 => ['Donnerstag', 504],
    5 => ['Freitag',    505],
    6 => ['Samstag',    506],
    7 => ['Sonntag',    507],
];

// =====================================================================
// 🎛️ KONFIGURATION – Parameter
// =====================================================================

// Sicherheit
const WIND_ALARM_BFT             = 6;       // ab dieser Windstärke sofort einfahren
const BOE_ALARM_FALLBACK         = 28.0;    // km/h, falls Vorgabe 0 oder leer
const BOE_TREND_KMH              = 12.0;    // Böen-Anstieg innerhalb des Fensters → vorsorglich einfahren …
const BOE_TREND_FENSTER_SEK      = 15 * 60;
const BOE_TREND_MIN_ANTEIL       = 0.7;     // … aber nur ab 70 % der Böen-Alarmschwelle
const WIND_SPERRE_SEK            = 20 * 60; // nach Windalarm nicht wieder ausfahren
const REGEN_SPERRE_SEK           = 45 * 60; // nach Regen Trocknungszeit
const WINDSENSOR_TIMEOUT_SEK     = 30 * 60; // Wind/Böe nicht aktualisiert → Sensor ausgefallen
const HELLIGKEIT_FREEZE_SEK      = 30 * 60; // Helligkeit unverändert → Sensor eingefroren
const SICHERHEIT_WIEDERHOLEN_SEK = 10 * 60; // Einfahrbefehl bei Sicherheitsgrund regelmäßig wiederholen

// Komfort / Ruhe
const LUX_MITTEL_SEK             = 10 * 60; // gleitender Lux-Mittelwert
const LUX_HYST_PROZENT           = 15;      // Ausfahren ab Vorgabe +15 %, Einfahren ab Vorgabe −15 %
const TEMP_HYST_K                = 0.5;     // Ausfahren ab Vorgabe +0,5 K, Einfahren ab Vorgabe −0,5 K
const AUSFAHR_VERZOEGERUNG_SEK   = 5 * 60;  // Bedingungen müssen so lange durchgehend erfüllt sein
const EINFAHR_VERZOEGERUNG_SEK   = 15 * 60; // so lange durchgehend zu dunkel/kalt, bevor eingefahren wird
const MIN_STANDZEIT_SEK          = 10 * 60; // Mindestzeit zwischen zwei Komfort-Schaltungen
const MAX_SCHALTUNGEN_PRO_STUNDE = 4;       // Komfort-Schaltungen, danach Pause

// Sonnenstand – Terrasse nach Südwesten (225°)
const SONNENSTAND_PRUEFEN        = true;
const STANDORT_BREITE            = 53.22;   // Breitengrad – ggf. genauer eintragen
const STANDORT_LAENGE            = 7.80;    // Längengrad
const SONNE_AZIMUT_VON           = 135.0;   // Sonne steht auf der Terrasse ab Südost …
const SONNE_AZIMUT_BIS           = 300.0;   // … bis Westnordwest
const SONNE_MIN_HOEHE            = 10.0;    // darunter verdecken meist Bäume/Häuser die Sonne

const KARENZ_SEK                 = 30 * 60; // Abwesenheits-Karenz
const ZYKLUS_SEK                 = 60;      // zyklische Prüfung

// =====================================================================
// 🔧 HILFSFUNKTIONEN
// =====================================================================

function wert(int $id)
{
    if (!IPS_VariableExists($id)) {
        throw new Exception("Variable #$id existiert nicht");
    }
    return GetValue($id);
}

function alterSek(int $id, string $feld): int
{
    return time() - (int)IPS_GetVariable($id)[$feld];
}

function minuten(int $sek): int
{
    return (int)ceil(max(0, $sek) / 60);
}

function zeitInMinuten(int $ts): int
{
    return (int)date('G', $ts) * 60 + (int)date('i', $ts);
}

function hilfsVariable(int $parent, string $ident, string $name, int $typ, $start): int
{
    $id = @IPS_GetObjectIDByIdent($ident, $parent);
    if ($id === false) {
        $id = IPS_CreateVariable($typ);
        IPS_SetParent($id, $parent);
        IPS_SetIdent($id, $ident);
        IPS_SetName($id, $name);
        IPS_SetHidden($id, true);
        SetValue($id, $start);
    }
    return $id;
}

/** Schaltbare Variable „Markise halten (Abendmodus)“, Aktionsskript ist dieses Skript. */
function halteSchalter(int $scriptId): int
{
    $id = @IPS_GetObjectIDByIdent('MARKISE_HALTEN', $scriptId);
    if ($id === false) {
        $id = IPS_CreateVariable(0);
        IPS_SetParent($id, $scriptId);
        IPS_SetIdent($id, 'MARKISE_HALTEN');
        IPS_SetName($id, 'Markise halten (Abendmodus)');
        IPS_SetIcon($id, 'Moon');
        IPS_SetVariableCustomProfile($id, '~Switch');
        IPS_SetVariableCustomAction($id, $scriptId);
        SetValueBoolean($id, false);
    }
    return $id;
}

function ereignisSicherstellen(int $scriptId, string $ident, string $name, ?int $triggerVariable): void
{
    if (@IPS_GetObjectIDByIdent($ident, $scriptId) !== false) {
        return;
    }
    if ($triggerVariable === null) {
        $eid = IPS_CreateEvent(1); // zyklisch
        IPS_SetEventCyclic($eid, 0, 0, 0, 0, 1, ZYKLUS_SEK);
    } else {
        $eid = IPS_CreateEvent(0); // ausgelöst
        IPS_SetEventTrigger($eid, 1, $triggerVariable); // 1 = bei Änderung
    }
    IPS_SetParent($eid, $scriptId);
    IPS_SetIdent($eid, $ident);
    IPS_SetName($eid, $name);
    IPS_SetEventActive($eid, true);
}

function jsonLesen(int $id): array
{
    $daten = json_decode(GetValueString($id), true);
    return is_array($daten) ? $daten : [];
}

/**
 * Sonnenstand (vereinfachte astronomische Formel, Genauigkeit ca. ±1°)
 * @return array [Azimut in Grad ab Nord im Uhrzeigersinn, Höhe in Grad]
 */
function sonnenstand(int $ts, float $breite, float $laenge): array
{
    $n      = $ts / 86400 + 2440587.5 - 2451545.0;            // Tage seit J2000
    $L      = fmod(280.460 + 0.9856474 * $n, 360);
    $g      = deg2rad(fmod(357.528 + 0.9856003 * $n, 360));
    $lambda = deg2rad($L + 1.915 * sin($g) + 0.020 * sin(2 * $g));
    $eps    = deg2rad(23.439 - 0.0000004 * $n);
    $ra     = atan2(cos($eps) * sin($lambda), cos($lambda));
    $dek    = asin(sin($eps) * sin($lambda));
    $gmst   = fmod(18.697374558 + 24.06570982441908 * $n, 24);
    $ha     = deg2rad(fmod($gmst * 15 + $laenge, 360)) - $ra;  // Stundenwinkel
    $phi    = deg2rad($breite);

    $hoehe  = asin(sin($phi) * sin($dek) + cos($phi) * cos($dek) * cos($ha));
    $azimut = atan2(-sin($ha), tan($dek) * cos($phi) - sin($phi) * cos($ha));

    return [fmod(rad2deg($azimut) + 360, 360), rad2deg($hoehe)];
}

/** ID der DWD-Warnstufe ermitteln, 0 = nicht vorhanden */
function dwdWarnstufeId(): int
{
    if (ID_DWD_WARNSTUFE > 0) {
        return IPS_VariableExists(ID_DWD_WARNSTUFE) ? ID_DWD_WARNSTUFE : 0;
    }
    if (ID_DWD_WARNSTUFE < 0) {
        return 0;
    }
    foreach (IPS_GetInstanceListByModuleID(UWW_MODUL_GUID) as $instanz) {
        $id = @IPS_GetObjectIDByIdent('Level', $instanz);
        if ($id !== false) {
            return $id;
        }
    }
    return 0;
}

function push(string $titel, string $text): void
{
    if (ID_VISU > 0 && function_exists('VISU_PostNotification') && IPS_InstanceExists(ID_VISU)) {
        @VISU_PostNotification(ID_VISU, $titel, $text, 'Warning', 0);
    }
}

/** Komfort-Schaltungen der letzten Stunde */
function schaltLog(): array
{
    $grenze = time() - 3600;
    return array_values(array_filter(
        jsonLesen($GLOBALS['MK']['schaltlog']),
        fn($ts) => (int)$ts >= $grenze
    ));
}

/**
 * Schaltet nur bei Zustandswechsel.
 * $art: SICHERHEIT = sofort, Einfahrbefehl wird regelmäßig wiederholt, Push bei Wechsel
 *       SPERRE     = sofort, keine Wiederholung
 *       KOMFORT    = mit Mindeststandzeit und Schaltlimit
 */
function schalten(int $ziel, string $art, string $grund): string
{
    $mk      = $GLOBALS['MK'];
    $zustand = GetValueInteger($mk['zustand']);
    $seit    = time() - GetValueInteger($mk['befehlTs']);
    $wort    = $ziel === 1 ? 'ausgefahren' : 'eingefahren';
    $wechsel = $zustand !== $ziel;
    $log     = schaltLog();

    if (!$wechsel) {
        // Falls die Markise zwischendurch von Hand ausgefahren wurde
        if (!($art === 'SICHERHEIT' && $seit >= SICHERHEIT_WIEDERHOLEN_SEK)) {
            return "$grund → Markise bleibt $wort";
        }
    } elseif ($art === 'KOMFORT' && $zustand !== -1) {
        if ($seit < MIN_STANDZEIT_SEK) {
            return "$grund → Mindeststandzeit, Wechsel in " . minuten(MIN_STANDZEIT_SEK - $seit) . ' Min.';
        }
        if (count($log) >= MAX_SCHALTUNGEN_PRO_STUNDE) {
            return "$grund → Schaltlimit (" . MAX_SCHALTUNGEN_PRO_STUNDE . '/h) erreicht, Markise bleibt';
        }
    }

    RequestAction($ziel === 1 ? ID_AUSFAHREN : ID_EINFAHREN, true);
    SetValueInteger($mk['zustand'], $ziel);
    SetValueInteger($mk['befehlTs'], time());

    if ($art === 'KOMFORT' && $wechsel) {
        $log[] = time();
        SetValueString($mk['schaltlog'], json_encode($log));
    }
    if ($art === 'SICHERHEIT' && $wechsel) {
        push('Markise eingefahren', $grund);
    }

    return "$grund → Markise $wort";
}

// =====================================================================
// ▶️ ABLAUF
// =====================================================================

$self = $_IPS['SELF'];

// Das Zurücksetzen der Taster löst das Ereignis erneut aus – diesen Lauf überspringen
if (($_IPS['SENDER'] ?? '') === 'Variable'
    && in_array((int)($_IPS['VARIABLE'] ?? 0), [ID_KARENZ_NEU, ID_KARENZ_ENDE], true)
    && empty($_IPS['VALUE'])) {
    return;
}

try {

    // ---------- Infrastruktur ----------
    ereignisSicherstellen($self, 'MARKISE_ZYKLUS', 'Zyklische Prüfung', null);
    ereignisSicherstellen($self, 'MARKISE_TUER_TRIGGER', 'Terrassentür geändert', ID_TERRASSENTUER);
    ereignisSicherstellen($self, 'MARKISE_HELL_TRIGGER', 'Helligkeit geändert', ID_HELLIGKEIT);
    $dwdId = dwdWarnstufeId();
    if ($dwdId > 0) {
        ereignisSicherstellen($self, 'MARKISE_DWD_TRIGGER', 'DWD-Warnstufe geändert', $dwdId);
    }
    if (function_exists('IPS_SetScriptTimer')) {
        IPS_SetScriptTimer($self, 0); // alter Karenz-Timer wird nicht mehr gebraucht
    }

    $V_ZUSTAND      = hilfsVariable($self, 'MARKISE_ZUSTAND',           'Markise Zustand (Automatik)',   1, -1); // -1 unbekannt, 0 ein, 1 aus
    $V_BEFEHL_TS    = hilfsVariable($self, 'MARKISE_LETZTER_BEFEHL_TS', 'Markise letzter Befehl',        1, 0);
    $V_WIND_SPERRE  = hilfsVariable($self, 'MARKISE_WIND_SPERRE_BIS',   'Markise Wind-Sperre bis',       1, 0);
    $V_REGEN_SPERRE = hilfsVariable($self, 'MARKISE_REGEN_SPERRE_BIS',  'Markise Regen-Sperre bis',      1, 0);
    $V_ABWESEND     = hilfsVariable($self, 'MARKISE_ABWESEND_SEIT_TS',  'Markise Abwesend seit',         1, 0);
    $V_AUS_OK_SEIT  = hilfsVariable($self, 'MARKISE_AUSFAHREN_OK_SEIT', 'Markise Ausfahren erfüllt seit', 1, 0);
    $V_EIN_SEIT     = hilfsVariable($self, 'MARKISE_EINFAHREN_SEIT',    'Markise Einfahren nötig seit',  1, 0);
    $V_VERLAUF      = hilfsVariable($self, 'MARKISE_VERLAUF',           'Markise Messwert-Verlauf',      3, '[]');
    $V_SCHALTLOG    = hilfsVariable($self, 'MARKISE_SCHALTLOG',         'Markise Schaltungen',           3, '[]');
    $V_SENSOR_GEMELDET = hilfsVariable($self, 'MARKISE_SENSOR_GEMELDET', 'Markise Sensorausfall gemeldet', 0, false);
    $V_HALTEN       = halteSchalter($self);

    $GLOBALS['MK'] = [
        'zustand'   => $V_ZUSTAND,
        'befehlTs'  => $V_BEFEHL_TS,
        'schaltlog' => $V_SCHALTLOG,
    ];

    // Schalter „Markise halten“ wurde in Kachel/WebFront betätigt → Wert übernehmen
    if (($_IPS['SENDER'] ?? '') !== 'Variable'
        && (int)($_IPS['VARIABLE'] ?? 0) === $V_HALTEN
        && isset($_IPS['VALUE'])) {
        SetValueBoolean($V_HALTEN, (bool)$_IPS['VALUE']);
    }

    $jetzt = time();

    // ---------- Werte lesen ----------
    $automatik     = (bool)wert(ID_AUTOMATIK);
    $anwesend      = (bool)wert(ID_ANWESEND);
    $helligkeit    = (float)wert(ID_HELLIGKEIT);
    $wind          = (int)wert(ID_WIND);
    $boe           = (float)wert(ID_BOE);
    $regen         = (bool)wert(ID_REGEN);
    $temp          = (float)wert(ID_TEMP);
    $LUX           = (float)wert(ID_LUX_VORGABE);
    $TEMP          = (float)wert(ID_TEMP_VORGABE);
    $WIND_MAX      = (int)wert(ID_WIND_MAX);
    $tagNachtAktiv = (bool)wert(ID_TAG_NACHT);
    $aufgang_ts    = (int)wert(ID_AUFGANG);
    $untergang_ts  = (int)wert(ID_UNTERGANG);

    $BOE_ALARM = (float)wert(ID_BOE_ALARM);
    if ($BOE_ALARM <= 0) {
        $BOE_ALARM = BOE_ALARM_FALLBACK;
    }

    // ---------- Wochentag ----------
    $wochentag = (int)date('N');
    [$wochentagName, $wochentagId] = WOCHENTAGE[$wochentag];
    $tag_aktiv = (bool)wert($wochentagId);

    $aktiveWochentage = [];
    foreach (WOCHENTAGE as [$name, $id]) {
        if ((bool)wert($id)) {
            $aktiveWochentage[] = $name;
        }
    }
    $aktiveWochentageText = $aktiveWochentage ? implode(', ', $aktiveWochentage) : 'Keine';

    // ---------- Tag / Nacht ----------
    $jetztM      = zeitInMinuten($jetzt);
    $tag         = $jetztM >= zeitInMinuten($aufgang_ts) && $jetztM <= zeitInMinuten($untergang_ts);
    $tag_erlaubt = $tagNachtAktiv ? $tag : true;

    // ---------- Messwert-Verlauf (Lux-Mittel, Böen-Trend) ----------
    $fenster = max(LUX_MITTEL_SEK, BOE_TREND_FENSTER_SEK);
    $verlauf = array_values(array_filter(
        jsonLesen($V_VERLAUF),
        fn($p) => is_array($p) && (int)$p[0] >= $jetzt - $fenster
    ));
    $verlauf[] = [$jetzt, $helligkeit, $boe];
    $verlauf   = array_slice($verlauf, -200);
    SetValueString($V_VERLAUF, json_encode($verlauf));

    $luxWerte = array_column(array_filter($verlauf, fn($p) => $p[0] >= $jetzt - LUX_MITTEL_SEK), 1);
    $luxMittel = array_sum($luxWerte) / count($luxWerte);

    $boeWerte = array_column(array_filter($verlauf, fn($p) => $p[0] >= $jetzt - BOE_TREND_FENSTER_SEK), 2);
    $boeMin   = min($boeWerte);
    $boeTrend = ($boe - $boeMin) >= BOE_TREND_KMH && $boe >= $BOE_ALARM * BOE_TREND_MIN_ANTEIL;

    // ---------- Sensorüberwachung ----------
    $hellAlter          = alterSek(ID_HELLIGKEIT, 'VariableChanged');
    $helligkeitSensorOk = $hellAlter <= HELLIGKEIT_FREEZE_SEK;
    $helligkeitSensorStatusText = $helligkeitSensorOk
        ? 'OK, Änderung vor ' . intdiv($hellAlter, 60) . ' Min.'
        : 'Eingefroren, seit ' . intdiv($hellAlter, 60) . ' Min. unverändert';

    $windAlter    = max(alterSek(ID_WIND, 'VariableUpdated'), alterSek(ID_BOE, 'VariableUpdated'));
    $windSensorOk = $windAlter <= WINDSENSOR_TIMEOUT_SEK;
    $windSensorStatusText = $windSensorOk
        ? 'OK, Aktualisierung vor ' . intdiv($windAlter, 60) . ' Min.'
        : 'Keine Daten seit ' . intdiv($windAlter, 60) . ' Min.';

    // Sensorausfall einmalig melden
    if (!$windSensorOk && !GetValueBoolean($V_SENSOR_GEMELDET)) {
        push('Markise: Windsensor ausgefallen', $windSensorStatusText);
        SetValueBoolean($V_SENSOR_GEMELDET, true);
    } elseif ($windSensorOk && GetValueBoolean($V_SENSOR_GEMELDET)) {
        SetValueBoolean($V_SENSOR_GEMELDET, false);
    }

    // ---------- DWD-Unwetterwarnung ----------
    // Bewusst kein wert(): fehlt die Variable, soll das nicht zum Fail-safe-Einfahren führen
    $dwdStufe = null;
    if ($dwdId > 0) {
        $dwdStufe = (int)GetValue($dwdId);
    }
    // ab 10 = Gesundheitswarnungen (Hitze/UV) → für die Markise kein Grund zum Einfahren
    $dwdWarnung = $dwdStufe !== null && $dwdStufe >= DWD_MIN_STUFE && $dwdStufe < 10;
    $dwdText = $dwdStufe === null
        ? (ID_DWD_WARNSTUFE < 0 ? 'Aus' : 'Warnstufe nicht gefunden')
        : 'Stufe ' . $dwdStufe . " (#$dwdId)";

    // ---------- Sperrzeiten ----------
    $windAlarm = $wind >= WIND_ALARM_BFT || $boe >= $BOE_ALARM || $boeTrend;
    if ($windAlarm) {
        SetValueInteger($V_WIND_SPERRE, $jetzt + WIND_SPERRE_SEK);
    }
    if ($regen) {
        SetValueInteger($V_REGEN_SPERRE, $jetzt + REGEN_SPERRE_SEK);
    }
    $windSperreRest  = max(0, GetValueInteger($V_WIND_SPERRE) - $jetzt);
    $regenSperreRest = max(0, GetValueInteger($V_REGEN_SPERRE) - $jetzt);

    // ---------- Karenz-Taster ----------
    if ((bool)wert(ID_KARENZ_NEU)) {
        SetValueInteger($V_ABWESEND, 0);                        // Karenz startet neu (100 %)
        SetValueBoolean(ID_KARENZ_NEU, false);
    }
    if ((bool)wert(ID_KARENZ_ENDE)) {
        SetValueInteger($V_ABWESEND, $jetzt - KARENZ_SEK - 1);  // Karenz sofort beenden (0 %)
        SetValueBoolean(ID_KARENZ_ENDE, false);
    }

    // ---------- Abwesenheit / Karenz ----------
    $karenzRest = 0;
    if ($anwesend) {
        if (GetValueInteger($V_ABWESEND) !== 0) {
            SetValueInteger($V_ABWESEND, 0);
        }
        $abwesenheitStatusText = 'Anwesend';
    } else {
        $abwesendSeit = GetValueInteger($V_ABWESEND);
        if ($abwesendSeit <= 0) {
            $abwesendSeit = $jetzt;
            SetValueInteger($V_ABWESEND, $abwesendSeit);
        }
        $karenzRest = max(0, KARENZ_SEK - ($jetzt - $abwesendSeit));
        $abwesenheitStatusText = $karenzRest > 0
            ? 'Abwesend, Karenz aktiv (' . minuten($karenzRest) . ' Min. Rest)'
            : 'Abwesend, Karenz abgelaufen';
    }
    $abwesenheitProgressProzent = (int)round($karenzRest / KARENZ_SEK * 100);

    // ---------- Halten-Modus (Abendmodus) ----------
    // Automatisch aus: Terrassentür geschlossen, Karenz abgelaufen oder nächster Sonnenaufgang
    $tuerGeschlossen = (int)wert(ID_TERRASSENTUER) === TUER_WERT_GESCHLOSSEN;
    $tuerText        = $tuerGeschlossen ? 'Geschlossen' : 'Offen';

    $halten = GetValueBoolean($V_HALTEN);
    $haltenText = 'Aus';
    if ($halten) {
        $aufgangHeute = strtotime('today') + zeitInMinuten($aufgang_ts) * 60;
        $haltenSeit   = (int)IPS_GetVariable($V_HALTEN)['VariableChanged'];
        $tuerSeit     = (int)IPS_GetVariable(ID_TERRASSENTUER)['VariableChanged'];

        // Nur ein Schließen NACH dem Einschalten zählt
        if ($tuerGeschlossen && $tuerSeit > $haltenSeit) {
            $halten = false;
            $haltenText = 'Zurückgesetzt (Terrassentür geschlossen)';
        } elseif (!$anwesend && $karenzRest <= 0) {
            $halten = false;
            $haltenText = 'Zurückgesetzt (abwesend)';
        } elseif ($haltenSeit < $aufgangHeute && $jetzt >= $aufgangHeute) {
            $halten = false;
            $haltenText = 'Zurückgesetzt (Sonnenaufgang)';
        } else {
            $haltenText = 'Aktiv';
        }
        if (!$halten) {
            SetValueBoolean($V_HALTEN, false);
        }
    }

    // ---------- Komfortbedingungen: Hysterese + Verzögerung ----------
    $luxEin = $LUX * (1 + LUX_HYST_PROZENT / 100);
    $luxAus = $LUX * (1 - LUX_HYST_PROZENT / 100);

    // Sonnenstand: scheint die Sonne überhaupt auf die Terrasse?
    [$sonneAzimut, $sonneHoehe] = sonnenstand($jetzt, STANDORT_BREITE, STANDORT_LAENGE);
    $sonneAufTerrasse = !SONNENSTAND_PRUEFEN || (
        $sonneHoehe >= SONNE_MIN_HOEHE
        && $sonneAzimut >= SONNE_AZIMUT_VON
        && $sonneAzimut <= SONNE_AZIMUT_BIS
    );

    $ausfahrenOk     = $sonneAufTerrasse && $luxMittel > $luxEin && $temp > $TEMP + TEMP_HYST_K && $wind <= $WIND_MAX;
    $einfahrenNoetig = !$sonneAufTerrasse || $luxMittel <= $luxAus || $temp <= $TEMP - TEMP_HYST_K;

    $gruende = [];
    if (!$sonneAufTerrasse)             { $gruende[] = 'Sonne nicht auf der Terrasse'; }
    if ($luxMittel <= $luxAus)          { $gruende[] = 'zu dunkel'; }
    if ($temp <= $TEMP - TEMP_HYST_K)   { $gruende[] = 'zu kalt'; }
    $einfahrGrund = $gruende ? implode(', ', $gruende) : '';
    $windUeberMax    = $wind > $WIND_MAX;

    // Seit wann ist die Bedingung durchgehend erfüllt?
    $ausOkSeit = GetValueInteger($V_AUS_OK_SEIT);
    if ($ausfahrenOk && $ausOkSeit === 0) {
        $ausOkSeit = $jetzt;
        SetValueInteger($V_AUS_OK_SEIT, $ausOkSeit);
    } elseif (!$ausfahrenOk && $ausOkSeit !== 0) {
        $ausOkSeit = 0;
        SetValueInteger($V_AUS_OK_SEIT, 0);
    }
    $einSeit = GetValueInteger($V_EIN_SEIT);
    if ($einfahrenNoetig && $einSeit === 0) {
        $einSeit = $jetzt;
        SetValueInteger($V_EIN_SEIT, $einSeit);
    } elseif (!$einfahrenNoetig && $einSeit !== 0) {
        $einSeit = 0;
        SetValueInteger($V_EIN_SEIT, 0);
    }

    $ausfahrenRest   = $ausfahrenOk ? max(0, AUSFAHR_VERZOEGERUNG_SEK - ($jetzt - $ausOkSeit)) : 0;
    $einfahrenRest   = $einfahrenNoetig ? max(0, EINFAHR_VERZOEGERUNG_SEK - ($jetzt - $einSeit)) : 0;
    $ausfahrenStabil = $ausfahrenOk && $ausfahrenRest === 0;
    $einfahrenStabil = $einfahrenNoetig && $einfahrenRest === 0;

    // ---------- Entscheidung ----------
    $zustand = GetValueInteger($V_ZUSTAND);

    if (!$automatik) {
        SetValueInteger($V_ZUSTAND, -1); // Handbetrieb → Zustand unbekannt
        $meldung = 'Automatik aus → keine automatische Aktion';

    } elseif ($windAlarm) {
        $grund = $boeTrend && $wind < WIND_ALARM_BFT && $boe < $BOE_ALARM
            ? sprintf('Böen steigen schnell (%.0f → %.0f km/h)', $boeMin, $boe)
            : sprintf('Windalarm (%d Bft, Böe %.0f km/h)', $wind, $boe);
        $meldung = schalten(0, 'SICHERHEIT', $grund);

    } elseif ($dwdWarnung) {
        $meldung = schalten(0, 'SICHERHEIT', "DWD-Unwetterwarnung ($dwdText)");

    } elseif ($regen) {
        $meldung = schalten(0, 'SICHERHEIT', 'Regen');

    } elseif (!$windSensorOk) {
        $meldung = schalten(0, 'SICHERHEIT', "Windsensor: $windSensorStatusText");

    } elseif ($windSperreRest > 0) {
        $meldung = schalten(0, 'SICHERHEIT', 'Wind-Sperre, noch ' . minuten($windSperreRest) . ' Min.');

    } elseif ($regenSperreRest > 0) {
        $meldung = schalten(0, 'SICHERHEIT', 'Regen-Sperre (Trocknung), noch ' . minuten($regenSperreRest) . ' Min.');

    } elseif ($halten) {
        $meldung = 'Halten-Modus aktiv → Helligkeit, Temperatur und Tageszeit werden ignoriert';

    } elseif (!$tag_aktiv) {
        $meldung = schalten(0, 'SPERRE', "$wochentagName nicht freigegeben");

    } elseif (!$tag_erlaubt) {
        $meldung = schalten(0, 'SPERRE', 'Nacht');

    } elseif (!$helligkeitSensorOk) {
        $meldung = schalten(0, 'SPERRE', "Helligkeitssensor: $helligkeitSensorStatusText");

    } elseif (!$anwesend && $karenzRest <= 0) {
        $meldung = schalten(0, 'SPERRE', 'Abwesend, Karenz abgelaufen');

    } elseif ($windUeberMax) {
        $meldung = schalten(0, 'SPERRE', "Wind über Maximum ($wind > $WIND_MAX Bft)");

    } elseif (!$anwesend) {
        $meldung = $einfahrenStabil
            ? schalten(0, 'KOMFORT', 'Abwesend (Karenz), ' . $einfahrGrund)
            : 'Abwesend, Karenz aktiv → Markise bleibt unverändert';

    } elseif ($ausfahrenStabil) {
        $meldung = schalten(1, 'KOMFORT', 'Bedingungen stabil erfüllt');

    } elseif ($einfahrenStabil) {
        $meldung = schalten(0, 'KOMFORT', 'Durchgehend ' . $einfahrGrund);

    } elseif ($ausfahrenOk && $zustand !== 1) {
        $meldung = 'Bedingungen erfüllt → Ausfahren in ' . minuten($ausfahrenRest) . ' Min., falls es so bleibt';

    } elseif ($einfahrenNoetig && $zustand !== 0) {
        $meldung = ucfirst($einfahrGrund) . ' → Einfahren in ' . minuten($einfahrenRest) . ' Min., falls es so bleibt';

    } else {
        $meldung = 'Keine Änderung nötig';
    }

    // ---------- Debug ----------
    $zustandTexte = [-1 => 'Unbekannt', 0 => 'Eingefahren', 1 => 'Ausgefahren'];

    $state = [
        'automatik'                  => $automatik,
        'wochentag'                  => $wochentag,
        'wochentagName'              => $wochentagName,
        'aktiveWochentageText'       => $aktiveWochentageText,
        'tag_aktiv'                  => $tag_aktiv,
        'helligkeit'                 => $helligkeit,
        'LUX'                        => $LUX,
        'temp'                       => $temp,
        'TEMP'                       => $TEMP,
        'wind'                       => $wind,
        'boe'                        => $boe,
        'WIND_MAX'                   => $WIND_MAX,
        'WIND_ALARM'                 => WIND_ALARM_BFT,
        'BOE_ALARM_KMH'              => $BOE_ALARM,
        'regen'                      => $regen,
        'tag'                        => $tag,
        'TAG_NACHT_PRUEFUNG_AKTIV'   => $tagNachtAktiv,
        'tag_erlaubt'                => $tag_erlaubt,
        'aufgang_ts'                 => $aufgang_ts,
        'untergang_ts'               => $untergang_ts,
        'anwesend'                   => $anwesend,
        // neu
        'helligkeitSensorStatusText' => $helligkeitSensorStatusText,
        'windSensorStatusText'       => $windSensorStatusText,
        'abwesenheitStatusText'      => $abwesenheitStatusText,
        'abwesenheitProgressProzent' => $abwesenheitProgressProzent,
        'windSperreMin'              => minuten($windSperreRest),
        'regenSperreMin'             => minuten($regenSperreRest),
        'luxMittel'                  => round($luxMittel),
        'luxEin'                     => $luxEin,
        'luxAus'                     => $luxAus,
        'boeTrend'                   => $boeTrend,
        'dwdWarnstufe'               => $dwdText,
        'sonneAzimut'                => round($sonneAzimut),
        'sonneHoehe'                 => round($sonneHoehe, 1),
        'sonneAufTerrasse'           => $sonneAufTerrasse,
        'ausfahrenInMin'             => $ausfahrenOk ? minuten($ausfahrenRest) : null,
        'einfahrenInMin'             => $einfahrenNoetig ? minuten($einfahrenRest) : null,
        'schaltungenLetzteStunde'    => count(schaltLog()),
        'halten'                     => $halten,
        'haltenText'                 => $haltenText,
        'terrassentuer'              => $tuerText,
        'zustand'                    => $zustandTexte[GetValueInteger($V_ZUSTAND)] ?? 'Unbekannt',
    ];

    debugMarkise($meldung, $state);

} catch (Throwable $e) {
    // Fail-safe: bei jedem Fehler einfahren
    IPS_LogMessage('Markisenskript', 'Fehler: ' . $e->getMessage() . ' → Markise wird eingefahren');
    if (IPS_VariableExists(ID_EINFAHREN)) {
        @RequestAction(ID_EINFAHREN, true);
    }
    push('Markise: Skriptfehler', $e->getMessage());
}
