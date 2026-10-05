<?php

declare(strict_types=1);

/**
 * Testumgebung für die Markisensteuerung – ohne laufendes IP-Symcon.
 *
 * Bildet IPSModuleStrict und die benötigten Symcon-Funktionen schlank nach.
 * Sensoren und Aktoren sind simulierte Variablen.
 *
 * SPDX-License-Identifier: MIT
 */

date_default_timezone_set('Europe/Berlin');

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const IPS_KERNELSTARTED = 10001;
const KR_READY = 10103;
const VM_UPDATE = 10603;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_VALUE_INPUT = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';
const VARIABLE_PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
const VARIABLE_PRESENTATION_SWITCH = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
const VARIABLE_PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

const GUID_LOCATION = '{45E97A63-F870-408A-B259-2933F7EABF74}';

/** Simulierter Symcon-Zustand */
final class Sym
{
    public static array $vars = [];          // ID => ['type', 'value', 'updated', 'action']
    public static array $actions = [];       // [[ID, Wert], …] ausgelöste Aktionen
    public static array $notifications = [];
    public static array $instances = [];
    public static ?array $location = [51.48, 7.22];
    public static array $objects = [];
    public static array $translations = [];
    public static bool $actionFails = false;
    public static int $now = 0;               // simulierte Uhr
    public static array $jitter = [];        // Sensoren, die beim Vorspulen leicht schwanken (wie echte Sensoren)
    public static array $scripts = [];       // ID => Inhalt
    public static array $idents = [];        // Eltern-ID => [Ident => ID]

    public static function reset(): void
    {
        self::$vars = [];
        self::$actions = [];
        self::$notifications = [];
        self::$instances = [901 => ['module' => GUID_LOCATION, 'props' => []], 950 => ['module' => 'visu', 'props' => []]];
        self::$location = [51.48, 7.22];
        self::$objects = [];
        self::$actionFails = false;
        self::$now = 0;
        self::$jitter = [];
        self::$scripts = [];
        self::$idents = [];
        self::$translations = json_decode((string) file_get_contents(__DIR__ . '/../Markisensteuerung/locale.json'), true)['translations']['de'];
    }

    public static function variable(int $id, int $type, mixed $value, bool $action = false, ?int $updated = null): void
    {
        self::$vars[$id] = ['type' => $type, 'value' => $value, 'updated' => $updated ?? self::$now, 'action' => $action];
    }
}

// ---------------------------------------------------------------------
// Symcon-Funktionen
// ---------------------------------------------------------------------

function IPS_GetKernelRunlevel(): int { return KR_READY; }

function IPS_VariableExists(int $id): bool { return isset(Sym::$vars[$id]); }

function GetValue(int $id): mixed { return Sym::$vars[$id]['value']; }

function IPS_GetVariable(int $id): array
{
    $v = Sym::$vars[$id];
    return ['VariableID' => $id, 'VariableType' => $v['type'], 'VariableUpdated' => $v['updated'], 'VariableChanged' => $v['updated']];
}

function HasAction(int $id): bool { return Sym::$vars[$id]['action'] ?? false; }

function RequestAction(int $id, mixed $value): bool
{
    if (Sym::$actionFails) {
        return false;
    }
    Sym::$actions[] = [$id, $value];
    Sym::$vars[$id]['value'] = $value;
    return true;
}

function IPS_SemaphoreEnter(string $name, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $name): bool { return true; }

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return array_keys(array_filter(Sym::$instances, static fn (array $i): bool => $i['module'] === $guid));
}

function IPS_GetInstanceList(): array { return array_keys(Sym::$instances); }

function IPS_GetInstance(int $id): array
{
    return ['InstanceID' => $id, 'ModuleInfo' => ['ModuleID' => Sym::$instances[$id]['module'] ?? '']];
}

function IPS_GetModule(string $moduleID): array
{
    return ['ModuleID' => $moduleID, 'Prefix' => ['visu' => 'VISU', GUID_LOCATION => 'LOC'][$moduleID] ?? ''];
}

function IPS_InstanceExists(int $id): bool { return isset(Sym::$instances[$id]); }

function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false
{
    if (!isset(Sym::$idents[$parent][$ident])) {
        trigger_error('Ident nicht gefunden: ' . $ident, E_USER_WARNING);
        return false;
    }
    return Sym::$idents[$parent][$ident];
}

function IPS_ScriptExists(int $id): bool { return isset(Sym::$scripts[$id]); }

function IPS_GetScriptContent(int $id): string { return Sym::$scripts[$id]; }

function IPS_GetConfiguration(int $id): string
{
    if ((Sym::$instances[$id]['module'] ?? '') === GUID_LOCATION && Sym::$location !== null) {
        return json_encode(['Location' => json_encode(['latitude' => Sym::$location[0], 'longitude' => Sym::$location[1]])]);
    }
    return '{}';
}

function IPS_SetProperty(int $id, string $name, mixed $value): bool
{
    Sym::$objects[$id]->properties[$name] = $value;
    return true;
}

function IPS_ApplyChanges(int $id): bool
{
    Sym::$objects[$id]->ApplyChanges();
    return true;
}

function VISU_PostNotification(int $id, string $title, string $text, string $type, int $target): int|false
{
    Sym::$notifications[] = ['title' => $title, 'text' => $text];
    return count(Sym::$notifications);
}

// ---------------------------------------------------------------------
// Nachbildung der Basisklasse
// ---------------------------------------------------------------------

class IPSModuleStrict
{
    public int $InstanceID;
    public array $properties = [];
    public array $attributes = [];
    public array $variables = [];
    public array $actionsEnabled = [];
    public array $messages = [];
    public array $references = [];
    public int $status = 0;
    public array $timers = [];
    public int $visualizationType = 0;
    public array $visualizationUpdates = [];
    public array $formUpdates = [];
    private static int $nextId = 30000;

    public function __construct(int $id)
    {
        $this->InstanceID = $id;
        Sym::$objects[$id] = $this;
    }

    public function Create(): void { }
    public function ApplyChanges(): void { }

    protected function RegisterPropertyString(string $n, string $v): void { $this->properties[$n] = $v; }
    protected function RegisterPropertyInteger(string $n, int $v): void { $this->properties[$n] = $v; }
    protected function RegisterPropertyFloat(string $n, float $v): void { $this->properties[$n] = $v; }
    protected function RegisterPropertyBoolean(string $n, bool $v): void { $this->properties[$n] = $v; }
    protected function ReadPropertyString(string $n): string { return (string) $this->properties[$n]; }
    protected function ReadPropertyInteger(string $n): int { return (int) $this->properties[$n]; }
    protected function ReadPropertyFloat(string $n): float { return (float) $this->properties[$n]; }
    protected function ReadPropertyBoolean(string $n): bool { return (bool) $this->properties[$n]; }
    protected function RegisterAttributeString(string $n, string $v): void { $this->attributes[$n] = $v; }
    protected function RegisterAttributeInteger(string $n, int $v): void { $this->attributes[$n] = $v; }
    protected function RegisterAttributeFloat(string $n, float $v): void { $this->attributes[$n] = $v; }
    protected function RegisterAttributeBoolean(string $n, bool $v): void { $this->attributes[$n] = $v; }
    protected function ReadAttributeString(string $n): string { return (string) $this->attributes[$n]; }
    protected function ReadAttributeInteger(string $n): int { return (int) $this->attributes[$n]; }
    protected function ReadAttributeFloat(string $n): float { return (float) $this->attributes[$n]; }
    protected function ReadAttributeBoolean(string $n): bool { return (bool) $this->attributes[$n]; }
    protected function WriteAttributeString(string $n, string $v): void { $this->attributes[$n] = $v; }
    protected function WriteAttributeInteger(string $n, int $v): void { $this->attributes[$n] = $v; }
    protected function WriteAttributeFloat(string $n, float $v): void { $this->attributes[$n] = $v; }
    protected function WriteAttributeBoolean(string $n, bool $v): void { $this->attributes[$n] = $v; }

    protected function RegisterTimer(string $n, int $ms, string $script): void { $this->timers[$n] = ['ms' => $ms, 'script' => $script]; }
    protected function SetTimerInterval(string $n, int $ms): void { $this->timers[$n]['ms'] = $ms; }
    protected function RegisterMessage(int $sender, int $message): void { $this->messages[$sender][$message] = true; }
    protected function UnregisterMessage(int $sender, int $message): void { unset($this->messages[$sender][$message]); }
    protected function RegisterReference(int $id): void { $this->references[$id] = true; }
    protected function UnregisterReference(int $id): void { unset($this->references[$id]); }
    protected function SetStatus(int $status): void { $this->status = $status; }
    public function GetStatus(): int { return $this->status; }
    protected function SendDebug(string $msg, string $data, int $format): void
    {
        if (getenv('DEBUG')) {
            echo '    [debug] ' . $msg . ': ' . $data . PHP_EOL;
        }
    }

    protected function MaintainVariable(string $ident, string $name, int $type, string|array $presentation, int $position, bool $keep): bool
    {
        if ($keep) {
            $default = [VARIABLETYPE_BOOLEAN => false, VARIABLETYPE_INTEGER => 0, VARIABLETYPE_FLOAT => 0.0, VARIABLETYPE_STRING => ''][$type];
            $this->variables[$ident] = [
                'id'           => $this->variables[$ident]['id'] ?? self::$nextId++,
                'name'         => $name,
                'type'         => $type,
                'presentation' => $presentation,
                'value'        => $this->variables[$ident]['value'] ?? $default,
            ];
        } else {
            unset($this->variables[$ident], $this->actionsEnabled[$ident]);
        }
        return true;
    }

    protected function EnableAction(string $ident): void { $this->actionsEnabled[$ident] = true; }

    protected function GetIDForIdent(string $ident): int|false
    {
        if (!isset($this->variables[$ident])) {
            trigger_error('Ident nicht gefunden: ' . $ident, E_USER_WARNING);
            return false;
        }
        return $this->variables[$ident]['id'];
    }

    protected function SetValue(string $ident, mixed $value): bool
    {
        if (!isset($this->variables[$ident])) {
            throw new RuntimeException('SetValue auf fehlende Variable ' . $ident);
        }
        $type = $this->variables[$ident]['type'];
        $expected = [VARIABLETYPE_BOOLEAN => 'boolean', VARIABLETYPE_INTEGER => 'integer', VARIABLETYPE_FLOAT => 'double', VARIABLETYPE_STRING => 'string'][$type];
        if (gettype($value) !== $expected && !($type === VARIABLETYPE_FLOAT && is_int($value))) {
            throw new RuntimeException('SetValue ' . $ident . ': falscher Typ ' . gettype($value));
        }
        $this->variables[$ident]['value'] = $type === VARIABLETYPE_FLOAT ? (float) $value : $value;
        return true;
    }

    protected function GetValue(string $ident): mixed
    {
        if (!isset($this->variables[$ident])) {
            throw new RuntimeException('GetValue auf fehlende Variable ' . $ident);
        }
        return $this->variables[$ident]['value'];
    }

    protected function SetVisualizationType(int $type): void { $this->visualizationType = $type; }
    protected function UpdateVisualizationValue(string $value): void { $this->visualizationUpdates[] = $value; }
    protected function UpdateFormField(string $field, string $param, mixed $value): void { $this->formUpdates[] = [$field, $param, $value]; }
    protected function Translate(string $text): string { return Sym::$translations[$text] ?? $text; }

    // Hilfen für Tests
    public function prop(string $n, mixed $v): static { $this->properties[$n] = $v; return $this; }
    public function attr(string $n): mixed { return $this->attributes[$n]; }
    public function setAttr(string $n, mixed $v): void { $this->attributes[$n] = $v; }
    public function value(string $ident): mixed { return $this->variables[$ident]['value'] ?? null; }
    public function has(string $ident): bool { return isset($this->variables[$ident]); }
}

set_error_handler(static function (int $no, string $str): bool {
    return $no === E_USER_WARNING && str_starts_with($str, 'Ident nicht gefunden');
});

require __DIR__ . '/../Markisensteuerung/module.php';

/** Testklasse mit steuerbarer Uhr */
final class TestMarkise extends Markisensteuerung
{
    protected function Now(): int
    {
        return Sym::$now;
    }

    /** Sensorwert ändern und wie Symcon eine VM_UPDATE-Nachricht schicken */
    public function sensor(int $id, mixed $value): void
    {
        $old = Sym::$vars[$id]['value'];
        Sym::$vars[$id]['value'] = $value;
        Sym::$vars[$id]['updated'] = Sym::$now;
        $this->MessageSink(Sym::$now, $id, VM_UPDATE, [$value, $old !== $value, $old, Sym::$now]);
    }

    /** Minuten vorspulen; jede Minute läuft der Timer */
    public function advance(int $minutes): void
    {
        for ($i = 0; $i < $minutes; $i++) {
            Sym::$now += 60;
            // Sensoren melden sich regelmäßig (wie eine echte Wetterstation)
            foreach (Sym::$vars as $id => $v) {
                if ($v['keepalive'] ?? true) {
                    Sym::$vars[$id]['updated'] = Sym::$now;
                }
            }
            foreach (Sym::$jitter as $id) {
                Sym::$vars[$id]['value'] += ($i % 2 === 0 ? 2.0 : -2.0);
            }
            $this->RequestAction('Tick', 0);
        }
    }
}
