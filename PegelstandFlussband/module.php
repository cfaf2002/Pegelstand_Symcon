<?php

declare(strict_types=1);

/**
 * Pegelstand – IP-Symcon-Modul für Wasserstände von PEGELONLINE
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */

require_once __DIR__ . '/../libs/PegelonlineTrait.php';

/**
 * Flussband: mehrere Stationen eines Gewässers nebeneinander, von oben nach unten (nach Fluss-km),
 * jeweils relativ zu den eigenen Kennwerten. So wird sichtbar, wie eine Hochwasserwelle flussabwärts wandert.
 */
class PegelstandFlussband extends IPSModuleStrict
{
    use PegelonlineTrait;

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Water', '');
        $this->RegisterPropertyFloat('KmFrom', 0.0);
        $this->RegisterPropertyFloat('KmTo', 0.0);
        $this->RegisterPropertyInteger('MaxStations', 12);
        $this->RegisterPropertyBoolean('HideLockUpper', true);
        $this->RegisterPropertyBoolean('HideNoReference', true);
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyInteger('TileTheme', 2);
        $this->RegisterPropertyBoolean('TileReduceMotion', false);
        $this->RegisterAttributeInteger('FailCount', 0);

        $this->RegisterAttributeString('WaterCache', '[]');
        $this->RegisterAttributeString('History', '{}');
        $this->RegisterAttributeString('TileData', '{}');

        $this->RegisterTimer('Update', 0, 'PEGELFB_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->SetVisualizationType(1);

        $presentation = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'water', 'SUFFIX' => ' ' . $this->Translate('stations')];
        $this->MaintainVariable('AboveMHW', $this->Translate('Stations above mean high water'), VARIABLETYPE_INTEGER, $presentation, 10, true);
        $this->MaintainVariable('AboveHSW', $this->Translate('Stations above HSW (navigation suspended)'), VARIABLETYPE_INTEGER, $presentation, 20, true);

        if ($this->ReadPropertyString('Water') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('Update', max(5, $this->ReadPropertyInteger('Interval')) * 60 * 1000);
        $this->SetStatus(102);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED && $this->ReadPropertyString('Water') !== '') {
            $this->Update();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ReloadWaters') {
            $this->WriteAttributeString('WaterCache', '[]');
            $this->UpdateFormField('Water', 'options', json_encode($this->WaterOptions()));
            return;
        }
        throw new Exception('Ungültiger Ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'Water') {
                $element['options'] = $this->WaterOptions();
            }
        }
        unset($element);

        return json_encode($form);
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/tile.html');
        return str_replace('/*INITIAL_DATA*/null', (string) json_encode($this->ReadAttributeString('TileData'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $html);
    }

    public function Update(): bool
    {
        $water = $this->ReadPropertyString('Water');
        if ($water === '') {
            $this->SetStatus(104);
            return false;
        }

        $data = $this->ApiRequest('stations.json?waters=' . rawurlencode($water) . '&timeseries=W&includeTimeseries=true&includeCurrentMeasurement=true&includeCharacteristicValues=true');
        if ($data === null) {
            // nach 2, 5 und 10 Minuten erneut versuchen, Fehlerstatus erst ab dem dritten Fehlschlag
            $fails = $this->ReadAttributeInteger('FailCount') + 1;
            $this->WriteAttributeInteger('FailCount', $fails);
            $this->SetTimerInterval('Update', [120, 300, 600][min($fails, 3) - 1] * 1000);
            $this->SendDebug('Fehler', 'PEGELONLINE nicht erreichbar (Versuch ' . $fails . ')', 0);
            $hasData = count(json_decode($this->ReadAttributeString('TileData'), true)['stations'] ?? []) > 0;
            if ($fails >= 3 || !$hasData) {
                $this->SetStatus(201);
                // Fehler auch in der Kachel zeigen; die letzten Werte bleiben stehen
                $tile = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
                $tile['error'] = $this->Translate('PEGELONLINE not reachable');
                $this->PushTile($tile);
            }
            return false;
        }
        if ($this->ReadAttributeInteger('FailCount') > 0) {
            $this->WriteAttributeInteger('FailCount', 0);
            $this->SetTimerInterval('Update', max(5, $this->ReadPropertyInteger('Interval')) * 60 * 1000);
        }

        $kmFrom = $this->ReadPropertyFloat('KmFrom');
        $kmTo = $this->ReadPropertyFloat('KmTo');
        $hideOP = $this->ReadPropertyBoolean('HideLockUpper');
        $waterName = $water;

        $rows = [];
        foreach ($data as $s) {
            $name = trim((string) ($s['longname'] ?? $s['shortname'] ?? ''));
            $km = isset($s['km']) ? (float) $s['km'] : null;
            $waterName = trim((string) ($s['water']['longname'] ?? $waterName));
            if ($hideOP && preg_match('/\sOP$/i', $name)) {
                continue;
            }
            if ($km !== null && ($km < $kmFrom || ($kmTo > 0 && $km > $kmTo))) {
                continue;
            }
            $w = null;
            foreach ($s['timeseries'] ?? [] as $ts) {
                if (strtoupper((string) ($ts['shortname'] ?? '')) === 'W') {
                    $w = $ts;
                }
            }
            if ($w === null || !isset($w['currentMeasurement']['value'])) {
                continue;
            }
            $cvs = [];
            foreach ($w['characteristicValues'] ?? [] as $cv) {
                $short = strtoupper(trim((string) ($cv['shortname'] ?? '')));
                if (in_array($short, ['MNW', 'MW', 'MHW', 'HSW'], true) && isset($cv['value'])) {
                    $cvs[$short] = (float) $cv['value'];
                }
            }
            // Ohne Kennwerte (z. B. Tidepegel) lässt sich die Station nicht einordnen
            if ($this->ReadPropertyBoolean('HideNoReference') && count($cvs) === 0) {
                continue;
            }
            $t = strtotime((string) ($w['currentMeasurement']['timestamp'] ?? ''));
            $rows[] = [
                'uuid'   => (string) $s['uuid'],
                'name'   => $name,
                'km'     => $km,
                'level'  => (float) $w['currentMeasurement']['value'],
                'time'   => $t !== false ? $t : 0,
                'stateH' => (string) ($w['currentMeasurement']['stateNswHsw'] ?? 'unknown'),
                'stateM' => (string) ($w['currentMeasurement']['stateMnwMhw'] ?? 'unknown'),
                'cvs'    => $cvs,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return ($a['km'] ?? 0.0) <=> ($b['km'] ?? 0.0);
        });
        // Vorhandene Pegelstand-Instanzen (per Auswahl oder Direkteingabe): immer im Band, direkt zu öffnen
        $instances = [];
        $byName = [];
        foreach (IPS_GetInstanceListByModuleID('{357D9512-5653-4253-8279-DA569FEB0E1E}') as $instanceID) {
            $uuid = (string) IPS_GetProperty($instanceID, 'StationUUID');
            if ($uuid !== '') {
                $instances[$uuid] = $instanceID;
            }
            $manual = mb_strtoupper(trim((string) @IPS_GetProperty($instanceID, 'StationManual')));
            if ($manual !== '') {
                $byName[$manual] = $instanceID;
            }
        }
        foreach ($rows as $r) {
            $name = mb_strtoupper($r['name']);
            if (!isset($instances[$r['uuid']]) && (isset($byName[$name]) || isset($byName[strtoupper($r['uuid'])]))) {
                $instances[$r['uuid']] = $byName[$name] ?? $byName[strtoupper($r['uuid'])];
            }
        }

        $rows = $this->Sample($rows, max(2, $this->ReadPropertyInteger('MaxStations')), array_keys($instances));

        if (count($rows) === 0) {
            $this->SetStatus(202);
            $this->PushTile(['water' => $waterName, 'stations' => [], 'error' => $this->Translate('No stations in the selected section')]);
            return false;
        }

        // Verlauf der letzten Stunden je Station für die Tendenz fortschreiben
        $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $now = time();
        $newHistory = [];

        $stations = [];
        $aboveMHW = 0;
        $aboveHSW = 0;
        foreach ($rows as $r) {
            $h = $history[$r['uuid']] ?? [];
            if (!array_key_exists($r['uuid'], $history)) {
                // neue Station im Band: die letzten Stunden einmalig laden, damit die Tendenz sofort stimmt
                // (auch eine Station ohne aktuelle Werte bleibt danach im Verlauf und wird nicht bei jedem Abruf neu geladen)
                $h = $this->FetchRecent($r['uuid'], $now);
            }
            if ($r['time'] > 0 && (count($h) === 0 || $h[count($h) - 1][0] !== $r['time'])) {
                $h[] = [$r['time'], $r['level']];
            }
            $h = array_values(array_filter($h, static function (array $p) use ($now): bool {
                return $p[0] >= $now - 4 * 3600;
            }));
            $newHistory[$r['uuid']] = $h;

            $diff = null;
            if (count($h) >= 2 && $h[count($h) - 1][0] - $h[0][0] >= 3600) {
                $diff = round($h[count($h) - 1][1] - $h[0][1], 1);
            }

            [$rel, $approx] = $this->Relative($r['level'], $r['cvs']);
            [$hswRel] = isset($r['cvs']['HSW']) ? $this->Relative($r['cvs']['HSW'], $r['cvs']) : [null];

            if (isset($r['cvs']['MHW']) && $r['level'] >= $r['cvs']['MHW']) {
                $aboveMHW++;
            }
            $warn = $r['stateH'] === 'high';
            if ($warn) {
                $aboveHSW++;
            }

            $stations[] = [
                'name'   => $r['name'],
                'km'     => $r['km'],
                'level'  => $r['level'],
                'rel'    => $rel,
                'approx' => $approx,
                'hswRel' => $hswRel,
                'diff'   => $diff,
                'state'  => $r['stateM'],
                'warn'   => $warn,
                'stale'  => $r['time'] > 0 && $now - $r['time'] > 3 * 3600,
                'cvs'    => $r['cvs'],
                'open'   => $instances[$r['uuid']] ?? 0,
            ];
        }

        $this->WriteAttributeString('History', json_encode($newHistory));
        $this->SetValue('AboveMHW', $aboveMHW);
        $this->SetValue('AboveHSW', $aboveHSW);

        $this->PushTile([
            'water'    => $waterName,
            'stations' => $stations,
            'theme'    => $this->ReadPropertyInteger('TileTheme'),
            'calm'     => $this->ReadPropertyBoolean('TileReduceMotion'),
            'updated'  => $now,
            'error'    => null,
        ]);

        $this->SetStatus(102);
        return true;
    }

    /**
     * Lage des Pegels auf einer gemeinsamen Skala: 0 = MNW, 1 = MHW.
     * Fehlen diese Kennwerte, wird näherungsweise mit MW bzw. HSW gerechnet.
     *
     * @return array [float|null $rel, bool $approx]
     */
    private function Relative(float $level, array $cvs): array
    {
        $mnw = $cvs['MNW'] ?? null;
        $mw = $cvs['MW'] ?? null;
        $mhw = $cvs['MHW'] ?? null;
        $hsw = $cvs['HSW'] ?? null;

        if ($mnw !== null && $mhw !== null && $mhw > $mnw) {
            return [round(($level - $mnw) / ($mhw - $mnw), 3), false];
        }
        if ($mw !== null && $mhw !== null && $mhw > $mw) {
            return [round(0.5 + ($level - $mw) / ($mhw - $mw) * 0.5, 3), true];
        }
        if ($mnw !== null && $mw !== null && $mw > $mnw) {
            return [round(($level - $mnw) / ($mw - $mnw) * 0.5, 3), true];
        }
        if ($hsw !== null && $hsw > 0) {
            // HSW liegt typischerweise etwas unter MHW
            return [round($level / $hsw * 0.9, 3), true];
        }
        return [null, true];
    }

    /**
     * Wählt gleichmäßig verteilt höchstens $max Stationen aus (erste und letzte bleiben immer).
     */
    private function Sample(array $rows, int $max, array $keep = []): array
    {
        $n = count($rows);
        if ($n <= $max) {
            return $rows;
        }
        // Pflicht: erste und letzte Station sowie alle mit eigener Pegelstand-Instanz
        $chosen = [0 => true, $n - 1 => true];
        foreach ($rows as $i => $r) {
            if (in_array($r['uuid'], $keep, true)) {
                $chosen[$i] = true;
            }
        }
        // Rest gleichmäßig verteilt auffüllen: jeweils die Station, die am weitesten von den gewählten entfernt ist
        while (count($chosen) < $max) {
            $best = -1;
            $bestGap = -1;
            for ($i = 0; $i < $n; $i++) {
                if (isset($chosen[$i])) {
                    continue;
                }
                $gap = PHP_INT_MAX;
                foreach (array_keys($chosen) as $c) {
                    $gap = min($gap, abs($c - $i));
                }
                if ($gap > $bestGap) {
                    $bestGap = $gap;
                    $best = $i;
                }
            }
            if ($best < 0) {
                break;
            }
            $chosen[$best] = true;
        }
        ksort($chosen);
        $result = [];
        foreach (array_keys($chosen) as $i) {
            $result[] = $rows[$i];
        }
        return $result;
    }

    private function WaterOptions(): array
    {
        $waters = json_decode($this->ReadAttributeString('WaterCache'), true);
        if (!is_array($waters) || count($waters) === 0) {
            $stations = $this->FetchStations() ?? [];
            $waters = [];
            foreach ($stations as $s) {
                $key = $s['waterShort'] ?? '';
                if ($key === '') {
                    continue;
                }
                if (!isset($waters[$key])) {
                    $waters[$key] = ['name' => $s['water'], 'count' => 0];
                }
                $waters[$key]['count']++;
            }
            // Flussband ergibt erst ab drei Stationen Sinn
            $waters = array_filter($waters, static function (array $w): bool {
                return $w['count'] >= 3;
            });
            uasort($waters, static function (array $a, array $b): int {
                return $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']);
            });
            if (count($waters) > 0) {
                $this->WriteAttributeString('WaterCache', json_encode($waters));
            }
        }

        $options = [['caption' => $this->Translate('– please select –'), 'value' => '']];
        foreach ($waters as $short => $w) {
            $options[] = [
                'caption' => mb_convert_case(mb_strtolower($w['name']), MB_CASE_TITLE, 'UTF-8') . ' (' . $w['count'] . ' ' . $this->Translate('stations') . ')',
                'value'   => (string) $short,
            ];
        }
        $selected = $this->ReadPropertyString('Water');
        if ($selected !== '' && !isset($waters[$selected])) {
            $options[] = ['caption' => $selected, 'value' => $selected];
        }
        return $options;
    }

    /**
     * Letzte 3 Stunden einer Station als [[Zeit, Wert], …].
     */
    private function FetchRecent(string $uuid, int $now): array
    {
        $data = $this->ApiRequest('stations/' . rawurlencode($uuid) . '/W/measurements.json?start=PT3H');
        $points = [];
        foreach ($data ?? [] as $m) {
            $t = isset($m['timestamp']) ? strtotime((string) $m['timestamp']) : false;
            if ($t !== false && isset($m['value']) && $t >= $now - 4 * 3600) {
                $points[] = [$t, (float) $m['value']];
            }
        }
        usort($points, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        return $points;
    }

    private function PushTile(array $data): void
    {
        $json = json_encode($data);
        // Zeitstempel der Aktualisierung zählt nicht als Änderung
        $compare = static function (string $j): string {
            return (string) preg_replace('/"updated":\d+,?/', '', $j);
        };
        if ($compare($json) === $compare($this->ReadAttributeString('TileData'))) {
            return;
        }
        $this->WriteAttributeString('TileData', $json);
        $this->UpdateVisualizationValue($json);
    }
}
