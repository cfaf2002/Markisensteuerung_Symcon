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
 * Grundwerte für die Einstellungs-Kachel (Modul „Markisen-Einstellungen“).
 *
 * Nur die hier aufgeführten Eigenschaften lassen sich von außen ändern. Jeder Wert wird
 * auf Typ und Bereich geprüft, bevor er gespeichert wird; die Eigenschaften der Instanz
 * bleiben die einzige Stelle, an der ein Wert steht.
 */
trait MarkiseParameterTrait
{
    /**
     * Gruppe => [Eigenschaft => [Typ, Beschriftung, min, max, Schritt, Einheit]]
     * Typen: int, float, bool, time, presence (Schalter der Anwesenheitsvariable);
     * Einheit 'wind'/'gust' = Einheit des jeweiligen Sensors
     */
    private const PARAMETERS = [
        'Sun protection' => [
            'LuxOn'           => ['int', 'Extend from', 0, 120000, 1000, 'lx'],
            'LuxOff'          => ['int', 'Retract below', 0, 120000, 1000, 'lx'],
            'TempMin'         => ['float', 'Minimum temperature', -10, 40, 0.5, '°C'],
            'WindMax'         => ['float', 'Wind limit for sun protection', 0, 0, 0, 'wind'],
            'DelayOn'         => ['int', 'Extend after sun for', 0, 120, 1, 'min'],
            'DelayOff'        => ['int', 'Retract after no sun for', 0, 240, 1, 'min'],
        ],
        'Safety' => [
            'WindAlarm'       => ['float', 'Wind alarm from', 0, 0, 0, 'wind'],
            'GustAlarm'       => ['float', 'Gust alarm from', 0, 0, 0, 'gust'],
            'WarningMinLevel' => ['int', 'Retract from level', 1, 4, 1, ''],
        ],
        'Times' => [
            'DayCheck'        => ['bool', 'Day/night check: retract at night', 0, 1, 1, ''],
            'UseTimeWindow'   => ['bool', 'Only within a time window', 0, 1, 1, ''],
            'TimeFrom'        => ['time', 'Time window from', 0, 0, 0, ''],
            'TimeTo'          => ['time', 'Time window to', 0, 0, 0, ''],
        ],
        'Presence' => [
            'Presence'        => ['presence', 'Someone at home', 0, 1, 1, ''],
            'GraceMinutes'    => ['int', 'Grace period after leaving', 0, 240, 5, 'min'],
        ],
    ];

    private const WEEKDAY_SHORT = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * Alle Grundwerte mit Beschriftung, Bereich und aktuellem Wert (JSON) – für die Einstellungs-Kachel.
     */
    public function GetParameters(): string
    {
        $groups = [];
        foreach (self::PARAMETERS as $group => $items) {
            $list = [];
            foreach ($items as $name => $def) {
                if ($name === 'WarningMinLevel' && !$this->ReadPropertyBoolean('UseWarning')) {
                    continue;
                }
                if ($name === 'Presence') {
                    $presence = $this->PresenceItem();
                    if ($presence !== null) {
                        $list[] = $presence;
                    }
                    continue;
                }
                [$type, $label, $min, $max, $step, $unit] = $this->ParameterDefinition($name, $def);
                $list[] = [
                    'name'  => $name,
                    'type'  => $type,
                    'label' => $this->Translate($label),
                    'min'   => $min,
                    'max'   => $max,
                    'step'  => $step,
                    'unit'  => $unit,
                    'value' => $this->ParameterValue($name, $type),
                ];
            }
            $groups[] = ['key' => $group, 'title' => $this->Translate($group), 'items' => $list];
        }
        $weekdays = [];
        foreach (self::WEEKDAY_SHORT as $d => $short) {
            $weekdays[] = ['name' => 'Weekday' . $d, 'label' => $this->Translate($short), 'value' => $this->ReadPropertyBoolean('Weekday' . $d)];
        }
        return (string) json_encode([
            'instance'      => IPS_GetName($this->InstanceID),
            'status'        => $this->GetStatus(),
            'groups'        => $groups,
            'weekdays'      => $weekdays,
            'weekdaysTitle' => $this->Translate('Released weekdays'),
            // Variablen, deren Änderung die Einstellungs-Kachel neu zeichnen soll
            'watch'         => array_values(array_filter([$this->PresenceTargetID()], static fn (int $id): bool => $id > 0)),
        ]);
    }

    /**
     * Schalter für die Anwesenheit: bedient die eingestellte Anwesenheitsvariable
     * (in der Simulation die Simulationsvariable). Ohne Anwesenheitsvariable gibt es keinen Schalter.
     */
    private function PresenceItem(): ?array
    {
        $id = $this->ReadPropertyInteger('PresenceVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $target = $this->PresenceTargetID();
        $sim = $target !== $id;
        return [
            'name'     => 'Presence',
            'type'     => 'bool',
            'label'    => IPS_GetName($id) . ($sim ? ' (' . $this->Translate('Simulation') . ')' : ''),
            'min'      => 0,
            'max'      => 1,
            'step'     => 1,
            'unit'     => '',
            'value'    => (bool) GetValue($target),
            // Ohne Aktion lässt sich die Variable nicht schalten – dann nur anzeigen
            'readOnly' => !$sim && !$this->PresenceDirect($id) && !HasAction($id),
        ];
    }

    /** Variable, die der Anwesenheitsschalter bedient: echte Variable oder in der Simulation die Simulationsvariable */
    private function PresenceTargetID(): int
    {
        $id = $this->ReadPropertyInteger('PresenceVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return 0;
        }
        if ($this->Simulating()) {
            $sim = @$this->GetIDForIdent('SimPresence');
            if (is_int($sim) && $sim > 0) {
                return $sim;
            }
        }
        return $id;
    }

    /** Anwesenheit schalten – über die Aktion der Variable, wie ein Taster in der Visualisierung */
    private function SetPresence(mixed $value): void
    {
        $on = self::ToBool($value);
        $id = $this->ReadPropertyInteger('PresenceVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            throw new InvalidArgumentException('Keine Anwesenheitsvariable eingestellt.');
        }
        if ($this->PresenceTargetID() !== $id) {
            $this->SetSimValue('SimPresence', $on);
            return;
        }
        if ($this->PresenceDirect($id)) {
            SetValue($id, $on);
            return;
        }
        if (!HasAction($id)) {
            throw new InvalidArgumentException('Die Anwesenheitsvariable hat keine Aktion.');
        }
        RequestAction($id, $on);
    }

    /**
     * Liegt die Anwesenheitsvariable unter dieser Instanz und hat keine eigene Aktion, landet ihre
     * Standardaktion bei dieser Instanz statt bei der Variable. Dann wird der Wert direkt gesetzt.
     */
    private function PresenceDirect(int $id): bool
    {
        if (IPS_GetParent($id) !== $this->InstanceID) {
            return false;
        }
        return (int) (IPS_GetVariable($id)['VariableCustomAction'] ?? 0) <= 0;
    }

    /** Standardaktion einer unter die Instanz verschobenen Anwesenheitsvariable (Objektbaum, Visualisierung) */
    private function PresenceActionFor(string $ident): ?int
    {
        $id = $this->ReadPropertyInteger('PresenceVariableID');
        if ($id <= 0 || !IPS_VariableExists($id) || !$this->PresenceDirect($id)) {
            return null;
        }
        return IPS_GetObject($id)['ObjectIdent'] === $ident ? $id : null;
    }

    /**
     * Ändert einen Grundwert. Unbekannte Namen und ungültige Werte werden abgelehnt.
     */
    public function SetParameter(string $Name, mixed $Value): bool
    {
        if ($Name === 'Presence') {
            // keine Einstellung, sondern der aktuelle Zustand – wird nicht als Eigenschaft gespeichert
            $this->SetPresence($Value);
            return true;
        }
        $changes = $this->ValidateParameter($Name, $Value);
        foreach ($changes as $property => $value) {
            IPS_SetProperty($this->InstanceID, $property, $value);
        }
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    /**
     * Prüft einen Wert und liefert die zu speichernden Eigenschaften (inklusive abhängiger Werte).
     *
     * @return array<string, mixed>
     */
    private function ValidateParameter(string $name, mixed $value): array
    {
        if (preg_match('/^Weekday([1-7])$/', $name)) {
            return [$name => self::ToBool($value)];
        }
        $def = null;
        foreach (self::PARAMETERS as $items) {
            if (isset($items[$name])) {
                $def = $items[$name];
            }
        }
        if ($def === null) {
            throw new InvalidArgumentException('Unbekannter Grundwert: ' . $name);
        }
        [$type, , $min, $max] = $this->ParameterDefinition($name, $def);

        switch ($type) {
            case 'bool':
                return [$name => self::ToBool($value)];

            case 'time':
                if (!is_string($value) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m)) {
                    throw new InvalidArgumentException('Uhrzeit bitte als HH:MM.');
                }
                return [$name => json_encode(['hour' => (int) $m[1], 'minute' => (int) $m[2], 'second' => 0])];

            default:
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new InvalidArgumentException('Ungültiger Wert für ' . $name);
                }
                $v = max((float) $min, min((float) $max, (float) $value));
                $v = $type === 'int' ? (int) round($v) : round($v, 1);
                $changes = [$name => $v];
                // Einfahrgrenze nie über der Ausfahrgrenze (sonst Status 203)
                if ($name === 'LuxOn' && $v < $this->ReadPropertyInteger('LuxOff')) {
                    $changes['LuxOff'] = $v;
                } elseif ($name === 'LuxOff' && $v > $this->ReadPropertyInteger('LuxOn')) {
                    $changes['LuxOn'] = $v;
                }
                return $changes;
        }
    }

    /** Bereich und Einheit, bei Wind und Böen passend zur Einheit des Sensors */
    private function ParameterDefinition(string $name, array $def): array
    {
        [$type, $label, $min, $max, $step, $unit] = $def;
        if ($unit === 'wind' || $unit === 'gust') {
            $u = $this->ReadPropertyInteger($unit === 'wind' ? 'WindUnit' : 'GustUnit');
            $max = self::UnitMax($u);
            $step = self::UnitStep($u);
            $unit = trim($this->UnitSuffix($u));
        }
        return [$type, $label, $min, $max, $step, $unit];
    }

    private function ParameterValue(string $name, string $type): mixed
    {
        return match ($type) {
            'bool'  => $this->ReadPropertyBoolean($name),
            'int'   => $this->ReadPropertyInteger($name),
            'float' => $this->ReadPropertyFloat($name),
            'time'  => sprintf('%02d:%02d', intdiv($this->TimeToMinutes($this->ReadPropertyString($name)), 60), $this->TimeToMinutes($this->ReadPropertyString($name)) % 60),
            default => null,
        };
    }

    private static function ToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || (is_string($value) && in_array($value, ['0', '1'], true))) {
            return (bool) (int) $value;
        }
        throw new InvalidArgumentException('Ungültiger Schalterwert');
    }
}
