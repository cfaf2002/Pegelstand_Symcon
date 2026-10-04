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
     * Sendet eine Testnachricht an die eingestellte (oder automatisch gefundene) Visualisierung.
     */
    public function TestNotification(): bool
    {
        $target = $this->NotifyTarget();
        if ($target === 0) {
            echo $this->Translate('No visualization found. Please select a tile visualization under “Visualization”.');
            return false;
        }
        $ok = $this->Notify($this->Translate('Water level'), $this->Translate('Test message from the water level module.'), true);
        echo $ok
            ? sprintf($this->Translate('Test message sent to “%s”.'), IPS_GetName($target))
            : sprintf($this->Translate('Sending to “%s” failed. Check the Symcon subscription and whether a device is registered for push messages in this visualization (see debug).'), IPS_GetName($target));
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
            $reason = $hswHigh ? $this->Translate('above the highest navigable water level (HSW)') : sprintf($this->Translate('above the warning threshold of %s cm'), number_format($warnLevel, 0, ',', '.'));
            $this->SendDebug('Warnung', 'aktiv: ' . $reason, 0);
            $this->Notify(sprintf($this->Translate('Flood %s'), $this->Nice($stationName)), sprintf($this->Translate('%s: level %s – %s.'), $place, $levelText, $reason));
        } elseif ($active) {
            $belowLevel = $warnLevel <= 0 || $level < $warnLevel - $hysteresis;
            if (!$hswHigh && $belowLevel) {
                $active = false;
                $this->SendDebug('Warnung', 'aufgehoben', 0);
                if ($this->ReadPropertyBoolean('NotifyClear')) {
                    $this->Notify(sprintf($this->Translate('All clear %s'), $this->Nice($stationName)), sprintf($this->Translate('%s: level back at %s.'), $place, $levelText));
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
        $target = $this->NotifyTarget();
        if ($target === 0) {
            $this->SendDebug('Benachrichtigung', 'Keine Visualisierung ausgewählt oder gefunden.', 0);
            return false;
        }

        $title = mb_substr($title, 0, 32);
        $text = mb_substr($text, 0, 256);
        $prefix = $this->ModulePrefix($target);
        $ok = false;

        try {
            if ($prefix === 'VISU' && function_exists('VISU_PostNotification')) {
                // Antippen öffnet die Instanz – das geht nur, wenn sie in der Visualisierung liegt.
                // Sonst lehnt Symcon ab; dann ohne Sprungziel senden.
                $ok = @VISU_PostNotification($target, $title, $text, 'Warning', $this->InstanceID) !== false;
                if (!$ok) {
                    $ok = @VISU_PostNotification($target, $title, $text, 'Warning', 0) !== false;
                }
            } elseif ($prefix === 'WFC' && function_exists('WFC_PushNotification')) {
                $ok = @WFC_PushNotification($target, $title, $text, '', $this->InstanceID) !== false;
                if (!$ok) {
                    $ok = @WFC_PushNotification($target, $title, $text, '', 0) !== false;
                }
            } else {
                $this->SendDebug('Benachrichtigung', 'Instanz ' . $target . ' ist keine Kachel-Visualisierung und kein WebFront (Präfix ' . $prefix . ').', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Benachrichtigung', $e->getMessage(), 0);
        }

        $this->SendDebug('Benachrichtigung', ($ok ? 'gesendet an ' : 'fehlgeschlagen an ') . $target . ': ' . $title . ' – ' . $text, 0);
        return $ok;
    }

    /**
     * Ziel der Nachricht: die ausgewählte Instanz, sonst automatisch die erste Kachel-Visualisierung
     * (ersatzweise ein WebFront).
     */
    private function NotifyTarget(): int
    {
        $target = $this->ReadPropertyInteger('NotifyTarget');
        if ($target > 0 && IPS_InstanceExists($target)) {
            return $target;
        }
        $found = $this->VisualizationInstances();
        return $found['VISU'][0] ?? $found['WFC'][0] ?? 0;
    }

    /**
     * Alle Kachel-Visualisierungen (VISU) und WebFronts (WFC), erkannt am Funktionspräfix.
     *
     * @return array ['VISU' => [IDs], 'WFC' => [IDs], 'modules' => [Modul-GUIDs]]
     */
    private function VisualizationInstances(): array
    {
        $result = ['VISU' => [], 'WFC' => [], 'modules' => []];
        foreach (IPS_GetInstanceList() as $id) {
            $prefix = $this->ModulePrefix($id);
            if ($prefix === 'VISU' || $prefix === 'WFC') {
                $result[$prefix][] = $id;
                $result['modules'][] = IPS_GetInstance($id)['ModuleInfo']['ModuleID'];
            }
        }
        $result['modules'] = array_values(array_unique($result['modules']));
        return $result;
    }

    private function ModulePrefix(int $instanceID): string
    {
        try {
            $module = IPS_GetModule(IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID']);
            return (string) ($module['Prefix'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }
}
