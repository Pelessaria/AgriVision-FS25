<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Throwable;
use ZipArchive;

/**
 * Cache mod store icons (PNG) from host mods/*.zip for the equipment UI.
 */
final class ModIconCacheService
{
    /**
     * Ensure icons for the given mod keys exist locally; return keys that are ready.
     *
     * @param  list<string>  $modKeys
     * @return array<string, true>
     */
    public function ensure(Workspace $workspace, array $modKeys): array
    {
        $ready = [];
        $keys = array_values(array_unique(array_filter($modKeys, static fn ($k) => is_string($k) && $k !== '')));
        if ($keys === []) {
            return $ready;
        }

        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if (! $access || ! $access->hasPassword()) {
            foreach ($keys as $key) {
                if ($this->localPath($workspace, $key) !== null) {
                    $ready[$key] = true;
                }
            }

            return $ready;
        }

        try {
            $client = new HostFileClient($access);
            $modsDir = ConnectorDeployService::resolveModsDirectory($client, $access);
        } catch (Throwable) {
            foreach ($keys as $key) {
                if ($this->localPath($workspace, $key) !== null) {
                    $ready[$key] = true;
                }
            }

            return $ready;
        }

        $dir = $this->cacheDir($workspace);
        File::ensureDirectoryExists($dir);

        foreach (array_slice($keys, 0, 60) as $key) {
            $safe = $this->safeKey($key);
            $existing = $this->localPath($workspace, $key);
            if ($existing !== null) {
                $ready[$key] = true;

                continue;
            }

            $remoteZip = rtrim($modsDir, '/').'/'.$key.'.zip';
            try {
                if (! $client->fileExists($remoteZip)) {
                    continue;
                }
                $bytes = $client->read($remoteZip);
            } catch (Throwable) {
                continue;
            }

            // Skip huge archives (icon lives near the zip root; still need the archive).
            if (strlen($bytes) > 80 * 1024 * 1024) {
                continue;
            }

            $extracted = $this->extractPngIcon($bytes, $safe, $dir);
            if ($extracted) {
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

    private function cacheDir(Workspace $workspace): string
    {
        return storage_path('app/connector/'.$workspace->public_id.'/icons');
    }

    private function safeKey(string $modKey): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $modKey) ?? 'icon';
    }

    private function extractPngIcon(string $zipBytes, string $safeKey, string $dir): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'agm');
        if ($tmp === false) {
            return false;
        }

        try {
            File::put($tmp, $zipBytes);
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                return false;
            }

            $modDesc = $zip->getFromName('modDesc.xml') ?: $zip->getFromName('moddesc.xml');
            $iconName = null;
            if (is_string($modDesc) && preg_match('/<iconFilename>\s*([^<]+)\s*<\/iconFilename>/i', $modDesc, $m)) {
                $iconName = trim(str_replace('\\', '/', $m[1]));
            }
            $candidates = array_values(array_filter([
                $iconName,
                'icon.png',
                'icon.PNG',
                'icon.jpg',
            ]));

            $picked = null;
            $ext = 'png';
            foreach ($candidates as $candidate) {
                if ($candidate === null || $candidate === '') {
                    continue;
                }
                // Browsers cannot render DDS; skip until we have a converter.
                if (preg_match('/\.dds$/i', $candidate)) {
                    continue;
                }
                $data = $zip->getFromName($candidate);
                if ($data === false) {
                    // Case-insensitive search
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                        if (strcasecmp(basename($name), basename($candidate)) === 0) {
                            $data = $zip->getFromIndex($i);
                            break;
                        }
                    }
                }
                if (is_string($data) && strlen($data) > 32) {
                    $picked = $data;
                    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION) ?: 'png');
                    if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                        $ext = 'png';
                    }
                    break;
                }
            }
            $zip->close();

            if ($picked === null) {
                return false;
            }

            File::put($dir.'/'.$safeKey.'.'.$ext, $picked);

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            @unlink($tmp);
        }
    }
}
