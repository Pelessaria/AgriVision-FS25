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

    $run = function (int $interval) use ($icons): array {
        $pending = 0;
        $moved = 0;
        foreach (Workspace::query()->cursor() as $workspace) {
            $result = $icons->syncStoreIconsIfDue($workspace, $interval);
            $pending += (int) $result['pending'];
            $moved += (int) $result['downloaded'] + (int) $result['converted'];
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
            if (($result['skipped'] ?? null) === 'busy') {
                return ['pending' => $pending, 'moved' => 0];
            }
        }

        return ['pending' => $pending, 'moved' => $moved];
    };

    if (! $this->option('daemon')) {
        $run(ConnectorSnapshotService::ICON_SYNC_INTERVAL_SEC);

        return;
    }

    while (true) {
        try {
            $run(ConnectorSnapshotService::ICON_SYNC_INTERVAL_SEC);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
        sleep(ConnectorSnapshotService::ICON_SYNC_INTERVAL_SEC);
    }
})->purpose('Pull missing vehicle store icons over FTP');

Schedule::command('agrivision:sync-store-icons')
    ->everyFiveMinutes()
    ->withoutOverlapping(20);
