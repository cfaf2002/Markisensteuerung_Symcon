<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon über den Modul-Loader, legt die Instanz an,
 * öffnet das Formular, verbindet Aktor- und Sensorvariablen und prüft Variablen und Kachel.
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

// Veraltete Stub-Aufrufe und Ident-Hinweise der Stubs ausblenden
set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
$copy = sys_get_temp_dir() . '/markise-stubs-' . getmypid();
@mkdir($copy);
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
    }
    file_put_contents($copy . '/' . basename($file), $code);
}
register_shutdown_function(static function () use ($copy): void {
    array_map('unlink', glob($copy . '/*.php'));
    @rmdir($copy);
});

require $copy . '/autoload.php';

\IPS\Kernel::reset();
IPS_CreateVariableProfile('~UnixTimestamp', 1);
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

function variable(int $type, mixed $value, int $action = 0): int
{
    $id = IPS_CreateVariable($type);
    SetValue($id, $value);
    if ($action > 0) {
        IPS_SetVariableCustomAction($id, $action);
    }
    return $id;
}

echo 'Markisensteuerung' . PHP_EOL;
try {
    $id = IPS_CreateInstance('{7EF9655A-D369-4F11-A33B-CE8053758685}');
    ok($id > 0, 'Instanz angelegt');
    $form = json_decode(IPS_GetConfigurationForm($id), true);
    ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');
    ok(IPS_GetInstance($id)['InstanceStatus'] === 200, 'Ohne Aktor Status 200');
    ok(str_contains(MARKISE_GetVisualizationTile($id), 'window.handleMessage'), 'Kachel-HTML auch ohne Einrichtung');

    // Aktionsskript wie bei einem Gateway: setzt einfach den Wert
    $script = IPS_CreateScript(0);
    IPS_SetScriptContent($script, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');

    $extend = variable(0, false, $script);
    $retract = variable(0, false, $script);
    IPS_SetProperty($id, 'ExtendVariableID', $extend);
    IPS_SetProperty($id, 'RetractVariableID', $retract);
    IPS_SetProperty($id, 'BrightnessVariableID', variable(2, 50000.0));
    IPS_SetProperty($id, 'TemperatureVariableID', variable(2, 24.0));
    IPS_SetProperty($id, 'WindVariableID', variable(1, 1));
    IPS_SetProperty($id, 'RainVariableID', variable(0, false));
    IPS_SetProperty($id, 'DayCheck', false);
    IPS_SetProperty($id, 'DelayOn', 0);
    IPS_SetProperty($id, 'ShowSettings', true);
    IPS_ApplyChanges($id);

    ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Mit Aktor Status 102');
    IPS_SetProperty($id, 'Active', false);
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'Nicht aktiv: Status 104');
    IPS_SetProperty($id, 'Active', true);
    IPS_ApplyChanges($id);
    foreach (['Automatic', 'Control', 'State', 'Status', 'Reason', 'Safety', 'SetLuxOn'] as $ident) {
        ok(@IPS_GetObjectIDByIdent($ident, $id) !== false, 'Variable ' . $ident);
    }
    ok(GetValue(IPS_GetObjectIDByIdent('Automatic', $id)) === true, 'Automatik an');
    ok(GetValue($extend) === true, 'Sonne: Ausfahrbefehl über die Aktion gesendet');
    ok(MARKISE_Evaluate($id) === true, 'MARKISE_Evaluate');
    // Simulation
    SetValue($extend, false);
    IPS_SetProperty($id, 'SimulationMode', true);
    IPS_ApplyChanges($id);
    ok(@IPS_GetObjectIDByIdent('SimLux', $id) !== false && @IPS_GetObjectIDByIdent('SimLog', $id) !== false, 'Simulationsvariablen angelegt');
    ok(GetValue($extend) === false, 'Simulation: kein echter Befehl');
    RequestAction(IPS_GetObjectIDByIdent('SimLux', $id), 1000.0);
    ok(str_starts_with((string) GetValue(IPS_GetObjectIDByIdent('Reason', $id)), 'Simulation'), 'Simulation: Begründung');
    IPS_SetProperty($id, 'SimulationMode', false);
    IPS_ApplyChanges($id);
    ok(@IPS_GetObjectIDByIdent('SimLux', $id) === false, 'Simulation aus: Variablen entfernt');

    // Version 1.2: Halten, Terrassentür, Übernahme aus dem Skript
    ok(@IPS_GetObjectIDByIdent('Hold', $id) !== false, 'Variable Hold');
    $door = variable(1, 2);
    IPS_SetProperty($id, 'DoorVariableID', $door);
    IPS_ApplyChanges($id);
    MARKISE_SetHold($id, true);
    ok(GetValue(IPS_GetObjectIDByIdent('Hold', $id)) === true, 'MARKISE_SetHold');
    SetValue($door, 0);
    MARKISE_Evaluate($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Hold', $id)) === false, 'Terrassentür zu beendet Halten');
    $old = IPS_CreateScript(0);
    IPS_SetScriptContent($old, '<?php $helligkeit = GetValueFloat(' . IPS_GetProperty($id, 'BrightnessVariableID') . '); RequestAction(' . $retract . ', true); RequestAction(' . $retract . ', true); RequestAction(' . $extend . ', true);');
    ob_start();
    IPS_RequestAction($id, 'ImportScript', $old);
    $out = (string) ob_get_clean();
    ok(str_contains($out, 'settings taken over') || str_contains($out, 'übernommen'), 'Übernahme aus dem Skript');

    // Zweite Kachel: Markisen-Einstellungen
    $set = IPS_CreateInstance('{196B0C7E-7EE0-4677-9DE5-B6C6FF3B787F}');
    ok($set > 0, 'Einstellungs-Instanz angelegt');
    IPS_SetProperty($set, 'TargetInstance', $id);
    IPS_ApplyChanges($set);
    ok(IPS_GetInstance($set)['InstanceStatus'] === 102, 'Einstellungs-Instanz Status 102');
    ok(str_contains(MARKSET_GetVisualizationTile($set), '"LuxOn"'), 'Einstellungs-Kachel mit Grundwerten');
    IPS_RequestAction($set, 'Set', json_encode(['name' => 'LuxOn', 'value' => 33000]));
    ok(IPS_GetProperty($id, 'LuxOn') === 33000, 'Änderung aus der Einstellungs-Kachel gespeichert');

    $tile = MARKISE_GetVisualizationTile($id);
    ok(!str_contains($tile, '/*INITIAL_DATA*/'), 'Kachel mit Startdaten');
    $form = json_decode(IPS_GetConfigurationForm($id), true);
    ok(is_array($form), 'Formular nach der Einrichtung');
} catch (Throwable $e) {
    ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
