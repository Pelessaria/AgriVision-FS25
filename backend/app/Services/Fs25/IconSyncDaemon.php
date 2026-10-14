<?php

namespace App\Services\Fs25;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Store-icon FTP must not depend on an open browser page.
 * php-fpm only runs during HTTP requests, and this install has no cron,
 * so the first request starts a detached CLI loop that keeps pulling icons.
 */
final class IconSyncDaemon
{
    public static function ensureStarted(): void
    {
        if (app()->runningInConsole() || app()->runningUnitTests()) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            return;
        }

        $pidFile = storage_path('app/connector/icon-sync.pid');
        if (self::alive($pidFile)) {
            return;
        }

        File::ensureDirectoryExists(dirname($pidFile));
        $php = self::phpCli();
        $artisan = base_path('artisan');
        $log = storage_path('logs/icon-sync.log');
        $cmd = 'setsid '.escapeshellarg($php).' '.escapeshellarg($artisan)
            .' agrivision:sync-store-icons --daemon >> '.escapeshellarg($log).' 2>&1 < /dev/null &';

        try {
            shell_exec($cmd);
        } catch (\Throwable $e) {
            Log::warning('AgriVision icon daemon failed to start', ['error' => $e->getMessage()]);
        }
    }

    public static function writePid(): void
    {
        $pidFile = storage_path('app/connector/icon-sync.pid');
        File::ensureDirectoryExists(dirname($pidFile));
        File::put($pidFile, (string) getmypid());
    }

    private static function alive(string $pidFile): bool
    {
        if (! is_file($pidFile)) {
            return false;
        }
        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }

        return is_dir('/proc/'.$pid);
    }

    private static function phpCli(): string
    {
        foreach (['/usr/bin/php', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php8.4'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return 'php';
    }
}
