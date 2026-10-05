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
 * Simulation (Testbetrieb): Das Modul entscheidet wie gewohnt, bewegt die Markise aber nicht.
 * Sensorwerte und Uhrzeit lassen sich über eigene Variablen vorgeben.
 * Der echte Wind- und Regenschutz arbeitet auf Wunsch weiter, mit den echten Sensoren.
 */
trait MarkiseSimulationTrait
{
    /** Simulationsvariable je Sensor-Eigenschaft */
    private const SIM_SENSORS = [
        'BrightnessVariableID'  => 'SimLux',
        'TemperatureVariableID' => 'SimTemp',
        'WindVariableID'        => 'SimWind',
        'GustVariableID'        => 'SimGust',
        'RainVariableID'        => 'SimRain',
        'PresenceVariableID'    => 'SimPresence',
    ];

    /** Einträge im Simulationsprotokoll */
    private const SIM_LOG_LINES = 15;

    protected function Simulating(): bool
    {
        return $this->ReadPropertyBoolean('SimulationMode');
    }

    /**
     * Legt die Simulationsvariablen an (nur für eingestellte Sensoren) oder entfernt sie.
     */
    private function MaintainSimVariables(): void
    {
        $sim = $this->Simulating();
        $wu = $this->ReadPropertyInteger('WindUnit');
        $gu = $this->ReadPropertyInteger('GustUnit');
        $slider = static function (string $icon, float $min, float $max, float $step, string $suffix, int $digits): array {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => $icon, 'MIN' => $min, 'MAX' => $max,
                'STEP_SIZE' => $step, 'SUFFIX' => $suffix, 'DIGITS' => $digits, 'USAGE_TYPE' => 5,
            ];
        };
        $switch = static fn (string $icon): array => ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON_TRUE' => $icon, 'USAGE_TYPE' => 0];

        $defs = [
            'SimLux'      => ['Simulation – brightness', VARIABLETYPE_FLOAT, $slider('brightness', 0, 120000, 500, ' lx', 0), 200],
            'SimTemp'     => ['Simulation – temperature', VARIABLETYPE_FLOAT, $slider('temperature-half', -10, 40, 0.5, ' °C', 1), 201],
            'SimWind'     => ['Simulation – wind', VARIABLETYPE_FLOAT, $slider('wind', 0, self::UnitMax($wu), self::UnitStep($wu), $this->UnitSuffix($wu), $wu === 0 ? 0 : 1), 202],
            'SimGust'     => ['Simulation – gusts', VARIABLETYPE_FLOAT, $slider('wind', 0, self::UnitMax($gu), self::UnitStep($gu), $this->UnitSuffix($gu), $gu === 0 ? 0 : 1), 203],
            'SimRain'     => ['Simulation – rain', VARIABLETYPE_BOOLEAN, $switch('cloud-rain'), 204],
            'SimPresence' => ['Simulation – someone at home', VARIABLETYPE_BOOLEAN, $switch('house-user'), 205],
        ];
        $sensorOf = array_flip(self::SIM_SENSORS);
        foreach ($defs as $ident => [$name, $type, $presentation, $pos]) {
            $keep = $sim && $this->ReadPropertyInteger($sensorOf[$ident]) > 0;
            $this->MaintainVariable($ident, $this->Translate($name), $type, $presentation, $pos, $keep);
            if ($keep) {
                $this->EnableAction($ident);
            }
        }

        $this->MaintainVariable('SimTime', $this->Translate('Simulation – time of day (HH:MM, empty = now)'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT,
        ], 206, $sim);
        if ($sim) {
            $this->EnableAction('SimTime');
        }
        $this->MaintainVariable('SimLog', $this->Translate('Simulation – log'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'list',
            'MULTILINE'    => true,
        ], 207, $sim);
    }

    /**
     * Setzt einen simulierten Wert (aus Visualisierung oder Kachel).
     */
    private function SetSimValue(string $ident, mixed $value): void
    {
        if (!$this->Simulating() || @$this->GetIDForIdent($ident) === false) {
            throw new InvalidArgumentException('Simulation ist ausgeschaltet oder der Sensor ist nicht eingestellt.');
        }
        switch ($ident) {
            case 'SimRain':
            case 'SimPresence':
                $value = (bool) $value;
                break;
            case 'SimTime':
                $value = trim((string) $value);
                if ($value !== '' && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value)) {
                    throw new InvalidArgumentException($this->Translate('Please enter the time as HH:MM, e.g. 14:30.'));
                }
                break;
            default:
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new InvalidArgumentException($this->Translate('Please enter a number.'));
                }
                $max = match ($ident) {
                    'SimLux'  => 200000.0,
                    'SimTemp' => 60.0,
                    default   => 500.0,
                };
                $value = round(max($ident === 'SimTemp' ? -40.0 : 0.0, min($max, (float) $value)), 1);
        }
        $this->SetValue($ident, $value);
        $this->EvaluateNow('simulation ' . $ident);
    }

    /**
     * Übernimmt die aktuellen Werte der echten Sensoren in die Simulation.
     */
    private function SimFromReal(): void
    {
        foreach (self::SIM_SENSORS as $property => $ident) {
            if (@$this->GetIDForIdent($ident) === false) {
                continue;
            }
            $real = $this->ReadSensor($property, true);
            if ($real === null) {
                continue;
            }
            $value = in_array($ident, ['SimRain', 'SimPresence'], true) ? $real > 0 : round($real, 1);
            $this->SetValueIfChanged($ident, $value);
        }
        if (@$this->GetIDForIdent('SimTime') !== false) {
            $this->SetValueIfChanged('SimTime', '');
        }
    }

    /**
     * Simulierter Sensorwert; null = nicht simuliert (Sensor nicht eingestellt).
     */
    private function SimSensor(string $property): ?float
    {
        $ident = self::SIM_SENSORS[$property] ?? '';
        if ($ident === '' || @$this->GetIDForIdent($ident) === false) {
            return null;
        }
        $v = $this->GetValue($ident);
        return is_bool($v) ? ($v ? 1.0 : 0.0) : (float) $v;
    }

    /**
     * Zeitpunkt für Sonnenstand, Tag/Nacht, Zeitfenster und Wochentag.
     * In der Simulation optional eine vorgegebene Uhrzeit am heutigen Tag.
     */
    private function SkyTime(int $now): int
    {
        if (!$this->Simulating() || @$this->GetIDForIdent('SimTime') === false) {
            return $now;
        }
        $t = trim((string) $this->GetValue('SimTime'));
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
            return $now;
        }
        return (int) mktime((int) $m[1], (int) $m[2], 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
    }

    /**
     * Verzögerungen und Sperren in Minuten – in der Simulation auf Wunsch 0.
     */
    private function Minutes(string $property): int
    {
        if ($this->Simulating() && $this->ReadPropertyBoolean('SimSkipDelays')) {
            return 0;
        }
        return max(0, $this->ReadPropertyInteger($property));
    }

    /**
     * Eintrag ins Simulationsprotokoll (neueste Zeile oben).
     */
    protected function SimLog(string $text): void
    {
        $lines = json_decode($this->ReadAttributeString('SimLog'), true) ?: [];
        array_unshift($lines, date('H:i:s', $this->Now()) . '  ' . $text);
        $lines = array_slice($lines, 0, self::SIM_LOG_LINES);
        $this->WriteAttributeString('SimLog', json_encode($lines));
        if (@$this->GetIDForIdent('SimLog') !== false) {
            $this->SetValue('SimLog', implode("\n", $lines));
        }
        $this->SendDebug('Simulation', $text, 0);
    }

    /**
     * Echter Wind- und Regenschutz während der Simulation: prüft die echten Sensoren
     * und fährt die echte Markise ein. Ein Befehl pro Alarm, auf Wunsch nach der Fahrzeit wiederholt.
     */
    private function RealSafety(): void
    {
        if (!$this->Simulating() || !$this->ReadPropertyBoolean('SimRealSafety')) {
            return;
        }
        $wind = $this->ReadSensor('WindVariableID', true);
        $gust = $this->ReadSensor('GustVariableID', true);
        $rain = $this->ReadSensor('RainVariableID', true);
        $alarm = '';
        if ($wind !== null && $wind >= $this->ReadPropertyFloat('WindAlarm')) {
            $alarm = sprintf($this->Translate('Real wind alarm (%s)'), $this->Num($wind) . $this->UnitSuffix($this->ReadPropertyInteger('WindUnit')));
        } elseif ($gust !== null && $gust >= $this->ReadPropertyFloat('GustAlarm')) {
            $alarm = sprintf($this->Translate('Real gust alarm (%s)'), $this->Num($gust) . $this->UnitSuffix($this->ReadPropertyInteger('GustUnit')));
        } elseif ($rain !== null && $rain > 0) {
            $alarm = $this->Translate('Real rain');
        }

        $sent = $this->ReadAttributeBoolean('RealSafetySent');
        if ($alarm !== '' && !$sent) {
            $ok = $this->SendCommand('retract');
            $this->WriteAttributeBoolean('RealSafetySent', $ok);
            $text = $alarm . ' → ' . ($ok ? $this->Translate('awning really retracted') : $this->Translate('retract command failed'));
            $this->SimLog($text);
            if ($ok && $this->ReadPropertyBoolean('RepeatRetract')) {
                $this->SetTimerInterval('Repeat', max(10, $this->ReadPropertyInteger('TravelTime')) * 1000);
            }
            if ($this->ReadPropertyBoolean('NotifySafety')) {
                $this->Notify($this->Translate('Awning – safety'), $text);
            }
        } elseif ($alarm === '' && $sent) {
            $this->WriteAttributeBoolean('RealSafetySent', false);
            $this->SimLog($this->Translate('Real alarm over'));
        }
    }

    /**
     * Beim Ein- und Ausschalten der Simulation: alle Laufzeitdaten zurücksetzen,
     * damit nichts Simuliertes in den echten Betrieb rutscht (und umgekehrt).
     * Nach dem Ausschalten ist der Zustand unbekannt – der nächste Durchlauf schickt den passenden echten Befehl.
     */
    private function ResetRuntime(bool $simulation): void
    {
        $this->WriteAttributeString('LastCommand', '');
        foreach (['OnSince', 'OffSince', 'WindLockUntil', 'RainLockUntil', 'AbsentSince', 'BrightLastChange'] as $a) {
            $this->WriteAttributeInteger($a, 0);
        }
        $this->WriteAttributeString('SafetyReason', '');
        $this->WriteAttributeBoolean('RealSafetySent', false);
        $this->WriteManualUntil(0);
        $this->SetTimerInterval('Repeat', 0);
        $this->SetTimerInterval('Travel', 0);
        $this->WriteAttributeString('SimLog', '[]');
        if ($simulation) {
            $this->SimFromReal();
            $this->SimLog($this->Translate('Simulation started – the awning is not moved'));
        }
    }

    /**
     * Sperren, Pause und laufende Verzögerungen löschen (nur in der Simulation).
     */
    private function SimClearLocks(): void
    {
        if (!$this->Simulating()) {
            throw new InvalidArgumentException('Nur in der Simulation möglich.');
        }
        foreach (['OnSince', 'OffSince', 'WindLockUntil', 'RainLockUntil'] as $a) {
            $this->WriteAttributeInteger($a, 0);
        }
        $this->WriteAttributeString('SafetyReason', '');
        $this->WriteManualUntil(0);
        $this->SimLog($this->Translate('Locks, pause and delays reset'));
        $this->EvaluateNow('simulation reset');
    }
}
