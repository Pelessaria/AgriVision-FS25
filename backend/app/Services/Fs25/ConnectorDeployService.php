<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use App\Models\Workspace;
use RuntimeException;
use Throwable;

/**
 * Serve the packaged connector zip from the repo and push it to the game host mods folder.
 */
final class ConnectorDeployService
{
    public const MOD_ZIP = 'FS25_AgriVisionConnector.zip';

    public const MOD_NAME = 'FS25_AgriVisionConnector';

    public function packagePath(): string
    {
        // Prefer backend/resources (survives GitHub zipball overlay of Laravel root).
        $candidates = [
            base_path('resources/connector/'.self::MOD_ZIP),
            base_path('../connector/dist/'.self::MOD_ZIP),
            dirname(base_path()).'/connector/dist/'.self::MOD_ZIP,
        ];
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        throw new RuntimeException(
            'Connector-Paket fehlt (resources/connector/'.self::MOD_ZIP.'). Bitte connector/pack.ps1 ausführen.'
        );
    }

    public function packageVersion(): ?string
    {
        $modDesc = $this->modDescPath();
        if ($modDesc === null) {
            return null;
        }
        $raw = @file_get_contents($modDesc);
        if (! is_string($raw) || ! preg_match('/<version>\s*([^<]+)\s*<\/version>/i', $raw, $m)) {
            return null;
        }

        return trim($m[1]) ?: null;
    }

    /**
     * @return array{ok: bool, error: string|null, remotePath: string|null, bytes: int|null, version: string|null}
     */
    public function deploy(Workspace $workspace, ?string $modsPathHint = null): array
    {
        $access = HostAccess::query()->where('workspace_id', $workspace->id)->first();
        if (! $access) {
            return $this->fail('Dateizugang nicht konfiguriert.');
        }

        try {
            $local = $this->packagePath();
            $bytes = filesize($local);
            if ($bytes === false || $bytes < 1) {
                return $this->fail('Connector-Paket ist leer oder unlesbar.');
            }
            $contents = file_get_contents($local);
            if ($contents === false) {
                return $this->fail('Connector-Paket konnte nicht gelesen werden.');
            }

            $client = new HostFileClient($access);
            $modsDir = $this->resolveModsDirectory($client, $access, $modsPathHint);
            $client->ensureDirectory($modsDir);
            $remote = rtrim($modsDir, '/').'/'.self::MOD_ZIP;
            $client->write($remote, $contents);

            return [
                'ok' => true,
                'error' => null,
                'remotePath' => $remote,
                'bytes' => strlen($contents),
                'version' => $this->packageVersion(),
            ];
        } catch (Throwable $e) {
            return $this->fail(HostConnectionError::format($e, 'Connector-Deploy'));
        }
    }

    private function resolveModsDirectory(HostFileClient $client, HostAccess $access, ?string $hint): string
    {
        $hint = $hint !== null ? trim(str_replace('\\', '/', $hint)) : '';
        if ($hint !== '') {
            return trim($hint, '/');
        }

        $modSettings = trim(str_replace('\\', '/', (string) ($access->remote_mod_settings_path ?? '')), '/');
        if ($modSettings !== '') {
            $parent = trim(dirname($modSettings), '/.');
            $siblings = ['mods', 'Mods', 'MODS'];
            foreach ($siblings as $name) {
                $candidate = $parent === '' || $parent === '.' ? $name : $parent.'/'.$name;
                if ($client->fileExists($candidate) || $this->dirLooksPresent($client, $candidate)) {
                    return $candidate;
                }
            }
            // Create beside modSettings when missing.
            return $parent === '' || $parent === '.' ? 'mods' : $parent.'/mods';
        }

        foreach (['mods', 'Mods', 'My Games/FarmingSimulator25/mods', 'Documents/My Games/FarmingSimulator25/mods'] as $candidate) {
            if ($this->dirLooksPresent($client, $candidate)) {
                return $candidate;
            }
        }

        return 'mods';
    }

    private function dirLooksPresent(HostFileClient $client, string $path): bool
    {
        try {
            $client->listDirectories($path);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function modDescPath(): ?string
    {
        $candidates = [
            base_path('../connector/FS25_AgriVisionConnector/modDesc.xml'),
            base_path('connector/FS25_AgriVisionConnector/modDesc.xml'),
            dirname(base_path()).'/connector/FS25_AgriVisionConnector/modDesc.xml',
        ];
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, error: string|null, remotePath: string|null, bytes: int|null, version: string|null}
     */
    private function fail(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'remotePath' => null,
            'bytes' => null,
            'version' => $this->packageVersion(),
        ];
    }
}
