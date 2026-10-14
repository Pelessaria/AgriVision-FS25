<?php

use App\Models\Workspace;
use App\Services\Fs25\ConnectorSnapshotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('agrivision:sync-store-icons {--daemon : Keep pulling icons until the process is stopped}', function (ConnectorSnapshotService $icons) {
    if ($this->option('daemon')) {
        \App\Services\Fs25\IconSyncDaemon::writePid();
        $this->info('icon daemon '.getmypid());
    }

    $run = function () use ($icons): int {
        $pending = 0;
        foreach (Workspace::query()->cursor() as $workspace) {
            $result = $icons->syncStoreIconsIfDue($workspace, $this->option('daemon') ? 8 : 20);
            $pending += (int) $result['pending'];
            $this->line(sprintf(
                '%s downloaded=%d converted=%d pending=%d listed=%d skipped=%s detail=%s',
                $workspace->public_id,
                $result['downloaded'],
                $result['converted'],
                $result['pending'],
                $result['listed'],
                $result['skipped'] ?? '-',
                $result['detail'] ?? '-'
            ));
        }

        return $pending;
    };

    if (! $this->option('daemon')) {
        $run();

        return;
    }

    while (true) {
        $pending = 0;
        try {
            $pending = $run();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
        sleep($pending > 0 ? 5 : 40);
    }
})->purpose('Pull missing vehicle store icons over FTP');

Schedule::command('agrivision:sync-store-icons')
    ->everyMinute()
    ->withoutOverlapping(10);
