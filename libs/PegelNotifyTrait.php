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
 * Hochwasserwarnung mit Hysterese und Push-Benachrichtigungen.
 * Baustein des Moduls „Pegelstand“.
 */
trait PegelNotifyTrait
{
    /**
     * Sendet eine Testnachricht an die eingestellte Visualisierung.
     */
    public function TestNotification(): bool
    {
        $ok = $this->Notify($this->Translate('Pegelstand'), $this->Translate('Testnachricht vom Pegelstand-Modul.'), true);
        echo $ok ? $this->Translate('Testnachricht wurde gesendet.') : $this->Translate('Senden fehlgeschlagen – Ziel-Instanz, Abo und registrierte Geräte prüfen (siehe Debug).');
        return $ok;
    }

    /**
     * Ermittelt den Warnzustand mit Hysterese und benachrichtigt bei Wechseln.
     */
    private function EvaluateWarning(float $level, string $stateNswHsw, string $stationName, string $waterName): bool
    {
        $warnLevel = $this->ReadPropertyInteger('WarnLevel');
        $hysteresis = max(0.0, $this->ReadPropertyFloat('NotifyHysteresis'));
        $hswHigh = ($stateNswHsw === 'high');
        $overLevel = $warnLevel > 0 && $level >= $warnLevel;

        $active = $this->ReadAttributeBoolean('WarningActive');
        $place = $stationName . ($waterName !== '' ? ' (' . $this->Nice($waterName) . ')' : '');
        $place = $this->Nice($place);
        $levelText = number_format($level, 0, ',', '.') . ' cm';

        if (!$active && ($hswHigh || $overLevel)) {
            $active = true;
            $reason = $hswHigh ? $this->Translate('über dem höchsten Schifffahrtswasserstand (HSW)') : sprintf($this->Translate('über der Warnschwelle von %s cm'), number_format($warnLevel, 0, ',', '.'));
            $this->SendDebug('Warnung', 'aktiv: ' . $reason, 0);
            $this->Notify(sprintf($this->Translate('Hochwasser %s'), $this->Nice($stationName)), sprintf($this->Translate('%s: Pegel %s – %s.'), $place, $levelText, $reason));
        } elseif ($active) {
            $belowLevel = $warnLevel <= 0 || $level < $warnLevel - $hysteresis;
            if (!$hswHigh && $belowLevel) {
                $active = false;
                $this->SendDebug('Warnung', 'aufgehoben', 0);
                if ($this->ReadPropertyBoolean('NotifyClear')) {
                    $this->Notify(sprintf($this->Translate('Entwarnung %s'), $this->Nice($stationName)), sprintf($this->Translate('%s: Pegel wieder bei %s.'), $place, $levelText));
                }
            }
        }

        $this->WriteAttributeBoolean('WarningActive', $active);
        return $active;
    }

    /**
     * Sendet eine Push-Nachricht über die Kachel-Visualisierung oder das WebFront.
     */
    private function Notify(string $title, string $text, bool $force = false): bool
    {
        if (!$force && !$this->ReadPropertyBoolean('NotifyEnabled')) {
            return false;
        }
        $target = $this->ReadPropertyInteger('NotifyTarget');
        if ($target <= 0 || !IPS_InstanceExists($target)) {
            $this->SendDebug('Benachrichtigung', 'Keine gültige Visualisierung ausgewählt.', 0);
            return false;
        }

        $title = mb_substr($title, 0, 32);
        $text = mb_substr($text, 0, 256);
        $ok = false;

        try {
            if (function_exists('VISU_PostNotification')) {
                $ok = @VISU_PostNotification($target, $title, $text, 'Warning', $this->InstanceID) !== false;
            }
        } catch (Throwable $e) {
            $this->SendDebug('Benachrichtigung', 'Kachel-Visualisierung: ' . $e->getMessage(), 0);
        }

        if (!$ok) {
            try {
                if (function_exists('WFC_PushNotification')) {
                    $ok = @WFC_PushNotification($target, $title, $text, '', $this->InstanceID) !== false;
                }
            } catch (Throwable $e) {
                $this->SendDebug('Benachrichtigung', 'WebFront: ' . $e->getMessage(), 0);
            }
        }

        $this->SendDebug('Benachrichtigung', ($ok ? 'gesendet: ' : 'fehlgeschlagen: ') . $title . ' – ' . $text, 0);
        return $ok;
    }
}
