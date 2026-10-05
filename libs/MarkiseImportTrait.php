<?php

declare(strict_types=1);

/**
 * Markisensteuerung – IP-Symcon-Modul für die automatische Markisensteuerung
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */

/**
 * Übernahme der Einstellungen aus dem bisherigen Markisenskript.
 *
 * Das Skript wird nur gelesen und mit regulären Ausdrücken ausgewertet – nie ausgeführt.
 * Die gefundenen Werte landen im Formular; gespeichert wird erst mit „Änderungen übernehmen“.
 * Verstanden werden beide Fassungen: das ursprüngliche Skript ($helligkeit = GetValueFloat(…))
 * und die überarbeitete Fassung mit Konstanten (const ID_HELLIGKEIT = …).
 */
trait MarkiseImportTrait
{
    /** Formularfeld => mögliche Namen im Skript (Konstante oder Variable) */
    private const IMPORT_IDS = [
        'BrightnessVariableID'  => ['ID_HELLIGKEIT', 'helligkeit'],
        'TemperatureVariableID' => ['ID_TEMP', 'temp'],
        'WindVariableID'        => ['ID_WIND', 'wind'],
        'GustVariableID'        => ['ID_BOE', 'boe'],
        'RainVariableID'        => ['ID_REGEN', 'regen'],
        'PresenceVariableID'    => ['ID_ANWESEND', 'anwesend'],
        'DoorVariableID'        => ['ID_TERRASSENTUER'],
        'ExtendVariableID'      => ['ID_AUSFAHREN'],
        'RetractVariableID'     => ['ID_EINFAHREN'],
    ];

    private const IMPORT_WEEKDAYS = ['Montag' => 1, 'Dienstag' => 2, 'Mittwoch' => 3, 'Donnerstag' => 4, 'Freitag' => 5, 'Samstag' => 6, 'Sonntag' => 7];

    /** Größe, ab der ein Skript nicht mehr gelesen wird (Schutz vor versehentlich gewählten Riesendateien) */
    private const IMPORT_MAX_BYTES = 262144;

    /**
     * Liest das Skript, füllt die Formularfelder und liefert eine Zusammenfassung.
     */
    private function ImportFromScript(int $scriptID): string
    {
        if ($scriptID <= 0 || !IPS_ScriptExists($scriptID)) {
            return $this->Translate('Please select the previous awning script first.');
        }
        $code = (string) IPS_GetScriptContent($scriptID);
        if ($code === '' || strlen($code) > self::IMPORT_MAX_BYTES) {
            return $this->Translate('The script is empty or too large.');
        }

        $p = self::ParseScript($code);
        $fields = [];
        $notes = [];

        // ---------- Variablen-IDs ----------
        foreach (self::IMPORT_IDS as $field => $names) {
            $id = self::FirstOf($p['ids'], $names);
            if ($id !== null) {
                $fields[$field] = $id;
            }
        }
        // Ursprüngliches Skript: Einfahren ist die Aktion, die am häufigsten vorkommt, Ausfahren die andere
        if (!isset($fields['ExtendVariableID'], $fields['RetractVariableID']) && count($p['actions']) >= 2) {
            arsort($p['actions']);
            $ids = array_keys($p['actions']);
            $fields['RetractVariableID'] ??= $ids[0];
            $fields['ExtendVariableID'] ??= $ids[1];
        }
        foreach ($fields as $field => $id) {
            if (!IPS_VariableExists($id)) {
                $notes[] = sprintf($this->Translate('Variable #%d (%s) does not exist and was skipped.'), $id, $field);
                unset($fields[$field]);
            } elseif (in_array($field, ['ExtendVariableID', 'RetractVariableID'], true) && !HasAction($id)) {
                $notes[] = sprintf($this->Translate('Variable #%d has no action – please check the actuator.'), $id);
            }
        }
        if (isset($fields['ExtendVariableID']) || isset($fields['RetractVariableID'])) {
            $fields['ActuatorMode'] = 0;
        }
        if (isset($fields['WindVariableID'])) {
            $fields['WindUnit'] = 0; // Windstärke in Beaufort
        }
        if (isset($fields['GustVariableID'])) {
            $fields['GustUnit'] = 1; // Böen in km/h
        }

        // ---------- Werte aus den Vorgabe-Variablen des Skripts ----------
        $value = function (array $names) use ($p): mixed {
            $id = self::FirstOf($p['ids'], $names);
            return ($id !== null && IPS_VariableExists($id)) ? GetValue($id) : null;
        };
        $const = static fn (string $name): ?float => $p['consts'][$name] ?? null;

        $lux = $value(['ID_LUX_VORGABE', 'LUX']);
        if ($lux !== null) {
            $hyst = ($const('LUX_HYST_PROZENT') ?? 0.0) / 100;
            $fields['LuxOn'] = (int) round((float) $lux * (1 + $hyst));
            $fields['LuxOff'] = (int) round((float) $lux * (1 - $hyst));
            if ((float) $lux <= 0) {
                $notes[] = $this->Translate('The brightness limit in the script is 0 lx – the brightness then decides nothing. Please set a sensible value, e.g. 20000 lx.');
            }
        }
        $temp = $value(['ID_TEMP_VORGABE', 'TEMP']);
        if ($temp !== null) {
            $tHyst = $const('TEMP_HYST_K') ?? 0.0;
            $fields['TempMin'] = round((float) $temp + $tHyst, 1);
            $fields['TempHysteresis'] = round(2 * $tHyst, 1);
        }
        $windMax = $value(['ID_WIND_MAX', 'WIND_MAX']);
        if ($windMax !== null) {
            $fields['WindMax'] = (float) $windMax;
        }
        $gustAlarm = $value(['ID_BOE_ALARM', 'BOE_ALARM_ID']);
        if ($gustAlarm !== null) {
            $fields['GustAlarm'] = (float) $gustAlarm > 0 ? (float) $gustAlarm : ($const('BOE_ALARM_FALLBACK') ?? 28.0);
        }
        $windAlarm = $const('WIND_ALARM_BFT') ?? $p['numbers']['WIND_ALARM'] ?? null;
        if ($windAlarm !== null) {
            $fields['WindAlarm'] = (float) $windAlarm;
        }
        $dayCheck = $value(['ID_TAG_NACHT', 'TAG_NACHT_PRUEFUNG_ID']);
        if ($dayCheck !== null) {
            $fields['DayCheck'] = (bool) $dayCheck;
        }
        foreach ($p['weekdays'] as $day => $id) {
            if (IPS_VariableExists($id)) {
                $fields['Weekday' . $day] = (bool) GetValue($id);
            }
        }

        // ---------- Zeiten und Optionen (in Sekunden im Skript) ----------
        $minutes = [
            'GraceMinutes'      => $const('KARENZ_SEK') ?? $p['numbers']['ABWESEND_KARENZ_SEK'] ?? null,
            'WindLockMinutes'   => $const('WIND_SPERRE_SEK'),
            'RainLockMinutes'   => $const('REGEN_SPERRE_SEK'),
            'DelayOn'           => $const('AUSFAHR_VERZOEGERUNG_SEK'),
            'DelayOff'          => $const('EINFAHR_VERZOEGERUNG_SEK'),
            'FreezeMinutes'     => $const('HELLIGKEIT_FREEZE_SEK') ?? $p['numbers']['HELLIGKEIT_FREEZE_LIMIT_SEK'] ?? null,
            'WindTimeoutMinutes' => $const('WINDSENSOR_TIMEOUT_SEK'),
            'LuxAverageMinutes' => $const('LUX_MITTEL_SEK'),
            'GustTrendMinutes'  => $const('BOE_TREND_FENSTER_SEK'),
        ];
        foreach ($minutes as $field => $sec) {
            if ($sec !== null) {
                $fields[$field] = (int) round($sec / 60);
            }
        }
        foreach (['MaxMovesPerHour' => 'MAX_SCHALTUNGEN_PRO_STUNDE', 'DoorClosedValue' => 'TUER_WERT_GESCHLOSSEN', 'WarningMinLevel' => 'DWD_MIN_STUFE'] as $field => $name) {
            if ($const($name) !== null) {
                $fields[$field] = (int) $const($name);
            }
        }
        if ($const('BOE_TREND_KMH') !== null) {
            $fields['GustTrend'] = true;
            $fields['GustTrendRise'] = (float) $const('BOE_TREND_KMH');
        }
        if ($const('BOE_TREND_MIN_ANTEIL') !== null) {
            $fields['GustTrendShare'] = (int) round($const('BOE_TREND_MIN_ANTEIL') * 100);
        }
        if ($p['numbers']['HELLIGKEIT_MIN_AENDERUNG'] ?? null) {
            $fields['FreezeMinChange'] = (float) $p['numbers']['HELLIGKEIT_MIN_AENDERUNG'];
        }

        // ---------- Sonnenstand und Standort ----------
        if (array_key_exists('SONNENSTAND_PRUEFEN', $p['bools'])) {
            $fields['UseSunPosition'] = $p['bools']['SONNENSTAND_PRUEFEN'];
        }
        foreach (['AzimuthFrom' => 'SONNE_AZIMUT_VON', 'AzimuthTo' => 'SONNE_AZIMUT_BIS', 'SunElevationMin' => 'SONNE_MIN_HOEHE', 'Latitude' => 'STANDORT_BREITE', 'Longitude' => 'STANDORT_LAENGE'] as $field => $name) {
            if ($const($name) !== null) {
                $fields[$field] = (float) $const($name);
            }
        }

        // ---------- DWD ----------
        $dwd = $const('ID_DWD_WARNSTUFE');
        if ($dwd !== null) {
            $fields['UseWarning'] = $dwd >= 0;
            $fields['WarningVariableID'] = $dwd > 0 && IPS_VariableExists((int) $dwd) ? (int) $dwd : 0;
        }

        if (count($fields) === 0) {
            return $this->Translate('Nothing recognized in this script. Is it the awning script?');
        }

        foreach ($fields as $field => $v) {
            $this->UpdateFormField($field, 'value', $v);
        }
        foreach ($this->ModeVisibility((int) ($fields['ActuatorMode'] ?? $this->ReadPropertyInteger('ActuatorMode'))) as $field => $visible) {
            $this->UpdateFormField($field, 'visible', $visible);
        }

        $text = sprintf($this->Translate('%d settings taken over from the script.'), count($fields));
        $text .= "\n\n" . $this->Translate('Please check the values and save with "Apply changes". Then deactivate the events of the old script so that only one controls the awning.');
        if ($notes !== []) {
            $text .= "\n\n" . implode("\n", $notes);
        }
        return $text;
    }

    /**
     * Zerlegt das Skript in Konstanten, Variablenzuweisungen, Aktionen und Wochentage.
     *
     * @return array{consts: array<string, float>, bools: array<string, bool>, numbers: array<string, float>, ids: array<string, int>, actions: array<int, int>, weekdays: array<int, int>}
     */
    private static function ParseScript(string $code): array
    {
        // Kommentare entfernen, damit auskommentierte Zeilen nicht zählen
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<![:"\'])//[^\n]*~', '~^\s*#[^\n]*~m'], '', $code);

        $num = '(-?\d+(?:\.\d+)?)(?:\s*\*\s*(\d+(?:\.\d+)?))?';
        $result = ['consts' => [], 'bools' => [], 'numbers' => [], 'ids' => [], 'actions' => [], 'weekdays' => []];

        preg_match_all('~\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*' . $num . '\s*;~', $code, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $result['consts'][$x[1]] = (float) $x[2] * (isset($x[3]) && $x[3] !== '' ? (float) $x[3] : 1.0);
        }
        preg_match_all('~\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*(true|false)\s*;~i', $code, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $result['bools'][$x[1]] = strtolower($x[2]) === 'true';
        }
        // $NAME = 123; bzw. $NAME = 30 * 60;
        preg_match_all('~\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*' . $num . '\s*;~', $code, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $result['numbers'][$x[1]] = (float) $x[2] * (isset($x[3]) && $x[3] !== '' ? (float) $x[3] : 1.0);
        }
        // $name = GetValueFloat(12345);
        preg_match_all('~\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*GetValue(?:Boolean|Integer|Float|String)?\s*\(\s*(\d+)\s*\)~', $code, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $result['ids'][$x[1]] ??= (int) $x[2];
        }
        // Konstanten mit ID_ und $…_ID-Variablen sind Objekt-IDs
        foreach ($result['consts'] as $name => $v) {
            if (str_starts_with($name, 'ID_') && $v > 0) {
                $result['ids'][$name] = (int) $v;
            }
        }
        foreach ($result['numbers'] as $name => $v) {
            if (str_ends_with($name, '_ID') && $v > 0) {
                $result['ids'][substr($name, 0, -3)] ??= (int) $v;
                $result['ids'][$name] = (int) $v;
            }
        }
        // RequestAction(12345, true) – im ursprünglichen Skript die Fahrbefehle
        preg_match_all('~RequestAction\s*\(\s*(\d+)\s*,~', $code, $m);
        foreach ($m[1] as $id) {
            $result['actions'][(int) $id] = ($result['actions'][(int) $id] ?? 0) + 1;
        }
        // Wochentage: ['Montag', 24981] oder ["name" => "Montag", "id" => 24981]
        preg_match_all('~["\'](Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag)["\']\s*,\s*(?:["\']id["\']\s*=>\s*)?(\d+)~', $code, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $result['weekdays'][self::IMPORT_WEEKDAYS[$x[1]]] = (int) $x[2];
        }
        return $result;
    }

    private static function FirstOf(array $map, array $names): ?int
    {
        foreach ($names as $name) {
            if (isset($map[$name]) && $map[$name] > 0) {
                return (int) $map[$name];
            }
        }
        return null;
    }
}
