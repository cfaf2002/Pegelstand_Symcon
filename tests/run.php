<?php

declare(strict_types=1);

/**
 * Testsuite für Pegelstand, Pegelstand Konfigurator und Pegelstand Flussband.
 * Aufruf: php tests/run.php   (DEBUG=1 zeigt die Debug-Ausgaben der Module)
 *
 * SPDX-License-Identifier: MIT
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

$passed = 0;
$failed = [];
$current = '';

function test(string $name, callable $fn): void
{
    global $current, $failed;
    $current = $name;
    Sym::reset();
    echo '• ' . $name . PHP_EOL;
    try {
        $fn();
    } catch (Throwable $e) {
        $failed[] = $name . ': ' . get_class($e) . ' – ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo '    ✗ Ausnahme: ' . $e->getMessage() . PHP_EOL;
    }
}

function check(bool $condition, string $message): void
{
    global $passed, $failed, $current;
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $current . ': ' . $message;
    echo '    ✗ ' . $message . PHP_EOL;
}

function near(float $a, float $b, float $tolerance): bool
{
    return abs($a - $b) <= $tolerance;
}

/** Neue Pegelstand-Instanz; ApplyChanges ruft bei gesetzter Station sofort Update auf */
function pegel(array $props = [], int $id = 1000): TestPegelstand
{
    $p = new TestPegelstand($id);
    $p->Create();
    foreach ($props as $k => $v) {
        $p->prop($k, $v);
    }
    $p->ApplyChanges();
    return $p;
}

function tileData(IPSModuleStrict $m): array
{
    return json_decode((string) $m->attr('TileData'), true) ?: [];
}

/** Standard-Fixtures: Konstanz bei 318 cm, steigend um 6 cm/h */
function standardFixtures(int $now, float $level = 318.0, ?int $measuredAt = null, string $nswHsw = 'normal'): void
{
    $measuredAt ??= $now - 600;
    Sym::$fixtures['stations/uuid-k.json'] = fixtureStation($level, $measuredAt, 'normal', $nswHsw);
    Sym::$fixtures['stations/uuid-k/W/measurements.json'] = fixtureSeries($measuredAt, 24, $level, 6.0);
    Sym::$fixtures['stations/uuid-k/W/measurements.json#30'] = fixtureSeries($now - 86400, 29 * 24, 300.0, 0.0, 3600);
    Sym::$fixtures['stations/uuid-k/Q/measurements.json'] = fixtureSeries($now, 24, 120.0, 0.0);
    Sym::$fixtures['stations.json?timeseries=W'] = fixtureStationList($now);
}

$now = time();

// =====================================================================
// Pegelstand
// =====================================================================

test('Ohne Station: Status 104, kein Timer', function (): void {
    $p = pegel();
    check($p->status === 104, 'Status 104 erwartet, ist ' . $p->status);
    check($p->timers['Update']['ms'] === 0, 'Timer muss aus sein');
});

test('Abruf: Pegel, Tendenz, Darstellung, Takt', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k']);
    check($p->status === 102, 'Status 102 erwartet, ist ' . $p->status);
    check($p->value('Level') === 318.0, 'Pegel 318 erwartet');
    check($p->value('Trend') === 1, 'Tendenz steigend erwartet');
    check(near((float) $p->value('Change'), 18.0, 0.2), 'Veränderung ~18 cm in 3 h erwartet, ist ' . $p->value('Change'));
    check($p->value('MeasuredAt') === $now - 600, 'Messzeitpunkt falsch');
    check($p->visualizationType === 1, 'Kachel-Visualisierung muss aktiv sein');
    $presentation = $p->variables['Level']['presentation'];
    check(is_array($presentation) && $presentation['SUFFIX'] === ' cm', 'Darstellung mit Suffix cm erwartet');
    check($p->timers['Update']['script'] === "PEGEL_Poll(\$_IPS['TARGET']);", 'Timer muss Poll aufrufen');
    // nächster Abruf: Messzeit + 15 min + 3 min Wartezeit → 8 min ab jetzt
    check(near($p->timers['Update']['ms'] / 1000, 480, 3), 'Nächster Abruf nach ~8 Min. erwartet, ist ' . ($p->timers['Update']['ms'] / 1000) . ' s');
});

test('Poll ohne neuen Messwert rechnet nicht neu', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k']);
    $before = count(Sym::$requests);
    $updates = count($p->visualizationUpdates);
    $p->Poll();
    check(count(Sym::$requests) - $before === 1, 'Nur die Stationsabfrage erwartet, waren ' . (count(Sym::$requests) - $before));
    check(count($p->visualizationUpdates) === $updates, 'Kachel darf nicht aktualisiert werden');
    check($p->timers['Update']['ms'] === 180000, 'Erneuter Versuch nach 3 Min. erwartet');
});

test('Poll mit neuem Messwert rechnet neu', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k']);
    standardFixtures($now, 320.0, $now - 60);
    $p->Poll();
    check($p->value('Level') === 320.0, 'Neuer Pegel 320 erwartet');
    check(in_array('stations/uuid-k/W/measurements.json?start=PT24H', Sym::$requests, true), 'Messreihe muss neu geladen werden');
});

test('Fehler: Wiederholung nach 2, 5, 10 Min., Status erst beim dritten Mal', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k']);
    $station = Sym::$fixtures['stations/uuid-k.json'];
    unset(Sym::$fixtures['stations/uuid-k.json']);
    Sym::$fixtures['__fail'] = 500;

    $p->Poll();
    check($p->timers['Update']['ms'] === 120000 && $p->status === 102, '1. Fehler: 2 Min., Status bleibt 102');
    $p->Poll();
    check($p->timers['Update']['ms'] === 300000 && $p->status === 102, '2. Fehler: 5 Min., Status bleibt 102');
    $p->Poll();
    check($p->timers['Update']['ms'] === 600000 && $p->status === 201, '3. Fehler: 10 Min. und Status 201');
    check((tileData($p)['error'] ?? '') === 'PEGELONLINE nicht erreichbar', 'Kachel muss den Fehler zeigen');

    Sym::$fixtures['stations/uuid-k.json'] = $station;
    $p->Poll();
    check($p->status === 102 && $p->attr('FailCount') === 0, 'Nach Erholung Status 102 und Zähler 0');
});

test('Hochwasserwarnung mit Hysterese und Benachrichtigungen', function () use ($now): void {
    Sym::reset();
    Sym::$instances[777] = ['module' => 'visu', 'props' => [], 'name' => 'Kachel-Visualisierung'];
    standardFixtures($now, 318.0);
    $p = pegel(['StationUUID' => 'uuid-k', 'ShowFloodWarning' => true, 'WarnLevel' => 330, 'NotifyEnabled' => true, 'NotifyTarget' => 777]);
    check($p->value('FloodWarning') === false, 'Bei 318 cm keine Warnung');

    standardFixtures($now, 335.0);
    $p->Update();
    check($p->value('FloodWarning') === true, 'Ab 330 cm Warnung');
    standardFixtures($now, 327.0);
    $p->Update();
    check($p->value('FloodWarning') === true, 'Innerhalb der Hysterese bleibt die Warnung');
    standardFixtures($now, 324.0);
    $p->Update();
    check($p->value('FloodWarning') === false, 'Unter Schwelle minus Hysterese Entwarnung');
    standardFixtures($now, 324.0, null, 'high');
    $p->Update();
    check($p->value('FloodWarning') === true, 'Über HSW Warnung');

    check(count(Sym::$notifications) === 3, '3 Nachrichten erwartet, waren ' . count(Sym::$notifications));
    check(str_contains(Sym::$notifications[0]['text'] ?? '', 'über der Warnschwelle von 330 cm'), 'Text der Warnung');
    check(str_starts_with(Sym::$notifications[1]['title'] ?? '', 'Entwarnung'), 'Entwarnung erwartet');
    check(str_contains(Sym::$notifications[2]['text'] ?? '', 'HSW'), 'HSW-Warnung erwartet');
    check((Sym::$notifications[0]['target'] ?? 0) === $p->InstanceID, 'Antippen öffnet die Instanz');
    check(mb_strlen(Sym::$notifications[0]['title']) <= 32, 'Titel höchstens 32 Zeichen');
});

test('Testnachricht ohne Visualisierung: klarer Hinweis', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k']);
    ob_start();
    $ok = $p->TestNotification();
    $out = (string) ob_get_clean();
    check($ok === false && str_contains($out, 'Keine Visualisierung gefunden'), 'Hinweis „Keine Visualisierung gefunden“ erwartet: ' . $out);
});

test('Testnachricht: Visualisierung automatisch, Sprungziel notfalls weglassen', function () use ($now): void {
    standardFixtures($now);
    Sym::$instances[888] = ['module' => 'visu', 'props' => [], 'name' => 'Kachel-Visualisierung'];
    Sym::$visuRejectsTarget = true;
    $p = pegel(['StationUUID' => 'uuid-k']);
    ob_start();
    $ok = $p->TestNotification();
    $out = (string) ob_get_clean();
    check($ok === true && str_contains($out, 'Kachel-Visualisierung'), 'Automatisch gefundene Visualisierung erwartet: ' . $out);
    check((Sym::$notifications[0]['visu'] ?? 0) === 888 && Sym::$notifications[0]['target'] === 0, 'Zweiter Versuch ohne Sprungziel erwartet');

    $form = json_decode($p->GetConfigurationForm(), true);
    $field = null;
    array_walk_recursive($form, static function () {});
    foreach ($form['elements'] as $panel) {
        foreach ($panel['items'] ?? [] as $e) {
            if (($e['name'] ?? '') === 'NotifyTarget') {
                $field = $e;
            }
        }
    }
    check(($field['validModules'] ?? []) === ['visu'], 'Auswahl nur auf Visualisierungen beschränkt');
    check(str_contains($field['caption'] ?? '', 'automatisch „Kachel-Visualisierung“'), 'Hinweis auf automatische Auswahl erwartet: ' . ($field['caption'] ?? ''));
});

test('Archiv: Archivierung einschalten und 30 Tage nachladen', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k', 'ArchiveEnabled' => true, 'ShowDischarge' => true]);
    $level = $p->varId('Level');
    check(Sym::$logging[$level] ?? false, 'Pegel muss archiviert werden');
    check(Sym::$logging[$p->varId('Discharge')] ?? false, 'Abfluss muss archiviert werden');
    check(count(Sym::$archive[$level] ?? []) > 600, 'Verlauf von rund 30 Tagen erwartet, sind ' . count(Sym::$archive[$level] ?? []));
    check($p->attr('BackfillDone') === 'uuid-k', 'Nachladen muss vermerkt sein');
    check($p->BackfillArchive() === 0, 'Zweites Nachladen darf keine Dubletten erzeugen');
});

test('Einordnung, Rekord und Prognose', function () use ($now): void {
    standardFixtures($now, 318.0);
    $p = pegel(['StationUUID' => 'uuid-k', 'ShowInsights' => true, 'WarnLevel' => 330]);
    $insight = (string) $p->value('Insight');
    check(str_contains($insight, '23 cm unter Mittelwasser'), 'Vergleich mit MW erwartet: ' . $insight);
    check(str_contains($insight, 'höchster Stand seit über 4 Wochen'), 'Rekord erwartet: ' . $insight);
    check($p->value('Forecast') === 'Warnschwelle in ca. 2 Std.', 'Prognose erwartet, ist: ' . $p->value('Forecast'));
});

test('Schifffahrt eingestellt über HSW', function () use ($now): void {
    standardFixtures($now, 340.0, null, 'high');
    $p = pegel(['StationUUID' => 'uuid-k']);
    check((tileData($p)['shipping'] ?? '') === 'stopped', 'Schiff muss vor Anker liegen');
});

test('Kennwerte als Variablen an- und abschalten', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k', 'ShowCharacteristics' => true]);
    check($p->has('CV_MNW') && $p->has('CV_MW') && $p->has('CV_MHW'), 'Kennwert-Variablen erwartet');
    check($p->value('CV_MW') === 341.0, 'MW 341 erwartet');
    $p->prop('ShowCharacteristics', false);
    $p->ApplyChanges();
    check(!$p->has('CV_MNW'), 'Kennwerte müssen entfernt werden');
});

test('Kachel wird nur bei Änderung aktualisiert', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k']);
    $count = count($p->visualizationUpdates);
    $p->Update();
    check(count($p->visualizationUpdates) === $count, 'Gleiche Daten dürfen nicht erneut gesendet werden');
    standardFixtures($now, 319.0, $now - 300);
    $p->Update();
    check(count($p->visualizationUpdates) === $count + 1, 'Neue Daten müssen gesendet werden');
});

test('Eigenes Hintergrundbild wird verkleinert eingebettet', function () use ($now): void {
    if (!function_exists('imagecreatetruecolor')) {
        echo '    (übersprungen: GD fehlt)' . PHP_EOL;
        return;
    }
    $img = imagecreatetruecolor(1600, 1000);
    ob_start();
    imagejpeg($img, null, 90);
    Sym::$media[555] = (string) ob_get_clean();
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k', 'TileBackground' => 555]);
    $html = $p->GetVisualizationTile();
    check(str_contains($html, 'data:image\/jpeg;base64,'), 'Bild muss als data-URI eingebettet sein');
    check(strlen($html) < 400000, 'Kachel muss unter 400 KB bleiben');
});

test('Stationsauswahl nach Entfernung sortiert', function () use ($now): void {
    standardFixtures($now);
    $p = pegel(['StationUUID' => 'uuid-k']);
    $form = json_decode($p->GetConfigurationForm(), true);
    $select = array_values(array_filter($form['elements'][0]['items'], static fn ($e) => ($e['name'] ?? '') === 'StationUUID'))[0];
    $options = $select['options'] ?? [];
    $hint = array_values(array_filter($form['elements'][0]['items'], static fn ($e) => ($e['name'] ?? '') === 'StationListError'))[0];
    check($hint['visible'] === false, 'Ohne Fehler kein Fehlerhinweis');
    check(str_starts_with($options[1]['caption'] ?? '', 'Bonn'), 'Bonn als nächste Station erwartet: ' . ($options[1]['caption'] ?? '–'));
    check(str_contains($options[1]['caption'] ?? '', 'km entfernt'), 'Entfernung im Text erwartet');
});

test('Stationsliste nicht ladbar: Grund im Formular, Direkteingabe funktioniert', function () use ($now): void {
    Sym::$fixtures['__fail'] = 0;
    $p = pegel();
    $form = json_decode($p->GetConfigurationForm(), true);
    $items = $form['elements'][0]['items'];
    $hint = array_values(array_filter($items, static fn ($e) => ($e['name'] ?? '') === 'StationListError'))[0];
    check($hint['visible'] === true, 'Fehlerhinweis muss sichtbar sein');
    check(str_contains($hint['caption'], 'Station unten direkt eintragen'), 'Hinweis auf Direkteingabe erwartet');
    check(in_array('StationManual', array_column($items, 'name'), true), 'Feld für Direkteingabe erwartet');

    // Station per Name eintragen
    $station = fixtureStation(318.0, $now - 600);
    Sym::$fixtures['stations/KONSTANZ.json'] = $station;
    Sym::$fixtures['stations/KONSTANZ/W/measurements.json'] = fixtureSeries($now - 600, 24, 318.0, 6.0);
    $p->prop('StationManual', 'KONSTANZ');
    $p->ApplyChanges();
    check($p->status === 102 && $p->value('Level') === 318.0, 'Mit Direkteingabe KONSTANZ muss der Pegel kommen');

    // Liste wieder erreichbar: Hinweis verschwindet
    Sym::$fixtures['stations.json?timeseries=W'] = fixtureStationList($now);
    ob_start();
    $p->RequestAction('ReloadStations', '');
    ob_end_clean();
    $form = json_decode($p->GetConfigurationForm(), true);
    $hint = array_values(array_filter($form['elements'][0]['items'], static fn ($e) => ($e['name'] ?? '') === 'StationListError'))[0];
    check($hint['visible'] === false, 'Nach erfolgreichem Neuladen kein Fehlerhinweis');
});

// =====================================================================
// Konfigurator
// =====================================================================

test('Konfigurator: Entfernung, Pegel, Instanzen, Zwischenspeicher, Umkreis', function () use ($now): void {
    Sym::$fixtures['stations.json?timeseries=W&includeTimeseries=true&includeCurrentMeasurement=true'] = fixtureStationList($now);
    Sym::$instances[4711] = ['module' => GUID_PEGELSTAND, 'props' => ['StationUUID' => 'uuid-k']];
    $k = new TestKonfigurator(2000);
    $k->Create();
    $k->ApplyChanges();

    $form = json_decode($k->GetConfigurationForm(), true);
    $conf = array_values(array_filter($form['actions'], static fn ($a) => ($a['type'] ?? '') === 'Configurator'))[0];
    $rows = array_column($conf['values'], null, 'name');
    check($conf['sort']['column'] === 'distance', 'Sortierung nach Entfernung erwartet');
    check(($rows['Bonn']['level'] ?? '') === '412 cm', 'Aktueller Pegel Bonn erwartet');
    check(($rows['Konstanz']['instanceID'] ?? 0) === 4711, 'Angelegte Instanz muss erkannt werden');
    check(near((float) ($rows['Bonn']['distance'] ?? 99), 1.1, 0.5), 'Entfernung Bonn ~1 km');

    $requests = count(Sym::$requests);
    $k->GetConfigurationForm();
    check(count(Sym::$requests) === $requests, 'Zweites Öffnen muss aus dem Zwischenspeicher kommen');
    $k->RequestAction('Reload', '');
    $k->GetConfigurationForm();
    check(count(Sym::$requests) === $requests + 1, 'Neu laden muss die API abfragen');

    $k->prop('MaxDistance', 50);
    $form = json_decode($k->GetConfigurationForm(), true);
    $conf = array_values(array_filter($form['actions'], static fn ($a) => ($a['type'] ?? '') === 'Configurator'))[0];
    check(count($conf['values']) === 3, 'Umkreis 50 km: Bonn, Köln und die angelegte Station erwartet');
});

// =====================================================================
// Flussband
// =====================================================================

function flussband(array $props = []): TestFlussband
{
    $f = new TestFlussband(3000);
    $f->Create();
    foreach ($props as $k => $v) {
        $f->prop($k, $v);
    }
    $f->ApplyChanges();
    return $f;
}

function moselFixtures(int $now): void
{
    Sym::$fixtures['stations.json?waters=MOSEL&timeseries=W&includeTimeseries=true&includeCurrentMeasurement=true&includeCharacteristicValues=true'] = fixtureMosel($now);
    foreach (fixtureMosel($now) as $i => $s) {
        $level = $s['timeseries'][0]['currentMeasurement']['value'];
        Sym::$fixtures['stations/' . $s['uuid'] . '/W/measurements.json'] = fixtureSeries($now - 600, 3, $level, $i >= 3 ? 10.0 : 0.0);
    }
}

test('Flussband: Schleusen-OP aus, relative Lage, Tendenz sofort, Öffnen', function () use ($now): void {
    moselFixtures($now);
    Sym::$instances[4712] = ['module' => GUID_PEGELSTAND, 'props' => ['StationUUID' => 'm2']];
    $f = flussband(['Water' => 'MOSEL']);
    $stations = tileData($f)['stations'] ?? [];
    $byName = array_column($stations, null, 'name');

    check($f->status === 102, 'Status 102 erwartet');
    check(count($stations) === 6, 'Ohne Oberpegel 6 Stationen erwartet, sind ' . count($stations));
    check(!isset($byName['KOBLENZ OP']), 'Oberpegel muss ausgeblendet sein');
    check(near((float) $byName['COCHEM']['rel'], 0.251, 0.002) && $byName['COCHEM']['approx'] === false, 'Cochem: rel 0,25 aus MNW/MHW');
    check($byName['LEHMEN UP']['approx'] === true, 'Lehmen: nur geschätzt (nur HSW)');
    check(near((float) ($byName['TRIER UP']['diff'] ?? 0), 30.0, 0.5), 'Tendenz Trier sofort aus der Messreihe: +30 cm');
    check($byName['COCHEM']['open'] === 4712, 'Angelegte Station muss geöffnet werden können');
    check($f->value('AboveHSW') === 1, 'Eine Station über HSW erwartet (Grevenmacher)');
    check($f->value('AboveMHW') === 0, 'Keine Station über MHW erwartet');
});

test('Flussband: Auswahl auf Höchstzahl und Wiederholung bei Fehler', function () use ($now): void {
    moselFixtures($now);
    $f = flussband(['Water' => 'MOSEL', 'MaxStations' => 3]);
    $names = array_column(tileData($f)['stations'] ?? [], 'name');
    check(count($names) === 3 && $names[0] === 'LEHMEN UP' && $names[2] === 'PERL', 'Erste und letzte Station müssen bleiben');

    Sym::$fixtures = [];
    $f->Update();
    check($f->timers['Update']['ms'] === 120000 && $f->status === 102, 'Erster Fehler: 2 Min., Status bleibt');
});

// =====================================================================
// Formulare passen zu den Eigenschaften
// =====================================================================

test('Alle Formularfelder haben eine Eigenschaft', function () use ($now): void {
    standardFixtures($now);
    Sym::$fixtures['stations.json?timeseries=W&includeTimeseries=true&includeCurrentMeasurement=true'] = fixtureStationList($now);
    $modules = [pegel(), new TestKonfigurator(2001), new TestFlussband(3001)];
    foreach ($modules as $m) {
        if ($m->properties === []) {
            $m->Create();
        }
        $form = json_decode($m->GetConfigurationForm(), true);
        $names = [];
        $walk = function (array $items) use (&$walk, &$names): void {
            foreach ($items as $e) {
                // Labels und Configuratoren tragen Namen nur zum Ansteuern, ohne Eigenschaft
                if (isset($e['name']) && !in_array($e['type'] ?? '', ['Configurator', 'Label'], true)) {
                    $names[] = $e['name'];
                }
                if (isset($e['items'])) {
                    $walk($e['items']);
                }
            }
        };
        $walk($form['elements']);
        foreach ($names as $n) {
            check(array_key_exists($n, $m->properties), get_class($m) . ': Feld „' . $n . '“ ohne Eigenschaft');
        }
    }
});

// =====================================================================
// Übersetzung
// =====================================================================

test('Ohne Übersetzung englisch, mit locale.json deutsch', function () use ($now): void {
    standardFixtures($now, 318.0);
    Sym::$translations = [];
    $p = pegel(['StationUUID' => 'uuid-k', 'ShowInsights' => true, 'WarnLevel' => 330]);
    check(str_contains((string) $p->value('Insight'), '23 cm below mean water level'), 'Englische Einordnung erwartet: ' . $p->value('Insight'));
    check($p->value('Forecast') === 'warning threshold in approx. 2 h', 'Englische Prognose erwartet: ' . $p->value('Forecast'));
    check($p->variables['Level']['name'] === 'Water level', 'Englischer Variablenname erwartet');

    Sym::reset();
    standardFixtures($now, 318.0);
    $p = pegel(['StationUUID' => 'uuid-k', 'ShowInsights' => true, 'WarnLevel' => 330]);
    check($p->variables['Level']['name'] === 'Pegelstand', 'Deutscher Variablenname erwartet');
});

test('locale.json im Symcon-Format mit deutscher Übersetzung', function (): void {
    foreach (glob(__DIR__ . '/../*/locale.json') as $file) {
        $json = json_decode((string) file_get_contents($file), true);
        check(isset($json['translations']['de']) && count($json['translations']) === 1, basename(dirname($file)) . ': nur der Schlüssel „de“ erwartet');
    }
});

test('Jeder übersetzbare Text steht in locale.json', function (): void {
    $root = __DIR__ . '/..';
    $locale = json_decode((string) file_get_contents($root . '/Pegelstand/locale.json'), true)['translations']['de'];
    $texts = [];
    foreach (array_merge(glob($root . '/*/module.php'), glob($root . '/libs/*.php')) as $file) {
        $code = (string) file_get_contents($file);
        preg_match_all("/Translate\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $code, $m);
        $texts = array_merge($texts, $m[1]);
        preg_match_all("/Variable\\('\\w+', '([^']+)'/", $code, $m);
        $texts = array_merge($texts, $m[1]);
    }
    foreach (glob($root . '/*/form.json') as $file) {
        $json = json_decode((string) file_get_contents($file), true);
        array_walk_recursive($json, function ($v, $k) use (&$texts): void {
            if (in_array($k, ['caption', 'label'], true) && is_string($v) && trim($v) !== '') {
                $texts[] = $v;
            }
        });
    }
    foreach (array_unique($texts) as $t) {
        check(array_key_exists($t, $locale), 'Übersetzung fehlt: „' . $t . '“');
    }
});

// =====================================================================

echo PHP_EOL . $passed . ' Prüfungen bestanden, ' . count($failed) . ' fehlgeschlagen.' . PHP_EOL;
foreach ($failed as $f) {
    echo '  ✗ ' . $f . PHP_EOL;
}
exit(count($failed) === 0 ? 0 : 1);
