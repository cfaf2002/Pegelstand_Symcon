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

/**
 * Gemeinsame Funktionen für den Zugriff auf die PEGELONLINE-REST-API (v2)
 * der Wasserstraßen- und Schifffahrtsverwaltung des Bundes (WSV).
 *
 * Doku: https://www.pegelonline.wsv.de/webservice/dokuRestapi
 */
trait PegelonlineTrait
{
    private static string $apiBase = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2/';

    /** Grund des letzten fehlgeschlagenen Abrufs (für Formular und Debug) */
    protected string $apiError = '';

    // Symcon-Kerninstanz "Location Control" (Standort)
    private static string $locationGuid = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    /**
     * Fragt einen API-Pfad ab und liefert das dekodierte JSON.
     *
     * @param string $path      Pfad relativ zur API-Basis inkl. Query-String
     * @param int    $httpCode  liefert den HTTP-Status zurück (0 = keine Verbindung)
     *
     * @return array|null null bei Fehler
     */
    protected function ApiRequest(string $path, int &$httpCode = 0): ?array
    {
        $url = self::$apiBase . $path;
        $this->SendDebug('Request', $url, 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'IP-Symcon Pegelstand-Modul',
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->apiError = '';

        if ($body === false) {
            $this->apiError = 'cURL-Fehler ' . curl_errno($ch) . ': ' . curl_error($ch);
            $this->SendDebug('Fehler', $this->apiError, 0);
            $httpCode = 0;
            return null;
        }

        $this->SendDebug('Response', 'HTTP ' . $httpCode . ' (' . strlen((string) $body) . ' Bytes): ' . substr((string) $body, 0, 1500), 0);

        if ($httpCode !== 200) {
            $this->apiError = 'HTTP ' . $httpCode;
            return null;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            $this->apiError = 'Antwort ist kein gültiges JSON';
            $this->SendDebug('Fehler', $this->apiError, 0);
            return null;
        }
        return $json;
    }

    /**
     * Lädt alle Stationen mit Wasserstands-Zeitreihe.
     *
     * @param bool $withCurrent auch den aktuellen Wasserstand jeder Station mitladen
     *
     * @return array|null Liste von ['uuid','name','water','km','agency','lat','lon','level','levelTime']
     */
    protected function FetchStations(bool $withCurrent = false): ?array
    {
        $path = 'stations.json?timeseries=W';
        if ($withCurrent) {
            $path .= '&includeTimeseries=true&includeCurrentMeasurement=true';
        }
        $data = $this->ApiRequest($path);
        if ($data === null) {
            return null;
        }

        $stations = [];
        foreach ($data as $s) {
            if (!isset($s['uuid'])) {
                continue;
            }
            $level = null;
            $levelTime = null;
            foreach ($s['timeseries'] ?? [] as $ts) {
                if (strtoupper((string) ($ts['shortname'] ?? '')) === 'W' && isset($ts['currentMeasurement']['value'])) {
                    $level = (float) $ts['currentMeasurement']['value'];
                    $t = strtotime((string) ($ts['currentMeasurement']['timestamp'] ?? ''));
                    $levelTime = $t !== false ? $t : null;
                }
            }
            $stations[] = [
                'uuid'      => (string) $s['uuid'],
                'name'      => trim((string) ($s['longname'] ?? $s['shortname'] ?? $s['uuid'])),
                'water'     => trim((string) ($s['water']['longname'] ?? '')),
                'waterShort'=> trim((string) ($s['water']['shortname'] ?? '')),
                'km'        => isset($s['km']) ? (float) $s['km'] : null,
                'agency'    => trim((string) ($s['agency'] ?? '')),
                'lat'       => isset($s['latitude']) ? (float) $s['latitude'] : null,
                'lon'       => isset($s['longitude']) ? (float) $s['longitude'] : null,
                'level'     => $level,
                'levelTime' => $levelTime,
            ];
        }

        usort($stations, static function (array $a, array $b): int {
            return [$a['water'], $a['km'] ?? 0.0, $a['name']] <=> [$b['water'], $b['km'] ?? 0.0, $b['name']];
        });

        return $stations;
    }

    /**
     * Filtert Stationen nach Gewässer (Teilstring, ohne Groß-/Kleinschreibung).
     * Mehrere Gewässer können mit Komma getrennt werden.
     */
    protected function FilterStations(array $stations, string $filter): array
    {
        $terms = array_filter(array_map('trim', explode(',', mb_strtolower($filter))), 'strlen');
        if (count($terms) === 0) {
            return $stations;
        }
        return array_values(array_filter($stations, static function (array $s) use ($terms): bool {
            $water = mb_strtolower($s['water']);
            foreach ($terms as $t) {
                if (mb_strpos($water, $t) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Ergänzt jede Station um 'distance' (km) zum Symcon-Standort und sortiert danach.
     * Ohne bekannten Standort bleibt die Liste unverändert.
     */
    protected function SortByDistance(array $stations, ?array $location): array
    {
        if ($location === null) {
            return $stations;
        }
        foreach ($stations as &$s) {
            $s['distance'] = ($s['lat'] !== null && $s['lon'] !== null)
                ? $this->Distance($location[0], $location[1], (float) $s['lat'], (float) $s['lon'])
                : null;
        }
        unset($s);
        usort($stations, static function (array $a, array $b): int {
            return ($a['distance'] ?? PHP_FLOAT_MAX) <=> ($b['distance'] ?? PHP_FLOAT_MAX);
        });
        return $stations;
    }

    /**
     * Standort aus der Symcon-Kerninstanz „Location Control“ als [Breite, Länge].
     */
    protected function GetSymconLocation(): ?array
    {
        try {
            $ids = IPS_GetInstanceListByModuleID(self::$locationGuid);
            if (count($ids) === 0) {
                return null;
            }
            $config = json_decode(IPS_GetConfiguration($ids[0]), true);
            if (!is_array($config)) {
                return null;
            }
            // ab Symcon 5.5: Eigenschaft "Location" als JSON
            if (isset($config['Location'])) {
                $loc = is_array($config['Location']) ? $config['Location'] : json_decode((string) $config['Location'], true);
                if (isset($loc['latitude'], $loc['longitude'])) {
                    return $this->ValidLocation((float) $loc['latitude'], (float) $loc['longitude']);
                }
            }
            // ältere Versionen
            if (isset($config['Latitude'], $config['Longitude'])) {
                return $this->ValidLocation((float) $config['Latitude'], (float) $config['Longitude']);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Standort', $e->getMessage(), 0);
        }
        return null;
    }

    private function ValidLocation(float $lat, float $lon): ?array
    {
        if ($lat == 0.0 && $lon == 0.0) {
            return null;
        }
        return [$lat, $lon];
    }

    /**
     * Entfernung zweier Koordinaten in km (Haversine).
     */
    protected function Distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected function StationLabel(array $s): string
    {
        $nice = static function (string $t): string {
            return mb_convert_case(mb_strtolower($t), MB_CASE_TITLE, 'UTF-8');
        };
        $label = $nice($s['name']);
        $extra = [];
        if ($s['water'] !== '') {
            $extra[] = $nice($s['water']);
        }
        if ($s['km'] !== null) {
            $extra[] = 'km ' . number_format((float) $s['km'], 1, ',', '');
        }
        if (count($extra) > 0) {
            $label .= ' (' . implode(', ', $extra) . ')';
        }
        if (isset($s['distance']) && $s['distance'] !== null) {
            $label .= ' · ' . number_format((float) $s['distance'], 0, ',', '.') . ' km entfernt';
        }
        return $label;
    }

    protected function FindSeries(array $station, string $shortname): ?array
    {
        foreach ($station['timeseries'] ?? [] as $ts) {
            if (strtoupper((string) ($ts['shortname'] ?? '')) === $shortname) {
                return $ts;
            }
        }
        return null;
    }

    /**
     * PEGELONLINE liefert Namen in Großbuchstaben: "KÖLN" → "Köln".
     */
    protected function Nice(string $text): string
    {
        return mb_convert_case(mb_strtolower($text), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * ID der eigenen Variable mit diesem Ident, 0 wenn es sie (gerade) nicht gibt.
     * Bewusst über IPS_GetObjectIDByIdent, weil GetIDForIdent bei IPSModuleStrict
     * keinen Rückgabewert false für fehlende Variablen kennt.
     */
    protected function VariableID(string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return is_int($id) ? $id : 0;
    }
}
