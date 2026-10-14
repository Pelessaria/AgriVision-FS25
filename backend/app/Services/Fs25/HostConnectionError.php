<?php

namespace App\Services\Fs25;

use Throwable;

/**
 * Builds support-friendly host access error strings (no secrets).
 */
final class HostConnectionError
{
    public static function format(Throwable $e, ?string $context = null): string
    {
        $parts = [];
        if ($context !== null && $context !== '') {
            $parts[] = $context;
        }

        $current = $e;
        $guard = 0;
        while ($current !== null && $guard < 6) {
            $message = trim($current->getMessage());
            $message = self::redact($message);
            if ($message !== '' && ! in_array($message, $parts, true)) {
                $parts[] = $message;
            }
            $current = $current->getPrevious();
            $guard++;
        }

        if ($parts === []) {
            return 'Unbekannter Host-Fehler.';
        }

        return mb_substr(implode(' — ', $parts), 0, 500);
    }

    /**
     * TCP reachability check before FTP/SFTP handshake.
     *
     * @return array{ok: bool, detail: string}
     */
    public static function tcpProbe(string $host, int $port, float $timeoutSeconds = 8.0): array
    {
        $host = trim($host);
        $port = $port > 0 ? $port : 0;
        if ($host === '' || $port <= 0) {
            return ['ok' => false, 'detail' => 'Host oder Port fehlt.'];
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
        if (is_resource($socket) || $socket instanceof \Socket) {
            fclose($socket);

            return [
                'ok' => true,
                'detail' => "TCP {$host}:{$port} erreichbar.",
            ];
        }

        $errstr = trim($errstr);
        $detail = "TCP {$host}:{$port} nicht erreichbar";
        if ($errno) {
            $detail .= " (errno {$errno}";
            if ($errstr !== '') {
                $detail .= ": {$errstr}";
            }
            $detail .= ')';
        } elseif ($errstr !== '') {
            $detail .= ": {$errstr}";
        } else {
            $detail .= ' (Timeout oder Verbindungsabbruch).';
        }

        return ['ok' => false, 'detail' => self::redact($detail)];
    }

    public static function redact(string $message): string
    {
        $message = preg_replace('/password[=:\s]+\S+/i', 'password=***', $message) ?? $message;
        $message = preg_replace('/(--pass(?:word)?\s+)\S+/i', '$1***', $message) ?? $message;
        $message = preg_replace('#://([^:\s/]+):([^@\s/]+)@#', '://$1:***@', $message) ?? $message;

        return trim($message);
    }
}
