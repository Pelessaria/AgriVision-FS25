<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use Throwable;

/**
 * Read-only remote filesystem for dedicated-server savegames / modSettings.
 */
final class HostFileClient
{
    private Filesystem $fs;

    public function __construct(HostAccess $access)
    {
        $password = $access->password();
        if ($password === null || $password === '') {
            throw new RuntimeException('Host password is not configured.');
        }

        $root = $this->normalizePath((string) ($access->remote_root ?? ''));
        $protocol = strtolower($access->protocol);

        try {
            if ($protocol === 'sftp') {
                $provider = new SftpConnectionProvider(
                    host: $access->host,
                    username: $access->username,
                    password: $password,
                    port: $access->port ?: 22,
                    timeout: 12,
                );
                $adapter = new SftpAdapter($provider, $root === '' ? '/' : $root);
            } elseif ($protocol === 'ftps' || $protocol === 'ftp') {
                $adapter = new FtpAdapter(FtpConnectionOptions::fromArray([
                    'host' => $access->host,
                    'root' => $root === '' ? '/' : $root,
                    'username' => $access->username,
                    'password' => $password,
                    'port' => $access->port ?: 21,
                    'ssl' => $protocol === 'ftps',
                    'timeout' => 12,
                    'utf8' => true,
                    'passive' => true,
                ]));
            } else {
                throw new RuntimeException('Unsupported host protocol.');
            }

            $this->fs = new Filesystem($adapter);
        } catch (Throwable $e) {
            throw new RuntimeException('Host connection failed.', 0, $e);
        }
    }

    /**
     * @return list<string>
     */
    public function listDirectories(string $path = ''): array
    {
        $path = $this->normalizePath($path);
        try {
            $listing = $this->fs->listContents($path === '' ? '' : $path, false);
            $dirs = [];
            /** @var StorageAttributes $item */
            foreach ($listing as $item) {
                if ($item->isDir()) {
                    $dirs[] = $item->path();
                }
            }
            sort($dirs);

            return $dirs;
        } catch (FilesystemException $e) {
            throw new RuntimeException('Host listing failed.', 0, $e);
        }
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->fs->fileExists($this->normalizePath($path));
        } catch (FilesystemException) {
            return false;
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->fs->read($this->normalizePath($path));
        } catch (FilesystemException $e) {
            throw new RuntimeException('Host file read failed.', 0, $e);
        }
    }

    /**
     * Walk a few well-known FS folder names under root / one level.
     *
     * @return array{savegame: string|null, modSettings: string|null, candidates: list<string>}
     */
    public function probePaths(?string $hintSavegame = null, ?string $hintModSettings = null): array
    {
        $savegame = null;
        $modSettings = null;
        $candidates = [];

        if ($hintSavegame && $this->looksLikeSavegameDir($hintSavegame)) {
            $savegame = $this->normalizePath($hintSavegame);
        }
        if ($hintModSettings && $this->directoryLooksPresent($hintModSettings)) {
            $modSettings = $this->normalizePath($hintModSettings);
        }

        $roots = [''];
        try {
            $top = $this->listDirectories('');
            $candidates = array_merge($candidates, $top);
            foreach ($top as $dir) {
                $base = strtolower(basename(str_replace('\\', '/', $dir)));
                if (in_array($base, ['documents', 'my games', 'farming simulator 25', 'farmingsimulator25', 'fs25'], true)) {
                    $roots[] = $dir;
                }
            }
        } catch (Throwable) {
            // listing may fail on empty root — still try hints
        }

        foreach ($roots as $root) {
            if ($savegame === null) {
                $savegame = $this->findNamedDir($root, ['savegame', 'savegame1', 'savegame2', 'savegame3']);
            }
            if ($modSettings === null) {
                $modSettings = $this->findNamedDir($root, ['modsettings', 'modSettings']);
            }
            if ($savegame !== null && $modSettings !== null) {
                break;
            }

            // One level deeper (e.g. Documents/My Games/FarmingSimulator25)
            try {
                foreach ($this->listDirectories($root) as $child) {
                    $candidates[] = $child;
                    if ($savegame === null) {
                        $savegame = $this->findNamedDir($child, ['savegame', 'savegame1', 'savegame2', 'savegame3']);
                    }
                    if ($modSettings === null) {
                        $modSettings = $this->findNamedDir($child, ['modsettings', 'modSettings']);
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return [
            'savegame' => $savegame,
            'modSettings' => $modSettings,
            'candidates' => array_values(array_unique($candidates)),
        ];
    }

    private function findNamedDir(string $parent, array $names): ?string
    {
        $want = array_map('strtolower', $names);
        try {
            foreach ($this->listDirectories($parent) as $dir) {
                $base = strtolower(basename(str_replace('\\', '/', $dir)));
                if (in_array($base, $want, true) || preg_match('/^savegame\d*$/i', $base)) {
                    if (str_starts_with($base, 'savegame') && ! $this->looksLikeSavegameDir($dir)) {
                        continue;
                    }

                    return $this->normalizePath($dir);
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function looksLikeSavegameDir(string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($this->fileExists($path.'/farms.xml') || $this->fileExists($path.'/careerSavegame.xml')) {
            return true;
        }
        // Directory exists at least
        return $this->directoryLooksPresent($path);
    }

    private function directoryLooksPresent(string $path): bool
    {
        try {
            $this->listDirectories($path);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');

        return $path;
    }
}
