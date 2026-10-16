<?php

namespace App\Services\Fs25;

use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Automatic store-icon check every five minutes.
 * A www-data crontab runs the artisan command when the host allows it.
 * Without cron, the next dashboard request after five minutes finishes the
 * HTTP response and then runs one check (manifest, then any changed files).
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

        self::installCron();

        app()->terminating(static function (): void {
            self::drainAfterResponse();
        });
    }

    public static function writePid(): void
    {
        $pidFile = storage_path('app/connector/icon-sync.pid');
        File::ensureDirectoryExists(dirname($pidFile));
        File::put($pidFile, (string) getmypid());
    }

    private static function drainAfterResponse(): void
    {
        $checked = storage_path('app/connector/icon-sync.checked');
        $backlog = is_file(storage_path('app/connector/icon-sync.backlog'));
        $gap = $backlog
            ? ConnectorSnapshotService::ICON_BACKLOG_INTERVAL_SEC
            : ConnectorSnapshotService::ICON_SYNC_INTERVAL_SEC;
        if (is_file($checked) && (time() - (int) filemtime($checked)) < $gap) {
            return;
        }

        $canDetach = function_exists('fastcgi_finish_request');
        if ($canDetach) {
            @fastcgi_finish_request();
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $icons = app(ConnectorSnapshotService::class);
            foreach (Workspace::query()->cursor() as $workspace) {
                $result = $icons->syncStoreIconsIfDue($workspace);
                if (($result['skipped'] ?? null) === 'busy') {
                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AgriVision icon sync stopped', ['error' => $e->getMessage()]);
        }
    }

    private static function installCron(): void
    {
        $ok = storage_path('app/connector/icon-sync.cron');
        $fail = storage_path('app/connector/icon-sync.cron-failed');
        $line = self::cronLine();
        if (is_file($ok) && trim((string) file_get_contents($ok)) === $line) {
            return;
        }
        if (is_file($fail) && (time() - (int) filemtime($fail)) < 3600) {
            return;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            File::ensureDirectoryExists(dirname($fail));
            File::put($fail, 'shell_exec disabled');

            return;
        }

        try {
            $existing = shell_exec('crontab -l 2>/dev/null');
            $existing = is_string($existing) ? $existing : '';
            $kept = [];
            foreach (preg_split('/\R/', $existing) ?: [] as $existingLine) {
                if (trim($existingLine) === '' || str_contains($existingLine, 'agrivision:sync-store-icons')) {
                    continue;
                }
                $kept[] = $existingLine;
            }
            $body = rtrim(implode("\n", $kept))."\n".$line."\n";
            $tmp = storage_path('app/connector/crontab.txt');
            File::ensureDirectoryExists(dirname($tmp));
            File::put($tmp, $body);
            shell_exec('crontab '.escapeshellarg($tmp).' 2>&1');
            $check = shell_exec('crontab -l 2>/dev/null');
            if (is_string($check) && str_contains($check, '*/5') && str_contains($check, 'agrivision:sync-store-icons')) {
                File::ensureDirectoryExists(dirname($ok));
                File::put($ok, $line);
                if (is_file($fail)) {
                    File::delete($fail);
                }

                return;
            }
            File::put($fail, 'crontab rejected');
            Log::warning('AgriVision icon cron was not installed');
        } catch (\Throwable $e) {
            File::ensureDirectoryExists(dirname($fail));
            File::put($fail, $e->getMessage());
            Log::warning('AgriVision icon cron failed', ['error' => $e->getMessage()]);
        }
    }

    private static function cronLine(): string
    {
        $php = self::phpCli();
        $artisan = base_path('artisan');
        $log = storage_path('logs/icon-sync.log');

        return '*/5 * * * * '.escapeshellarg($php).' '.escapeshellarg($artisan)
            .' agrivision:sync-store-icons >> '.escapeshellarg($log).' 2>&1';
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
