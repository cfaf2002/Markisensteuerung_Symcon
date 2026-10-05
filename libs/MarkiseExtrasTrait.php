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
 * Halten (Abendmodus), Terrassentür, DWD-Unwetterwarnung, Böen-Trend, Lux-Mittelwert und Schaltlimit.
 * Alle Verläufe sind kurz (höchstens ein Eintrag pro Minute) und begrenzt – kein Wachstum im Dauerbetrieb.
 */
trait MarkiseExtrasTrait
{
    /** Modul „Unwetterwarnung“ (Wilkware) – Variable „Warnstufe“ hat dort den Ident „Level“ */
    private static string $warningModuleGuid = '{DCDBF64A-F2DC-B23B-2483-69BCAFFE091A}';

    /** Obergrenze für die Verläufe (Einträge), unabhängig von den Einstellungen */
    private const HISTORY_MAX = 120;

    // =====================================================================
    // Halten (Abendmodus) und Terrassentür
    // =====================================================================

    /**
     * Ist Halten aktiv? Setzt Halten automatisch zurück, wenn
     * – die Terrassentür geschlossen wird (Wechsel von offen auf zu),
     * – die Abwesenheits-Karenz abgelaufen ist,
     * – ein neuer Tag begonnen hat und es hell ist (spätestens am Morgen).
     */
    protected function HoldActive(bool $present, int $graceLeft, bool $isDay, int $sky): bool
    {
        $door = $this->DoorClosed();
        if ($door !== null && $door !== $this->ReadAttributeBoolean('DoorWasClosed')) {
            $this->WriteAttributeBoolean('DoorWasClosed', $door);
            $doorJustClosed = $door;
        } else {
            $doorJustClosed = false;
        }

        if (!$this->ReadPropertyBoolean('HoldEnabled') || @$this->GetIDForIdent('Hold') === false || !$this->GetValue('Hold')) {
            return false;
        }

        $reset = '';
        $since = $this->ReadAttributeInteger('HoldSince');
        if ($doorJustClosed) {
            $reset = $this->Translate('Hold ended: terrace door closed');
        } elseif (!$present && $this->ReadPropertyInteger('PresenceVariableID') > 0 && $graceLeft <= 0) {
            $reset = $this->Translate('Hold ended: absent');
        } elseif ($since > 0 && $isDay && date('Ymd', $sky) !== date('Ymd', $since)) {
            $reset = $this->Translate('Hold ended: new day');
        }
        if ($reset === '') {
            return true;
        }

        $this->SetValue('Hold', false);
        $this->WriteAttributeInteger('HoldSince', 0);
        $this->SendDebug('Halten', $reset, 0);
        if ($this->Simulating()) {
            $this->SimLog($reset);
        }
        return false;
    }

    /**
     * Terrassentür geschlossen? null = keine Tür eingestellt.
     */
    protected function DoorClosed(): ?bool
    {
        if ($this->Simulating() && ($open = $this->SimSensor('DoorVariableID')) !== null) {
            return $open <= 0;
        }
        $raw = $this->ReadSensor('DoorVariableID', true);
        if ($raw === null) {
            return null;
        }
        return (int) round($raw) === $this->ReadPropertyInteger('DoorClosedValue');
    }

    // =====================================================================
    // Urlaub
    // =====================================================================

    /**
     * Urlaubsschalter des Hauses aktiv? (an = Urlaub, auf Wunsch invertiert; in der Simulation vorgebbar)
     */
    protected function VacationActive(): bool
    {
        if ($this->Simulating() && ($sim = $this->SimSensor('VacationVariableID')) !== null) {
            return $sim > 0;
        }
        $raw = $this->ReadSensor('VacationVariableID', true);
        if ($raw === null) {
            return false;
        }
        return ($raw > 0) !== $this->ReadPropertyBoolean('VacationInvert');
    }

    // =====================================================================
    // DWD-Unwetterwarnung
    // =====================================================================

    /** ID der Warnstufe: eingestellt oder automatisch gefunden; 0 = keine */
    protected function WarningID(): int
    {
        if (!$this->ReadPropertyBoolean('UseWarning')) {
            return 0;
        }
        $id = $this->ReadPropertyInteger('WarningVariableID');
        return $id > 0 ? $id : $this->ReadAttributeInteger('WarningVar');
    }

    /**
     * Sucht die Warnstufe des Moduls „Unwetterwarnung“ (wird nur in ApplyChanges und per Taste aufgerufen).
     */
    private function FindWarningVariable(bool $force = false): int
    {
        if (!$force) {
            if (!$this->ReadPropertyBoolean('UseWarning')) {
                return 0;
            }
            $id = $this->ReadPropertyInteger('WarningVariableID');
            if ($id > 0) {
                return $id;
            }
        }
        foreach (IPS_GetInstanceListByModuleID(self::$warningModuleGuid) as $instance) {
            $id = @IPS_GetObjectIDByIdent('Level', $instance);
            if (is_int($id) && $id > 0 && IPS_VariableExists($id)) {
                return $id;
            }
        }
        return 0;
    }

    /** Aktuelle Warnstufe; null = keine Warnung eingerichtet oder Variable fehlt (blockiert nichts) */
    protected function WarningLevel(): ?int
    {
        if (!$this->ReadPropertyBoolean('UseWarning')) {
            return null;
        }
        $v = $this->ReadSensor('WarningVariableID');
        return $v === null ? null : (int) round($v);
    }

    // =====================================================================
    // Verläufe: Böen-Trend und Lux-Mittelwert
    // =====================================================================

    /**
     * Böen steigen schnell an: liefert den Ausgangswert (Minimum im Zeitfenster), sonst null.
     */
    protected function GustTrend(?float $gust, int $now): ?float
    {
        if ($gust === null || !$this->ReadPropertyBoolean('GustTrend')) {
            return null;
        }
        $window = max(1, $this->ReadPropertyInteger('GustTrendMinutes')) * 60;
        $history = $this->History('GustHistory', $gust, $now, $window);
        $previous = array_slice($history, 0, -1);
        if ($previous === []) {
            return null;
        }
        $min = min(array_column($previous, 1));
        $alarm = $this->ReadPropertyFloat('GustAlarm');
        $share = max(0, min(100, $this->ReadPropertyInteger('GustTrendShare'))) / 100;
        $rise = max(0.1, $this->ReadPropertyFloat('GustTrendRise'));
        if ($gust - $min >= $rise && $gust >= $alarm * $share && $gust < $alarm) {
            return (float) $min;
        }
        return null;
    }

    /**
     * Gleitender Mittelwert der Helligkeit; ohne Mittelung der aktuelle Wert.
     */
    protected function LuxAverage(?float $lux, int $now): ?float
    {
        $minutes = $this->ReadPropertyInteger('LuxAverageMinutes');
        if ($lux === null || $minutes <= 0 || ($this->Simulating() && $this->ReadPropertyBoolean('SimSkipDelays'))) {
            return $lux;
        }
        $history = $this->History('LuxHistory', $lux, $now, $minutes * 60);
        $values = array_column($history, 1);
        return array_sum($values) / count($values);
    }

    /**
     * Pflegt einen Verlauf: ein Eintrag pro Minute (der neueste Wert der Minute zählt),
     * ältere Einträge als das Zeitfenster fallen weg.
     *
     * @return array<int, array{0: int, 1: float}>
     */
    private function History(string $attribute, float $value, int $now, int $window): array
    {
        $history = json_decode($this->ReadAttributeString($attribute), true);
        $history = is_array($history) ? $history : [];
        $minute = intdiv($now, 60) * 60;
        $last = end($history);
        if (is_array($last) && (int) $last[0] === $minute) {
            array_pop($history);
        }
        $history[] = [$minute, round($value, 2)];
        $from = $minute - $window;
        $history = array_values(array_filter($history, static fn ($e): bool => is_array($e) && (int) $e[0] > $from));
        $history = array_slice($history, -self::HISTORY_MAX);
        $json = json_encode($history);
        if ($json !== $this->ReadAttributeString($attribute)) {
            $this->WriteAttributeString($attribute, $json);
        }
        return $history;
    }

    // =====================================================================
    // Schaltlimit
    // =====================================================================

    /** Höchstzahl automatischer Sonnen-Fahrten pro Stunde erreicht? */
    protected function MoveLimitReached(int $now): bool
    {
        $max = $this->ReadPropertyInteger('MaxMovesPerHour');
        if ($max <= 0 || ($this->Simulating() && $this->ReadPropertyBoolean('SimSkipDelays'))) {
            return false;
        }
        return count($this->RecentMoves($now)) >= $max;
    }

    private function RecordMove(int $now): void
    {
        if ($this->ReadPropertyInteger('MaxMovesPerHour') <= 0) {
            return;
        }
        $moves = $this->RecentMoves($now);
        $moves[] = $now;
        $this->WriteAttributeString('MoveLog', json_encode(array_slice($moves, -self::HISTORY_MAX)));
    }

    /** @return int[] Zeitpunkte der Sonnen-Fahrten der letzten Stunde */
    private function RecentMoves(int $now): array
    {
        $moves = json_decode($this->ReadAttributeString('MoveLog'), true);
        return array_values(array_filter(is_array($moves) ? $moves : [], static fn ($t): bool => is_int($t) && $t > $now - 3600));
    }
}
