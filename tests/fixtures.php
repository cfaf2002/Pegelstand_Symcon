<?php

declare(strict_types=1);

/**
 * Beispieldaten im Format der PEGELONLINE-REST-API (v2).
 *
 * SPDX-License-Identifier: MIT
 */

/** Station Konstanz mit Wasserstand, Abfluss und Kennwerten */
function fixtureStation(float $level, int $measuredAt, string $stateMnwMhw = 'normal', string $stateNswHsw = 'normal'): array
{
    return [
        'uuid'      => 'uuid-k',
        'shortname' => 'KONSTANZ',
        'longname'  => 'KONSTANZ',
        'km'        => 0.0,
        'latitude'  => 47.66,
        'longitude' => 9.18,
        'water'     => ['shortname' => 'BODENSEE', 'longname' => 'BODENSEE'],
        'timeseries' => [
            [
                'shortname'          => 'W',
                'unit'               => 'cm',
                'currentMeasurement' => ['timestamp' => date('c', $measuredAt), 'value' => $level, 'stateMnwMhw' => $stateMnwMhw, 'stateNswHsw' => $stateNswHsw],
                'gaugeZero'          => ['unit' => 'm. ü. NHN', 'value' => 391.89],
                'characteristicValues' => [
                    ['shortname' => 'MNW', 'longname' => 'Mittel der Niedrigwasserstände ', 'unit' => 'cm', 'value' => 262.0],
                    ['shortname' => 'MW', 'longname' => 'Mittel der Tageswasserstände ', 'unit' => 'cm', 'value' => 341.0],
                    ['shortname' => 'MHW', 'longname' => 'Mittel der Hochwasserstände', 'unit' => 'cm', 'value' => 420.0],
                ],
            ],
            [
                'shortname'          => 'Q',
                'unit'               => 'm3/s',
                'currentMeasurement' => ['timestamp' => date('c', $measuredAt), 'value' => 123.4],
            ],
        ],
    ];
}

/** Messreihe: gleichmäßig steigend um $perHour cm/h über $hours Stunden bis $now */
function fixtureSeries(int $now, int $hours, float $end, float $perHour, int $step = 900): array
{
    $rows = [];
    for ($t = $now - $hours * 3600; $t <= $now; $t += $step) {
        $rows[] = ['timestamp' => date('c', $t), 'value' => round($end - ($now - $t) / 3600 * $perHour, 1)];
    }
    return $rows;
}

/** Stationsliste für Auswahl und Konfigurator */
function fixtureStationList(int $now): array
{
    return [
        ['uuid' => 'uuid-k', 'longname' => 'KONSTANZ', 'km' => 0.0, 'agency' => 'RP FREIBURG', 'latitude' => 47.66, 'longitude' => 9.18,
         'water' => ['shortname' => 'BODENSEE', 'longname' => 'BODENSEE'],
         'timeseries' => [['shortname' => 'W', 'currentMeasurement' => ['timestamp' => date('c', $now - 600), 'value' => 320.0]]]],
        ['uuid' => 'uuid-b', 'longname' => 'BONN', 'km' => 654.8, 'agency' => 'WSA RHEIN', 'latitude' => 50.74, 'longitude' => 7.10,
         'water' => ['shortname' => 'RHEIN', 'longname' => 'RHEIN'],
         'timeseries' => [['shortname' => 'W', 'currentMeasurement' => ['timestamp' => date('c', $now - 600), 'value' => 412.0]]]],
        ['uuid' => 'uuid-c', 'longname' => 'KÖLN', 'km' => 688.0, 'agency' => 'WSA RHEIN', 'latitude' => 50.94, 'longitude' => 6.96,
         'water' => ['shortname' => 'RHEIN', 'longname' => 'RHEIN'],
         'timeseries' => [['shortname' => 'W', 'currentMeasurement' => ['timestamp' => date('c', $now - 600), 'value' => 380.0]]]],
    ];
}

/** Mosel-Abschnitt mit Schleusenpegeln (OP/UP) und einer Hochwasserwelle */
function fixtureMosel(int $now): array
{
    $defs = [
        ['KOBLENZ OP', 2.1, [], 201],
        ['LEHMEN UP', 20.4, ['HSW' => 715], 240],
        ['COCHEM', 51.6, ['MNW' => 210, 'MW' => 330, 'MHW' => 648, 'HSW' => 600], 320],
        ['ZELTINGEN UP', 123.4, ['MNW' => 228, 'MHW' => 780, 'HSW' => 695], 520],
        ['WINTRICH OP', 141.7, [], 900],
        ['TRIER UP', 195.3, ['MNW' => 200, 'MW' => 320, 'MHW' => 730, 'HSW' => 695], 640],
        ['GREVENMACHER UP', 212.5, ['HSW' => 520], 530],
        ['PERL', 241.8, ['MNW' => 190, 'MHW' => 521], 500],
    ];
    $stations = [];
    foreach ($defs as $i => [$name, $km, $cv, $level]) {
        $cvs = [];
        foreach ($cv as $k => $v) {
            $cvs[] = ['shortname' => $k, 'longname' => $k, 'unit' => 'cm', 'value' => $v];
        }
        $stations[] = [
            'uuid'     => 'm' . $i,
            'longname' => $name,
            'km'       => $km,
            'water'    => ['shortname' => 'MOSEL', 'longname' => 'MOSEL'],
            'timeseries' => [[
                'shortname'          => 'W',
                'currentMeasurement' => [
                    'timestamp'   => date('c', $now - 600),
                    'value'       => (float) $level,
                    'stateMnwMhw' => isset($cv['MHW']) && $level >= $cv['MHW'] ? 'high' : 'normal',
                    'stateNswHsw' => isset($cv['HSW']) && $level >= $cv['HSW'] ? 'high' : 'normal',
                ],
                'characteristicValues' => $cvs,
            ]],
        ];
    }
    return $stations;
}
