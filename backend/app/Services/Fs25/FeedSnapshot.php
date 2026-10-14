<?php

namespace App\Services\Fs25;

/**
 * Normalized dedicated-server feed summary for Control Center / sync jobs.
 * Never includes the feed code.
 */
final class FeedSnapshot
{
    /**
     * @param  list<array{name: string, author: string|null, version: string|null, hash: string|null, title: string|null}>  $mods
     * @param  list<array{name: string|null, category: string|null, type: string|null, x: float|null, y: float|null, z: float|null}>  $vehicles
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $fetchedAtUtc,
        public readonly string $linkState,
        public readonly ?string $serverName,
        public readonly ?string $mapName,
        public readonly ?int $mapSize,
        public readonly ?string $gameVersion,
        public readonly ?int $playersOnline,
        public readonly ?int $playerCapacity,
        public readonly ?int $dayTimeMs,
        public readonly int $modCount,
        public readonly int $vehicleCountStats,
        public readonly int $vehicleCountSavegame,
        public readonly ?string $careerMoney,
        public readonly array $mods,
        public readonly array $vehicles,
        public readonly int $economyFillTypes,
    ) {}

    /**
     * @param  array<string, mixed>  $statsJson
     */
    public static function fromRaw(
        string $baseUrl,
        string $fetchedAtUtc,
        array $statsJson,
        string $careerXml,
        string $vehiclesXml,
        string $economyXml,
    ): self {
        $server = is_array($statsJson['server'] ?? null) ? $statsJson['server'] : [];
        $server = self::flattenAttrs($server);
        $slots = self::normalizeSlots(
            is_array($statsJson['Slots'] ?? null)
                ? $statsJson['Slots']
                : (is_array($statsJson['slots'] ?? null) ? $statsJson['slots'] : [])
        );
        $modsRaw = $statsJson['Mods']['Mod'] ?? $statsJson['mods']['Mod'] ?? $statsJson['Mods'] ?? [];
        if (isset($modsRaw['name']) || isset($modsRaw['@attributes'])) {
            $modsRaw = [$modsRaw];
        }
        if (! is_array($modsRaw)) {
            $modsRaw = [];
        }

        // Live JSON: flat `vehicles: [{…}]`. XML-ish: `Vehicles.Vehicle` (object or list).
        $vehiclesRaw = $statsJson['Vehicles']['Vehicle']
            ?? $statsJson['vehicles']['Vehicle']
            ?? $statsJson['Vehicles']
            ?? $statsJson['vehicles']
            ?? [];
        if (isset($vehiclesRaw['name']) || isset($vehiclesRaw['x']) || isset($vehiclesRaw['@attributes'])) {
            $vehiclesRaw = [$vehiclesRaw];
        }
        if (! is_array($vehiclesRaw)) {
            $vehiclesRaw = [];
        } else {
            $vehiclesRaw = array_values(array_filter($vehiclesRaw, 'is_array'));
        }

        $mods = [];
        foreach ($modsRaw as $mod) {
            if (! is_array($mod)) {
                continue;
            }
            $attrs = is_array($mod['@attributes'] ?? null) ? $mod['@attributes'] : $mod;
            $mods[] = [
                'name' => self::str($attrs['name'] ?? $mod['name'] ?? null) ?? '',
                'author' => self::str($attrs['author'] ?? $mod['author'] ?? null),
                'version' => self::str($attrs['version'] ?? $mod['version'] ?? null),
                'hash' => self::str($attrs['hash'] ?? $mod['hash'] ?? null),
                'title' => self::str($attrs['title'] ?? $mod['title'] ?? null),
            ];
        }

        $vehicles = [];
        foreach ($vehiclesRaw as $vehicle) {
            if (! is_array($vehicle)) {
                continue;
            }
            $attrs = is_array($vehicle['@attributes'] ?? null) ? $vehicle['@attributes'] : $vehicle;
            $vehicles[] = [
                'name' => self::str($attrs['name'] ?? $vehicle['name'] ?? null),
                'category' => self::str($attrs['category'] ?? $vehicle['category'] ?? null),
                'type' => self::str($attrs['type'] ?? $vehicle['type'] ?? null),
                'x' => self::float($attrs['x'] ?? $vehicle['x'] ?? null),
                'y' => self::float($attrs['y'] ?? $vehicle['y'] ?? null),
                'z' => self::float($attrs['z'] ?? $vehicle['z'] ?? null),
            ];
        }

        $capacity = self::int($slots['capacity'] ?? $server['capacity'] ?? null);
        $numUsed = self::int($slots['numUsed'] ?? $server['numUsed'] ?? null);
        if ($numUsed === null && isset($slots['usedCount'])) {
            $numUsed = self::int($slots['usedCount']);
        }

        $saveVehicleCount = 0;
        if ($vehiclesXml !== '' && preg_match_all('/<vehicle\b/i', $vehiclesXml, $m)) {
            $saveVehicleCount = count($m[0]);
        }

        $economyCount = 0;
        if ($economyXml !== '' && preg_match_all('/<fillType\b/i', $economyXml, $m)) {
            $economyCount = count($m[0]);
        }

        $careerMoney = null;
        if ($careerXml !== '' && preg_match('/<money>([^<]+)<\/money>/i', $careerXml, $m)) {
            $careerMoney = trim($m[1]);
        }

        return new self(
            baseUrl: $baseUrl,
            fetchedAtUtc: $fetchedAtUtc,
            linkState: 'online',
            serverName: self::str($server['name'] ?? $statsJson['name'] ?? null),
            mapName: self::str($server['mapName'] ?? $server['map'] ?? null),
            mapSize: self::int($server['mapSize'] ?? null),
            gameVersion: self::str($server['version'] ?? $server['game'] ?? null),
            playersOnline: $numUsed,
            playerCapacity: $capacity,
            dayTimeMs: self::int($server['dayTime'] ?? null),
            modCount: count($mods),
            vehicleCountStats: count($vehicles),
            vehicleCountSavegame: $saveVehicleCount,
            careerMoney: $careerMoney,
            mods: $mods,
            vehicles: $vehicles,
            economyFillTypes: $economyCount,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'fetchedAtUtc' => $this->fetchedAtUtc,
            'linkState' => $this->linkState,
            'serverName' => $this->serverName,
            'mapName' => $this->mapName,
            'mapSize' => $this->mapSize,
            'gameVersion' => $this->gameVersion,
            'playersOnline' => $this->playersOnline,
            'playerCapacity' => $this->playerCapacity,
            'dayTimeMs' => $this->dayTimeMs,
            'modCount' => $this->modCount,
            'vehicleCountStats' => $this->vehicleCountStats,
            'vehicleCountSavegame' => $this->vehicleCountSavegame,
            'careerMoney' => $this->careerMoney,
            'economyFillTypes' => $this->economyFillTypes,
            'mods' => $this->mods,
            'vehicles' => $this->vehicles,
        ];
    }

    /**
     * Giants JSON often nests XML attributes under @attributes.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function flattenAttrs(array $node): array
    {
        $attrs = is_array($node['@attributes'] ?? null) ? $node['@attributes'] : [];
        $flat = $node;
        foreach ($attrs as $key => $value) {
            if (! array_key_exists($key, $flat) || $flat[$key] === null || $flat[$key] === '') {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * JSON feed (FS25) uses lowercase `slots` with `used` + `players[]`.
     * XML attributes often appear as `numUsed` / `Slot` (or under @attributes).
     *
     * @param  array<string, mixed>  $slots
     * @return array{capacity: int|null, numUsed: int|null}
     */
    private static function normalizeSlots(array $slots): array
    {
        $flat = self::flattenAttrs($slots);
        $capacity = self::int($flat['capacity'] ?? null);
        // Live JSON: "used"; XML-ish JSON: "numUsed"
        $numUsed = self::int($flat['numUsed'] ?? $flat['num_used'] ?? $flat['used'] ?? null);

        $slotRaw = $flat['Slot'] ?? $flat['slot'] ?? $flat['players'] ?? $flat['Players'] ?? null;
        if ($slotRaw !== null) {
            if (isset($slotRaw['isUsed']) || isset($slotRaw['@attributes']) || isset($slotRaw['name'])) {
                $slotRaw = [$slotRaw];
            }
            if (is_array($slotRaw)) {
                // Ignore non-list keys if mixed; count numeric entries.
                $list = array_values(array_filter($slotRaw, 'is_array'));
                if ($capacity === null) {
                    $capacity = count($list);
                }
                if ($numUsed === null) {
                    $used = 0;
                    foreach ($list as $slot) {
                        $slot = self::flattenAttrs($slot);
                        $flag = $slot['isUsed'] ?? $slot['used'] ?? null;
                        if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true' || $flag === 'True') {
                            $used++;
                        }
                    }
                    $numUsed = $used;
                }
            }
        }

        return [
            'capacity' => $capacity,
            'numUsed' => $numUsed,
        ];
    }

    private static function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $s === '' ? null : $s;
    }

    private static function int(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function float(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
