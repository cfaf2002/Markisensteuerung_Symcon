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
 * Ansteuerung des Markisenmotors über vorhandene Symcon-Variablen.
 *
 * Arten:
 *  0 = Befehlsvariablen (Ausfahren / Einfahren / optional Stopp), z. B. Somfy RTS über ein Gateway
 *  1 = Schaltvariable (Boolean: an = ausfahren)
 *  2 = Positionsvariable (Prozent)
 */
trait MarkiseActuatorTrait
{
    /** Zeitfenster, in dem Rückmeldungen der Aktorvariablen als eigene Befehle gelten (Sekunden) */
    private const OWN_WINDOW_TRIGGER = 4;

    /**
     * Schickt einen Befehl an den Motor.
     *
     * @param string $command extend | retract | stop
     */
    protected function SendCommand(string $command): bool
    {
        $target = $this->CommandTarget($command);
        if ($target === null) {
            $this->SendDebug('Befehl', $command . ': keine passende Aktorvariable eingestellt', 0);
            return false;
        }
        [$id, $value] = $target;

        if (!IPS_VariableExists($id)) {
            $this->SendDebug('Befehl', $command . ': Variable ' . $id . ' existiert nicht', 0);
            return false;
        }
        if (!HasAction($id)) {
            $this->SendDebug('Befehl', $command . ': Variable ' . $id . ' hat keine Aktion', 0);
            return false;
        }

        // Rückmeldungen der Aktorvariablen in diesem Zeitfenster stammen von uns, nicht von Hand
        $window = $this->ReadPropertyInteger('ActuatorMode') === 2
            ? max(10, $this->ReadPropertyInteger('TravelTime') + 10)
            : self::OWN_WINDOW_TRIGGER;
        $this->WriteAttributeInteger('OwnCommandUntil', $this->Now() + $window);

        $ok = false;
        try {
            $ok = @RequestAction($id, $value) !== false;
        } catch (Throwable $e) {
            $this->SendDebug('Befehl', $e->getMessage(), 0);
        }
        $this->SendDebug('Befehl', $command . ' → ' . $id . ' = ' . json_encode($value) . ($ok ? ' (ok)' : ' (fehlgeschlagen)'), 0);
        return $ok;
    }

    /**
     * Variable und Wert für einen Befehl; null, wenn die Ansteuerung den Befehl nicht kann.
     *
     * @return array{0: int, 1: mixed}|null
     */
    private function CommandTarget(string $command): ?array
    {
        $mode = $this->ReadPropertyInteger('ActuatorMode');
        $stopID = $this->ReadPropertyInteger('StopVariableID');

        if ($command === 'stop') {
            return $stopID > 0 && $mode !== 1 ? [$stopID, true] : null;
        }

        switch ($mode) {
            case 0:
                $id = $this->ReadPropertyInteger($command === 'extend' ? 'ExtendVariableID' : 'RetractVariableID');
                return $id > 0 ? [$id, true] : null;

            case 1:
                $id = $this->ReadPropertyInteger('SwitchVariableID');
                if ($id <= 0) {
                    return null;
                }
                $on = ($command === 'extend') !== $this->ReadPropertyBoolean('SwitchInvert');
                return [$id, $on];

            case 2:
                $id = $this->ReadPropertyInteger('PositionVariableID');
                if ($id <= 0) {
                    return null;
                }
                $percent = $command === 'extend' ? $this->ReadPropertyInteger('PositionExtended') : $this->ReadPropertyInteger('PositionRetracted');
                return [$id, $this->PercentToActuator($id, $percent)];
        }
        return null;
    }

    /**
     * Prozentwert in den Wertebereich der Positionsvariable umrechnen (0–100 oder 0–1).
     */
    private function PercentToActuator(int $id, int $percent): int|float
    {
        $percent = max(0, min(100, $percent));
        if ($this->ReadPropertyInteger('PositionScale') === 1) {
            return round($percent / 100, 2);
        }
        $type = IPS_GetVariable($id)['VariableType'] ?? VARIABLETYPE_INTEGER;
        return $type === VARIABLETYPE_FLOAT ? (float) $percent : $percent;
    }

    /**
     * Positionswert der Aktorvariable in Prozent.
     */
    private function ActuatorToPercent(mixed $value): int
    {
        $v = (float) $value;
        if ($this->ReadPropertyInteger('PositionScale') === 1) {
            $v *= 100;
        }
        return (int) round(max(0, min(100, $v)));
    }

    /**
     * Alle Aktorvariablen, die auf Bedienung von Hand überwacht werden.
     *
     * @return array<int, string> ID => Rolle (extend | retract | stop | switch | position)
     */
    protected function ActuatorWatchList(): array
    {
        $list = [];
        switch ($this->ReadPropertyInteger('ActuatorMode')) {
            case 0:
                $list[$this->ReadPropertyInteger('ExtendVariableID')] = 'extend';
                $list[$this->ReadPropertyInteger('RetractVariableID')] = 'retract';
                $list[$this->ReadPropertyInteger('StopVariableID')] = 'stop';
                break;
            case 1:
                $list[$this->ReadPropertyInteger('SwitchVariableID')] = 'switch';
                break;
            case 2:
                $list[$this->ReadPropertyInteger('PositionVariableID')] = 'position';
                $list[$this->ReadPropertyInteger('StopVariableID')] = 'stop';
                break;
        }
        unset($list[0]);
        return $list;
    }

    /**
     * Wertet eine Änderung an einer Aktorvariable aus.
     * Liefert extend | retract | stop, wenn sie von Hand (Fernbedienung, andere Skripte) kam, sonst null.
     *
     * @param array $data Data aus VM_UPDATE: [Wert, geändert, alter Wert, Zeitstempel]
     */
    protected function DetectManual(string $role, array $data): ?string
    {
        if ($this->Now() <= $this->ReadAttributeInteger('OwnCommandUntil')) {
            return null; // Rückmeldung auf eigenen Befehl
        }
        $value = $data[0] ?? null;
        $changed = (bool) ($data[1] ?? true);

        switch ($role) {
            case 'extend':
            case 'retract':
            case 'stop':
                // Tastervariablen: nur das Auslösen zählt, nicht das Zurücksetzen auf false/0
                return $value ? $role : null;

            case 'switch':
                if (!$changed) {
                    return null;
                }
                return ((bool) $value !== $this->ReadPropertyBoolean('SwitchInvert')) ? 'extend' : 'retract';

            case 'position':
                if (!$changed) {
                    return null; // zyklische Statusmeldung ohne Bewegung
                }
                $percent = $this->ActuatorToPercent($value);
                $ext = $this->ReadPropertyInteger('PositionExtended');
                $ret = $this->ReadPropertyInteger('PositionRetracted');
                return abs($percent - $ret) <= abs($percent - $ext) ? 'retract' : 'extend';
        }
        return null;
    }

    /**
     * Ob die Ansteuerung einen Stopp kennt.
     */
    protected function CanStop(): bool
    {
        return $this->CommandTarget('stop') !== null;
    }
}
