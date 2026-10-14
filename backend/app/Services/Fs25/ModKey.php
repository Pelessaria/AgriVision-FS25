<?php

namespace App\Services\Fs25;

/**
 * Derive a stable mod key from a savegame / connector vehicle filename.
 */
final class ModKey
{
    public static function fromFilename(?string $filename): ?string
    {
        if ($filename === null || trim($filename) === '') {
            return null;
        }

        $path = str_replace('\\', '/', $filename);

        if (preg_match('/\$moddir\$([^\/]+)/i', $path, $m)) {
            return self::clean($m[1]);
        }

        if (preg_match('#/(FS25_[^/]+)/#i', $path, $m)) {
            return self::clean($m[1]);
        }

        if (preg_match('#^(FS25_[^/]+)/#i', $path, $m)) {
            return self::clean($m[1]);
        }

        return null;
    }

    public static function clean(string $key): ?string
    {
        $key = trim($key);
        $key = preg_replace('/\.zip$/i', '', $key) ?? $key;
        $key = trim($key, '/');

        return $key === '' ? null : $key;
    }
}
