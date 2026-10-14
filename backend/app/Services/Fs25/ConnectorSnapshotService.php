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
 * Store icons: read icons_manifest.xml, then RETR every missing or changed file
 * in one pass. A later sync only fetches the manifest and stops when the store
 * rows are unchanged. Never gate downloads on FTP LIST.
 */
final class ConnectorSnapshotService
{
    public const MOD_FOLDER = 'FS25_AgriVisionConnector';

    /** Seconds between automatic icon checks. A manual sync passes 0. */
    public const ICON_SYNC_INTERVAL_SEC = 300;

    /** Seconds to wait before retrying a DDS that did not become a PNG. */
    private const CONVERT_COOLDOWN_SEC = 300;

    /** @var resource|null */
    private static $iconLock = null;

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

        // Throttle after a real check, including "manifest unchanged" (one XML, no DDS).
        if ($result['skipped'] === null
            || in_array($result['skipped'], ['unchanged', 'connect_failed', 'no_icons', 'manifest_failed', 'convert_cooldown'], true)) {
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, (string) time());
            $checked = storage_path('app/connector/icon-sync.checked');
            File::ensureDirectoryExists(dirname($checked));
            File::put($checked, (string) time());
        }

        return $result;
    }

    /**
     * @return array{downloaded: int, converted: int, skipped: string|null, pending: int, errors: int, listed: int, detail: string|null}
     */
    public function syncStoreIcons(Workspace $workspace): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        if (! $this->acquireIconLock()) {
            return $this->syncResult(skipped: 'busy', pending: 1, detail: 'Icon-Sync läuft bereits');
        }

        try {
            return $this->syncStoreIconsLocked($workspace);
        } finally {
            $this->releaseIconLock();
        }
    }

    /**
     * @return array{downloaded: int, converted: int, skipped: string|null, pending: int, errors: int, listed: int, detail: string|null}
     */
    private function syncStoreIconsLocked(Workspace $workspace): array
    {
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

        // Manifest-first: one XML read (same path that already works), then direct RETR.
        // Do NOT require FTP LIST — empty NLST would skip all icon downloads forever.
        try {
            $manifestXml = $client->read($base.'/state/icons_manifest.xml');
        } catch (Throwable $e) {
            Log::warning('AgriVision icons_manifest read failed', [
                'workspace' => $workspace->public_id,
                'error' => $e->getMessage(),
            ]);

            return $this->syncResult(skipped: 'manifest_failed', detail: $e->getMessage());
        }

        $entries = ConnectorIconsParser::parse($manifestXml);
        $jobs = [];
        foreach ($entries as $icon) {
            if (($icon['kind'] ?? 'store') !== 'store') {
                continue;
            }
            $key = trim((string) $icon['key']);
            $iconFile = trim(str_replace('\\', '/', (string) $icon['iconFile']));
            if ($key === '' || $iconFile === '') {
                continue;
            }
            $remote = str_starts_with($iconFile, $base.'/')
                ? $iconFile
                : rtrim($base, '/').'/'.ltrim($iconFile, '/');
            $ext = strtolower(pathinfo($iconFile, PATHINFO_EXTENSION) ?: 'dds');
            if (! in_array($ext, ['dds', 'png', 'jpg', 'jpeg', 'webp'], true)) {
                $ext = 'dds';
                if (! str_contains(strtolower($iconFile), '.dds')) {
                    $remote = preg_replace('/\.[^.]+$/', '', $remote).'.dds';
                }
            }
            $jobs[$key] = [
                'key' => $key,
                'ext' => $ext,
                'remote' => $remote,
            ];
        }

        // Fallback: derive store keys from vehicles_full when manifest has no store rows.
        if ($jobs === []) {
            try {
                $vehicles = ConnectorVehiclesParser::parse($client->read($base.'/state/vehicles_full.xml'));
                foreach ($vehicles as $vehicle) {
                    $key = $vehicle['storeKey'] ?? null;
                    if (! is_string($key) || $key === '') {
                        continue;
                    }
                    $jobs[$key] = [
                        'key' => $key,
                        'ext' => 'dds',
                        'remote' => rtrim($base, '/').'/icons/store/'.$key.'.dds',
                    ];
                }
            } catch (Throwable) {
                // keep empty
            }
        }

        if ($jobs === []) {
            return $this->syncResult(
                skipped: 'no_icons',
                detail: 'icons_manifest.xml ohne store-Einträge'
            );
        }

        return $this->downloadIconJobs($client, $workspace, array_values($jobs));
    }

    public function cachedIconPath(Workspace $workspace, string $key): ?string
    {
        return (new ModIconCacheService)->localPath($workspace, $key)
            ?? (new ModIconCacheService)->localDdsPath($workspace, $key);
    }

    /**
     * @param  list<array{key: string, ext: string, remote: string}>  $jobs
     * @return array{downloaded: int, converted: int, skipped: string|null, pending: int, errors: int, listed: int, detail: string|null}
     */
    private function downloadIconJobs(HostFileClient $client, Workspace $workspace, array $jobs): array
    {
        $cache = new ModIconCacheService;
        $dir = $cache->cacheDir($workspace);
        File::ensureDirectoryExists($dir);

        $cooldown = $this->readConvertCooldown($dir);
        $catalog = $this->readIconCatalog($dir);
        $known = $catalog !== [];
        $now = time();
        $work = [];
        $cooling = 0;
        foreach ($jobs as $job) {
            $safeKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $job['key']) ?? 'icon';
            $remote = $this->normalizeIconRemote($job['remote']);
            if ($remote === null) {
                continue;
            }
            $hasPng = $cache->localPath($workspace, $safeKey) !== null;
            $remoteChanged = $known && ($catalog[$safeKey] ?? null) !== $remote;
            if ($hasPng && ! $remoteChanged) {
                continue;
            }
            if (! $remoteChanged && ($cooldown[$safeKey] ?? 0) > $now) {
                $cooling++;
                continue;
            }
            $work[] = [
                'key' => $safeKey,
                'ext' => $job['ext'],
                'remote' => $remote,
                'pngLocal' => $dir.'/'.$safeKey.'.png',
                'force' => $remoteChanged,
            ];
        }

        $listed = count($jobs);
        if ($work === []) {
            if ($cooling === 0) {
                $this->writeIconCatalog($dir, $jobs);
            }

            return $this->syncResult(
                pending: $cooling,
                listed: $listed,
                skipped: $cooling > 0 ? 'convert_cooldown' : 'unchanged',
                detail: $cooling > 0 ? 'DDS liegt lokal, Konvertierung pausiert' : 'Manifest unverändert'
            );
        }

        $downloaded = 0;
        $converted = 0;
        $errors = 0;
        $firstError = null;

        Log::info('AgriVision icon sync starting RETR', [
            'workspace' => $workspace->public_id,
            'listed' => $listed,
            'missing' => count($work),
            'firstRemote' => $work[0]['remote'] ?? null,
        ]);

        foreach ($work as $job) {
            try {
                $ddsLocal = $dir.'/'.$job['key'].'.dds';
                $bytes = null;
                $fetched = false;
                if (! $job['force'] && $job['ext'] === 'dds' && is_file($ddsLocal) && filesize($ddsLocal) > 128) {
                    $cached = file_get_contents($ddsLocal);
                    if (is_string($cached) && strlen($cached) > 128) {
                        $bytes = $cached;
                    }
                }
                if ($bytes === null) {
                    $bytes = $client->read($job['remote']);
                    $fetched = true;
                }
                if ($bytes === '' || strlen($bytes) <= 32) {
                    $errors++;
                    $firstError ??= 'RETR leer '.$job['remote'];
                    continue;
                }
                if ($fetched) {
                    $downloaded++;
                }
                $isDds = str_starts_with($bytes, 'DDS ') || $job['ext'] === 'dds';
                if ($isDds) {
                    if ($fetched) {
                        File::put($ddsLocal, $bytes);
                    }
                    $png = DdsToPng::convert($bytes);
                    if ($png !== null && str_starts_with($png, "\x89PNG")) {
                        File::put($job['pngLocal'], $png);
                        unset($cooldown[$job['key']]);
                        $converted++;
                    } else {
                        $errors++;
                        $cooldown[$job['key']] = time() + self::CONVERT_COOLDOWN_SEC;
                        $firstError ??= 'DDS→PNG fehlgeschlagen für '.$job['key'];
                    }
                } else {
                    $writeExt = in_array($job['ext'], ['png', 'jpg', 'jpeg', 'webp'], true) ? $job['ext'] : 'png';
                    File::put($dir.'/'.$job['key'].'.'.$writeExt, $bytes);
                    if ($writeExt === 'png') {
                        File::put($job['pngLocal'], $bytes);
                    }
                    $converted++;
                }
            } catch (Throwable $e) {
                $errors++;
                $firstError ??= $e->getMessage().' @ '.$job['remote'];
                Log::warning('AgriVision icon RETR failed', [
                    'workspace' => $workspace->public_id,
                    'remote' => $job['remote'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $stillPending = 0;
        foreach ($jobs as $job) {
            $safeKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $job['key']) ?? 'icon';
            if ($cache->localPath($workspace, $safeKey) === null) {
                $stillPending++;
            }
        }

        $this->writeConvertCooldown($dir, $cooldown);
        if ($stillPending === 0) {
            $this->writeIconCatalog($dir, $jobs);
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

    private function acquireIconLock(): bool
    {
        if (is_resource(self::$iconLock)) {
            return true;
        }
        $path = storage_path('app/connector/icon-sync.lock');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        self::$iconLock = $handle;

        return true;
    }

    private function releaseIconLock(): void
    {
        if (! is_resource(self::$iconLock)) {
            self::$iconLock = null;

            return;
        }
        flock(self::$iconLock, LOCK_UN);
        fclose(self::$iconLock);
        self::$iconLock = null;
    }

    /**
     * @return array<string, int>
     */
    private function readConvertCooldown(string $dir): array
    {
        $path = $dir.'/.convert-cooldown.json';
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $now = time();
        $out = [];
        foreach ($decoded as $key => $until) {
            if (is_string($key) && is_int($until) && $until > $now) {
                $out[$key] = $until;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $cooldown
     */
    private function writeConvertCooldown(string $dir, array $cooldown): void
    {
        $path = $dir.'/.convert-cooldown.json';
        $now = time();
        $keep = [];
        foreach ($cooldown as $key => $until) {
            if (is_string($key) && is_int($until) && $until > $now) {
                $keep[$key] = $until;
            }
        }
        if ($keep === []) {
            if (is_file($path)) {
                File::delete($path);
            }

            return;
        }
        File::put($path, json_encode($keep));
    }

    /**
     * @return array<string, string>
     */
    private function readIconCatalog(string $dir): array
    {
        $path = $dir.'/.icon-catalog.json';
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $remote) {
            if (is_string($key) && is_string($remote) && $key !== '' && $remote !== '') {
                $out[$key] = $remote;
            }
        }

        return $out;
    }

    /**
     * Store key + remote path. Manifest timestamps are ignored, so a rewrite
     * with the same icons does not look like a change.
     *
     * @param  list<array{key: string, ext: string, remote: string}>  $jobs
     */
    private function writeIconCatalog(string $dir, array $jobs): void
    {
        $catalog = [];
        foreach ($jobs as $job) {
            $safeKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $job['key']) ?? 'icon';
            $remote = $this->normalizeIconRemote($job['remote']);
            if ($remote === null) {
                continue;
            }
            $catalog[$safeKey] = $remote;
        }
        ksort($catalog);
        File::put($dir.'/.icon-catalog.json', json_encode($catalog));
    }

    private function normalizeIconRemote(string $remote): ?string
    {
        $path = str_replace('\\', '/', $remote);
        $baseName = basename($path);
        $remoteDir = rtrim(dirname($path), '/');
        if ($baseName === '' || $baseName === '.' || $baseName === '..') {
            return null;
        }

        return ($remoteDir !== '' && $remoteDir !== '.' ? $remoteDir : 'icons/store').'/'.$baseName;
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
