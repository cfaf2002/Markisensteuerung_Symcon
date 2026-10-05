<?php

declare(strict_types=1);

/**
 * Markisen-Einstellungen – zweite Kachel für die Markisensteuerung
 *
 * Zeigt die Grundwerte einer Markisensteuerung (Grenzwerte, Verzögerungen, Wochentage, Zeiten)
 * und lässt sie in der Kachel-Visualisierung ändern. Die Werte bleiben Eigenschaften der
 * Markisensteuerung; diese Instanz speichert selbst nichts davon.
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */
class MarkisenEinstellungen extends IPSModuleStrict
{
    private const MARKISE_GUID = '{7EF9655A-D369-4F11-A33B-CE8053758685}';

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('TargetInstance', 0);
        $this->RegisterPropertyBoolean('AllowChanges', true);
        $this->RegisterPropertyInteger('TileTheme', 0);

        $this->RegisterAttributeInteger('Watched', 0);
        $this->RegisterAttributeString('WatchedVariables', '[]');
        $this->RegisterAttributeString('TileData', '{}');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->SetVisualizationType(1);

        // Auf Änderungen der Markisensteuerung hören (Werte aus Formular, Kachel oder Skript)
        $old = $this->ReadAttributeInteger('Watched');
        if ($old > 0) {
            $this->UnregisterMessage($old, IM_CHANGESETTINGS);
            $this->UnregisterReference($old);
        }
        $target = $this->ReadPropertyInteger('TargetInstance');
        $status = $this->CheckTarget($target);
        if ($status === 102) {
            $this->RegisterMessage($target, IM_CHANGESETTINGS);
            $this->RegisterReference($target);
            $this->WriteAttributeInteger('Watched', $target);
        } else {
            $this->WriteAttributeInteger('Watched', 0);
        }
        $this->SetStatus($status);
        $this->PushTile();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === IM_CHANGESETTINGS && $SenderID === $this->ReadAttributeInteger('Watched')) {
            $this->PushTile();
            return;
        }
        // z. B. Anwesenheit von außen geändert
        if ($Message === VM_UPDATE && in_array($SenderID, json_decode($this->ReadAttributeString('WatchedVariables'), true) ?: [], true)) {
            $this->PushTile();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'Set') {
            throw new InvalidArgumentException('Ungültiger Ident: ' . $Ident);
        }
        if (!$this->ReadPropertyBoolean('AllowChanges')) {
            throw new InvalidArgumentException('Änderungen in der Kachel sind ausgeschaltet.');
        }
        if ($this->GetStatus() !== 102) {
            throw new InvalidArgumentException('Keine Markisensteuerung ausgewählt.');
        }
        $data = is_string($Value) ? json_decode($Value, true) : $Value;
        if (!is_array($data) || !is_string($data['name'] ?? null) || !array_key_exists('value', $data)) {
            throw new InvalidArgumentException('Ungültige Daten');
        }
        // Prüfung von Name, Typ und Bereich übernimmt die Markisensteuerung
        MARKISE_SetParameter($this->ReadPropertyInteger('TargetInstance'), $data['name'], $data['value']);
        $this->PushTile();
    }

    /**
     * HTML der Kachel mit den aktuellen Werten als Startdaten.
     */
    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
        // Immer frische Werte: nach einem Modul-Update können die gespeicherten Startdaten veraltet sein
        $this->PushTile();
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        // JSON_HEX_* verhindert, dass Werte wie "</script>" das Skript der Kachel beenden
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', (string) $json, $html);
    }

    private function PushTile(): void
    {
        $status = $this->GetStatus();
        $data = [
            'theme'    => $this->ReadPropertyInteger('TileTheme'),
            'readOnly' => !$this->ReadPropertyBoolean('AllowChanges'),
            'error'    => '',
        ];
        $watch = [];
        if ($status === 102) {
            $params = json_decode(MARKISE_GetParameters($this->ReadPropertyInteger('TargetInstance')), true);
            $data += is_array($params) ? $params : [];
            $watch = array_values(array_filter($data['watch'] ?? [], 'is_int'));
            unset($data['watch']);
        } else {
            $data['error'] = $this->Translate($status === 201 ? 'The selected instance is not an awning control.' : 'Please select the awning control in the instance.');
        }
        $this->WatchVariables($watch);

        $json = json_encode($data);
        if ($json === $this->ReadAttributeString('TileData')) {
            return; // unverändert: nichts senden
        }
        $this->WriteAttributeString('TileData', $json);
        $this->UpdateVisualizationValue($json);
    }

    /** Meldet die Variablen an, deren Änderung die Kachel betrifft (nur bei Änderung der Liste) */
    private function WatchVariables(array $ids): void
    {
        $old = json_decode($this->ReadAttributeString('WatchedVariables'), true) ?: [];
        if ($old === $ids) {
            return;
        }
        foreach ($old as $id) {
            $this->UnregisterMessage((int) $id, VM_UPDATE);
        }
        foreach ($ids as $id) {
            if (IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
        $this->WriteAttributeString('WatchedVariables', json_encode($ids));
    }

    private function CheckTarget(int $id): int
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            return 200;
        }
        return IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::MARKISE_GUID ? 102 : 201;
    }
}
