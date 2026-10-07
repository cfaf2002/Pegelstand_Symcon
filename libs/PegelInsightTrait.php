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
 * Einordnung in Worten, Rekorde der letzten 30 Tage und Prognose.
 * Baustein des Moduls „Pegelstand“.
 */
trait PegelInsightTrait
{
    /**
     * Vergleich mit den Kennwerten, z. B. „23 cm unter Mittelwasser“.
     */
    private function BuildComparison(float $level, array $cvs): string
    {
        $fmt = static function (float $v): string {
            return number_format(abs($v), 0, ',', '.') . ' cm';
        };
        if (isset($cvs['HSW']) && $level >= $cvs['HSW']) {
            return sprintf($this->Translate('%s above the highest navigable water level'), $fmt($level - $cvs['HSW']));
        }
        if (isset($cvs['MHW']) && $level >= $cvs['MHW']) {
            return sprintf($this->Translate('%s above mean high water'), $fmt($level - $cvs['MHW']));
        }
        if (isset($cvs['MNW']) && $level <= $cvs['MNW']) {
            return sprintf($this->Translate('%s below mean low water'), $fmt($cvs['MNW'] - $level));
        }
        if (isset($cvs['MW'])) {
            $diff = $level - $cvs['MW'];
            if (abs($diff) < 3) {
                return $this->Translate('about at mean water level');
            }
            return sprintf($this->Translate($diff > 0 ? '%s above mean water level' : '%s below mean water level'), $fmt($diff));
        }
        return '';
    }

    /**
     * „höchster Stand seit 12 Tagen“ / „niedrigster Stand seit …“ aus Tages-Min/Max der letzten 30 Tage.
     */
    private function BuildRecord(string $uuid, float $level, array $history): string
    {
        $days = $this->UpdateDailyStats($uuid, $history);
        $today = date('Y-m-d');
        unset($days[$today]);
        if (count($days) < 3) {
            return '';
        }
        krsort($days);

        $higher = 0;
        $lower = 0;
        $checkHigh = true;
        $checkLow = true;
        foreach ($days as [$min, $max]) {
            if ($checkHigh && $level > $max) {
                $higher++;
            } else {
                $checkHigh = false;
            }
            if ($checkLow && $level < $min) {
                $lower++;
            } else {
                $checkLow = false;
            }
        }

        $all = count($days) >= 28;
        if ($higher >= 3) {
            return ($higher === count($days) && $all) ? $this->Translate('highest level in over 4 weeks') : sprintf($this->Translate('highest level in %d days'), $higher);
        }
        if ($lower >= 3) {
            return ($lower === count($days) && $all) ? $this->Translate('lowest level in over 4 weeks') : sprintf($this->Translate('lowest level in %d days'), $lower);
        }
        return '';
    }

    /**
     * Pflegt Tages-Minimum/-Maximum der letzten 30 Tage. Einmal täglich wird der volle Verlauf geladen,
     * dazwischen wird der heutige Tag aus der laufenden Messreihe fortgeschrieben.
     *
     * @return array ['Y-m-d' => [min, max]]
     */
    private function UpdateDailyStats(string $uuid, array $history): array
    {
        $stats = json_decode($this->ReadAttributeString('DailyStats'), true) ?: [];
        $today = date('Y-m-d');
        $sameStation = ($stats['uuid'] ?? '') === $uuid;
        $days = $sameStation ? ($stats['days'] ?? []) : [];
        $fails = $sameStation ? (int) ($stats['fails'] ?? 0) : 0;
        $retryAt = $sameStation ? (int) ($stats['retryAt'] ?? 0) : 0;
        $fetched = $sameStation ? (string) ($stats['fetched'] ?? '') : '';

        // nach einem Fehlschlag mit wachsender Wartezeit erneut (wie beim Archiv-Nachladen)
        if ($fetched !== $today && ($fails === 0 || time() >= $retryAt)) {
            $data = $this->ApiRequest('stations/' . rawurlencode($uuid) . '/W/measurements.json?start=P30D');
            if ($data === null) {
                $fails++;
                $wait = min(86400, 3600 * 2 ** min($fails - 1, 5)); // 1, 2, 4, 8, 16, dann 24 Stunden
                $retryAt = time() + $wait;
                $this->SendDebug('Verlauf', '30-Tage-Abruf fehlgeschlagen, nächster Versuch in ' . round($wait / 3600) . ' Std.', 0);
            } else {
                $fails = 0;
                $retryAt = 0;
                $days = [];
                foreach ($data as $m) {
                    $t = isset($m['timestamp']) ? strtotime((string) $m['timestamp']) : false;
                    if ($t !== false && isset($m['value'])) {
                        $this->AddToDay($days, date('Y-m-d', $t), (float) $m['value']);
                    }
                }
                $fetched = $today;
            }
        }

        foreach ($history as [$t, $v]) {
            if (date('Y-m-d', $t) === $today) {
                $this->AddToDay($days, $today, $v);
            }
        }

        // nur 31 Tage behalten
        ksort($days);
        $days = array_slice($days, -31, null, true);

        $save = ['uuid' => $uuid, 'fetched' => $fetched, 'days' => $days];
        if ($fails > 0) {
            $save['fails'] = $fails;
            $save['retryAt'] = $retryAt;
        }
        $this->WriteAttributeString('DailyStats', json_encode($save));
        return $days;
    }

    private function AddToDay(array &$days, string $day, float $value): void
    {
        if (!isset($days[$day])) {
            $days[$day] = [$value, $value];
            return;
        }
        $days[$day] = [min($days[$day][0], $value), max($days[$day][1], $value)];
    }

    /**
     * Hochrechnung aus der aktuellen Steigung, z. B. „Warnschwelle in ca. 6 Std.“.
     */
    private function BuildForecast(float $level, float $slope, array $cvs, bool $warning): string
    {
        $warnLevel = $this->ReadPropertyInteger('WarnLevel');
        if ($warnLevel > 0) {
            $target = (float) $warnLevel;
            $label = $this->Translate('warning threshold');
            $isWarnLevel = true;
        } elseif (isset($cvs['HSW'])) {
            $target = $cvs['HSW'];
            $label = 'HSW';
        } elseif (isset($cvs['MHW'])) {
            $target = $cvs['MHW'];
            $label = $this->Translate('mean high water');
        } else {
            return '';
        }

        if ($slope > 0.05 && $level < $target) {
            $hours = ($target - $level) / $slope;
            return $hours <= 48 ? $label . ' ' . $this->FormatHours($hours) : '';
        }
        if ($slope < -0.05 && $warning) {
            $clear = $target - (!empty($isWarnLevel) ? max(0.0, $this->ReadPropertyFloat('NotifyHysteresis')) : 0.0);
            if ($level > $clear) {
                $hours = ($level - $clear) / -$slope;
                return $hours <= 48 ? sprintf($this->Translate('below %s %s'), $label, $this->FormatHours($hours)) : '';
            }
        }
        return '';
    }

    private function FormatHours(float $hours): string
    {
        if ($hours < 1) {
            return $this->Translate('in less than 1 h');
        }
        return sprintf($this->Translate('in approx. %d h'), (int) round($hours));
    }
}
