<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Pull AgriVision connector files from host modSettings.
 *
 * Vehicles: cheap XML pull for inventory enrichment.
 * Store icons: frequent incremental sync (missing only) — never on every <img> request.
 */
final class ConnectorSnapshotService
{
    public const MOD_FOLDER = 'FS25_AgriVisionConnector';

    /** Minimum seconds between icon FTP syncs per workspace. */
    public const ICON_SYNC_INTERVAL_SEC = 60;

    /** Max missing icons to fetch (or convert) per sync pass. */
    public const ICON_SYNC_BATCH = 80;

    /**
     * @return array{
     *   vehicles: list<array<string, mixed>>,
     *   icons: list<array{key: string, name: string|null, author: string|null, version: string|null, iconFile: string, kind?: string}>,
     *   basePath: string|null
     * }
     */
    public function read(Workspace $workspace): array
    {
        $result = $this->readVehicles($workspace);
        $this->syncStoreIconsIfDue($workspace);

        return [
            'vehicles' => $result['vehicles'],
            'icons' => [],
            'basePath' => $result['basePath'],
        ];
    }

    /**
     * Vehicles XML only — no icon FTP.
     *
     * @return array{vehicles: list<array<string, mixed>>, basePath: string|null}
     */
    public function readVehicles(Workspace $workspace): array
    {
        $empty = ['vehicles' => [], 'basePath' => null];
        $access = $this->hostAccess($workspace);
        if ($access === null) {
            return $empty;
        }

        $base = $this->connectorBase($access);
        if ($base === null) {
            return $empty;
        }

        try {
            $client = new HostFileClient($access);
        } catch (Throwable) {
            return $empty;
        }

        $vehicles = [];
        $vehiclesPath = $base.'/state/vehicles_full.xml';
        try {
            if ($client->fileExists($vehiclesPath)) {
                $vehicles = ConnectorVehiclesParser::parse($client->read($vehiclesPath));
            }
        } catch (Throwable) {
            // optional enrichment
        }

        return [
            'vehicles' => $vehicles,
            'basePath' => $base,
        ];
    }

    /**
     * Pull missing store icons when due. Skips files already in local cache.
     * Returns number of newly downloaded files.
     */
    public function syncStoreIconsIfDue(Workspace $workspace, ?int $intervalSec = null): int
    {
        $intervalSec ??= self::ICON_SYNC_INTERVAL_SEC;
        $marker = $this->iconSyncMarkerPath($workspace);
        if (is_file($marker)) {
            $age = time() - (int) filemtime($marker);
            if ($age >= 0 && $age < $intervalSec) {
                return 0;
            }
        }

        // Claim the interval even if host is offline — avoid hammering FTP.
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, (string) time());

        return $this->syncStoreIcons($workspace);
    }

    /**
     * Force a store-icon sync (still skips existing local files).
     */
    public function syncStoreIcons(Workspace $workspace): int
    {
        $access = $this->hostAccess($workspace);
        if ($access === null) {
            return 0;
        }

        $base = $this->connectorBase($access);
        if ($base === null) {
            return 0;
        }

        try {
            $client = new HostFileClient($access);
        } catch (Throwable) {
            return 0;
        }

        $manifestPath = $base.'/state/icons_manifest.xml';
        $icons = [];
        try {
            if (! $client->fileExists($manifestPath)) {
                return 0;
            }
            $icons = ConnectorIconsParser::parse($client->read($manifestPath));
        } catch (Throwable) {
            return 0;
        }

        return $this->cacheIcons($client, $workspace, $base, $icons);
    }

    public function cachedIconPath(Workspace $workspace, string $key): ?string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $key) ?? 'icon';
        $dir = storage_path('app/connector/'.$workspace->public_id.'/icons');
        foreach (['png', 'jpg', 'jpeg', 'webp', 'dds'] as $ext) {
            $path = $dir.'/'.$safe.'.'.$ext;
            if (is_file($path) && filesize($path) > 32) {
                return $path;
            }
        }

        $matches = glob($dir.'/'.$safe.'.*') ?: [];
        foreach ($matches as $match) {
            if (is_file($match) && filesize($match) > 32) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  list<array{key: string, name: string|null, author: string|null, version: string|null, iconFile: string, kind?: string}>  $icons
     */
    private function cacheIcons(HostFileClient $client, Workspace $workspace, string $base, array $icons): int
    {
        if ($icons === []) {
            return 0;
        }

        $dir = storage_path('app/connector/'.$workspace->public_id.'/icons');
        File::ensureDirectoryExists($dir);

        $work = [];
        foreach ($icons as $icon) {
            // Vehicle overview uses store icons; skip legacy mod-pack rows.
            if (($icon['kind'] ?? 'store') === 'mod') {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', $icon['iconFile']), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $safeKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $icon['key']) ?? 'icon';
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION) ?: 'png');
            $local = $dir.'/'.$safeKey.'.'.$ext;
            $pngLocal = $dir.'/'.$safeKey.'.png';
            $hasPng = is_file($pngLocal) && filesize($pngLocal) > 32;
            $hasLocal = is_file($local) && filesize($local) > 32;
            if ($hasPng) {
                continue;
            }
            // Prefer browser-ready PNG; keep DDS for on-demand convert if GD fails.
            if ($hasLocal && $ext !== 'dds') {
                continue;
            }
            $work[] = [
                'remote' => str_starts_with($rel, 'icons/')
                    ? $base.'/'.$rel
                    : $base.'/icons/'.basename($rel),
                'ext' => $ext,
                'local' => $local,
                'pngLocal' => $pngLocal,
                'hasLocal' => $hasLocal,
            ];
        }

        $downloaded = 0;
        foreach (array_slice($work, 0, self::ICON_SYNC_BATCH) as $job) {
            try {
                $bytes = null;
                if ($job['hasLocal']) {
                    $bytes = (string) file_get_contents($job['local']);
                } else {
                    if (! $client->fileExists($job['remote'])) {
                        continue;
                    }
                    $bytes = $client->read($job['remote']);
                    if ($bytes === '') {
                        continue;
                    }
                    File::put($job['local'], $bytes);
                    $downloaded++;
                }
                if ($job['ext'] === 'dds' && $bytes !== null && $bytes !== '') {
                    $png = DdsToPng::convert($bytes);
                    if ($png !== null) {
                        File::put($job['pngLocal'], $png);
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $downloaded;
    }

    private function hostAccess(Workspace $workspace): ?HostAccess
    {
        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if (! $access || $access->status !== 'ok') {
            return null;
        }

        return $access;
    }

    private function connectorBase(HostAccess $access): ?string
    {
        $modSettings = trim((string) ($access->remote_mod_settings_path ?? ''));
        if ($modSettings === '') {
            return null;
        }

        return rtrim(str_replace('\\', '/', $modSettings), '/').'/'.self::MOD_FOLDER;
    }

    private function iconSyncMarkerPath(Workspace $workspace): string
    {
        return storage_path('app/connector/'.$workspace->public_id.'/icons/.last_sync');
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
