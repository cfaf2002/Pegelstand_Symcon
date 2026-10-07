<?php

declare(strict_types=1);

/**
 * Testumgebung für die Pegelstand-Module – ohne laufendes IP-Symcon.
 *
 * Stellt eine schlanke Nachbildung von IPSModuleStrict und den benötigten
 * Symcon-Funktionen bereit. PEGELONLINE wird über Fixtures simuliert.
 *
 * SPDX-License-Identifier: MIT
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const IPS_KERNELSTARTED = 10001;
const KR_READY = 10103;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

const GUID_ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
const GUID_LOCATION = '{45E97A63-F870-408A-B259-2933F7EABF74}';
const GUID_PEGELSTAND = '{357D9512-5653-4253-8279-DA569FEB0E1E}';

/** Simulierter Symcon-Zustand */
final class Sym
{
    public static array $archive = [];       // VariablenID => [[TimeStamp, Value], …]
    public static array $logging = [];       // VariablenID => bool
    public static array $notifications = []; // gesendete Push-Nachrichten
    public static array $instances = [];     // InstanzID => ['module' => GUID, 'props' => [...]]
    public static ?array $location = [50.73, 7.10];
    public static array $media = [];         // MedienID => Rohdaten
    public static int $mediaReads = 0;       // wie oft Medieninhalt gelesen wurde
    public static array $fixtures = [];      // API-Pfad => Antwort
    public static array $requests = [];      // abgefragte API-Pfade
    public static array $objects = [];       // InstanzID => Modulobjekt
    public static bool $visuRejectsTarget = false; // Visualisierung lehnt Sprungziel ab (Instanz nicht enthalten)
    public static array $translations = [];  // aktive Übersetzung (Standard: Deutsch wie in Symcon, leer = Englisch)

    public static function reset(): void
    {
        self::$archive = [];
        self::$logging = [];
        self::$notifications = [];
        self::$instances = [900 => ['module' => GUID_ARCHIVE, 'props' => []], 901 => ['module' => GUID_LOCATION, 'props' => []]];
        self::$location = [50.73, 7.10];
        self::$media = [];
        self::$mediaReads = 0;
        self::$fixtures = [];
        self::$requests = [];
        self::$objects = [];
        self::$visuRejectsTarget = false;
        // Wie eine deutsche Symcon-Installation: Übersetzung aus locale.json (de)
        self::$translations = json_decode((string) file_get_contents(__DIR__ . '/../Pegelstand/locale.json'), true)['translations']['de'];
    }

    /** Antwort zu einem API-Pfad: erst exakt, dann ohne Query-String (Zeitraum P30D getrennt) */
    public static function api(string $path): ?array
    {
        self::$requests[] = $path;
        if (array_key_exists($path, self::$fixtures)) {
            return self::$fixtures[$path];
        }
        $base = explode('?', $path)[0];
        if (str_contains($path, 'start=P30D') && array_key_exists($base . '#30', self::$fixtures)) {
            return self::$fixtures[$base . '#30'];
        }
        return self::$fixtures[$base] ?? null;
    }
}

// ---------------------------------------------------------------------
// Symcon-Funktionen
// ---------------------------------------------------------------------

function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return array_keys(array_filter(Sym::$instances, static fn (array $i): bool => $i['module'] === $guid));
}

function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false
{
    $module = Sym::$objects[$parent] ?? null;
    return $module !== null && $module->has($ident) ? $module->varId($ident) : false;
}

function IPS_GetInstanceList(): array
{
    return array_keys(Sym::$instances);
}

function IPS_GetInstance(int $id): array
{
    return ['InstanceID' => $id, 'ModuleInfo' => ['ModuleID' => Sym::$instances[$id]['module'] ?? '']];
}

function IPS_GetModule(string $moduleID): array
{
    $prefixes = ['visu' => 'VISU', 'webfront' => 'WFC', GUID_ARCHIVE => 'AC', GUID_LOCATION => 'LOC', GUID_PEGELSTAND => 'PEGEL'];
    return ['ModuleID' => $moduleID, 'Prefix' => $prefixes[$moduleID] ?? ''];
}

function IPS_InstanceExists(int $id): bool
{
    return isset(Sym::$instances[$id]);
}

function IPS_GetProperty(int $id, string $name): mixed
{
    return Sym::$instances[$id]['props'][$name] ?? '';
}

function IPS_GetName(int $id): string
{
    return Sym::$instances[$id]['name'] ?? 'Instanz ' . $id;
}

function IPS_GetConfiguration(int $id): string
{
    if ((Sym::$instances[$id]['module'] ?? '') === GUID_LOCATION && Sym::$location !== null) {
        return json_encode(['Location' => json_encode(['latitude' => Sym::$location[0], 'longitude' => Sym::$location[1]])]);
    }
    return '{}';
}

function IPS_ApplyChanges(int $id): bool
{
    return true;
}

function IPS_MediaExists(int $id): bool
{
    return isset(Sym::$media[$id]);
}

function IPS_GetMediaContent(int $id): string
{
    Sym::$mediaReads++;
    return base64_encode(Sym::$media[$id]);
}

function IPS_GetMedia(int $id): array
{
    return ['MediaID' => $id, 'MediaUpdated' => 1000, 'MediaSize' => strlen(Sym::$media[$id] ?? '')];
}

function AC_GetLoggingStatus(int $archive, int $variable): bool
{
    return Sym::$logging[$variable] ?? false;
}

function AC_SetLoggingStatus(int $archive, int $variable, bool $status): bool
{
    Sym::$logging[$variable] = $status;
    return true;
}

function AC_GetLoggedValues(int $archive, int $variable, int $start, int $end, int $limit): array
{
    return Sym::$archive[$variable] ?? [];
}

function AC_AddLoggedValues(int $archive, int $variable, array $values): bool
{
    Sym::$archive[$variable] = array_merge(Sym::$archive[$variable] ?? [], $values);
    return true;
}

function AC_ReAggregateVariable(int $archive, int $variable): bool
{
    return true;
}

function VISU_PostNotification(int $id, string $title, string $text, string $type, int $target): int|false
{
    if (!isset(Sym::$instances[$id]) || (Sym::$visuRejectsTarget && $target !== 0)) {
        return false;
    }
    Sym::$notifications[] = ['title' => $title, 'text' => $text, 'target' => $target, 'visu' => $id];
    return count(Sym::$notifications);
}

// ---------------------------------------------------------------------
// Nachbildung der Basisklasse
// ---------------------------------------------------------------------

class IPSModuleStrict
{
    public int $InstanceID;
    public array $properties = [];
    public array $attributes = [];
    public array $variables = [];
    public int $status = 0;
    public array $timers = [];
    public int $visualizationType = 0;
    public array $visualizationUpdates = [];
    public array $formUpdates = [];
    public array $writes = [];               // Ident => Anzahl der Schreibvorgänge
    private static int $nextId = 20000;

    public function __construct(int $id)
    {
        $this->InstanceID = $id;
        Sym::$objects[$id] = $this;
    }

    public function Create(): void
    {
    }

    public function ApplyChanges(): void
    {
    }

    // Eigenschaften und Attribute
    protected function RegisterPropertyString(string $n, string $v): void { $this->properties[$n] = $v; }
    protected function RegisterPropertyInteger(string $n, int $v): void { $this->properties[$n] = $v; }
    protected function RegisterPropertyFloat(string $n, float $v): void { $this->properties[$n] = $v; }
    protected function RegisterPropertyBoolean(string $n, bool $v): void { $this->properties[$n] = $v; }
    protected function ReadPropertyString(string $n): string { return (string) $this->properties[$n]; }
    protected function ReadPropertyInteger(string $n): int { return (int) $this->properties[$n]; }
    protected function ReadPropertyFloat(string $n): float { return (float) $this->properties[$n]; }
    protected function ReadPropertyBoolean(string $n): bool { return (bool) $this->properties[$n]; }
    protected function RegisterAttributeString(string $n, string $v): void { $this->attributes[$n] = $v; }
    protected function RegisterAttributeInteger(string $n, int $v): void { $this->attributes[$n] = $v; }
    protected function RegisterAttributeBoolean(string $n, bool $v): void { $this->attributes[$n] = $v; }
    protected function ReadAttributeString(string $n): string { return (string) $this->attributes[$n]; }
    protected function ReadAttributeInteger(string $n): int { return (int) $this->attributes[$n]; }
    protected function ReadAttributeBoolean(string $n): bool { return (bool) $this->attributes[$n]; }
    protected function WriteAttributeString(string $n, string $v): void { $this->attributes[$n] = $v; }
    protected function WriteAttributeInteger(string $n, int $v): void { $this->attributes[$n] = $v; }
    protected function WriteAttributeBoolean(string $n, bool $v): void { $this->attributes[$n] = $v; }

    // Timer, Nachrichten, Status
    protected function RegisterTimer(string $n, int $ms, string $script): void { $this->timers[$n] = ['ms' => $ms, 'script' => $script]; }
    protected function SetTimerInterval(string $n, int $ms): void { $this->timers[$n]['ms'] = $ms; }
    protected function RegisterMessage(int $sender, int $message): void { }
    protected function SetStatus(int $status): void { $this->status = $status; }
    protected function SendDebug(string $msg, string $data, int $format): void
    {
        if (getenv('DEBUG')) {
            echo '    [debug] ' . $msg . ': ' . substr($data, 0, 160) . PHP_EOL;
        }
    }

    // Variablen
    protected function MaintainVariable(string $ident, string $name, int $type, string|array $presentation, int $position, bool $keep): bool
    {
        if ($keep) {
            $this->variables[$ident] = [
                'id'           => $this->variables[$ident]['id'] ?? self::$nextId++,
                'name'         => $name,
                'type'         => $type,
                'presentation' => $presentation,
                'value'        => $this->variables[$ident]['value'] ?? null,
            ];
        } else {
            unset($this->variables[$ident]);
        }
        return true;
    }

    protected function GetIDForIdent(string $ident): int
    {
        if (!isset($this->variables[$ident])) {
            trigger_error('Ident nicht gefunden: ' . $ident, E_USER_WARNING);
            return 0;
        }
        return $this->variables[$ident]['id'];
    }

    protected function SetValue(string $ident, mixed $value): bool
    {
        if (!isset($this->variables[$ident])) {
            throw new RuntimeException('SetValue auf fehlende Variable ' . $ident);
        }
        $this->variables[$ident]['value'] = $value;
        $this->writes[$ident] = ($this->writes[$ident] ?? 0) + 1;
        return true;
    }

    protected function GetValue(string $ident): mixed
    {
        if (!isset($this->variables[$ident])) {
            throw new RuntimeException('GetValue auf fehlende Variable ' . $ident);
        }
        return $this->variables[$ident]['value'];
    }

    // Visualisierung und Formular
    protected function SetVisualizationType(int $type): void { $this->visualizationType = $type; }
    protected function UpdateVisualizationValue(string $value): void { $this->visualizationUpdates[] = $value; }
    protected function UpdateFormField(string $field, string $param, mixed $value): void { $this->formUpdates[] = [$field, $param, $value]; }
    protected function ReloadForm(): void { }
    public array $buffers = [];
    protected function GetBuffer(string $name): string { return $this->buffers[$name] ?? ''; }
    protected function SetBuffer(string $name, string $data): bool { $this->buffers[$name] = $data; return true; }
    protected function Translate(string $text): string { return Sym::$translations[$text] ?? $text; }

    // Hilfen für Tests
    public function prop(string $n, mixed $v): static { $this->properties[$n] = $v; return $this; }
    public function attr(string $n): mixed { return $this->attributes[$n]; }
    public function setAttr(string $n, mixed $v): void { $this->attributes[$n] = $v; }
    public function value(string $ident): mixed { return $this->variables[$ident]['value'] ?? null; }
    public function has(string $ident): bool { return isset($this->variables[$ident]); }
    public function varId(string $ident): int { return $this->variables[$ident]['id']; }
}

// GetIDForIdent liefert in Symcon bei fehlendem Ident 0/false mit Warnung – im Test still
set_error_handler(static function (int $no, string $str): bool {
    return $no === E_USER_WARNING && str_starts_with($str, 'Ident nicht gefunden');
});

// ---------------------------------------------------------------------
// Module laden, API auf Fixtures umleiten
// ---------------------------------------------------------------------

require __DIR__ . '/../Pegelstand/module.php';
require __DIR__ . '/../PegelstandKonfigurator/module.php';
require __DIR__ . '/../PegelstandFlussband/module.php';

trait FixtureApi
{
    protected function ApiRequest(string $path, int &$httpCode = 0): ?array
    {
        $data = Sym::api($path);
        $httpCode = $data === null ? (Sym::$fixtures['__fail'] ?? 404) : 200;
        return $data;
    }
}

final class TestPegelstand extends Pegelstand
{
    use FixtureApi;
}

final class TestKonfigurator extends PegelstandKonfigurator
{
    use FixtureApi;
}

final class TestFlussband extends PegelstandFlussband
{
    use FixtureApi;
}
