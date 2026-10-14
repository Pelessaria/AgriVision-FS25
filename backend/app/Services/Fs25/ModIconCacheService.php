<?php

namespace App\Services\Fs25;

use App\Models\Workspace;
use Illuminate\Support\Facades\File;

/**
 * Serve cached mod store icons for the equipment UI.
 *
 * Icons come from the connector (modSettings/.../icons) after it runs.
 * Never download full mods/*.zip over FTP — that fills disk and stalls the UI.
 */
final class ModIconCacheService
{
    /**
     * @param  list<string>  $modKeys
     * @return array<string, true>
     */
    public function ensure(Workspace $workspace, array $modKeys): array
    {
        $ready = [];
        foreach ($modKeys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if ($this->localPath($workspace, $key) !== null) {
                $ready[$key] = true;
            }
        }

        return $ready;
    }

    public function localPath(Workspace $workspace, string $modKey): ?string
    {
        $safe = $this->safeKey($modKey);
        $dir = $this->cacheDir($workspace);
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $path = $dir.'/'.$safe.'.'.$ext;
            if (is_file($path) && filesize($path) > 32) {
                return $path;
            }
        }

        return null;
    }

    public function publicUrl(Workspace $workspace, string $modKey): string
    {
        return '/api/workspaces/'.rawurlencode($workspace->public_id)
            .'/connector/icons/'.rawurlencode($modKey);
    }

    public function cacheDir(Workspace $workspace): string
    {
        return storage_path('app/connector/'.$workspace->public_id.'/icons');
    }

    public function putPng(Workspace $workspace, string $modKey, string $pngBytes): void
    {
        $dir = $this->cacheDir($workspace);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$this->safeKey($modKey).'.png', $pngBytes);
    }

    private function safeKey(string $modKey): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $modKey) ?? 'icon';
    }
}
