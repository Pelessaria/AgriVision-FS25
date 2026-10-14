<?php

namespace App\Services\Fs25;

use App\Models\HostAccess;
use RuntimeException;

/**
 * FTP / explicit FTPS / implicit FTPS via cURL (game hosts usually need AUTH TLS on :21).
 */
final class CurlFtpClient
{
    private string $host;

    private int $port;

    private string $username;

    private string $password;

    private string $root;

    /** @var 'ftp'|'ftps_explicit'|'ftps_implicit' */
    private string $mode;

    public function __construct(HostAccess $access)
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('PHP-cURL fehlt — FTP/FTPS nicht nutzbar.');
        }

        $password = $access->password();
        if ($password === null || $password === '') {
            throw new RuntimeException('Host-Passwort ist nicht konfiguriert.');
        }

        $protocol = strtolower($access->protocol);
        $this->host = trim($access->host);
        $this->port = $access->port > 0 ? $access->port : ($protocol === 'ftps' ? 21 : 21);
        $this->username = $access->username;
        $this->password = $password;
        $this->root = $this->normalizePath((string) ($access->remote_root ?? ''));

        if ($protocol === 'ftp') {
            $this->mode = 'ftp';
        } elseif ($protocol === 'ftps') {
            // Port 990 → implicit; otherwise explicit AUTH TLS (typical FileZilla / hoster FTPS).
            $this->mode = $this->port === 990 ? 'ftps_implicit' : 'ftps_explicit';
        } else {
            throw new RuntimeException('CurlFtpClient unterstützt nur ftp/ftps.');
        }
    }

    /**
     * @return list<string>
     */
    public function listDirectories(string $path = ''): array
    {
        $listing = $this->listNames($path);
        $dirs = [];
        $base = $this->normalizePath($path);
        foreach ($listing as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // DIRLISTONLY has no type — treat every name as candidate path; callers probe.
            $dirs[] = $base === '' ? $name : $base.'/'.$name;
        }
        sort($dirs);

        return $dirs;
    }

    /**
     * @return list<string>
     */
    public function listNames(string $path = ''): array
    {
        $url = $this->urlForPath($path, true);
        $body = $this->exec($url, [
            CURLOPT_DIRLISTONLY => true,
        ], 'LIST');

        $names = preg_split('/\r\n|\n|\r/', trim($body)) ?: [];
        $out = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '' || str_contains($name, '/')) {
                // Some servers return "dir/" or full paths — take basename.
                $name = basename(str_replace('\\', '/', $name));
            }
            if ($name !== '' && $name !== '.' && $name !== '..') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->read($path);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function read(string $path): string
    {
        $url = $this->urlForPath($path, false);

        return $this->exec($url, [], 'READ '.$path);
    }

    public function write(string $path, string $contents): void
    {
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new RuntimeException('TEMP-Datei für Upload nicht erstellbar.');
        }
        fwrite($tmp, $contents);
        rewind($tmp);
        $meta = stream_get_meta_data($tmp);
        $local = $meta['uri'] ?? null;
        if (! is_string($local) || $local === '') {
            fclose($tmp);
            throw new RuntimeException('TEMP-Pfad für Upload fehlt.');
        }

        try {
            $url = $this->urlForPath($path, false);
            $this->exec($url, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $tmp,
                CURLOPT_INFILESIZE => strlen($contents),
                // Create parent folders on the host when missing (mods/, etc.).
                CURLOPT_FTP_CREATE_MISSING_DIRS => defined('CURLFTP_CREATE_DIR_RETRY')
                    ? CURLFTP_CREATE_DIR_RETRY
                    : 2,
            ], 'WRITE '.$path);
        } finally {
            fclose($tmp);
        }
    }

    public function ensureDirectory(string $path): void
    {
        $path = $this->normalizePath($path);
        if ($path === '' || $this->directoryExists($path)) {
            return;
        }

        // Upload a tiny marker then delete is heavy; prefer CREATE_MISSING_DIRS on write.
        // Soft-create by listing parent and relying on write() for the zip path.
        $parent = dirname(str_replace('\\', '/', $path));
        if ($parent !== '.' && $parent !== '' && $parent !== '/') {
            $this->ensureDirectory($parent);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $this->listNames($path);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function modeLabel(): string
    {
        return match ($this->mode) {
            'ftp' => 'FTP (klartext)',
            'ftps_explicit' => 'FTPS explicit (AUTH TLS)',
            'ftps_implicit' => 'FTPS implicit',
        };
    }

    /**
     * @param  array<int, mixed>  $extra
     */
    private function exec(string $url, array $extra, string $op): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL init fehlgeschlagen.');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->username.':'.$this->password,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FTP_USE_EPSV => true,
            // Many hosters sit behind NAT and advertise a private PASV IP.
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($this->mode === 'ftps_explicit') {
            $opts[CURLOPT_USE_SSL] = CURLFTPSSL_ALL;
            $opts[CURLOPT_FTPSSLAUTH] = CURLFTPAUTH_TLS;
            // Self-signed certs are common on dedicated-server hosts.
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif ($this->mode === 'ftps_implicit') {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        foreach ($extra as $key => $value) {
            $opts[$key] = $value;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primary = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            $detail = $error !== '' ? $error : "cURL errno {$errno}";
            throw new RuntimeException(
                "{$op} fehlgeschlagen ({$this->modeLabel()} → {$this->host}:{$this->port}): {$detail}"
            );
        }

        // FTP response codes via curl are often 0 for successful transfers; treat clear failures.
        if ($status >= 400 || $primary >= 400) {
            throw new RuntimeException(
                "{$op} abgelehnt ({$this->modeLabel()} → {$this->host}:{$this->port}, Code ".max($status, $primary).').'
            );
        }

        return (string) $body;
    }

    private function urlForPath(string $path, bool $asDirectory): string
    {
        $path = $this->normalizePath($path);
        $full = $this->root === '' ? $path : ($path === '' ? $this->root : $this->root.'/'.$path);
        $full = $this->normalizePath($full);

        $scheme = $this->mode === 'ftps_implicit' ? 'ftps' : 'ftp';
        $url = $scheme.'://'.$this->host.':'.$this->port.'/';
        if ($full !== '') {
            $url .= implode('/', array_map('rawurlencode', explode('/', $full)));
        }
        if ($asDirectory && ! str_ends_with($url, '/')) {
            $url .= '/';
        }

        return $url;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');

        return $path;
    }
}
