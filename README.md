# Pegelstand

[![IP-Symcon ab 8.2](https://img.shields.io/badge/IP--Symcon-ab_8.2-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
![Modul-Version 1.0](https://img.shields.io/badge/Modul--Version-1.0-informational.svg)
[![Tests](https://github.com/cfaf2002/Pegelstand_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Pegelstand_Symcon/actions/workflows/tests.yml)
![Sprachen: Deutsch, Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777bb4.svg?logo=php&logoColor=white)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Daten: DL-DE Zero 2.0](https://img.shields.io/badge/Daten-DL--DE%E2%86%92Zero--2.0-lightgrey.svg)](https://www.govdata.de/dl-de/zero-2-0)

IP-Symcon-Modul zum Auslesen von Wasserständen der Messstationen der Wasserstraßen- und Schifffahrtsverwaltung des Bundes (WSV) über die offene REST-API von [PEGELONLINE](https://www.pegelonline.wsv.de/).

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [Instanz „Pegelstand“](#4-instanz-pegelstand)
5. [Instanz „Pegelstand Konfigurator“](#5-instanz-pegelstand-konfigurator)
   - [Instanz „Pegelstand Flussband“](#instanz-pegelstand-flussband)
6. [Variablen und Darstellungen](#6-variablen-und-darstellungen)
7. [PHP-Befehle](#7-php-befehle)
8. [Sicherheit und Leistung](#8-sicherheit-und-leistung)
9. [Entwicklung und Tests](#9-entwicklung-und-tests)
10. [Datenquelle](#10-datenquelle)
11. [Changelog](#11-changelog)
12. [Lizenz](#12-lizenz)

## 1. Funktionsumfang

- Aktueller Wasserstand einer frei wählbaren PEGELONLINE-Messstation
- Stationsliste wird live von der API geladen, filterbar nach Gewässer und nach Entfernung zum eigenen Standort sortiert
- Station wird intern über ihre UUID gespeichert (eindeutig, unabhängig von Schreibweisen)
- Tendenz (steigend / gleichbleibend / fallend) aus der Messreihe der letzten Stunden per linearer Regression, mit einstellbarem Zeitraum und Schwelle
- Zuschaltbar je Instanz:
  - Zeitpunkt der Messung
  - Stationsinfos: Gewässer, Stationsname, Fluss-km, Pegelnullpunkt
  - Kennwerte der Station (MNW, MW, MHW, HSW, … – je nachdem, was die Station liefert)
  - Zustand gegenüber MNW/MHW und NSW/HSW sowie Hochwasserwarnung (über HSW der WSV und/oder eigene Warnschwelle)
  - Abfluss (Q) in m³/s, sofern die Station Abfluss misst
- Push-Benachrichtigung bei Hochwasser und Entwarnung (Kachel-Visualisierung oder WebFront), mit Hysterese gegen Dauermeldungen
- Archivierung auf Knopfdruck, inklusive Nachladen der letzten bis zu 30 Tage
- Einordnung in Worten („23 cm unter Mittelwasser · höchster Stand seit 12 Tagen“) und Prognose aus der Tendenz („Warnschwelle in ca. 6 Std.“), auf Wunsch auch als Variablen
- Eigene Kachel für die Kachel-Visualisierung: animiertes Wasser, dessen Füllhöhe dem Pegel folgt, Pegellatte mit cm-Skala, Himmel nach Tageszeit mit Sonne, Mond (echte Mondphase) und Wolken, ein Schiff auf dem Wasser, Kennwert-Linien, Verlaufslinie, Strömung passend zur Tendenz, Regen bei Hochwasser und eine Detailansicht mit Diagramm
- Farbschemas Natur, Dunkel und Hell, eigenes Hintergrundbild, Pegel-Ring für kleine Kacheln
- Flussband: mehrere Stationen eines Flusses nebeneinander, um eine Hochwasserwelle flussabwärts zu verfolgen
- Moderne Symcon-Darstellungen statt Variablenprofilen, Basisklasse IPSModuleStrict, Kachel im Symcon-Design
- Konfigurator mit Entfernung, aktuellem Pegel und Umkreis-Filter zum bequemen Anlegen mehrerer Stationen (eine Instanz pro Station)
- Sparsamer Abruf im Takt der Station: abgefragt wird kurz nach dem erwarteten neuen Messwert, gerechnet und an die Kachel gesendet nur bei neuen Daten
- Robuste Fehlerbehandlung: cURL mit Timeout, Wiederholung nach 2, 5 und 10 Minuten, Fehlerstatus erst nach drei Fehlschlägen in Folge, Debug-Ausgaben
- Kacheln halten ihre Animationen an, solange sie nicht zu sehen sind, und lassen sich für ältere Wandtablets beruhigen
- Deutsch und Englisch (Formulare, Variablen, Meldungen und Kacheln) nach Symcon-Konvention: englische Texte im Modul, deutsche Übersetzung in `locale.json`
- Automatische Tests mit GitHub-Workflow

## 2. Voraussetzungen und Technik

- IP-Symcon ab Version 8.2, empfohlen 9.0
- Für die Push-Benachrichtigungen ein gültiges Symcon-Abo und registrierte Geräte
- Internetzugang zu `www.pegelonline.wsv.de`

Das Modul nutzt die aktuelle Symcon-Technik:

| Technik | Ab Symcon | Wofür |
|---|---|---|
| Basisklasse `IPSModuleStrict` | 8.1 | Strenge Typen in allen Modulfunktionen, robuster unter PHP 8.5 (Symcon 9.0) |
| Darstellungen statt Variablenprofilen | 8.0 | Pegel, Tendenz (mit Pfeilsymbolen), Zustand, Warnung, Abfluss usw. – keine eigenen Profile mehr |
| HTML-SDK-Kachel | 7.1 | Eigene Kachel mit Live-Aktualisierung über `UpdateVisualizationValue` |
| `openObject` im HTML-SDK | 8.2 | „Verlauf öffnen“ in der Detailansicht; im Flussband „Öffnen“ für angelegte Stationen |
| Design der Visualisierung | 9.0 | Farbschema „Symcon-Design“ übernimmt Schrift- und Akzentfarbe des gewählten Designs und erkennt helle und dunkle Designs automatisch |

## 3. Installation

Im Objektbaum unter **Kern Instanzen → Modules** das Repository hinzufügen:

```
https://github.com/cfaf2002/Pegelstand_Symcon
```

Danach eine Instanz **Pegelstand** (einzelne Station) oder **Pegelstand Konfigurator** (mehrere Stationen) anlegen.

## 4. Instanz „Pegelstand“

### Messstation

| Einstellung | Beschreibung |
|---|---|
| Gewässer-Filter | Teilstring des Gewässernamens, mehrere mit Komma (z. B. `Rhein, Mosel`). Leer = alle Stationen |
| Filter anwenden | Schränkt die Auswahlliste sofort ein |
| Stationsliste neu laden | Holt die Stationsliste erneut von PEGELONLINE (sie wird sonst zwischengespeichert) |
| Nach Entfernung sortieren | Sortiert die Auswahl nach Entfernung zu deinem Standort (Kern Instanzen → Location) und zeigt die Entfernung an |
| Messstation | Auswahl der Station |

### Aktualisierung & Tendenz

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Messtakt der Station | 15 Minuten | Die meisten Stationen liefern alle 15 Minuten einen neuen Wert (min. 5 Minuten). Abgefragt wird kurz nach dem erwarteten neuen Wert; ist er noch nicht da, wird nach 3 Minuten erneut nachgesehen. Ohne neuen Messwert rechnet das Modul nichts neu |
| Zeitraum für die Tendenz | 3 Stunden | Über diesen Zeitraum wird die Messreihe ausgewertet |
| Schwelle für steigend/fallend | 2,0 cm | Ab dieser Änderung im Zeitraum gilt der Pegel als steigend bzw. fallend |

### Zusatzfunktionen

| Einstellung | Standard |
|---|---|
| Zeitpunkt der Messung anzeigen | an |
| Stationsinfos anzeigen | aus |
| Kennwerte anzeigen | aus |
| Zustand und Hochwasserwarnung anzeigen | aus |
| Eigene Warnschwelle (cm, 0 = nur HSW) | 0 |
| Abfluss (Q) anzeigen | aus |
| Einordnung und Prognose als Variablen | aus |

Abgeschaltete Extras entfernen ihre Variablen wieder.

### Benachrichtigungen

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Push-Nachricht senden | aus | Benachrichtigung bei Hochwasserwarnung |
| Visualisierung | – | Instanz der Kachel-Visualisierung oder eines WebFronts, über die die Nachricht verschickt wird |
| Hysterese | 5,0 cm | Entwarnung erst, wenn der Pegel um diesen Wert unter der Warnschwelle liegt |
| Auch bei Entwarnung | an | Zusätzliche Nachricht, wenn die Warnung endet |
| Testnachricht senden | – | Schickt sofort eine Probe-Nachricht |

Ausgelöst wird, wenn PEGELONLINE „über HSW“ meldet oder der Pegel die eigene Warnschwelle erreicht. Für Push-Nachrichten braucht Symcon ein gültiges Abo und registrierte Geräte. Antippen der Nachricht öffnet die Pegelstand-Instanz.

### Archiv

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Archivieren und Verlauf nachladen | aus | Schaltet die Archivierung für Pegel (und Abfluss) ein und lädt beim ersten Abruf den Verlauf nach |
| Nachladen der letzten … Tage | 30 | Maximal 30 Tage, mehr liefert PEGELONLINE nicht |
| Verlauf jetzt ins Archiv nachladen | – | Lädt manuell nach, bereits vorhandene Werte werden übersprungen |

### Kachel

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Eigene Kachel verwenden | an | Zeigt die Instanz in der Kachel-Visualisierung als eigene Kachel |
| Farbschema | Symcon-Design | **Symcon-Design:** Farben der gewählten Visualisierung, Wasser in der Akzentfarbe. **Natur:** Himmel nach Tageszeit am Ort der Station. **Dunkel:** immer Nachthimmel. **Hell:** heller Himmel mit dunkler Schrift |
| Darstellung | Automatisch | Szene mit Wasser; bei Kacheln unter 190 × 190 Pixel automatisch der kompakte Pegel-Ring. Beides lässt sich auch fest einstellen |
| Eigenes Hintergrundbild | – | Ein Bild aus dem Objektbaum (Medienobjekt), z. B. ein Foto vom Fluss. Es wird automatisch verkleinert; das Wasser liegt halbtransparent darüber |
| Abdunkeln | 35 % | Abdunkelung des Hintergrundbilds, damit die Schrift lesbar bleibt |
| Kennwert-Linien einzeichnen | an | Gestrichelte Linien für MNW, MW, MHW, HSW (soweit vorhanden) und die eigene Warnschwelle |
| Verlaufslinie anzeigen | an | Messverlauf als Linie im Hintergrund |
| Zeitraum der Verlaufslinie | 24 Stunden | max. 720 Stunden (30 Tage, Grenze der API) |
| Pegellatte | an | Messlatte mit cm-Skala am linken Rand, gelbe Markierung am aktuellen Pegel |
| Sonne, Mond und Wolken | an | Sonne mit Korona und Strahlen nach echtem Sonnenstand, nachts der Mond in der aktuellen Phase, dazu weiche Haufenwolken in zwei Ebenen |
| Schiff | an | Modernes Binnen-Containerschiff (flacher Rumpf, Container mit Wellblech-Struktur, hochgesetztes Steuerhaus mit Panoramaverglasung, Radar, LED-Lichter, Schatten auf dem Wasser) mit Bugwelle und Kielwasser, das langsam flussabwärts fährt; nachts mit beleuchtetem Steuerhaus und Positionslichtern. Liegt der Pegel über HSW, ist die Schifffahrt eingestellt: Das Schiff liegt vor Anker und ein Hinweis erscheint |
| Fischschwarm | an | Ein Schwarm zieht in ruhigen Bahnen durchs Wasser; hält an, wenn die Kachel nicht zu sehen ist |
| Einordnung und Prognose | an | Zeigt die Einordnung in Worten und die Prognose in der Kachel |
| Strömung und Regen | an | Steigt der Pegel, zieht das Wasser schneller und mit Strömungsstreifen; fällt er, beruhigt es sich. Bei Hochwasser regnet es |
| Animationen reduzieren | aus | Schaltet Sterne, Wolken, Strömung, Regen und Schaukeln ab und verlangsamt die Wellen – für ältere Wandtablets |

Die Wasserfarbe zeigt den Zustand: hellblau = niedrig (unter MNW), blau = normal, orange = hoch (über MHW), rot = Hochwasserwarnung, grau = keine oder veraltete Daten (älter als 3 Stunden). Die Skala passt sich automatisch an Pegel, Kennwerte und Verlauf an.

**Wasser:** Die Wasserfläche wird live gezeichnet: überlagerte Wellenzüge (stärker bei steigendem Pegel), ein heller Lichtsaum unter der Oberfläche, schräg einfallende Lichtstrahlen, Schwebeteilchen, Glitzern unter der Sonne und ein silberner Schimmer unter dem Mond. Oben spiegelt sich der Himmel, nach unten wird das Wasser tief.

**Sparsam auf Wandtablets:** Ist die Kachel nicht zu sehen (anderer Raum in der Visualisierung, Bildschirm aus, App im Hintergrund), hält sie alle Animationen an.

**Wert hochzählen:** Bei jedem neuen Messwert läuft die Zahl sichtbar vom alten zum neuen Stand.

**Einordnung und Prognose:** Die Einordnung vergleicht den Pegel mit den Kennwerten der Station (MNW, MW, MHW, HSW) und mit den Tagesständen der letzten 30 Tage. Die Prognose ist eine einfache Hochrechnung der aktuellen Steigung bis zur Warnschwelle (ersatzweise HSW oder MHW), angezeigt bis 48 Stunden im Voraus. Sie ist keine offizielle Hochwasservorhersage.

**Detailansicht:** Antippen der Kachel öffnet ein Diagramm über den eingestellten Zeitraum mit Achsen, Kennwert-Linien sowie aktuellem Wert, Minimum, Maximum und Differenz. „Verlauf öffnen“ springt zur Pegel-Variable mit ihrem Archiv-Diagramm. Das ✕ schließt die Ansicht wieder. Auf sehr kleinen Kacheln blendet die Kachel Nebeninfos automatisch aus.

### Status

| Code | Bedeutung |
|---|---|
| 102 | Aktiv |
| 104 | Keine Messstation gewählt |
| 201 | PEGELONLINE nicht erreichbar oder fehlerhafte Antwort |
| 202 | Messstation nicht gefunden |
| 203 | Station liefert keinen aktuellen Wasserstand |

Kurze Aussetzer von PEGELONLINE führen nicht sofort zu einem Fehlerstatus: Das Modul versucht es nach 2, 5 und 10 Minuten erneut und meldet den Fehler erst beim dritten Fehlschlag in Folge.

## 5. Instanz „Pegelstand Konfigurator“

Listet alle Stationen mit Wasserstandsmessung und legt per Klick eine Pegelstand-Instanz mit vorausgewählter Station an. Bereits angelegte Stationen werden immer angezeigt.

| Einstellung | Beschreibung |
|---|---|
| Gewässer-Filter | wie in der Instanz, mehrere Gewässer mit Komma |
| Umkreis | Zeigt nur Stationen bis zu dieser Entfernung (0 = alle) |

Spalten: Station, Gewässer, Entfernung, aktueller Pegel, Fluss-km und Betreiber. Die Liste wird 10 Minuten zwischengespeichert, damit das Formular schnell öffnet; „Liste und Pegel neu laden“ holt sie sofort neu. Ist in Symcon ein Standort hinterlegt (Kern Instanzen → Location), wird nach Entfernung sortiert, sonst nach Gewässer.

### Instanz „Pegelstand Flussband“

Zeigt mehrere Stationen eines Gewässers als Wassersäulen nebeneinander, geordnet nach Fluss-km (links flussaufwärts, rechts flussabwärts). Jede Säule zeigt den Pegel relativ zu den eigenen Kennwerten der Station: unten mittleres Niedrigwasser (MNW), oben mittleres Hochwasser (MHW). Dadurch sind Stationen mit ganz unterschiedlichen Pegelnullpunkten vergleichbar, und eine Hochwasserwelle wird als ansteigende Linie sichtbar, die mit der Zeit flussabwärts wandert.

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Gewässer | – | Auswahl aus allen Gewässern mit mindestens drei Stationen |
| von / bis Fluss-km | 0 / 0 | Abschnitt des Gewässers (0 = bis zum Ende) |
| Höchstens so viele Stationen | 12 | Bei mehr Stationen wird gleichmäßig verteilt ausgewählt |
| Schleusen-Oberpegel ausblenden | an | Oberpegel (OP) an Staustufen sind staugeregelt und würden das Bild verfälschen |
| Abfrageintervall | 15 Minuten | |
| Farbschema | Symcon-Design | Symcon-Design, Dunkel oder Hell |
| Animationen reduzieren | aus | Schaltet den Schimmer auf den Säulen ab |

In der Kachel:
- **Farbe** wie beim Pegelstand: blau normal, hellblau niedrig, orange hoch, rot über HSW.
- **Schraffierte Säulen** haben nicht alle Kennwerte; ihre Lage ist über MW bzw. HSW geschätzt.
- **Rote Striche** markieren den HSW (höchster Schifffahrtswasserstand) jeder Station. Erreicht die Säule den Strich, ist die Schifffahrt dort eingestellt und die Säule wird rot.
- **Legende** unten in der Kachel erklärt Striche, Farben und Schraffur; auf schmalen Kacheln nur das Wichtigste, auf sehr niedrigen ausgeblendet.
- **Pfeile** an den Werten zeigen, ob die Station in den letzten Stunden um mindestens 2 cm gestiegen oder gefallen ist. Neue Stationen laden ihre letzten 3 Stunden einmalig nach, die Pfeile stimmen also sofort.
- **Antippen** einer Säule zeigt Station, Fluss-km, Pegel, Abstand zum Mittelwasser und Änderung. Ist für die Station schon eine Pegelstand-Instanz angelegt, öffnet „Öffnen“ sie direkt.

Variablen: „Stationen über mittlerem Hochwasser“ und „Stationen über HSW (Schifffahrt eingestellt)“, praktisch für eigene Benachrichtigungen.

## 6. Variablen und Darstellungen

Alle Variablen nutzen die Darstellungen von Symcon (Wertanzeige bzw. Aufzählung mit Symbolen und Farben). Eigene Variablenprofile legt das Modul nicht mehr an.

| Ident | Name | Typ | Darstellung | Bedingung |
|---|---|---|---|---|
| Level | Pegelstand | Float | Wert in cm | immer |
| Trend | Tendenz | Integer | Aufzählung mit Pfeilen | immer |
| Change | Veränderung im Tendenz-Zeitraum | Float | Wert in cm | immer |
| MeasuredAt | Messzeitpunkt | Integer | ~UnixTimestamp | Zeitpunkt |
| Water | Gewässer | String | – | Stationsinfos |
| Station | Station | String | – | Stationsinfos |
| RiverKm | Fluss-km | Float | km | Stationsinfos |
| GaugeZero | Pegelnullpunkt | Float | m ü. NHN | Stationsinfos |
| StateMnwMhw | Zustand (MNW/MHW) | Integer | Aufzählung mit Farben | Hochwasser |
| StateNswHsw | Zustand (NSW/HSW) | Integer | Aufzählung mit Farben | Hochwasser |
| FloodWarning | Hochwasserwarnung | Boolean | keine / Hochwasser | Hochwasser |
| Discharge | Abfluss | Float | m³/s | Abfluss |
| CV_… | z. B. MNW (Mittel der Niedrigwasserstände) | Float | Wert in cm | Kennwerte |
| Insight | Einordnung | String | – | Einordnung und Prognose |
| Forecast | Prognose | String | – | Einordnung und Prognose |

**Tendenz:** -1 = fallend, 0 = gleichbleibend, 1 = steigend
**Zustand:** 0 = unbekannt, 1 = niedrig, 2 = normal, 3 = hoch, 4 = kommentiert, 5 = veraltet

Die Hochwasserwarnung ist aktiv, wenn PEGELONLINE den Zustand NSW/HSW als „hoch“ meldet (über dem höchsten Schifffahrtswasserstand) oder der Pegel die eigene Warnschwelle erreicht. Nicht jede Station hat einen HSW – dann hilft die eigene Schwelle.

## 7. PHP-Befehle

```php
PEGEL_Update(int $InstanzID): bool
```

Fragt den Pegelstand sofort ab und rechnet alles neu, auch ohne neuen Messwert.

```php
PEGEL_Poll(int $InstanzID): void
```

Wird vom Timer aufgerufen: fragt ab und rechnet nur bei einem neuen Messwert. Für eigene Skripte ist meist `PEGEL_Update` die richtige Wahl.

```php
PEGEL_BackfillArchive(int $InstanzID): int
```

Lädt den Verlauf ins Archiv nach und liefert die Anzahl der neuen Werte (-1 bei Fehler).

```php
PEGEL_TestNotification(int $InstanzID): bool
```

Sendet eine Testnachricht an die eingestellte Visualisierung.

```php
PEGELFB_Update(int $InstanzID): bool
```

Aktualisiert das Flussband sofort.

## 8. Sicherheit und Leistung

**Sicherheit**
- Abrufe nur über HTTPS mit Zertifikatsprüfung, höchstens 3 Weiterleitungen (ebenfalls nur HTTPS), Zeitlimits und eine Größengrenze für Antworten.
- Stations- und Gewässernamen aus PEGELONLINE werden in den Kacheln nie als HTML eingesetzt, sondern immer maskiert – auch präparierte Namen können keinen Code in die Visualisierung schleusen.
- Eingaben (Station, Gewässer) werden für die Abfrage-URL kodiert.
- Hintergrundbilder über 40 Megapixel werden nicht dekodiert (Schutz vor Speicherüberlauf).
- Keine Zugangsdaten nötig, keine Daten verlassen Symcon außer den Abrufen bei PEGELONLINE.

**Leistung**
- Abruf im Takt der Station; ohne neuen Messwert wird nichts neu berechnet und nichts an die Kachel geschickt.
- Konfigurator und Stationsliste werden zwischengespeichert, das verkleinerte Hintergrundbild ebenfalls (neu nur bei geändertem Medienobjekt).
- Kachel-Animationen halten an, sobald die Kachel nicht zu sehen ist; der Fischschwarm zeichnet höchstens 30 Bilder pro Sekunde.

## 9. Entwicklung und Tests

Aufbau des Repositorys:

| Pfad | Inhalt |
|---|---|
| `Pegelstand/` | Hauptmodul: Abruf, Tendenz, Variablen, Formular und Kachel (`tile.html`) |
| `PegelstandKonfigurator/` | Konfigurator |
| `PegelstandFlussband/` | Flussband mit eigener Kachel |
| `libs/PegelonlineTrait.php` | gemeinsamer Zugriff auf PEGELONLINE, Standort, Entfernung, Hilfsfunktionen |
| `libs/PegelTileTrait.php` | Kachel: Ausgabe, Hintergrundbild, Datenaufbereitung |
| `libs/PegelArchiveTrait.php` | Archivierung und Nachladen |
| `libs/PegelNotifyTrait.php` | Hochwasserwarnung und Push-Nachrichten |
| `libs/PegelInsightTrait.php` | Einordnung, Rekorde und Prognose |
| `*/locale.json` | deutsche Übersetzung (Symcon-Format, Schlüssel `de`) |
| `tests/` | Testumgebung ohne Symcon, Beispieldaten und Testsuite |

Tests lokal ausführen:

```
php tests/run.php
```

Die Testsuite bildet die Symcon-Basisklasse nach und simuliert PEGELONLINE mit Beispieldaten. Sie prüft unter anderem den Abruf-Takt, das Überspringen ohne neue Messwerte, die Wiederholung bei Fehlern, Hysterese und Benachrichtigungen, das Nachladen ins Archiv ohne Dubletten, Einordnung und Prognose, Konfigurator mit Zwischenspeicher und Umkreis, das Flussband, die englische Übersetzung und ob jedes Formularfeld eine Eigenschaft hat. Mit `DEBUG=1` werden die Debug-Ausgaben der Module angezeigt.

Zusätzlich lädt `tests/stubs.php` die Bibliothek mit den offiziellen [Symcon-Stubs](https://github.com/symcon/SymconStubs) so, wie Symcon es tut, legt alle Instanzen an und öffnet die Formulare:

```
php tests/stubs.php <Pfad zu SymconStubs>
```

Bei jedem Push und Pull-Request prüft GitHub Actions (`.github/workflows/tests.yml`) mit PHP 8.3 und 8.5 die Syntax aller PHP-Dateien und alle JSON-Dateien, führt die Testsuite aus und macht den Ladetest mit den Symcon-Stubs.

## 10. Datenquelle

Daten: [PEGELONLINE](https://www.pegelonline.wsv.de/), Wasserstraßen- und Schifffahrtsverwaltung des Bundes (WSV). Die Rohdaten sind ungeprüft.

## 11. Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.0 | 1 | 04.10.2026 | Erste Version |

## 12. Lizenz

Dieses Modul steht unter der **MIT-Lizenz** (siehe Datei [`LICENSE`](LICENSE)).

Das Modul darf jeder kostenlos nutzen, verändern und weitergeben, auch kommerziell. Bedingung ist nur, dass der Copyright-Hinweis und der Lizenztext in Kopien erhalten bleiben. Eine Gewährleistung gibt es nicht.

Jede Code-Datei trägt einen Lizenzkopf mit `SPDX-License-Identifier: MIT`. Wer das Modul weitergibt oder Teile davon übernimmt, behält diesen Kopf und die Datei `LICENSE` bei.

**Daten:** Die Wasserstandsdaten stammen von [PEGELONLINE](https://www.pegelonline.wsv.de/), einem Dienst der Wasserstraßen- und Schifffahrtsverwaltung des Bundes (WSV). Sie stehen unter der [Datenlizenz Deutschland – Zero – Version 2.0](https://www.govdata.de/dl-de/zero-2-0) und dürfen ohne Einschränkung genutzt werden. Die WSV übernimmt keine Gewähr für Richtigkeit und Aktualität; die Rohdaten sind ungeprüft. Dieses Modul ist kein offizielles Produkt der WSV und steht in keiner Verbindung zu ihr.
