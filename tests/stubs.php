<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon über den Modul-Loader, legt alle Instanzen an,
 * öffnet die Formulare und prüft Variablen und Kachel. Netzwerk wird nicht benötigt:
 * Abrufe von PEGELONLINE schlagen hier fehl, das Modul muss damit sauber umgehen.
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

// Veraltete Stub-Aufrufe (ReflectionParameter::getClass) und Ident-Hinweise der Stubs ausblenden
set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
$copy = sys_get_temp_dir() . '/pegelstand-stubs-' . getmypid();
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
// Systemprofil, das in echten Symcon-Installationen vorhanden ist
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

$modules = [
    '{357D9512-5653-4253-8279-DA569FEB0E1E}' => 'Pegelstand',
    '{540B4DE7-1C65-407B-8866-4AD66E54EFF7}' => 'Pegelstand Konfigurator',
    '{71C714BA-12BA-4DF4-BD6A-911506B05D45}' => 'Pegelstand Flussband',
];

foreach ($modules as $guid => $name) {
    echo $name . PHP_EOL;
    try {
        $id = IPS_CreateInstance($guid);
        ok($id > 0, 'Instanz angelegt');
        $form = json_decode(IPS_GetConfigurationForm($id), true);
        ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');

        if ($name === 'Pegelstand') {
            ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'Ohne Station Status 104');
            IPS_SetProperty($id, 'StationUUID', 'aa9179c1-17ef-4c61-a48a-74193fa7bfdf');
            IPS_ApplyChanges($id);
            $status = IPS_GetInstance($id)['InstanceStatus'];
            ok($status !== 104, 'Mit Station nicht mehr „Bitte Station wählen“ (Status ' . $status . ')');
            ok(@IPS_GetObjectIDByIdent('Level', $id) !== false, 'Variable „Pegelstand“ vorhanden');
            $tile = PEGEL_GetVisualizationTile($id);
            ok(str_contains($tile, 'window.handleMessage'), 'Kachel-HTML wird geliefert');
            ok(!str_contains($tile, 'Keine Messstation gewählt'), 'Kachel zeigt nicht mehr „Keine Messstation gewählt“');
        }
        if ($name === 'Pegelstand Flussband') {
            IPS_SetProperty($id, 'Water', 'MOSEL');
            IPS_ApplyChanges($id);
            ok(IPS_GetInstance($id)['InstanceStatus'] !== 104, 'Mit Gewässer nicht mehr „Bitte Gewässer wählen“');
        }
    } catch (Throwable $e) {
        ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
