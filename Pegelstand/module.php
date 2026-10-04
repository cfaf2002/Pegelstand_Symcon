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
require_once __DIR__ . '/../libs/PegelTileTrait.php';
require_once __DIR__ . '/../libs/PegelArchiveTrait.php';
require_once __DIR__ . '/../libs/PegelNotifyTrait.php';
require_once __DIR__ . '/../libs/PegelInsightTrait.php';

class Pegelstand extends IPSModuleStrict
{
    use PegelonlineTrait;
    use PegelTileTrait;
    use PegelArchiveTrait;
    use PegelNotifyTrait;
    use PegelInsightTrait;

    // Symcon-Kerninstanz "Archive Control"
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    // Zustände laut PEGELONLINE (stateMnwMhw / stateNswHsw)
    private const STATE_MAP = [
        'unknown'   => 0,
        'low'       => 1,
        'normal'    => 2,
        'high'      => 3,
        'commented' => 4,
        'out-dated' => 5,
    ];

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Messstation
        $this->RegisterPropertyString('StationUUID', '');
        $this->RegisterPropertyString('StationManual', '');
        $this->RegisterPropertyString('WaterFilter', '');
        $this->RegisterPropertyBoolean('SortByDistance', true);

        // Aktualisierung & Tendenz
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyInteger('TrendHours', 3);
        $this->RegisterPropertyFloat('TrendThreshold', 2.0);

        // Zusatzfunktionen
        $this->RegisterPropertyBoolean('ShowTimestamp', true);
        $this->RegisterPropertyBoolean('ShowStationInfo', false);
        $this->RegisterPropertyBoolean('ShowCharacteristics', false);
        $this->RegisterPropertyBoolean('ShowFloodWarning', false);
        $this->RegisterPropertyInteger('WarnLevel', 0);
        $this->RegisterPropertyBoolean('ShowDischarge', false);
        $this->RegisterPropertyBoolean('ShowInsights', false);

        // Benachrichtigungen
        $this->RegisterPropertyBoolean('NotifyEnabled', false);
        $this->RegisterPropertyInteger('NotifyTarget', 0);
        $this->RegisterPropertyFloat('NotifyHysteresis', 5.0);
        $this->RegisterPropertyBoolean('NotifyClear', true);

        // Archiv
        $this->RegisterPropertyBoolean('ArchiveEnabled', false);
        $this->RegisterPropertyInteger('ArchiveBackfillDays', 30);

        // Kachel
        $this->RegisterPropertyBoolean('UseTile', true);
        $this->RegisterPropertyBoolean('TileShowRefs', true);
        $this->RegisterPropertyBoolean('TileShowHistory', true);
        $this->RegisterPropertyInteger('TileHistoryHours', 24);
        $this->RegisterPropertyBoolean('TileShowStaff', true);
        $this->RegisterPropertyInteger('TileTheme', 3);
        $this->RegisterPropertyInteger('TileLayout', 0);
        $this->RegisterPropertyBoolean('TileSkyObjects', true);
        $this->RegisterPropertyBoolean('TileShowBoat', true);
        $this->RegisterPropertyBoolean('TileShowFish', true);
        $this->RegisterPropertyBoolean('TileShowInsights', true);
        $this->RegisterPropertyInteger('TileBackground', 0);
        $this->RegisterPropertyInteger('TileBackgroundDim', 35);
        $this->RegisterPropertyBoolean('TileEffects', true);

        $this->RegisterAttributeString('StationCache', '[]');
        $this->RegisterAttributeString('StationListError', '');
        $this->RegisterAttributeString('CharIdents', '[]');
        $this->RegisterAttributeString('LastStation', '');
        $this->RegisterAttributeString('TileData', '{}');
        $this->RegisterAttributeBoolean('WarningActive', false);
        $this->RegisterAttributeString('BackfillDone', '');
        $this->RegisterAttributeString('DailyStats', '{}');

        $this->RegisterPropertyBoolean('TileReduceMotion', false);

        $this->RegisterAttributeInteger('LastMeasurement', 0);
        $this->RegisterAttributeInteger('FailCount', 0);

        // Der Timer fragt nur ab und rechnet nur bei neuen Messwerten (siehe Poll)
        $this->RegisterTimer('Update', 0, 'PEGEL_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Kachel-Visualisierung (HTML-SDK, ab Symcon 7)
        $this->SetVisualizationType($this->ReadPropertyBoolean('UseTile') ? 1 : 0);

        // Grundvariablen
        $this->Variable('Level', 'Water level', VARIABLETYPE_FLOAT, 'level', 10, true);
        $this->Variable('Trend', 'Trend', VARIABLETYPE_INTEGER, 'trend', 20, true);
        $this->Variable('Change', 'Change in trend period', VARIABLETYPE_FLOAT, 'delta', 30, true);

        // Zuschaltbare Extras
        $this->Variable('MeasuredAt', 'Time of measurement', VARIABLETYPE_INTEGER, 'timestamp', 40, $this->ReadPropertyBoolean('ShowTimestamp'));

        $info = $this->ReadPropertyBoolean('ShowStationInfo');
        $this->Variable('Water', 'Waterway', VARIABLETYPE_STRING, 'text', 50, $info);
        $this->Variable('Station', 'Station', VARIABLETYPE_STRING, 'text', 51, $info);
        $this->Variable('RiverKm', 'River km', VARIABLETYPE_FLOAT, 'km', 52, $info);
        $this->Variable('GaugeZero', 'Gauge zero', VARIABLETYPE_FLOAT, 'nhn', 53, $info);

        $flood = $this->ReadPropertyBoolean('ShowFloodWarning');
        $this->Variable('StateMnwMhw', 'State (MNW/MHW)', VARIABLETYPE_INTEGER, 'state', 60, $flood);
        $this->Variable('StateNswHsw', 'State (NSW/HSW)', VARIABLETYPE_INTEGER, 'state', 61, $flood);
        $this->Variable('FloodWarning', 'Flood warning', VARIABLETYPE_BOOLEAN, 'warning', 62, $flood);

        $this->Variable('Discharge', 'Discharge', VARIABLETYPE_FLOAT, 'discharge', 70, $this->ReadPropertyBoolean('ShowDischarge'));

        $insights = $this->ReadPropertyBoolean('ShowInsights');
        $this->Variable('Insight', 'Assessment', VARIABLETYPE_STRING, 'insight', 80, $insights);
        $this->Variable('Forecast', 'Forecast', VARIABLETYPE_STRING, 'forecast', 81, $insights);

        // Kennwerte entfernen, wenn abgeschaltet oder Station gewechselt
        $uuid = $this->StationID();
        if (!$this->ReadPropertyBoolean('ShowCharacteristics') || $uuid !== $this->ReadAttributeString('LastStation')) {
            $this->RemoveCharacteristicVariables([]);
        }
        if ($uuid !== $this->ReadAttributeString('LastStation')) {
            // neue Station: Warnzustand, Archiv-Nachladen und Messzeit zurücksetzen
            $this->WriteAttributeBoolean('WarningActive', false);
            $this->WriteAttributeString('BackfillDone', '');
            $this->WriteAttributeInteger('LastMeasurement', 0);
        }
        $this->WriteAttributeInteger('FailCount', 0);
        $this->WriteAttributeString('LastStation', $uuid);

        if ($uuid === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            $this->PushTile(['error' => $this->Translate('No gauging station selected')]);
            return;
        }

        $this->SetTimerInterval('Update', $this->IntervalSeconds() * 1000);

        // Station gewählt: „Bitte Station wählen“ sofort durch „lädt“ ersetzen
        $this->SetStatus(102);
        if ($this->ReadAttributeInteger('LastMeasurement') === 0) {
            $this->PushTile(['error' => $this->Translate('Loading …')]);
        }

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->ApplyArchive();
            $this->Update();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED && $this->StationID() !== '') {
            $this->ApplyArchive();
            $this->Update();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'ReloadStations':
                $stations = $this->FetchStations();
                if ($stations === null || count($stations) === 0) {
                    $this->WriteAttributeString('StationListError', $this->apiError !== '' ? $this->apiError : $this->Translate('empty response'));
                    echo $this->Translate('Station list could not be loaded from PEGELONLINE.') . ' (' . $this->ReadAttributeString('StationListError') . ')';
                    return;
                }
                $this->WriteAttributeString('StationCache', json_encode($stations));
                $this->WriteAttributeString('StationListError', '');
                $this->UpdateFormField('StationListError', 'visible', false);
                $this->UpdateStationSelect((string) $Value);
                break;

            case 'FilterStations':
                $this->UpdateStationSelect((string) $Value);
                break;

            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $options = $this->BuildStationOptions($this->ReadPropertyString('WaterFilter'));
        $this->InjectOptions($form['elements'], 'StationUUID', $options);

        // Liste nicht ladbar: Grund anzeigen und auf die Direkteingabe hinweisen
        $error = $this->ReadAttributeString('StationListError');
        $this->InjectProperty($form['elements'], 'StationListError', 'visible', $error !== '');
        $this->InjectProperty($form['elements'], 'StationListError', 'caption', sprintf(
            $this->Translate('Station list could not be loaded (%s). Enter the station directly below or use “Reload station list”.'),
            $error
        ));

        // Benachrichtigung: nur Kachel-Visualisierungen und WebFronts zur Auswahl anbieten
        $visu = $this->VisualizationInstances();
        if (count($visu['modules']) > 0) {
            $this->InjectProperty($form['elements'], 'NotifyTarget', 'validModules', $visu['modules']);
        }
        if ($this->ReadPropertyInteger('NotifyTarget') === 0 && count($visu['VISU']) + count($visu['WFC']) > 0) {
            $auto = $visu['VISU'][0] ?? $visu['WFC'][0];
            $this->InjectProperty($form['elements'], 'NotifyTarget', 'caption', sprintf($this->Translate('Visualization (empty = automatically “%s”)'), IPS_GetName($auto)));
        }

        if ($this->GetSymconLocation() === null) {
            $this->InjectCaption($form['elements'], 'SortByDistance', $this->Translate('Sort by distance (location not set in Symcon under Core Instances → Location)'));
        }
        return json_encode($form);
    }

    /**
     * Fragt den aktuellen Wasserstand und alle aktivierten Extras ab.
     */
    /**
     * Fragt sofort ab und rechnet alles neu, auch ohne neuen Messwert (Schaltfläche, Skripte).
     */
    public function Update(): bool
    {
        return $this->Refresh(true);
    }

    /**
     * Wird vom Timer aufgerufen: rechnet nur, wenn die Station einen neuen Messwert hat.
     */
    public function Poll(): void
    {
        $this->Refresh(false);
    }

    private function Refresh(bool $force): bool
    {
        $uuid = $this->StationID();
        if ($uuid === '') {
            $this->SetStatus(104);
            return false;
        }

        $code = 0;
        $station = $this->ApiRequest('stations/' . rawurlencode($uuid) . '.json?includeTimeseries=true&includeCurrentMeasurement=true&includeCharacteristicValues=true', $code);
        if ($station === null) {
            $this->HandleFailure($code === 404 ? 202 : 201, $code === 404 ? $this->Translate('Gauging station not found') : $this->Translate('PEGELONLINE not reachable'));
            return false;
        }

        $w = $this->FindSeries($station, 'W');
        if ($w === null || !isset($w['currentMeasurement']['value'])) {
            $this->HandleFailure(203, $this->Translate('No current water level'));
            return false;
        }

        $this->WriteAttributeInteger('FailCount', 0);
        $current = $w['currentMeasurement'];
        $level = (float) $current['value'];

        // Messzeitpunkt
        $measuredAt = isset($current['timestamp']) ? strtotime((string) $current['timestamp']) : false;
        $measuredAt = $measuredAt !== false ? $measuredAt : 0;

        // Kein neuer Messwert: nichts neu berechnen, nur den nächsten Abruf planen
        if (!$force && $measuredAt > 0 && $measuredAt === $this->ReadAttributeInteger('LastMeasurement')) {
            $this->SendDebug('Abruf', 'Kein neuer Messwert seit ' . date('H:i', $measuredAt) . ' – nichts zu tun.', 0);
            $this->SetStatus(102);
            $this->ScheduleNext(false, $measuredAt);
            return true;
        }
        $this->WriteAttributeInteger('LastMeasurement', $measuredAt);

        $this->SetValue('Level', $level);
        if ($measuredAt > 0) {
            $this->SetValueIfExists('MeasuredAt', $measuredAt);
        }

        // Messreihe: einmal abrufen, für Tendenz und Kachel-Verlauf
        $history = $this->FetchHistory($uuid);
        $trend = $this->UpdateTrend($history);

        $waterName = trim((string) ($station['water']['longname'] ?? ''));
        $stationName = trim((string) ($station['longname'] ?? $station['shortname'] ?? ''));

        // Stationsinfos
        if ($this->ReadPropertyBoolean('ShowStationInfo')) {
            $this->SetValueIfExists('Water', $waterName);
            $this->SetValueIfExists('Station', $stationName);
            $this->SetValueIfExists('RiverKm', (float) ($station['km'] ?? 0));
            $this->SetValueIfExists('GaugeZero', (float) ($w['gaugeZero']['value'] ?? 0));
        }

        // Kennwerte (MNW, MW, MHW, HSW, …) in cm
        $characteristics = $w['characteristicValues'] ?? [];
        $cvs = [];
        foreach ($characteristics as $cv) {
            $short = strtoupper(trim((string) ($cv['shortname'] ?? '')));
            if ($short !== '' && isset($cv['value']) && ($cv['unit'] ?? 'cm') === 'cm') {
                $cvs[$short] = (float) $cv['value'];
            }
        }

        // Zustand und Hochwasserwarnung (mit Hysterese, auch für Kachel und Benachrichtigung)
        $stateMnwMhw = (string) ($current['stateMnwMhw'] ?? 'unknown');
        $stateNswHsw = (string) ($current['stateNswHsw'] ?? 'unknown');
        $warning = $this->EvaluateWarning($level, $stateNswHsw, $stationName, $waterName);

        if ($this->ReadPropertyBoolean('ShowFloodWarning')) {
            $this->SetValueIfExists('StateMnwMhw', self::STATE_MAP[$stateMnwMhw] ?? 0);
            $this->SetValueIfExists('StateNswHsw', self::STATE_MAP[$stateNswHsw] ?? 0);
            $this->SetValueIfExists('FloodWarning', $warning);
        }

        if ($this->ReadPropertyBoolean('ShowCharacteristics')) {
            $this->UpdateCharacteristics($characteristics);
        }

        // Abfluss
        $discharge = null;
        $q = $this->FindSeries($station, 'Q');
        if ($q !== null && isset($q['currentMeasurement']['value'])) {
            $discharge = (float) $q['currentMeasurement']['value'];
        }
        if ($this->ReadPropertyBoolean('ShowDischarge')) {
            if ($discharge !== null) {
                $this->SetValueIfExists('Discharge', $discharge);
            } else {
                $this->SendDebug('Abfluss', 'Diese Station liefert keinen Abfluss (Q).', 0);
            }
        }

        // Einordnung in Worten und Prognose
        $needInsights = $this->ReadPropertyBoolean('ShowInsights')
            || ($this->ReadPropertyBoolean('UseTile') && $this->ReadPropertyBoolean('TileShowInsights'));
        $insight = '';
        $forecast = '';
        if ($needInsights) {
            $parts = array_filter([
                $this->BuildComparison($level, $cvs),
                $this->BuildRecord($uuid, $level, $history),
            ]);
            $insight = implode(' · ', $parts);
            $forecast = $this->BuildForecast($level, $trend['slope'] ?? 0.0, $cvs, $warning);
        }
        $this->SetValueIfExists('Insight', $insight !== '' ? $insight : '–');
        $this->SetValueIfExists('Forecast', $forecast !== '' ? $forecast : 'keine');

        // Schifffahrt: über HSW eingestellt
        $shipping = null;
        if ($stateNswHsw === 'high') {
            $shipping = 'stopped';
        } elseif ($stateNswHsw === 'normal' || isset($cvs['HSW'])) {
            $shipping = 'free';
        }

        // Archiv beim ersten Lauf für diese Station nachladen
        if ($this->ReadPropertyBoolean('ArchiveEnabled') && $this->ReadAttributeString('BackfillDone') !== $uuid) {
            $this->BackfillArchive();
        }

        // Kachel
        $refs = array_intersect_key($cvs, array_flip(['MNW', 'MW', 'MHW', 'HSW']));
        $warnLevel = $this->ReadPropertyInteger('WarnLevel');
        if ($warnLevel > 0) {
            $refs['Warnung'] = (float) $warnLevel;
        }
        $tileInsights = $this->ReadPropertyBoolean('TileShowInsights');

        $this->PushTile([
            'station'      => $stationName,
            'water'        => $waterName,
            'lat'          => isset($station['latitude']) ? (float) $station['latitude'] : null,
            'lon'          => isset($station['longitude']) ? (float) $station['longitude'] : null,
            'level'        => $level,
            'measuredAt'   => $measuredAt > 0 ? $measuredAt : null,
            'trend'        => $trend['trend'] ?? null,
            'change'       => $trend['change'] ?? null,
            'trendHours'   => max(1, $this->ReadPropertyInteger('TrendHours')),
            'state'        => $stateMnwMhw,
            'warning'      => $warning,
            'shipping'     => $shipping,
            'insight'      => $tileInsights ? $insight : '',
            'forecast'     => $tileInsights ? $forecast : '',
            'discharge'    => $this->ReadPropertyBoolean('ShowDischarge') ? $discharge : null,
            'refs'         => $this->ReadPropertyBoolean('TileShowRefs') ? $refs : [],
            'scaleRefs'    => array_intersect_key($cvs, array_flip(['MNW', 'MW', 'MHW', 'HSW'])),
            'history'      => $this->ReadPropertyBoolean('TileShowHistory') ? $this->Downsample($history, 120) : [],
            'historyHours' => max(1, $this->ReadPropertyInteger('TileHistoryHours')),
            'staff'        => $this->ReadPropertyBoolean('TileShowStaff'),
            'theme'        => $this->ReadPropertyInteger('TileTheme'),
            'layout'       => $this->ReadPropertyInteger('TileLayout'),
            'sky'          => $this->ReadPropertyBoolean('TileSkyObjects'),
            'boat'         => $this->ReadPropertyBoolean('TileShowBoat'),
            'fish'         => $this->ReadPropertyBoolean('TileShowFish'),
            'effects'      => $this->ReadPropertyBoolean('TileEffects'),
            'bgDim'        => min(90, max(0, $this->ReadPropertyInteger('TileBackgroundDim'))),
            'openId'       => $this->VariableID('Level'),
            'calm'         => $this->ReadPropertyBoolean('TileReduceMotion'),
            'error'        => null,
        ]);

        $this->SetStatus(102);
        $this->ScheduleNext(true, $measuredAt);
        return true;
    }

    // ------------------------------------------------------------------
    // Abruf-Takt und Fehlerbehandlung
    // ------------------------------------------------------------------

    private function IntervalSeconds(): int
    {
        return max(5, $this->ReadPropertyInteger('Interval')) * 60;
    }

    /**
     * Plant den nächsten Abruf im Takt der Station: kurz nachdem der nächste Messwert erwartet wird.
     * Ist er noch nicht da, wird nach 3 Minuten erneut nachgesehen.
     */
    private function ScheduleNext(bool $newData, int $measuredAt): void
    {
        $interval = $this->IntervalSeconds();
        $now = time();
        $grace = 180; // PEGELONLINE meldet neue Werte mit einigen Minuten Verzögerung

        if ($newData && $measuredAt > 0) {
            $next = $measuredAt + $interval + $grace - $now;
        } elseif ($measuredAt > 0 && $now - $measuredAt < 2 * $interval + 600) {
            $next = $grace;
        } else {
            // Station liefert gerade keine aktuellen Werte: normaler Takt
            $next = $interval;
        }
        $next = max(60, min($interval, $next));
        $this->SendDebug('Abruf', 'Nächster Abruf in ' . round($next / 60, 1) . ' Min.', 0);
        $this->SetTimerInterval('Update', $next * 1000);
    }

    /**
     * Fehler beim Abruf: nach 2, 5 und 10 Minuten erneut versuchen.
     * Status und Kachel zeigen den Fehler erst ab dem dritten Fehlschlag in Folge.
     */
    private function HandleFailure(int $status, string $message): void
    {
        $fails = $this->ReadAttributeInteger('FailCount') + 1;
        $this->WriteAttributeInteger('FailCount', $fails);

        $retry = [120, 300, 600][min($fails, 3) - 1];
        $this->SendDebug('Fehler', $message . ' (Versuch ' . $fails . ', nächster in ' . ($retry / 60) . ' Min.)', 0);
        $this->SetTimerInterval('Update', $retry * 1000);

        // Ohne bisherige Daten den Fehler sofort zeigen, sonst erst ab dem dritten Fehlschlag
        if ($fails >= 3 || $status === 202 || $this->ReadAttributeInteger('LastMeasurement') === 0) {
            $this->SetStatus($status);
            $this->PushTileError($message);
        }
    }

    // ------------------------------------------------------------------
    // Messreihe und Tendenz
    // ------------------------------------------------------------------

    /**
     * Holt die Messreihe für den größeren der beiden Zeiträume (Tendenz / Kachel-Verlauf).
     *
     * @return array Liste von [Unix-Zeit, Wert], aufsteigend
     */
    private function FetchHistory(string $uuid): array
    {
        $hours = max(1, $this->ReadPropertyInteger('TrendHours'));
        if ($this->ReadPropertyBoolean('UseTile') && $this->ReadPropertyBoolean('TileShowHistory')) {
            $hours = max($hours, min(720, $this->ReadPropertyInteger('TileHistoryHours')));
        }

        $data = $this->ApiRequest('stations/' . rawurlencode($uuid) . '/W/measurements.json?start=PT' . $hours . 'H');
        if ($data === null) {
            $this->SendDebug('Messreihe', 'Nicht abrufbar.', 0);
            return [];
        }

        $points = [];
        foreach ($data as $m) {
            if (!isset($m['timestamp'], $m['value'])) {
                continue;
            }
            $t = strtotime((string) $m['timestamp']);
            if ($t !== false) {
                $points[] = [$t, (float) $m['value']];
            }
        }
        usort($points, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        return $points;
    }

    /**
     * Berechnet Tendenz und Veränderung per linearer Regression über den Tendenz-Zeitraum.
     *
     * @return array|null ['trend' => int, 'change' => float] oder null, wenn nicht berechenbar
     */
    private function UpdateTrend(array $history): ?array
    {
        $hours = max(1, $this->ReadPropertyInteger('TrendHours'));
        if (count($history) < 2) {
            $this->SendDebug('Tendenz', 'Zu wenige Messwerte – Tendenz unverändert.', 0);
            return null;
        }

        // Zeitraum relativ zum letzten Messwert (Stationen melden mit Verzögerung)
        $last = $history[count($history) - 1][0];
        $points = array_values(array_filter($history, static function (array $p) use ($last, $hours): bool {
            return $p[0] >= $last - $hours * 3600;
        }));

        if (count($points) < 2) {
            $this->SendDebug('Tendenz', 'Zu wenige Messwerte (' . count($points) . ') – Tendenz unverändert.', 0);
            return null;
        }

        // Lineare Regression: robust gegen einzelne Ausreißer
        $n = count($points);
        $t0 = $points[0][0];
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($points as [$t, $v]) {
            $x = ($t - $t0) / 3600.0; // Stunden
            $sumX += $x;
            $sumY += $v;
            $sumXY += $x * $v;
            $sumXX += $x * $x;
        }
        $denominator = $n * $sumXX - $sumX * $sumX;
        if ($denominator == 0.0) {
            return null;
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator; // cm pro Stunde
        $spanHours = ($points[$n - 1][0] - $t0) / 3600.0;
        $change = round($slope * $spanHours, 1);

        $threshold = $this->ReadPropertyFloat('TrendThreshold');
        $trend = 0;
        if ($change >= $threshold && $change > 0) {
            $trend = 1;
        } elseif ($change <= -$threshold && $change < 0) {
            $trend = -1;
        }

        $this->SendDebug('Tendenz', sprintf('%d Werte, %.1f h, Steigung %.2f cm/h, Änderung %.1f cm → %d', $n, $spanHours, $slope, $change, $trend), 0);
        $this->SetValue('Trend', $trend);
        $this->SetValue('Change', $change);

        return ['trend' => $trend, 'change' => $change, 'slope' => $slope];
    }

    // ------------------------------------------------------------------
    // Kennwerte
    // ------------------------------------------------------------------

    private function UpdateCharacteristics(array $values): void
    {
        $idents = [];
        $position = 100;
        foreach ($values as $cv) {
            if (!isset($cv['shortname'], $cv['value'])) {
                continue;
            }
            $short = trim((string) $cv['shortname']);
            $ident = 'CV_' . preg_replace('/[^A-Za-z0-9]/', '', $short);
            if ($ident === 'CV_' || in_array($ident, $idents, true)) {
                continue;
            }
            $long = trim((string) ($cv['longname'] ?? ''));
            $name = $long !== '' ? $short . ' (' . $long . ')' : $short;
            $kind = (($cv['unit'] ?? '') === 'cm') ? 'level' : 'plain';

            $this->Variable($ident, $name, VARIABLETYPE_FLOAT, $kind, $position++, true);
            $this->SetValue($ident, (float) $cv['value']);
            $idents[] = $ident;
        }

        $this->RemoveCharacteristicVariables($idents);
        $this->WriteAttributeString('CharIdents', json_encode($idents));
    }

    /**
     * Entfernt alle Kennwert-Variablen, die nicht in $keep stehen.
     */
    private function RemoveCharacteristicVariables(array $keep): void
    {
        $known = json_decode($this->ReadAttributeString('CharIdents'), true) ?: [];
        foreach ($known as $ident) {
            if (!in_array($ident, $keep, true)) {
                $this->MaintainVariable($ident, '', VARIABLETYPE_FLOAT, '', 0, false);
            }
        }
        $this->WriteAttributeString('CharIdents', json_encode(array_values($keep)));
    }

    // ------------------------------------------------------------------
    // Stationsauswahl im Formular
    // ------------------------------------------------------------------

    private function GetCachedStations(): array
    {
        $stations = json_decode($this->ReadAttributeString('StationCache'), true);
        // alte Cache-Einträge ohne Koordinaten neu laden
        if (!is_array($stations) || count($stations) === 0 || !array_key_exists('lat', $stations[0])) {
            $stations = $this->FetchStations() ?? [];
            if (count($stations) > 0) {
                $this->WriteAttributeString('StationCache', json_encode($stations));
                $this->WriteAttributeString('StationListError', '');
            } else {
                $this->WriteAttributeString('StationListError', $this->apiError !== '' ? $this->apiError : $this->Translate('empty response'));
            }
        }
        return $stations;
    }

    /**
     * Gewählte Station: aus der Liste, sonst die Direkteingabe (Name wie „KONSTANZ“ oder UUID).
     */
    private function StationID(): string
    {
        $uuid = $this->ReadPropertyString('StationUUID');
        return $uuid !== '' ? $uuid : trim($this->ReadPropertyString('StationManual'));
    }

    private function InjectProperty(array &$elements, string $name, string $key, mixed $value): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element[$key] = $value;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->InjectProperty($element['items'], $name, $key, $value);
            }
        }
        unset($element);
    }

    private function BuildStationOptions(string $filter): array
    {
        $stations = $this->GetCachedStations();
        if ($this->ReadPropertyBoolean('SortByDistance')) {
            $stations = $this->SortByDistance($stations, $this->GetSymconLocation());
        }
        $filtered = $this->FilterStations($stations, $filter);
        $selected = $this->ReadPropertyString('StationUUID');

        $options = [['caption' => $this->Translate('– please select –'), 'value' => '']];
        $found = false;
        foreach ($filtered as $s) {
            $options[] = ['caption' => $this->StationLabel($s), 'value' => $s['uuid']];
            if ($s['uuid'] === $selected) {
                $found = true;
            }
        }

        // Aktuell gewählte Station immer anbieten, auch wenn der Filter sie ausblendet
        if ($selected !== '' && !$found) {
            $caption = sprintf($this->Translate('Selected station (%s)'), $selected);
            foreach ($stations as $s) {
                if ($s['uuid'] === $selected) {
                    $caption = $this->StationLabel($s);
                    break;
                }
            }
            array_splice($options, 1, 0, [['caption' => $caption, 'value' => $selected]]);
        }

        if (count($stations) === 0) {
            $options[] = ['caption' => $this->Translate('Station list not available – “Reload station list”'), 'value' => ''];
        }

        return $options;
    }

    private function UpdateStationSelect(string $filter): void
    {
        $options = $this->BuildStationOptions($filter);
        $this->UpdateFormField('StationUUID', 'options', json_encode($options));
        $this->SendDebug('Stationsliste', (count($options) - 1) . ' Einträge für Filter "' . $filter . '"', 0);
    }

    private function InjectOptions(array &$elements, string $name, array $options): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element['options'] = $options;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->InjectOptions($element['items'], $name, $options);
            }
        }
        unset($element);
    }

    private function InjectCaption(array &$elements, string $name, string $caption): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element['caption'] = $caption;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->InjectCaption($element['items'], $name, $caption);
            }
        }
        unset($element);
    }

    // ------------------------------------------------------------------
    // Variablen und Darstellungen
    // ------------------------------------------------------------------

    private function Variable(string $ident, string $name, int $type, string $kind, int $position, bool $keep): void
    {
        $this->MaintainVariable($ident, $this->Translate($name), $type, $keep ? $this->Presentation($kind) : '', $position, $keep);
    }

    /**
     * Liefert die Darstellung (Symcon 8+) für eine Variablenart.
     *
     * @return array|string
     */
    private function Presentation(string $kind): array|string
    {
        if ($kind === 'timestamp') {
            return '~UnixTimestamp';
        }
        if ($kind === 'text') {
            return '';
        }
        if ($kind === 'insight' || $kind === 'forecast') {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $kind === 'insight' ? 'scale-balanced' : 'clock'];
        }

        $value = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
        $enum = VARIABLE_PRESENTATION_ENUMERATION;

        switch ($kind) {
            case 'level':
                return ['PRESENTATION' => $value, 'ICON' => 'water', 'SUFFIX' => ' cm', 'DIGITS' => 0];
            case 'delta':
                return ['PRESENTATION' => $value, 'ICON' => 'arrows-up-down', 'SUFFIX' => ' cm', 'DIGITS' => 1];
            case 'discharge':
                return ['PRESENTATION' => $value, 'ICON' => 'water', 'SUFFIX' => ' m³/s', 'DIGITS' => 1];
            case 'km':
                return ['PRESENTATION' => $value, 'ICON' => 'location-dot', 'PREFIX' => 'km ', 'DIGITS' => 1];
            case 'nhn':
                return ['PRESENTATION' => $value, 'ICON' => 'ruler-vertical', 'SUFFIX' => ' m ü. NHN', 'DIGITS' => 2];
            case 'plain':
                return ['PRESENTATION' => $value, 'DIGITS' => 1];
            case 'trend':
                return [
                    'PRESENTATION' => $enum,
                    'ICON'         => 'chart-line',
                    'DISPLAY'      => 2,
                    'OPTIONS'      => json_encode([
                        ['Value' => -1, 'Caption' => $this->Translate('falling'), 'IconActive' => true, 'IconValue' => 'arrow-trend-down', 'Color' => 0x3366FF],
                        ['Value' => 0, 'Caption' => $this->Translate('steady'), 'IconActive' => true, 'IconValue' => 'arrow-right', 'Color' => -1],
                        ['Value' => 1, 'Caption' => $this->Translate('rising'), 'IconActive' => true, 'IconValue' => 'arrow-trend-up', 'Color' => 0xFF9900],
                    ]),
                ];
            case 'state':
                return [
                    'PRESENTATION' => $enum,
                    'ICON'         => 'circle-info',
                    'OPTIONS'      => json_encode([
                        ['Value' => 0, 'Caption' => $this->Translate('unknown'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                        ['Value' => 1, 'Caption' => $this->Translate('low'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0x3366FF],
                        ['Value' => 2, 'Caption' => $this->Translate('normal'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0x00AA00],
                        ['Value' => 3, 'Caption' => $this->Translate('high'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0xFF0000],
                        ['Value' => 4, 'Caption' => $this->Translate('commented'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                        ['Value' => 5, 'Caption' => $this->Translate('outdated'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0x999999],
                    ]),
                ];
            case 'warning':
                return [
                    'PRESENTATION' => $value,
                    'ICON'         => 'triangle-exclamation',
                    'OPTIONS'      => json_encode([
                        ['Value' => false, 'Caption' => $this->Translate('none'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0x00AA00],
                        ['Value' => true, 'Caption' => $this->Translate('Flood'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0xFF0000],
                    ]),
                ];
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function SetValueIfExists(string $ident, mixed $value): void
    {
        if ($this->VariableID($ident) > 0) {
            $this->SetValue($ident, $value);
        }
    }

}
