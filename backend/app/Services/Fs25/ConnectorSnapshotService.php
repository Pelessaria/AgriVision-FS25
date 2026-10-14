<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull AgriVision connector files from host modSettings.
 *
 * Vehicles: cheap XML pull for inventory enrichment.
 * Store icons: dedicated incremental FTP sync → local PNG (GD optional).
 */
final class ConnectorSnapshotService
{
    public const MOD_FOLDER = 'FS25_AgriVisionConnector';

    /** Minimum seconds between icon FTP syncs per workspace. */
    public const ICON_SYNC_INTERVAL_SEC = 3;

    /** Max missing icons to fetch/convert per sync pass. */
    public const ICON_SYNC_BATCH = 15;

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

        return [
            'vehicles' => $result['vehicles'],
            'icons' => [],
            'basePath' => $result['basePath'],
        ];
    }

    /**
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
        try {
            $vehicles = ConnectorVehiclesParser::parse($client->read($base.'/state/vehicles_full.xml'));
        } catch (Throwable) {
            // optional enrichment
        }

        return [
            'vehicles' => $vehicles,
            'basePath' => $base,
        ];
    }

    /**
     * @return array{downloaded: int, converted: int, skipped: string|null, pending: int, errors: int, listed: int, detail: string|null}
     */
    public function syncStoreIconsIfDue(Workspace $workspace, ?int $intervalSec = null): array
    {
        $intervalSec ??= self::ICON_SYNC_INTERVAL_SEC;
        $marker = $this->iconSyncMarkerPath($workspace);
        if (is_file($marker)) {
            $age = time() - (int) filemtime($marker);
            if ($age >= 0 && $age < $intervalSec) {
                return [
                    'downloaded' => 0,
                    'converted' => 0,
                    'skipped' => 'throttled',
                    'pending' => 0,
                    'errors' => 0,
                    'listed' => 0,
                    'detail' => null,
                ];
            }
        }

        $result = $this->syncStoreIcons($workspace);

        if ($result['skipped'] === null || in_array($result['skipped'], ['connect_failed', 'no_icons', 'empty'], true)) {
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, (string) time());
        }

        return $result;
    }

    /**
     * @return array{downloaded: int, converted: int, skipped: string|null, pending: int, errors: int, listed: int, detail: string|null}
     */
    public function syncStoreIcons(Workspace $workspace): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $access = $this->hostAccess($workspace);
        if ($access === null) {
            return $this->syncResult(skipped: 'no_host_access', detail: 'HostAccess fehlt oder ohne Passwort');
        }

        $base = $this->connectorBase($access);
        if ($base === null) {
            return $this->syncResult(skipped: 'no_mod_settings', detail: 'remote_mod_settings_path leer');
        }

        try {
            $client = new HostFileClient($access);
        } catch (Throwable $e) {
            Log::warning('AgriVision icon sync connect failed', [
                'workspace' => $workspace->public_id,
                'error' => $e->getMessage(),
            ]);

            return $this->syncResult(skipped: 'connect_failed', detail: $e->getMessage());
        }

        $storeDir = $base.'/icons/store';
        $names = [];
        try {
            $names = $client->listNames($storeDir);
        } catch (Throwable $e) {
            // Fall back to manifest keys if LIST is blocked.
            Log::info('AgriVision icon LIST failed, trying manifest', [
                'workspace' => $workspace->public_id,
                'error' => $e->getMessage(),
            ]);
            $names = $this->namesFromManifest($client, $base);
            if ($names === []) {
                return $this->syncResult(
                    skipped: 'no_icons',
                    detail: 'LIST icons/store fehlgeschlagen: '.$e->getMessage()
                );
            }
        }

        $iconNames = [];
        foreach ($names as $name) {
            $lower = strtolower($name);
            if (str_ends_with($lower, '.dds') || str_ends_with($lower, '.png')) {
                $iconNames[] = $name;
            }
        }

        if ($iconNames === []) {
            return $this->syncResult(skipped: 'no_icons', listed: count($names), detail: 'icons/store ohne .dds/.png');
        }

        return $this->cacheIconFiles($client, $workspace, $storeDir, $iconNames);
    }

    public function cachedIconPath(Workspace $workspace, string $key): ?string
    {
        return (new ModIconCacheService)->localPath($workspace, $key)
            ?? (new ModIconCacheService)->localDdsPath($workspace, $key);
    }

    /**
     * @return list<string>
     */
    private function namesFromManifest(HostFileClient $client, string $base): array
    {
        try {
            $icons = ConnectorIconsParser::parse($client->read($base.'/state/icons_manifest.xml'));
        } catch (Throwable) {
            return [];
        }
        $names = [];
        foreach ($icons as $icon) {
            if (($icon['kind'] ?? 'store') !== 'store') {
                continue;
            }
            $baseName = basename(str_replace('\\', '/', $icon['iconFile']));
            if ($baseName !== '') {
                $names[] = $baseName;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $iconNames  basenames in icons/store
     * @return array{downloaded: int, converted: int, skipped: string|null, pending: int, errors: int, listed: int, detail: string|null}
     */
    private function cacheIconFiles(HostFileClient $client, Workspace $workspace, string $storeDir, array $iconNames): array
    {
        $cache = new ModIconCacheService;
        $dir = $cache->cacheDir($workspace);
        File::ensureDirectoryExists($dir);

        $work = [];
        foreach ($iconNames as $name) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'dds');
            $key = pathinfo($name, PATHINFO_FILENAME);
            if ($key === '') {
                continue;
            }
            $safeKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $key) ?? 'icon';
            if ($cache->localPath($workspace, $safeKey) !== null) {
                continue; // browser-ready PNG/JPEG already present
            }
            $work[] = [
                'key' => $safeKey,
                'ext' => $ext,
                'remote' => rtrim($storeDir, '/').'/'.$name,
                'local' => $dir.'/'.$safeKey.'.'.$ext,
                'pngLocal' => $dir.'/'.$safeKey.'.png',
                'hasLocal' => is_file($dir.'/'.$safeKey.'.'.$ext) && filesize($dir.'/'.$safeKey.'.'.$ext) > 32,
            ];
        }

        $listed = count($iconNames);
        $pending = count($work);
        if ($pending === 0) {
            return $this->syncResult(listed: $listed, detail: 'alle Icons bereits als PNG vorhanden');
        }

        $downloaded = 0;
        $converted = 0;
        $errors = 0;
        $firstError = null;

        foreach (array_slice($work, 0, self::ICON_SYNC_BATCH) as $job) {
            try {
                $bytes = null;
                if ($job['hasLocal']) {
                    $bytes = (string) file_get_contents($job['local']);
                } else {
                    $bytes = $client->read($job['remote']);
                    if ($bytes === '' || strlen($bytes) <= 32) {
                        $errors++;
                        $firstError ??= 'leere Datei '.$job['remote'];
                        continue;
                    }
                    File::put($job['local'], $bytes);
                    $downloaded++;
                }

                if ($job['ext'] === 'dds' && is_string($bytes) && $bytes !== '') {
                    $png = DdsToPng::convert($bytes);
                    if ($png !== null) {
                        File::put($job['pngLocal'], $png);
                        $converted++;
                    } else {
                        $errors++;
                        $firstError ??= 'DDS→PNG fehlgeschlagen für '.$job['key'];
                    }
                } elseif (in_array($job['ext'], ['png', 'jpg', 'jpeg', 'webp'], true) && is_string($bytes) && $bytes !== '') {
                    if ($job['ext'] === 'png') {
                        File::put($job['pngLocal'], $bytes);
                    }
                    $converted++;
                }
            } catch (Throwable $e) {
                $errors++;
                $firstError ??= $e->getMessage().' @ '.$job['remote'];
                Log::warning('AgriVision icon fetch failed', [
                    'workspace' => $workspace->public_id,
                    'remote' => $job['remote'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $stillPending = 0;
        foreach ($iconNames as $name) {
            $key = pathinfo($name, PATHINFO_FILENAME);
            if ($key === '') {
                continue;
            }
            $safeKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $key) ?? 'icon';
            if ($cache->localPath($workspace, $safeKey) === null) {
                $stillPending++;
            }
        }

        Log::info('AgriVision icon sync pass', [
            'workspace' => $workspace->public_id,
            'listed' => $listed,
            'downloaded' => $downloaded,
            'converted' => $converted,
            'errors' => $errors,
            'pending' => $stillPending,
            'firstError' => $firstError,
        ]);

        return [
            'downloaded' => $downloaded,
            'converted' => $converted,
            'skipped' => null,
            'pending' => $stillPending,
            'errors' => $errors,
            'listed' => $listed,
            'detail' => $firstError,
        ];
    }

    /**
     * @return array{downloaded: int, converted: int, skipped: string|null, pending: int, errors: int, listed: int, detail: string|null}
     */
    private function syncResult(
        int $downloaded = 0,
        int $converted = 0,
        ?string $skipped = null,
        int $pending = 0,
        int $errors = 0,
        int $listed = 0,
        ?string $detail = null,
    ): array {
        return [
            'downloaded' => $downloaded,
            'converted' => $converted,
            'skipped' => $skipped,
            'pending' => $pending,
            'errors' => $errors,
            'listed' => $listed,
            'detail' => $detail,
        ];
    }

    private function hostAccess(Workspace $workspace): ?HostAccess
    {
        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if ($access === null) {
            return null;
        }
        $password = $access->password();
        if ($password === null || $password === '') {
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
