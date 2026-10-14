<?php

namespace App\Services\Fs25;

use App\Models\Workspace;
use Illuminate\Support\Facades\File;

/**
 * Local cache of vehicle store icons (and later mod-pack icons).
 *
 * Files are filled by ConnectorSnapshotService::syncStoreIconsIfDue from
 * modSettings/.../icons/store. Never download full mods/*.zip over FTP.
 */
final class ModIconCacheService
{
    /**
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    public function ensure(Workspace $workspace, array $keys): array
    {
        $ready = [];
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if ($this->localPath($workspace, $key) !== null) {
                $ready[$key] = true;
            }
        }

        return $ready;
    }

    public function localPath(Workspace $workspace, string $key): ?string
    {
        $safe = $this->safeKey($key);
        $dir = $this->cacheDir($workspace);
        // Prefer raster formats the browser can show; DDS is served via on-demand PNG convert.
        foreach (['png', 'jpg', 'jpeg', 'webp', 'dds'] as $ext) {
            $path = $dir.'/'.$safe.'.'.$ext;
            if (is_file($path) && filesize($path) > 32) {
                return $path;
            }
        }

        return null;
    }

    public function publicUrl(Workspace $workspace, string $key): string
    {
        return '/api/workspaces/'.rawurlencode($workspace->public_id)
            .'/connector/icons/'.rawurlencode($key);
    }

    public function cacheDir(Workspace $workspace): string
    {
        return storage_path('app/connector/'.$workspace->public_id.'/icons');
    }

    public function putPng(Workspace $workspace, string $key, string $pngBytes): void
    {
        $dir = $this->cacheDir($workspace);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$this->safeKey($key).'.png', $pngBytes);
    }

    private function safeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $key) ?? 'icon';
    }
}
