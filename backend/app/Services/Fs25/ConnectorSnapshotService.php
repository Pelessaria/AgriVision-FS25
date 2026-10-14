<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Pull AgriVision connector files from host modSettings (uniqueId inventory + icons).
 */
final class ConnectorSnapshotService
{
    public const MOD_FOLDER = 'FS25_AgriVisionConnector';

    /**
     * @return array{
     *   vehicles: list<array<string, mixed>>,
     *   icons: list<array{key: string, name: string|null, author: string|null, version: string|null, iconFile: string}>,
     *   basePath: string|null
     * }
     */
    public function read(Workspace $workspace): array
    {
        $empty = ['vehicles' => [], 'icons' => [], 'basePath' => null];
        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if (! $access || $access->status !== 'ok') {
            return $empty;
        }

        $modSettings = trim((string) ($access->remote_mod_settings_path ?? ''));
        if ($modSettings === '') {
            return $empty;
        }

        try {
            $client = new HostFileClient($access);
        } catch (Throwable) {
            return $empty;
        }

        $base = rtrim(str_replace('\\', '/', $modSettings), '/').'/'.self::MOD_FOLDER;
        $vehiclesPath = $base.'/state/vehicles_full.xml';
        $manifestPath = $base.'/state/icons_manifest.xml';

        $vehicles = [];
        try {
            if ($client->fileExists($vehiclesPath)) {
                $vehicles = ConnectorVehiclesParser::parse($client->read($vehiclesPath));
            }
        } catch (Throwable) {
            // optional enrichment
        }

        $icons = [];
        try {
            if ($client->fileExists($manifestPath)) {
                $icons = ConnectorIconsParser::parse($client->read($manifestPath));
                $this->cacheIcons($client, $workspace, $base, $icons);
            }
        } catch (Throwable) {
            // optional
        }

        return [
            'vehicles' => $vehicles,
            'icons' => $icons,
            'basePath' => $base,
        ];
    }

    public function cachedIconPath(Workspace $workspace, string $modKey): ?string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $modKey) ?? 'icon';
        $dir = storage_path('app/connector/'.$workspace->public_id.'/icons');
        foreach (['png', 'jpg', 'jpeg', 'webp', 'dds'] as $ext) {
            $path = $dir.'/'.$safe.'.'.$ext;
            if (is_file($path)) {
                return $path;
            }
        }

        // Manifest may store key without extension in filename.
        $matches = glob($dir.'/'.$safe.'.*') ?: [];

        return $matches[0] ?? null;
    }

    /**
     * @param  list<array{key: string, name: string|null, author: string|null, version: string|null, iconFile: string}>  $icons
     */
    private function cacheIcons(HostFileClient $client, Workspace $workspace, string $base, array $icons): void
    {
        if ($icons === []) {
            return;
        }

        $dir = storage_path('app/connector/'.$workspace->public_id.'/icons');
        File::ensureDirectoryExists($dir);

        foreach (array_slice($icons, 0, 80) as $icon) {
            $rel = ltrim(str_replace('\\', '/', $icon['iconFile']), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $remote = str_starts_with($rel, 'icons/')
                ? $base.'/'.$rel
                : $base.'/icons/'.basename($rel);
            $safeKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $icon['key']) ?? 'icon';
            $ext = pathinfo($rel, PATHINFO_EXTENSION) ?: 'png';
            $local = $dir.'/'.$safeKey.'.'.$ext;
            if (is_file($local) && filesize($local) > 0) {
                continue;
            }
            try {
                if (! $client->fileExists($remote)) {
                    continue;
                }
                File::put($local, $client->read($remote));
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * Prefer connector rows by uniqueId; fill gaps from savegame meta.
     *
     * @param  list<array<string, mixed>>  $connector
     * @param  list<array<string, mixed>>  $savegame
     * @return list<array<string, mixed>>
     */
    public static function mergeMeta(array $connector, array $savegame): array
    {
        if ($connector === []) {
            return $savegame;
        }
        if ($savegame === []) {
            return $connector;
        }

        $byId = [];
        foreach ($savegame as $row) {
            $id = $row['uniqueId'] ?? null;
            if (is_string($id) && $id !== '') {
                $byId[$id] = $row;
            }
        }
        foreach ($connector as $row) {
            $id = $row['uniqueId'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $prev = $byId[$id] ?? [];
            foreach ($row as $key => $value) {
                if ($value !== null) {
                    $prev[$key] = $value;
                }
            }
            $byId[$id] = $prev;
        }

        $out = array_values($byId);
        foreach ($savegame as $row) {
            $id = $row['uniqueId'] ?? null;
            if (! is_string($id) || $id === '') {
                $out[] = $row;
            }
        }

        return $out;
    }
}
