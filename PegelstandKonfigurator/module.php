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

class PegelstandKonfigurator extends IPSModuleStrict
{
    use PegelonlineTrait;

    private const DEVICE_GUID = '{357D9512-5653-4253-8279-DA569FEB0E1E}';

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('WaterFilter', '');
        $this->RegisterPropertyInteger('MaxDistance', 0);

        $this->RegisterAttributeString('Cache', '');
        $this->RegisterAttributeInteger('CacheTime', 0);
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $stations = $this->CachedStations();
        if ($stations === null) {
            $this->SetStatus(201);
            $stations = [];
        } else {
            $this->SetStatus(102);
        }

        $location = $this->GetSymconLocation();
        $stations = $this->SortByDistance($stations, $location);

        // Vorhandene Instanzen: StationUUID => InstanzID
        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_GUID) as $instanceID) {
            $uuid = (string) IPS_GetProperty($instanceID, 'StationUUID');
            if ($uuid !== '') {
                $existing[$uuid] = $instanceID;
            }
        }

        $filtered = $this->FilterStations($stations, $this->ReadPropertyString('WaterFilter'));
        $maxDistance = $this->ReadPropertyInteger('MaxDistance');
        if ($location !== null && $maxDistance > 0) {
            $filtered = array_values(array_filter($filtered, static function (array $s) use ($maxDistance): bool {
                return isset($s['distance']) && $s['distance'] !== null && $s['distance'] <= $maxDistance;
            }));
        }

        $shown = [];
        $values = [];
        foreach ($filtered as $s) {
            $values[] = $this->BuildRow($s, $existing[$s['uuid']] ?? 0);
            $shown[$s['uuid']] = true;
        }

        // Angelegte Stationen immer zeigen, auch wenn der Filter sie ausblendet
        $byUuid = array_column($stations, null, 'uuid');
        foreach ($existing as $uuid => $instanceID) {
            if (isset($shown[$uuid])) {
                continue;
            }
            $s = $byUuid[$uuid] ?? [
                'uuid'      => $uuid,
                'name'      => IPS_GetName($instanceID),
                'water'     => '',
                'km'        => null,
                'agency'    => '',
                'level'     => null,
                'levelTime' => null,
                'distance'  => null,
            ];
            $values[] = $this->BuildRow($s, $instanceID);
        }

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'Stations') {
                $action['values'] = $values;
                // Mit Standort nach Entfernung sortieren, sonst nach Gewässer
                $action['sort'] = $location !== null
                    ? ['column' => 'distance', 'direction' => 'ascending']
                    : ['column' => 'water', 'direction' => 'ascending'];
            }
        }
        unset($action);

        if ($location === null) {
            foreach ($form['elements'] as &$element) {
                if (($element['name'] ?? '') === 'MaxDistance') {
                    $element['caption'] = $this->Translate('Radius (only with location in Core Instances → Location)');
                }
            }
            unset($element);
        }

        return json_encode($form);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Reload') {
            $this->WriteAttributeInteger('CacheTime', 0);
            $this->ReloadForm();
            return;
        }
        throw new Exception('Ungültiger Ident: ' . $Ident);
    }

    /**
     * Stationsliste mit aktuellen Pegeln, 10 Minuten zwischengespeichert –
     * die Liste ist groß und das Formular soll schnell öffnen.
     */
    private function CachedStations(): ?array
    {
        if (time() - $this->ReadAttributeInteger('CacheTime') < 600) {
            $cached = json_decode($this->ReadAttributeString('Cache'), true);
            if (is_array($cached) && count($cached) > 0) {
                $this->SendDebug('Stationsliste', 'aus dem Zwischenspeicher (' . count($cached) . ' Stationen)', 0);
                return $cached;
            }
        }
        $stations = $this->FetchStations(true);
        if ($stations !== null) {
            $this->WriteAttributeString('Cache', json_encode($stations));
            $this->WriteAttributeInteger('CacheTime', time());
        }
        return $stations;
    }

    private function BuildRow(array $s, int $instanceID): array
    {
        $levelText = '';
        if (isset($s['level']) && $s['level'] !== null) {
            $levelText = number_format((float) $s['level'], 0, ',', '.') . ' cm';
            if (!empty($s['levelTime']) && time() - (int) $s['levelTime'] > 3 * 3600) {
                $levelText .= ' (' . $this->Translate('outdated') . ')';
            }
        }

        return [
            'name'       => $this->Nice($s['name']),
            'water'      => $this->Nice($s['water']),
            'km'         => $s['km'] !== null ? number_format((float) $s['km'], 1, ',', '') : '',
            'distance'   => isset($s['distance']) && $s['distance'] !== null ? round((float) $s['distance'], 1) : '',
            'level'      => $levelText,
            'agency'     => $s['agency'],
            'instanceID' => $instanceID,
            'create'     => [
                'moduleID'      => self::DEVICE_GUID,
                'name'          => sprintf($this->Translate('Gauge %s'), $this->Nice($s['name'])),
                'configuration' => [
                    'StationUUID' => $s['uuid'],
                ],
            ],
        ];
    }
}
