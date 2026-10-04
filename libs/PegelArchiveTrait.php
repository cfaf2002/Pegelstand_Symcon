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
 * Archiv: Archivierung einschalten und Verlauf nachladen.
 * Baustein des Moduls „Pegelstand“.
 */
trait PegelArchiveTrait
{
    /**
     * Lädt die Messwerte der letzten Tage (max. 30) ins Archiv nach.
     *
     * @return int Anzahl der nachgeladenen Werte, -1 bei Fehler
     */
    public function BackfillArchive(): int
    {
        $uuid = $this->StationID();
        $archive = $this->ArchiveID();
        if ($uuid === '' || $archive === 0) {
            $this->SendDebug('Archiv', 'Keine Station oder kein Archiv vorhanden.', 0);
            return -1;
        }
        $this->ApplyArchive(true);

        $days = min(30, max(1, $this->ReadPropertyInteger('ArchiveBackfillDays')));
        $total = 0;
        $series = ['Level' => 'W'];
        if ($this->VariableID('Discharge') > 0) {
            $series['Discharge'] = 'Q';
        }

        foreach ($series as $ident => $shortname) {
            $variableID = $this->VariableID($ident);
            if ($variableID === 0) {
                continue;
            }
            $code = 0;
            $data = $this->ApiRequest('stations/' . rawurlencode($uuid) . '/' . $shortname . '/measurements.json?start=P' . $days . 'D', $code);
            if ($data === null) {
                if ($code === 404) {
                    continue; // z. B. kein Abfluss an dieser Station
                }
                return -1;
            }

            $start = time() - $days * 86400 - 3600;
            $existing = [];
            foreach (AC_GetLoggedValues($archive, $variableID, $start, time(), 0) as $row) {
                $existing[(int) $row['TimeStamp']] = true;
            }

            $values = [];
            $now = time();
            foreach ($data as $m) {
                $t = isset($m['timestamp']) ? strtotime((string) $m['timestamp']) : false;
                if ($t === false || !isset($m['value']) || $t > $now || isset($existing[$t])) {
                    continue;
                }
                $values[] = ['TimeStamp' => $t, 'Value' => (float) $m['value']];
            }

            if (count($values) > 0) {
                AC_AddLoggedValues($archive, $variableID, $values);
                AC_ReAggregateVariable($archive, $variableID);
            }
            $this->SendDebug('Archiv', $ident . ': ' . count($values) . ' Werte nachgeladen', 0);
            $total += count($values);
        }

        $this->WriteAttributeString('BackfillDone', $uuid);
        return $total;
    }

    private function ArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return count($ids) > 0 ? (int) $ids[0] : 0;
    }

    /**
     * Schaltet die Archivierung für Pegel (und ggf. Abfluss) ein.
     */
    private function ApplyArchive(bool $force = false): void
    {
        if (!$force && !$this->ReadPropertyBoolean('ArchiveEnabled')) {
            return;
        }
        $archive = $this->ArchiveID();
        if ($archive === 0) {
            return;
        }
        $changed = false;
        foreach (['Level', 'Discharge'] as $ident) {
            $variableID = $this->VariableID($ident);
            if ($variableID > 0 && !AC_GetLoggingStatus($archive, $variableID)) {
                AC_SetLoggingStatus($archive, $variableID, true);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($archive);
        }
    }
}
