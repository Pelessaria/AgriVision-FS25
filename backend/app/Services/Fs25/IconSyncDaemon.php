<?php

namespace App\Services\Fs25;

use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Store-icon FTP must keep going after the browser leaves the page.
 * php-fpm stops work when the request ends, and this install often has no cron,
 * so the worker finishes the HTTP response and then keeps pulling icons.
 * A www-data crontab is installed when the host allows it, so the pull also
 * continues when nobody has the dashboard open.
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
        $canDetach = function_exists('fastcgi_finish_request');
        if (! $canDetach) {
            self::drain(20);

            return;
        }

        @fastcgi_finish_request();
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        self::drain(180);
    }

    private static function drain(int $budgetSec): void
    {
        $idle = storage_path('app/connector/icon-sync.idle');
        if (is_file($idle) && (time() - (int) filemtime($idle)) < 120) {
            return;
        }

        $deadline = time() + $budgetSec;
        try {
            $icons = app(ConnectorSnapshotService::class);
            while (time() < $deadline) {
                $pending = 0;
                $moved = 0;
                foreach (Workspace::query()->cursor() as $workspace) {
                    $result = $icons->syncStoreIconsIfDue($workspace, 0);
                    $pending += (int) ($result['pending'] ?? 0);
                    $moved += (int) ($result['downloaded'] ?? 0) + (int) ($result['converted'] ?? 0);
                    if (($result['skipped'] ?? null) === 'busy') {
                        return;
                    }
                }
                if ($pending <= 0) {
                    File::ensureDirectoryExists(dirname($idle));
                    File::put($idle, (string) time());

                    return;
                }
                if ($moved <= 0) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AgriVision icon drain stopped', ['error' => $e->getMessage()]);
        }
    }

    private static function installCron(): void
    {
        $ok = storage_path('app/connector/icon-sync.cron');
        $fail = storage_path('app/connector/icon-sync.cron-failed');
        if (is_file($ok)) {
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

        $php = self::phpCli();
        $artisan = base_path('artisan');
        $log = storage_path('logs/icon-sync.log');
        $line = '* * * * * '.escapeshellarg($php).' '.escapeshellarg($artisan)
            .' agrivision:sync-store-icons >> '.escapeshellarg($log).' 2>&1';

        try {
            $existing = shell_exec('crontab -l 2>/dev/null');
            $existing = is_string($existing) ? $existing : '';
            if (str_contains($existing, 'agrivision:sync-store-icons')) {
                File::ensureDirectoryExists(dirname($ok));
                File::put($ok, $line);

                return;
            }
            $body = rtrim($existing)."\n".$line."\n";
            $tmp = storage_path('app/connector/crontab.txt');
            File::ensureDirectoryExists(dirname($tmp));
            File::put($tmp, $body);
            shell_exec('crontab '.escapeshellarg($tmp).' 2>&1');
            $check = shell_exec('crontab -l 2>/dev/null');
            if (is_string($check) && str_contains($check, 'agrivision:sync-store-icons')) {
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
