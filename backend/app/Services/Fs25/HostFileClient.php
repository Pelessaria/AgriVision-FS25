<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use Throwable;

/**
 * Read-only remote filesystem for dedicated-server savegames / modSettings.
 * SFTP → Flysystem/phpseclib; FTP/FTPS → cURL (explicit AUTH TLS on :21).
 */
final class HostFileClient
{
    private ?Filesystem $sftp = null;

    private ?CurlFtpClient $ftp = null;

    private string $protocol;

    private string $host;

    private int $port;

    public function __construct(HostAccess $access)
    {
        $password = $access->password();
        if ($password === null || $password === '') {
            throw new RuntimeException('Host-Passwort ist nicht konfiguriert.');
        }

        $this->protocol = strtolower($access->protocol);
        $this->host = trim($access->host);
        $this->port = $access->port > 0
            ? $access->port
            : ($this->protocol === 'sftp' ? 22 : 21);

        $tcp = HostConnectionError::tcpProbe($this->host, $this->port, 8.0);
        if (! $tcp['ok']) {
            throw new RuntimeException(
                "Kein TCP-Connect ({$this->protocol} {$this->host}:{$this->port}). {$tcp['detail']} "
                .'AgriVision-VM → Ziel prüfen (DNS, Routing, ausgehende Firewall).'
            );
        }

        try {
            if ($this->protocol === 'sftp') {
                $root = $this->normalizePath((string) ($access->remote_root ?? ''));
                $provider = new SftpConnectionProvider(
                    host: $this->host,
                    username: $access->username,
                    password: $password,
                    port: $this->port,
                    timeout: 12,
                );
                $this->sftp = new Filesystem(new SftpAdapter($provider, $root === '' ? '/' : $root));
            } elseif ($this->protocol === 'ftps' || $this->protocol === 'ftp') {
                $this->ftp = new CurlFtpClient($access);
            } else {
                throw new RuntimeException("Unbekanntes Protokoll: {$this->protocol}");
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                HostConnectionError::format($e, "Verbindung {$this->protocol} {$this->host}:{$this->port}"),
                0,
                $e
            );
        }
    }

    public function endpointLabel(): string
    {
        if ($this->ftp) {
            return $this->ftp->modeLabel()." {$this->host}:{$this->port}";
        }

        return strtoupper($this->protocol)." {$this->host}:{$this->port}";
    }

    /**
     * @return list<string>
     */
    public function listDirectories(string $path = ''): array
    {
        try {
            if ($this->ftp) {
                return $this->ftp->listDirectories($path);
            }

            $path = $this->normalizePath($path);
            $listing = $this->sftp()->listContents($path === '' ? '' : $path, false);
            $dirs = [];
            /** @var StorageAttributes $item */
            foreach ($listing as $item) {
                if ($item->isDir()) {
                    $dirs[] = $item->path();
                }
            }
            sort($dirs);

            return $dirs;
        } catch (Throwable $e) {
            throw new RuntimeException(
                HostConnectionError::format($e, 'Verzeichnislisting '.$this->endpointLabel()),
                0,
                $e
            );
        }
    }

    public function fileExists(string $path): bool
    {
        try {
            if ($this->ftp) {
                return $this->ftp->fileExists($path);
            }

            return $this->sftp()->fileExists($this->normalizePath($path));
        } catch (FilesystemException|RuntimeException) {
            return false;
        }
    }

    public function read(string $path): string
    {
        try {
            if ($this->ftp) {
                return $this->ftp->read($path);
            }

            return $this->sftp()->read($this->normalizePath($path));
        } catch (Throwable $e) {
            throw new RuntimeException(
                HostConnectionError::format($e, 'Lesen '.$path.' @ '.$this->endpointLabel()),
                0,
                $e
            );
        }
    }

    /**
     * @return array{
     *   savegame: string|null,
     *   savegames: list<array{slotKey: string, path: string}>,
     *   modSettings: string|null,
     *   candidates: list<string>
     * }
     */
    public function probePaths(?string $hintSavegame = null, ?string $hintModSettings = null): array
    {
        $modSettings = null;
        $candidates = [];
        $listErrors = [];
        /** @var array<string, string> $savegames path by slot key */
        $savegames = [];

        if ($hintSavegame && $this->looksLikeSavegameDir($hintSavegame)) {
            $slot = $this->slotKeyFromPath($hintSavegame);
            $savegames[$slot] = $this->normalizePath($hintSavegame);
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
                $this->collectSavegameCandidate($dir, $savegames);
                if ($base === 'modsettings') {
                    $modSettings ??= $this->normalizePath($dir);
                }
            }
        } catch (Throwable $e) {
            $listErrors[] = HostConnectionError::format($e);
        }

        foreach ($roots as $root) {
            if ($modSettings === null) {
                $found = $this->findNamedDir($root, ['modsettings', 'modSettings']);
                if ($found) {
                    $modSettings = $found;
                }
            }

            try {
                foreach ($this->listDirectories($root) as $child) {
                    $candidates[] = $child;
                    $this->collectSavegameCandidate($child, $savegames);
                    $base = strtolower(basename(str_replace('\\', '/', $child)));
                    if ($modSettings === null && $base === 'modsettings') {
                        $modSettings = $this->normalizePath($child);
                    }

                    // One more level (My Games / FarmingSimulator25)
                    try {
                        foreach ($this->listDirectories($child) as $grand) {
                            $candidates[] = $grand;
                            $this->collectSavegameCandidate($grand, $savegames);
                            $gbase = strtolower(basename(str_replace('\\', '/', $grand)));
                            if ($modSettings === null && $gbase === 'modsettings') {
                                $modSettings = $this->normalizePath($grand);
                            }
                        }
                    } catch (Throwable) {
                        // ignore deeper listing failures
                    }
                }
            } catch (Throwable $e) {
                $listErrors[] = HostConnectionError::format($e);
            }
        }

        if ($savegames === [] && $modSettings === null && $listErrors !== []) {
            throw new RuntimeException(
                'Pfad-Suche fehlgeschlagen: '.$listErrors[0]
                .' — optional Savegame-Pfad manuell setzen.'
            );
        }

        ksort($savegames, SORT_NATURAL | SORT_FLAG_CASE);
        $list = [];
        foreach ($savegames as $slot => $path) {
            $list[] = ['slotKey' => $slot, 'path' => $path];
        }

        $primary = $list[0]['path'] ?? null;

        return [
            'savegame' => $primary,
            'savegames' => $list,
            'modSettings' => $modSettings,
            'candidates' => array_values(array_unique($candidates)),
        ];
    }

    /**
     * @param  array<string, string>  $savegames
     */
    private function collectSavegameCandidate(string $dir, array &$savegames): void
    {
        $base = basename(str_replace('\\', '/', $dir));
        if (! preg_match('/^savegame\d*$/i', $base)) {
            return;
        }
        if (! $this->looksLikeSavegameDir($dir)) {
            return;
        }
        $slot = strtolower($base);
        $savegames[$slot] = $this->normalizePath($dir);
    }

    private function slotKeyFromPath(string $path): string
    {
        $base = strtolower(basename(str_replace('\\', '/', $this->normalizePath($path))));

        return preg_match('/^savegame\d*$/', $base) ? $base : $base;
    }

    private function sftp(): Filesystem
    {
        if (! $this->sftp) {
            throw new RuntimeException('SFTP-Client nicht initialisiert.');
        }

        return $this->sftp;
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
