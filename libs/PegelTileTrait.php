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
 * Kachel: HTML-SDK-Ausgabe, Hintergrundbild und Datenaufbereitung.
 * Baustein des Moduls „Pegelstand“.
 */
trait PegelTileTrait
{
    /**
     * Liefert das HTML der Kachel für die Kachel-Visualisierung (ab Symcon 7).
     */
    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/../Pegelstand/tile.html');
        $initial = $this->ReadAttributeString('TileData');
        $html = str_replace('/*INITIAL_DATA*/null', json_encode($initial), $html);
        return str_replace('/*BACKGROUND*/null', json_encode($this->BackgroundDataUri()), $html);
    }

    private function PushTile(array $data): void
    {
        $json = json_encode($data);
        if ($json === $this->ReadAttributeString('TileData')) {
            return; // unverändert: nichts an die Visualisierung senden
        }
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('UseTile')) {
            $this->UpdateVisualizationValue($json);
        }
    }

    /**
     * Zeigt einen Fehler in der Kachel an, behält aber die letzten Werte.
     */
    private function PushTileError(string $message): void
    {
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        $data['error'] = $message;
        $this->PushTile($data);
    }

    /**
     * Eigenes Hintergrundbild als data-URI, mit GD auf max. 900 px verkleinert.
     */
    private function BackgroundDataUri(): ?string
    {
        $mediaID = $this->ReadPropertyInteger('TileBackground');
        if ($mediaID <= 0 || !IPS_MediaExists($mediaID)) {
            return null;
        }
        $raw = base64_decode((string) IPS_GetMediaContent($mediaID), true);
        if ($raw === false || $raw === '') {
            return null;
        }

        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($raw);
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                $scale = min(1.0, 900 / max($w, $h));
                if ($scale < 1.0) {
                    $nw = (int) round($w * $scale);
                    $nh = (int) round($h * $scale);
                    $small = imagecreatetruecolor($nw, $nh);
                    imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    $img = $small;
                }
                ob_start();
                imagejpeg($img, null, 80);
                $jpeg = (string) ob_get_clean();
                return 'data:image/jpeg;base64,' . base64_encode($jpeg);
            }
        }

        // ohne GD: Original nur, wenn es klein genug ist
        if (strlen($raw) > 1500000) {
            $this->SendDebug('Hintergrund', 'Bild zu groß und GD nicht verfügbar.', 0);
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw) ?: 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }

    /**
     * Reduziert die Messreihe auf höchstens $max Punkte (Mittelwert je Abschnitt).
     */
    private function Downsample(array $points, int $max): array
    {
        $n = count($points);
        if ($n <= $max) {
            return array_map(static function (array $p): array {
                return [$p[0], round($p[1], 1)];
            }, $points);
        }
        $result = [];
        $bucket = $n / $max;
        for ($i = 0; $i < $max; $i++) {
            $from = (int) floor($i * $bucket);
            $to = (int) floor(($i + 1) * $bucket);
            $slice = array_slice($points, $from, max(1, $to - $from));
            $sum = 0.0;
            foreach ($slice as $p) {
                $sum += $p[1];
            }
            $result[] = [$slice[(int) floor(count($slice) / 2)][0], round($sum / count($slice), 1)];
        }
        // letzten echten Wert immer behalten
        $result[count($result) - 1] = [$points[$n - 1][0], round($points[$n - 1][1], 1)];
        return $result;
    }
}
