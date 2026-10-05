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

require_once __DIR__ . '/../libs/MarkiseSunTrait.php';
require_once __DIR__ . '/../libs/MarkiseActuatorTrait.php';
require_once __DIR__ . '/../libs/MarkiseTileTrait.php';
require_once __DIR__ . '/../libs/MarkiseNotifyTrait.php';
require_once __DIR__ . '/../libs/MarkiseSimulationTrait.php';
require_once __DIR__ . '/../libs/MarkiseExtrasTrait.php';
require_once __DIR__ . '/../libs/MarkiseImportTrait.php';

class Markisensteuerung extends IPSModuleStrict
{
    use MarkiseSunTrait;
    use MarkiseActuatorTrait;
    use MarkiseTileTrait;
    use MarkiseNotifyTrait;
    use MarkiseSimulationTrait;
    use MarkiseExtrasTrait;
    use MarkiseImportTrait;

    // Werte der Variable "Status"
    public const ST_OFF = 0;
    public const ST_WAITING = 1;
    public const ST_SUN = 2;
    public const ST_MANUAL = 3;
    public const ST_GRACE = 4;
    public const ST_ABSENT = 5;
    public const ST_WEEKDAY = 6;
    public const ST_NIGHT = 7;
    public const ST_TIME = 8;
    public const ST_WIND = 9;
    public const ST_RAIN = 10;
    public const ST_FROST = 11;
    public const ST_SENSOR = 12;
    public const ST_HOLD = 13;
    public const ST_WARNING = 14;

    // Werte der Variable "Zustand"
    public const STATE_RETRACTED = 0;
    public const STATE_RETRACTING = 1;
    public const STATE_EXTENDING = 2;
    public const STATE_EXTENDED = 3;
    public const STATE_STOPPED = 4;

    // Werte der Variable "Markise" (Bedienung)
    private const CTRL_RETRACT = 0;
    private const CTRL_STOP = 1;
    private const CTRL_EXTEND = 2;

    /** Einstellungen, die auf Wunsch als Variablen in der Visualisierung erscheinen: Ident => [Eigenschaft, Typ] */
    private const SETTINGS = [
        'SetLuxOn'     => ['LuxOn', VARIABLETYPE_INTEGER],
        'SetTempMin'   => ['TempMin', VARIABLETYPE_FLOAT],
        'SetWindMax'   => ['WindMax', VARIABLETYPE_FLOAT],
        'SetWindAlarm' => ['WindAlarm', VARIABLETYPE_FLOAT],
        'SetGustAlarm' => ['GustAlarm', VARIABLETYPE_FLOAT],
        'SetDayCheck'  => ['DayCheck', VARIABLETYPE_BOOLEAN],
        'SetWeekday1'  => ['Weekday1', VARIABLETYPE_BOOLEAN],
        'SetWeekday2'  => ['Weekday2', VARIABLETYPE_BOOLEAN],
        'SetWeekday3'  => ['Weekday3', VARIABLETYPE_BOOLEAN],
        'SetWeekday4'  => ['Weekday4', VARIABLETYPE_BOOLEAN],
        'SetWeekday5'  => ['Weekday5', VARIABLETYPE_BOOLEAN],
        'SetWeekday6'  => ['Weekday6', VARIABLETYPE_BOOLEAN],
        'SetWeekday7'  => ['Weekday7', VARIABLETYPE_BOOLEAN],
    ];

    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Instanz aktiv / nicht aktiv
        $this->RegisterPropertyBoolean('Active', true);

        // Ansteuerung
        $this->RegisterPropertyInteger('ActuatorMode', 0);
        $this->RegisterPropertyInteger('ExtendVariableID', 0);
        $this->RegisterPropertyInteger('RetractVariableID', 0);
        $this->RegisterPropertyInteger('StopVariableID', 0);
        $this->RegisterPropertyInteger('SwitchVariableID', 0);
        $this->RegisterPropertyBoolean('SwitchInvert', false);
        $this->RegisterPropertyInteger('PositionVariableID', 0);
        $this->RegisterPropertyInteger('PositionScale', 0);
        $this->RegisterPropertyInteger('PositionExtended', 100);
        $this->RegisterPropertyInteger('PositionRetracted', 0);
        $this->RegisterPropertyInteger('TravelTime', 60);
        $this->RegisterPropertyBoolean('RepeatRetract', true);

        // Sensoren
        $this->RegisterPropertyInteger('BrightnessVariableID', 0);
        $this->RegisterPropertyInteger('TemperatureVariableID', 0);
        $this->RegisterPropertyInteger('WindVariableID', 0);
        $this->RegisterPropertyInteger('WindUnit', 0);
        $this->RegisterPropertyInteger('GustVariableID', 0);
        $this->RegisterPropertyInteger('GustUnit', 1);
        $this->RegisterPropertyInteger('RainVariableID', 0);
        $this->RegisterPropertyInteger('PresenceVariableID', 0);

        // Sicherheit
        $this->RegisterPropertyFloat('WindAlarm', 6.0);
        $this->RegisterPropertyFloat('GustAlarm', 28.0);
        $this->RegisterPropertyInteger('WindLockMinutes', 15);
        $this->RegisterPropertyInteger('RainLockMinutes', 10);
        $this->RegisterPropertyBoolean('FrostEnabled', true);
        $this->RegisterPropertyFloat('FrostTemp', 3.0);
        $this->RegisterPropertyInteger('WindTimeoutMinutes', 120);
        $this->RegisterPropertyInteger('FreezeMinutes', 30);
        $this->RegisterPropertyFloat('FreezeMinChange', 1.0);
        $this->RegisterPropertyBoolean('SafetyAlways', true);
        $this->RegisterPropertyBoolean('GustTrend', true);
        $this->RegisterPropertyFloat('GustTrendRise', 12.0);
        $this->RegisterPropertyInteger('GustTrendMinutes', 15);
        $this->RegisterPropertyInteger('GustTrendShare', 70);
        $this->RegisterPropertyBoolean('UseWarning', false);
        $this->RegisterPropertyInteger('WarningVariableID', 0);
        $this->RegisterPropertyInteger('WarningMinLevel', 2);

        // Sonnenautomatik
        $this->RegisterPropertyInteger('LuxOn', 30000);
        $this->RegisterPropertyInteger('LuxOff', 20000);
        $this->RegisterPropertyInteger('DelayOn', 5);
        $this->RegisterPropertyInteger('DelayOff', 15);
        $this->RegisterPropertyFloat('TempMin', 18.0);
        $this->RegisterPropertyFloat('TempHysteresis', 1.0);
        $this->RegisterPropertyFloat('WindMax', 4.0);
        $this->RegisterPropertyBoolean('UseSunPosition', false);
        $this->RegisterPropertyFloat('AzimuthFrom', 90.0);
        $this->RegisterPropertyFloat('AzimuthTo', 270.0);
        $this->RegisterPropertyFloat('SunElevationMin', 10.0);
        $this->RegisterPropertyInteger('LuxAverageMinutes', 10);
        $this->RegisterPropertyInteger('MaxMovesPerHour', 4);

        // Standort (0/0 = aus Kern Instanzen → Location)
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);

        // Zeiten
        for ($d = 1; $d <= 7; $d++) {
            $this->RegisterPropertyBoolean('Weekday' . $d, true);
        }
        $this->RegisterPropertyBoolean('DayCheck', true);
        $this->RegisterPropertyFloat('DayElevation', 0.0);
        $this->RegisterPropertyBoolean('UseTimeWindow', false);
        $this->RegisterPropertyString('TimeFrom', '{"hour":9,"minute":0,"second":0}');
        $this->RegisterPropertyString('TimeTo', '{"hour":20,"minute":0,"second":0}');

        // Anwesenheit und Handbetrieb
        $this->RegisterPropertyInteger('GraceMinutes', 30);
        $this->RegisterPropertyInteger('ManualPauseMinutes', 60);
        $this->RegisterPropertyBoolean('ManualDetect', true);

        // Halten (Abendmodus)
        $this->RegisterPropertyBoolean('HoldEnabled', true);
        $this->RegisterPropertyInteger('DoorVariableID', 0);
        $this->RegisterPropertyInteger('DoorClosedValue', 0);

        // Benachrichtigung
        $this->RegisterPropertyBoolean('NotifySafety', false);
        $this->RegisterPropertyBoolean('NotifyMove', false);
        $this->RegisterPropertyInteger('NotifyTarget', 0);

        // Simulation (Testbetrieb)
        $this->RegisterPropertyBoolean('SimulationMode', false);
        $this->RegisterPropertyBoolean('SimSkipDelays', false);
        $this->RegisterPropertyBoolean('SimRealSafety', true);

        // Anzeige
        $this->RegisterPropertyBoolean('ShowSettings', false);
        $this->RegisterPropertyBoolean('ShowSunPosition', false);
        $this->RegisterPropertyBoolean('UseTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyBoolean('TileReduceMotion', false);

        // Laufzeitdaten
        $this->RegisterAttributeString('LastCommand', '');
        $this->RegisterAttributeInteger('LastCommandTime', 0);
        $this->RegisterAttributeInteger('OwnCommandUntil', 0);
        $this->RegisterAttributeInteger('ManualUntil', 0);
        $this->RegisterAttributeInteger('AbsentSince', 0);
        $this->RegisterAttributeInteger('OnSince', 0);
        $this->RegisterAttributeInteger('OffSince', 0);
        $this->RegisterAttributeInteger('WindLockUntil', 0);
        $this->RegisterAttributeInteger('RainLockUntil', 0);
        $this->RegisterAttributeString('SafetyReason', '');
        $this->RegisterAttributeFloat('BrightLastValue', -1.0);
        $this->RegisterAttributeInteger('BrightLastChange', 0);
        $this->RegisterAttributeString('TileData', '{}');
        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterAttributeBoolean('SimActive', false);
        $this->RegisterAttributeBoolean('RealSafetySent', false);
        $this->RegisterAttributeString('SimLog', '[]');
        $this->RegisterAttributeInteger('HoldSince', 0);
        $this->RegisterAttributeBoolean('DoorWasClosed', true);
        $this->RegisterAttributeString('GustHistory', '[]');
        $this->RegisterAttributeString('LuxHistory', '[]');
        $this->RegisterAttributeString('MoveLog', '[]');
        $this->RegisterAttributeInteger('WarningVar', 0);

        $this->RegisterTimer('Tick', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Tick\', 0);');
        $this->RegisterTimer('Travel', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TravelDone\', 0);');
        $this->RegisterTimer('Repeat', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'RepeatRetract\', 0);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetVisualizationType($this->ReadPropertyBoolean('UseTile') ? 1 : 0);
        $this->WriteAttributeInteger('WarningVar', $this->FindWarningVariable());
        $this->MaintainVariables();
        $this->MaintainSimVariables();
        $this->WatchVariables();

        // Simulation ein- oder ausgeschaltet: Laufzeitdaten zurücksetzen
        $sim = $this->Simulating();
        if ($sim !== $this->ReadAttributeBoolean('SimActive')) {
            $this->WriteAttributeBoolean('SimActive', $sim);
            $this->ResetRuntime($sim);
        }

        $status = $this->ReadPropertyBoolean('Active') ? $this->CheckConfiguration() : 104;
        $this->SetStatus($status);
        $this->SetTimerInterval('Tick', $status === 102 ? 60000 : 0);
        if ($status !== 102) {
            // Inaktiv oder nicht eingerichtet: keine laufenden Wiederholungen oder Fahrzeit-Timer
            $this->SetTimerInterval('Repeat', 0);
            $this->SetTimerInterval('Travel', 0);
        }

        if ($status === 102) {
            $this->EvaluateNow('apply');
        } else {
            $this->PushTileState();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE || $this->GetStatus() !== 102) {
            return;
        }

        $watch = $this->ActuatorWatchList();
        if (isset($watch[$SenderID])) {
            // In der Simulation ist der Zustand nur gedacht – echte Aktorvariablen zählen nicht als Handbetrieb
            if (!$this->ReadPropertyBoolean('ManualDetect') || $this->Simulating()) {
                return;
            }
            $command = $this->DetectManual($watch[$SenderID], $Data);
            if ($command !== null) {
                $this->SendDebug('Handbetrieb', 'Bedienung von außen erkannt: ' . $command, 0);
                $this->ManualOperation($command, false);
            }
            return;
        }

        // Sensor oder Anwesenheit geändert: sofort neu bewerten
        $this->EvaluateNow('sensor ' . $SenderID);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Automatic':
                $this->SetAutomatic((bool) $Value);
                return;

            case 'Control':
                $map = [self::CTRL_RETRACT => 'retract', self::CTRL_STOP => 'stop', self::CTRL_EXTEND => 'extend'];
                $value = (int) $Value;
                if (!isset($map[$value])) {
                    throw new InvalidArgumentException('Ungültiger Wert für Control: ' . $value);
                }
                $this->ManualOperation($map[$value], true);
                return;

            case 'Position':
                $this->ManualPosition((int) $Value);
                return;

            case 'EndManual':
                $this->EndManualPause();
                return;

            case 'Hold':
                $this->SetHold((bool) $Value);
                return;

            case 'ImportScript':
                echo $this->ImportFromScript((int) $Value);
                return;

            case 'LocationFromModule':
                $loc = $this->ModuleLocation();
                if ($loc === null) {
                    echo $this->Translate('No location found under Core Instances → Location.');
                    return;
                }
                $this->UpdateFormField('Latitude', 'value', $loc[0]);
                $this->UpdateFormField('Longitude', 'value', $loc[1]);
                echo sprintf($this->Translate('Location %s / %s taken over. Click "Apply changes" to save.'), (string) $loc[0], (string) $loc[1]);
                return;

            case 'FindWarning':
                $id = $this->FindWarningVariable(true);
                if ($id === 0) {
                    echo $this->Translate('No instance of the module "Unwetterwarnung" with a warning level variable found. Enable "Indicator variable for active warnings" there.');
                    return;
                }
                $this->UpdateFormField('WarningVariableID', 'value', $id);
                echo sprintf($this->Translate('Warning level found (#%d). Click "Apply changes" to save.'), $id);
                return;

            case 'RestartGrace':
                $this->RestartGrace();
                return;

            case 'EndGrace':
                $this->EndGrace();
                return;

            case 'Tick':
                $this->EvaluateNow('timer');
                return;

            case 'FormWindUnit':
                foreach (['WindAlarm', 'WindMax'] as $field) {
                    $this->UpdateFormField($field, 'suffix', trim($this->UnitSuffix((int) $Value)));
                }
                return;

            case 'FormGustUnit':
                foreach (['GustAlarm', 'GustTrendRise'] as $field) {
                    $this->UpdateFormField($field, 'suffix', trim($this->UnitSuffix((int) $Value)));
                }
                return;

            case 'FormMode':
                foreach ($this->ModeVisibility((int) $Value) as $field => $visible) {
                    $this->UpdateFormField($field, 'visible', $visible);
                }
                return;

            case 'TestNotify':
                $ok = $this->Notify($this->Translate('Awning'), $this->Translate('Test notification from the awning control.'));
                echo $ok ? $this->Translate('Test notification sent.') : $this->Translate('Test notification could not be sent. Is a tile visualization with registered devices available?');
                return;

            case 'TravelDone':
                $this->SetTimerInterval('Travel', 0);
                $this->FinishTravel();
                return;

            case 'SimLux':
            case 'SimTemp':
            case 'SimWind':
            case 'SimGust':
            case 'SimRain':
            case 'SimPresence':
            case 'SimDoor':
            case 'SimWarning':
            case 'SimTime':
                $this->SetSimValue($Ident, $Value);
                return;

            case 'SimFromReal':
                if (!$this->Simulating()) {
                    throw new InvalidArgumentException('Nur in der Simulation möglich.');
                }
                $this->SimFromReal();
                $this->EvaluateNow('simulation from real');
                return;

            case 'SimClear':
                $this->SimClearLocks();
                return;

            case 'RepeatRetract':
                $this->SetTimerInterval('Repeat', 0);
                $realInSim = $this->Simulating() && $this->ReadAttributeBoolean('RealSafetySent');
                $realNormal = !$this->Simulating() && $this->ReadAttributeString('SafetyReason') !== '' && $this->ReadAttributeString('LastCommand') === 'retract';
                if ($realInSim || $realNormal) {
                    $this->SendDebug('Sicherheit', 'Einfahrbefehl wird wiederholt', 0);
                    $this->SendCommand('retract');
                }
                return;
        }

        if (isset(self::SETTINGS[$Ident])) {
            $this->ChangeSetting($Ident, $Value);
            return;
        }

        throw new InvalidArgumentException('Ungültiger Ident: ' . $Ident);
    }

    // =====================================================================
    // Öffentliche Befehle (MARKISE_*)
    // =====================================================================

    /** Bewertet alle Bedingungen sofort neu. */
    public function Evaluate(): bool
    {
        return $this->EvaluateNow('command');
    }

    private function EvaluateNow(string $Trigger): bool
    {
        if ($this->GetStatus() !== 102) {
            return false;
        }
        // Timer und Sensoren laufen in eigenen Threads – nie zwei Bewertungen gleichzeitig
        $sem = 'MARKISE_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 3000)) {
            $this->SendDebug('Bewertung', 'übersprungen (läuft bereits)', 0);
            return false;
        }
        try {
            $this->Run($Trigger);
        } finally {
            IPS_SemaphoreLeave($sem);
        }
        return true;
    }

    /** Fährt die Markise aus (zählt als Handbetrieb). */
    public function Extend(): bool
    {
        return $this->ManualOperation('extend', true);
    }

    /** Fährt die Markise ein (zählt als Handbetrieb). */
    public function Retract(): bool
    {
        return $this->ManualOperation('retract', true);
    }

    /** Hält die Markise an (sofern die Ansteuerung Stopp kennt). */
    public function Stop(): bool
    {
        return $this->ManualOperation('stop', true);
    }

    public function SetAutomatic(bool $Active): void
    {
        $this->SetValue('Automatic', $Active);
        if ($Active) {
            // Beim Einschalten zählt die Automatik sofort, eine laufende Handbetrieb-Pause endet
            $this->WriteManualUntil(0);
        }
        $this->EvaluateNow('automatic ' . ($Active ? 'on' : 'off'));
    }

    /** Halten (Abendmodus): Markise bleibt, wie sie ist – nur Sicherheit fährt noch ein. */
    public function SetHold(bool $Active): void
    {
        if (!$this->ReadPropertyBoolean('HoldEnabled')) {
            throw new InvalidArgumentException('Halten ist in der Instanz ausgeschaltet.');
        }
        $this->SetValue('Hold', $Active);
        $this->WriteAttributeInteger('HoldSince', $Active ? $this->Now() : 0);
        $this->EvaluateNow('hold ' . ($Active ? 'on' : 'off'));
    }

    public function EndManualPause(): void
    {
        $this->WriteManualUntil(0);
        $this->EvaluateNow('manual pause ended');
    }

    /** Startet die Abwesenheits-Karenz neu (wieder volle Zeit). */
    public function RestartGrace(): void
    {
        if ($this->ReadAttributeInteger('AbsentSince') > 0) {
            $this->WriteAttributeInteger('AbsentSince', $this->Now());
        }
        $this->EvaluateNow('grace restarted');
    }

    /** Beendet die Abwesenheits-Karenz sofort. */
    public function EndGrace(): void
    {
        if ($this->ReadAttributeInteger('AbsentSince') > 0) {
            $this->WriteAttributeInteger('AbsentSince', $this->Now() - $this->ReadPropertyInteger('GraceMinutes') * 60 - 1);
        }
        $this->EvaluateNow('grace ended');
    }

    // =====================================================================
    // Bewertung
    // =====================================================================

    private function Run(string $trigger): void
    {
        $now = $this->Now();
        $ctx = $this->Context($now);
        $result = $this->Decide($ctx);

        foreach ($result['attributes'] as $name => $value) {
            $this->WriteAttributeInteger($name, $value);
        }

        $previousSafety = $this->ReadAttributeString('SafetyReason');
        $this->WriteAttributeString('SafetyReason', $result['safety']);
        $sim = $this->Simulating();

        if ($result['action'] !== 'none') {
            $this->ExecuteCommand($result['action'], false);
            if ($result['comfort']) {
                $this->RecordMove($now);
            }
            if (!$sim && $result['action'] === 'retract' && $result['safety'] !== '' && $this->ReadPropertyBoolean('RepeatRetract')) {
                // Funkmotoren ohne Rückmeldung (z. B. Somfy RTS): Befehl nach der Fahrzeit sicherheitshalber wiederholen
                $this->SetTimerInterval('Repeat', max(10, $this->ReadPropertyInteger('TravelTime')) * 1000);
            }
            if (!$sim && $this->ReadPropertyBoolean('NotifyMove') && $result['safety'] === '') {
                $this->Notify($this->Translate('Awning'), $result['reason']);
            }
        }
        if (!$sim && $result['safety'] !== '' && $previousSafety === '' && $this->ReadPropertyBoolean('NotifySafety')) {
            $this->Notify($this->Translate('Awning – safety'), $result['reason']);
        }
        if ($sim) {
            // Protokoll: jede gedachte Fahrt und jeder Statuswechsel
            if ($result['action'] !== 'none' || $result['status'] !== $this->GetValue('Status')) {
                $this->SimLog($result['reason']);
            }
            $this->RealSafety();
        }

        $this->SetValueIfChanged('Status', $result['status']);
        $this->SetValueIfChanged('Reason', ($sim ? $this->Translate('Simulation') . ': ' : '') . $result['reason']);
        $this->SetValueIfChanged('Safety', $result['safety'] !== '');
        if ($this->ReadPropertyBoolean('ShowSunPosition') && $ctx['sun'] !== null) {
            $this->SetValueIfChanged('SunAzimuth', $ctx['sun']['azimuth']);
            $this->SetValueIfChanged('SunElevation', $ctx['sun']['elevation']);
        }

        $this->SendDebug('Bewertung', $trigger . ' → ' . $result['action'] . ' · ' . $result['reason'], 0);
        $this->PushTileState($ctx, $result);
    }

    /**
     * Sammelt alle Eingangswerte für die Entscheidung.
     */
    protected function Context(int $now): array
    {
        $sim = $this->Simulating();
        // Uhr für Himmel, Zeitfenster und Wochentag (in der Simulation vorgebbar); Verzögerungen laufen mit der echten Uhr
        $sky = $this->SkyTime($now);
        $sun = $this->SunNow($sky);

        $lux = $this->ReadSensor('BrightnessVariableID');
        $gust = $this->ReadSensor('GustVariableID');
        $windID = $this->ReadPropertyInteger('WindVariableID');
        $gustID = $this->ReadPropertyInteger('GustVariableID');

        // Tag: Sonne über der eingestellten Höhe (ohne Standort gilt immer Tag)
        $isDay = $sun === null || $sun['elevation'] >= $this->ReadPropertyFloat('DayElevation');

        // Anwesenheit mit Karenz
        $presenceID = $this->ReadPropertyInteger('PresenceVariableID');
        $present = true;
        if ($presenceID > 0 && IPS_VariableExists($presenceID)) {
            $present = (bool) GetValue($presenceID);
        }
        if ($sim && $presenceID > 0 && ($simPresent = $this->SimSensor('PresenceVariableID')) !== null) {
            $present = $simPresent > 0;
        }
        $absentSince = $this->ReadAttributeInteger('AbsentSince');
        if ($present) {
            $absentSince = 0;
        } elseif ($absentSince === 0) {
            $absentSince = $now;
        }
        $this->WriteAttributeInteger('AbsentSince', $absentSince);
        $graceTotal = max(0, $this->ReadPropertyInteger('GraceMinutes')) * 60;
        $graceLeft = $absentSince > 0 ? max(0, $graceTotal - ($now - $absentSince)) : 0;

        // Böen-Trend und Lux-Mittelwert aus dem kurzen Verlauf
        $gustTrend = $this->GustTrend($gust, $now);
        $luxAvg = $this->LuxAverage($lux, $now);

        return [
            'now'         => $now,
            'automatic'   => (bool) $this->GetValue('Automatic'),
            'lastCommand' => $this->ReadAttributeString('LastCommand'),
            'manualUntil' => $this->ReadAttributeInteger('ManualUntil'),
            'lux'         => $lux,
            'luxAvg'      => $luxAvg,
            'luxFrozen'   => !$sim && $this->BrightnessFrozen($lux, $now, $isDay),
            'temp'        => $this->ReadSensor('TemperatureVariableID'),
            'wind'        => $this->ReadSensor('WindVariableID'),
            'gust'        => $gust,
            'gustTrend'   => $gustTrend,
            'rain'        => $this->ReadSensor('RainVariableID'),
            'warning'     => $this->WarningLevel(),
            'windStale'   => !$sim && $this->SensorsStale([$windID, $gustID], $now),
            'simulation'  => $sim,
            'sun'         => $sun,
            'isDay'       => $isDay,
            'weekday'     => (int) date('N', $sky),
            'inTime'      => $this->InTimeWindow($sky),
            'present'     => $present,
            'hasPresence' => $presenceID > 0,
            'graceLeft'   => $graceLeft,
            'absentSince' => $absentSince,
            'graceTotal'  => $graceTotal,
            'hold'        => $this->HoldActive($present, $graceLeft, $isDay, $sky),
        ];
    }

    /**
     * Entscheidet, was zu tun ist. Reihenfolge = Priorität:
     * Sicherheit → Automatik aus → Handbetrieb → Halten → Wochentag → Nacht → Zeitfenster → Sonne → Anwesenheit.
     *
     * @return array{action: string, status: int, reason: string, safety: string, attributes: array<string, int>, comfort: bool}
     */
    protected function Decide(array $c): array
    {
        $now = $c['now'];
        $last = $c['lastCommand'];
        $attr = [];
        $comfort = false; // Fahrt der Sonnenautomatik (zählt für das Schaltlimit)
        $out = function (string $action, int $status, string $reason, string $safety = '') use (&$attr, &$comfort): array {
            return ['action' => $action, 'status' => $status, 'reason' => $reason, 'safety' => $safety, 'attributes' => $attr, 'comfort' => $comfort && $action !== 'none'];
        };
        $retractIfNeeded = static function () use ($last): string {
            return $last === 'retract' ? 'none' : 'retract';
        };

        // ---------- 1. Sicherheit ----------
        $windLock = $this->ReadAttributeInteger('WindLockUntil');
        $rainLock = $this->ReadAttributeInteger('RainLockUntil');
        $safety = '';
        $status = 0;
        $reason = '';
        $wu = $this->UnitSuffix($this->ReadPropertyInteger('WindUnit'));
        $gu = $this->UnitSuffix($this->ReadPropertyInteger('GustUnit'));

        if ($c['wind'] !== null && $c['wind'] >= $this->ReadPropertyFloat('WindAlarm')) {
            $safety = 'wind';
            $status = self::ST_WIND;
            $reason = sprintf($this->Translate('Wind alarm (%s) → awning retracted'), $this->Num($c['wind']) . $wu);
        } elseif ($c['gust'] !== null && $c['gust'] >= $this->ReadPropertyFloat('GustAlarm')) {
            $safety = 'gust';
            $status = self::ST_WIND;
            $reason = sprintf($this->Translate('Gust alarm (%s) → awning retracted'), $this->Num($c['gust']) . $gu);
        } elseif ($c['gustTrend'] !== null) {
            // Böen steigen schnell: vorsorglich einfahren, bevor der Alarm erreicht ist
            $safety = 'gust';
            $status = self::ST_WIND;
            $reason = sprintf($this->Translate('Gusts rising quickly (%s → %s) → awning retracted'), $this->Num($c['gustTrend']), $this->Num((float) $c['gust']) . $gu);
        } elseif ($c['warning'] !== null && $c['warning'] >= $this->ReadPropertyInteger('WarningMinLevel') && $c['warning'] < 10) {
            // Unwetterwarnung des DWD; Stufen ab 10 sind Hitze/UV und kein Grund zum Einfahren
            $safety = 'warning';
            $status = self::ST_WARNING;
            $reason = sprintf($this->Translate('Weather warning (level %d) → awning retracted'), $c['warning']);
        } elseif ($c['windStale']) {
            $safety = 'sensor';
            $status = self::ST_SENSOR;
            $reason = $this->Translate('Wind sensor sends no values → awning retracted');
        } elseif ($c['rain'] !== null && $c['rain'] > 0) {
            $safety = 'rain';
            $status = self::ST_RAIN;
            $reason = $this->Translate('Rain → awning retracted');
        } elseif ($this->ReadPropertyBoolean('FrostEnabled') && $c['temp'] !== null && $c['temp'] <= $this->ReadPropertyFloat('FrostTemp')) {
            $safety = 'frost';
            $status = self::ST_FROST;
            $reason = sprintf($this->Translate('Frost (%s) → awning retracted'), $this->Num($c['temp']) . ' °C');
        } elseif ($c['luxFrozen']) {
            $safety = 'sensor';
            $status = self::ST_SENSOR;
            $reason = $this->Translate('Brightness sensor frozen → awning retracted');
        }

        if ($safety === 'wind' || $safety === 'gust') {
            $windLock = $now + $this->Minutes('WindLockMinutes') * 60;
            $attr['WindLockUntil'] = $windLock;
        } elseif ($safety === 'rain') {
            $rainLock = $now + $this->Minutes('RainLockMinutes') * 60;
            $attr['RainLockUntil'] = $rainLock;
        }

        if ($safety === '' && $now < $windLock) {
            $safety = 'windlock';
            $status = self::ST_WIND;
            $reason = sprintf($this->Translate('Wind lock until %s'), date('H:i', $windLock));
        } elseif ($safety === '' && $now < $rainLock) {
            $safety = 'rainlock';
            $status = self::ST_RAIN;
            $reason = sprintf($this->Translate('Rain lock until %s'), date('H:i', $rainLock));
        }

        if ($safety !== '' && ($c['automatic'] || $this->ReadPropertyBoolean('SafetyAlways'))) {
            $attr['OnSince'] = 0;
            $attr['OffSince'] = 0;
            // Beim Eintritt in den Alarm immer einfahren, auch wenn der letzte Befehl schon "einfahren" war
            $entering = $this->ReadAttributeString('SafetyReason') === '';
            return $out($entering ? 'retract' : $retractIfNeeded(), $status, $reason, $safety);
        }

        // ---------- 2. Automatik aus ----------
        if (!$c['automatic']) {
            $attr['OnSince'] = 0;
            $attr['OffSince'] = 0;
            return $out('none', self::ST_OFF, $this->Translate('Automatic off → no automatic action'));
        }

        // ---------- 3. Handbetrieb ----------
        if ($now < $c['manualUntil']) {
            return $out('none', self::ST_MANUAL, sprintf($this->Translate('Manual operation → automatic paused until %s'), date('H:i', $c['manualUntil'])));
        }

        // ---------- 3a. Halten (Abendmodus) ----------
        if ($c['hold']) {
            $attr['OnSince'] = 0;
            $attr['OffSince'] = 0;
            return $out('none', self::ST_HOLD, $this->Translate('Hold active → awning stays as it is (only safety retracts)'));
        }

        // ---------- 4. Wochentag ----------
        if (!$this->ReadPropertyBoolean('Weekday' . $c['weekday'])) {
            return $out($retractIfNeeded(), self::ST_WEEKDAY, $this->Translate('Day not released → awning retracted'));
        }

        // ---------- 5. Nacht ----------
        if ($this->ReadPropertyBoolean('DayCheck') && !$c['isDay']) {
            return $out($retractIfNeeded(), self::ST_NIGHT, $this->Translate('Night → awning retracted'));
        }

        // ---------- 6. Zeitfenster ----------
        if (!$c['inTime']) {
            return $out($retractIfNeeded(), self::ST_TIME, $this->Translate('Outside the time window → awning retracted'));
        }

        // ---------- 7. Sonnenautomatik mit Hysterese und Verzögerung ----------
        $luxOn = $this->ReadPropertyInteger('LuxOn');
        $luxOff = min($luxOn, $this->ReadPropertyInteger('LuxOff'));
        $tempMin = $this->ReadPropertyFloat('TempMin');
        $tempHyst = max(0.0, $this->ReadPropertyFloat('TempHysteresis'));
        $windMax = $this->ReadPropertyFloat('WindMax');

        // Helligkeit als gleitender Mittelwert: durchziehende Wolken lösen nichts aus
        $lux = $c['luxAvg'] ?? $c['lux'];
        $brightOn = $lux === null || $lux >= $luxOn;
        $brightLow = $lux !== null && $lux < $luxOff;
        $warmOn = $c['temp'] === null || $c['temp'] >= $tempMin;
        $coldOff = $c['temp'] !== null && $c['temp'] < $tempMin - $tempHyst;
        $windOk = $c['wind'] === null || $c['wind'] <= $windMax;
        $sunHits = true;
        if ($this->ReadPropertyBoolean('UseSunPosition') && $c['sun'] !== null) {
            $sunHits = self::AzimuthInRange($c['sun']['azimuth'], $this->ReadPropertyFloat('AzimuthFrom'), $this->ReadPropertyFloat('AzimuthTo'))
                && $c['sun']['elevation'] >= $this->ReadPropertyFloat('SunElevationMin');
        }

        $extended = $last === 'extend' || $last === 'stop';
        $action = 'none';
        if (!$extended) {
            $attr['OffSince'] = 0;
            $status = self::ST_WAITING;
            if ($brightOn && $warmOn && $windOk && $sunHits) {
                $since = $this->ReadAttributeInteger('OnSince') ?: $now;
                $attr['OnSince'] = $since;
                $wait = $this->Minutes('DelayOn') * 60 - ($now - $since);
                if ($wait <= 0 && $this->MoveLimitReached($now)) {
                    $status = self::ST_WAITING;
                    $reason = sprintf($this->Translate('Movement limit (%d per hour) reached → awning stays'), $this->ReadPropertyInteger('MaxMovesPerHour'));
                } elseif ($wait <= 0) {
                    $action = 'extend';
                    $comfort = true;
                    $status = self::ST_SUN;
                    $reason = $this->Translate('Conditions met → awning extended');
                } else {
                    $reason = sprintf($this->Translate('Sun detected → extending in %d min'), (int) ceil($wait / 60));
                }
            } else {
                $attr['OnSince'] = 0;
                $reason = $this->WaitingReason($brightOn, $warmOn, $windOk, $sunHits);
            }
        } else {
            $attr['OnSince'] = 0;
            $status = self::ST_SUN;
            if (!$windOk) {
                // über der Windgrenze sofort einfahren (keine Verzögerung)
                $attr['OffSince'] = 0;
                $action = 'retract';
                $status = self::ST_WAITING;
                $reason = sprintf($this->Translate('Wind above limit (%s) → awning retracted'), $this->Num((float) $c['wind']) . $wu);
            } elseif ($brightLow || $coldOff || !$sunHits) {
                $since = $this->ReadAttributeInteger('OffSince') ?: $now;
                $attr['OffSince'] = $since;
                $wait = $this->Minutes('DelayOff') * 60 - ($now - $since);
                if ($wait <= 0 && $this->MoveLimitReached($now)) {
                    $status = self::ST_SUN;
                    $reason = sprintf($this->Translate('Movement limit (%d per hour) reached → awning stays'), $this->ReadPropertyInteger('MaxMovesPerHour'));
                } elseif ($wait <= 0) {
                    $action = 'retract';
                    $comfort = true;
                    $status = self::ST_WAITING;
                    $reason = $this->Translate('Conditions no longer met → awning retracted');
                } else {
                    $reason = sprintf($this->Translate('Conditions no longer met → retracting in %d min'), (int) ceil($wait / 60));
                }
            } else {
                $attr['OffSince'] = 0;
                $reason = $this->Translate('Sun protection active');
            }
        }

        // ---------- 8. Anwesenheit mit Karenz ----------
        if (!$c['present']) {
            if ($c['graceLeft'] > 0) {
                $min = (int) ceil($c['graceLeft'] / 60);
                if ($action === 'extend') {
                    $attr['OnSince'] = 0;
                    return $out('none', self::ST_GRACE, sprintf($this->Translate('Absent, grace period (%d min left) → awning is not extended'), $min));
                }
                if ($action === 'retract') {
                    return $out('retract', self::ST_GRACE, $this->Translate('Absent, grace period, conditions not met → awning retracted'));
                }
                return $out('none', self::ST_GRACE, sprintf($this->Translate('Absent, grace period (%d min left) → awning unchanged'), $min));
            }
            $attr['OnSince'] = 0;
            return $out($retractIfNeeded(), self::ST_ABSENT, $this->Translate('Absent, grace period expired → awning retracted'));
        }

        return $out($action, $status, $reason);
    }

    private function WaitingReason(bool $bright, bool $warm, bool $wind, bool $sun): string
    {
        $missing = [];
        if (!$bright) {
            $missing[] = $this->Translate('too dark');
        }
        if (!$warm) {
            $missing[] = $this->Translate('too cold');
        }
        if (!$wind) {
            $missing[] = $this->Translate('too windy');
        }
        if (!$sun) {
            $missing[] = $this->Translate('sun not on the awning');
        }
        return sprintf($this->Translate('Waiting for sun (%s)'), implode(', ', $missing));
    }

    // =====================================================================
    // Befehle und Handbetrieb
    // =====================================================================

    /**
     * Bedienung von Hand (Visualisierung, Kachel, PHP-Befehl oder von außen erkannt).
     *
     * @param bool $send true = Befehl an den Motor schicken, false = ist von außen schon passiert
     */
    private function ManualOperation(string $command, bool $send): bool
    {
        if ($this->GetStatus() !== 102) {
            echo $this->Translate('The instance is inactive or not configured.');
            return false;
        }
        if ($send && $command === 'extend') {
            $safety = $this->ReadAttributeString('SafetyReason');
            if ($safety !== '') {
                // Bei Wind, Regen, Frost oder Sensorfehler wird nicht ausgefahren
                echo $this->Translate('The awning cannot be extended right now:') . ' ' . (string) $this->GetValue('Reason');
                return false;
            }
        }
        if ($command === 'stop' && $send && !$this->CanStop()) {
            echo $this->Translate('Stop is not available with this actuator.');
            return false;
        }

        $pause = $this->ReadPropertyInteger('ManualPauseMinutes');
        if ($pause > 0) {
            $this->WriteManualUntil($this->Now() + $pause * 60);
        }
        $this->WriteAttributeInteger('OnSince', 0);
        $this->WriteAttributeInteger('OffSince', 0);

        $ok = $send ? $this->ExecuteCommand($command, true) : $this->RecordCommand($command, true);

        // Sicherheit hat Vorrang: von außen ausgefahren bei Wind → sofort wieder einfahren
        $this->EvaluateNow('manual ' . $command);
        return $ok;
    }

    private function ManualPosition(int $percent): void
    {
        if ($this->ReadPropertyInteger('ActuatorMode') !== 2) {
            throw new InvalidArgumentException('Position ist nur mit einer Positionsvariable verfügbar.');
        }
        if ($this->GetStatus() !== 102) {
            echo $this->Translate('The instance is inactive or not configured.');
            return;
        }
        $percent = max(0, min(100, $percent));
        $retracted = $this->ReadPropertyInteger('PositionRetracted');
        if ($percent !== $retracted && $this->ReadAttributeString('SafetyReason') !== '') {
            echo $this->Translate('The awning cannot be extended right now:') . ' ' . (string) $this->GetValue('Reason');
            return;
        }
        $id = $this->ReadPropertyInteger('PositionVariableID');
        if (!IPS_VariableExists($id) || !HasAction($id)) {
            return;
        }
        $pause = $this->ReadPropertyInteger('ManualPauseMinutes');
        if ($pause > 0) {
            $this->WriteManualUntil($this->Now() + $pause * 60);
        }
        if ($this->Simulating()) {
            $this->SimLog(sprintf($this->Translate('Would send: position %d %%'), $percent) . ' (' . $this->Translate('manual') . ')');
        } else {
            $this->WriteAttributeInteger('OwnCommandUntil', $this->Now() + max(10, $this->ReadPropertyInteger('TravelTime') + 10));
            @RequestAction($id, $this->PercentToActuator($id, $percent));
        }
        $this->SetValue('Position', $percent);
        $this->RecordCommand(abs($percent - $retracted) <= 1 ? 'retract' : 'extend', true);
        $this->EvaluateNow('manual position');
    }

    /**
     * Schickt einen Befehl an den Motor und merkt ihn sich.
     */
    private function ExecuteCommand(string $command, bool $manual): bool
    {
        if ($this->Simulating()) {
            // Simulation: nichts senden, nur den gedachten Zustand führen
            $this->SimLog(sprintf($this->Translate('Would send: %s'), $this->CommandName($command)) . ($manual ? ' (' . $this->Translate('manual') . ')' : ''));
            return $this->RecordCommand($command, $manual);
        }
        $ok = $this->SendCommand($command);
        if ($ok) {
            $this->RecordCommand($command, $manual);
        }
        return $ok;
    }

    /**
     * Merkt sich den Befehl und setzt Zustand, Bedienung und Fahrzeit.
     */
    private function RecordCommand(string $command, bool $manual): bool
    {
        $this->WriteAttributeString('LastCommand', $command);
        $this->WriteAttributeInteger('LastCommandTime', $this->Now());

        $ctrl = ['retract' => self::CTRL_RETRACT, 'stop' => self::CTRL_STOP, 'extend' => self::CTRL_EXTEND][$command];
        $this->SetValueIfChanged('Control', $ctrl);

        if ($command === 'stop') {
            $this->SetTimerInterval('Travel', 0);
            $this->SetValueIfChanged('State', self::STATE_STOPPED);
        } else {
            $this->SetValueIfChanged('State', $command === 'extend' ? self::STATE_EXTENDING : self::STATE_RETRACTING);
            $this->SetTimerInterval('Travel', max(1, $this->ReadPropertyInteger('TravelTime')) * 1000);
            if ($this->ReadPropertyInteger('ActuatorMode') === 2) {
                $this->SetValueIfChanged('Position', $this->ReadPropertyInteger($command === 'extend' ? 'PositionExtended' : 'PositionRetracted'));
            }
        }
        $this->SendDebug('Zustand', $command . ($manual ? ' (Hand)' : ' (Automatik)'), 0);
        return true;
    }

    private function CommandName(string $command): string
    {
        return match ($command) {
            'extend'  => $this->Translate('Extend'),
            'retract' => $this->Translate('Retract'),
            default   => $this->Translate('Stop'),
        };
    }

    private function FinishTravel(): void
    {
        $last = $this->ReadAttributeString('LastCommand');
        if ($last === 'extend') {
            $this->SetValueIfChanged('State', self::STATE_EXTENDED);
        } elseif ($last === 'retract') {
            $this->SetValueIfChanged('State', self::STATE_RETRACTED);
        }
        $this->PushTileState();
    }

    private function WriteManualUntil(int $until): void
    {
        $this->WriteAttributeInteger('ManualUntil', $until);
        if ($this->ReadPropertyInteger('ManualPauseMinutes') > 0) {
            $this->SetValueIfChanged('ManualUntil', $until);
        }
    }

    // =====================================================================
    // Sensoren
    // =====================================================================

    /**
     * Wert eines Sensors als Zahl; null, wenn nicht eingestellt oder nicht vorhanden.
     */
    protected function ReadSensor(string $property, bool $real = false): ?float
    {
        if (!$real && $this->Simulating()) {
            $simulated = $this->SimSensor($property);
            if ($simulated !== null) {
                return $simulated;
            }
        }
        $id = $property === 'WarningVariableID' ? $this->WarningID() : $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $value = GetValue($id);
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $v = (float) $value;
        return is_finite($v) ? $v : null;
    }

    /**
     * Windsensor(en) liefern seit der eingestellten Zeit keine Werte mehr.
     * Es genügt, wenn einer der beiden (Wind oder Böe) noch aktuell ist.
     */
    private function SensorsStale(array $ids, int $now): bool
    {
        $timeout = $this->ReadPropertyInteger('WindTimeoutMinutes') * 60;
        if ($timeout <= 0) {
            return false;
        }
        $newest = 0;
        $any = false;
        foreach ($ids as $id) {
            if ($id > 0 && IPS_VariableExists($id)) {
                $any = true;
                $newest = max($newest, (int) (IPS_GetVariable($id)['VariableUpdated'] ?? 0));
            }
        }
        return $any && $newest > 0 && ($now - $newest) > $timeout;
    }

    /**
     * Helligkeitssensor eingefroren: tagsüber seit X Minuten keine Änderung um mindestens Y lx.
     * Nachts wird nicht geprüft (Helligkeit ist dann zu Recht konstant).
     */
    private function BrightnessFrozen(?float $lux, int $now, bool $isDay): bool
    {
        $limit = $this->ReadPropertyInteger('FreezeMinutes') * 60;
        if ($lux === null || $limit <= 0) {
            return false;
        }
        $lastValue = $this->ReadAttributeFloat('BrightLastValue');
        $lastChange = $this->ReadAttributeInteger('BrightLastChange');

        if (!$isDay || $lastChange === 0 || abs($lux - $lastValue) >= max(0.0, $this->ReadPropertyFloat('FreezeMinChange'))) {
            $this->WriteAttributeFloat('BrightLastValue', $lux);
            $this->WriteAttributeInteger('BrightLastChange', $now);
            return false;
        }
        return ($now - $lastChange) > $limit;
    }

    private function InTimeWindow(int $now): bool
    {
        if (!$this->ReadPropertyBoolean('UseTimeWindow')) {
            return true;
        }
        $from = $this->TimeToMinutes($this->ReadPropertyString('TimeFrom'));
        $to = $this->TimeToMinutes($this->ReadPropertyString('TimeTo'));
        $m = (int) date('G', $now) * 60 + (int) date('i', $now);
        return $from <= $to ? ($m >= $from && $m < $to) : ($m >= $from || $m < $to);
    }

    private function TimeToMinutes(string $json): int
    {
        $t = json_decode($json, true);
        if (!is_array($t)) {
            return 0;
        }
        return max(0, min(23, (int) ($t['hour'] ?? 0))) * 60 + max(0, min(59, (int) ($t['minute'] ?? 0)));
    }

    // =====================================================================
    // Variablen, Konfiguration, Einstellungen
    // =====================================================================

    private function MaintainVariables(): void
    {
        $this->MaintainVariable('Automatic', $this->Translate('Automatic'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'wand-magic-sparkles',
            'USAGE_TYPE'   => 0,
        ], 10, true);
        $this->EnableAction('Automatic');

        $hold = $this->ReadPropertyBoolean('HoldEnabled');
        $this->MaintainVariable('Hold', $this->Translate('Hold (evening mode)'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'moon-stars',
            'ICON_FALSE'   => 'moon',
            'USAGE_TYPE'   => 0,
        ], 15, $hold);
        if ($hold) {
            $this->EnableAction('Hold');
        }

        $canStop = $this->CanStop();
        $options = [
            ['Value' => self::CTRL_RETRACT, 'Caption' => $this->Translate('Retract'), 'IconActive' => true, 'IconValue' => 'arrow-up', 'Color' => -1],
        ];
        if ($canStop) {
            $options[] = ['Value' => self::CTRL_STOP, 'Caption' => $this->Translate('Stop'), 'IconActive' => true, 'IconValue' => 'stop', 'Color' => -1];
        }
        $options[] = ['Value' => self::CTRL_EXTEND, 'Caption' => $this->Translate('Extend'), 'IconActive' => true, 'IconValue' => 'arrow-down', 'Color' => -1];
        $this->MaintainVariable('Control', $this->Translate('Awning'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'sun-bright',
            'LAYOUT'       => 1,
            'DISPLAY'      => 2,
            'OPTIONS'      => json_encode($options),
        ], 20, true);
        $this->EnableAction('Control');

        $hasPosition = $this->ReadPropertyInteger('ActuatorMode') === 2;
        $this->MaintainVariable('Position', $this->Translate('Position'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'arrows-up-down',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 5,
            'SUFFIX'       => ' %',
            'USAGE_TYPE'   => 5,
        ], 25, $hasPosition);
        if ($hasPosition) {
            $this->EnableAction('Position');
        }

        $this->MaintainVariable('State', $this->Translate('State'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'sun-bright',
            'DISPLAY'      => 2,
            'OPTIONS'      => json_encode([
                ['Value' => self::STATE_RETRACTED, 'Caption' => $this->Translate('retracted'), 'IconActive' => true, 'IconValue' => 'arrow-up-to-line', 'Color' => -1],
                ['Value' => self::STATE_RETRACTING, 'Caption' => $this->Translate('retracting'), 'IconActive' => true, 'IconValue' => 'arrow-up', 'Color' => 0x1677FF],
                ['Value' => self::STATE_EXTENDING, 'Caption' => $this->Translate('extending'), 'IconActive' => true, 'IconValue' => 'arrow-down', 'Color' => 0x1677FF],
                ['Value' => self::STATE_EXTENDED, 'Caption' => $this->Translate('extended'), 'IconActive' => true, 'IconValue' => 'sun', 'Color' => 0xF59E0B],
                ['Value' => self::STATE_STOPPED, 'Caption' => $this->Translate('stopped'), 'IconActive' => true, 'IconValue' => 'pause', 'Color' => -1],
            ]),
        ], 30, true);

        $this->MaintainVariable('Status', $this->Translate('Status'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'circle-info',
            'DISPLAY'      => 2,
            'OPTIONS'      => json_encode($this->StatusOptions()),
        ], 40, true);

        $this->MaintainVariable('Reason', $this->Translate('Last decision'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'list-check',
        ], 50, true);

        $this->MaintainVariable('Safety', $this->Translate('Safety'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $this->Translate('OK'), 'IconActive' => true, 'IconValue' => 'shield-check', 'ColorActive' => false, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => $this->Translate('Alarm'), 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xDC2626],
            ]),
        ], 60, true);

        $this->MaintainVariable('ManualUntil', $this->Translate('Manual operation until'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 70, $this->ReadPropertyInteger('ManualPauseMinutes') > 0);

        $sun = $this->ReadPropertyBoolean('ShowSunPosition');
        $this->MaintainVariable('SunAzimuth', $this->Translate('Sun azimuth'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'compass', 'SUFFIX' => '°', 'DIGITS' => 1,
        ], 80, $sun);
        $this->MaintainVariable('SunElevation', $this->Translate('Sun elevation'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'sun', 'SUFFIX' => '°', 'DIGITS' => 1,
        ], 81, $sun);

        $this->MaintainSettings();

        // Startwert der Automatik: beim ersten Anlegen an
        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->WriteAttributeBoolean('Initialized', true);
            $this->SetValue('Automatic', true);
        }
    }

    private function StatusOptions(): array
    {
        $o = static function (int $v, string $caption, string $icon, int $color): array {
            return ['Value' => $v, 'Caption' => $caption, 'IconActive' => true, 'IconValue' => $icon, 'Color' => $color];
        };
        return [
            $o(self::ST_OFF, $this->Translate('Automatic off'), 'pause', 0x7C3AED),
            $o(self::ST_WAITING, $this->Translate('Waiting for sun'), 'cloud-sun', -1),
            $o(self::ST_SUN, $this->Translate('Sun protection active'), 'sun', 0x16A34A),
            $o(self::ST_MANUAL, $this->Translate('Manual operation'), 'hand', 0x1677FF),
            $o(self::ST_GRACE, $this->Translate('Absent – grace period'), 'hourglass-half', 0x1677FF),
            $o(self::ST_ABSENT, $this->Translate('Absent'), 'house-person-leave', 0x0F766E),
            $o(self::ST_WEEKDAY, $this->Translate('Day not released'), 'calendar-xmark', 0x7C3AED),
            $o(self::ST_NIGHT, $this->Translate('Night'), 'moon', 0x64748B),
            $o(self::ST_TIME, $this->Translate('Outside the time window'), 'clock', 0x64748B),
            $o(self::ST_WIND, $this->Translate('Wind alarm'), 'wind', 0xDC2626),
            $o(self::ST_RAIN, $this->Translate('Rain'), 'cloud-rain', 0xDC2626),
            $o(self::ST_FROST, $this->Translate('Frost'), 'snowflake', 0xDC2626),
            $o(self::ST_SENSOR, $this->Translate('Sensor fault'), 'sensor-triangle-exclamation', 0xDC2626),
            $o(self::ST_HOLD, $this->Translate('Hold (evening mode)'), 'moon-stars', 0x6366F1),
            $o(self::ST_WARNING, $this->Translate('Weather warning'), 'triangle-exclamation', 0xDC2626),
        ];
    }

    /**
     * Grenzwerte und Wochentage als bedienbare Variablen (optional).
     * Änderungen landen in den Eigenschaften der Instanz – eine einzige Quelle der Wahrheit.
     */
    private function MaintainSettings(): void
    {
        $show = $this->ReadPropertyBoolean('ShowSettings');
        $wu = $this->ReadPropertyInteger('WindUnit');
        $gu = $this->ReadPropertyInteger('GustUnit');
        $slider = static function (string $icon, float $min, float $max, float $step, string $suffix, int $digits): array {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => $icon, 'MIN' => $min, 'MAX' => $max,
                'STEP_SIZE' => $step, 'SUFFIX' => $suffix, 'DIGITS' => $digits, 'USAGE_TYPE' => 5,
            ];
        };
        $switch = ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'USAGE_TYPE' => 0];

        $defs = [
            'SetLuxOn'     => [$this->Translate('Brightness to extend'), $slider('brightness', 0, 120000, 1000, ' lx', 0), 100],
            'SetTempMin'   => [$this->Translate('Minimum temperature'), $slider('temperature-half', -10, 40, 0.5, ' °C', 1), 101],
            'SetWindMax'   => [$this->Translate('Wind limit for sun protection'), $slider('wind', 0, self::UnitMax($wu), self::UnitStep($wu), $this->UnitSuffix($wu), $wu === 0 ? 0 : 1), 102],
            'SetWindAlarm' => [$this->Translate('Wind alarm'), $slider('wind', 0, self::UnitMax($wu), self::UnitStep($wu), $this->UnitSuffix($wu), $wu === 0 ? 0 : 1), 103],
            'SetGustAlarm' => [$this->Translate('Gust alarm'), $slider('wind', 0, self::UnitMax($gu), self::UnitStep($gu), $this->UnitSuffix($gu), $gu === 0 ? 0 : 1), 104],
            'SetDayCheck'  => [$this->Translate('Day/night check'), $switch + ['ICON_TRUE' => 'sun-horizon'], 105],
        ];
        foreach (self::WEEKDAYS as $d => $name) {
            $defs['SetWeekday' . $d] = [$this->Translate($name), $switch + ['ICON_TRUE' => 'calendar-day'], 110 + $d];
        }

        foreach ($defs as $ident => [$name, $presentation, $pos]) {
            [$property, $type] = self::SETTINGS[$ident];
            $this->MaintainVariable($ident, $name, $type, $presentation, $pos, $show);
            if (!$show) {
                continue;
            }
            $this->EnableAction($ident);
            $value = match ($type) {
                VARIABLETYPE_BOOLEAN => $this->ReadPropertyBoolean($property),
                VARIABLETYPE_INTEGER => $this->ReadPropertyInteger($property),
                default              => $this->ReadPropertyFloat($property),
            };
            $this->SetValueIfChanged($ident, $value);
        }
    }

    /**
     * Einstellung aus der Visualisierung übernehmen: Wert prüfen, in die Eigenschaft schreiben, übernehmen.
     */
    private function ChangeSetting(string $ident, mixed $value): void
    {
        if (!$this->ReadPropertyBoolean('ShowSettings')) {
            throw new InvalidArgumentException('Einstellungen in der Visualisierung sind ausgeschaltet.');
        }
        [$property, $type] = self::SETTINGS[$ident];
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                $value = (bool) $value;
                break;
            case VARIABLETYPE_INTEGER:
                $value = max(0, min(200000, (int) $value));
                break;
            default:
                $value = (float) $value;
                if (!is_finite($value)) {
                    throw new InvalidArgumentException('Ungültiger Wert');
                }
                $value = round(max(-50.0, min(500.0, $value)), 1);
        }
        IPS_SetProperty($this->InstanceID, $property, $value);
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Prüft die Einstellungen und liefert den Instanzstatus.
     */
    private function CheckConfiguration(): int
    {
        $mode = $this->ReadPropertyInteger('ActuatorMode');
        $required = match ($mode) {
            0       => ['ExtendVariableID', 'RetractVariableID'],
            1       => ['SwitchVariableID'],
            2       => ['PositionVariableID'],
            default => [],
        };
        if ($required === []) {
            return 200;
        }
        foreach ($required as $prop) {
            if ($this->ReadPropertyInteger($prop) <= 0) {
                return 200;
            }
        }
        $actuators = $required;
        if ($this->ReadPropertyInteger('StopVariableID') > 0 && $mode !== 1) {
            $actuators[] = 'StopVariableID';
        }
        foreach ($actuators as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if (!IPS_VariableExists($id) || !HasAction($id)) {
                $this->SendDebug('Konfiguration', $prop . ' (' . $id . ') fehlt oder hat keine Aktion', 0);
                return 201;
            }
        }
        foreach (['BrightnessVariableID', 'TemperatureVariableID', 'WindVariableID', 'GustVariableID', 'RainVariableID', 'PresenceVariableID', 'DoorVariableID', 'WarningVariableID'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && !IPS_VariableExists($id)) {
                $this->SendDebug('Konfiguration', $prop . ' (' . $id . ') existiert nicht', 0);
                return 202;
            }
        }
        if ($this->ReadPropertyInteger('LuxOff') > $this->ReadPropertyInteger('LuxOn')) {
            return 203;
        }
        return 102;
    }

    /**
     * Meldet alle Sensoren, die Anwesenheit und die Aktorvariablen zur Überwachung an
     * und trägt sie als Referenz ein. Alte Anmeldungen werden entfernt.
     */
    private function WatchVariables(): void
    {
        foreach (json_decode($this->ReadAttributeString('Watched'), true) ?: [] as $id) {
            $this->UnregisterMessage((int) $id, VM_UPDATE);
            $this->UnregisterReference((int) $id);
        }
        $ids = [];
        foreach (['BrightnessVariableID', 'TemperatureVariableID', 'WindVariableID', 'GustVariableID', 'RainVariableID', 'PresenceVariableID', 'DoorVariableID'] as $prop) {
            $ids[] = $this->ReadPropertyInteger($prop);
        }
        $ids[] = $this->WarningID();
        $ids = array_merge($ids, array_keys($this->ActuatorWatchList()));
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        foreach ($ids as $id) {
            if (IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
                $this->RegisterReference($id);
            }
        }
        $this->WriteAttributeString('Watched', json_encode($ids));
    }

    // =====================================================================
    // Formular
    // =====================================================================

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $mode = $this->ReadPropertyInteger('ActuatorMode');
        $unitOptions = [
            ['caption' => 'Bft', 'value' => 0],
            ['caption' => 'km/h', 'value' => 1],
            ['caption' => 'm/s', 'value' => 2],
        ];
        $visible = $this->ModeVisibility($mode);
        $visible['LocationHint'] = $this->Location() === null;
        $sun = $this->SunNow($this->Now());
        $sunText = $sun === null ? '' : sprintf(
            $this->Translate('Sun now: direction %s°, height %s°'),
            $this->Num($sun['azimuth']),
            $this->Num($sun['elevation'])
        );
        $windSuffix = trim($this->UnitSuffix($this->ReadPropertyInteger('WindUnit')));
        $gustSuffix = trim($this->UnitSuffix($this->ReadPropertyInteger('GustUnit')));

        // Woher kommt der Standort?
        $own = self::ValidLocation($this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'));
        $module = $this->ModuleLocation();
        $captions = [
            'LocationSource' => $own !== null
                ? sprintf($this->Translate('Used: own location %s / %s'), $this->Coord($own[0]), $this->Coord($own[1]))
                : ($module !== null ? sprintf($this->Translate('Used: location module %s / %s'), $this->Coord($module[0]), $this->Coord($module[1])) : ''),
        ];
        if ($this->ReadPropertyBoolean('UseWarning')) {
            $warn = $this->WarningID();
            $captions['WarningInfo'] = $this->Translate('Levels: 1 = weather warning, 2 = significant weather, 3 = severe weather, 4 = extreme weather. Heat and UV warnings are ignored. If the warning source fails, the awning is not blocked.')
                . ' ' . ($warn > 0 ? sprintf($this->Translate('Warning level in use: #%d'), $warn) : $this->Translate('No warning level found yet.'));
        }
        $form['elements'] = $this->WalkForm($form['elements'], static function (array $el) use ($visible, $unitOptions, $windSuffix, $gustSuffix, $captions): array {
            $name = $el['name'] ?? '';
            if (isset($visible[$name])) {
                $el['visible'] = $visible[$name];
            }
            if ($name === 'WindAlarm' || $name === 'WindMax') {
                $el['suffix'] = $windSuffix;
            }
            if ($name === 'GustAlarm' || $name === 'GustTrendRise') {
                $el['suffix'] = $gustSuffix;
            }
            if (isset($captions[$name])) {
                $el['caption'] = $captions[$name];
            }
            if ($name === 'WindUnit' || $name === 'GustUnit') {
                $el['options'] = $unitOptions;
            }
            return $el;
        });
        foreach ($form['actions'] as $i => $el) {
            if (($el['name'] ?? '') === 'SunNow') {
                $form['actions'][$i]['caption'] = $sunText;
                $form['actions'][$i]['visible'] = $sunText !== '';
            }
        }
        return json_encode($form);
    }

    /**
     * Sichtbarkeit der Felder je nach Art der Ansteuerung.
     *
     * @return array<string, bool>
     */
    private function ModeVisibility(int $mode): array
    {
        return [
            'ExtendVariableID'   => $mode === 0,
            'RetractVariableID'  => $mode === 0,
            'StopVariableID'     => $mode !== 1,
            'SwitchVariableID'   => $mode === 1,
            'SwitchInvert'       => $mode === 1,
            'PositionVariableID' => $mode === 2,
            'PositionScale'      => $mode === 2,
            'PositionExtended'   => $mode === 2,
            'PositionRetracted'  => $mode === 2,
        ];
    }

    private function WalkForm(array $elements, callable $fn): array
    {
        foreach ($elements as $i => $el) {
            if (!is_array($el)) {
                continue;
            }
            $el = $fn($el);
            if (isset($el['items']) && is_array($el['items'])) {
                $el['items'] = $this->WalkForm($el['items'], $fn);
            }
            $elements[$i] = $el;
        }
        return $elements;
    }

    // =====================================================================
    // Hilfsfunktionen
    // =====================================================================

    /** Aktuelle Zeit (in den Tests überschreibbar) */
    protected function Now(): int
    {
        return time();
    }

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function UnitSuffix(int $unit): string
    {
        return [0 => ' Bft', 1 => ' km/h', 2 => ' m/s'][$unit] ?? '';
    }

    private static function UnitMax(int $unit): float
    {
        return [0 => 12.0, 1 => 150.0, 2 => 40.0][$unit] ?? 100.0;
    }

    private static function UnitStep(int $unit): float
    {
        return [0 => 1.0, 1 => 1.0, 2 => 0.5][$unit] ?? 1.0;
    }

    private function Thousands(float $v): string
    {
        $dec = $this->Translate('.');
        return number_format($v, 0, $dec, $dec === ',' ? '.' : ',');
    }

    private function Coord(float $v): string
    {
        return number_format($v, 4, $this->Translate('.'), '');
    }

    private function Num(float $v): string
    {
        $digits = abs($v - round($v)) < 0.05 ? 0 : 1;
        return number_format($v, $digits, $this->Translate('.'), '');
    }
}
