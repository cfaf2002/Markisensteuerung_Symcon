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
 * Kachel für die Kachel-Visualisierung (HTML-SDK).
 * Die Kachel bekommt nur Daten (JSON) und baut alles mit textContent auf – kein HTML aus Variablen.
 */
trait MarkiseTileTrait
{
    /**
     * Liefert das HTML der Kachel (wird von der Visualisierung einmal geladen).
     */
    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/../Markisensteuerung/tile.html');
        $initial = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        $initial['now'] = $this->Now();
        // Als Objekt eingesetzt; JSON_HEX_* verhindert, dass Werte wie "</script>" das Skript der Kachel beenden
        $json = json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', (string) $json, $html);
    }

    /**
     * Baut die Kacheldaten und sendet sie, wenn sich etwas geändert hat.
     * Ohne Bewertung (null) werden nur Zustand und Bedienung aktualisiert.
     */
    protected function PushTileState(?array $ctx = null, ?array $result = null): void
    {
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        $status = $this->GetStatus();

        $data['theme'] = $this->ReadPropertyInteger('TileTheme');
        $data['reduce'] = $this->ReadPropertyBoolean('TileReduceMotion');
        $data['instanceStatus'] = $status;
        $data['error'] = $this->ConfigError($status);
        $data['errorTitle'] = $status === 104 ? $this->Translate('Inactive') : $this->Translate('Not configured');
        $data['canStop'] = $this->CanStop();
        $data['hasPosition'] = $this->ReadPropertyInteger('ActuatorMode') === 2;
        $data['automatic'] = (bool) $this->GetValue('Automatic');
        $data['holdEnabled'] = $this->ReadPropertyBoolean('HoldEnabled') && @$this->GetIDForIdent('Hold') !== false;
        $data['hold'] = $data['holdEnabled'] && (bool) $this->GetValue('Hold');
        $data['simulation'] = $this->Simulating();
        $data['state'] = (int) $this->GetValue('State');
        $data['position'] = $data['hasPosition'] ? (int) $this->GetValue('Position') : null;
        $data['travel'] = max(1, $this->ReadPropertyInteger('TravelTime'));
        $data['manualUntil'] = $this->ReadAttributeInteger('ManualUntil');
        $lock = max($this->ReadAttributeInteger('WindLockUntil'), $this->ReadAttributeInteger('RainLockUntil'));
        $data['lockUntil'] = $lock > $this->Now() ? $lock : 0;

        if ($ctx !== null && $result !== null) {
            $data['status'] = $result['status'];
            $data['tone'] = self::Tone($result['status']);
            $data['reason'] = $result['reason'];
            $data['safety'] = $result['safety'] !== '';
            $data['present'] = $ctx['hasPresence'] ? $ctx['present'] : null;
            $data['graceEnd'] = $ctx['absentSince'] > 0 ? $ctx['absentSince'] + $ctx['graceTotal'] : 0;
            $data['graceTotal'] = $ctx['graceTotal'];
            // nur Tag/Nacht – der genaue Sonnenstand würde die Kachel jede Minute neu zeichnen
            $data['sun'] = $ctx['sun'] === null ? null : ['day' => $ctx['isDay']];
            $data['today'] = $this->Translate(self::WEEKDAYS[$ctx['weekday']]);
            $data['todayOn'] = $this->ReadPropertyBoolean('Weekday' . $ctx['weekday']);
            $data['sensors'] = $this->TileSensors($ctx);
        }
        $data['statusText'] = $this->StatusText((int) ($data['status'] ?? self::ST_WAITING));

        $json = json_encode($data);
        if ($json === $this->ReadAttributeString('TileData')) {
            return; // unverändert: nichts an die Visualisierung schicken
        }
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('UseTile')) {
            // Serverzeit mitschicken, damit Countdowns auch bei falsch gehender Tablet-Uhr stimmen
            $data['now'] = $this->Now();
            $this->UpdateVisualizationValue(json_encode($data));
        }
    }

    private function TileSensors(array $c): array
    {
        $list = [];
        $wu = $this->UnitSuffix($this->ReadPropertyInteger('WindUnit'));
        $gu = $this->UnitSuffix($this->ReadPropertyInteger('GustUnit'));
        if ($c['lux'] !== null) {
            // auf 100 lx gerundet: weniger Kachel-Updates bei unruhigem Sensor
            $round = static fn (float $v): float => $v >= 1000 ? round($v, -2) : round($v);
            $avg = $c['luxAvg'] ?? $c['lux'];
            $note = $c['luxFrozen'] ? $this->Translate('frozen') : '';
            if ($note === '' && abs($round($avg) - $round($c['lux'])) >= 100) {
                $note = '≥ ' . $this->Thousands((float) $this->ReadPropertyInteger('LuxOn')) . ' lx · Ø ' . $this->Thousands($round($avg)) . ' lx';
            }
            $list[] = [
                'k'     => 'lux',
                'label' => $this->Translate('Brightness'),
                'value' => $this->Thousands($round($c['lux'])) . ' lx',
                'limit' => '≥ ' . $this->Thousands((float) $this->ReadPropertyInteger('LuxOn')) . ' lx',
                // grün = hell genug zum Ausfahren, rot = so dunkel, dass eingefahren wird, grau = dazwischen (Hysterese: bleibt, wie es ist)
                'ok'    => $c['luxFrozen'] ? false : self::Band($avg, (float) $this->ReadPropertyInteger('LuxOn'), (float) min($this->ReadPropertyInteger('LuxOn'), $this->ReadPropertyInteger('LuxOff'))),
                'note'  => $note,
            ];
        }
        if ($c['temp'] !== null) {
            $list[] = [
                'k'     => 'temp',
                'label' => $this->Translate('Temperature'),
                'value' => $this->Num($c['temp']) . ' °C',
                'limit' => '≥ ' . $this->Num($this->ReadPropertyFloat('TempMin')) . ' °C',
                'ok'    => self::Band($c['temp'], $this->ReadPropertyFloat('TempMin'), $this->ReadPropertyFloat('TempMin') - max(0.0, $this->ReadPropertyFloat('TempHysteresis'))),
                'note'  => '',
            ];
        }
        if ($c['wind'] !== null) {
            $list[] = [
                'k'     => 'wind',
                'label' => $this->Translate('Wind'),
                'value' => $this->Num($c['wind']) . $wu,
                'limit' => '≤ ' . $this->Num($this->ReadPropertyFloat('WindMax')) . $wu . ', ' . $this->Translate('alarm') . ' ' . $this->Num($this->ReadPropertyFloat('WindAlarm')) . $wu,
                'ok'    => $c['windStale'] ? false : $c['wind'] <= $this->ReadPropertyFloat('WindMax'),
                'note'  => $c['windStale'] ? $this->Translate('no values') : '',
            ];
        }
        if ($c['gust'] !== null) {
            $list[] = [
                'k'     => 'gust',
                'label' => $this->Translate('Gusts'),
                'value' => $this->Num($c['gust']) . $gu,
                'limit' => '< ' . $this->Num($this->ReadPropertyFloat('GustAlarm')) . $gu,
                'ok'    => $c['gust'] < $this->ReadPropertyFloat('GustAlarm'),
                'note'  => '',
            ];
        }
        if ($c['rain'] !== null) {
            $list[] = [
                'k'     => 'rain',
                'label' => $this->Translate('Rain'),
                'value' => $c['rain'] > 0 ? $this->Translate('yes') : $this->Translate('no'),
                'limit' => '',
                'ok'    => $c['rain'] <= 0,
                'note'  => '',
            ];
        }
        if ($c['warning'] !== null) {
            $min = $this->ReadPropertyInteger('WarningMinLevel');
            $active = $c['warning'] >= $min && $c['warning'] < 10;
            $list[] = [
                'k'     => 'warning',
                'label' => $this->Translate('Weather warning'),
                'value' => $c['warning'] === 0 ? $this->Translate('none') : sprintf($this->Translate('level %d'), $c['warning']),
                'limit' => sprintf($this->Translate('retract from level %d'), $min),
                'ok'    => !$active,
                'note'  => '',
            ];
        }
        if ($this->ReadPropertyInteger('VacationVariableID') > 0) {
            $list[] = [
                'k'     => 'vacation',
                'label' => $this->Translate('Vacation'),
                'value' => $c['vacation'] ? $this->Translate('yes') : $this->Translate('no'),
                'limit' => '',
                'ok'    => $c['vacation'] ? false : null,
                'note'  => '',
            ];
        }
        $door = $this->DoorClosed();
        if ($door !== null) {
            // Wert der Variable mit anzeigen, damit eine falsche Einstellung „Wert für geschlossen“ sofort auffällt
            $doorID = $this->ReadPropertyInteger('DoorVariableID');
            $raw = ($doorID > 0 && IPS_VariableExists($doorID) && !$this->Simulating())
                ? sprintf($this->Translate('value %d'), (int) round((float) GetValue($doorID)))
                : '';
            $list[] = [
                'k'     => 'door',
                'label' => $this->Translate('Terrace door'),
                'value' => $door ? $this->Translate('closed') : $this->Translate('open'),
                'limit' => $raw,
                'ok'    => null,
                'note'  => '',
            ];
        }
        return $list;
    }

    /**
     * Ampel mit Hysterese: true ab der Ausfahrgrenze, false unter der Einfahrgrenze,
     * null dazwischen (die Markise bleibt dort, wie sie ist).
     */
    private static function Band(float $value, float $on, float $off): ?bool
    {
        if ($value >= $on) {
            return true;
        }
        return $value < $off ? false : null;
    }

    private function StatusText(int $status): string
    {
        foreach ($this->StatusOptions() as $o) {
            if ($o['Value'] === $status) {
                return $o['Caption'];
            }
        }
        return '';
    }

    private static function Tone(int $status): string
    {
        return match ($status) {
            self::ST_SUN => 'ok',
            self::ST_WIND, self::ST_RAIN, self::ST_FROST, self::ST_SENSOR, self::ST_WARNING => 'bad',
            self::ST_OFF, self::ST_WEEKDAY, self::ST_NIGHT, self::ST_TIME, self::ST_VACATION => 'off',
            default => 'info',
        };
    }

    private function ConfigError(int $status): string
    {
        return match ($status) {
            104     => $this->Translate('The instance is switched off. Switch it on in the instance settings.'),
            200     => $this->Translate('Please select the actuator variables in the instance.'),
            201     => $this->Translate('An actuator variable is missing or has no action.'),
            202     => $this->Translate('A sensor variable does not exist.'),
            203     => $this->Translate('The brightness to retract must not be higher than the brightness to extend.'),
            default => '',
        };
    }
}
